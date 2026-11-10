<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Re-encodes uploaded photos as JPEG within a maximum size. Re-encoding also drops embedded metadata such as the
 * GPS location phones add to photos.
 */
class ImageResizer
{
    public static function toJpeg(UploadedFile $file, string $field, int $maxSide = 1600): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw ValidationException::withMessages([$field => 'Image processing is unavailable. Ask an administrator to enable the PHP GD extension.']);
        }
        $dimensions = @getimagesize($file->getPathname());
        if (! $dimensions || $dimensions[0] < 50 || $dimensions[1] < 50 || $dimensions[0] > 8000 || $dimensions[1] > 8000 || $dimensions[0] * $dimensions[1] > 40000000) {
            throw ValidationException::withMessages([$field => 'Use a clear photo between 50 and 8,000 pixels per side.']);
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (! $image) {
            throw ValidationException::withMessages([$field => 'The photo could not be read. Upload a JPG, PNG or WebP image.']);
        }
        try {
            $ratio = min(1, $maxSide / max(imagesx($image), imagesy($image)));
            $width = max(1, (int) round(imagesx($image) * $ratio));
            $height = max(1, (int) round(imagesy($image) * $ratio));
            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            ob_start();
            $written = imagejpeg($canvas, null, 82);
            $encoded = (string) ob_get_clean();
            imagedestroy($canvas);
            if (! $written || $encoded === '') {
                throw ValidationException::withMessages([$field => 'The photo could not be processed. Try another image.']);
            }

            return $encoded;
        } finally {
            imagedestroy($image);
        }
    }
}
