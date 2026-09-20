<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage in routes: ->middleware('role:admin,hrd')
 * Admin always passes, so "role:hrd" really means "hrd or admin".
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->isAdmin() || $user->hasRole(...$roles)) {
            return $next($request);
        }

        abort(403, 'Peran akun Anda tidak punya akses ke halaman ini.');
    }
}
