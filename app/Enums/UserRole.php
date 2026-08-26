<?php

namespace App\Enums;

enum UserRole: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case Admin = 'admin';

    /**
     * Roles a visitor is allowed to self-register with.
     *
     * @return array<int, string>
     */
    public static function selfRegisterable(): array
    {
        return [self::Patient->value];
    }
}
