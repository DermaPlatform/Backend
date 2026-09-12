<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+9665########'),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(Gender::cases()),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Patient,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function patient(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Patient,
        ]);
    }

    public function doctor(): static
    {
        return $this
            ->state(fn (array $attributes) => [
                'role' => UserRole::Doctor,
            ])
            ->afterCreating(function (User $user): void {
                $user->doctorProfile()->create([
                    'specialty' => fake()->randomElement([
                        'Cardiology',
                        'Dermatology',
                        'Pediatrics',
                        'Neurology',
                    ]),
                ]);
            });
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function withImage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'image_path' => 'users/'.fake()->uuid().'.jpg',
        ]);
    }
}
