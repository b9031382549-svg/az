<?php

namespace App\Providers;

use App\Services\Embeddings\OllamaEmbedder;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OpenRouterClient::class, fn () => OpenRouterClient::fromConfig());
        $this->app->singleton(OllamaEmbedder::class, fn () => OllamaEmbedder::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
