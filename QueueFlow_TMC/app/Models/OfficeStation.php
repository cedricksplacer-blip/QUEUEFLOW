<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeStation extends Model
{
    use HasFactory;

    /**
     * The campus every station belongs to.
     *
     * Trinidad Municipal College is a single-campus institution on President
     * Carlos P. Garcia Street, Santo Nino, Trinidad, Bohol. The college does
     * not publish a per-office or per-room directory, so this verified campus
     * address is the only location that can be asserted as fact. Building or
     * room detail is intentionally left to staff, who can edit it per station
     * from the admin Offices screen.
     */
    public const CAMPUS_LOCATION = 'Trinidad Municipal College, President Carlos P. Garcia St., Santo Nino, Trinidad, Bohol';

    protected $fillable = [
        'office_id','name','location','course_codes',
        'min_daily_students','target_daily_students','max_daily_slots',
        'opening_time','closing_time','is_active',
    ];

    protected $casts = [
        'course_codes' => 'array',
        'is_active' => 'boolean',
    ];

    public function office() { return $this->belongsTo(Office::class); }
    public function services() { return $this->hasMany(OfficeService::class, 'station_id'); }
    public function queueTickets() { return $this->hasMany(QueueTicket::class, 'station_id'); }

    public function acceptsCourse(?string $course): bool
    {
        $codes = $this->course_codes ?: [];
        return blank($course) || empty($codes) || in_array(strtoupper($course), array_map('strtoupper', $codes), true);
    }
}
