# نضارة — Backend

الـ API لمنصة **نضارة**: نظام إدارة لعيادات الجلدية وبوابة للمرضى فيها حجز مواعيد واستشارات أونلاين.

الأطباء مستقلون: كل طبيب ينضم باتفاق مع المنصة، والأدمن ينشئ حسابه وحساب سكرتيره. المريض يسجّل بنفسه، يتصفح الأطباء، ويحجز موعداً أو يطلب استشارة. الدفع يدوي برفع إيصال تتحقق منه العيادة، لأن بوابات الدفع الإلكتروني غير متاحة في غزة.

- **التقنية:** Laravel 12 · PHP 8.2+ · Sanctum · MySQL · Pest
- **عقد الـ API:** [`API.md`](API.md)
- **الخطة والقرارات:** [`NADARA-PLAN.md`](NADARA-PLAN.md)
- **Postman:** [`postman/Nadara-API-v1.postman_collection.json`](postman/Nadara-API-v1.postman_collection.json)

## التشغيل

```bash
composer install
cp .env.example .env
php artisan key:generate
```

عدّل بيانات قاعدة البيانات في `.env` (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`)، ثم:

```bash
php artisan migrate:fresh --seed
php artisan storage:link
php artisan serve
```

الـ API على `http://127.0.0.1:8000/api/v1`.

المهمة المجدولة التي تحرّر الحجوزات غير المدفوعة بعد انتهاء مهلتها تحتاج:

```bash
php artisan schedule:work
```

## حسابات العرض

بعد `migrate:fresh --seed`:

| الدور | الإيميل | كلمة السر | البوابة |
|---|---|---|---|
| أدمن | `admin@nadara.test` | `password` | `POST /admin/auth/login` |
| طبيب | `doctor@nadara.test` | `password` | `POST /clinic/auth/login` |
| سكرتير | `secretary@nadara.test` | `password` | `POST /clinic/auth/login` |
| مريض | `patient@nadara.test` | `password` | `POST /auth/login` |
| طبيبة (بيانات عربية) | `layla@nadara.test` | `nadara2026` | `POST /clinic/auth/login` |
| سكرتيرة (بيانات عربية) | `sana@nadara.test` | `nadara2026` | `POST /clinic/auth/login` |
| مريضة (بيانات عربية) | `sara.demo@nadara.test` | `nadara2026` | `POST /auth/login` |

عيادة د. ليلى هي عيادة العرض: أسبوع كامل من المواعيد، إيصال بانتظار التحقق، استشارات، ملاحظات طبية، دخل، إجازة قادمة، وسجل تدقيق.
لإعادة تعبئتها فقط دون مسح القاعدة: `php artisan db:seed --class=DemoSeeder` (آمن للتكرار).

## الأدوار

| الدور | ماذا يفعل |
|---|---|
| **مريض** | يسجّل بنفسه، يحجز، يرفع الإيصال، يطلب استشارة، يتابع إشعاراته |
| **طبيب** | يدير عيادته: الأسعار، الدوام، المواعيد، الإيصالات، الاستشارات، الملاحظات الطبية، تقرير الدخل |
| **سكرتير** | مربوط بطبيب واحد. الرزنامة والمصاري: المواعيد، الإيصالات، الحجز التلفوني، الدوام. **لا يرى التشخيص ولا الدخل** |
| **أدمن** | يدير المنصة: الكتالوج، حسابات الأطباء والسكرتيرين، الاشتراكات، سجل التدقيق |

كل دور يدخل من بوابته، والتوكن مختوم بالبوابة التي أصدرته: توكن مريض لا يفتح مسار عيادة.

## رحلة الحجز

```
حجز موعد (مهلة 30 دقيقة) ← رفع إيصال ← العيادة تتحقق ← الموعد مؤكَّد تلقائياً ← الزيارة ← مكتمل
```

- لا خطوة موافقة منفصلة: الفترة مأخوذة من دوام الطبيب، فهو وافق عليها مسبقاً.
- السكرتير يحجز تلفونياً لمريض اتصل: الموعد مؤكَّد ومدفوع نقداً في خطوة واحدة.
- الإلغاء من العيادة يتطلب سبباً ويعلّم الموعد «يحتاج استرجاع». الاسترجاع نفسه خارج النظام.

## نموذج الدخل

اشتراك شهري من الطبيب، والمريض مجاناً. لا نظام فوترة: التحصيل بتحويل أو واتساب، والأدمن يمدد الاشتراك يدوياً.

- طبيب جديد ← تجربة **14 يوماً**.
- بعد انتهاء التجربة أو الاشتراك ← مهلة سماح **7 أيام**.
- بعد المهلة ← يختفي من الكتالوج ولا يستقبل حجوزات جديدة. مواعيده المدفوعة وبياناته تبقى.

## الإعدادات

في `config/nadara.php`، وتتغيّر من `.env`:

| المتغيّر | الافتراضي | المعنى |
|---|---|---|
| `NADARA_CLINIC_TIMEZONE` | `Asia/Hebron` | توقيت العيادة. التخزين دائماً UTC |
| `NADARA_BOOKING_HOLD_MINUTES` | `30` | مهلة الدفع قبل تحرير الحجز |
| `NADARA_CANCELLATION_WINDOW_HOURS` | `24` | آخر مهلة لإلغاء المريض |
| `NADARA_MAX_BOOKING_DAYS_AHEAD` | `90` | أبعد يوم يمكن الحجز فيه |
| `NADARA_TRIAL_DAYS` | `14` | مدة تجربة الطبيب الجديد |
| `NADARA_GRACE_DAYS` | `7` | مهلة السماح بعد انتهاء الاشتراك |
| `NADARA_RATE_LIMIT_PER_MINUTE` | `120` | حد الطلبات لكل مستخدم |
| `FRONTEND_URL` | `http://localhost:5173` | وجهة روابط إعادة كلمة السر |
| `PATIENT_FRONTEND_URL` · `CLINIC_FRONTEND_URL` · `ADMIN_FRONTEND_URL` | — | واجهة كل بوابة إن اختلفت |
| `CORS_ALLOWED_ORIGINS` | `http://localhost:5173` | الواجهات المسموح لها، مفصولة بفواصل |

## الاختبارات

```bash
php artisan test --compact
vendor/bin/pint --test
```

الاختبارات تعمل على SQLite بالذاكرة ولا تحتاج قاعدة بيانات. الـ CI يشغّلها مع فحص التنسيق عند كل push.

ثلاثة اختبارات تحرس المنصة كلها لا ميزة واحدة:

| الملف | ماذا يضمن |
|---|---|
| `tests/Feature/Security/RoleMatrixTest.php` | كل مسار مع كل دور. مسار جديد لم يُحدَّد من يستعمله يُفشل الاختبارات |
| `tests/Feature/Performance/QueryCountTest.php` | لا قائمة تشغّل استعلاماً لكل صف (N+1) |
| `tests/Feature/EndToEndJourneyTest.php` | الرحلة كاملة عبر HTTP: من إنشاء العيادة إلى تقرير الدخل |

## هيكل المشروع

```
app/
  Enums/           الحالات والأدوار والبوابات
  Http/
    Controllers/Api/V1/   Admin* للأدمن · Staff* للعيادة · Doctor* للطبيب
    Requests/Api/V1/      التحقق من المدخلات
    Resources/Api/V1/     شكل الردود
  Models/
  Policies/        من يرى ماذا: الموعد، الدفع، الاستشارة
  Services/        منطق العمل: الإتاحة، الحجز، الدفع، الإلغاء، الاستشارات، الدوام
routes/api.php     كل المسارات تحت /api/v1
```

## طريقة العمل

- كل تغيير على فرع باسمه ثم PR على `main`. لا رفع مباشر، ولا `force push` على `main`.
- قبل الرفع: `php artisan test --compact` و `vendor/bin/pint`.
- أي مسار جديد يُضاف إلى `API.md` وكولكشن Postman ومصفوفة `RoleMatrixTest`.
