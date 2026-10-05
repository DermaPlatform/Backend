<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hardening headers for every API response.
 *
 * The API returns JSON and the files on the private disk: payment receipts
 * and clinical photographs that patients upload. None of it is ever meant to
 * render as a page, so the policy forbids everything a document could do. If
 * an upload ever slipped past the type check, opening it directly would run
 * nothing and could not be framed.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*')) {
            return $response;
        }

        $response->headers->add([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
