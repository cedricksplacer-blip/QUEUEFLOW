<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffNotification extends Model
{
    use HasFactory;

    public const TYPE_TICKET_CANCELLED = 'ticket_cancelled';

    public const TITLE_TICKET_CANCELLED = 'Student Cancelled Ticket';

    protected $fillable = [
        'type',
        'office_id',
        'ticket_id',
        'triggered_by',
        'title',
        'message',
        'student_name',
        'student_id_no',
        'queue_number',
        'office_name',
        'office_code',
        'purpose',
        'priority',
        'reason',
        'cancelled_at',
        'is_read',
        'read_at',
        'read_by',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function ticket()
    {
        return $this->belongsTo(QueueTicket::class, 'ticket_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function readByUser()
    {
        return $this->belongsTo(User::class, 'read_by');
    }

    public function scopeForOffice($query, int $officeId)
    {
        return $query->where('office_id', $officeId);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeCancelled($query)
    {
        return $query->where('type', self::TYPE_TICKET_CANCELLED);
    }

    public function scopeRecent($query)
    {
        return $query->latest('id');
    }

    public function markAsRead(?User $reader = null): void
    {
        if ($this->is_read) {
            return;
        }

        $this->update([
            'is_read' => true,
            'read_at' => now(),
            'read_by' => $reader?->id,
        ]);
    }

    /**
     * JSON shape for the staff bell dropdown / polling endpoint and the
     * staff mobile bootstrap. Snapshot fields first so the panel never
     * needs a second query to render a row.
     *
     * @return array<string, mixed>
     */
    public function toPanelArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'student_name' => $this->student_name,
            'student_id' => $this->student_id_no,
            'ticket_id' => $this->ticket_id,
            'queue_number' => $this->queue_number,
            'office_id' => $this->office_id,
            'office' => $this->office_name,
            'office_code' => $this->office_code,
            'purpose' => $this->purpose,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'is_read' => (bool) $this->is_read,
            'read_at' => optional($this->read_at)->toIso8601String(),
        ];
    }
}
