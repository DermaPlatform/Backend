<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Gender;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
        'specialty',
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
        ];
    }
}
