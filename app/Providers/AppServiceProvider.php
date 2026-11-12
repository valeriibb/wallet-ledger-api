<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Account\Port\AccountReader;
use App\Application\Account\Port\AccountStore;
use App\Application\Idempotency\Port\IdempotencyExecutor;
use App\Application\Ledger\Port\LedgerStore;
use App\Application\Transfer\Port\TransferStore;
use App\Http\ProblemDetails;
use App\Infrastructure\Account\PostgresAccountReader;
use App\Infrastructure\Account\PostgresAccountStore;
use App\Infrastructure\Idempotency\PostgresIdempotencyExecutor;
use App\Infrastructure\Ledger\PostgresLedgerStore;
use App\Infrastructure\Transfer\PostgresTransferStore;
use App\Models\Account;
use App\Policies\AccountPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AccountReader::class, PostgresAccountReader::class);
        $this->app->bind(AccountStore::class, PostgresAccountStore::class);
        $this->app->bind(IdempotencyExecutor::class, PostgresIdempotencyExecutor::class);
        $this->app->bind(LedgerStore::class, PostgresLedgerStore::class);
        $this->app->bind(TransferStore::class, PostgresTransferStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Account::class, AccountPolicy::class);

        RateLimiter::for('login', static function (Request $request): Limit {
            $emailInput = $request->input('email');
            $email = is_string($emailInput) ? mb_strtolower(trim($emailInput)) : '';
            $key = hash('sha256', $request->ip().'|'.$email);

            return Limit::perMinute(5)
                ->by($key)
                ->after(static fn (Response $response): bool => $response->getStatusCode() === Response::HTTP_UNAUTHORIZED)
                ->response(static function (Request $request, array $headers) {
                    /** @var array<string, string> $headers */
                    return ProblemDetails::response(
                        $request,
                        Response::HTTP_TOO_MANY_REQUESTS,
                        'Too Many Requests',
                        'Too many login attempts. Please try again later.',
                        headers: $headers,
                    );
                });
        });
    }
}
