<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Http\ProblemDetails;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequestId::class);
        $middleware->throttleWithRedis();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(
                $request,
                401,
                'Unauthorized',
                'Authentication is required to access this resource.',
            );
        });

        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(
                $request,
                403,
                'Forbidden',
                'You are not allowed to perform this action.',
            );
        });

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            return ProblemDetails::response(
                $request,
                404,
                'Not Found',
                'The requested resource was not found.',
            );
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('v1/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $title = SymfonyResponse::$statusTexts[$status] ?? 'HTTP Error';

            return ProblemDetails::response(
                $request,
                $status,
                $title,
                $title.'.',
            );
        });
    })->create();
