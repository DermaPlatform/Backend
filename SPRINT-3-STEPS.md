# خطوات تنفيذ Sprint 3 — دليل عمل يدوي

دليل تنفيذ خطوة بخطوة لسبرنت الحجز والدفع، مبني على `NADARA-PLAN.md`.
كل خطوة فيه مكتوبة لتُنفَّذ باليد، ولها بوابة قبول تُثبت أنها نجحت قبل الانتقال لما بعدها.

---

## الوضع الفعلي عند كتابة هذا الملف

تم التحقق من الشجرة، وهذه هي الحقائق لا التقديرات:

- المجلد `app/Services/` غير موجود إطلاقاً.
- المجلد `app/Policies/` غير موجود إطلاقاً.
- المجلد `app/Enums/` يحتوي ملفين فقط: `UserRole.php` و `Gender.php`.
- مجلد الفاكتوريز يحتوي ثلاثة ملفات فقط: `UserFactory` و `ServiceFactory` و `ServiceCategoryFactory`.
- آخر مايغريشن مطبَّق هو `2026_08_31_102121_add_image_path_to_users_table.php`.
- الموديلات الخمسة `Appointment` و `Payment` و `Consultation` و `ConsultationMessage` و `MedicalNote` ما زالت كما كُتبت قبل المرحلة 0: بلا `casts()` وبلا return types وبلا فاكتوريز.

**الخلاصة:** لم يُنفَّذ شيء من Sprint 3 بعد. نقطة البداية الصحيحة هي المرحلة 0.5، وهي نفسها بند 3.1 في الخطة، وليست `AvailabilityService`.

---

## قبل البدء

أنشئ فرع العمل من `main`، فقد دُمج فيه كل شيء عبر الطلب رقم 3، وهو الفرع المربوط بالسيرفر.

**انتبه:** الفرع `main` المحلي متأخر عن `origin/main` باثني عشر كوميتاً. اسحب أولاً وإلا بدأت من مشروع Laravel فارغ:

```bash
git checkout main
git pull origin main
git checkout -b feature/booking-and-payments
```

تأكّد قبل الاستمرار بأن `git log --oneline -3` يعرض الكوميت `ea1407a` في الأعلى.

**قاعدة الدمج:** بما أن السيرفر مربوط بـ `main`، لا تدمج هذا الفرع فيه إلا بعد اجتياز بوابة قبول السبرنت كاملة. الدفعة A2 تحذف العمود `requested_date` وتعيد بناء جداول، ووصولها إلى السيرفر نصف مكتملة يوقعه.

شغّل WampServer، ثم طبّق مايغريشنز المرحلة 0 على قاعدة MySQL المحلية، فهي لم تُطبَّق عليها بعد:

```bash
php artisan migrate
```

ثبّت خط الأساس قبل أي تعديل. الرقم المتوقع هو 134 اختباراً خضراء:

```bash
php artisan test --compact
```

### الروتين المتكرر بعد كل خطوة

