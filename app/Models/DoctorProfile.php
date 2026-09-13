<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\DoctorProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorProfile extends Model
{
    /** @use HasFactory<DoctorProfileFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'specialty',
        'accepts_appointments',
        'accepts_online_consultations',
        'subscription_status',
        'subscription_ends_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Whether the doctor may still take new work.
     *
     * Trial covers the founding doctors, who run free with no time limit until
     * the platform reaches an active patient base, so it never lapses on its
     * own. Only a dated subscription can run out.
     */
    public function hasActiveSubscription(): bool
    {
        return match ($this->subscription_status) {
            SubscriptionStatus::Trial => true,
            SubscriptionStatus::Active => $this->subscription_ends_at === null
                || $this->subscription_ends_at->isFuture(),
            SubscriptionStatus::Expired => false,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepts_appointments' => 'boolean',
            'accepts_online_consultations' => 'boolean',
            'subscription_status' => SubscriptionStatus::class,
            'subscription_ends_at' => 'datetime',
        ];
    }
}
