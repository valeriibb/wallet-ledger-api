<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\ProblemDetails;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(ProblemDetails::response(
            $this,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Unprocessable Content',
            'One or more fields are invalid.',
            ['errors' => $validator->errors()->toArray()],
        ));
    }
}
