<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'in:admin,basic_user,management,billing'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roles = $this->input('roles', []);

            $coreRoles = is_array($roles)
                ? array_filter($roles, fn (mixed $role): bool => is_string($role) && in_array($role, ['admin', 'basic_user'], true))
                : [];

            if (count($coreRoles) !== 1) {
                $validator->errors()->add('roles', 'Choose exactly one account role: Admin or Basic User.');
            }
        }];
    }
}
