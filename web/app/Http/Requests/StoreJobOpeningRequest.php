<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both store and update. */
class StoreJobOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware + policies handle authorization.
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'department' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'salary_min' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'salary_max' => ['nullable', 'integer', 'min:0', 'max:999999999', 'gte:salary_min'],
            'status' => ['required', Rule::in(JobOpening::STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'title' => 'judul lowongan',
            'department' => 'departemen',
            'description' => 'deskripsi',
            'salary_min' => 'gaji minimum',
            'salary_max' => 'gaji maksimum',
            'status' => 'status',
        ];
    }
}
