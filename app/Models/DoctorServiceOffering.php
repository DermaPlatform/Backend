<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The doctor_service row: what one doctor charges for one service, and how
 * long they give it.
 *
 * A pivot needs its own model to be cast at all. Without this the price came
 * back as a bare number rather than the two decimal string the rest of the
 * API uses for money, and is_active as 1 rather than true.
 */
class DoctorServiceOffering extends Pivot
{
    protected $table = 'doctor_service';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
