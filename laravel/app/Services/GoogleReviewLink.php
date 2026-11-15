<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * The company's Google review link. When it is set, review requests send guests to Google instead of the website's
 * own review form. Only Google addresses are accepted so a typo or pasted link cannot send guests somewhere else.
 */
class GoogleReviewLink
{
    /** Short-link and sharing hosts Google gives businesses, besides google.* domains. */
    private const SHARE_HOSTS = ['share.google', 'g.page', 'goo.gl', 'maps.app.goo.gl'];

    public static function for(Tenant $tenant): ?string
    {
        $url = trim((string) $tenant->google_review_url);

        return $url !== '' && self::isGoogle($url) ? $url : null;
    }

    public static function isGoogle(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($host, self::SHARE_HOSTS, true)
            || (bool) preg_match('/^(?:[a-z0-9-]+\.)*google\.(?:com|co\.[a-z]{2}|com\.[a-z]{2}|[a-z]{2})$/D', $host);
    }
}
