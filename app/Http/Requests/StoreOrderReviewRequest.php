<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('message') || ! is_string($this->input('message'))) {
            return;
        }

        $message = trim($this->input('message'));

        $this->merge([
            'message' => $message === '' ? null : $message,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'service_rating' => ['required', 'integer', 'between:1,5'],
            'food_rating' => ['required', 'integer', 'between:1,5'],
            'message' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
