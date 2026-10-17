<?php

namespace App\Models;

use App\Notifications\AdminResetPasswordNotification;
use App\Services\AvatarStore;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, CanResetPassword;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const ROLE_STUDENT = 'student';

    public const COURSES = [
        'BSIT',
        'BSOA',
        'BSCRIM',
        'BEED',
        'BSED',
        'BACOMM',
        'BAPOLSCI',
    ];

    public const YEAR_LEVELS = [
        '1st Year',
        '2nd Year',
        '3rd Year',
        '4th Year',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'student_id',
        'course',
        'block',
        'year_level',
        'office_id',
        'phone',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Mutators
    |--------------------------------------------------------------------------
    */

    /**
     * QueueFlow TMC stores passwords as clear text so they stay readable in
     * the database. Legacy bcrypt rows are still accepted on sign-in and are
     * rewritten as clear text by PlainPassword::upgradeIfHashed().
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value;
    }

    /*
    |--------------------------------------------------------------------------
    | Profile Photo
    |--------------------------------------------------------------------------
    |
    | The column holds a path on the "local" disk, not a URL. hasAvatar()
    | also confirms the file is still on disk, so a row whose file was
    | removed by hand falls back to the name initial instead of a broken
    | <img> tag.
    |
    */

    public function hasAvatar(): bool
    {
        return AvatarStore::exists($this->avatar);
    }

    /**
     * URL of the streaming route for this user's photo, or null when they have
     * none. Points at the admin-only route, so it is only for admin views.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->hasAvatar()) {
            return null;
        }

        return route('admin.profile.avatar', $this) . '?v=' . AvatarStore::version($this->avatar);
    }

    /*
    |--------------------------------------------------------------------------
    | Password Reset Notification
    |--------------------------------------------------------------------------
    |
    | Use the custom QueueFlow TMC admin password-reset page instead
    | of Laravel's default password-reset route.
    |
    */

    public function sendPasswordResetNotification($token)
    {
        $this->notify(
            new AdminResetPasswordNotification($token)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function assignedCourses()
    {
        return $this->hasMany(Course::class, 'staff_id');
    }

    public function queueTickets()
    {
        return $this->hasMany(QueueTicket::class);
    }

    public function servedTickets()
    {
        return $this->hasMany(
            QueueTicket::class,
            'served_by'
        );
    }

    public function announcements()
    {
        return $this->hasMany(
            Announcement::class,
            'posted_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-in identity
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve an account from whatever the person typed on the sign-in form.
     *
     * Students sign in with their Student ID, staff and admins with their
     * email address, so the lookup accepts either. Student ID is tried first
     * because it is the unique, human-chosen identifier; email is the
     * fallback so accounts created before Student ID was introduced, and
     * every staff/admin account, keep working untouched.
     */
    public static function findByIdentifier(?string $value): ?self
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return static::where('student_id', $value)
            ->orWhere('email', $value)
            ->first();
    }

    /**
     * Whether a reset code can actually reach this account.
     *
     * Password recovery is delivered over the mail channel, so a student
     * created with a Student ID and no email on file has no self-service
     * route back in and has to be reactivated by an admin. An address that
     * is present but malformed is treated the same as a missing one: the
     * reset endpoint answers generically either way (so the difference
     * cannot be used to probe accounts), and the real reason goes to the
     * log. The student is told to contact the administrator through the
     * app's static help text, which is shown for every outcome.
     */
    public function canReceiveMail(): bool
    {
        return filled($this->email)
            && filter_var(trim((string) $this->email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /*
    |--------------------------------------------------------------------------
    | Role Helpers
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    /*
    |--------------------------------------------------------------------------
    | Student Portal API helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The student-safe representation of an account, used by the mobile app.
     *
     * Never exposes the password hash, remember token, or office/staff
     * bookkeeping that a student has no business seeing.
     */
    public function toStudentPortalArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'student_id' => $this->student_id,
            'course' => $this->course,
            'block' => $this->block,
            'year_level' => $this->year_level,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => optional($this->email_verified_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}