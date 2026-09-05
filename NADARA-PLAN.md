# خطة تطوير منصة نضارة — Backend

> **الحالة:** المرحلة 0 مكتملة ✅ · التالي: المرحلة 0.5 ثم Sprint 3
> **آخر تحديث:** 2026-09-05

---

## نظرة عامة

منصة **نضارة**: نظام إدارة عيادات جلدية (إداري ومالي) + بوابة مرضى للحجز والاستشارة عن بعد.

أطباء **مستقلون** ينضمون للمنصة بعد اتفاق — لا عيادة واحدة فيها عدة أطباء. نضارة تُنشئ حساب
الطبيب وحساب سكرتيره، والتسجيل الذاتي للمريض وحده. المريض يتصفح أطباء المنصة ويختار: حجز
بعيادة الطبيب، أو استشارة أونلاين.

| البند | القيمة |
|---|---|
| التقنيات | Laravel 12 · PHP 8.2 · Sanctum 4 · MySQL |
| الواجهة | React 19 + Vite + Tailwind 4 (ريبو منفصل) |
| المنهجية | Agile Scrum — 6 سبرنتات |
| القصص | 24 قصة + 4 قصص جديدة (US-025 → US-028) |
| المرجع | وثيقة القصص + SRS المعتمدة |

### القرارات المعتمدة

| القرار | الاختيار | الأثر |
|---|---|---|
| **الدفع** | رفع إيصال يدوي + تحقق المسؤول | لا تكامل مع بوابة أونلاين — Stripe/PayPal غير متاحين في غزة |
| **ترتيب الحجز** | حجز ← دفع ← موافقة | بدون منطق استرجاع في النظام |
| **النطاق** | Backend فقط | + عقد API موثّق للفرونت |
| **الأطباء** | أطباء مستقلون، المريض يختار | جداول دوام + ربط أطباء بخدمات |
| **الدخل** | اشتراك شهري من الطبيب | المريض مجاناً · العمولة مؤجَّلة حتى تثبت المنصة أنها تجلب مرضى |
| **السعر** | على `doctor_service` لا على `services` | كل طبيب يسعّر خدمته ويحدد مدتها · الكتالوج يعرض "يبدأ من" |
| **العمليات اليومية** | سكرتير الطبيب لا أدمن المنصة | دور رابع مربوط بطبيب واحد — يرى الرزنامة والمصاري لا التشخيص |
| **الاستشارة أونلاين** | اختيارية لكل طبيب | التشخيص عن بُعد مسؤولية مهنية — يُفعَّل بموافقة صريحة |

**حدّ النطاق لكلمة "مالي":** تتبّع الدخل نعم (مَن دفع، كم، متى، تقرير شهري، كشف الاشتراك).
المحاسبة لا (مصاريف، رواتب، فواتير ضريبية، تسويات بنكية) — ذاك منتج مستقل.

---

## الوضع الحالي

| السبرنت | الحالة |
|---|---|
| Sprint 1 — المصادقة والصلاحيات | ✅ مكتمل ومختبر |
| **المرحلة 0** — إصلاح وتوحيد | ✅ **مكتملة** |
| Sprint 2 — الكتالوج والبروفايل | ✅ يعمل بالكامل بعد المرحلة 0 |
| **المرحلة 0.5** — تسوية الديون + إعادة التموضع | ⬜ **التالي** (تُنفَّذ داخل أسبوع Sprint 3، بند 3.1) |
| Sprint 3 — الحجز والدفع | ⬜ |
| Sprint 4 — لوحة الإدارة والتشغيل | ⬜ |
| Sprint 5 — بوابة الطبيب | ⬜ |
| Sprint 6 — الاختبارات والتسليم | ⬜ |

**الاختبارات حالياً:** 134 اختبار · 449 تأكيد · كلها خضراء
**الفرع:** `feature/dermatology-services` — نظيف، آخر كوميت `1c55281`

---

## المرحلة 0 — الإصلاح والتوحيد ✅

نُفِّذت بالكامل في الفرع `feature/sprint2-remediation` (كوميت `0bfcc4a`).

### ما تم إصلاحه

