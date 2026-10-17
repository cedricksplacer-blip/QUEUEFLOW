<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Shared image upload checks for QueueFlow TMC.
 *
 * The project deliberately avoids the "image"/"mimes" rules and validates
 * uploads by hand instead, so the accepted formats live in exactly one place
 * and cannot drift between callers.
 */
trait ValidatesImageUpload
{
    /**
     * Accepted image uploads. The extension is derived from the sniffed MIME
     * type, never from the client-supplied filename.
     */
    protected array $allowedImageMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];

    protected array $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Verify the actual image payload, not just the declared MIME type.
     */
    protected function isValidImage(UploadedFile $file): bool
    {
        $info = @getimagesize($file->getPathname());

        return $info !== false && $info[0] > 0 && $info[1] > 0;
    }

    protected function extensionForImageMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }

    /**
     * Run every image check, raising a validation error on the given field.
     *
     * @throws ValidationException
     */
    protected function guardImageUpload(UploadedFile $file, string $field, string $label, int $maxKilobytes): string
    {
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($mime, $this->allowedImageMimeTypes, true) || ! in_array($extension, $this->allowedImageExtensions, true)) {
            throw ValidationException::withMessages([
                $field => "Only JPG, JPEG, PNG or WEBP image files are allowed for the {$label}.",
            ]);
        }

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw ValidationException::withMessages([
                $field => "The {$label} must not exceed {$this->formatKilobytes($maxKilobytes)}.",
            ]);
        }

        if (! $this->isValidImage($file)) {
            throw ValidationException::withMessages([
                $field => "The uploaded {$label} is not a valid image.",
            ]);
        }

        return $this->extensionForImageMime($mime);
    }

    /**
     * Render a kilobyte limit in the largest unit that stays readable.
     */
    protected function formatKilobytes(int $kilobytes): string
    {
        if ($kilobytes % 1024 !== 0) {
            return $kilobytes . ' KB';
        }

        return (int) ($kilobytes / 1024) . ' MB';
    }
}
