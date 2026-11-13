<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'security_groups' => ['present', 'array', 'max:10'],
            'security_groups.*' => ['required', 'string', 'distinct', 'in:finance,hr'],
            'notification_roles' => ['sometimes', 'array', 'max:2'],
            'notification_roles.*' => ['required', 'string', 'distinct', 'in:management,billing'],
        ];
    }
}
