<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class RestaurantStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::createFromFormat('Y-m-d', $this->string('from')->toString())->startOfDay();
            $to = Carbon::createFromFormat('Y-m-d', $this->string('to')->toString())->startOfDay();

            if ($from->addDays(89)->lt($to)) {
                $validator->errors()->add('to', 'The date range may not be longer than 90 days.');
            }
        });
    }
}
