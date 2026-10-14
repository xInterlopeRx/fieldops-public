<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreMicrosoftGraphOnboardingTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active'
            && ($this->user()?->can('integrations.manage') ?? false);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'notification_mailbox_id' => ['required', 'uuid'],
            'notification_mailbox_address' => ['required', 'string', 'email', 'max:255'],
            'target_is_dedicated_shared_mailbox' => ['required', 'accepted'],
            'notification_calendar_id' => ['nullable', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D'],
            'work_schedules_group_id' => ['required', 'uuid'],
            'work_schedules_site_id' => ['required', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D'],
            'work_schedules_drive_id' => ['required', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D'],
            'business_attachments_site_id' => ['nullable', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D'],
            'business_attachments_drive_id' => ['nullable', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D'],
            'non_target_mailbox_email' => ['nullable', 'email', 'max:255', 'different:notification_mailbox_address'],
            'non_target_is_user_mailbox' => ['nullable', 'boolean'],
            'non_target_site_id' => ['nullable', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D', 'different:work_schedules_site_id', 'different:business_attachments_site_id'],
            'non_target_drive_id' => ['nullable', 'string', 'max:512', 'regex:/^[^\x00-\x20\x7F]+$/D', 'different:work_schedules_drive_id', 'different:business_attachments_drive_id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'target_is_dedicated_shared_mailbox.accepted' => 'Confirm that the supplied object ID belongs to the dedicated shared mailbox specified by your Microsoft 365 administrator.',
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $pairs = [
                ['non_target_mailbox_email', 'notification_mailbox_address'],
                ['non_target_site_id', 'work_schedules_site_id'],
                ['non_target_site_id', 'business_attachments_site_id'],
                ['non_target_drive_id', 'work_schedules_drive_id'],
                ['non_target_drive_id', 'business_attachments_drive_id'],
            ];

            foreach ($pairs as [$control, $target]) {
                $controlId = $this->input($control);
                $targetId = $this->input($target);

                if (is_string($controlId) && is_string($targetId)
                    && $controlId !== '' && $targetId !== ''
                    && hash_equals(strtolower($controlId), strtolower($targetId))) {
                    $validator->errors()->add($control, 'A non-target mailbox email must differ from the selected notification mailbox.');
                }
            }

            if (filled($this->input('non_target_mailbox_email'))
                && ! in_array($this->input('non_target_is_user_mailbox'), [true, 1, '1', 'true', 'on'], true)) {
                $validator->errors()->add(
                    'non_target_is_user_mailbox',
                    'Confirm that the non-target control is an existing same-tenant user mailbox supplied by your Microsoft 365 administrator.',
                );
            }
        }];
    }
}
