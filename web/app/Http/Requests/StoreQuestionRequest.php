<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Used for both store and update. */
class StoreQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:1000'],
            'position' => ['nullable', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'text' => 'teks pertanyaan',
            'position' => 'urutan',
        ];
    }
}
