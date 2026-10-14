<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StopTimeEntryTimerRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->status === 'active'
            && $user->can('work_orders.manage');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'project_id' => ['prohibited'],
            'job_id' => ['prohibited'],
            'visit_id' => ['prohibited'],
            'activity_type' => ['prohibited'],
            'started_at' => ['prohibited'],
            'ended_at' => ['prohibited'],
            'duration_minutes' => ['prohibited'],
            'billable' => ['prohibited'],
            'notes' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
