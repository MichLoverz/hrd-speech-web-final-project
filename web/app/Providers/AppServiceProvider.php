<?php

namespace App\Providers;

use App\Services\Llm\ChatClient;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ChatClient takes its settings as an array; build it from config/services.php.
        $this->app->bind(ChatClient::class, fn () => ChatClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.hirenote');
        Paginator::defaultSimpleView('vendor.pagination.hirenote');
    }
}
