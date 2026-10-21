<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auditors can open every page but change nothing. Rather than adding an auditor check to every
 * write endpoint (and to every one added later), any request that is not a read is refused here.
 *
 * Signing out and changing their own password are the only writes an auditor keeps.
 */
class BlockAuditorWrites
{
    private const ALLOWED = ['api/logout', 'api/profile/password'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAuditor() && ! $request->isMethodSafe() && ! $request->is(...self::ALLOWED)) {
            abort(403, 'Auditors have view-only access.');
        }

        return $next($request);
    }
}
