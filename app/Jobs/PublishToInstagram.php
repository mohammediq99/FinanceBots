<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PublishToInstagram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public string $videoPath,
        public string $description,
        public int    $chatId,
    ) {}

    public function handle(): void
    {
        $igUserId = config('services.instagram.ig_user_id');
        $token    = config('services.instagram.access_token');

        // Instagram requires a PUBLIC URL to fetch the video
        $publicName = 'public/ig/' . basename($this->videoPath);
        Storage::disk('public')->put(basename($this->videoPath), file_get_contents($this->videoPath));
        $publicUrl = asset('storage/' . basename($this->videoPath));

        // Step 1: create container
        $container = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media", [
            'media_type'    => 'REELS',
            'video_url'     => $publicUrl,
            'caption'       => $this->description,
            'access_token'  => $token,
        ])->json();

        $creationId = $container['id'] ?? null;
        if (!$creationId) { $this->notify("❌ IG container: " . json_encode($container)); return; }

        // Step 2: wait until processed (poll)
        for ($i = 0; $i < 20; $i++) {
            sleep(6);
            $status = Http::get("https://graph.facebook.com/v19.0/{$creationId}", [
                'fields' => 'status_code', 'access_token' => $token,
            ])->json();
            if (($status['status_code'] ?? '') === 'FINISHED') break;
        }

        // Step 3: publish
        $publish = Http::post("https://graph.facebook.com/v19.0/{$igUserId}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $token,
        ])->json();

        $this->notify(isset($publish['id'])
            ? "✅ Instagram posted (id: {$publish['id']})"
            : "❌ Instagram: " . json_encode($publish));
    }

    private function notify(string $msg): void
    {
        Http::post("https://api.telegram.org/bot" . env('PUBLISHER_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $this->chatId, 'text' => $msg,
        ]);
    }
}
