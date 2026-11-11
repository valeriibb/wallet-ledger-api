<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ledger\Port\LedgerStore;
use App\Infrastructure\Ledger\PostgresLedgerStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LedgerStore::class, PostgresLedgerStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
