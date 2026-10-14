<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminProjectWorkspaceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:20000'],
            'status' => ['required', Rule::in(['created', 'scheduled', 'planning', 'active', 'on_hold', 'completed', 'cancelled'])],
            'site_ids' => ['present', 'array', 'max:100'],
            'site_ids.*' => ['required', 'integer', 'distinct', 'exists:sites,id'],
            'assigned_user_ids' => ['present', 'array', 'max:100'],
            'assigned_user_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('status', 'active')],
        ];
    }
}