هذه الأوامر الثلاثة تُنفَّذ بعد كل خطوة من خطوات هذا الملف، بلا استثناء:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact
git commit
```

---

## قرار واحد يجب حسمه قبل أول مايغريشن

الأعمدة `appointments.status` و `payments.status` و `users.role` معرَّفة حالياً في قاعدة البيانات كنوع `enum`.
توسيع أي منها يعني استدعاء `->change()` مع إعادة كتابة **كل** خصائص العمود بما فيها `->default()`، وإلا سقطت الخصائص المحذوفة. وهذا يتكرر مع كل حالة جديدة تُضاف مستقبلاً.

**التوصية:** حوّلها إلى نوع `string` مع cast إلى PHP Enum داخل الموديل.

مبررات التوصية:

1. الـ Enum في `app/Enums/` يصبح مصدر الحقيقة الوحيد للحالات.
2. لا حاجة لمايغريشن جديد عند إضافة أي حالة لاحقاً.
3. السلوك يصبح متطابقاً بين MySQL في بيئة التطوير و SQLite في الذاكرة التي تعمل عليها الاختبارات.
4. التطبيق سطر واحد داخل ميثود `casts()` في الموديل، مثال: `'status' => AppointmentStatus::class`.

بقية هذا الملف مكتوبة على أساس هذه التوصية. إن اخترت إبقاء الأعمدة من نوع `enum` في قاعدة البيانات، فالمتغيّر الوحيد هو محتوى مايغريشنات الدفعة A2.

---

# الدفعة أ — المرحلة 0.5 (يومان)

هذه الدفعة لا تضيف أي ميزة ولا تخدم أي قصة مستخدم. غرضها الوحيد أن يُبنى Sprint 3 فوق أرض صلبة.
سبب تنفيذها أولاً: لو بُني السبرنت قبلها، لكُتبت كل اختباراته على أسماء حالات خاطئة وعلى مبلغ نصّي، وإصلاحها بعد ذلك يعني إعادة كتابة السبرنت لا إصلاح الموديلات.

---

## A1 — الـ Enums

المرجع الوحيد في الأسلوب هو `app/Enums/UserRole.php`: مفاتيح بصيغة TitleCase، والنوع `string`.

الملفات المطلوب إنشاؤها في `app/Enums/`:

**الملف `AppointmentStatus.php`** — سبع حالات:
`pending_payment` · `awaiting_review` · `approved` · `completed` · `expired` · `rejected` · `cancelled`

**الملف `PaymentStatus.php`** — أربع حالات:
`pending` · `submitted` · `verified` · `rejected`

**الملف `ConsultationStatus.php`** — أربع حالات:
`pending_payment` · `awaiting_reply` · `replied` · `closed`

**الملف `SubscriptionStatus.php`** — ثلاث حالات:
`trial` · `active` · `expired`

الحالة `trial` تخص الدفعة المؤسِّسة من الأطباء، وهم يعملون مجاناً بلا سقف زمني حتى تصل المنصة إلى عدد مرضى نشط، فهي ليست نفسها `active`.

ولا توجد حالة باسم `grace`. مهلة السماح البالغة سبعة أيام تُحسب حسابياً من العمود `subscription_ends_at` وقت القراءة، ولا تُخزَّن. تخزينها يعني مهمة مجدولة تغيّرها كل منتصف ليل، وهي نقطة انكسار بلا مقابل. تُكتب كميثود على الموديل `User` في Sprint 4.

**تعديل على `UserRole.php`** — تُضاف حالة رابعة:

```php
case Secretary = 'secretary';
```

### ميثود مساعِدة تُكتب الآن لا لاحقاً

أضف داخل `AppointmentStatus` ميثود ترجع الحالات التي تشغل الفترة الزمنية فعلياً، على نمط `UserRole::selfRegisterable()`:

```php
/**
 * Statuses that occupy a calendar slot and must be subtracted from availability.
 *
 * @return array<int, self>
 */
public static function blocking(): array
{
    return [self::PendingPayment, self::AwaitingReview, self::Approved];
}
```

ستستدعيها في `AvailabilityService` بالخطوة B1، وكتابتها الآن تمنع تكرار المصفوفة في ثلاثة أماكن.

**بوابة القبول:** لا اختبار ينكسر، لأن هذه الملفات لم تُستعمل بعد.

---

## A2 — المايغريشنز

تُقسَّم إلى ثلاث دفعات، وكل دفعة كوميت مستقل. لا تدمجها في كوميت واحد، لأن أي تراجع لاحق سيصبح مستحيلاً.

### A2-أ — إعادة التموضع

رسالة الكوميت المقترحة: `feat(schema): reposition users and doctor pricing`

**المايغريشن `add_secretary_role_and_activation_to_users_table`** ويحتوي:

- تحويل العمود `role` إلى نوع `string` مع الإبقاء على القيمة الافتراضية `patient`.
- عمود `doctor_id` من نوع FK اختياري يشير إلى جدول `users` نفسه بسلوك `nullOnDelete`، وهو رابط السكرتير بطبيبه.
- عمود `is_active` من نوع boolean وقيمته الافتراضية `true`. هذا هو زر التعطيل الذي يحلّ محلّ الحذف.
- فهرس على العمود `name` وفهرس على العمود `phone`، وهما لازمان لبحث القصة US-016.

لاحظ أن الأعمدة الخاصة بالطبيب لم تعد هنا، بل انتقلت إلى جدوله الخاص في المايغريشن التالي.

**المايغريشن `create_doctor_profiles_table`** وهو ثلاث خطوات داخل ملف واحد وبهذا الترتيب:

الخطوة الأولى، إنشاء الجدول:

- العمود `user_id` وهو **المفتاح الأساسي** للجدول وفي الوقت نفسه FK على `users` بسلوك `cascadeOnDelete`. لا تضف عمود `id` منفصلاً. كون `user_id` مفتاحاً أساسياً يضمن وحده صفاً واحداً لكل طبيب، ويمنع الخلط بين رقم الحساب ورقم صفّ البروفايل.
- العمود `specialty` اختياري.
- العمود `accepts_appointments` من نوع boolean وقيمته الافتراضية `true`.
- العمود `accepts_online_consultations` من نوع boolean وقيمته الافتراضية `false`.
- العمود `subscription_status` من نوع string وقيمته الافتراضية `trial`.
- العمود `subscription_ends_at` من نوع datetime اختياري.

الخطوة الثانية، نقل البيانات القائمة قبل حذف مصدرها:

```php
DB::table('users')
    ->where('role', 'doctor')
    ->orderBy('id')
    ->each(function (object $doctor): void {
        DB::table('doctor_profiles')->insert([
            'user_id' => $doctor->id,
            'specialty' => $doctor->specialty,
        ]);
    });
