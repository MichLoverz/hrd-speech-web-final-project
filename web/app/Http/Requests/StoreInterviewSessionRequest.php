<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInterviewSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'candidate_id' => ['required', 'integer', 'exists:candidates,id'],
            'interviewer_id' => ['required', 'integer', 'exists:users,id'],
            'panel' => ['nullable', 'array'],
            'panel.*' => ['integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'candidate_id' => 'kandidat',
            'interviewer_id' => 'pewawancara utama',
            'panel' => 'panel',
            'notes' => 'catatan',
        ];
    }
}
