<?php

namespace App\Providers;

use App\Models\DividendPayment;
use App\Observers\DividendPaymentObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DividendPayment::observe(DividendPaymentObserver::class);

        $appUrl = config('app.url');
        if (is_string($appUrl) && str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
            URL::forceRootUrl($appUrl);
        }
    }
}
