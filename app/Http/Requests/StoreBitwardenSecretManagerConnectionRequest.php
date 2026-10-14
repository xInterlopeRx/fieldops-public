<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class StoreBitwardenSecretManagerConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->status === 'active'
            && $user->can('integrations.manage')
            && $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'runtime_access_token' => ['required', 'string', 'max:8192'],
            'organization_id' => ['required', 'uuid'],
            'project_id' => ['required', 'uuid'],
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

            $runtimeAccessToken = $this->string('runtime_access_token')->toString();

            if (strlen($runtimeAccessToken) > 8192 || $runtimeAccessToken !== trim($runtimeAccessToken)) {
                $validator->errors()->add('runtime_access_token', 'Enter a valid BWS runtime reader token.');

                return;
            }

            $user = $this->user();

            if ($user === null || ! Hash::check($this->string('password')->toString(), $user->getAuthPassword())) {
                $validator->errors()->add('password', __('auth.password'));

                return;
            }

        }];
    }

    /**
     * Sensitive credentials must not be flashed into the session after validation errors.
     *
     * @throws ValidationException
     */
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
        $sensitiveFields = ['runtime_access_token', 'password', 'totp_code'];

        foreach ($sensitiveFields as $key) {
            $this->getInputSource()->remove($key);
            $this->query->remove($key);
        }

        // FormRequest is a clone; the exception handler flashes input from the original request.
        $originalRequest = $this->container->make('request');
        $originalInput = $originalRequest->isJson()
            ? $originalRequest->json()
            : $originalRequest->request;

        foreach ($sensitiveFields as $key) {
            $originalInput->remove($key);
            $originalRequest->query->remove($key);
        }

    }
}
