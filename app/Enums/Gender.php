<?php

namespace App\Enums;

/**
 * Biological sex, recorded because dermatology treatment decisions depend on
 * it (hormonal therapy, and the pregnancy contraindications that come with
 * drugs such as isotretinoin).
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
