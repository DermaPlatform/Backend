# خطة تطوير منصة نضارة — Backend

> **الحالة:** المرحلة 0 مكتملة ✅ · التالي: Sprint 3
> **آخر تحديث:** 2026-08-24

---

## نظرة عامة

منصة **نضارة** لإدارة عيادات الجلدية والعناية بالبشرة.

| البند | القيمة |
|---|---|
| التقنيات | Laravel 12 · PHP 8.2 · Sanctum 4 · MySQL |
| الواجهة | React 19 + Vite + Tailwind 4 (ريبو منفصل) |
| المنهجية | Agile Scrum — 6 سبرنتات |
| القصص | 24 قصة + قصة جديدة مقترحة (US-025) |
| المرجع | وثيقة القصص + SRS المعتمدة |

### القرارات المعتمدة

| القرار | الاختيار | الأثر |
|---|---|---|
| **الدفع** | رفع إيصال يدوي + تحقق المسؤول | لا تكامل مع بوابة أونلاين — Stripe/PayPal غير متاحين في غزة |
| **ترتيب الحجز** | حجز ← دفع ← موافقة | بدون منطق استرجاع في النظام |
| **النطاق** | Backend فقط | + عقد API موثّق للفرونت |
| **الأطباء** | عدة أطباء، المريض يختار | يتطلب جداول دوام وربط أطباء بخدمات |

---

## الوضع الحالي

| السبرنت | الحالة |
|---|---|
| Sprint 1 — المصادقة والصلاحيات | ✅ مكتمل ومختبر (44 اختبار) |
| **المرحلة 0** — إصلاح وتوحيد | ✅ **مكتملة** (49 اختبار) |
| Sprint 2 — الكتالوج والبروفايل | ✅ يعمل بالكامل بعد المرحلة 0 |
| Sprint 3 — الحجز والدفع | ⬜ التالي |
| Sprint 4 — لوحة الإدارة | ⬜ |
| Sprint 5 — بوابة الطبيب | ⬜ |
| Sprint 6 — الاختبارات والتسليم | ⬜ |

**الاختبارات حالياً:** 93 اختبار · 308 تأكيد · كلها خضراء

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
| `appointments` | `requested_date` → `start_at` + `end_at` (datetime)؛ إضافة `hold_expires_at`, `approved_by`, `approved_at`, `rejection_reason`, `cancelled_at`, `cancellation_reason`؛ توسيع `status`؛ فهارس | تاريخ بلا وقت لا يخدم أي قصة مواعيد |
| `services` | `default_duration_minutes` | لحساب الفترة وقت الحجز |
| `payments` | `receipt_number` (فريد), `receipt_path`, `method`, `reference`, `verified_by`, `verified_at`, `rejection_reason`, `paid_at`؛ توسيع `status` | US-011 و US-014 |
| `consultations` | `symptoms`, `price`, `submitted_at`, `replied_at` | US-012 |
| `users` | فهرس على `name` و `phone` | بحث US-016 |

### جداول جديدة

| الجدول | الغرض | القصة |
|---|---|---|
| `doctor_schedules` | دوام أسبوعي لكل طبيب | US-010 |
| `schedule_exceptions` | إجازات وأيام استثنائية | US-010 |
| `doctor_service` | أي طبيب يقدّم أي خدمة | US-009 |
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
Migrations للجداول أعلاه + Enums + Factories + Seeders.

### 3.2 `AvailabilityService` — قلب السبرنت

`app/Services/AvailabilityService.php` — توليد الفترات المتاحة:

1. يبني الفترات من `doctor_schedules` بخطوة `slot_minutes`
2. يطرح `schedule_exceptions` (الإجازات)
3. يطرح المواعيد المشغولة — الحالات: `pending_payment` (والمهلة سارية)، `awaiting_review`، `approved`
4. يطرح الفترات في الماضي
5. يعيد `[{ start_at, end_at, available }]`

### 3.3 `BookingService` — إنشاء الحجز

- التحقق أن الطبيب يقدّم الخدمة (`doctor_service`)
- `end_at = start_at + service.default_duration_minutes`
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

### 3.6 إلغاء الحجز
نافذة 24 ساعة من `config`. تحرير الفترة فوراً. تعليم `refund_required` بدون منطق استرجاع.

### 3.7 مهمة مجدولة
`ReleaseExpiredHolds` كل 5 دقائق.

### 3.8 Policies
`AppointmentPolicy`, `ConsultationPolicy` — المريض يرى ويلغي حجوزاته فقط.

