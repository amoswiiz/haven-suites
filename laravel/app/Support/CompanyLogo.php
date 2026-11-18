<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The logo on invoices, receipts, quotations and investor reports: the one uploaded in Company settings,
 * otherwise the company's built-in logo file. Embedded as a data URI so PDFs and printouts need no web request.
 */
final class CompanyLogo
{
    /** Widest logo kept after upload, in pixels. Documents show it about 200 to 270 pixels wide. */
    public const MAX_WIDTH = 900;

    public static function uploadedPath(Tenant $tenant): ?string
    {
        $path = (string) ($tenant->report_logo_path ?? '');

        return $path !== '' && str_starts_with($path, 'company-branding/'.$tenant->id.'/') && Storage::disk('local')->exists($path)
            ? Storage::disk('local')->path($path) : null;
    }

    /** Absolute path of the logo file to use, or null when the company has none. */
    public static function path(Tenant $tenant): ?string
    {
        $candidates = [self::uploadedPath($tenant), public_path('assets/img/companies/'.$tenant->slug.'.png')];
        if ($tenant->slug === 'haven') {
            $candidates[] = public_path('assets/images/haven-suites-logo.png');
        }
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function dataUri(Tenant $tenant): ?string
    {
        $path = self::path($tenant);

        return $path === null ? null : 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Save an uploaded logo as a PNG no wider than MAX_WIDTH, keeping a transparent background.
     *
     * @return string Storage path on the local disk.
     */
    public static function store(Tenant $tenant, UploadedFile $file): string
    {
        $source = @imagecreatefromstring((string) $file->get());
        if ($source === false) {
            throw ValidationException::withMessages(['report_logo' => 'This image could not be read. Upload the logo as a PNG or JPEG file.']);
        }
        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1, self::MAX_WIDTH / max(1, $width));
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $logo = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($logo, false);
            imagesavealpha($logo, true);
            imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 255, 255, 255, 127));
            imagecopyresampled($logo, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            ob_start();
            imagepng($logo, null, 9);
            $png = (string) ob_get_clean();
            imagedestroy($logo);
        } finally {
            imagedestroy($source);
        }
        $path = 'company-branding/'.$tenant->id.'/logo-'.Str::random(16).'.png';
        Storage::disk('local')->put($path, $png);

        return $path;
    }
}
