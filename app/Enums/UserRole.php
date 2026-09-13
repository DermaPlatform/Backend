<?php

namespace App\Enums;

enum UserRole: string
{
    case Patient = 'patient';
    case Doctor = 'doctor';
    case Admin = 'admin';
    case Secretary = 'secretary';

    /**
     * Roles a visitor is allowed to self-register with.
     *
     * @return array<int, string>
     */

    // هذه الدالة تحدد أي Role يُسمح للمستخدم باختياره عند التسجيل بنفسه.
    // أي أن المريض فقط يستطيع إنشاء حساب بنفسه.

    // وهذا منطقي حسب متطلبات نضارة: الـ SRS يذكر أن تسجيل الحساب الجديد مخصص للمريض، بينما المرضى والأطباء والأدمن يستطيعون تسجيل الدخول

    public static function selfRegisterable(): array
    {
        return [self::Patient->value];
    }
}
