<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $orderNumber = $this->input('order_number');

        if (is_string($orderNumber)) {
            $this->merge([
                'order_number' => trim($orderNumber),
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'order_number' => ['required', 'string', 'max:32'],
        ];
    }
}