| # | المشكلة | الحل |
|---|---|---|
| 0.1 | `ServiceCategory` بدون `SoftDeletes` رغم وجود `deleted_at` → حذف نهائي | إضافة الـ trait |
| 0.2 | `cascadeOnDelete` يمسح كل خدمات التصنيف نهائياً | تحويله لـ `restrictOnDelete` |
| 0.3 | `StoreServiceRequest` بـ namespace خاطئ → 500 عند إضافة خدمة | تصحيح بالنقل |
| 0.6 | `AdminCategoryController` فاضي و5 راوتس تشير له → 500 | كتابته كاملاً |
| 0.6 | `StoreCategoryRequest.authorize()` = `false` → 403 دائماً | تعبئتها بقواعد حقيقية |
| 0.6 | كود ميت في `AdminServiceController` (3 ميثودز بلا راوت) | حذفه |
| 0.7 | `phone` بدون `unique` → 500 من قاعدة البيانات | `Rule::unique()->ignore()` |
| 0.8 | `ProfileController` يبني JSON يدوياً | استخدام `UserResource` |
| 0.9 | `->get()` على كل القوائم | Pagination مع سقف 100 |

### ما تم توحيده

- **11 ملف** نُقلوا من `Api\V2` إلى `Api\V1` (4 كنترولرز، 5 requests، 2 resources) مع الحفاظ على git history
- الراوتس مدموجة في مجموعة `v1` وكلها مسمّاة `api.v1.*`
- `EnsureAdmin` → `EnsureRole` بمعامل (`role:admin`, `role:doctor`) — جاهز لبوابة الطبيب

### ما تمت إضافته

- `is_active` على الخدمات — لشق "التعطيل" في US-008
- `ServiceCategoryFactory` + `ServiceFactory` + `CatalogSeeder` (13 خدمة جلدية واقعية)
- `config/nadara.php` — المنطقة الزمنية وقواعد الحجز
- 49 اختبار Feature يغطي US-005 → US-008

---

## المرحلة 0.5 — تسوية الديون وإعادة التموضع

**لا ميزة جديدة ولا قصة مستخدم.** هذه المرحلة هي بند **3.1 البنية التحتية** من Sprint 3 نفسه،
مُفصَّلاً ومُنفَّذاً أولاً — فلا تضيف يوماً واحداً على الجدول الزمني.

### 0.5.أ — خمسة موديلات لم تُلمس منذ كوميت `251d374`

`Appointment` · `Payment` · `Consultation` · `ConsultationMessage` · `MedicalNote` كُتبت قبل
المرحلة 0 كلها. بعدها ارتفع معيار `Service` و`User` (casts، return types، PHPDoc generics)
وبقيت هي كما هي. **سبرنت 3 كله يُبنى فوقها.**

| # | العطل | الدليل | الإصلاح |
|---|---|---|---|
| 0.5.1 | لا factories لأيٍّ من الخمسة | `Appointment` فيه `use HasFactory` و`AppointmentFactory` غير موجود → `Appointment::factory()` ترمي استثناء | خمسة factories — **بدونها لا يمكن كتابة اختبار واحد لسبرنت 3** |
| 0.5.2 | `Payment::$amount` نص لا رقم | الجدول `decimal(10,2)` والموديل بلا `casts()` إطلاقاً → `"150.00"` | `'amount' => 'decimal:2'` أسوةً بـ `Service::$price` |
| 0.5.3 | `ConsultationMessage.created_at` نص لا Carbon | `public $timestamps = false` مع عمود `created_at` بالجدول → لا يُكاست أبداً | `const UPDATED_AT = null;` بدل `$timestamps = false` |
| 0.5.4 | ثلاثة FK تمحو سجلات لا يجوز محوها | تفصيلها أدناه | `restrictOnDelete` |
| 0.5.5 | لا Enums للحالات ولا return types ولا `casts()` | `app/Enums/` فيه `UserRole` و`Gender` فقط | Enums لكل حالة على نمط `UserRole` |

**تفصيل 0.5.4 — فقدان بيانات، نفس نوع الخطأ المُصلَح بالبند 0.2:**

