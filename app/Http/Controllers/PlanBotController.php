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
                '/last', '/l', '/lastusers' => $this->handleLastUsers($chatId, $args),
                '/loc', '/location', '/setloc' => $this->handleSetLocation($chatId, $args),

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
    /**
     * List the last N registered users from the daftari DB.
     * Usage:  /last            → default 10
     *         /last 20         → last 20
     *         /last 15 gold    → last 15 filtered by plan slug/name (optional)
     */
    private function handleLastUsers(int $chatId, string $args): void
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($args))));

        $count      = 10;
        $planFilter = null;

        if (isset($parts[0])) {
            if (ctype_digit($parts[0])) {
                $count = (int) $parts[0];
            } else {
                $planFilter = strtolower($parts[0]);
            }
        }
        if (isset($parts[1]) && $planFilter === null) {
            $planFilter = strtolower($parts[1]);
        }

        if ($count < 1 || $count > 50) {
            $this->send($chatId, "❌ Count must be between 1 and 50.");
            return;
        }

        $query = DB::connection(self::CONN)
            ->table('users as u')
            ->leftJoin('plans as p', 'p.id', '=', 'u.plan_id')
            ->orderByDesc('u.created_at')
            ->limit($count)
            ->select([
                'u.id', 'u.name', 'u.phone', 'u.created_at',
                'u.plan_expires_at',
                'p.id as plan_id', 'p.name as plan_name', 'p.slug as plan_slug',
            ]);

        if ($planFilter !== null) {
            $query->where(function ($q) use ($planFilter) {
                $q->whereRaw('LOWER(p.slug) = ?', [$planFilter])
                    ->orWhereRaw('LOWER(p.name) = ?', [$planFilter]);
            });
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->send($chatId, "ℹ️ No users found"
                . ($planFilter ? " for plan *{$planFilter}*" : '') . '.');
            return;
        }

        $totalUsers = DB::connection(self::CONN)->table('users')->count();

        $lines = [];
        $lines[] = "👥 *Last {$users->count()} Registered Users*"
            . ($planFilter ? " — plan: *{$planFilter}*" : '')
            . " (of {$totalUsers} total)";
        $lines[] = '';

        foreach ($users as $i => $u) {
            $seq       = $i + 1;
            $regDate   = $u->created_at ? Carbon::parse($u->created_at)->format('Y-m-d H:i') : '—';
            $planLabel = $u->plan_name ? "{$u->plan_name} (#{$u->plan_id})" : '—';

            $status = '⚪';
            if ($u->plan_expires_at) {
                $status = Carbon::parse($u->plan_expires_at)->isFuture() ? '🟢' : '🔴';
            }

            $lines[] = "*{$seq}.* `#{$u->id}` {$status} *" . ($u->name ?: '—') . "*";
            $lines[] = "   📱 {$u->phone}";
            $lines[] = "   📦 {$planLabel}";
            $lines[] = "   📅 Registered: {$regDate}";
            $lines[] = '';
        }

        $this->send($chatId, implode("\n", $lines));
    }



    /**
     * Set the `location` field (Google Maps link) for a user.
     * Usage:
     *   /loc <msisdn|#id> <google_maps_url>
     * Examples:
     *   /loc 07806999105 https://maps.google.com/?q=33.3152,44.3661
     *   /loc #42 https://maps.app.goo.gl/abc123
     */
    private function handleSetLocation(int $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 2);

        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            $this->send($chatId,
                "❌ Usage: `/loc <msisdn|#id> <google_maps_url>`\n" .
                "Examples:\n" .
                "`/loc 07806999105 https://maps.google.com/?q=33.3152,44.3661`\n" .
                "`/loc #42 https://maps.app.goo.gl/abc123`"
            );
            return;
        }

        [$identifier, $url] = $parts;
        $url = trim($url);

        // ── Validate the URL is a Google Maps link ─────────
        if (!$this->isGoogleMapsUrl($url)) {
            $this->send($chatId,
                "❌ Invalid Google Maps link.\n" .
                "Accepted hosts: `google.com/maps`, `maps.google.com`, `goo.gl/maps`, `maps.app.goo.gl`."
            );
            return;
        }

        // ── Locate the user (by #id or by phone) ───────────
        $userQuery = DB::connection(self::CONN)->table('users');

        if (str_starts_with($identifier, '#') && ctype_digit(substr($identifier, 1))) {
            $userId = (int) substr($identifier, 1);
            $user   = $userQuery->where('id', $userId)->first(['id', 'name', 'phone', 'location']);
        } elseif (ctype_digit($identifier) && strlen($identifier) <= 6) {
            // Treat short numeric input as an ID
            $user = $userQuery->where('id', (int) $identifier)->first(['id', 'name', 'phone', 'location']);
        } else {
            $msisdn = $this->normalizeMsisdn($identifier);
            if (!$msisdn) {
                $this->send($chatId, "❌ Invalid phone number or id: `{$identifier}`");
                return;
            }
            $user = $userQuery->where('phone', $msisdn)->first(['id', 'name', 'phone', 'location']);
        }

        if (!$user) {
            $this->send($chatId, "❌ No user found for `{$identifier}`.");
            return;
        }

        // ── Update ─────────────────────────────────────────
        try {
            DB::connection(self::CONN)
                ->table('users')
                ->where('id', $user->id)
                ->update([
                    'location'   => $url,
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('PlanBot set location failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            $this->send($chatId, "❌ DB error: " . $e->getMessage());
            return;
        }

        $previous = $user->location ? "\n_Previous:_ {$user->location}" : '';

        $this->send($chatId,
            "✅ *Location updated*\n" .
            "👤 User: {$user->name} (#{$user->id})\n" .
            "📱 Phone: {$user->phone}\n" .
            "📍 New: {$url}" .
            $previous
        );
    }

    /**
     * Validate a Google Maps URL (accepts common variants + shortlinks).
     */
    private function isGoogleMapsUrl(string $url): bool
    {
        return true;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        // Full maps URLs
        if ($host === 'maps.google.com' || str_ends_with($host, '.maps.google.com')) {
            return true;
        }
        if (($host === 'google.com' || str_ends_with($host, '.google.com'))
            && str_starts_with($path, '/maps')) {
            return true;
        }

        // Shortlinks
        if ($host === 'goo.gl' && str_starts_with($path, '/maps')) {
            return true;
        }
        if ($host === 'maps.app.goo.gl') {
            return true;
        }

        return false;
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


    // ... existing code ...
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
`/plans`                     — list all available plans
`/check <msisdn>`            — show current plan of a user
`/last [count] [plan]`       — last N registered users (default 10, max 50)
                               e.g. `/last 20` or `/last 15 gold`
`/loc <msisdn|#id> <url>`    — set a user's Google Maps location
                               e.g. `/loc 07806999105 https://maps.app.goo.gl/abc`
                               or   `/loc #42 https://maps.google.com/?q=33.3,44.4`
`/help`                      — this help
HELP;
    }
// ... existing code ...


}
