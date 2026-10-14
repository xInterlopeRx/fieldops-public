<?php

namespace App\Http\Requests;

use App\Enums\TimeEntryActivityType;
use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReviewedTimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reviewAny', TimeEntry::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],
            'duration_minutes' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'activity_type' => ['required', Rule::enum(TimeEntryActivityType::class)],
            'billable' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
