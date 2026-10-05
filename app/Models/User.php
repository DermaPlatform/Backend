<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Gender;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'image_path',
        'birth_date',
        'gender',
        'password',
        'role',
        'doctor_id',
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
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Column defaults mirrored onto the model.
     *
     * The database already defaults is_active to true, but a freshly created
     * instance does not read it back, so authorization checks on the same
     * request would see null and deny a brand new account.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Sign the account out everywhere.
     *
     * Tokens alone are not the whole session: a device that was allowed to
     * show this account's notifications would keep receiving them after the
     * password changed or the account was closed, so those go with them.
     */
    public function revokeAllSessions(): void
    {
        $this->tokens()->delete();
        $this->pushSubscriptions()->delete();
    }

    /**
     * @return HasOne<DoctorProfile, $this>
     */
    public function doctorProfile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class, 'user_id');
    }

    /**
     * The secretaries working for this doctor.
     *
     * @return HasMany<User, $this>
     */
    public function secretaries(): HasMany
    {
        return $this->hasMany(self::class, 'doctor_id')->where('role', UserRole::Secretary);
    }

    /**
     * Doctors the public may currently book.
     *
     * Mirrors isBookableDoctor() in SQL so a listing can filter in the
     * database instead of loading every doctor and discarding most of them.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBookableDoctors(Builder $query): void
    {
        /*
         * Columns are qualified because this scope is also applied through the
         * doctor_service pivot, which carries an is_active of its own.
         */
        $query->where('users.role', UserRole::Doctor)
            ->where('users.is_active', true)
            ->whereHas('doctorProfile', fn (Builder $profile) => $profile->takingNewWork());
    }

    /**
     * Whether this account is a doctor the public may currently book.
     *
     * Used to answer with a 404 rather than an empty calendar: a deactivated
     * or lapsed doctor should read as absent, not as permanently unavailable.
     */
    public function isBookableDoctor(): bool
    {
        return $this->role === UserRole::Doctor
            && $this->is_active
            && $this->doctorProfile?->hasActiveSubscription() === true;
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function offeredServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'doctor_service', 'doctor_id', 'service_id')
            ->using(DoctorServiceOffering::class)
            ->withPivot(['price', 'duration_minutes', 'is_active']);
    }

    public function patientAppointments()
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function doctorAppointments()
    {
        return $this->hasMany(Appointment::class, 'doctor_id');
    }

    public function patientConsultations()
    {
        return $this->hasMany(Consultation::class, 'patient_id');
    }

    public function doctorConsultations()
    {
        return $this->hasMany(Consultation::class, 'doctor_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'patient_id');
    }

    public function medicalNotesAsPatient()
    {
        return $this->hasMany(MedicalNote::class, 'patient_id');
    }

    public function medicalNotesAsDoctor()
    {
        return $this->hasMany(MedicalNote::class, 'doctor_id');
    }

    public function consultationMessages()
    {
        return $this->hasMany(ConsultationMessage::class, 'sender_id');
    }

    /**
     * Age in whole years, or null while no birth date is on file. Doctors read
     * this instead of recomputing it on every screen.
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->birth_date?->age);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birth_date' => 'date',
            'role' => UserRole::class,
            'gender' => Gender::class,
            'is_active' => 'boolean',
        ];
    }
}
