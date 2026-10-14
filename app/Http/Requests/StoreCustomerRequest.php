<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:500'],
            'billing_address.line2' => ['nullable', 'string', 'max:500'],
            'billing_address.line3' => ['nullable', 'string', 'max:500'],
            'billing_address.line4' => ['nullable', 'string', 'max:500'],
            'billing_address.line5' => ['nullable', 'string', 'max:500'],
            'billing_address.city' => ['nullable', 'string', 'max:255'],
            'billing_address.country_subdivision_code' => ['nullable', 'string', 'max:255'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:64'],
            'billing_address.country' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['nullable', 'array'],
            'shipping_address.line1' => ['nullable', 'string', 'max:500'],
            'shipping_address.line2' => ['nullable', 'string', 'max:500'],
            'shipping_address.line3' => ['nullable', 'string', 'max:500'],
            'shipping_address.line4' => ['nullable', 'string', 'max:500'],
            'shipping_address.line5' => ['nullable', 'string', 'max:500'],
            'shipping_address.city' => ['nullable', 'string', 'max:255'],
            'shipping_address.country_subdivision_code' => ['nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:64'],
            'shipping_address.country' => ['nullable', 'string', 'max:100'],
        ];
    }
}
