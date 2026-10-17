<?php

namespace App\Services;

use App\Support\ValidatesImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores and serves user profile photos.
 *
 * Files live under storage/app/avatars on the "local" disk, so they sit
 * outside the public web root. They are only ever streamed back through an
 * authenticated, admin-only route.
 */
class AvatarStore
{
    use ValidatesImageUpload;

    /**
     * Directory on the local disk.
     */
    public const DIRECTORY = 'avatars';

    /**
     * Maximum size for a profile photo.
     */
    public const MAX_AVATAR_SIZE = 2048; // KB (2 MB)

    /**
     * Validate and persist a profile photo, returning its stored path.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(UploadedFile $file): string
    {
        $safeExtension = $this->guardImageUpload($file, 'avatar', 'profile photo', self::MAX_AVATAR_SIZE);

        // The client's filename is never reused, so there is nothing to
        // sanitise and no way to escape the target directory.
        $name = 'avatar_' . Str::random(32) . '.' . $safeExtension;

        return $file->storeAs(self::DIRECTORY, $name);
    }

    /**
     * Remove a stored photo. Missing files are ignored so a stale database
     * value can never break the request that clears it.
     */
    public function delete(?string $path): void
    {
        if (filled($path) && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Whether the given path still points at a real file, so the UI can fall
     * back to a name initial instead of rendering a broken image.
     */
    public static function exists(?string $path): bool
    {
        return filled($path) && Storage::disk('local')->exists($path);
    }

    /**
     * Cache-busting token for a stored photo.
     *
     * The streaming route is a stable URL, so without this a browser would keep
     * showing the previous photo until it decided to revalidate. The mtime
     * changes whenever the file is replaced.
     */
    public static function version(?string $path): ?int
    {
        if (! self::exists($path)) {
            return null;
        }

        return Storage::disk('local')->lastModified($path);
    }
}
