<?php

namespace App\Http\Requests\Api\V1;

use App\Services\RecommendationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hair quiz answers. Several hair types (pattern + thickness) and several
 * needs may be chosen; `hair_type_id` is still accepted from older clients.
 */
class RecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('hair_type_ids') && $this->filled('hair_type_id')) {
            $this->merge(['hair_type_ids' => [$this->input('hair_type_id')]]);
        }
    }

    public function rules(): array
    {
        return [
            'hair_type_ids'   => ['required', 'array', 'min:1', 'max:7'],
            'hair_type_ids.*' => ['integer', 'distinct', 'exists:hair_types,id'],
            'concerns'        => ['present', 'array', 'max:20'],
            'concerns.*'      => ['integer', 'distinct', 'exists:hair_concerns,id'],
            'steps'           => ['nullable', 'array'],
            'steps.*'         => ['string', Rule::in(array_keys(RecommendationService::ROUTINE_STEPS))],
            'budget_range'    => ['nullable', 'string', 'max:30', 'regex:/^\d+(\.\d+)?-\d+(\.\d+)?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'budget_range.regex' => 'The budget range must be in the format "min-max" (e.g. "10-50").',
        ];
    }
}
