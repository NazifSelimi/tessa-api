<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hair_type_id'  => ['required', 'integer', 'exists:hair_types,id'],
            'concerns'      => ['required', 'array', 'min:1'],
            'concerns.*'    => ['integer', 'exists:hair_concerns,id'],
            'budget_range'  => ['nullable', 'string', 'max:30', 'regex:/^\d+(\.\d+)?-\d+(\.\d+)?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'budget_range.regex' => 'The budget range must be in the format "min-max" (e.g. "10-50").',
        ];
    }
}
