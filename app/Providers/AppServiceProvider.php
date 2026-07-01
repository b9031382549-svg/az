<?php

namespace App\Providers;

use App\Services\Classify\Mechanisms\BrokerDescentMechanism;
use App\Services\Classify\Mechanisms\ClassifierMechanism;
use App\Services\Classify\Mechanisms\MechanismRegistry;
use App\Services\Classify\Mechanisms\VectorMechanism;
use App\Services\Embeddings\OllamaEmbedder;
use App\Services\Llm\OpenRouterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Map of mechanism key => implementation. Add new mechanisms here; the
     * registry instantiates the ones listed in config('classify.mechanisms.enabled').
     *
     * @var array<string, class-string<ClassifierMechanism>>
     */
    private const MECHANISMS = [
        'vector' => VectorMechanism::class,
        'broker' => BrokerDescentMechanism::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OpenRouterClient::class, fn () => OpenRouterClient::fromConfig());
        $this->app->singleton(OllamaEmbedder::class, fn () => OllamaEmbedder::fromConfig());

        $this->app->singleton(MechanismRegistry::class, function ($app) {
            $registry = new MechanismRegistry;
            foreach ((array) config('classify.mechanisms.enabled', ['vector']) as $key) {
                $class = self::MECHANISMS[$key] ?? null;
                if ($class !== null) {
                    $registry->register($app->make($class));
                }
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
