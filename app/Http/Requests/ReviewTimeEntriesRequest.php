<?php

namespace App\Http\Requests;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTimeEntriesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['technician', 'customer', 'project'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => [
                'nullable',
                'date_format:Y-m-d',
                Rule::when($this->filled('date_from'), ['after_or_equal:date_from']),
            ],
            'technician' => ['nullable', 'string', 'max:100'],
            'customer' => ['nullable', 'string', 'max:100'],
            'project' => ['nullable', 'string', 'max:100'],
            'activity_type' => ['nullable', Rule::enum(TimeEntryActivityType::class)],
            'billable' => ['nullable', Rule::in(['0', '1'])],
            'status' => ['nullable', Rule::enum(TimeEntryApprovalStatus::class)],
        ];
    }
}
