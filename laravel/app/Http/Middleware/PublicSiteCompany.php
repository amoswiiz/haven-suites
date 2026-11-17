<?php

namespace App\Http\Middleware;

use App\Support\PublicSiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests on the main website domain have no "/stays/{company}" in the address. This puts the company first in the
 * route parameters, where the public controllers expect it (Laravel passes route values to controllers in order).
 */
class PublicSiteCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $parameters = $route->parameters();
        foreach (array_keys($parameters) as $name) {
            $route->forgetParameter($name);
        }
        $route->setParameter('company', PublicSiteUrl::company());
        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        return $next($request);
    }
}
