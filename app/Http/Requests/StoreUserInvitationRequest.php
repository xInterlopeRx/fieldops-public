<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', 'in:basic_user,management,billing'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roles = $this->input('roles', []);

            if (! is_array($roles) || ! in_array('basic_user', $roles, true)) {
                $validator->errors()->add('roles', 'New invitations start as Basic User. Transfer the Admin account role separately after account setup.');
            }
        }];
    }
}
