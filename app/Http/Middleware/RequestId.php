<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ProblemDetails;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $suppliedRequestId = $request->header('X-Request-Id');
        $requestId = is_string($suppliedRequestId) && Str::isUuid($suppliedRequestId)
            ? strtolower($suppliedRequestId)
            : (string) Str::uuid7();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', ProblemDetails::requestId($request));

        return $response;
    }
}
