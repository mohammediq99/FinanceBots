<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RaController extends Controller
{
    private $telegramToken;
    private $allowedUsers;

    public function __construct()
    {
        $this->telegramToken = config('services.telegram_ra.token');
        $this->allowedUsers  = explode(',', config('services.telegram_ra.allowed_users'));
    }

    // =========================================================
    // Entry point
    // =========================================================

    public function webhook(Request $request): void
    {
        $body    = $request->all();
        $message = $body['message'] ?? $body['edited_message'] ?? null;

        if (!$message) {
            return;
        }

        $chatId   = $message['chat']['id'];
        $username = $message['from']['username'] ?? '';
        $text     = trim($message['text'] ?? '');

        // Authorization check
        if (!in_array($username, $this->allowedUsers, true)) {
            \Log::info('Unauthorized Telegram Message', [
                'chat_id' => $chatId,
                'username' => $username,
                'text' => $text,
            ]);
            $this->send($chatId, '⛔ Unauthorized');
            return;
        }

        if (!str_starts_with($text, '/')) {
            $this->send($chatId, '❓ Please use a bot command. Type /help to see all commands.');
            return;
        }

        // Parse command and arguments
        [$command, $args] = $this->parseCommand($text);

        $aliases = [
            '/sys' => '/system',
            '/int' => '/integration',
            '/f'   => '/flow',
            '/mh'  => '/maphtml',
            '/h'  => '/help',
        ];
        $command = $aliases[$command] ?? $command;

        match ($command) {
            '/system'      => $this->handleSystem($chatId, $args),
            '/integration' => $this->handleIntegration($chatId, $args),
            '/flow'        => $this->handleFlow($chatId, $args),
            '/recon'       => $this->handleRecon($chatId, $args),
            '/map'         => $this->handleMap($chatId, $args),
            '/maphtml'     => $this->handleMapHtml($chatId, $args),
            '/search'      => $this->handleSearch($chatId, $args),
            '/help'        => $this->send($chatId, $this->helpText()),
            default        => $this->send($chatId, "❓ Unknown command.\n\n" . $this->helpText()),
        };
    }

    // =========================================================
    // Command handlers
    // =========================================================

    private function handleSystem(int $chatId, string $args): void
    {
        [$sub, $payload] = $this->splitSubCommand($args);

        if ($sub === 'add') {
            // /system add name|purpose|team
            $parts = $this->splitPipe($payload, 3);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /system add name|purpose|team');
                return;
            }
            [$name, $purpose, $team] = $parts;

            try {
                DB::table('ra_systems')->insert([
                    'name'       => $name,
                    'purpose'    => $purpose,
                    'owner_team' => $team,
                    'created_at' => now(),
                ]);
                $this->send($chatId, "✅ System *{$name}* added successfully.");
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        if ($sub === 'list') {
            $systems = DB::table('ra_systems')
                ->orderBy('name')
                ->get(['id', 'name', 'purpose', 'owner_team']);

            if ($systems->isEmpty()) {
                $this->send($chatId, 'ℹ️ No systems registered yet.');
                return;
            }

            $lines = ["📋 *Registered Systems*\n"];
            foreach ($systems as $s) {
                $lines[] = "*[{$s->id}] {$s->name}*";
                $lines[] = "  📌 Purpose: {$s->purpose}";
                $lines[] = "  👥 Team: {$s->owner_team}\n";
            }

            $this->send($chatId, implode("\n", $lines));
            return;
        }

        $this->send($chatId, '❌ Usage: /system add name|purpose|team  OR  /system list');
    }

    // ---------------------------------------------------------

    private function handleIntegration(int $chatId, string $args): void
    {
        [$sub, $payload] = $this->splitSubCommand($args);

        if ($sub === 'add') {
            // /integration add system_a|system_b|purpose|type|direction|notes
            $parts = $this->splitPipe($payload, 6);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /integration add system_a|system_b|purpose|type|direction|notes');
                return;
            }
            [$sysA, $sysB, $purpose, $type, $direction, $notes] = $parts;

            $idA = $this->findSystemId($sysA);
            $idB = $this->findSystemId($sysB);

            if (!$idA) {
                $this->send($chatId, "❌ System not found: *{$sysA}*");
                return;
            }
            if (!$idB) {
                $this->send($chatId, "❌ System not found: *{$sysB}*");
                return;
            }

            $validTypes      = ['API', 'DB_link', 'file_exchange', 'message_queue'];
            $validDirections = ['a_to_b', 'b_to_a', 'bidirectional'];

            if (!in_array($type, $validTypes, true)) {
                $this->send($chatId, '❌ Invalid type. Choose: ' . implode(', ', $validTypes));
                return;
            }
            if (!in_array($direction, $validDirections, true)) {
                $this->send($chatId, '❌ Invalid direction. Choose: ' . implode(', ', $validDirections));
                return;
            }

            try {
                $id = DB::table('ra_system_integrations')->insertGetId([
                    'system_a_id'         => $idA,
                    'system_b_id'         => $idB,
                    'integration_purpose' => $purpose,
                    'integration_type'    => $type,
                    'direction'           => $direction,
                    'notes'               => $notes,
                    'created_at'          => now(),
                ]);
                $this->send($chatId, "✅ Integration added (ID: {$id}) between *{$sysA}* ↔ *{$sysB}*.");
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        if ($sub === 'list') {
            $filter = trim($payload);

            $query = DB::table('ra_system_integrations as i')
                ->join('ra_systems as sa', 'sa.id', '=', 'i.system_a_id')
                ->join('ra_systems as sb', 'sb.id', '=', 'i.system_b_id')
                ->orderBy('i.id');

            if ($filter !== '') {
                $systemId = $this->findSystemId($filter);
                if ($systemId) {
                    $query->where(function ($q) use ($systemId) {
                        $q->where('i.system_a_id', $systemId)
                            ->orWhere('i.system_b_id', $systemId);
                    });
                }
            }

            $rows = $query->get([
                'i.id', 'i.integration_purpose', 'i.integration_type',
                'i.direction', 'i.notes', 'sa.name as name_a', 'sb.name as name_b',
            ]);

            if ($rows->isEmpty()) {
                $this->send($chatId, 'ℹ️ No integrations found.');
                return;
            }

            $lines = ["🔗 *Integrations*\n"];
            foreach ($rows as $r) {
                $arrow = match ($r->direction) {
                    'a_to_b'      => '→',
                    'b_to_a'      => '←',
                    'bidirectional' => '↔',
                    default       => '—',
                };
                $lines[] = "*[{$r->id}] {$r->name_a} {$arrow} {$r->name_b}*";
                $lines[] = "  📌 Purpose: {$r->integration_purpose}";
                $lines[] = "  🔧 Type: {$r->integration_type}";
                if ($r->notes) {
                    $lines[] = "  📝 Notes: {$r->notes}";
                }
                $lines[] = '';
            }

            $this->send($chatId, implode("\n", $lines));
            return;
        }

        $this->send($chatId, '❌ Usage: /integration add ...  OR  /integration list [system_name]');
    }

    // ---------------------------------------------------------

    private function handleFlow(int $chatId, string $args): void
    {
        [$sub, $payload] = $this->splitSubCommand($args);

        if ($sub === 'add') {
            // /flow add source|target|integration_id|data_type|format|frequency|freq_note|notes
            $parts = $this->splitPipe($payload, 8);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /flow add source|target|integration_id|data_type|format|frequency|freq_note|notes');
                return;
            }
            [$source, $target, $intId, $dataType, $format, $frequency, $freqNote, $notes] = $parts;

            $srcId = $this->findSystemId($source);
            $tgtId = $this->findSystemId($target);

            if (!$srcId) {
                $this->send($chatId, "❌ System not found: *{$source}*");
                return;
            }
            if (!$tgtId) {
                $this->send($chatId, "❌ System not found: *{$target}*");
                return;
            }

            $validFreqs = ['realtime', 'hourly', 'daily', 'weekly', 'manual'];
            if (!in_array($frequency, $validFreqs, true)) {
                $this->send($chatId, '❌ Invalid frequency. Choose: ' . implode(', ', $validFreqs));
                return;
            }

            $integration = DB::table('ra_system_integrations')->find((int) $intId);
            if (!$integration) {
                $this->send($chatId, "❌ Integration ID {$intId} not found.");
                return;
            }

            try {
                $id = DB::table('ra_system_flows')->insertGetId([
                    'source_system_id' => $srcId,
                    'target_system_id' => $tgtId,
                    'integration_id'   => (int) $intId,
                    'data_type'        => $dataType,
                    'format'           => $format,
                    'frequency'        => $frequency,
                    'frequency_note'   => $freqNote,
                    'notes'            => $notes,
                    'created_at'       => now(),
                ]);
                $this->send($chatId, "✅ Flow added (ID: {$id}): *{$source}* → *{$target}*.");
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        if ($sub === 'list') {
            $filter = trim($payload);

            $query = DB::table('ra_system_flows as f')
                ->join('ra_systems as ss', 'ss.id', '=', 'f.source_system_id')
                ->join('ra_systems as ts', 'ts.id', '=', 'f.target_system_id')
                ->orderBy('f.id');

            if ($filter !== '') {
                $systemId = $this->findSystemId($filter);
                if ($systemId) {
                    $query->where(function ($q) use ($systemId) {
                        $q->where('f.source_system_id', $systemId)
                            ->orWhere('f.target_system_id', $systemId);
                    });
                }
            }

            $rows = $query->get([
                'f.id', 'f.data_type', 'f.format', 'f.frequency',
                'f.frequency_note', 'f.notes', 'f.integration_id',
                'ss.name as src_name', 'ts.name as tgt_name',
            ]);

            if ($rows->isEmpty()) {
                $this->send($chatId, 'ℹ️ No flows found.');
                return;
            }

            $lines = ["🌊 *Data Flows*\n"];
            foreach ($rows as $r) {
                $lines[] = "*[{$r->id}] {$r->src_name} → {$r->tgt_name}*";
                $lines[] = "  🗂 Data: {$r->data_type} | Format: {$r->format}";
                $lines[] = "  ⏱ Frequency: {$r->frequency}" . ($r->frequency_note ? " ({$r->frequency_note})" : '');
                $lines[] = "  🔗 Integration ID: {$r->integration_id}";
                if ($r->notes) {
                    $lines[] = "  📝 Notes: {$r->notes}";
                }
                $lines[] = '';
            }

            $this->send($chatId, implode("\n", $lines));
            return;
        }

        $this->send($chatId, '❌ Usage: /flow add ...  OR  /flow list [system_name]');
    }

    // ---------------------------------------------------------

    private function handleRecon(int $chatId, string $args): void
    {
        [$sub, $payload] = $this->splitSubCommand($args);

        // /recon log flow_id|date|exp_count|act_count|exp_amount|act_amount
        if ($sub === 'log') {
            $parts = $this->splitPipe($payload, 6);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /recon log flow_id|date|exp_count|act_count|exp_amount|act_amount');
                return;
            }
            [$flowId, $date, $expCount, $actCount, $expAmount, $actAmount] = $parts;

            $flow = DB::table('ra_system_flows')->find((int) $flowId);
            if (!$flow) {
                $this->send($chatId, "❌ Flow ID {$flowId} not found.");
                return;
            }

            $expC = (int) $expCount;
            $actC = (int) $actCount;
            $status = ($expC === $actC && (float) $expAmount === (float) $actAmount)
                ? 'matched'
                : 'gap_found';

            try {
                $id = DB::table('ra_reconciliations')->insertGetId([
                    'flow_id'         => (int) $flowId,
                    'recon_date'      => $date,
                    'expected_count'  => $expC,
                    'actual_count'    => $actC,
                    'expected_amount' => (float) $expAmount,
                    'actual_amount'   => (float) $actAmount,
                    'status'          => $status,
                    'created_at'      => now(),
                ]);

                $gapCount  = $expC - $actC;
                $gapAmount = (float) $expAmount - (float) $actAmount;
                $icon      = $status === 'matched' ? '✅' : '⚠️';

                $this->send($chatId,
                    "{$icon} Reconciliation logged (ID: {$id})\n" .
                    "Status: *{$status}*\n" .
                    "Gap Count: {$gapCount} | Gap Amount: {$gapAmount}"
                );
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        // /recon note recon_id|note|root_cause
        if ($sub === 'note') {
            $parts = $this->splitPipe($payload, 3);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /recon note recon_id|note|root_cause');
                return;
            }
            [$reconId, $note, $rootCause] = $parts;

            $recon = DB::table('ra_reconciliations')->find((int) $reconId);
            if (!$recon) {
                $this->send($chatId, "❌ Reconciliation ID {$reconId} not found.");
                return;
            }

            // Update status to investigating if it was gap_found
            if ($recon->status === 'gap_found') {
                DB::table('ra_reconciliations')
                    ->where('id', (int) $reconId)
                    ->update(['status' => 'investigating']);
            }

            try {
                DB::table('ra_recon_notes')->insert([
                    'recon_id'   => (int) $reconId,
                    'note'       => $note,
                    'root_cause' => $rootCause,
                    'resolution' => null,
                    'created_at' => now(),
                ]);
                $this->send($chatId, "📝 Note added to Recon #{$reconId}. Status set to *investigating*.");
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        // /recon resolve recon_id|resolution
        if ($sub === 'resolve') {
            $parts = $this->splitPipe($payload, 2);
            if (!$parts) {
                $this->send($chatId, '❌ Usage: /recon resolve recon_id|resolution');
                return;
            }
            [$reconId, $resolution] = $parts;

            $recon = DB::table('ra_reconciliations')->find((int) $reconId);
            if (!$recon) {
                $this->send($chatId, "❌ Reconciliation ID {$reconId} not found.");
                return;
            }

            try {
                DB::table('ra_reconciliations')
                    ->where('id', (int) $reconId)
                    ->update(['status' => 'resolved']);

                // Add resolution note to the latest investigation note if any, otherwise create new
                $lastNote = DB::table('ra_recon_notes')
                    ->where('recon_id', (int) $reconId)
                    ->orderByDesc('id')
                    ->first();

                if ($lastNote) {
                    DB::table('ra_recon_notes')
                        ->where('id', $lastNote->id)
                        ->update(['resolution' => $resolution]);
                } else {
                    DB::table('ra_recon_notes')->insert([
                        'recon_id'   => (int) $reconId,
                        'note'       => 'Resolved',
                        'root_cause' => null,
                        'resolution' => $resolution,
                        'created_at' => now(),
                    ]);
                }

                $this->send($chatId, "✅ Recon #{$reconId} marked as *resolved*.");
            } catch (\Throwable $e) {
                $this->send($chatId, "❌ Error: " . $e->getMessage());
            }
            return;
        }

        $this->send($chatId, "❌ Usage:\n/recon log ...\n/recon note ...\n/recon resolve ...");
    }

    // ---------------------------------------------------------

    private function handleMap(int $chatId, string $args): void
    {
        $systemName = trim($args);

        if ($systemName === '') {
            $this->send($chatId, '❌ Usage: /map system_name');
            return;
        }

        $system = DB::table('ra_systems')
            ->whereRaw('LOWER(name) = ?', [strtolower($systemName)])
            ->first();

        if (!$system) {
            $this->send($chatId, "❌ System not found: *{$systemName}*");
            return;
        }

        $lines = ["🗺 *Map for: {$system->name}*\n"];

        // Integrations where this system is involved
        $integrations = DB::table('ra_system_integrations as i')
            ->join('ra_systems as sa', 'sa.id', '=', 'i.system_a_id')
            ->join('ra_systems as sb', 'sb.id', '=', 'i.system_b_id')
            ->where(function ($q) use ($system) {
                $q->where('i.system_a_id', $system->id)
                    ->orWhere('i.system_b_id', $system->id);
            })
            ->orderBy('i.id')
            ->get([
                'i.id as int_id', 'i.integration_purpose', 'i.integration_type',
                'i.direction', 'sa.name as name_a', 'sb.name as name_b',
                'i.system_a_id', 'i.system_b_id',
            ]);

        if ($integrations->isEmpty()) {
            $lines[] = 'ℹ️ No integrations found for this system.';
            $this->send($chatId, implode("\n", $lines));
            return;
        }

        foreach ($integrations as $int) {
            $isA   = $int->system_a_id === $system->id;
            $other = $isA ? $int->name_b : $int->name_a;

            $arrow = match ($int->direction) {
                'a_to_b'       => $isA ? '→' : '←',
                'b_to_a'       => $isA ? '←' : '→',
                'bidirectional'=> '↔',
                default        => '—',
            };

            $lines[] = "🔗 *Integration #{$int->int_id}* — {$system->name} {$arrow} {$other}";
            $lines[] = "   📌 {$int->integration_purpose} [{$int->integration_type}]";

            // Flows under this integration
            $flows = DB::table('ra_system_flows as f')
                ->join('ra_systems as ss', 'ss.id', '=', 'f.source_system_id')
                ->join('ra_systems as ts', 'ts.id', '=', 'f.target_system_id')
                ->where('f.integration_id', $int->int_id)
                ->orderBy('f.id')
                ->get([
                    'f.id', 'f.data_type', 'f.format', 'f.frequency',
                    'f.frequency_note', 'ss.name as src', 'ts.name as tgt',
                ]);

            if ($flows->isEmpty()) {
                $lines[] = '   └─ _(no flows)_';
            } else {
                foreach ($flows as $flow) {
                    $freq = $flow->frequency . ($flow->frequency_note ? " ({$flow->frequency_note})" : '');
                    $lines[] = "   └─ Flow #{$flow->id}: {$flow->src} → {$flow->tgt}";
                    $lines[] = "       📦 {$flow->data_type} | {$flow->format} | ⏱ {$freq}";
                }
            }

            $lines[] = '';
        }

        $this->send($chatId, implode("\n", $lines));
    }

    // ---------------------------------------------------------

    private function handleSearch(int $chatId, string $args): void
    {
        $keyword = trim($args);

        if ($keyword === '') {
            $this->send($chatId, '❌ Usage: /search keyword');
            return;
        }

        $like    = "%{$keyword}%";
        $results = [];

        // Systems
        $systems = DB::table('ra_systems')
            ->where('name', 'LIKE', $like)
            ->orWhere('purpose', 'LIKE', $like)
            ->orWhere('owner_team', 'LIKE', $like)
            ->get(['id', 'name', 'purpose']);

        foreach ($systems as $s) {
            $results[] = "📦 *System [{$s->id}]* {$s->name}\n   {$s->purpose}";
        }

        // Integrations
        $integrations = DB::table('ra_system_integrations as i')
            ->join('ra_systems as sa', 'sa.id', '=', 'i.system_a_id')
            ->join('ra_systems as sb', 'sb.id', '=', 'i.system_b_id')
            ->where('i.integration_purpose', 'LIKE', $like)
            ->orWhere('i.notes', 'LIKE', $like)
            ->orWhere('sa.name', 'LIKE', $like)
            ->orWhere('sb.name', 'LIKE', $like)
            ->get(['i.id', 'i.integration_purpose', 'sa.name as name_a', 'sb.name as name_b']);

        foreach ($integrations as $int) {
            $results[] = "🔗 *Integration [{$int->id}]* {$int->name_a} ↔ {$int->name_b}\n   {$int->integration_purpose}";
        }

        // Flows
        $flows = DB::table('ra_system_flows as f')
            ->join('ra_systems as ss', 'ss.id', '=', 'f.source_system_id')
            ->join('ra_systems as ts', 'ts.id', '=', 'f.target_system_id')
            ->where('f.data_type', 'LIKE', $like)
            ->orWhere('f.format', 'LIKE', $like)
            ->orWhere('f.notes', 'LIKE', $like)
            ->orWhere('ss.name', 'LIKE', $like)
            ->orWhere('ts.name', 'LIKE', $like)
            ->get(['f.id', 'f.data_type', 'f.format', 'ss.name as src', 'ts.name as tgt']);

        foreach ($flows as $flow) {
            $results[] = "🌊 *Flow [{$flow->id}]* {$flow->src} → {$flow->tgt}\n   {$flow->data_type} | {$flow->format}";
        }

        // Notes
        $notes = DB::table('ra_recon_notes')
            ->where('note', 'LIKE', $like)
            ->orWhere('root_cause', 'LIKE', $like)
            ->orWhere('resolution', 'LIKE', $like)
            ->get(['id', 'recon_id', 'note']);

        foreach ($notes as $note) {
            $excerpt = mb_substr($note->note, 0, 80);
            $results[] = "📝 *Note [{$note->id}]* (Recon #{$note->recon_id})\n   {$excerpt}…";
        }

        if (empty($results)) {
            $this->send($chatId, "🔍 No results found for: *{$keyword}*");
            return;
        }

        $header  = "🔍 *Search results for: {$keyword}* (" . count($results) . " found)\n\n";
        $this->send($chatId, $header . implode("\n\n", $results));
    }

    // =========================================================
    // Private helpers
    // =========================================================

    private function send(int $chatId, string $text): void
    {
        Http::post("https://api.telegram.org/bot{$this->telegramToken}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Parses "/command rest of args" into ['/command', 'rest of args'].
     */
    private function parseCommand(string $text): array
    {
        $parts   = explode(' ', $text, 2);
        $command = strtolower($parts[0]);
        $args    = trim($parts[1] ?? '');
        return [$command, $args];
    }

    /**
     * Splits "subcommand rest" into ['subcommand', 'rest'].
     */
    private function splitSubCommand(string $args): array
    {
        $parts = explode(' ', $args, 2);
        return [strtolower($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    /**
     * Splits a pipe-delimited string into exactly $count parts.
     * Returns null if parts count doesn't match.
     */
    private function splitPipe(string $payload, int $count): ?array
    {
        $parts = array_map('trim', explode('|', $payload));
        if (count($parts) < $count) {
            return null;
        }
        // Merge any extra pipes in the last field (e.g. notes with pipes)
        $head = array_slice($parts, 0, $count - 1);
        $tail = implode('|', array_slice($parts, $count - 1));
        return [...$head, $tail];
    }

    /**
     * Finds a system ID by exact name (case-insensitive).
     */
    private function findSystemId(string $name): ?int
    {
        $system = DB::table('ra_systems')
            ->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])
            ->first(['id']);

        return $system ? (int) $system->id : null;
    }

    // ---------------------------------------------------------

    private function handleMapHtml(int $chatId, string $args): void
    {
        $filterName = trim($args);

        // ── 1. Pull data from DB ──────────────────────────────
        $systemsQuery = DB::table('ra_systems')->orderBy('name');
        if ($filterName !== '') {
            $systemsQuery->whereRaw('LOWER(name) = ?', [strtolower($filterName)]);
        }
        $systems = $systemsQuery->get(['id', 'name', 'purpose', 'owner_team']);

        if ($systems->isEmpty()) {
            $this->send($chatId, '❌ No systems found' . ($filterName ? " for: *{$filterName}*" : '') . '.');
            return;
        }

        $systemIds = $systems->pluck('id')->toArray();

        $integrations = DB::table('ra_system_integrations as i')
            ->join('ra_systems as sa', 'sa.id', '=', 'i.system_a_id')
            ->join('ra_systems as sb', 'sb.id', '=', 'i.system_b_id')
            ->where(function ($q) use ($systemIds) {
                $q->whereIn('i.system_a_id', $systemIds)
                    ->orWhereIn('i.system_b_id', $systemIds);
            })
            ->get([
                'i.id', 'i.system_a_id', 'i.system_b_id',
                'i.integration_purpose', 'i.integration_type', 'i.direction',
                'i.notes', 'sa.name as name_a', 'sb.name as name_b',
            ]);

        $integrationIds = $integrations->pluck('id')->toArray();

        $flows = DB::table('ra_system_flows as f')
            ->join('ra_systems as ss', 'ss.id', '=', 'f.source_system_id')
            ->join('ra_systems as ts', 'ts.id', '=', 'f.target_system_id')
            ->whereIn('f.integration_id', $integrationIds ?: [0])
            ->get([
                'f.id', 'f.integration_id', 'f.data_type', 'f.format',
                'f.frequency', 'f.frequency_note', 'f.notes',
                'ss.name as src', 'ts.name as tgt',
            ]);

        // ── 2. Build JSON blobs for JS ────────────────────────
        $nodesJson = json_encode($systems->map(fn($s) => [
            'id'    => $s->id,
            'label' => $s->name,
            'title' => "<b>{$s->name}</b><br>Purpose: {$s->purpose}<br>Team: {$s->owner_team}",
            'group' => $s->owner_team,
        ])->values()->all());

        $edges = [];
        foreach ($integrations as $int) {
            $intFlows = $flows->where('integration_id', $int->id);
            $flowLines = $intFlows->map(fn($f) =>
                "• [{$f->id}] {$f->src}→{$f->tgt} | {$f->data_type} / {$f->format} / {$f->frequency}"
                . ($f->frequency_note ? " ({$f->frequency_note})" : '')
            )->implode('<br>');

            $arrowLabel = match ($int->direction) {
                'a_to_b'        => '→',
                'b_to_a'        => '←',
                'bidirectional' => '↔',
                default         => '—',
            };

            $tooltip  = "<b>{$int->name_a} {$arrowLabel} {$int->name_b}</b><br>";
            $tooltip .= "<b>Purpose:</b> {$int->integration_purpose}<br>";
            $tooltip .= "<b>Type:</b> {$int->integration_type}<br>";
            if ($int->notes)   { $tooltip .= "<b>Notes:</b> {$int->notes}<br>"; }
            if ($flowLines)    { $tooltip .= "<b>Flows:</b><br>{$flowLines}"; }

            $arrows = match ($int->direction) {
                'a_to_b'        => ['to' => ['enabled' => true],  'from' => ['enabled' => false]],
                'b_to_a'        => ['to' => ['enabled' => false], 'from' => ['enabled' => true]],
                'bidirectional' => ['to' => ['enabled' => true],  'from' => ['enabled' => true]],
                default         => ['to' => ['enabled' => true],  'from' => ['enabled' => false]],
            };

            $edges[] = [
                'id'     => $int->id,
                'from'   => $int->system_a_id,
                'to'     => $int->system_b_id,
                'label'  => $int->integration_type,
                'title'  => $tooltip,
                'arrows' => $arrows,
            ];
        }
        $edgesJson = json_encode($edges);

        $title     = $filterName !== '' ? "RA Map — {$filterName}" : 'RA Systems Map — All';
        $generated = now()->format('Y-m-d H:i');

        // ── 3. Build standalone HTML ──────────────────────────
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>{$title}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f1117; color: #e2e8f0; height: 100vh; display: flex; flex-direction: column; }
  header { padding: 12px 20px; background: #1a1d2e; border-bottom: 1px solid #2d3148; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
  header h1 { font-size: 16px; font-weight: 600; color: #7c83fd; }
  header span { font-size: 12px; color: #64748b; }
  #legend { display: flex; gap: 16px; padding: 8px 20px; background: #13152a; border-bottom: 1px solid #2d3148; flex-wrap: wrap; }
  .leg { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #94a3b8; }
  .leg-line { width: 20px; height: 3px; border-radius: 2px; }
  #network { flex: 1; }
  #tip { display: none; position: fixed; background: #1e2235; border: 1px solid #3d4270; border-radius: 8px; padding: 10px 14px; font-size: 13px; line-height: 1.7; max-width: 340px; z-index: 999; pointer-events: none; box-shadow: 0 4px 24px rgba(0,0,0,0.6); }
  #tip b { color: #7c83fd; }
</style>
</head>
<body>
<header>
  <h1>🗺 {$title}</h1>
  <span>Generated: {$generated} &nbsp;|&nbsp; Drag • Scroll to zoom • Hover for details</span>
</header>
<div id="legend">
  <div class="leg"><div class="leg-line" style="background:#34d399"></div>API</div>
  <div class="leg"><div class="leg-line" style="background:#f59e0b"></div>DB Link</div>
  <div class="leg"><div class="leg-line" style="background:#60a5fa"></div>File Exchange</div>
  <div class="leg"><div class="leg-line" style="background:#f472b6"></div>Message Queue</div>
</div>
<div id="network"></div>
<div id="tip"></div>

<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
const NODES_DATA = {$nodesJson};
const EDGES_DATA = {$edgesJson};

const edgeColors = {
  'API':           '#34d399',
  'DB_link':       '#f59e0b',
  'file_exchange': '#60a5fa',
  'message_queue': '#f472b6',
};

const nodes = new vis.DataSet(NODES_DATA.map(n => ({
  ...n,
  shape: 'box',
  font:  { color: '#e2e8f0', face: 'Segoe UI', size: 14 },
  color: {
    background: '#1e2235',
    border:     '#7c83fd',
    highlight:  { background: '#2d3262', border: '#a5abff' },
    hover:      { background: '#252847', border: '#9499fd' },
  },
  borderWidth: 2,
  shadow: { enabled: true, color: 'rgba(124,131,253,0.3)', size: 10 },
  margin: 12,
})));

const edges = new vis.DataSet(EDGES_DATA.map(e => ({
  ...e,
  color:  { color: edgeColors[e.label] || '#64748b', highlight: '#fff', hover: '#fff' },
  font:   { color: '#94a3b8', size: 11, align: 'middle', background: '#0f1117' },
  width:  2,
  smooth: { type: 'curvedCW', roundness: 0.15 },
})));

const network = new vis.Network(
  document.getElementById('network'),
  { nodes, edges },
  {
    physics: {
      solver: 'forceAtlas2Based',
      forceAtlas2Based: { gravitationalConstant: -80, centralGravity: 0.008, springLength: 200, springConstant: 0.05 },
      stabilization: { iterations: 200 },
    },
    interaction: { hover: true, navigationButtons: true, keyboard: true },
    layout:      { improvedLayout: true },
  }
);

const tip = document.getElementById('tip');
network.on('hoverNode', p => { tip.innerHTML = nodes.get(p.node).title;  tip.style.display = 'block'; });
network.on('blurNode',  () => { tip.style.display = 'none'; });
network.on('hoverEdge', p => { tip.innerHTML = edges.get(p.edge).title;  tip.style.display = 'block'; });
network.on('blurEdge',  () => { tip.style.display = 'none'; });
document.addEventListener('mousemove', ev => {
  tip.style.left = (ev.clientX + 18) + 'px';
  tip.style.top  = (ev.clientY + 18) + 'px';
});
</script>
</body>
</html>
HTML;

        // ── 4. Write temp file and send ───────────────────────
        $slug     = $filterName !== '' ? strtolower(preg_replace('/\W+/', '_', $filterName)) : 'all';
        $filename = "ra_map_{$slug}_" . now()->format('Ymd_His') . '.html';
        $tmpPath  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($tmpPath, $html);

        try {
            $this->sendDocument(
                $chatId,
                $tmpPath,
                $filename,
                "🗺 *{$title}*\nOpen in any browser — drag, zoom, hover for details."
            );
        } finally {
            @unlink($tmpPath);
        }
    }

    // ---------------------------------------------------------

    private function sendDocument(int $chatId, string $filePath, string $filename, string $caption = ''): void
    {
        Http::attach('document', file_get_contents($filePath), $filename)
            ->post("https://api.telegram.org/bot{$this->telegramToken}/sendDocument", [
                'chat_id'    => $chatId,
                'caption'    => $caption,
                'parse_mode' => 'Markdown',
            ]);
    }

    private function helpText(): string
    {
        return <<<HELP
📖 *RA Bot Commands*

*Systems*
`/system add name|purpose|team`
`/system list`

*Integrations*
`/integration add sysA|sysB|purpose|type|direction|notes`
  Types: API, DB\_link, file\_exchange, message\_queue
  Direction: a\_to\_b, b\_to\_a, bidirectional
`/integration list [system_name]`

*Flows*
`/flow add source|target|int\_id|data\_type|format|frequency|freq\_note|notes`
  Frequency: realtime, hourly, daily, weekly, manual
`/flow list [system_name]`

*Reconciliation*
`/recon log flow\_id|date|exp\_count|act\_count|exp\_amount|act\_amount`
`/recon note recon\_id|note|root\_cause`
`/recon resolve recon\_id|resolution`

*Discovery*
`/map system\_name`          — text map: integrations & flows
`/maphtml [system\_name]`  — interactive HTML map (alias: /mh)
`/search keyword`           — search across all entities
HELP;
    }
}
