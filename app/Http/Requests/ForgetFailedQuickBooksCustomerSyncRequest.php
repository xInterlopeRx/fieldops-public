<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class ForgetFailedQuickBooksCustomerSyncRequest extends FormRequest
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
            'confirm_forget' => ['required', 'accepted'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_forget.accepted' => 'Confirm that this failed QuickBooks job record can be permanently forgotten without retrying it.',
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
