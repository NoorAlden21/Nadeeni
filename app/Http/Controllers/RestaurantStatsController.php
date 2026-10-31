<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestaurantStatsRequest;
use App\Services\RestaurantStats;
use Illuminate\Http\JsonResponse;

class RestaurantStatsController extends Controller
{
    public function __construct(private RestaurantStats $stats) {}

    public function show(RestaurantStatsRequest $request): JsonResponse
    {
        return response()->json($this->stats->summarize(
            $request->user(),
            $request->string('from')->toString(),
            $request->string('to')->toString(),
        ));
    }
}
