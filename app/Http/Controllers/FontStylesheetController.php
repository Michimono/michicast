<?php

namespace App\Http\Controllers;

use App\Support\FontStylesheet;
use Illuminate\Http\Response;

/**
 * Serves the dev-server font CSS as a real stylesheet. Production links the
 * built file directly and never hits this route.
 */
class FontStylesheetController extends Controller
{
    public function __invoke(FontStylesheet $fonts): Response
    {
        $css = $fonts->devCss();

        abort_if($css === null, 404);

        return response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
