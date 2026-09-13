<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Nadara Admin',
            'email' => 'admin@nadara.test',
            'phone' => '0590000001',
        ]);

        $doctor = User::factory()->doctor()->create([
            'name' => 'Dr. Layla Dermatologist',
            'email' => 'doctor@nadara.test',
            'phone' => '0590000002',
        ]);

        User::factory()->secretary($doctor)->create([
            'name' => 'Nadara Secretary',
            'email' => 'secretary@nadara.test',
            'phone' => '0590000007',
        ]);

        User::factory()->patient()->create([
            'name' => 'Test Patient',
            'email' => 'patient@nadara.test',
            'phone' => '0590000003',
        ]);

        $this->call(CatalogSeeder::class);
    }
}
