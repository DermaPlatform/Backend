<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve a page size from the request, clamped so a caller cannot ask for
     * the entire table in one response.
     */
    protected function perPage(Request $request): int
    {
        $requested = $request->integer('per_page', config('nadara.per_page'));

        return min(max($requested, 1), config('nadara.max_per_page'));
    }
}
