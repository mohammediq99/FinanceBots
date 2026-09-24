<?php

namespace App\Http\Controllers;

use App\Jobs\PublishToFacebook;
use App\Jobs\PublishToInstagram;
use App\Jobs\PublishToTikTok;
use App\Jobs\PublishToYouTube;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PublisherBotController extends Controller
{
    private string $token;
    private string $apiBase;

    public function __construct()
    {
        $this->token   = config('services.publisher_bot.token', env('PUBLISHER_BOT_TOKEN'));
        $this->apiBase = "https://api.telegram.org/bot{$this->token}";
    }

    public function webhook(Request $request)
    {
        $update  = $request->all();
        $message = $update['message'] ?? null;
        if (!$message) {
            return response('ok');
        }

        $chatId  = $message['chat']['id'];
        $caption = $message['caption'] ?? $message['text'] ?? '';

        if (!isset($message['video'])) {
            $this->reply($chatId, "Send me a *video* with a description as caption.");
            return response('ok');
        }

        // 1. Download video from Telegram
        $fileId   = $message['video']['file_id'];
        $localPath = $this->downloadTelegramFile($fileId);

        if (!$localPath) {
            $this->reply($chatId, "❌ Failed to download video.");
            return response('ok');
        }

        // 2. Dispatch a job per platform
        PublishToYouTube::dispatch($localPath, $caption, $chatId);
        PublishToFacebook::dispatch($localPath, $caption, $chatId);
        PublishToInstagram::dispatch($localPath, $caption, $chatId);
        PublishToTikTok::dispatch($localPath, $caption, $chatId);

        $this->reply($chatId, "✅ Received. Publishing to YouTube, Facebook, Instagram, TikTok…");
        return response('ok');
    }

    private function downloadTelegramFile(string $fileId): ?string
    {
        $res = Http::get("{$this->apiBase}/getFile", ['file_id' => $fileId])->json();
        $path = $res['result']['file_path'] ?? null;
        if (!$path) return null;

        $url      = "https://api.telegram.org/file/bot{$this->token}/{$path}";
        $binary   = Http::timeout(120)->get($url)->body();
        $filename = 'videos/' . uniqid('v_') . '.mp4';
        Storage::disk('local')->put($filename, $binary);

        return storage_path('app/' . $filename);
    }

    private function reply(int $chatId, string $text): void
    {
        Http::post("{$this->apiBase}/sendMessage", [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
