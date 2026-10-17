<?php

namespace Tests\Feature\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use App\Models\Course;
use App\Models\Office;
use App\Models\OfficeService;
use App\Models\User;

trait CreatesQueueFixtures
{
    /** A real 1x1 JPEG encoded so tests never require the GD extension. */
    protected const PWD_EVIDENCE_BASE64 = '/9j/4AAQSkZJRgABAQEAeAB4AAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAAPwBVf//Z';

    /**
     * Redirect the "local" storage disk into the framework testing folder so
     * PWD evidence written by tests never touches the real storage directory.
     */
    protected function isolateLocalStorage(): void
    {
        config(['filesystems.disks.local.root' => storage_path('framework/testing')]);
    }

    protected function makeOffice(array $attrs = []): Office
    {
        return Office::create(array_merge([
            'name' => 'Registrar',
            'code' => 'REG',
            'description' => null,
            'is_active' => true,
            // Tests stand in for a functioning campus, so their offices are
            // online unless a test explicitly switches them off.
            'status' => Office::STATUS_ONLINE,
            'opening_time' => '00:00',
            'closing_time' => '23:59',
            'max_slots' => 100,
        ], $attrs));
    }

    protected function makeUser(string $role, array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($role) . ' User',
            'email' => $role . '_' . uniqid() . '@tmc.test',
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ], $attrs));
    }

    protected function makeCourse(array $attrs = []): Course
    {
        return Course::create(array_merge([
            'code' => 'BSIT',
            'name' => 'BS in Information Technology',
            'is_active' => true,
        ], $attrs));
    }

    protected function makeService(Office $office, array $attrs = []): OfficeService
    {
        return OfficeService::create(array_merge([
            'office_id' => $office->id,
            'name' => 'General Services',
            'requires_course' => false,
            'is_active' => true,
        ], $attrs));
    }

    /**
     * Build a real JPEG UploadedFile (no GD required) for PWD evidence tests.
     */
    protected function evidenceUpload(string $name = 'pwd.jpg'): UploadedFile
    {
        $dir = storage_path('framework/testing/uploads');

        File::ensureDirectoryExists($dir);

        $path = $dir . '/' . $name;

        File::put($path, base64_decode(self::PWD_EVIDENCE_BASE64));

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}