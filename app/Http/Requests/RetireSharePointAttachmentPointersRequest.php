<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class RetireSharePointAttachmentPointersRequest extends FormRequest
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
            'attachment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'attachment_ids.*' => ['required', 'integer', 'distinct', 'exists:sharepoint_attachments,id'],
            'remote_files_preserved' => ['required', 'accepted'],
            'sharepoint_admin_upn' => ['required', 'email', 'max:255'],
            'verification_reference' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_-]{0,14}[-:#][A-Za-z0-9][A-Za-z0-9._-]{0,48}$/D'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

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
            }
        }];
    }

    protected function passedValidation(): void
    {
        $this->scrubPassword();
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->scrubPassword();

        parent::failedValidation($validator);
    }

    private function scrubPassword(): void
    {
        $this->getInputSource()->remove('password');
        $this->query->remove('password');

        $originalRequest = $this->container->make('request');
        $originalInput = $originalRequest->isJson()
            ? $originalRequest->json()
            : $originalRequest->request;

        $originalInput->remove('password');
        $originalRequest->query->remove('password');
    }
}