| القيد | الآن | ماذا يحدث فعلياً |
|---|---|---|
| `payments.patient_id` | `cascadeOnDelete` | حذف مريض يمحو سجله المالي — الدخل يختفي من التقارير بأثر رجعي |
| `medical_notes.patient_id` · `doctor_id` | `cascadeOnDelete` | حذف مستخدم يمحو السجل الطبي — الاحتفاظ به التزام مهني |
| `consultation_messages.sender_id` | `cascadeOnDelete` | حذف طبيب يمحو رسائله من محادثة قائمة، فتصير نصف حوار |

**تفصيل 0.5.5 — الحالات الحالية لا تطابق آلات الحالة أدناه، وبأسماء مختلفة لا أضيق فقط:**

| العمود | في الـ migration الآن | في آلات الحالة |
|---|---|---|
| `appointments.status` | `pending` · `approved` · `rejected` · `cancelled` | `pending_payment` · `awaiting_review` · `approved` · `completed` · `expired` · `rejected` · `cancelled` |
| `payments.status` | `pending` · `paid` · `failed` | `pending` · `submitted` · `verified` · `rejected` |

`paid` و`failed` لا وجود لهما بالخطة، و`submitted` و`verified` لا وجود لهما بالجدول.

### 0.5.ب — migrations إعادة التموضع

تُنفَّذ بنفس الدفعة لأنها تمسّ الجداول ذاتها (التفصيل في قسم **قاعدة البيانات** أدناه):
الدور الرابع وأعمدته على `users` · جدول `doctor_service` بالسعر والمدة · `services.price`
تصير nullable · لقطة السعر على `appointments`.

### لماذا الآن لا لاحقاً

لو بُني Sprint 3 أولاً، تُكتب كل اختباراته على `status` بأسماء خاطئة وعلى `amount` نصّي —
وإصلاحها بعدها يعني إعادة كتابة السبرنت لا إصلاح الموديلات.

### بوابة القبول

`php artisan test --compact` → **134 اختبار تبقى خضراء** (المرحلة لا تمسّ أي مسار مبني)
· `Appointment::factory()->create()` تنجح · `Payment::first()->amount` رقم
· `ConsultationMessage::first()->created_at` كائن `Carbon`.

---

## التعديلات على القصص

**21 تعديل منطقي** موثّقة بالتفصيل في ملف منفصل: **`USER-STORIES-CHANGES.md`**

ملخص سريع:

| الفئة | العدد | أمثلة |
|---|---|---|
| 🔴 تناقضات تمنع التنفيذ | 8 | المواعيد بدون وقت · تناقض US-009 مع US-015 · لا جدول دوام |
| 🟠 فجوات منطقية | 8 | لا مسار لإنشاء حساب طبيب · CSRF لا ينطبق · US-021 في السبرنت الخطأ |
| 🟡 تحسينات تقنية | 5 | المنطقة الزمنية · سجل التدقيق · Policies |

---

## قاعدة البيانات

### تعديلات على جداول قائمة

| الجدول | التغيير | السبب |
|---|---|---|
| `appointments` | `requested_date` → `start_at` + `end_at` (datetime)؛ إضافة `hold_expires_at`, `approved_by`, `approved_at`, `rejection_reason`, `cancelled_at`, `cancellation_reason`؛ **`price` (لقطة سعر)**؛ توسيع `status`؛ فهارس | تاريخ بلا وقت لا يخدم أي قصة مواعيد |
| `services` | `default_duration_minutes`؛ **`price` تصير `nullable`** | مدة مقترحة على الطبيب · والسعر انتقل إلى `doctor_service` |
| `payments` | `receipt_number` (فريد), `receipt_path`, `method`, `reference`, `verified_by`, `verified_at`, `rejection_reason`, `paid_at`؛ توسيع `status`؛ **`patient_id` → `restrictOnDelete`** | US-011 و US-014 · والبند 0.5.4 |
| `consultations` | `symptoms`, `price`, `submitted_at`, `replied_at` | US-012 |
| `medical_notes` | **`patient_id` و `doctor_id` → `restrictOnDelete`** | البند 0.5.4 — السجل الطبي لا يُمحى بحذف حساب |
| `consultation_messages` | **`sender_id` → `restrictOnDelete`** | البند 0.5.4 |
| `users` | فهرس على `name` و `phone`؛ **`role` يقبل `secretary`**؛ **`doctor_id`** (nullable FK)؛ **`accepts_appointments`** (default `true`)؛ **`accepts_online_consultations`** (default `false`)؛ **`subscription_status`** + **`subscription_ends_at`** | بحث US-016 · الدور الرابع · الاشتراك |

