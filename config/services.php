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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    /*
    |--------------------------------------------------------------------------
    | Social Platform OAuth Credentials
    |--------------------------------------------------------------------------
    */

    'linkedin' => [
        'client_id'     => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri'  => env('LINKEDIN_REDIRECT_URI', env('APP_URL') . '/oauth/linkedin/callback'),
    ],

    'youtube' => [
        'client_id'     => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        'redirect_uri'  => env('YOUTUBE_REDIRECT_URI', env('APP_URL') . '/oauth/youtube/callback'),
    ],

    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect_uri'  => env('FACEBOOK_REDIRECT_URI', env('APP_URL') . '/oauth/facebook/callback'),
    ],

    'instagram' => [
        'client_id'     => env('INSTAGRAM_CLIENT_ID', env('FACEBOOK_CLIENT_ID')),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET', env('FACEBOOK_CLIENT_SECRET')),
        'redirect_uri'  => env('INSTAGRAM_REDIRECT_URI', env('APP_URL') . '/oauth/instagram/callback'),
    ],

    'twitter' => [
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect_uri'  => env('TWITTER_REDIRECT_URI', env('APP_URL') . '/oauth/twitter/callback'),
    ],

    'x' => [
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect_uri'  => env('TWITTER_REDIRECT_URI', env('APP_URL') . '/oauth/twitter/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social Provider Class Registry
    |--------------------------------------------------------------------------
    |
    | Maps platform identifiers to their provider implementation class.
    | Used by SocialAccountController and OAuthController to resolve providers.
    |
    */

    'social_providers' => [
        'linkedin'  => \App\Services\SocialProviders\LinkedInProvider::class,
        'youtube'   => \App\Services\SocialProviders\YouTubeProvider::class,
        'facebook'  => \App\Services\SocialProviders\FacebookProvider::class,
        'meta'      => \App\Services\SocialProviders\FacebookProvider::class,
        'instagram' => \App\Services\SocialProviders\InstagramProvider::class,
        'twitter'   => \App\Services\SocialProviders\TwitterProvider::class,
        'x'         => \App\Services\SocialProviders\TwitterProvider::class,
    ],


    /*
    |--------------------------------------------------------------------------
    | Dashboard URL (OAuth redirect target)
    |--------------------------------------------------------------------------
    */

    'dashboard_url' => env('DASHBOARD_URL', 'http://localhost:5173'),

];
