<?php

namespace App\Http\Controllers;

use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedQrController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'url' => FrontendUrl::restaurant($request->user()->restaurant->slug),
        ]);
    }
}
