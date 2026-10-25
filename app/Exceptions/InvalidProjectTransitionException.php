<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class InvalidProjectTransitionException extends Exception implements ShouldntReport
{
    /**
     * Show the rule to the user instead of an error page. The pipeline editor
     * changes status without leaving the canvas, so it gets the rule as JSON.
     */
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 409);
        }

        return back()->with('error', $this->getMessage());
    }
}
