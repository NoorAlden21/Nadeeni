<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        self::prepareContact($this);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return self::fieldRules();
    }

    public static function prepareContact(FormRequest $request): void
    {
        $trimmed = [];

        foreach (['facebook_url', 'instagram_url', 'phone'] as $field) {
            $value = $request->input($field);

            if (is_string($value)) {
                $trimmed[$field] = trim($value);
            }
        }

        if ($trimmed !== []) {
            $request->merge($trimmed);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function fieldRules(): array
    {
        return [
            'facebook_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https', 'regex:/^https?:\/\/(?:www\.|m\.|mbasic\.|web\.)?(?:facebook\.com|fb\.com|fb\.me)(?:\/\S*)?$/i'],
            'instagram_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https', 'regex:/^https?:\/\/(?:www\.)?(?:instagram\.com|instagr\.am)(?:\/\S*)?$/i'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^(?=(?:\D*\d){6,})[0-9+()\s.-]{6,32}$/'],
        ];
    }
}
