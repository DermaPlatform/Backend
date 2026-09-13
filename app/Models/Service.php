<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'price',
        'description',
        'image_path',
        'is_active',
    ];

    /**
     * Public URL of the service image, or null when none has been uploaded.
     *
     * @return Attribute<?string, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path === null
            ? null
            : Storage::disk(config('nadara.uploads.disk'))->url($this->image_path));
    }

    /**
     * Drop the stored image file and clear the column.
     */
    public function clearImage(): void
    {
        if ($this->image_path === null) {
            return;
        }

        Storage::disk(config('nadara.uploads.disk'))->delete($this->image_path);

        $this->update(['image_path' => null]);
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'doctor_service', 'service_id', 'doctor_id')
            ->using(DoctorServiceOffering::class)
            ->withPivot(['price', 'duration_minutes', 'is_active']);
    }

    /**
     * Doctors on this service who can actually be booked right now.
     *
     * @return BelongsToMany<User, $this>
     */
    public function bookableDoctors(): BelongsToMany
    {
        return $this->doctors()
            ->wherePivot('is_active', true)
            ->bookableDoctors();
    }

    /**
     * Adds the catalog's "starts from" price and the number of doctors behind
     * it, as subqueries rather than loaded relations.
     *
     * Since pricing moved to doctor_service, services.price is only a hint and
     * the real figure differs per doctor. Computing it here keeps a catalog
     * page at one query instead of one per row.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeWithCatalogPricing(Builder $query): void
    {
        $bookable = fn (): \Illuminate\Database\Query\Builder => DB::table('doctor_service')
            ->join('users', 'users.id', '=', 'doctor_service.doctor_id')
            ->join('doctor_profiles', 'doctor_profiles.user_id', '=', 'users.id')
            ->whereColumn('doctor_service.service_id', 'services.id')
            ->where('doctor_service.is_active', true)
            ->where('users.is_active', true)
            ->where(function ($subscription): void {
                $subscription->where('doctor_profiles.subscription_status', SubscriptionStatus::Trial->value)
                    ->orWhere(function ($dated): void {
                        $dated->where('doctor_profiles.subscription_status', SubscriptionStatus::Active->value)
                            ->where(function ($window): void {
                                $window->whereNull('doctor_profiles.subscription_ends_at')
                                    ->orWhere('doctor_profiles.subscription_ends_at', '>', now());
                            });
                    });
            });

        $query->addSelect([
            'starting_price' => $bookable()->selectRaw('MIN(doctor_service.price)'),
            'bookable_doctors_count' => $bookable()->selectRaw('COUNT(*)'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
