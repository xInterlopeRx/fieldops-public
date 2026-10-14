<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class StoreFirstAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ! $this->user();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?\z/'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_security' => [
                'required',
                Rule::in(app()->environment('local') ? ['starttls', 'ssl', 'none'] : ['starttls', 'ssl']),
            ],
            'smtp_username' => ['nullable', 'string', 'max:255', 'required_with:smtp_password'],
            'smtp_password' => ['nullable', 'string', 'max:1024', 'required_with:smtp_username'],
            'smtp_from_address' => ['required', 'email', 'max:255'],
            'smtp_from_name' => ['required', 'string', 'max:128', 'regex:/\A[^\r\n]+\z/u'],
        ];
    }
}
