<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\RedirectResponse;

class InvalidDestinationTransitionException extends Exception implements ShouldntReport
{
    /**
     * Show the rule to the user instead of an error page.
     */
    public function render(): RedirectResponse
    {
        return back()->with('error', $this->getMessage());
    }
}
