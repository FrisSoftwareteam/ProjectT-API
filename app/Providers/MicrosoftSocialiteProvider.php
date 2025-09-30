<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Contracts\Factory;

class MicrosoftSocialiteProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $socialite = $this->app->make(Factory::class);

        $socialite->extend('microsoft', function ($app) use ($socialite) {
            $config = $app['config']['services.microsoft'];
            return $socialite->buildProvider(
                \Laravel\Socialite\Two\MicrosoftProvider::class,
                $config
            );
        });
    }
}