**قواعد حاكمة على الجداول أعلاه:**

1. **مصدر السعر واحد:** الحجز يقرأ السعر من `doctor_service` **دائماً**. `services.price` سعر
   إرشادي للكتالوج فقط ("يبدأ من")، ويُستخدم حين لا يسعّر الخدمةَ أيُّ طبيب بعد.
2. **لقطة السعر إلزامية:** `appointments.price` تُكتب لحظة الحجز. الطبيب سيغيّر سعره لاحقاً،
   وبدون اللقطة يتغيّر سعر موعد قديم بأثر رجعي وينكسر تقرير الدخل (US-027).
3. **`role` عمود `enum`:** إضافة `secretary` تحتاج migration يعيد كتابة كل خصائص العمود
   بما فيها `->default('patient')`، وإلا سقطت.
4. **الاشتراك يُنفَّذ بالوصول لا بالتحصيل:** لا بوابة دفع. انتهاء الاشتراك يوقف **الجديد فقط** —
   الطبيب يختفي من الكتالوج ولا يقبل حجوزات أو استشارات جديدة، بينما المواعيد المحجوزة
   والمدفوعة لا تُلغى، وحساب السكرتير لا يُقفل، والبيانات لا تُمسح. مهلة سماح 7 أيام.

### جداول جديدة

| الجدول | الغرض | القصة |
|---|---|---|
| `doctor_schedules` | دوام أسبوعي لكل طبيب | US-010 |
| `schedule_exceptions` | إجازات وأيام استثنائية | US-010 |
| `doctor_service` | أي طبيب يقدّم أي خدمة — **ومعها `price` و `duration_minutes` و `is_active`**، فريد على الزوج | US-009 |
| `attachments` | صور الحالة الجلدية (polymorphic) | US-012 |
| `settings` | رسوم الاستشارة، نافذة الإلغاء، مهلة الحجز | US-008, US-012 |
| `audit_logs` | تتبّع التغييرات الحساسة | فجوة أمنية |

### آلات الحالة

```
الموعد (Appointment):

  pending_payment ──رفع إيصال──> awaiting_review ──تحقق + موافقة──> approved ──> completed
        │                              │                                │
   انتهاء المهلة                    رفض المسؤول                   إلغاء المريض
        ↓                              ↓                                ↓
     expired                       rejected                        cancelled


الدفع (Payment):

  pending ──رفع إيصال──> submitted ──تحقق المسؤول──> verified
                              │
                              └──رفض──> rejected


الاستشارة (Consultation):

  pending_payment ──إيصال + تحقق──> awaiting_reply ──رد الطبيب──> replied ──> closed
```

كل حالة تصبح **PHP Enum** في `app/Enums/` على نمط `UserRole` الموجود.

---

## Sprint 3 — الحجز والدفع · أسبوع

**القصص:** US-009، US-010، US-011، US-012، **US-021a** (منقولة من Sprint 6)

### 3.1 البنية التحتية
**= المرحلة 0.5 أعلاه.** Migrations للجداول أعلاه + Enums + Factories + Seeders، مسبوقة بتسوية
ديون الموديلات الخمسة. تُنفَّذ أول أيام السبرنت ولا تُؤجَّل بعد أي بند آخر.

### 3.2 `AvailabilityService` — قلب السبرنت

`app/Services/AvailabilityService.php` — توليد الفترات المتاحة:

1. يبني الفترات من `doctor_schedules` بخطوة `slot_minutes`
2. يطرح `schedule_exceptions` (الإجازات)
3. يطرح المواعيد المشغولة — الحالات: `pending_payment` (والمهلة سارية)، `awaiting_review`، `approved`
4. يطرح الفترات في الماضي
5. يعيد `[{ start_at, end_at, available }]`

### 3.3 `BookingService` — إنشاء الحجز

