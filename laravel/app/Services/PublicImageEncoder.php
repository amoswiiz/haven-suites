<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Re-encodes an uploaded image as a resized JPEG, which also removes location and other metadata. */
class PublicImageEncoder
{
    public function jpeg(UploadedFile $file, string $field, int $maxSide = 1800): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            throw ValidationException::withMessages([$field => 'Image processing is unavailable. Ask an administrator to enable the PHP GD extension.']);
        }
        $dimensions = @getimagesize($file->getPathname());
        if (! $dimensions || $dimensions[0] < 200 || $dimensions[1] < 120 || $dimensions[0] > 6000 || $dimensions[1] > 6000 || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages([$field => 'Use a photo between 200 and 6,000 pixels per side, with at most 16 megapixels.']);
        }
        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (! $image) {
            throw ValidationException::withMessages([$field => 'The photo could not be read. Upload a JPG, PNG or WEBP image.']);
        }
        try {
            $ratio = min(1, $maxSide / max(imagesx($image), imagesy($image)));
            $width = max(1, (int) round(imagesx($image) * $ratio));
            $height = max(1, (int) round(imagesy($image) * $ratio));
            $canvas = imagecreatetruecolor($width, $height);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
            ob_start();
            $written = imagejpeg($canvas, null, 84);
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
