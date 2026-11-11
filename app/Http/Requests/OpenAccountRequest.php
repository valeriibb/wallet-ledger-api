<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class OpenAccountRequest extends ApiRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
        ];
    }
}
