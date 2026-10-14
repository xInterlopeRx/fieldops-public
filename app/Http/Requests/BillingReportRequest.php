<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BillingReportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('date_from') || $this->filled('date_to')) {
            return;
        }

        $now = CarbonImmutable::now(config('app.timezone'));

        $this->merge([
            'date_from' => $now->startOfMonth()->toDateString(),
            'date_to' => $now->endOfMonth()->toDateString(),
        ]);
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
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['date_from', 'date_to'])) {
                return;
            }

            $from = CarbonImmutable::parse($this->validated('date_from'), config('app.timezone'));
            $to = CarbonImmutable::parse($this->validated('date_to'), config('app.timezone'));
            $inclusiveDays = $from->diffInDays($to) + 1;
            $maxDays = (int) config('time_tracking.billing.max_date_range_days', 366);

            if ($inclusiveDays > $maxDays) {
                $validator->errors()->add('date_to', "Choose a date range of no more than {$maxDays} days.");
            }
        }];
    }
}
