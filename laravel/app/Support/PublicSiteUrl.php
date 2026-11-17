<?php

namespace App\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;

/**
 * The company website on its own domain. When PUBLIC_SITE_DOMAIN is set (for example havensuitesbnb.com), the
 * public pages of PUBLIC_SITE_COMPANY are served there without the "/stays/{company}" prefix, and every link to
 * them is written with that domain. Other companies, and staff pages, stay on the app address.
 */
final class PublicSiteUrl
{
    /** Root of the app address the last public-site link was being built on; see register(). */
    private static ?string $pendingRoot = null;

    public static function domain(): ?string
    {
        $domain = strtolower(trim((string) config('haven.public_site_domain')));

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/D', $domain) ? $domain : null;
    }

    public static function company(): string
    {
        return (string) config('haven.public_site_company', 'haven');
    }

    public static function enabled(): bool
    {
        return self::domain() !== null;
    }

    /** Whether this company's website is served on the main domain. */
    public static function serves(string $company): bool
    {
        return self::enabled() && $company === self::company();
    }

    public static function root(): string
    {
        return 'https://'.self::domain();
    }

    /** "/stays/haven/blog/x" → "/blog/x" for the company on the main domain; null for any other path. */
    public static function domainPath(string $path): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        $prefix = '/stays/'.self::company();
        $path = '/'.ltrim($path, '/');
        if ($path === $prefix) {
            return '/';
        }

        return str_starts_with($path, $prefix.'/') ? substr($path, strlen($prefix)) : null;
    }

    /**
     * Full public address for a public-site link or path: on the main domain when it belongs there, otherwise on the
     * configured app address (never the address a request happened to use).
     */
    public static function canonical(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        if (self::enabled() && $host === self::domain()) {
            return self::root().$path.$query;
        }
        $onDomain = self::domainPath($path);

        return $onDomain !== null ? self::root().$onDomain.$query : rtrim((string) config('app.url'), '/').$path.$query;
    }

    /**
     * Writes links to the company's public pages ("public-stays.*" routes) with the main domain. Laravel formats the
     * host before the path, and only the path names the company, so the host step parks the app root and the path
     * step puts back either the main domain or that root.
     */
    public static function register(UrlGenerator $url): void
    {
        if (! self::enabled()) {
            return;
        }
        $isPublic = fn (mixed $route): bool => $route instanceof Route && str_starts_with((string) $route->getName(), 'public-stays.');
        $url->formatHostUsing(function (string $root, mixed $route) use ($isPublic): string {
            if (! $isPublic($route)) {
                // Staff and other app pages (sign in, "Edit this article") always use the app address, even when the
                // page linking to them is on the main domain.
                return strtolower((string) parse_url($root, PHP_URL_HOST)) === self::domain() ? rtrim((string) config('app.url'), '/') : $root;
            }
            self::$pendingRoot = $root;

            return '';
        });
        $url->formatPathUsing(function (string $path, mixed $route) use ($isPublic): string {
            if (! $isPublic($route) || self::$pendingRoot === null) {
                return $path;
            }
            $root = self::$pendingRoot;
            self::$pendingRoot = null;
            $onDomain = self::domainPath($path);

            if ($onDomain !== null) {
                return self::root().($onDomain === '/' ? '' : $onDomain);
            }

            return (strtolower((string) parse_url($root, PHP_URL_HOST)) === self::domain() ? rtrim((string) config('app.url'), '/') : $root).$path;
        });
    }
}
