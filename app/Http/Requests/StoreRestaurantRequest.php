<?php

namespace App\Http\Requests;

use App\Enums\PreferredQrMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreRestaurantRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('restaurants', 'slug')],
            'preferred_qr_mode' => ['sometimes', Rule::enum(PreferredQrMode::class)],
            'timezone' => ['sometimes', 'timezone:all'],
            'order_ttl_minutes' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'plan_id' => ['required', 'integer', Rule::exists('subscription_plans', 'id')->where('is_active', true)],
        ];
    }
}
