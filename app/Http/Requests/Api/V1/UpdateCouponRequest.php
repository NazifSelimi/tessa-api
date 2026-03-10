<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCouponRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:50'],
            'type' => ['sometimes', 'in:percentage,fixed'],
            'value' => ['sometimes', 'numeric', 'gt:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'expiration_date' => ['sometimes', 'date'],
        ];
    }
}
