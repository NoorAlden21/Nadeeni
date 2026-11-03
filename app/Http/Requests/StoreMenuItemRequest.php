<?php

namespace App\Http\Requests;

use App\Rules\MenuPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('menu_categories', 'id')->where('restaurant_id', $this->user()->restaurant_id),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_available' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', new MenuPhoto],
        ];
    }
}