- التحقق أن الطبيب يقدّم الخدمة (`doctor_service`) **وأن `accepts_appointments` = true وأن اشتراكه سارٍ**
- `end_at = start_at + doctor_service.duration_minutes` — **المدة من الطبيب لا من الخدمة**؛ `services.default_duration_minutes` قيمة مقترحة وقت التسعير فقط
- **`appointments.price = doctor_service.price`** — لقطة تُكتب مرة واحدة ولا تُقرأ من الجدول لاحقاً
- **فحص التعارض داخل `DB::transaction` مع `lockForUpdate()`** — بدونه سباق تزامني يسمح بحجزين لنفس الفترة
- `hold_expires_at = now() + config('nadara.booking_hold_minutes')`

### 3.4 رفع الإيصال

- `POST /appointments/{id}/payment-receipt` — multipart
- التحقق: `mimes:jpg,jpeg,png,pdf|max:4096`
- التخزين على قرص `local` = `storage/app/private` — **غير قابل للوصول العام** (مضبوط مسبقاً)
- توليد `receipt_number` بصيغة `NDR-{YEAR}-{seq}`
- الحالات: الدفع → `submitted`، الموعد → `awaiting_review`، مسح `hold_expires_at`

### 3.5 الاستشارات
`POST /consultations` مع `symptoms` + مرفقات صور. الحالة تصبح `awaiting_reply` **فقط بعد تحقق المسؤول**.
**شرط مسبق:** الطبيب المقصود عليه `accepts_online_consultations` = true، وإلا 422 — بعض الأطباء
لا يرتاحون للتشخيص عن بعد، وإجبارهم يخسّر أطباء.

### 3.6 إلغاء الحجز
نافذة 24 ساعة من `config`. تحرير الفترة فوراً. تعليم `refund_required` بدون منطق استرجاع.

### 3.7 مهمة مجدولة
`ReleaseExpiredHolds` كل 5 دقائق.

### 3.8 Policies
`AppointmentPolicy`, `ConsultationPolicy` — المريض يرى ويلغي حجوزاته فقط.

> ⚠️ **`app/Policies/` غير موجود بالمشروع إطلاقاً.** بأربعة أدوار صارت الـ Policies ضرورة لا
> اختياراً، ومصفوفة US-022 تصير 4×N. يُنشأ المجلد هنا لا في Sprint 6.

---

## Sprint 4 — لوحة الإدارة والتشغيل · أسبوع

**القصص:** US-013، US-014، US-015، US-016، **US-025**، **US-026** (جديدة)، **US-028** (جديدة)

هذا السبرنت **منقسم على خطٍّ موجود أصلاً**: US-013/014/015/016 عمليات يومية تخصّ طبيباً واحداً
(← السكرتير)، بينما US-025/028 إدارة منصة (← أدمن نضارة). إضافة الدور الرابع ليست توسيعاً
للسبرنت، بل تسمية للخط الذي كان مرسوماً فيه.

### تشغيل — `role:admin,secretary` تحت `/api/v1/staff/*`

| المهمة | التفصيل |
|---|---|
| US-013 موافقة/رفض | **يشترط أن يكون الدفع `verified` أولاً**؛ الرفض يتطلب سبباً |
| US-015 المدة | `duration_minutes` اختياري في الموافقة يتجاوز مدة `doctor_service`، **مع إعادة فحص التعارض** |
| US-014 التحقق المالي | قائمة بفلاتر (حالة، تاريخ، بحث) + `verify` + `reject` |
| تحميل الإيصال | **بث الملف عبر كنترولر محمي بـ Policy** — وليس رابطاً عاماً |
| US-016 الجدول اليومي | مواعيد اليوم المعتمدة والمدفوعة |
| US-016 قائمة المرضى | بحث بالاسم/الهاتف + Pagination |
| **US-026 الحجز التلفوني** | السكرتير يحجز لمريض اتصل — إنشاء/إيجاد المريض ثم موعد بحالة مدفوعة نقداً |
| إدارة الدوام | CRUD لجداول دوام طبيبه واستثناءاته |

**فلترة كل ما سبق:** `where doctor_id = auth()->user()->doctor_id` — السكرتير مربوط بطبيب
**واحد**، فلا pivot ولا فلترة متعددة. الأدمن يمرّ بنفس الراوتس بلا قيد الطبيب.

