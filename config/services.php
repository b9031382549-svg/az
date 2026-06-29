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

    // External LLM (chat / NL->SQL / re-ranking) via OpenRouter.
    'openrouter' => [
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        // Two-tier re-ranking (ТЗ: cheap/local-equivalent model first, strong
        // model as fallback). tier-1 does the bulk; an uncertain pick (low
        // confidence OR weak semantic backing) is escalated to classify_model.
        // tier-1 is a small, self-hostable model (Qwen 2.5 7B) run via OpenRouter;
        // swap to a local Ollama endpoint later without touching the flow.
        'classify_model_tier1' => env('OPENROUTER_CLASSIFY_TIER1_MODEL', 'qwen/qwen-2.5-7b-instruct'),
        // Stronger fallback model for the re-ranking (better accuracy and
        // confidence calibration). Override per environment.
        'classify_model' => env('OPENROUTER_CLASSIFY_MODEL', 'openai/gpt-4o'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
    ],

    // Local embedding model served by Ollama (catalog similarity search).
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://ollama:11434'),
        'embed_model' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
        'dimensions' => (int) env('EMBED_DIMENSIONS', 1024),
    ],

];
