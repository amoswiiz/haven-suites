<?php

namespace App\Http\Middleware;

use App\Support\PublicSiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Once the company website has moved to its own domain, its old app addresses ("/stays/haven/...") redirect there
 * permanently, so search rankings and shared links follow. Form submissions are still accepted where they are.
 */
class RedirectToPublicDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $request->route('company');
        if (is_string($company) && PublicSiteUrl::serves($company) && $request->isMethodSafe() && strtolower($request->getHost()) !== PublicSiteUrl::domain()) {
            $query = $request->getQueryString();

            return redirect()->to(PublicSiteUrl::canonical($request->getPathInfo()).($query ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
