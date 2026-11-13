<?php

namespace App\Http\Requests;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Enums\CustomerGroup;
use App\Enums\CustomerStatus;
use App\Enums\UsState;
use App\Models\Customer;
use App\Services\CustomerAddressBookService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminCustomerWorkspaceRequest extends FormRequest
{
    private ?bool $billingAddressChangeCache = null;

    private ?bool $shippingAddressChangeCache = null;

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
        $billingAddressChanged = $this->billingAddressChanged();

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'customer_group' => ['nullable', Rule::enum(CustomerGroup::class)],
            'customer_status' => ['nullable', Rule::enum(CustomerStatus::class)],
            'notes' => ['nullable', 'string', 'max:20000'],
            'billing_address' => ['required', 'array'],
            'billing_address.line1' => [Rule::requiredIf($billingAddressChanged), 'nullable', 'string', 'max:500'],
            'billing_address.line2' => ['nullable', 'string', 'max:500'],
            'billing_address.line3' => ['nullable', 'string', 'max:500'],
            'billing_address.line4' => ['nullable', 'string', 'max:500'],
            'billing_address.line5' => ['nullable', 'string', 'max:500'],
            'billing_address.city' => [Rule::requiredIf($billingAddressChanged), 'nullable', 'string', 'max:255'],
            'billing_address.country_subdivision_code' => [Rule::requiredIf($billingAddressChanged), 'nullable', Rule::enum(UsState::class)],
            'billing_address.postal_code' => [Rule::requiredIf($billingAddressChanged), 'nullable', 'string', 'max:64'],
            'billing_address.country' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['sometimes', 'array'],
            'shipping_address.line1' => ['nullable', 'string', 'max:500'],
            'shipping_address.line2' => ['nullable', 'string', 'max:500'],
            'shipping_address.line3' => ['nullable', 'string', 'max:500'],
            'shipping_address.line4' => ['nullable', 'string', 'max:500'],
            'shipping_address.line5' => ['nullable', 'string', 'max:500'],
            'shipping_address.city' => ['nullable', 'string', 'max:255'],
            'shipping_address.country_subdivision_code' => ['nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => ['nullable', 'string', 'max:64'],
            'shipping_address.country' => ['nullable', 'string', 'max:100'],
            'sites' => ['present', 'array', 'max:100'],
            'sites.*.id' => ['nullable', 'integer'],
            'sites.*.name' => ['required', 'string', 'max:255'],
            'sites.*.address' => ['required', 'array'],
            'sites.*.address.line1' => ['required', 'string', 'max:500'],
            'sites.*.address.line2' => ['nullable', 'string', 'max:500'],
            'sites.*.address.line3' => ['nullable', 'string', 'max:500'],
            'sites.*.address.line4' => ['nullable', 'string', 'max:500'],
            'sites.*.address.line5' => ['nullable', 'string', 'max:500'],
            'sites.*.address.city' => ['required', 'string', 'max:255'],
            'sites.*.address.country_subdivision_code' => ['required', Rule::enum(UsState::class)],
            'sites.*.address.postal_code' => ['required', 'string', 'max:64'],
            'sites.*.address.country' => ['nullable', 'string', 'max:100'],
            'sites.*.primary_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'sites.*.primary_contact_key' => ['nullable', 'string', 'max:100'],
            'contacts' => ['present', 'array', 'max:100'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.client_key' => ['nullable', 'string', 'max:100', 'distinct'],
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

    public function billingAddressChanged(): bool
    {
        return $this->billingAddressChangeCache ??= $this->addressHasChanged('billing_address', 'billing', missingIsChanged: true);
    }

    public function shippingAddressChanged(): bool
    {
        return $this->shippingAddressChangeCache ??= $this->addressHasChanged('shipping_address', 'shipping', missingIsChanged: false);
    }

    private function addressHasChanged(string $inputKey, string $addressType, bool $missingIsChanged): bool
    {
        $customer = $this->route('customer');
        $submitted = $this->input($inputKey);

        if (! $customer instanceof Customer || ! is_array($submitted)) {
            return $missingIsChanged;
        }

        $current = app(CustomerAddressBookService::class)->defaultsForForm($customer, $addressType);

        foreach ([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ] as $field) {
            if ($this->normalizedAddressField($field, $submitted[$field] ?? null, submitted: true)
                !== $this->normalizedAddressField($field, $current[$field] ?? null, submitted: false)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedAddressField(string $field, mixed $value, bool $submitted): ?string
    {
        $value = is_string($value) && trim($value) !== '' ? trim($value) : null;

        if ($field !== 'country_subdivision_code' || $value === null) {
            return $value;
        }

        foreach (UsState::cases() as $state) {
            if (strcasecmp($state->value, $value) === 0 || strcasecmp($state->label(), $value) === 0) {
                return $state->value;
            }
        }

        return $submitted ? strtoupper($value) : null;
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $contacts = $this->input('contacts', []);
            $sites = $this->input('sites', []);

            if (is_array($contacts) && is_array($sites)) {
                $contactsByClientKey = collect($contacts)
                    ->filter(fn (mixed $contact): bool => is_array($contact) && is_string($contact['client_key'] ?? null))
                    ->keyBy('client_key');

                foreach ($sites as $siteIndex => $site) {
                    if (! is_array($site)) {
                        continue;
                    }

                    $clientKey = $site['primary_contact_key'] ?? null;

                    if (! is_string($clientKey) || $clientKey === '') {
                        continue;
                    }

                    if (isset($site['primary_contact_id'])) {
                        $validator->errors()->add(
                            "sites.{$siteIndex}.primary_contact_key",
                            'Choose an existing contact or add a new contact, not both.',
                        );

                        continue;
                    }

                    $contact = $contactsByClientKey->get($clientKey);

                    if (! is_array($contact) || filter_var($contact['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        $validator->errors()->add(
                            "sites.{$siteIndex}.primary_contact_key",
                            'The selected new Site contact is not available.',
                        );

                        continue;
                    }

                    if (trim((string) ($contact['first_name'] ?? '')) === '' && trim((string) ($contact['last_name'] ?? '')) === '') {
                        $contactIndex = array_search($contact, $contacts, true);

                        if ($contactIndex !== false) {
                            $validator->errors()->add(
                                "contacts.{$contactIndex}.first_name",
                                'Enter a first or last name for the Site contact.',
                            );
                        }
                    }
                }
            }

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
