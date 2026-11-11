<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Smaller WebP copies of listing photos (480px for cards and thumbnails, 1200px for galleries), stored next to the
 * original, e.g. ".../abc.jpg" -> ".../abc-480.webp". Phones download a fraction of the original size.
 */
class ListingPhotoVariants
{
    public const WIDTHS = [480, 1200];

    public static function key(string $original, int $width): string
    {
        return (string) preg_replace('/\.(?:jpg|png)$/D', '-'.$width.'.webp', $original);
    }

    /** "url?w=480 480w, url?w=1200 1200w" for an <img srcset>. */
    public static function srcset(string $url): string
    {
        return implode(', ', array_map(fn (int $width): string => $url.'?w='.$width.' '.$width.'w', self::WIDTHS));
    }

    public static function supported(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagewebp');
    }

    /** Create any missing copies. Returns how many were written; failures are reported, not thrown. */
    public function make(string $original): int
    {
        $disk = Storage::disk('local');
        if (! self::supported() || ! preg_match('/\.(?:jpg|png)$/D', $original) || ! $disk->exists($original)) {
            return 0;
        }
        $missing = array_values(array_filter(self::WIDTHS, fn (int $width): bool => ! $disk->exists(self::key($original, $width))));
        if ($missing === []) {
            return 0;
        }
        $image = @imagecreatefromstring((string) $disk->get($original));
        if (! $image) {
            return 0;
        }
        $written = 0;
        try {
            foreach ($missing as $width) {
                $ratio = min(1, $width / max(1, imagesx($image)));
                $scaled = $ratio < 1 ? imagescale($image, $width, max(1, (int) round(imagesy($image) * $ratio)), IMG_BICUBIC_FIXED) : $image;
                if (! $scaled) {
                    continue;
                }
                ob_start();
                $ok = imagewebp($scaled, null, 78);
                $contents = (string) ob_get_clean();
                if ($scaled !== $image) {
                    imagedestroy($scaled);
                }
                if ($ok && $contents !== '' && $disk->put(self::key($original, $width), $contents)) {
                    $written++;
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        } finally {
            imagedestroy($image);
        }

        return $written;
    }

    public function delete(string $original): void
    {
        foreach (self::WIDTHS as $width) {
            $key = self::key($original, $width);
            if ($key !== $original) {
                Storage::disk('local')->delete($key);
            }
        }
    }
}
