<?php

namespace App\Http\Requests;

use App\Models\Candidate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both store and update. */
class StoreCandidateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_opening_id' => ['required', 'integer', 'exists:job_openings,id'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'expected_salary' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'status' => ['required', Rule::in(Candidate::STATUSES)],
            // CV upload: PDF only, max 5 MB. Stored privately, never served directly.
            'cv' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }

    public function attributes(): array
    {
        return [
            'job_opening_id' => 'lowongan',
            'name' => 'nama kandidat',
            'email' => 'email',
            'phone' => 'telepon',
            'expected_salary' => 'ekspektasi gaji',
            'status' => 'status',
            'cv' => 'berkas CV',
        ];
    }
}
