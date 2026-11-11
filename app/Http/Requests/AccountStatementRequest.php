<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Closure;
use Illuminate\Pagination\Cursor;

final class AccountStatementRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => [
                'sometimes',
                'nullable',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || Cursor::fromEncoded($value) === null) {
                        $fail("The {$attribute} is invalid.");
                    }
                },
            ],
        ];
    }
}
