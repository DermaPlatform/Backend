<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorProfile>
 */
class DoctorProfileFactory extends Factory
{
    protected $model = DoctorProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state([
                'role' => UserRole::Doctor,
            ]),
            'specialty' => fake()->randomElement([
                'Cardiology',
                'Dermatology',
                'Pediatrics',
                'Neurology',
            ]),
            'accepts_appointments' => true,
            'accepts_online_consultations' => false,
            'subscription_status' => SubscriptionStatus::Trial,
            'subscription_ends_at' => now()->addDays(14),
        ];
    }
}
