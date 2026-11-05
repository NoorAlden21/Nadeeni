<?php

namespace App\Http\Requests;

use App\Enums\PlanInterval;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionPlanRequest extends FormRequest
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
        /** @var SubscriptionPlan $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('subscription_plans', 'name')->ignore($plan)],
            'interval' => ['sometimes', 'required', Rule::enum(PlanInterval::class)],
            'duration_days' => [
                'nullable',
                'integer',
                'min:1',
                'max:90',
                Rule::requiredIf(fn () => $this->resolvedInterval() === PlanInterval::Trial->value && $plan->duration_days === null),
                Rule::prohibitedIf(fn () => $this->resolvedInterval() !== PlanInterval::Trial->value && $this->exists('duration_days')),
            ],
            'price' => ['sometimes', 'required', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'pager' => ['sometimes', 'boolean'],
            'menu' => ['sometimes', 'boolean'],
            'ordering' => ['sometimes', 'boolean'],
            'kitchen' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $plan = $this->route('plan');
            $menu = $this->exists('menu') ? $this->boolean('menu') : ($plan instanceof SubscriptionPlan && $plan->menu);
            $ordering = $this->exists('ordering') ? $this->boolean('ordering') : ($plan instanceof SubscriptionPlan && $plan->ordering);

            if ($ordering && ! $menu) {
                $validator->errors()->add('ordering', 'Ordering requires the menu.');
            }
        });
    }

    private function resolvedInterval(): ?string
    {
        $interval = $this->input('interval');

        if (is_string($interval)) {
            return $interval;
        }

        $plan = $this->route('plan');

        return $plan instanceof SubscriptionPlan ? $plan->interval->value : null;
    }
}
