<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\DoctorSchedule;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Realistic dermatology catalog so the API can be demoed without hand entry.
 */
class CatalogSeeder extends Seeder
{
    /**
     * @var array<string, array<int, array{name: string, price: float, description: string}>>
     */
    private array $catalog = [
        'Medical Dermatology' => [
            ['name' => 'General Skin Examination', 'price' => 60.00, 'description' => 'Full skin assessment with a dermatologist.'],
            ['name' => 'Acne Treatment Consultation', 'price' => 75.00, 'description' => 'Assessment and treatment plan for acne and post-acne marks.'],
            ['name' => 'Eczema & Psoriasis Follow-up', 'price' => 70.00, 'description' => 'Ongoing management of chronic inflammatory skin conditions.'],
            ['name' => 'Mole & Lesion Check', 'price' => 90.00, 'description' => 'Dermoscopic examination of moles and suspicious lesions.'],
        ],
        'Cosmetic Dermatology' => [
            ['name' => 'Chemical Peel', 'price' => 150.00, 'description' => 'Exfoliating peel for tone, texture and pigmentation.'],
            ['name' => 'Botox Injection Session', 'price' => 320.00, 'description' => 'Targeted injections for expression lines.'],
            ['name' => 'Dermal Filler Session', 'price' => 400.00, 'description' => 'Volume restoration and contouring with dermal fillers.'],
        ],
        'Laser Treatments' => [
            ['name' => 'Laser Hair Removal - Small Area', 'price' => 120.00, 'description' => 'Single session for a small treatment area.'],
            ['name' => 'Laser Hair Removal - Large Area', 'price' => 250.00, 'description' => 'Single session for a large treatment area.'],
            ['name' => 'Laser Pigmentation Treatment', 'price' => 280.00, 'description' => 'Targets sun spots, melasma and uneven pigmentation.'],
        ],
        'Skincare & Routine Care' => [
            ['name' => 'Deep Cleansing Facial', 'price' => 85.00, 'description' => 'Professional cleansing, extraction and hydration.'],
            ['name' => 'Hydrafacial Session', 'price' => 180.00, 'description' => 'Multi-step resurfacing and hydration treatment.'],
            ['name' => 'Skincare Routine Consultation', 'price' => 50.00, 'description' => 'Personalised product and routine recommendations.'],
        ],
    ];

    public function run(): void
    {
        foreach ($this->catalog as $categoryName => $services) {
            $category = ServiceCategory::firstOrCreate(['name' => $categoryName]);

            foreach ($services as $service) {
                Service::firstOrCreate(
                    ['name' => $service['name']],
                    [
                        'category_id' => $category->id,
                        'description' => $service['description'],
                        'price' => $service['price'],
                        'is_active' => true,
                    ]
                );
            }
        }

        $this->seedDoctors();
    }

    private function seedDoctors(): void
    {
        $generalExamination = Service::query()
            ->where('name', 'General Skin Examination')
            ->firstOrFail();

        $acneConsultation = Service::query()
            ->where('name', 'Acne Treatment Consultation')
            ->firstOrFail();

        $moleCheck = Service::query()
            ->where('name', 'Mole & Lesion Check')
            ->firstOrFail();

        $layla = User::query()
            ->where('email', 'doctor@nadara.test')
            ->firstOrFail();

        $omar = User::factory()->doctor()->create([
            'name' => 'Dr. Omar Haddad',
            'email' => 'omar@nadara.test',
            'phone' => '0590000004',
        ]);

        $samir = User::factory()->doctor()->create([
            'name' => 'Dr. Samir Nasser',
            'email' => 'samir@nadara.test',
            'phone' => '0590000005',
            'is_active' => false,
        ]);

        $noor = User::factory()->doctor()->create([
            'name' => 'Dr. Noor Saleh',
            'email' => 'noor@nadara.test',
            'phone' => '0590000006',
        ]);

        $this->configureDoctor(
            $layla,
            specialty: 'Dermatology',
            acceptsOnlineConsultations: true,
            subscriptionStatus: SubscriptionStatus::Active,
            subscriptionEndsAt: now()->addYear(),
            services: [
                $generalExamination->id => [
                    'price' => 60.00,
                    'duration_minutes' => 30,
                    'is_active' => true,
                ],
                $acneConsultation->id => [
                    'price' => 75.00,
                    'duration_minutes' => 45,
                    'is_active' => true,
                ],
            ],
        );

        $this->configureDoctor(
            $omar,
            specialty: 'Cosmetic Dermatology',
            acceptsOnlineConsultations: true,
            subscriptionStatus: SubscriptionStatus::Active,
            subscriptionEndsAt: now()->addYear(),
            services: [
                $generalExamination->id => [
                    'price' => 85.00,
                    'duration_minutes' => 45,
                    'is_active' => true,
                ],
                $moleCheck->id => [
                    'price' => 110.00,
                    'duration_minutes' => 30,
                    'is_active' => true,
                ],
            ],
        );

        $this->configureDoctor(
            $samir,
            specialty: 'Clinical Dermatology',
            acceptsOnlineConsultations: false,
            subscriptionStatus: SubscriptionStatus::Active,
            subscriptionEndsAt: now()->addYear(),
            services: [
                $generalExamination->id => [
                    'price' => 55.00,
                    'duration_minutes' => 30,
                    'is_active' => true,
                ],
            ],
        );

        $this->configureDoctor(
            $noor,
            specialty: 'Dermatology',
            acceptsOnlineConsultations: true,
            subscriptionStatus: SubscriptionStatus::Expired,
            subscriptionEndsAt: now()->subDay(),
            services: [
                $generalExamination->id => [
                    'price' => 65.00,
                    'duration_minutes' => 30,
                    'is_active' => true,
                ],
            ],
        );
    }

    /**
     * @param  array<int, array{price: float, duration_minutes: int, is_active: bool}>  $services
     */
    private function configureDoctor(
        User $doctor,
        string $specialty,
        bool $acceptsOnlineConsultations,
        SubscriptionStatus $subscriptionStatus,
        \DateTimeInterface $subscriptionEndsAt,
        array $services,
    ): void {
        $doctor->doctorProfile()->update([
            'specialty' => $specialty,
            'accepts_appointments' => true,
            'accepts_online_consultations' => $acceptsOnlineConsultations,
            'subscription_status' => $subscriptionStatus,
            'subscription_ends_at' => $subscriptionEndsAt,
        ]);

        $doctor->offeredServices()->sync($services);

        foreach (range(0, 4) as $weekday) {
            DoctorSchedule::query()->updateOrCreate(
                [
                    'doctor_id' => $doctor->id,
                    'weekday' => $weekday,
                ],
                [
                    'starts_at' => '09:00:00',
                    'ends_at' => '17:00:00',
                    'slot_minutes' => 30,
                    'is_active' => true,
                ],
            );
        }
    }
}
