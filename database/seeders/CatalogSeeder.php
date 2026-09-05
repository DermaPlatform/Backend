<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
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
    }
}