**قيد صارم:** السكرتير يرى **الرزنامة والمصاري**، لا يرى **التشخيص**. عملياً: لا يُضاف إلى
`[Doctor, Admin]` في `UserResource::viewerMaySeeClinicalDetails()` — تاريخ الميلاد والجنس
بيانات سريرية — وممنوع عليه الملاحظات الطبية ومرفقات الاستشارات.
يغطي ذلك `tests/Feature/UserResourceVisibilityTest.php` الموجود.

### إدارة المنصة — `role:admin` تحت `/api/v1/admin/*` (يبقى كما هو)

| المهمة | التفصيل |
|---|---|
| US-025 إدارة المستخدمين | CRUD لحسابات الأطباء **وحسابات السكرتيرين** + seeder لأول مسؤول · لا تسجيل ذاتي إلا للمريض |
| **US-028 إدارة الاشتراكات** | كشف اشتراكات الأطباء + تمديد يدوي. **لا نظام فوترة للـ MVP** — عمودان وشاشة تمديد؛ تحصيل عشرة اشتراكات يتم بواتساب لا بكود |
| تصنيف الخدمات | كتالوج المنصة (US-008) — يبقى للأدمن |
| سجل التدقيق | تسجيل كل موافقة/رفض/تحقق دفع/تمديد اشتراك |

---

## Sprint 5 — بوابة الطبيب · أسبوع

**القصص:** US-017، US-018، US-019، US-020، **US-021b** (منقولة من Sprint 6)، **US-027** (جديدة)

| المهمة | التفصيل |
|---|---|
| US-017 جدول الطبيب | يومي وأسبوعي — **مقصور على `approved` + دفع `verified`** |
| **US-027 تقرير دخل الطبيب** | مجموع `appointments.price` و `consultations.price` للمواعيد المكتملة، مفلترة بمدى تاريخي. **يقرأ لقطة السعر لا `doctor_service`** وإلا تغيّر دخل الشهر الماضي كلما عدّل الطبيب سعره |
| تسعير خدماته | الطبيب يضيف/يعدّل `doctor_service` — سعره ومدته و`is_active` |
| تفضيلاته | `accepts_appointments` و `accepts_online_consultations` |
| US-018 الاستشارات الواردة | مع المرفقات، مفروزة بالتاريخ |
| US-019 الرد | كتابة الرد + تحديث الحالة إلى `replied` **داخل transaction واحدة** |
| US-020 الملاحظات الطبية | مقيّدة بـ `MedicalNotePolicy` — الطبيب يكتب لمواعيده فقط + أرشيف الزيارات السابقة |
| US-021b متابعة المريض | عرض حالة الطلبات وردود الأطباء |
| الإشعارات | عند: موافقة/رفض حجز، تحقق دفع، رد استشارة |

---

## Sprint 6 — الاختبارات والتسليم · أسبوع

**القصص:** US-022، US-023، US-024 · *(US-021 نُقلت لسبرنتات سابقة)*

| المهمة | معيار القبول |
|---|---|
| US-022 الأمان | مصفوفة أدوار كاملة: كل endpoint × كل دور → 403. تدقيق Rate Limiting و Token Abilities و CORS. **تأكيد أن الإيصالات غير قابلة للوصول بدون تصريح** |
| US-023 الأداء | فهارس مطبّقة · صفر N+1 · **p95 < 300ms** لكل endpoint |
| US-024 التكامل | اختبار E2E واحد للسلسلة الكاملة |
| التوثيق | عقد API نهائي + README + بيانات عرض |

**سيناريو الـ E2E:**
```
تسجيل مريض → اختيار خدمة وطبيب → حجز موعد → رفع إيصال
   → تحقق المسؤول → موافقة وتحديد مدة → ظهوره بجدول الطبيب
   → كتابة ملاحظة طبية → تقديم استشارة → رد الطبيب
```

---

## عقد الـ API

كل المسارات تحت `/api/v1/` بأسماء `api.v1.*` · المصادقة: `Authorization: Bearer <token>`

### عام — بدون مصادقة
```
GET  /categories
GET  /services              ?category_id= &q= &per_page=      ← يُضاف price_from
GET  /services/{service}
GET  /doctors               ?service_id= &accepts_appointments= &accepts_online_consultations=
GET  /doctors/{doctor}      ملف الطبيب: نبذة + خدماته بأسعارها ومددها + دوامه
GET  /availability          ?doctor_id= &service_id= &date=
```

