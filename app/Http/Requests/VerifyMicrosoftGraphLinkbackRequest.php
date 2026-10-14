<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class VerifyMicrosoftGraphLinkbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D'],
        ];
    }

    /** @throws ValidationException */
    protected function failedValidation(Validator $validator): void
    {
        $this->getInputSource()->remove('token');
        $this->query->remove('token');

        $originalRequest = $this->container->make('request');
        $originalInput = $originalRequest->isJson()
            ? $originalRequest->json()
            : $originalRequest->request;
        $originalInput->remove('token');
        $originalRequest->query->remove('token');

        parent::failedValidation($validator);
    }
}
