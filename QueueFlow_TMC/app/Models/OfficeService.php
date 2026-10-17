<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeService extends Model
{
    use HasFactory;

    protected $fillable = [
        'office_id','station_id','name','description','requires_course','is_active',
    ];

    protected $casts = ['requires_course' => 'boolean', 'is_active' => 'boolean'];

    public function office() { return $this->belongsTo(Office::class); }
    public function station() { return $this->belongsTo(OfficeStation::class, 'station_id'); }
    public function queueTickets() { return $this->hasMany(QueueTicket::class, 'service_id'); }
}
