<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ProblemDetails
{
    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function response(
        Request $request,
        int $status,
        string $title,
        string $detail,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        $problem = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/'.$request->path(),
            'request_id' => self::requestId($request),
        ];

        foreach ($extensions as $name => $value) {
            if (! array_key_exists($name, $problem)) {
                $problem[$name] = $value;
            }
        }

        return response()->json($problem, $status, $headers + [
            'Content-Type' => 'application/problem+json',
        ]);
    }

    public static function requestId(Request $request): string
    {
        $requestId = $request->attributes->get('request_id');

        if (is_string($requestId)) {
            return $requestId;
        }

        $requestId = (string) Str::uuid7();
        $request->attributes->set('request_id', $requestId);

        return $requestId;
    }
}
