<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBootstrapSmtpTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $useSavedConfiguration = $this->boolean('use_saved_configuration');

        return [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'use_saved_configuration' => ['sometimes', 'boolean'],
            'smtp_host' => [
                Rule::requiredIf(! $useSavedConfiguration),
                'nullable',
                'string',
                'max:255',
                'regex:/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/',
            ],
            'smtp_port' => [Rule::requiredIf(! $useSavedConfiguration), 'nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_security' => [
                Rule::requiredIf(! $useSavedConfiguration),
                'nullable',
                Rule::in(app()->environment('local') ? ['starttls', 'ssl', 'none'] : ['starttls', 'ssl']),
            ],
            'smtp_username' => ['nullable', 'string', 'max:255', 'required_with:smtp_password'],
            'smtp_password' => ['nullable', 'string', 'max:1024', 'required_with:smtp_username'],
            'smtp_from_address' => [Rule::requiredIf(! $useSavedConfiguration), 'nullable', 'email', 'max:255'],
            'smtp_from_name' => [
                Rule::requiredIf(! $useSavedConfiguration),
                'nullable',
                'string',
                'max:128',
                'regex:/\A[^\r\n]+\z/u',
            ],
        ];
    }
}
