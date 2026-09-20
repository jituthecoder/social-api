<?php

namespace App\Providers;

use App\Services\Contracts\SocialProviderInterface;
use App\Services\PublishingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Services\Contracts\AIServiceInterface::class,
            \App\Services\AIService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register social platform providers with the publishing engine.
        $this->app->afterResolving(PublishingService::class, function (PublishingService $service) {
            $providers = config('services.social_providers', []);

            foreach ($providers as $class) {
                if (class_exists($class)) {
                    $provider = $this->app->make($class);

                    if ($provider instanceof SocialProviderInterface) {
                        $service->registerProvider($provider);
                    }
                }
            }
        });
    }
}

