<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\PublicListingCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After staff save anything for a company, drop its cached public catalogue so the website shows the change at once.
 * Listings, photos, units, partner apartments and website settings are edited in many places; clearing on every
 * staff write is simpler and safer than tracking each one, and costs one small query and a cache delete.
 *
 * The company comes from the {tenant} route parameter, because ResolveTenant releases "currentTenant" before this
 * outer middleware finishes.
 */
class ForgetPublicCatalogue
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            // Also after failed saves: part of a change may already be stored.
            $slug = $request->route()?->parameter('tenant');
            if (! $request->isMethodSafe() && is_string($slug) && $slug !== '') {
                $tenantId = Tenant::where('slug', $slug)->value('id');
                if ($tenantId) {
                    PublicListingCatalog::forget((int) $tenantId);
                }
            }
        }
    }
}
