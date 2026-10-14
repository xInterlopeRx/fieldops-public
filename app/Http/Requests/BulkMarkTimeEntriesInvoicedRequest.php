<?php

namespace App\Http\Requests;

use App\Models\TimeEntry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BulkMarkTimeEntriesInvoicedRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'exists:time_entries,id'],
        ];
    }
}
