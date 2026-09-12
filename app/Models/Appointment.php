<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'service_id',
        'start_at',
        'end_at',
        'hold_expires_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'cancelled_at',
        'cancellation_reason',
        'refund_required',
        'price',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'appointment_id');
    }

    /**
     * @return HasOne<MedicalNote, $this>
     */
    public function medicalNote(): HasOne
    {
        return $this->hasOne(MedicalNote::class, 'appointment_id');
    }

    /**
     * Appointments that genuinely hold their slot right now.
     *
     * A pending_payment row only counts while its hold is still running. Once
     * the hold lapses the slot is free again, even though the command that
     * rewrites the status may not have run yet, so availability and the
     * booking conflict check must agree on that without waiting for it.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOccupyingSlot(Builder $query): void
    {
        $query->whereIn('status', AppointmentStatus::blocking())
            ->where(function (Builder $query): void {
                $query->where('status', '!=', AppointmentStatus::PendingPayment)
                    ->orWhere('hold_expires_at', '>', now());
            });
    }

    /**
     * Appointments overlapping a half open [$startAt, $endAt) window, so an
     * appointment ending exactly when the window opens does not count.
     *
     * Both bounds are normalised to UTC first. Laravel formats a date binding
     * in whatever timezone the object carries, so a clinic wall clock time
     * would otherwise reach the query as its own literal digits and silently
     * search the wrong hours.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $query->where('start_at', '<', CarbonImmutable::instance($endAt)->utc())
            ->where('end_at', '>', CarbonImmutable::instance($startAt)->utc());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'price' => 'decimal:2',
            'start_at' => UtcDateTime::class,
            'end_at' => UtcDateTime::class,
            'hold_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refund_required' => 'boolean',
        ];
    }
}