**الكتالوج بعد نقل السعر:** `services.price` لم تعد سعراً نهائياً — الرد يحمل
`price_from = MIN(doctor_service.price)` للخدمة، ويسقط إلى `services.price` حين لا يسعّرها
أي طبيب بعد. السعر الدقيق يظهر بعد اختيار الطبيب.
**الأطباء غير النشطين** (اشتراك منتهٍ بعد مهلة السماح) لا يظهرون في `/doctors` ولا في
`price_from`، ولا يقبلون حجزاً أو استشارة جديدة.

### المريض — `auth:sanctum`
```
GET  /profile                             PUT  /profile
POST /appointments                        { doctor_id, service_id, start_at }
GET  /appointments                        GET  /appointments/{id}
POST /appointments/{id}/cancel
POST /appointments/{id}/payment-receipt   multipart
POST /consultations                       { symptoms, attachments[] }
GET  /consultations                       GET  /consultations/{id}
POST /consultations/{id}/payment-receipt  multipart
GET  /payments
```

### التشغيل — `auth:sanctum` + `role:admin,secretary`
```
GET  /staff/appointments                  ?status= &date=
POST /staff/appointments                  { patient_id | patient{}, service_id, start_at }  ← US-026 حجز تلفوني
POST /staff/appointments/{id}/approve     { duration_minutes?, notes? }
POST /staff/appointments/{id}/reject      { reason }
GET  /staff/payments                      ?status= &from= &to= &q=
POST /staff/payments/{id}/verify          POST /staff/payments/{id}/reject
GET  /staff/payments/{id}/receipt         بث ملف محمي
GET  /staff/schedule/daily                ?date=
GET  /staff/patients                      ?q=       GET /staff/patients/{id}
apiResource /staff/doctor-schedules       + /schedule-exceptions
```
السكرتير مقصور على `doctor_id` الخاص به؛ الأدمن يمرّ بلا قيد. **لا شيء تشخيصياً هنا:**
لا ملاحظات طبية، ولا مرفقات استشارات، ولا `birth_date`/`gender` في رد `/staff/patients`.

### إدارة المنصة — `auth:sanctum` + `role:admin`
```
apiResource /admin/categories
apiResource /admin/services               POST /admin/services/{id}/toggle-active
apiResource /admin/doctors                + إنشاء حساب السكرتير المرتبط
GET  /admin/subscriptions                 ?status=                        ← US-028
POST /admin/doctors/{id}/extend-subscription  { ends_at }                 ← US-028
```

### الطبيب — `auth:sanctum` + `role:doctor`
```
GET  /doctor/appointments                 ?date= | ?week=
GET  /doctor/appointments/{id}
GET  /doctor/consultations                ?status=
GET  /doctor/consultations/{id}
POST /doctor/consultations/{id}/reply     { message }
GET  /doctor/patients/{id}/medical-notes
POST /doctor/medical-notes                { patient_id, appointment_id, notes }
PUT  /doctor/medical-notes/{id}
GET  /doctor/income                       ?from= &to=                     ← US-027
apiResource /doctor/services              { service_id, price, duration_minutes, is_active }
PUT  /doctor/preferences                  { accepts_appointments, accepts_online_consultations }
```

**اتفاقيات موحّدة:** كل الردود عبر API Resources · القوائم مرقّمة · الأخطاء بصيغة Laravel القياسية · كل التواريخ ISO-8601.

---

## أنماط قائمة يجب إعادة استخدامها

| النمط | الموقع |
|---|---|
| بنية Enum | `app/Enums/UserRole.php` |
| ميدلوير الأدوار | `app/Http/Middleware/EnsureRole.php` |
| تسجيل الميدلوير | `bootstrap/app.php` |
| Rate Limiter | `app/Providers/AppServiceProvider.php:70` |
| نمط الكنترولر | `app/Http/Controllers/Api/V1/AuthController.php` |
| نمط الـ Resource | `app/Http/Resources/Api/V1/UserResource.php` |
| حساب حجم الصفحة | `app/Http/Controllers/Controller.php` — `perPage()` |
| تسمية الراوتس | `routes/api.php` |
| قرص التخزين الخاص | `config/filesystems.php` — `local` = `storage/app/private` |
| بنية الاختبارات | `tests/Feature/` |

