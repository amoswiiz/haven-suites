<?php

namespace App\Support;

/**
 * Public asset URLs with an automatic version, so browsers fetch a file again only after it changes.
 * Replaces hand-edited "?v=16" query strings.
 */
class AssetVersion
{
    /** @var array<string, string> */
    private static array $versions = [];

    public static function url(string $path): string
    {
        $path = ltrim($path, '/');
        if (! isset(self::$versions[$path])) {
            $modified = @filemtime(public_path($path));
            self::$versions[$path] = $modified ? base_convert((string) $modified, 10, 36) : '0';
        }

        return asset($path).'?v='.self::$versions[$path];
    }
}
