<?php

namespace App\Jobs;

use Google\Client;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class PublishToYouTube implements ShouldQueue
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
        $client = new Client();
        $client->setClientId(config('services.youtube.client_id'));
        $client->setClientSecret(config('services.youtube.client_secret'));
        $client->refreshToken(config('services.youtube.refresh_token'));

        $youtube = new YouTube($client);

        $snippet = new VideoSnippet();
        $snippet->setTitle(mb_substr($this->description, 0, 90) ?: 'Untitled');
        $snippet->setDescription($this->description);

        $status = new VideoStatus();
        $status->setPrivacyStatus('public');

        $video = new Video();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        $response = $youtube->videos->insert('snippet,status', $video, [
            'data'       => file_get_contents($this->videoPath),
            'mimeType'   => 'video/*',
            'uploadType' => 'multipart',
        ]);

        $this->notify("✅ YouTube: https://youtu.be/{$response->id}");
    }

    private function notify(string $msg): void
    {
        Http::post("https://api.telegram.org/bot" . env('PUBLISHER_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $this->chatId,
            'text'    => $msg,
        ]);
    }
}
