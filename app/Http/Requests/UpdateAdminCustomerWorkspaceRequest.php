<?php

namespace App\Http\Requests;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminCustomerWorkspaceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('admin.access') ?? false;
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
            'company_name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'title' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'alternate_phone' => ['nullable', 'string', 'max:50'],
            'mobile_phone' => ['nullable', 'string', 'max:50'],
            'fax' => ['nullable', 'string', 'max:50'],
            'website' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'billing_address' => ['required', 'array'],
            'billing_address.line1' => ['nullable', 'string', 'max:500'],
            'billing_address.line2' => ['nullable', 'string', 'max:500'],
            'billing_address.line3' => ['nullable', 'string', 'max:500'],
            'billing_address.line4' => ['nullable', 'string', 'max:500'],
            'billing_address.line5' => ['nullable', 'string', 'max:500'],
            'billing_address.city' => ['nullable', 'string', 'max:255'],
            'billing_address.country_subdivision_code' => ['nullable', 'string', 'max:20'],
            'billing_address.postal_code' => ['nullable', 'string', 'max:40'],
            'billing_address.country' => ['nullable', 'string', 'max:255'],
            'sites' => ['present', 'array', 'max:100'],
            'sites.*.id' => ['nullable', 'integer'],
            'sites.*.name' => ['required', 'string', 'max:255'],
            'sites.*.address' => ['nullable', 'string', 'max:1000'],
            'contacts' => ['present', 'array', 'max:100'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.site_id' => ['nullable', 'integer'],
            'contacts.*.remove' => ['sometimes', 'boolean'],
            'contacts.*.contact_type' => ['required', 'string', 'max:40'],
            'contacts.*.first_name' => ['nullable', 'string', 'max:100'],
            'contacts.*.last_name' => ['nullable', 'string', 'max:100'],
            'contacts.*.middle_name' => ['nullable', 'string', 'max:100'],
            'contacts.*.title' => ['nullable', 'string', 'max:100'],
            'contacts.*.suffix' => ['nullable', 'string', 'max:50'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.alternate_phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.mobile_phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.fax' => ['nullable', 'string', 'max:50'],
            'contacts.*.website' => ['nullable', 'url', 'max:255'],
            'contacts.*.methods' => ['sometimes', 'array', 'max:100'],
            'contacts.*.methods.*.id' => ['nullable', 'integer'],
            'contacts.*.methods.*.method_type' => ['required', Rule::enum(ContactMethodType::class)],
            'contacts.*.methods.*.label' => ['required', Rule::enum(ContactMethodLabel::class)],
            'contacts.*.methods.*.value' => Rule::forEach(function (mixed $value, string $attribute): array {
                $segments = explode('.', $attribute);
                $type = $this->input('contacts.'.$segments[1].'.methods.'.$segments[3].'.method_type');

                return $type === ContactMethodType::Email->value
                    ? ['required', 'string', 'email', 'max:255']
                    : ['required', 'string', 'max:1000'];
            }),
            'contacts.*.methods.*.is_primary' => ['required', 'boolean'],
            'email_addresses' => ['present', 'array', 'max:100'],
            'email_addresses.*.id' => ['nullable', 'integer'],
            'email_addresses.*.email' => ['required', 'email', 'max:255'],
            'email_addresses.*.label' => ['nullable', 'string', 'max:100'],
            'email_addresses.*.remove' => ['sometimes', 'boolean'],
            'assigned_user_ids' => ['present', 'array', 'max:100'],
            'assigned_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('status', 'active')],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $contacts = $this->input('contacts', []);

            if (! is_array($contacts)) {
                return;
            }

            foreach ($contacts as $contactIndex => $contact) {
                if (! is_array($contact) || ! is_array($contact['methods'] ?? null)) {
                    continue;
                }

                $primaryMethodIndexes = [];

                foreach ($contact['methods'] as $methodIndex => $method) {
                    if (
                        ! is_array($method) ||
                        ! filter_var($method['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ) {
                        continue;
                    }

                    $methodTypeValue = $method['method_type'] ?? null;

                    if (! is_string($methodTypeValue)) {
                        continue;
                    }

                    $methodType = ContactMethodType::tryFrom($methodTypeValue);

                    if ($methodType === null) {
                        continue;
                    }

                    if (isset($primaryMethodIndexes[$methodType->value])) {
                        $validator->errors()->add(
                            "contacts.{$contactIndex}.methods.{$methodIndex}.is_primary",
                            "Only one {$methodType->label()} method per Contact can be primary.",
                        );

                        continue;
                    }

                    $primaryMethodIndexes[$methodType->value] = $methodIndex;
                }
            }
        }];
    }
}
