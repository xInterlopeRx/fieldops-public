<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkOrderStatusRequest extends FormRequest
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
            'scheduling_override' => ['sometimes', 'boolean'],
            'timer_visit_id' => ['nullable', 'integer', 'exists:visits,id'],
        ];
    }
}