---

## Sprint 4 — لوحة الإدارة · أسبوع

**القصص:** US-013، US-014، US-015، US-016، **US-025** (جديدة)

| المهمة | التفصيل |
|---|---|
| US-013 موافقة/رفض | **يشترط أن يكون الدفع `verified` أولاً**؛ الرفض يتطلب سبباً |
| US-015 المدة | `duration_minutes` اختياري في الموافقة يتجاوز مدة الخدمة، **مع إعادة فحص التعارض** |
| US-014 التحقق المالي | قائمة بفلاتر (حالة، تاريخ، بحث) + `verify` + `reject` |
| تحميل الإيصال | **بث الملف عبر كنترولر محمي بـ Policy** — وليس رابطاً عاماً |
| US-016 الجدول اليومي | مواعيد اليوم المعتمدة والمدفوعة |
| US-016 قائمة المرضى | بحث بالاسم/الهاتف + Pagination |
| US-025 إدارة المستخدمين | CRUD لحسابات الأطباء + seeder لأول مسؤول |
| إدارة الدوام | CRUD لجداول الدوام والاستثناءات |
| سجل التدقيق | تسجيل كل موافقة/رفض/تحقق دفع |

---

## Sprint 5 — بوابة الطبيب · أسبوع

**القصص:** US-017، US-018، US-019، US-020، **US-021b** (منقولة من Sprint 6)

| المهمة | التفصيل |
|---|---|
| US-017 جدول الطبيب | يومي وأسبوعي — **مقصور على `approved` + دفع `verified`** |
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
GET  /services              ?category_id= &q= &per_page=
GET  /services/{service}
GET  /doctors               ?service_id=
GET  /availability          ?doctor_id= &service_id= &date=
```

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

### المسؤول — `auth:sanctum` + `role:admin`
```
apiResource /admin/categories
apiResource /admin/services               POST /admin/services/{id}/toggle-active
GET  /admin/appointments                  ?status= &date= &doctor_id=
POST /admin/appointments/{id}/approve     { duration_minutes?, notes? }
POST /admin/appointments/{id}/reject      { reason }
GET  /admin/payments                      ?status= &from= &to= &q=
POST /admin/payments/{id}/verify          POST /admin/payments/{id}/reject
GET  /admin/payments/{id}/receipt         بث ملف محمي
GET  /admin/schedule/daily                ?date=
GET  /admin/patients                      ?q=       GET /admin/patients/{id}
apiResource /admin/doctors
apiResource /admin/doctor-schedules       + /schedule-exceptions
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
| **3** | طلبا حجز متوازيان لنفس الفترة → واحد ينجح والثاني 409 · انتهاء المهلة يحرّر الفترة |
| **4** | الموافقة ترفض إن لم يُتحقق من الدفع · تحميل إيصال بتوكن مريض → 403 |
| **5** | الطبيب لا يرى مواعيد غير مدفوعة · طبيب آخر يقرأ ملاحظة → 403 |
| **6** | E2E كامل + مصفوفة أدوار + `assertQueryCount` |

---

## الجدول الزمني

| المرحلة | المدة | المخرج |
|---|---|---|
| ~~المرحلة 0~~ | ~~3–4 أيام~~ | ✅ **مكتملة** |
| Sprint 3 | أسبوع | حجز + توفر + إيصالات + استشارات + إلغاء |
| Sprint 4 | أسبوع | لوحة إدارة + إدارة مستخدمين ودوام |
| Sprint 5 | أسبوع | بوابة الطبيب + ملاحظات + إشعارات |
| Sprint 6 | أسبوع | أمان + أداء + E2E + تسليم |

**المتبقي: 4 أسابيع**

---

## ملاحظات تشغيلية

1. **الفرع الحالي:** `feature/sprint2-remediation` — مفروع من `feature/dermatology-services` وليس `develop`، لأن `develop` متأخر ولا يحتوي كود Sprint 2.

2. **قبل الاستمرار:** شغّل WampServer ونفّذ `php artisan migrate` — الاختبارات تعمل على SQLite في الذاكرة، لكن migrations المرحلة 0 لم تُطبَّق بعد على قاعدة MySQL المحلية.

3. **ملفات مرجعية:**
   - `V2-REVIEW.md` — تقرير مراجعة Sprint 2 الأصلي
   - `USER-STORIES-CHANGES.md` — التعديلات المقترحة على القصص
