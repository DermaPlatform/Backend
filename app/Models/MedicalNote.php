<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicalNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'appointment_id',
        'notes',
    ];

    public function patient()
    {
        return $this->belongsTo(
            User::class,
            'patient_id'
        );
    }

    public function doctor()
    {
        return $this->belongsTo(
            User::class,
            'doctor_id'
        );
    }

    public function appointment()
    {
        return $this->belongsTo(
            Appointment::class,
            'appointment_id'
        );
    }
}
