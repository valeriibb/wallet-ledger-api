<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ReverseTransactionRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/D'],
        ];
    }
}
