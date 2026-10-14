<?php

namespace App\Http\Requests;

use App\Enums\TimeEntryActivityType;
use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StartTimeEntryTimerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->status === 'active'
            && $user->can('create', TimeEntry::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'job_id' => ['nullable', 'integer', 'exists:work_orders,id'],
            'visit_id' => ['nullable', 'integer', 'exists:visits,id'],
            'activity_type' => ['required', Rule::enum(TimeEntryActivityType::class)],
            'billable' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'user_id' => ['prohibited'],
            'started_at' => ['prohibited'],
            'ended_at' => ['prohibited'],
            'duration_minutes' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->filled('visit_id') && ! $this->filled('job_id')) {
                $validator->errors()->add('visit_id', 'Choose a Job before associating a Visit.');
            }
        }];
    }
}
