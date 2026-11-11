<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class TransferFundsRequest extends ApiRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'uuid'],
            'to_account_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
