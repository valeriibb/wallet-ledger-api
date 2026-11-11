<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\IdentityService;
use App\Application\Identity\TokenGrant;
use App\Http\ProblemDetails;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AuthController extends Controller
{
    public function __construct(private readonly IdentityService $identity) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, password_confirmation: string} $data */
        $data = $request->validated();
        $grant = $this->identity->register($data['name'], $data['email'], $data['password']);

        return response()->json([
            'data' => $this->userData($grant),
            'token' => $grant->plainTextToken,
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validated();

        $grant = $this->identity->authenticate($credentials['email'], $credentials['password']);

        if ($grant === null) {
            return ProblemDetails::response(
                $request,
                Response::HTTP_UNAUTHORIZED,
                'Unauthorized',
                'The provided credentials are invalid.',
            );
        }

        return response()->json([
            'data' => $this->userData($grant),
            'token' => $grant->plainTextToken,
        ]);
    }

    /** @return array{id: string, name: string, email: string} */
    private function userData(TokenGrant $grant): array
    {
        return [
            'id' => $grant->userId,
            'name' => $grant->name,
            'email' => $grant->email,
        ];
    }
}
