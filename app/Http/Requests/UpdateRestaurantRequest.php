<?php

namespace App\Http\Requests;

use App\Enums\PreferredQrMode;
use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateRestaurantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => Str::lower(trim((string) $this->input('slug'))),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Restaurant $restaurant */
        $restaurant = $this->route('restaurant');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('restaurants', 'slug')->ignore($restaurant)],
            'preferred_qr_mode' => ['sometimes', 'required', Rule::enum(PreferredQrMode::class)],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'order_ttl_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
