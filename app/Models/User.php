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
        'birth_date',
        'gender',
        'password',
        'role',
        'specialty',
    ];

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
