<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class PublishToFacebook implements ShouldQueue
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
        $pageId = config('services.facebook.page_id');
        $token  = config('services.facebook.access_token');

        $response = Http::timeout(600)
            ->attach('source', file_get_contents($this->videoPath), basename($this->videoPath))
            ->post("https://graph-video.facebook.com/v19.0/{$pageId}/videos", [
                'description'  => $this->description,
                'access_token' => $token,
            ])->json();

        $id = $response['id'] ?? null;
        $this->notify($id
            ? "✅ Facebook posted (id: {$id})"
            : "❌ Facebook: " . json_encode($response));
    }

    private function notify(string $msg): void
    {
        Http::post("https://api.telegram.org/bot" . env('PUBLISHER_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $this->chatId, 'text' => $msg,
        ]);
    }
}
