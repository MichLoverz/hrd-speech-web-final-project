<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Used for both store and update. */
class StoreCriterionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'weight' => ['required', 'integer', 'min:1', 'max:10'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama kriteria',
            'weight' => 'bobot',
            'description' => 'deskripsi',
        ];
    }
}
