<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'allowed_users' => env('TELEGRAM_ALLOWED_USERS'),
    ],
    'telegram_gold' => [
        'token' => env('TELEGRAM_GOLD_TOKEN'),
        'allowed_users' => env('TELEGRAM_GOLD_ALLOWED_USERS'),
    ],


    'telegram_plan' => [
        'token'         => env('TELEGRAM_PLAN_BOT_TOKEN'),
        'allowed_users' => env('TELEGRAM_PLAN_BOT_ALLOWED_USERS', ''),
    ],
    'gold_api' => [
        'key' => env('MASSIVE_API_KEY'),
    ],

    'youtube' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'refresh_token' => env('YOUTUBE_REFRESH_TOKEN'),
    ],

    'facebook' => [
        'page_id'      => env('FB_PAGE_ID'),
        'access_token' => env('FB_PAGE_ACCESS_TOKEN'),
    ],

    'instagram' => [
        'ig_user_id'   => env('IG_USER_ID'),
        'access_token' => env('IG_ACCESS_TOKEN'),
    ],

    'tiktok' => [
        'access_token' => env('TIKTOK_ACCESS_TOKEN'),
    ],
];
