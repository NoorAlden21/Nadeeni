<?php

namespace App\Http\Requests;

use App\Enums\RestaurantRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantAccountRequest extends FormRequest
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
        /** @var User $account */
        $account = $this->route('account');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($account)],
            'password' => ['sometimes', 'required', 'string', 'min:8', 'max:255'],
            'restaurant_role' => ['sometimes', 'required', Rule::enum(RestaurantRole::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
