<?php

namespace App\Http\Requests;

use App\Enums\PlanInterval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency')) {
            $this->merge([
                'currency' => strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('subscription_plans', 'name')],
            'interval' => ['required', Rule::enum(PlanInterval::class)],
            'duration_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:90',
                Rule::requiredIf(fn () => $this->input('interval') === PlanInterval::Trial->value),
                Rule::prohibitedIf(fn () => $this->input('interval') !== PlanInterval::Trial->value),
            ],
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'pager' => ['sometimes', 'boolean'],
            'menu' => ['sometimes', 'boolean'],
            'ordering' => ['sometimes', 'boolean'],
            'kitchen' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $menu = $this->exists('menu') ? $this->boolean('menu') : false;
            $ordering = $this->exists('ordering') ? $this->boolean('ordering') : false;

            if ($ordering && ! $menu) {
                $validator->errors()->add('ordering', 'Ordering requires the menu.');
            }
        });
    }
}
