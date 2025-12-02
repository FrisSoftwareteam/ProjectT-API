<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\SocialiteManager;
use App\Services\MicrosoftProvider;

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
        /** @var SocialiteManager $socialite */
        $socialite = $this->app->make('Laravel\Socialite\Contracts\Factory');

        $socialite->extend('microsoft', function ($app) use ($socialite) {
            $config = $app['config']['services.microsoft'];
            return $socialite->buildProvider(
                MicrosoftProvider::class,
                $config
            );
        });
    }
}