---

## التحقق

### بعد كل مرحلة
```bash
php artisan test --compact                 # كل الاختبارات خضراء
vendor/bin/pint --dirty --format agent     # تنسيق نظيف
php artisan route:list --path=v1           # لا راوت يشير لميثود مفقود
php artisan migrate:fresh --seed           # المخطط يُبنى من الصفر
```

### بوابات خاصة بكل سبرنت

| السبرنت | التحقق |
|---|---|
| **0** ✅ | كل راوتس Sprint 2 تعمل — لا 500 · حذف تصنيف لا يمسح خدماته |
| **0.5** | 134 اختبار تبقى خضراء · `Appointment::factory()` تنجح · `Payment::first()->amount` رقم · حذف مستخدم له دفعة → يُرفَض لا يُمحى |
| **3** | طلبا حجز متوازيان لنفس الفترة → واحد ينجح والثاني 409 · انتهاء المهلة يحرّر الفترة · `appointments.price` = سعر الطبيب لحظة الحجز لا سعره الحالي |
| **4** | الموافقة ترفض إن لم يُتحقق من الدفع · تحميل إيصال بتوكن مريض → 403 · سكرتير يفتح موعد طبيب آخر → 403 · سكرتير يطلب `birth_date` مريض → الحقل غائب |
| **5** | الطبيب لا يرى مواعيد غير مدفوعة · طبيب آخر يقرأ ملاحظة → 403 · تعديل سعر الطبيب لا يغيّر تقرير دخل شهر مضى |
| **6** | E2E كامل + **مصفوفة أدوار 4×N** + `assertQueryCount` |

---

## الجدول الزمني

| المرحلة | المدة | المخرج |
|---|---|---|
| ~~المرحلة 0~~ | ~~3–4 أيام~~ | ✅ **مكتملة** |
| Sprint 3 | أسبوع | **المرحلة 0.5 (= بند 3.1)** + حجز + توفر + إيصالات + استشارات + إلغاء |
| Sprint 4 | أسبوع | تشغيل السكرتير + إدارة المنصة والاشتراكات + الدوام |
| Sprint 5 | أسبوع | بوابة الطبيب + التسعير + تقرير الدخل + ملاحظات + إشعارات |
| Sprint 6 | أسبوع | أمان + أداء + E2E + تسليم |

**المتبقي: 4 أسابيع — بلا تمديد.**

إعادة التموضع لا تضيف أسبوعاً لأنها **إعادة تموضع لا إعادة بناء**: المصادقة والبروفايل وآلات
الحالة وفلو الحجز والدفع كلها تبقى. ما ينتقل شيئان — السعر من `services` إلى `doctor_service`،
وملكية العمليات من الأدمن إلى السكرتير — وكلاهما داخل عمل مُخطَّط له أصلاً:

| العنصر الجديد | يُستوعَب داخل |
|---|---|
| المرحلة 0.5 | بند **3.1 البنية التحتية** الذي كان يعد بـ "Migrations + Enums + Factories" |
| السكرتير | **Sprint 4** — نفس القصص، بتقسيم ملكية لا بمهام إضافية |
| US-026 حجز تلفوني | Sprint 4 — مسار كتابة على `BookingService` المبني في Sprint 3 |
| US-027 تقرير الدخل | Sprint 5 — استعلام تجميعي واحد على لقطة السعر |
| US-028 الاشتراكات | Sprint 4 — عمودان وشاشة تمديد، لا نظام فوترة |

---

## ملاحظات تشغيلية

1. **الفرع الحالي:** `feature/dermatology-services` — نظيف، 134 اختبار خضراء، آخر كوميت `1c55281`.
   `develop` ما زال متأخراً ولا يحتوي كود Sprint 2.

2. **قبل الاستمرار:** شغّل WampServer ونفّذ `php artisan migrate` — الاختبارات تعمل على SQLite في الذاكرة، لكن migrations المرحلة 0 لم تُطبَّق بعد على قاعدة MySQL المحلية.

3. **ملفات مرجعية:**
   - `V2-REVIEW.md` — تقرير مراجعة Sprint 2 الأصلي
   - `USER-STORIES-CHANGES.md` — التعديلات المقترحة على القصص
