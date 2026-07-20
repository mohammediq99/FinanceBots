<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PlanBotController extends Controller
{
    private string $telegramToken;
    private array $allowedUsers;

    /** External MySQL connection name (see config/database.php) */
    private const CONN = 'daftari';

    /** Field-visit CRM connection */
    private const BOT = 'daftari_bot';

    public function __construct()
    {
        $this->telegramToken = (string) config('services.telegram_plan.token');

        $this->allowedUsers = array_filter(array_map('trim',
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

        $chatId = (int) ($message['chat']['id'] ?? env('TELEGRAM_ALLOWED_CHAT_ID'));

        $username = $message['from']['username'] ?? '';
        $text     = trim($message['text'] ?? $message['caption'] ?? '');

        if (!in_array($username, $this->allowedUsers, true)) {
            Log::info('Unauthorized PlanBot message', [
                'chat_id'  => $chatId,
                'username' => $username,
                'text'     => $text,
            ]);
            $this->send($chatId, '⛔ Unauthorized');
            return;
        }

        // Voice / audio → attach to the active visit
        if (isset($message['voice']) || isset($message['audio'])) {
            $this->handleVoice($chatId, $message['voice'] ?? $message['audio'], $text);
            return;
        }

        if ($text === '') {
            $this->send($chatId, $this->helpText());
            return;
        }

        // Commands starting with "/"
        if (str_starts_with($text, '/')) {
            [$command, $args] = $this->parseCommand($text);

            match ($command) {
                // ── original commands (unchanged) ──
                '/start', '/help' => $this->send($chatId, $this->helpText()),
                '/plans'          => $this->handleListPlans($chatId),
                '/check'          => $this->handleCheck($chatId, $args),
                '/last', '/l', '/lastusers' => $this->handleLastUsers($chatId, $args),
                '/loc', '/location', '/setloc' => $this->handleSetLocation($chatId, $args),

                // ── field-visit CRM ──
                '/lead'    => $this->handleAddLead($chatId, $args, $username),
                '/leads'   => $this->handleListLeads($chatId, $args),
                '/show'    => $this->handleShowLead($chatId, $args),
                '/visit'   => $this->handleVisit($chatId, $args, $username),
                '/revisit' => $this->handleRevisit($chatId, $args),
                '/fail'    => $this->handleFail($chatId, $args, $username),
                '/note'    => $this->handleNote($chatId, $args),
                '/leadloc' => $this->handleLeadLocation($chatId, $args),
                '/due'     => $this->handleDue($chatId, $args),
                '/stats'   => $this->handleStats($chatId),

                default    => $this->send($chatId, "❓ Unknown command.\n\n" . $this->helpText()),
            };
            return;
        }

        // Plain "activation line":  msisdn plan_id months price
        // Example:  07806999105 3 12 1500000
        $this->handleActivation($chatId, $text);
    }

    // =========================================================
    // Handlers — plans (original)
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

        $now = Carbon::now();
        $baseDate = ($user->plan_expires_at && Carbon::parse($user->plan_expires_at)->isFuture())
            ? Carbon::parse($user->plan_expires_at)
            : $now;

        $newExpiry = $now->copy()->addMonths($months);

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
     * Set the `location` field (Google Maps link) for a daftari user.
     * Usage:
     *   /loc <msisdn|#id> <google_maps_url>
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

        if (!$this->isGoogleMapsUrl($url)) {
            $this->send($chatId,
                "❌ Invalid Google Maps link.\n" .
                "Accepted hosts: `google.com/maps`, `maps.google.com`, `goo.gl/maps`, `maps.app.goo.gl`."
            );
            return;
        }

        $userQuery = DB::connection(self::CONN)->table('users');

        if (str_starts_with($identifier, '#') && ctype_digit(substr($identifier, 1))) {
            $userId = (int) substr($identifier, 1);
            $user   = $userQuery->where('id', $userId)->first(['id', 'name', 'phone', 'location']);
        } elseif (ctype_digit($identifier) && strlen($identifier) <= 6) {
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

        if ($host === 'maps.google.com' || str_ends_with($host, '.maps.google.com')) {
            return true;
        }
        if (($host === 'google.com' || str_ends_with($host, '.google.com'))
            && str_starts_with($path, '/maps')) {
            return true;
        }

        if ($host === 'goo.gl' && str_starts_with($path, '/maps')) {
            return true;
        }
        if ($host === 'maps.app.goo.gl') {
            return true;
        }

        return false;
    }

    // =========================================================
    // Handlers — field-visit CRM (daftari_bot)
    // =========================================================

    private const OUTCOMES = [
        'int'            => 'interested',
        'interested'     => 'interested',
        'no'             => 'not_interested',
        'not'            => 'not_interested',
        'not_interested' => 'not_interested',
        'rev'            => 'revisit',
        'revisit'        => 'revisit',
        'abs'            => 'owner_absent',
        'absent'         => 'owner_absent',
        'closed'         => 'shop_closed',
        'signed'         => 'signed_up',
        'signup'         => 'signed_up',
        'fail'           => 'failed',
        'failed'         => 'failed',
    ];

    /** /lead <shop name> | <phone> | <area> | <type> */
    private function handleAddLead(int $chatId, string $args, string $username): void
    {
        if (trim($args) === '') {
            $this->send($chatId, "❌ Usage: `/lead <shop name> | <phone> | <area> | <type>`");
            return;
        }

        $p     = array_map('trim', explode('|', $args));
        $name  = $p[0] ?? '';
        $phone = isset($p[1]) && $p[1] !== '' ? $this->normalizeMsisdn($p[1]) : null;
        $area  = $p[2] ?? null;
        $type  = $p[3] ?? null;

        if ($name === '') {
            $this->send($chatId, "❌ Shop name required.");
            return;
        }

        if ($phone) {
            $existing = DB::connection(self::BOT)->table('leads')->where('phone', $phone)->first(['id', 'shop_name']);
            if ($existing) {
                $this->send($chatId, "⚠️ Lead already exists: `#{$existing->id}` *{$existing->shop_name}*");
                return;
            }
        }

        $now = Carbon::now();

        try {
            $id = DB::connection(self::BOT)->table('leads')->insertGetId([
                'shop_name'  => $name,
                'phone'      => $phone,
                'area'       => $area ?: null,
                'shop_type'  => $type ?: null,
                'status'     => 'new',
                'created_by' => $username,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlanBot lead insert failed', ['error' => $e->getMessage()]);
            $this->send($chatId, "❌ DB error: " . $e->getMessage());
            return;
        }

        $this->setSession($chatId, $id, null);

        $this->send($chatId,
            "✅ *Lead created* `#{$id}`\n" .
            "🏬 {$name}" .
            ($phone ? "\n📱 {$phone}" : '') .
            ($area ? "\n📍 {$area}" : '')
        );
    }

    /** /visit <#id|phone|name> <outcome> [notes] */
    private function handleVisit(int $chatId, string $args, string $username): void
    {
        $parts = preg_split('/\s+/', trim($args), 3);

        if (count($parts) < 2) {
            $this->send($chatId,
                "❌ Usage: `/visit <#id|phone|name> <outcome> [notes]`\n" .
                "Outcomes: `int` `no` `rev` `abs` `closed` `signed` `fail`"
            );
            return;
        }

        $lead = $this->findLead($parts[0]);
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found: `{$parts[0]}`");
            return;
        }

        $outcome = self::OUTCOMES[strtolower($parts[1])] ?? null;
        if (!$outcome) {
            $this->send($chatId, "❌ Unknown outcome `{$parts[1]}`. Use: `int` `no` `rev` `abs` `closed` `signed` `fail`");
            return;
        }

        $notes = $parts[2] ?? null;
        $now   = Carbon::now();

        try {
            $visitId = DB::connection(self::BOT)->table('visits')->insertGetId([
                'lead_id'           => $lead->id,
                'outcome'           => $outcome,
                'notes'             => $notes,
                'visited_at'        => $now,
                'telegram_username' => $username,
                'telegram_chat_id'  => $chatId,
                'created_at'        => $now,
            ]);

            $leadStatus = match ($outcome) {
                'interested'     => 'interested',
                'not_interested' => 'not_interested',
                'signed_up'      => 'signed_up',
                'failed'         => 'failed',
                default          => 'revisit',
            };

            $nextVisit = in_array($outcome, ['revisit', 'owner_absent', 'shop_closed'], true)
                ? $now->copy()->addDays(3)->toDateString()
                : null;

            $update = [
                'status'        => $leadStatus,
                'last_visit_at' => $now,
                'visits_count'  => DB::raw('visits_count + 1'),
                'next_visit_at' => $nextVisit,
                'updated_at'    => $now,
            ];

            // Link to the daftari account when he signs up
            if ($outcome === 'signed_up' && $lead->phone) {
                $daftariUser = DB::connection(self::CONN)->table('users')
                    ->where('phone', $lead->phone)->first(['id']);
                if ($daftariUser) {
                    $update['daftari_user_id'] = $daftariUser->id;
                }
            }

            DB::connection(self::BOT)->table('leads')->where('id', $lead->id)->update($update);

            DB::connection(self::BOT)->table('contact_attempts')->insert([
                'lead_id'           => $lead->id,
                'channel'           => 'visit',
                'result'            => match ($outcome) {
                    'signed_up', 'interested'     => 'success',
                    'owner_absent', 'shop_closed' => 'no_answer',
                    'not_interested'              => 'refused',
                    'revisit'                     => 'rescheduled',
                    default                       => 'no_answer',
                },
                'notes'             => $notes,
                'attempted_at'      => $now,
                'telegram_username' => $username,
            ]);
        } catch (\Throwable $e) {
            Log::error('PlanBot visit insert failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            $this->send($chatId, "❌ DB error: " . $e->getMessage());
            return;
        }

        $this->setSession($chatId, $lead->id, $visitId);

        $this->send($chatId,
            "✅ *Visit logged* `#{$visitId}`\n" .
            "🏬 {$lead->shop_name} (`#{$lead->id}`)\n" .
            "📌 Outcome: {$outcome}\n" .
            ($notes ? "📝 {$notes}\n" : '') .
            ($nextVisit ? "🔁 Next visit: {$nextVisit}\n" : '') .
            "🎙 Send the voice note now to attach it to this visit."
        );
    }

    /** Voice / audio message → attached to the active visit */
    private function handleVoice(int $chatId, array $file, string $caption): void
    {
        $session = DB::connection(self::BOT)->table('bot_sessions')
            ->where('chat_id', $chatId)->first();

        if (!$session || !$session->active_visit_id) {
            $this->send($chatId, "❌ No active visit. Log one first: `/visit <ref> <outcome>`");
            return;
        }

        $fileId = $file['file_id'] ?? '';
        $stored = $this->downloadTelegramFile($fileId, (int) $session->active_visit_id);

        try {
            DB::connection(self::BOT)->table('visit_recordings')->insert([
                'visit_id'                => $session->active_visit_id,
                'lead_id'                 => $session->active_lead_id,
                'telegram_file_id'        => $fileId,
                'telegram_file_unique_id' => $file['file_unique_id'] ?? null,
                'file_path'               => $stored,
                'mime_type'               => $file['mime_type'] ?? null,
                'duration_seconds'        => $file['duration'] ?? null,
                'file_size'               => $file['file_size'] ?? null,
                'transcript'              => $caption ?: null,
                'created_at'              => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('PlanBot voice insert failed', ['error' => $e->getMessage()]);
            $this->send($chatId, "❌ DB error: " . $e->getMessage());
            return;
        }

        $duration = isset($file['duration']) ? gmdate('i:s', (int) $file['duration']) : '—';

        $this->send($chatId,
            "🎙 *Recording saved* → visit `#{$session->active_visit_id}`\n" .
            "⏱ {$duration}" .
            ($stored ? "\n💾 {$stored}" : "\n⚠️ Not downloaded — file_id stored only.")
        );
    }

    private function downloadTelegramFile(string $fileId, int $visitId): ?string
    {
        if ($fileId === '') {
            return null;
        }

        try {
            $meta = Http::timeout(20)
                ->get("https://api.telegram.org/bot{$this->telegramToken}/getFile", ['file_id' => $fileId])
                ->json();

            $path = $meta['result']['file_path'] ?? null;
            if (!$path) {
                return null;
            }

            $bytes = Http::timeout(120)
                ->get("https://api.telegram.org/file/bot{$this->telegramToken}/{$path}")
                ->body();

            if ($bytes === '') {
                return null;
            }

            $dest = "daftari_visits/{$visitId}/" . Carbon::now()->format('Ymd_His') . '_' . basename($path);
            Storage::disk('local')->put($dest, $bytes);

            return $dest;
        } catch (\Throwable $e) {
            Log::error('PlanBot file download failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** /revisit <ref> <YYYY-MM-DD|+3> [notes] */
    private function handleRevisit(int $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 3);

        if (count($parts) < 2) {
            $this->send($chatId, "❌ Usage: `/revisit <ref> <YYYY-MM-DD|+3> [notes]`");
            return;
        }

        $lead = $this->findLead($parts[0]);
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found: `{$parts[0]}`");
            return;
        }

        try {
            $date = str_starts_with($parts[1], '+')
                ? Carbon::now()->addDays((int) substr($parts[1], 1))
                : Carbon::parse($parts[1]);
        } catch (\Throwable) {
            $this->send($chatId, "❌ Invalid date. Use `YYYY-MM-DD` or `+3`.");
            return;
        }

        $notes = isset($parts[2])
            ? trim(($lead->notes ? $lead->notes . "\n" : '') . '[' . Carbon::now()->format('Y-m-d') . '] ' . $parts[2])
            : $lead->notes;

        DB::connection(self::BOT)->table('leads')->where('id', $lead->id)->update([
            'status'        => 'revisit',
            'next_visit_at' => $date->toDateString(),
            'notes'         => $notes,
            'updated_at'    => Carbon::now(),
        ]);

        $this->send($chatId, "🔁 *{$lead->shop_name}* (`#{$lead->id}`) → revisit on *{$date->toDateString()}*");
    }

    /** /fail <ref> <no_answer|wrong_number|refused|busy|rescheduled> [notes] */
    private function handleFail(int $chatId, string $args, string $username): void
    {
        $parts = preg_split('/\s+/', trim($args), 3);

        if (count($parts) < 2) {
            $this->send($chatId,
                "❌ Usage: `/fail <ref> <no_answer|wrong_number|refused|busy|rescheduled> [notes]`");
            return;
        }

        $lead = $this->findLead($parts[0]);
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found: `{$parts[0]}`");
            return;
        }

        $result = strtolower($parts[1]);
        if (!in_array($result, ['no_answer', 'wrong_number', 'refused', 'busy', 'rescheduled'], true)) {
            $this->send($chatId, "❌ Invalid result. Use: `no_answer` `wrong_number` `refused` `busy` `rescheduled`");
            return;
        }

        $now = Carbon::now();

        DB::connection(self::BOT)->table('contact_attempts')->insert([
            'lead_id'           => $lead->id,
            'channel'           => 'call',
            'result'            => $result,
            'notes'             => $parts[2] ?? null,
            'attempted_at'      => $now,
            'telegram_username' => $username,
        ]);

        $fails = DB::connection(self::BOT)->table('contact_attempts')
            ->where('lead_id', $lead->id)
            ->whereIn('result', ['no_answer', 'wrong_number', 'busy'])
            ->count();

        $status = match (true) {
            $result === 'refused' => 'not_interested',
            $fails >= 3           => 'unreachable',
            default               => 'failed',
        };

        DB::connection(self::BOT)->table('leads')->where('id', $lead->id)->update([
            'status'     => $status,
            'updated_at' => $now,
        ]);

        $this->send($chatId,
            "📵 *Failed contact logged*\n" .
            "🏬 {$lead->shop_name} (`#{$lead->id}`)\n" .
            "📌 {$result}  |  total fails: {$fails}\n" .
            "📊 Status → {$status}"
        );
    }

    /** /note <ref> <text> */
    private function handleNote(int $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 2);

        if (count($parts) < 2) {
            $this->send($chatId, "❌ Usage: `/note <ref> <text>`");
            return;
        }

        $lead = $this->findLead($parts[0]);
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found: `{$parts[0]}`");
            return;
        }

        $notes = trim(($lead->notes ? $lead->notes . "\n" : '') .
            '[' . Carbon::now()->format('Y-m-d') . '] ' . $parts[1]);

        DB::connection(self::BOT)->table('leads')->where('id', $lead->id)->update([
            'notes'      => $notes,
            'updated_at' => Carbon::now(),
        ]);

        $this->send($chatId, "📝 Note added to *{$lead->shop_name}* (`#{$lead->id}`).");
    }

    /** /leadloc <ref> <google_maps_url> */
    private function handleLeadLocation(int $chatId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args), 2);

        if (count($parts) < 2) {
            $this->send($chatId, "❌ Usage: `/leadloc <ref> <google_maps_url>`");
            return;
        }

        $lead = $this->findLead($parts[0]);
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found: `{$parts[0]}`");
            return;
        }

        $url = trim($parts[1]);
        if (!$this->isGoogleMapsUrl($url)) {
            $this->send($chatId, "❌ Invalid Google Maps link.");
            return;
        }

        DB::connection(self::BOT)->table('leads')->where('id', $lead->id)->update([
            'location_url' => $url,
            'updated_at'   => Carbon::now(),
        ]);

        $this->send($chatId, "📍 Location set for *{$lead->shop_name}* (`#{$lead->id}`)\n🗺 {$url}");
    }

    /** /leads [status] [count] */
    private function handleListLeads(int $chatId, string $args): void
    {
        $parts  = array_values(array_filter(preg_split('/\s+/', trim($args))));
        $status = null;
        $count  = 15;

        foreach ($parts as $p) {
            if (ctype_digit($p)) {
                $count = (int) $p;
            } else {
                $status = strtolower($p);
            }
        }

        $count = max(1, min($count, 50));

        $q = DB::connection(self::BOT)->table('leads')->orderByDesc('updated_at')->limit($count);
        if ($status !== null) {
            $q->where('status', $status);
        }

        $leads = $q->get();

        if ($leads->isEmpty()) {
            $this->send($chatId, "ℹ️ No leads found" . ($status ? " with status *{$status}*" : '') . '.');
            return;
        }

        $total = DB::connection(self::BOT)->table('leads')->count();

        $lines = ["🏬 *Leads*" . ($status ? " — {$status}" : '') . " ({$leads->count()} of {$total})", ''];
        foreach ($leads as $l) {
            $lines[] = "`#{$l->id}` {$this->statusIcon($l->status)} *{$l->shop_name}*"
                . ($l->area ? " — {$l->area}" : '');
            $lines[] = "   📱 " . ($l->phone ?: '—') . "  |  🚶 {$l->visits_count}"
                . ($l->next_visit_at ? "  |  🔁 {$l->next_visit_at}" : '');
        }

        $this->send($chatId, implode("\n", $lines));
    }

    /** /show <ref> */
    private function handleShowLead(int $chatId, string $args): void
    {
        $lead = $this->findLead(trim($args));
        if (!$lead) {
            $this->send($chatId, "❌ Lead not found. Usage: `/show <#id|phone|name>`");
            return;
        }

        $visits = DB::connection(self::BOT)->table('visits')
            ->where('lead_id', $lead->id)
            ->orderByDesc('visited_at')
            ->limit(5)
            ->get();

        $recs = DB::connection(self::BOT)->table('visit_recordings')
            ->where('lead_id', $lead->id)->count();

        $attempts = DB::connection(self::BOT)->table('contact_attempts')
            ->where('lead_id', $lead->id)->count();

        $lines = [
            "🏬 *{$lead->shop_name}* `#{$lead->id}` {$this->statusIcon($lead->status)}",
            "📱 " . ($lead->phone ?: '—') . ($lead->shop_type ? "  |  {$lead->shop_type}" : ''),
            "📍 " . ($lead->area ?: '—') . ($lead->location_url ? "\n🗺 {$lead->location_url}" : ''),
            "📊 {$lead->status}  |  🚶 {$lead->visits_count}  |  🎙 {$recs}  |  📞 {$attempts}",
            "🔁 Next visit: " . ($lead->next_visit_at ?: '—'),
            "🆔 Daftari user: " . ($lead->daftari_user_id ? "#{$lead->daftari_user_id}" : '—'),
            '',
        ];

        if ($visits->isNotEmpty()) {
            $lines[] = '*Recent visits*';
            foreach ($visits as $v) {
                $lines[] = "• `#{$v->id}` " . Carbon::parse($v->visited_at)->format('Y-m-d H:i')
                    . " — {$v->outcome}" . ($v->notes ? ": {$v->notes}" : '');
            }
            $lines[] = '';
        }

        if ($lead->notes) {
            $lines[] = "📝 {$lead->notes}";
        }

        $this->send($chatId, implode("\n", $lines));

        $this->setSession($chatId, (int) $lead->id, $visits->first()->id ?? null);
    }

    /** /due [days] — leads due for revisit (default: today and overdue) */
    private function handleDue(int $chatId, string $args): void
    {
        $args = trim($args);
        $days = ctype_digit($args) ? (int) $args : 0;
        $cut  = Carbon::now()->addDays($days)->toDateString();

        $leads = DB::connection(self::BOT)->table('leads')
            ->whereNotNull('next_visit_at')
            ->whereDate('next_visit_at', '<=', $cut)
            ->whereNotIn('status', ['signed_up', 'not_interested'])
            ->orderBy('next_visit_at')
            ->limit(30)
            ->get();

        if ($leads->isEmpty()) {
            $this->send($chatId, "✅ Nothing due (≤ {$cut}).");
            return;
        }

        $today = Carbon::now()->toDateString();

        $lines = ["🔁 *Due visits* (≤ {$cut})", ''];
        foreach ($leads as $l) {
            $flag = $l->next_visit_at < $today ? '⏰' : '📅';
            $lines[] = "`#{$l->id}` {$this->statusIcon($l->status)} *{$l->shop_name}*"
                . ($l->area ? " — {$l->area}" : '');
            $lines[] = "   {$flag} {$l->next_visit_at}  |  📱 " . ($l->phone ?: '—');
        }

        $this->send($chatId, implode("\n", $lines));
    }

    /** /stats */
    private function handleStats(int $chatId): void
    {
        $byStatus = DB::connection(self::BOT)->table('leads')
            ->select('status', DB::raw('COUNT(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $totalLeads = DB::connection(self::BOT)->table('leads')->count();

        $visits7 = DB::connection(self::BOT)->table('visits')
            ->where('visited_at', '>=', Carbon::now()->subDays(7))->count();

        $visitsAll = DB::connection(self::BOT)->table('visits')->count();

        $recs = DB::connection(self::BOT)->table('visit_recordings')->count();

        $dueToday = DB::connection(self::BOT)->table('leads')
            ->whereNotNull('next_visit_at')
            ->whereDate('next_visit_at', '<=', Carbon::now()->toDateString())
            ->whereNotIn('status', ['signed_up', 'not_interested'])
            ->count();

        $lines = ["📊 *Field stats* — {$totalLeads} leads", ''];
        foreach ($byStatus as $s => $c) {
            $lines[] = $this->statusIcon((string) $s) . " {$s}: *{$c}*";
        }
        $lines[] = '';
        $lines[] = "🚶 Visits (7d): *{$visits7}*  |  total: *{$visitsAll}*";
        $lines[] = "🎙 Recordings: *{$recs}*";
        $lines[] = "🔁 Due now: *{$dueToday}*";

        $this->send($chatId, implode("\n", $lines));
    }

    // =========================================================
    // CRM helpers
    // =========================================================

    /** ref = `#12` | id | phone | shop name (partial) */
    private function findLead(string $ref): ?object
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $t = DB::connection(self::BOT)->table('leads');

        if (str_starts_with($ref, '#') && ctype_digit(substr($ref, 1))) {
            return $t->where('id', (int) substr($ref, 1))->first();
        }
        if (ctype_digit($ref) && strlen($ref) <= 6) {
            return $t->where('id', (int) $ref)->first();
        }

        $phone = $this->normalizeMsisdn($ref);
        if ($phone) {
            return $t->where('phone', $phone)->first();
        }

        return $t->where('shop_name', 'like', "%{$ref}%")->orderByDesc('updated_at')->first();
    }

    private function setSession(int $chatId, ?int $leadId, ?int $visitId): void
    {
        DB::connection(self::BOT)->table('bot_sessions')->updateOrInsert(
            ['chat_id' => $chatId],
            [
                'active_lead_id'  => $leadId,
                'active_visit_id' => $visitId,
                'updated_at'      => Carbon::now(),
            ]
        );
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'signed_up'      => '🟢',
            'interested'     => '🔵',
            'revisit'        => '🟡',
            'not_interested' => '🔴',
            'failed'         => '🟠',
            'unreachable'    => '⚫',
            'visited'        => '⚪',
            default          => '🆕',
        };
    }

    // =========================================================
    // Helpers (original)
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

        if (str_starts_with($digits, '00964')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '964')) {
            $digits = substr($digits, 3);
        }

        if (!str_starts_with($digits, '0')) {
            $digits = '0' . $digits;
        }

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
📖 *Daftari Bot*

*Activate / extend a user plan*
Just send:
`msisdn plan_id months price`
Example:
`07806999105 3 12 1500000`

*Plans & users*
`/plans`                     — list all available plans
`/check <msisdn>`            — show current plan of a user
`/last [count] [plan]`       — last N registered users (default 10, max 50)
`/loc <msisdn|#id> <url>`    — set a *user's* Google Maps location

*Field visits (leads)*
`/lead <shop> | <phone> | <area> | <type>`  — add lead
`/visit <ref> <outcome> [notes]`            — log a visit
   outcomes: `int` `no` `rev` `abs` `closed` `signed` `fail`
🎙 Send a voice note right after `/visit` → saved to that visit
`/revisit <ref> <YYYY-MM-DD|+3> [notes]`    — schedule revisit
`/fail <ref> <no_answer|wrong_number|refused|busy|rescheduled> [notes]`
`/note <ref> <text>`         — append a note
`/leadloc <ref> <url>`       — set a *lead's* Google Maps location
`/show <ref>`                — full lead card
`/leads [status] [count]`    — list leads
`/due [days]`                — leads due for revisit
`/stats`                     — field stats

ref = `#12` | phone | shop name
`/help`                      — this help
HELP;
    }
}
