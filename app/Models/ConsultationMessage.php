<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConsultationMessage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'consultation_id',
        'sender_id',
        'message',
    ];

    public function consultation()
    {
        return $this->belongsTo(
            Consultation::class,
            'consultation_id'
        );
    }

    public function sender()
    {
        return $this->belongsTo(
            User::class,
            'sender_id'
        );
    }
}
