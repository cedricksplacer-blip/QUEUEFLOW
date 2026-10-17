<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';

    public const AUDIENCE_USER = 'user';
    public const AUDIENCE_STAFF = 'staff';
    public const AUDIENCE_BOTH = 'both';

    /**
     * Audience options shown in the admin Announcement form, keyed by
     * their stored value.
     */
    public const AUDIENCES = [
        self::AUDIENCE_USER => 'User Only',
        self::AUDIENCE_STAFF => 'Staff Only',
        self::AUDIENCE_BOTH => 'User and Staff',
    ];

    protected $fillable = [
        'title',
        'body',
        'is_active',
        'status',
        'office_id',
        'audience',
        'posted_by',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'reviewed_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * The staff member who submitted this announcement for admin review.
     */
    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * The office the staff member was operating when they submitted it.
     */
    public function office()
    {
        return $this->belongsTo(Office::class, 'office_id');
    }

    /**
     * The administrator who accepted/rejected this announcement.
     */
    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Per-staff read receipts for the announcement bell.
     */
    public function reads()
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Whether the given user has marked this announcement as read.
     */
    public function isReadBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($this->relationLoaded('reads')) {
            return $this->reads->contains(
                fn (AnnouncementRead $read) => (int) $read->user_id === (int) $user->id
            );
        }

        return $this->reads()->where('user_id', $user->id)->exists();
    }

    /**
     * JSON shape for the staff bell dropdown / polling endpoint.
     *
     * Mirrors the keys the bell renders (title, message, date, read state)
     * so the panel never needs a second query to draw a row.
     *
     * @return array<string, mixed>
     */
    public function toBellArray(?User $viewer = null): array
    {
        $this->loadMissing(['author', 'office']);

        return [
            'id' => $this->id,
            'type' => 'announcement',
            'title' => $this->title,
            'message' => $this->body,
            'office' => $this->office?->name,
            'audience' => $this->audience ?? self::AUDIENCE_BOTH,
            'audience_label' => $this->audienceLabel(),
            'author' => $this->author?->name ?? 'Administrator',
            'created_at' => optional($this->created_at)->toIso8601String(),
            'is_read' => $this->isReadBy($viewer),
        ];
    }

    /**
     * Announcements currently visible to everyone (published and active).
     */
    public function scopePublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_active', true);
    }

    /**
     * Restrict to announcements visible to the given viewer role.
     *
     * Accepts 'user'/'student' (User Only + User and Staff),
     * 'staff' (Staff Only + User and Staff), or null/'admin' for every
     * audience (admin management screens). Unknown values return every
     * audience rather than silently matching nothing.
     */
    public function scopeForAudience($query, ?string $role)
    {
        $role = strtolower(trim((string) ($role ?? '')));

        if ($role === 'student') {
            $role = self::AUDIENCE_USER;
        }

        if ($role === self::AUDIENCE_USER) {
            return $query->whereIn('audience', [self::AUDIENCE_USER, self::AUDIENCE_BOTH]);
        }

        if ($role === self::AUDIENCE_STAFF) {
            return $query->whereIn('audience', [self::AUDIENCE_STAFF, self::AUDIENCE_BOTH]);
        }

        return $query;
    }

    /**
     * Published + active announcements for the given viewer role.
     */
    public function scopePublicFor($query, ?string $role)
    {
        return $query->public()->forAudience($role);
    }

    /**
     * Human label for the stored audience value.
     */
    public function audienceLabel(): string
    {
        return self::AUDIENCES[$this->audience] ?? self::AUDIENCES[self::AUDIENCE_BOTH];
    }

    /**
     * Whether this announcement should be shown to the given viewer role.
     */
    public function isVisibleTo(?string $role): bool
    {
        $role = strtolower(trim((string) ($role ?? '')));

        if ($role === 'student') {
            $role = self::AUDIENCE_USER;
        }

        if ($role === '' || $role === 'admin') {
            return true;
        }

        $audience = $this->audience ?? self::AUDIENCE_BOTH;

        if ($role === self::AUDIENCE_USER) {
            return in_array($audience, [self::AUDIENCE_USER, self::AUDIENCE_BOTH], true);
        }

        if ($role === self::AUDIENCE_STAFF) {
            return in_array($audience, [self::AUDIENCE_STAFF, self::AUDIENCE_BOTH], true);
        }

        return true;
    }
}
