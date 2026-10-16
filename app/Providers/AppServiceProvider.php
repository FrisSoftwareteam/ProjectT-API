<?php

namespace App\Providers;

use App\Models\DividendPayment;
use App\Observers\DividendPaymentObserver;
use App\Services\ShareholderAuditService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ShareholderAuditService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function ($name, $payload) use ($event) {
                app(ShareholderAuditService::class)->capture($event, $payload[0]);
            });
        }

        DividendPayment::observe(DividendPaymentObserver::class);

        $appUrl = config('app.url');
        if (is_string($appUrl) && str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
            URL::forceRootUrl($appUrl);
        }
    }
}
