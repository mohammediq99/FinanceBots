<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class PublishToTikTok implements ShouldQueue
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
        $token = config('services.tiktok.access_token');
        $size  = filesize($this->videoPath);

        // Step 1: init upload
        $init = Http::withToken($token)->post(
            'https://open.tiktokapis.com/v2/post/publish/video/init/',
            [
                'post_info' => [
                    'title'         => mb_substr($this->description, 0, 150),
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                ],
                'source_info' => [
                    'source'          => 'FILE_UPLOAD',
                    'video_size'      => $size,
                    'chunk_size'      => $size,
                    'total_chunk_count' => 1,
                ],
            ]
        )->json();

        $uploadUrl = $init['data']['upload_url'] ?? null;
        if (!$uploadUrl) { $this->notify("❌ TikTok init: " . json_encode($init)); return; }

        // Step 2: upload binary
        $upload = Http::withHeaders([
            'Content-Type'  => 'video/mp4',
            'Content-Range' => "bytes 0-" . ($size - 1) . "/{$size}",
        ])->withBody(file_get_contents($this->videoPath), 'video/mp4')
            ->put($uploadUrl);

        $this->notify($upload->successful()
            ? "✅ TikTok upload started (publish_id: " . ($init['data']['publish_id'] ?? '?') . ")"
            : "❌ TikTok upload failed: " . $upload->body());
    }

    private function notify(string $msg): void
    {
        Http::post("https://api.telegram.org/bot" . env('PUBLISHER_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $this->chatId, 'text' => $msg,
        ]);
    }
}
