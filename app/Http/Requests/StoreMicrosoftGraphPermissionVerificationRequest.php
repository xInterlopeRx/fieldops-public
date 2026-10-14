<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class StoreMicrosoftGraphPermissionVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->status === 'active'
            && $user->can('integrations.manage')
            && $user->hasVerifiedEmail()
            && $user->hasEnabledTwoFactorAuthentication();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'confirm_scope_verification' => ['required', 'accepted'],
            'm365_admin_upn' => ['required', 'email', 'max:255'],
            'verification_reference' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,14}[-:#][A-Za-z0-9][A-Za-z0-9._-]{0,48}$/D'],
            'mail_send_target_verified' => ['required', 'accepted'],
            'mail_send_non_target_denied' => ['required', 'accepted'],
            'calendar_write_target_verified' => ['required', 'accepted'],
            'calendar_write_non_target_denied' => ['required', 'accepted'],
            'contacts_boundary_verified' => ['required', 'accepted'],
            'sharepoint_boundary_verified' => ['required', 'accepted'],
            'sharepoint_retention_reviewed' => ['required', 'accepted'],
            'unscoped_app_roles_reviewed' => ['required', 'accepted'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'verification_reference.regex' => 'Use a short reference such as CHG-2026-1042. Start with a letter, include no spaces, and separate the prefix and reference with -, : or #.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();

            if ($user === null || ! Hash::check($this->string('password')->toString(), $user->getAuthPassword())) {
                $validator->errors()->add('password', __('auth.password'));

                return;
            }

        }];
    }

    protected function passedValidation(): void
    {
        $this->scrubSensitiveInputs();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->scrubSensitiveInputs();

        parent::failedValidation($validator);
    }

    private function scrubSensitiveInputs(): void
    {
        foreach (['password', 'totp_code'] as $field) {
            $this->getInputSource()->remove($field);
            $this->query->remove($field);
        }

        $originalRequest = $this->container->make('request');
        $originalInput = $originalRequest->isJson()
            ? $originalRequest->json()
            : $originalRequest->request;

        foreach (['password', 'totp_code'] as $field) {
            $originalInput->remove($field);
            $originalRequest->query->remove($field);
        }
    }
}
