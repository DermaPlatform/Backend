<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'price',
    ];

    public function category()
    {
        return $this->belongsTo(
            ServiceCategory::class,
            'category_id'
        );
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }
}
