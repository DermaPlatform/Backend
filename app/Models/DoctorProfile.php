<?php

namespace App\Models;

use App\Enums\SubscriptionStanding;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonInterface;
use Database\Factories\DoctorProfileFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * Where the subscription stands today.
     *
     * A trial and a paid subscription run out the same way: once the end date
     * passes the doctor keeps working through the grace period, then drops
     * out. A record with no end date never lapses on its own, which is how
     * the founding doctors run free until the admin gives them a date.
     */
    public function subscriptionStanding(): SubscriptionStanding
    {
        if ($this->subscription_status === SubscriptionStatus::Expired) {
            return SubscriptionStanding::Expired;
        }

        if ($this->subscription_ends_at === null || $this->subscription_ends_at->isFuture()) {
            return $this->subscription_status === SubscriptionStatus::Trial
                ? SubscriptionStanding::Trial
                : SubscriptionStanding::Active;
        }

        return $this->graceEndsAt()->isFuture()
            ? SubscriptionStanding::Grace
            : SubscriptionStanding::Expired;
    }

    /**
     * The moment the doctor drops out of the catalog, or null while the
     * subscription has no end date.
     */
    public function graceEndsAt(): ?CarbonInterface
    {
        return $this->subscription_ends_at?->copy()->addDays(self::graceDays());
    }

    /**
     * Whether the doctor may still take new work.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscriptionStanding()->takesNewWork();
    }

    /**
     * Profiles in the given standing. Mirrors subscriptionStanding() in SQL
     * so listings can filter in the database.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInStanding(Builder $query, SubscriptionStanding $standing): void
    {
        $now = now();
        $graceCutoff = $now->copy()->subDays(self::graceDays());

        $running = fn (Builder $dates) => $dates
            ->whereNull('subscription_ends_at')
            ->orWhere('subscription_ends_at', '>', $now);

        match ($standing) {
            SubscriptionStanding::Trial => $query
                ->where('subscription_status', SubscriptionStatus::Trial)
                ->where($running),
            SubscriptionStanding::Active => $query
                ->where('subscription_status', SubscriptionStatus::Active)
                ->where($running),
            SubscriptionStanding::Grace => $query
                ->where('subscription_status', '!=', SubscriptionStatus::Expired)
                ->where('subscription_ends_at', '<=', $now)
                ->where('subscription_ends_at', '>', $graceCutoff),
            SubscriptionStanding::Expired => $query->where(fn (Builder $lapsed) => $lapsed
                ->where('subscription_status', SubscriptionStatus::Expired)
                ->orWhere('subscription_ends_at', '<=', $graceCutoff)),
        };
    }

    /**
     * Profiles whose doctor may still take new work: anything not expired.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeTakingNewWork(Builder $query): void
    {
        $query->where('subscription_status', '!=', SubscriptionStatus::Expired)
            ->where(fn (Builder $dates) => $dates
                ->whereNull('subscription_ends_at')
                ->orWhere('subscription_ends_at', '>', now()->subDays(self::graceDays())));
    }

    private static function graceDays(): int
    {
        return (int) config('nadara.subscriptions.grace_days');
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
