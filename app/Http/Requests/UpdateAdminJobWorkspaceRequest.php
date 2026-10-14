<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminJobWorkspaceRequest extends FormRequest
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
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'scope' => ['required', 'string', 'max:20000'],
            'job_notes' => ['nullable', 'string', 'max:20000'],
            'field_notes' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(array_keys(config('work_orders.statuses', [])))],
            'client_completion_summary' => [
                'nullable',
                Rule::requiredIf(fn (): bool => data_get(
                    config('work_orders.statuses', []),
                    $this->input('status').'.client_completion_summary_required',
                    false,
                )),
                'string',
                'max:5000',
            ],
            'pending_reason' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('status') === config('work_orders.pending_status')
                    && $this->route('workOrder')?->status !== config('work_orders.pending_status')),
                'string',
                'max:2000',
            ],
            'scheduled_at' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('status') === 'scheduled'
                    && $this->route('workOrder')?->status !== 'scheduled'
                    && ! $this->route('workOrder')?->visits()->exists()),
                'date',
            ],
            'timer_visit_id' => ['nullable', 'integer', 'exists:visits,id'],
            'client_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'technical_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'project_manager_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contractor_check_in_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'additional_contact_ids' => ['present', 'array', 'max:100'],
            'additional_contact_ids.*' => ['required', 'integer', 'distinct', 'exists:contacts,id'],
            'assigned_user_ids' => ['present', 'array', 'max:100'],
            'assigned_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('status', 'active')],
        ];
    }
}
