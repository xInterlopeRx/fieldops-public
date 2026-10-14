<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'scope' => ['required', 'string', 'max:20000'],
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
            'client_contact_name' => ['nullable', 'string', 'max:255'],
            'client_contact_phone' => ['nullable', 'string', 'max:50'],
            'client_contact_email' => ['nullable', 'email', 'max:255'],
            'client_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'technical_contact_name' => ['nullable', 'string', 'max:255'],
            'technical_contact_phone' => ['nullable', 'string', 'max:50'],
            'technical_contact_email' => ['nullable', 'email', 'max:255'],
            'technical_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'project_manager_name' => ['nullable', 'string', 'max:255'],
            'project_manager_phone' => ['nullable', 'string', 'max:50'],
            'project_manager_email' => ['nullable', 'email', 'max:255'],
            'project_manager_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'contractor_check_in_name' => ['nullable', 'string', 'max:255'],
            'contractor_check_in_phone' => ['nullable', 'string', 'max:50'],
            'contractor_check_in_email' => ['nullable', 'email', 'max:255'],
            'contractor_check_in_contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'job_notes' => [
                'nullable',
                Rule::requiredIf(fn (): bool => data_get(
                    config('work_orders.statuses', []),
                    $this->input('status').'.job_notes_required',
                    false,
                )),
                'string',
                'max:20000',
            ],
            'field_notes' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(array_keys(config('work_orders.statuses', [])))],
            'timer_visit_id' => ['nullable', 'integer', 'exists:visits,id'],
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
                    && ! $this->route('workOrder')?->visits()->exists()),
                'date',
            ],
            'scheduling_override' => ['sometimes', 'boolean'],
            'site_id' => ['required', 'integer', 'exists:sites,id'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,txt,csv,doc,docx,xls,xlsx,ppt,pptx'],
        ];
    }
}
