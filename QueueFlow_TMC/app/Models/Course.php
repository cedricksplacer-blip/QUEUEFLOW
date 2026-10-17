<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'office_id',
        'staff_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function assignedStaff()
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function queueTickets()
    {
        return $this->hasMany(QueueTicket::class);
    }
}