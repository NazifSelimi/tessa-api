<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RequestStylistRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'saloon_name' => ['required', 'string', 'max:120'],
            'saloon_city' => ['required', 'string', 'max:120'],
            'saloon_address' => ['required', 'string', 'max:255'],
            'saloon_phone' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
