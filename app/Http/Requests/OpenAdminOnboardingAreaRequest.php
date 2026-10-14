<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class OpenAdminOnboardingAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->isAdmin()
            && $user->can('integrations.manage')
            && $user->hasEnabledTwoFactorAuthentication();
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'totp_code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(TwoFactorAuthenticationProvider $twoFactor): array
    {
        return [function (Validator $validator) use ($twoFactor): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $user = $this->user();

            if ($user === null || ! Hash::check($this->string('password')->toString(), $user->getAuthPassword())) {
                $validator->errors()->add('password', __('auth.password'));

                return;
            }

            try {
                $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            } catch (DecryptException) {
                $validator->errors()->add('totp_code', 'Enter a fresh authenticator code.');

                return;
            }

            if (! $twoFactor->verify($secret, $this->string('totp_code')->toString())) {
                $validator->errors()->add('totp_code', 'Enter a fresh authenticator code.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        $this->scrubSensitiveInputs();
    }

    /**
     * Do not flash passwords or authenticator codes into the session.
     *
     * @throws ValidationException
     */
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