```

الخطوة الثالثة، حذف العمود `specialty` من جدول `users`.

وميثود `down()` تعكس الخطوات الثلاث بترتيب مقلوب: تعيد العمود، ثم تنقل البيانات إليه، ثم تحذف الجدول.

**قاعدة حاكمة لا تخالفها:** كل المفاتيح الأخرى تبقى مؤشِّرة على `users.id` كما هي، أي `appointments.doctor_id` و `doctor_service.doctor_id` و `doctor_schedules.doctor_id`. جدول `doctor_profiles` امتداد للحساب لا كيان مستقل.

**المايغريشن `create_doctor_service_table`** ويحتوي:

- العمودان `doctor_id` و `service_id`، وكلاهما FK بسلوك `cascadeOnDelete`.
- العمود `price` من نوع `decimal(10,2)`.
- العمود `duration_minutes` من نوع integer.
- العمود `is_active` من نوع boolean.
- قيد فريد على الزوج `['doctor_id', 'service_id']`. بدونه يستطيع الطبيب تسعير الخدمة ذاتها مرتين بسعرين مختلفين.

**المايغريشن `make_services_price_nullable_and_add_duration`** ويحتوي:

- تحويل العمود `price` إلى اختياري. انتبه: يجب إعادة كتابة `decimal(10, 2)` كاملة داخل `->change()`، وإلا فُقدت دقة العمود.
- إضافة العمود `default_duration_minutes` وهو مدة مقترحة تُعرض على الطبيب لحظة التسعير فقط.

القاعدة الحاكمة التي تبررها هذه الدفعة: مصدر السعر أصبح `doctor_service` دائماً، وبقي `services.price` سعراً إرشادياً للكتالوج يُعرض بصيغة «يبدأ من»، ويُستخدم فقط حين لا يكون أي طبيب قد سعّر الخدمة بعد.

### A2-ب — آلات الحالة

رسالة الكوميت المقترحة: `feat(schema): expand appointment and payment lifecycles`

**المايغريشن `restructure_appointments_for_scheduling`** ويحتوي:

- حذف العمود `requested_date`، فتاريخ بلا وقت لا يخدم أي قصة مواعيد.
- إضافة العمودين `start_at` و `end_at` من نوع datetime.
- إضافة الأعمدة `hold_expires_at` و `approved_by` و `approved_at` و `rejection_reason` و `cancelled_at` و `cancellation_reason`.
- إضافة العمود `price` من نوع `decimal(10,2)`، وهو لقطة السعر لحظة الحجز.
- تحويل العمود `status` إلى نوع `string`.
- فهرس مركّب على `['doctor_id', 'start_at']`، وهو الفهرس الذي يعتمد عليه فحص التعارض، وفهرس آخر على `status`.

سبب لقطة السعر: الطبيب سيغيّر سعره لاحقاً، وبدون اللقطة يتغيّر سعر موعد قديم بأثر رجعي فينكسر تقرير الدخل في القصة US-027.

**المايغريشن `extend_payments_for_receipts`** ويحتوي:

- العمود `receipt_number` اختياري وفريد.
- الأعمدة `receipt_path` و `method` و `reference` و `verified_by` و `verified_at` و `rejection_reason` و `paid_at`.
- تحويل العمود `status` إلى نوع `string`.
- تحويل المفتاح `patient_id` إلى سلوك `restrictOnDelete`. الوضع الحالي `cascadeOnDelete`، ومعناه أن حذف مريض يمحو سجله المالي فيختفي الدخل من التقارير بأثر رجعي.

**المايغريشن `extend_consultations_for_symptoms`** ويحتوي:

- الأعمدة `symptoms` من نوع text، و `price`، و `submitted_at`، و `replied_at`.
- تحويل العمود `status` إلى نوع `string`.

### A2-ج — تسوية المفاتيح والجداول الجديدة

رسالة الكوميت المقترحة: `feat(schema): add scheduling tables and protect records`

**المايغريشن `harden_appointment_relations`**: تحويل `patient_id` إلى `restrictOnDelete`، وتحويل `doctor_id` إلى `restrictOnDelete` **وإلى عمود غير قابل لـ NULL**.

وهذان إصلاحان لعطلين حقيقيين لا احتياطيين:

الأول أن `patient_id` عليه `cascadeOnDelete` الآن، فحذف مريض واحد يمحو تاريخ مواعيده، ومعه لقطة السعر التي يُبنى عليها تقرير الدخل في القصة US-027، فيتغيّر دخل شهر مضى بأثر رجعي.

الثاني أن `doctor_id` يقبل NULL وعليه `nullOnDelete`، فحذف طبيب يُيتّم مواعيده بدل أن يُرفض. وهنا يظهر العطل الأخطر: الفلترة المعتمدة للسكرتير هي `where('doctor_id', auth()->user()->doctor_id)`، ولارافيل يحوّل `where` ذات القيمة الفارغة إلى `IS NULL` تلقائياً. فسكرتيرٌ فقد طبيبه تصبح قيمته فارغة، ويقرأ الاستعلام كل المواعيد اليتيمة لكل الأطباء المحذوفين. تسريب بيانات يمرّ بصمت لأنه لا يرمي أي خطأ.

**المايغريشن `harden_medical_notes_relations`**: تحويل `patient_id` و `doctor_id` إلى `restrictOnDelete`. الاحتفاظ بالسجل الطبي التزام مهني، ولا يجوز أن يمحوه حذف حساب.

**المايغريشن `harden_consultation_messages_sender`**: تحويل `sender_id` إلى `restrictOnDelete`. حذف طبيب اليوم يمحو رسائله من محادثة قائمة فتصير نصف حوار.

**المايغريشن `create_doctor_schedules_table`** ويحتوي: `doctor_id` و `weekday` بقيمة من صفر إلى ستة، و `starts_at` و `ends_at` من نوع time، و `slot_minutes`، و `is_active`.

**المايغريشن `create_schedule_exceptions_table`** ويحتوي: `doctor_id` و `date` و `starts_at` اختياري و `ends_at` اختياري و `reason`. الاصطلاح: حين يكون الوقتان فارغين فهذا يعني إجازة يوم كامل.

**المايغريشن `create_attachments_table`** ويحتوي علاقة polymorphic: `attachable_type` و `attachable_id` و `path` و `mime` و `size`. تخدم صور الحالة الجلدية في القصة US-012.

**المايغريشن `create_settings_table`** ويحتوي: `key` فريد و `value`.

**المايغريشن `create_audit_logs_table`** ويحتوي: `user_id` و `action` و `auditable_type` و `auditable_id` و `changes` من نوع json. يُنشأ الجدول هنا مع الدفعة، ويبدأ استعماله الفعلي في Sprint 4.

### تحذيران عمليان في هذه الدفعة

الأول: انسخ نمط الملف `2026_08_24_122959_harden_services_category_relation.php` حرفياً في مايغريشنات المفاتيح. فيه ميثود `down()` صحيحة، وفيه تعليق PHPDoc يشرح **لماذا** لا ماذا، وهو الأسلوب المعتمد في المشروع.

الثاني: على SQLite، استدعاء `dropForeign` يعيد بناء الجدول كاملاً. لذلك اكتب مايغريشنات المفاتيح بلا أي تعديل عمود داخل الـ closure نفسها، وإلا انكسرت الاختبارات وحدها دون أن تنكسر MySQL.

### بوابة قبول الدفعة A2

```bash
php artisan migrate:fresh --seed
php artisan test --compact
```

انتبه أن الأمر الأول يمسح بيانات قاعدة MySQL المحلية. والمتوقع بقاء 134 اختباراً خضراء، لأن هذه الدفعة لا تمسّ أي مسار مبني.

---

## A3 — تسوية ديون الموديلات الخمسة

الموديلات المقصودة: `Appointment` و `Payment` و `Consultation` و `ConsultationMessage` و `MedicalNote`.
كُتبت كلها قبل المرحلة 0، وبعدها ارتفع المعيار في `Service` و `User` وبقيت هي كما هي.

**المرجع الوحيد للأسلوب هو الملف `app/Models/Service.php`.** افتحه بجانبك وطبّق عليها ما فيه:

1. أضف تعليق `/** @use HasFactory<XFactory> */` فوق الـ trait، مع سطر `use Database\Factories\XFactory;` في الأعلى.
2. أضف return type لكل علاقة، مثل `: BelongsTo` و `: HasOne` و `: HasMany`، مع تعليق generics فوقها بصيغة `@return BelongsTo<User, $this>`.
3. أضف ميثود `casts()`، ولا تستخدم الخاصية `$casts`.
4. وسّع `$fillable` ليشمل الأعمدة الجديدة المضافة في الدفعة A2.

### تفصيل الـ casts المطلوبة

في الموديل `Payment`:

- `'amount' => 'decimal:2'` — العمود في الجدول `decimal(10,2)` والموديل بلا `casts()` إطلاقاً، فالقيمة تعود نصاً مثل `"150.00"` لا رقماً.
- `'status' => PaymentStatus::class`
- `'verified_at' => 'datetime'` و `'paid_at' => 'datetime'`

في الموديل `Appointment`:

- `'status' => AppointmentStatus::class`
- `'price' => 'decimal:2'`
- تحويل `datetime` للأعمدة: `start_at` و `end_at` و `hold_expires_at` و `approved_at` و `cancelled_at`

في الموديل `Consultation`:

- `'status' => ConsultationStatus::class`
- `'price' => 'decimal:2'`
- تحويل `datetime` للعمودين `submitted_at` و `replied_at`

### الإصلاح الخاص بالموديل ConsultationMessage

عليه حالياً السطر `public $timestamps = false;` بينما الجدول يحتوي عموداً اسمه `created_at`. النتيجة أن العمود لا يُكاست أبداً فيعود نصاً لا كائن `Carbon`.

**الحل:** احذف ذلك السطر واستبدله بـ:

```php
const UPDATED_AT = null;
```

بهذا تبقى `created_at` عاملة ومُكاستة، ويختفي `updated_at` وهو المطلوب أصلاً في جدول رسائل.

### بوابة قبول الخطوة A3

```bash
php artisan tinker --execute 'dump(Payment::first()?->amount, ConsultationMessage::first()?->created_at);'
```

المتوقع: القيمة الأولى رقم، والثانية كائن `Carbon`.

---

## A3-ب — الموديل DoctorProfile والمواضع الأربعة

```bash
php artisan make:model DoctorProfile --no-interaction
```

في الموديل `DoctorProfile`:

- الخاصية `protected $primaryKey = 'user_id';`
- الخاصية `public $incrementing = false;` لأن المفتاح ليس رقماً متسلسلاً بل رقم حساب موجود.
- علاقة `user()` من نوع `BelongsTo`.
- ميثود `casts()` تحوّل `subscription_status` إلى `SubscriptionStatus::class`، و `subscription_ends_at` إلى `datetime`، والعمودين المنطقيين إلى `boolean`.

في الموديل `User`:

- علاقة `doctorProfile()` من نوع `HasOne` على المفتاح `user_id`.
- إضافة `'is_active' => 'boolean'` إلى ميثود `casts()`.
- حذف `specialty` من `$fillable`.

### المواضع الأربعة التي تكسر بنقل specialty

هذه هي كل المواضع التي تذكر `specialty` في المشروع، وقد جرى إحصاؤها. عدّلها كلها في هذه الخطوة:

1. في `app/Http/Resources/Api/V1/UserResource.php` عند السطر 49، تُقرأ القيمة من البروفايل: `$this->doctorProfile?->specialty`.
2. في `database/factories/UserFactory.php`، تحذف مفتاح `specialty` من الحالات الثلاث، وتُنشئ حالة `doctor()` صفَّ البروفايل بعد إنشاء المستخدم عبر `afterCreating`.
3. في `database/seeders/DatabaseSeeder.php` عند السطر 28، يُنشأ البروفايل مع الطبيب.
4. في `tests/Feature/Auth/TokenAuthenticationTest.php`، يصير المرجع `$user->doctorProfile->specialty`.

**بوابة القبول:** يبقى عدد الاختبارات 134 وكلها خضراء. لا تحذف اختباراً ولا تضيف واحداً في هذه الخطوة — أنت تصلح مرجعاً تغيّر مكانه لا أكثر.

---

## A4 — الفاكتوريز

بدون هذه الخطوة لا يمكن كتابة اختبار واحد لسبرنت 3. الموديل `Appointment` عليه `use HasFactory` بينما `AppointmentFactory` غير موجود، فاستدعاء `Appointment::factory()` يرمي استثناء الآن.

```bash
php artisan make:factory AppointmentFactory --model=Appointment --no-interaction
php artisan make:factory PaymentFactory --model=Payment --no-interaction
php artisan make:factory ConsultationFactory --model=Consultation --no-interaction
php artisan make:factory ConsultationMessageFactory --model=ConsultationMessage --no-interaction
php artisan make:factory MedicalNoteFactory --model=MedicalNote --no-interaction
php artisan make:factory DoctorScheduleFactory --model=DoctorSchedule --no-interaction
php artisan make:factory DoctorProfileFactory --model=DoctorProfile --no-interaction
```

انسخ نمط `ServiceFactory` في التسمية والبنية.

### الحالات المطلوبة داخل AppointmentFactory

اكتبها الآن لا لاحقاً، فكل اختبارات الدفعة ب ستستدعيها:

- `pendingPayment()` — مع ضبط `hold_expires_at` في المستقبل.
- `awaitingReview()`
- `approved()`
- `expired()` — مع ضبط `hold_expires_at` في الماضي، وهي الحالة التي تختبر بها المهمة المجدولة.
- `forDoctor(User $doctor)`

### بوابة قبول المرحلة 0.5 كاملة

```bash
php artisan tinker --execute 'Appointment::factory()->create();'
php artisan test --compact
```

يُضاف إليهما فحص يدوي واحد: احذف مستخدماً له دفعة مسجَّلة، والمتوقع أن يُرفض الحذف لا أن يُمحى السجل.

---

## A5 — تحديث السيدر

في الملف `CatalogSeeder`، أضف:

- طبيبين اثنين على الأقل.
- ربطهما بخدمات عبر جدول `doctor_service`، **بسعرين مختلفين لنفس الخدمة**. هذا الاختلاف تحديداً هو ما يجعلك ترى صيغة «يبدأ من» تعمل فعلاً في الكتالوج.
- دواماً أسبوعياً لكل طبيب في `doctor_schedules`، وإلا ستعيد `AvailabilityService` قائمة فارغة دائماً في الخطوة B1 فتظن أن فيها عطلاً.
- صفَّ بروفايل لكل طبيب. طبيبٌ بلا بروفايل يسقط من الكتالوج بصمت، وستضيع ساعة في البحث عن السبب.
- طبيباً واحداً بـ `is_active = false` وآخر باشتراك منتهٍ، لترى بعينك أن كليهما اختفى من الكتالوج.

---

# الدفعة ب — قلب السبرنت (ثلاثة إلى أربعة أيام)

بهذا الترتيب بالضبط. كل بند كوميت مستقل، ولا يُفتح البند التالي قبل أن تخضرّ اختبارات سابقه.

---

## B1 — الخدمة AvailabilityService

الملف: `app/Services/AvailabilityService.php`

**ابنِها باختبار Unit أولاً.** هي منطق حسابي صرف بلا HTTP، واختبارها من خلال الراوتس لاحقاً يجعل تشخيص أي خطأ فيها أصعب بكثير.

الترتيب المنطقي داخلها، وهو ترتيب مُلزِم:

1. توليد الفترات من `doctor_schedules` بخطوة مقدارها `slot_minutes`.
2. طرح استثناءات الدوام من `schedule_exceptions`، أي الإجازات.
3. طرح المواعيد المشغولة، وهي المواعيد التي حالتها ضمن `AppointmentStatus::blocking()`، بشرط إضافي على الحالة `pending_payment` وحدها: أن تكون `hold_expires_at` لم تنقضِ بعد. بدون هذا الشرط تبقى الفترة محجوزة إلى الأبد بسبب حجز لم يُدفع.
4. طرح الفترات الواقعة في الماضي.

المخرَج: مصفوفة عناصرها بالشكل `{ start_at, end_at, available }`.

---

## B2 — الخدمة BookingService

الملف: `app/Services/BookingService.php`

الشروط التي تتحقق منها قبل إنشاء أي حجز:

- أن الطبيب يقدّم هذه الخدمة فعلاً، أي أن السطر موجود في `doctor_service`.
- أن `accepts_appointments` تساوي `true` عند هذا الطبيب.
- أن اشتراك الطبيب ما زال سارياً.

القواعد الحاكمة لإنشاء الحجز:

- تُحسب `end_at` بجمع `start_at` مع `doctor_service.duration_minutes`. **المدة من الطبيب لا من الخدمة**، وقيمة `services.default_duration_minutes` قيمة مقترحة وقت التسعير فقط.
- تُكتب `appointments.price` نسخاً عن `doctor_service.price` مرة واحدة، ولا تُقرأ من جدول التسعير بعد ذلك أبداً.
- تُضبط `hold_expires_at` على `now()` مضافاً إليها `config('nadara.booking_hold_minutes')`.

**النقطة الأخطر في السبرنت كله:** فحص التعارض يجب أن يجري داخل `DB::transaction` مع `lockForUpdate()` على مواعيد الطبيب في ذلك اليوم. بدون القفل يمرّ طلبان متزامنان من الفحص معاً وينجح كلاهما في حجز الفترة ذاتها.

عند وقوع تعارض: ارمِ استثناءً مخصصاً يُترجَم إلى استجابة 409، لا 422.

---

## B3 — الـ Policies

المجلد `app/Policies/` غير موجود في المشروع، ويُنشأ هنا لا في Sprint 6. بأربعة أدوار صارت الـ Policies ضرورة لا اختياراً، ومصفوفة الأدوار في القصة US-022 تصبح أربعة في N.

المطلوب: `AppointmentPolicy` و `ConsultationPolicy`، وقاعدتهما أن المريض يرى ويلغي حجوزاته هو فقط.

**اكتبها قبل الكنترولرز لا بعدها.** كتابتها بعد الكنترولرز تعني مراجعة كل ميثود مرة ثانية لإضافة الاستدعاء.

---

## B4 — كنترولر الحجز

الأنماط الجاهزة التي تُنسخ منها:

- بنية الكنترولر من `app/Http/Controllers/Api/V1/AuthController.php`.
- بنية الـ Resource من `app/Http/Resources/Api/V1/UserResource.php`.
- حساب حجم الصفحة من ميثود `perPage()` في `app/Http/Controllers/Controller.php`.
- تسمية الراوتس على نمط الموجود في `routes/api.php`.

اكتب FormRequest مستقلاً لكل مدخل، ولا تتحقق داخل الكنترولر.

---

## B5 — رفع الإيصال

الراوت: `POST /appointments/{id}/payment-receipt` بصيغة multipart.

قواعد التحقق: `mimes:jpg,jpeg,png,pdf` مع `max:4096`.

**التخزين على قرص `local` لا `public`.** القرص `local` يشير إلى `storage/app/private` وهو غير قابل للوصول العام، وهو مضبوط مسبقاً في `config/filesystems.php`. الإيصال مستند مالي ولا يجوز أن يكون على رابط عام يُخمَّن.

توليد `receipt_number` بالصيغة `NDR-{YEAR}-{seq}`. ولّده **داخل نفس الـ transaction** التي تحدّث الدفعة، وإلا أنتج طلبان متزامنان الرقم ذاته وسقط القيد الفريد في وجه المستخدم.

الانتقالات التي تحدث في هذه العملية:

- حالة الدفع تصبح `submitted`.
- حالة الموعد تصبح `awaiting_review`.
- العمود `hold_expires_at` يُمسح، فالفترة لم تعد مؤقتة.

---

## B6 — الاستشارات

الراوت: `POST /consultations` مع الحقل `symptoms` ومرفقات صور.

**شرط مسبق:** أن يكون `accepts_online_consultations` مساوياً `true` عند الطبيب المقصود، وإلا فالرد 422. بعض الأطباء لا يرتاحون للتشخيص عن بعد، وإجبارهم يخسّر أطباء.

الحالة تصبح `awaiting_reply` **فقط بعد تحقق المسؤول من الدفع**، لا فور الإرسال.

---

## B7 — إلغاء الحجز

النافذة الزمنية تُقرأ من `config('nadara.cancellation_window_hours')` وقيمتها الافتراضية 24 ساعة.

عند الإلغاء: تُحرَّر الفترة فوراً، ويُعلَّم الحجز بـ `refund_required` بدون أي منطق استرجاع فعلي في هذا السبرنت.

---

## B8 — المهمة المجدولة

المهمة `ReleaseExpiredHolds` تعمل كل خمس دقائق وتحرّر الحجوزات التي انقضت مهلتها.

```bash
php artisan make:command ReleaseExpiredHolds --no-interaction
```

تُسجَّل في الملف `routes/console.php`.

---

# بوابة القبول النهائية لسبرنت 3

الاختبارات الثلاثة التي تفصل بين «انتهى» و«يبدو أنه انتهى»:

1. طلبا حجز متوازيان على الفترة ذاتها: واحد يعيد 201 والآخر يعيد 409. هذا هو اختبار القفل في B2.
2. انقضاء المهلة يحرّر الفترة فعلاً وتعود متاحة في `AvailabilityService`.
3. تعديل الطبيب لسعره **لا** يغيّر قيمة `appointments.price` في موعد قديم. هذا هو اختبار لقطة السعر.

يُضاف إليها الروتين العام بعد كل مرحلة:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan route:list --path=v1
php artisan migrate:fresh --seed
```

