<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'consultation_id',
        'amount',
        'status',
    ];

    public function patient()
    {
        return $this->belongsTo(
            User::class,
            'patient_id'
        );
    }

    public function appointment()
    {
        return $this->belongsTo(
            Appointment::class,
            'appointment_id'
        );
    }

    public function consultation()
    {
        return $this->belongsTo(
            Consultation::class,
            'consultation_id'
        );
    }
}
