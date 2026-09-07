<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return response()->json([
                'application' => 'ok',
                'database' => 'unavailable',
            ], 503);
        }

        return response()->json([
            'application' => 'ok',
            'database' => 'ok',
        ]);
    }
}
