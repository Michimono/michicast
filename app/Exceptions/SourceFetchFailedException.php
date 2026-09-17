<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * A source provider could not produce items. Callers turn this into a user-
 * visible result; it is not an unexpected fault.
 */
class SourceFetchFailedException extends Exception implements ShouldntReport
{
    //
}
