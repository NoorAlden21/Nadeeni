<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanInterval;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubscriptionPlanRequest;
use App\Http\Requests\UpdateSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SubscriptionPlanResource::collection(
            SubscriptionPlan::query()->orderBy('name')->orderBy('id')->get()
        );
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::query()->create([
            'name' => $request->string('name')->toString(),
            'interval' => $request->string('interval')->toString(),
            'duration_days' => $request->input('interval') === PlanInterval::Trial->value
                ? $request->integer('duration_days')
                : null,
            'price' => $request->integer('price'),
            'currency' => $request->input('currency', 'USD'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new SubscriptionPlanResource($plan))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SubscriptionPlan $plan): SubscriptionPlanResource
    {
        return new SubscriptionPlanResource($plan);
    }

    public function update(UpdateSubscriptionPlanRequest $request, SubscriptionPlan $plan): SubscriptionPlanResource
    {
        $data = $request->validated();
        $interval = PlanInterval::from($data['interval'] ?? $plan->interval->value);

        if ($interval !== PlanInterval::Trial) {
            $data['duration_days'] = null;
        }

        $plan->update($data);

        return new SubscriptionPlanResource($plan);
    }

    public function destroy(SubscriptionPlan $plan): JsonResponse
    {
        if ($plan->restaurants()->exists()) {
            return response()->json([
                'message' => 'plan_in_use',
            ], 409);
        }

        $plan->delete();

        return response()->json([
            'message' => 'deleted',
        ]);
    }
}