---

# أنماط جاهزة في المشروع انسخ منها

- بنية الـ Enum: الملف `app/Enums/UserRole.php`
- ميدلوير الأدوار: الملف `app/Http/Middleware/EnsureRole.php`
- تسجيل الميدلوير: الملف `bootstrap/app.php`
- الـ Rate Limiter: الملف `app/Providers/AppServiceProvider.php` عند السطر 70
- نمط الكنترولر: الملف `app/Http/Controllers/Api/V1/AuthController.php`
- نمط الـ Resource: الملف `app/Http/Resources/Api/V1/UserResource.php`
- حساب حجم الصفحة: ميثود `perPage()` في `app/Http/Controllers/Controller.php`
- تسمية الراوتس: الملف `routes/api.php`
- معيار الموديل: الملف `app/Models/Service.php`
- نمط مايغريشن المفاتيح: الملف `database/migrations/2026_08_24_122959_harden_services_category_relation.php`
- القرص الخاص: التعريف `local` في `config/filesystems.php` ويشير إلى `storage/app/private`
- بنية الاختبارات: المجلد `tests/Feature/`

---

# ملاحظات تشغيلية

**قاعدتان مختلفتان:** بيئة التطوير على MySQL بينما الاختبارات تعمل على SQLite في الذاكرة حسب `phpunit.xml`. أي مايغريشن يمرّ على إحداهما قد يسقط في الأخرى، لذلك شغّل `php artisan migrate` و `php artisan test` معاً بعد كل دفعة مايغريشنز لا واحداً منهما.

**سيرفر Laravel Boost:** فشل اتصاله بجلسة اليوم بخطأ `CONNECT_TIMEOUT`، فأداتا `search-docs` و `database-query` غير متاحتين. إن احتجتهما أعد تشغيل الجلسة.

**حالة الفروع:** الفرع `develop` ما زال متأخراً ولا يحتوي كود Sprint 2. الفرع `docs/platform-repositioning` مبني فوق `feature/dermatology-services` ويحتوي الخطة المحدَّثة، ولذلك هو الأساس الصحيح لفرع هذا السبرنت.
