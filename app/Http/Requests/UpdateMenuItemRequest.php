<?php

namespace App\Http\Requests;

use App\Enums\MenuItemBadge;
use App\Rules\MenuPhoto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
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
                'sometimes',
                'integer',
                Rule::exists('menu_categories', 'id')->where('restaurant_id', $this->user()->restaurant_id),
            ],
            'name_ar' => ['sometimes', 'required', 'string', 'max:255'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_available' => ['sometimes', 'boolean'],
            'badge' => ['sometimes', 'nullable', Rule::enum(MenuItemBadge::class)],
            'photo' => ['sometimes', new MenuPhoto],
        ];
    }
}
