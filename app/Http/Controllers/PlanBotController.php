<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PlanBotController  extends Controller
{
    private string $telegramToken;
    private array $allowedUsers;

    /** External MySQL connection name (see config/database.php) */
    private const CONN = 'daftari';

    public function __construct()
    {

        $this->telegramToken = (string) config('services.telegram_plan.token');


        $this->allowedUsers  = array_filter(array_map('trim',
            explode(',', (string) config('services.telegram_plan.allowed_users'))
        ));
    }

    // =========================================================
    // Entry point
    // =========================================================

    public function webhook(Request $request): void
    {
        Log::info('PlanBot webhook hit', [
            'ip'      => $request->ip(),
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
        ]);
        $body    = $request->all();
        $message = $body['message'] ?? $body['edited_message'] ?? null;

        if (!$message) {
            return;
        }

        $chatId = $update['message']['chat']['id'] ?? env('TELEGRAM_ALLOWED_CHAT_ID') ;

//        if ($chatId != env('TELEGRAM_ALLOWED_CHAT_ID')) {
//            return response()->json(['ok' => true]);
//        }
        $username = $message['from']['username'] ?? '';
        $text     = trim($message['text'] ?? '');

        if (!in_array($username, $this->allowedUsers, true)) {
            Log::info('Unauthorized PlanBot message', [
                'chat_id'  => $chatId,
                'username' => $username,
                'text'     => $text,
            ]);
            $this->send($chatId, '⛔ Unauthorized');
            return;
        }

        if ($text === '' ) {
            $this->send($chatId, $this->helpText());
            return;
        }

        // Commands starting with "/"
        if (str_starts_with($text, '/')) {
            [$command, $args] = $this->parseCommand($text);

            match ($command) {
                '/start', '/help' => $this->send($chatId, $this->helpText()),
                '/plans'          => $this->handleListPlans($chatId),
                '/check'          => $this->handleCheck($chatId, $args),
                default           => $this->send($chatId, "❓ Unknown command.\n\n" . $this->helpText()),
            };
            return;
        }

        // Plain "activation line":  msisdn plan_id months price
        // Example:  07806999105 3 12 1500000
        $this->handleActivation($chatId, $text);
    }

    // =========================================================
    // Handlers
    // =========================================================

    private function handleActivation(int $chatId, string $text): void
    {
        $parts = preg_split('/\s+/', trim($text));

        if (count($parts) !== 4) {
            $this->send($chatId,
                "❌ Invalid format.\n" .
                "Send: `msisdn plan_id months price`\n" .
                "Example: `07806999105 3 12 1500000`"
            );
            return;
        }

        [$msisdn, $planId, $months, $price] = $parts;

        // ── Validate ───────────────────────────────────────
        $msisdn = $this->normalizeMsisdn($msisdn);
        if (!$msisdn) {
            $this->send($chatId, "❌ Invalid phone number.");
            return;
        }
        if (!ctype_digit($planId) || (int) $planId <= 0) {
            $this->send($chatId, "❌ plan_id must be a positive integer.");
            return;
        }
        if (!ctype_digit($months) || (int) $months <= 0 || (int) $months > 120) {
            $this->send($chatId, "❌ months must be between 1 and 120.");
            return;
        }
        if (!is_numeric($price) || (float) $price < 0) {
            $this->send($chatId, "❌ price must be a non-negative number.");
            return;
        }

        $planId = (int) $planId;
        $months = (int) $months;
        $price  = (float) $price;

        // ── Locate user & plan ─────────────────────────────
        $user = DB::connection(self::CONN)
            ->table('users')
            ->where('phone', $msisdn)
            ->first(['id', 'name', 'phone', 'plan_id', 'plan_expires_at']);

        if (!$user) {
            $this->send($chatId, "❌ No user found with phone *{$msisdn}*.");
            return;
        }

        $plan = DB::connection(self::CONN)
            ->table('plans')
            ->where('id', $planId)
            ->first(['id', 'name', 'slug', 'price', 'is_active']);

        if (!$plan) {
            $this->send($chatId, "❌ Plan #{$planId} not found.");
            return;
        }
        if (!$plan->is_active) {
            $this->send($chatId, "❌ Plan *{$plan->name}* is inactive.");
            return;
        }

        // ── Compute new expiry (extend if still active) ────
        $now = Carbon::now();
        $baseDate = ($user->plan_expires_at && Carbon::parse($user->plan_expires_at)->isFuture())
            ? Carbon::parse($user->plan_expires_at)
            : $now;

        $newExpiry = $now->copy()->addMonths($months);

        // ── Update user ────────────────────────────────────
        try {
            DB::connection(self::CONN)->transaction(function () use ($user, $planId, $newExpiry, $now) {
                DB::connection(self::CONN)
                    ->table('users')
                    ->where('id', $user->id)
                    ->update([
                        'plan_id'         => $planId,
                        'plan_expires_at' => $newExpiry,
                        'updated_at'      => $now,
                    ]);
            });
        } catch (\Throwable $e) {
            Log::error('PlanBot activation failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            $this->send($chatId, "❌ DB error: " . $e->getMessage());
            return;
        }

        $this->send($chatId,
            "✅ *Activation successful*\n" .
            "👤 User: {$user->name} ({$user->phone})\n" .
            "📦 Plan: {$plan->name} (#{$plan->id})\n" .
            "⏳ Months: {$months}\n" .
            "💰 Price: " . number_format($price) . "\n" .
            "📅 Expires: " . $newExpiry->format('Y-m-d H:i')
        );
    }

    private function handleCheck(int $chatId, string $args): void
    {
        $msisdn = $this->normalizeMsisdn(trim($args));
        if (!$msisdn) {
            $this->send($chatId, "❌ Usage: `/check 07806999105`");
            return;
        }

        $row = DB::connection(self::CONN)
            ->table('users as u')
            ->leftJoin('plans as p', 'p.id', '=', 'u.plan_id')
            ->where('u.phone', $msisdn)
            ->first([
                'u.id', 'u.name', 'u.phone', 'u.plan_expires_at',
                'p.id as plan_id', 'p.name as plan_name',
            ]);

        if (!$row) {
            $this->send($chatId, "❌ No user with phone *{$msisdn}*.");
            return;
        }

        $expires = $row->plan_expires_at
            ? Carbon::parse($row->plan_expires_at)->format('Y-m-d H:i')
            : '—';
        $status  = ($row->plan_expires_at && Carbon::parse($row->plan_expires_at)->isFuture())
            ? '🟢 Active' : '🔴 Expired';

        $this->send($chatId,
            "👤 *{$row->name}* ({$row->phone})\n" .
            "📦 Plan: " . ($row->plan_name ?? '—') . " (#" . ($row->plan_id ?? '—') . ")\n" .
            "📅 Expires: {$expires}\n" .
            "Status: {$status}"
        );
    }

    private function handleListPlans(int $chatId): void
    {
        $plans = DB::connection(self::CONN)
            ->table('plans')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'price', 'max_shops', 'max_customers', 'is_active']);

        if ($plans->isEmpty()) {
            $this->send($chatId, 'ℹ️ No plans defined.');
            return;
        }

        $lines = ['📋 *Available Plans*', ''];
        foreach ($plans as $p) {
            $flag = $p->is_active ? '✅' : '⛔';
            $lines[] = "{$flag} *[{$p->id}] {$p->name}* ({$p->slug})";
            $lines[] = "   💰 " . number_format((float) $p->price) .
                "  |  🏬 shops: {$p->max_shops}  |  👥 customers: {$p->max_customers}";
        }

        $this->send($chatId, implode("\n", $lines));
    }

    // =========================================================
    // Helpers
    // =========================================================

    /**
     * Normalize an Iraqi phone number.
     * Accepts: 07806999105, 7806999105, +9647806999105, 009647806999105
     * Returns local form `07XXXXXXXXX` or null if invalid.
     */
    private function normalizeMsisdn(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '' || $digits === null) {
            return null;
        }

        // Strip 00964 / 964 country prefix
        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '964')) {
            $digits = substr($digits, 3);
        }

        // Ensure it begins with the local trunk zero
        if (!str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

        // Iraqi mobile numbers: 11 digits starting with 07
        if (!preg_match('/^07\d{9}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    private function parseCommand(string $text): array
    {
        $parts   = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args    = trim($parts[1] ?? '');
        return [$command, $args];
    }

    private function send(int $chatId, string $text): void
    {
        Http::post("https://api.telegram.org/bot{$this->telegramToken}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function helpText(): string
    {
        return <<<HELP
📖 *Plan Bot Commands*

*Activate / extend a user plan*
Just send:
`msisdn plan_id months price`
Example:
`07806999105 3 12 1500000`

*Other commands*
`/plans`               — list all available plans
`/check <msisdn>`      — show current plan of a user
`/help`                — this help
HELP;
    }
}
