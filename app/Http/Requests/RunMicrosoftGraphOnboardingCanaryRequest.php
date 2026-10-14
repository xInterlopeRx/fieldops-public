<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class RunMicrosoftGraphOnboardingCanaryRequest extends FormRequest
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
            'confirm_canary_action' => ['required', 'accepted'],
            'password' => ['required', 'string', 'max:255'],
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

    /** @throws ValidationException */
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
