# تقرير مراجعة مجلد `Api/V2` — Sprint 2

**الفرع:** `feature/dermatology-services`
**آخر كوميت:** `c57f3b2` — First Implement Sprint 2: V2 API, Catalog, Profile management, and Admin services/categories with soft deletes
**تاريخ المراجعة:** 2026-08-23
**نطاق المراجعة:** `app/Http/Controllers/Api/V2/` وما يرتبط فيه (Routes, Form Requests, Resources, Models, Middleware, Migrations)

> **ملاحظة:** هذه مراجعة قراءة فقط. لم يتم تعديل أي ملف.

---

## الخلاصة التنفيذية

الفلو فيه أخطاء **تكسر التشغيل فعلياً**، مش مجرد ملاحظات تنظيمية. عملياً معظم endpoints الأدمن في V2 بترجع خطأ 500، وفي خطر حقيقي لفقدان بيانات عند حذف تصنيف.

| التصنيف | العدد |
|---|---|
| 🔴 أخطاء قاتلة (تكسر التشغيل / تفقد بيانات) | 5 |
| 🟠 مشاكل في تصميم الفلو | 7 |
| ✅ أشياء مبنية صح | 4 |

**قرار نقل V2 إلى داخل V1: صحيح** — التفاصيل في آخر القسم.

---

## 🔴 أخطاء قاتلة

### 1. `AdminCategoryController` فاضي بالكامل مع راوتس مسجّلة عليه

الملف `app/Http/Controllers/Api/V2/AdminCategoryController.php:10` ما فيه غير `//`.

لكن `routes/api.php:54` مسجّل عليه:

```php
Route::apiResource('categories', AdminCategoryController::class);
```

وهاد بيولّد 5 راوتس شغّالة فعلاً — مؤكّدة من `php artisan route:list --path=v2`:

```
GET|HEAD  api/v2/admin/categories             → categories.index   › AdminCategoryController@index
POST      api/v2/admin/categories             → categories.store   › AdminCategoryController@store
GET|HEAD  api/v2/admin/categories/{category}  → categories.show    › AdminCategoryController@show
PUT|PATCH api/v2/admin/categories/{category}  → categories.update  › AdminCategoryController@update
DELETE    api/v2/admin/categories/{category}  → categories.destroy › AdminCategoryController@destroy
```

**الأثر:** كل واحدة من الخمسة بترمي `BadMethodCallException` → **500**. يعني **US-007 (إدارة التصنيفات) مش شغّال إطلاقاً**.

---

### 2. Namespace خاطئ في `StoreServiceRequest` — خطأ مخفي

الملف موجود بمسار `app/Http/Requests/Api/V2/StoreServiceRequest.php`، لكن الـ namespace المكتوب جوّاه:

```php
namespace App\Http\Requests;   // ❌ ناقص \Api\V2
```

تم التأكيد عملياً:

```
class_exists("App\Http\Requests\Api\V2\StoreServiceRequest")  →  false
class_exists("App\Http\Requests\StoreServiceRequest")         →  true
```

و `AdminServiceController.php:7` بيستورد المسار الصحيح `App\Http\Requests\Api\V2\StoreServiceRequest`.

**الأثر:** `POST /api/v2/admin/services` بيرمي `Class "…StoreServiceRequest" not found` → **500**.

هاي أخطر واحدة من ناحية الاكتشاف، لأنها ما بتبيّن بالمراجعة السريعة ولا بتوقف عند التحميل — بس وقت استدعاء الـ endpoint.

---

### 3. `StoreCategoryRequest` مولّد من artisan وما انعبّى

في `app/Http/Requests/Api/V2/StoreCategoryRequest.php`:

```php
public function authorize(): bool
{
    return false;   // ❌ القيمة الافتراضية من artisan
}

public function rules(): array
{
    return [
        //          // ❌ فاضية
    ];
}
```

**الأثر:** حتى بعد ما تكتب `store()` في `AdminCategoryController`، الطلب رح يرجع **403 دايماً** قبل ما يوصل للكنترولر. وحتى لو صلّحت `authorize()`، ما في أي تحقق من المدخلات.

للمقارنة: `UpdateCategoryRequest` معبّاية صح (`authorize` = `true` + قاعدة `unique`).

---

### 4. ⚠️ Soft Delete التصنيفات مكسور + خطر فقدان بيانات

سلسلة الخطأ:

1. migration `2026_08_22_202039_add_deleted_at_to_service_categories_table.php` بتضيف عمود `deleted_at` لجدول `service_categories`
2. لكن موديل `app/Models/ServiceCategory.php` **ما فيه `use SoftDeletes;`** — بعكس `app/Models/Service.php` اللي فيه
3. و migration الخدمات معرّفة العلاقة:

```php
$table->foreignId('category_id')
    ->constrained('service_categories')
    ->cascadeOnDelete();      // ⚠️
```

**النتيجة عند استدعاء `AdminServiceController.php:42`:**

```php
$category->delete();   // حذف نهائي (hard delete) — مش soft
```

- التصنيف بينمسح نهائياً من قاعدة البيانات
- و `cascadeOnDelete` بيمسح **كل خدمات هذا التصنيف نهائياً**، بما فيها الخدمات اللي كانت محذوفة soft — يعني الـ soft delete اللي على `Service` بينلتف عليه بالكامل
- **لا يوجد أي طريقة للاسترجاع**

وزيادة على هيك، الرد في `AdminServiceController.php:43-50`:

```php
'deleted_at' => $category->deleted_at,   // بترجع null دايماً
```

بتوهم المستخدم إن العملية نجحت كـ soft delete.

> هاي البند الوحيد اللي فيه فقدان بيانات فعلي — أولوية قصوى.

---

### 5. كود ميت — منطق التصنيفات مكتوب في كنترولر غير مربوط

`AdminServiceController.php:26-51` فيه ثلاث ميثودز كاملة:

- `storeCategory(StoreCategoryRequest $request)`
- `updateCategory(UpdateCategoryRequest $request, ServiceCategory $category)`
- `destroyCategory(ServiceCategory $category)`

**ولا واحدة فيهم مربوطة بأي راوت.** الراوتس رايحة على `AdminCategoryController` الفاضي.

يعني المنطق مكتوب بمكان والراوتس مؤشّرة على مكان تاني — وهاد اللي بيفسّر ليش بند (1) و(3) صارت.

---

## 🟠 مشاكل في تصميم الفلو

### 6. تكرار حرفي بين Catalog و Admin

`CatalogController@index` و `AdminServiceController@categories` نفس الكود بالضبط:

```php
$categories = ServiceCategory::with('services')->get();
return ServiceCategoryResource::collection($categories);
```

وكمان اسم الراوت `/v2/admin/admin-services-categories` غير متسق مع بقية التسمية، ومكرّر لـ `/v2/categories` العام. الأدمن يقدر يستدعي الـ endpoint العام مباشرة.

---

### 7. `phone` بدون قاعدة `unique` في `UpdateProfileRequest`

في migration الـ users:

```php
$table->string('phone')->nullable()->unique();   // فريد على مستوى قاعدة البيانات
```

لكن في `UpdateProfileRequest`:

```php
'phone' => ['sometimes', 'nullable', 'string', 'max:20'],   // ❌ ما في unique
```

**الأثر:** لو مستخدم حدّث رقمه لرقم موجود عند مستخدم تاني → `QueryException` (Integrity constraint violation) → **500** بدل استجابة **422** نظيفة.

**المطلوب:**

```php
'phone' => ['sometimes', 'nullable', 'string', 'max:20',
    Rule::unique('users', 'phone')->ignore($this->user()->id)],
```

---

### 8. لا يوجد Pagination

`CatalogController@index` و `CatalogController@services` و `AdminServiceController@categories` كلهم بيستخدموا `->get()` — بيرجّعوا الجدول كامل في كل طلب. ومع `with('services')` بيرجع كل الخدمات المتداخلة كمان.

**المطلوب:** `->paginate()` مع حجم صفحة معقول.

---

### 9. `ProfileController` ما بيستخدم API Resource

الميثودين `index()` و `update()` بيبنوا مصفوفة JSON يدوياً (`ProfileController.php:15-23` و `:31-40`)، رغم إن `App\Http\Resources\Api\V1\UserResource` موجود وجاهز.

النتائج:

- نفس المصفوفة مكتوبة مرتين في نفس الملف
- عدم اتساق مع V1: `UserResource` بترجّع `role?->value` و `email_verified` و `created_at`، بينما `ProfileController` بترجّع حقول مختلفة
- أي تغيير مستقبلي على شكل بيانات المستخدم لازم ينعمل في مكانين

---

### 10. عدم اتساق قاعدة `unique` بين Store و Update

| الملف | القاعدة على `name` |
|---|---|
| `StoreServiceRequest` | `['required','string','max:255']` — بدون `unique` |
| `UpdateServiceRequest` | `[…, 'unique:services,name,'.$serviceId]` |

**الأثر:** تقدر تنشئ خدمتين بنفس الاسم، وبعدين ما تقدر تعدّل على ولا وحدة منهم.

وكمان: قاعدة `unique` ما بتستثني السجلات المحذوفة soft، فاسم أي خدمة محذوفة بيضل محجوز للأبد. الحل باستخدام `Rule::unique(...)->whereNull('deleted_at')`.

**ملاحظة إضافية:** ما في unique index على `services.name` ولا `service_categories.name` في الـ migrations — التحقق على مستوى التطبيق بس.

---

### 11. راوتس V2 بدون أسماء

V1 منظّمة:

```php
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->name('login');
```

V2 بدون أي `->name()` (ما عدا الأسماء اللي بيولّدها `apiResource` تلقائياً، وهي `categories.*` بدون البادئة `api.v2.` — يعني عرضة للتصادم مع أي راوت مستقبلي بنفس الاسم).

**الأثر:** ما بتقدر تستخدم `route()` helper، والاختبارات بتضطر تكتب الـ URL نصياً.

---

### 12. صفر اختبارات وصفر Factories لـ V2

- `tests/Feature/Auth/` فيها 5 ملفات لـ V1 (`LoginTest`, `RegistrationTest`, `PasswordResetTest`, `TokenAuthenticationTest`, `ApiErrorFormatTest`)
- **ما في ولا اختبار** للكتالوج أو البروفايل أو إدارة الأدمن
- `database/factories/` فيها `UserFactory.php` بس — **ما في `ServiceFactory` ولا `ServiceCategoryFactory`**، فما تقدر تكتب اختبارات أصلاً
- `database/seeders/` ما فيها بيانات للكتالوج

> ملاحظة: وجود اختبارات كان رح يكشف البنود 1 و2 و3 فوراً.

---

## ✅ الأشياء المبنية صح

| البند | الملف |
|---|---|
| منطق `EnsureAdmin` سليم — 401 للضيف، 403 لغير الأدمن، ومعالجة صحيحة لـ enum ولـ string | `app/Http/Middleware/EnsureAdmin.php` |
| تسجيل الميدلوير بالطريقة الحديثة لـ Laravel 12 (`$middleware->alias()` في `bootstrap/app.php`) وليس `Http/Kernel.php` | `bootstrap/app.php:24` |
| ترتيب الميدلوير `['auth:sanctum', 'admin']` صحيح — المصادقة قبل التحقق من الدور | `routes/api.php:51` |
| Eager loading سليم (`with('services')` / `with('category')`) و `whenLoaded()` في الـ Resources — بيمنع N+1 وبيمنع التداخل اللانهائي بين `ServiceResource` و `ServiceCategoryResource` | `app/Http/Resources/Api/V2/` |

كمان Route Model Binding مستخدم بشكل صحيح، و`SoftDeletes` على `Service` بيخلي `GET /v2/services/{service}` يرجّع 404 للخدمة المحذوفة تلقائياً.

---

## بخصوص نقل V2 إلى داخل V1

**القرار صحيح.** كل هذا الكود جزء من نفس الـ API الأولى، مش نسخة ثانية منها. الـ API versioning بينعمل لما تكسر عقد قائم مع عملاء موجودين، مش لما تضيف موارد جديدة (كتالوج، بروفايل، إدارة).

### نطاق النقل

**11 ملف** بدها تغيير namespace:

```
app/Http/Controllers/Api/V2/  →  V1/     (4 ملفات)
    AdminCategoryController.php
    AdminServiceController.php
    CatalogController.php
    ProfileController.php

app/Http/Requests/Api/V2/     →  V1/     (5 ملفات)
    StoreCategoryRequest.php
    StoreServiceRequest.php      ← بيتصلح namespace-ها تلقائياً بنفس الحركة
    UpdateCategoryRequest.php
    UpdateProfileRequest.php
    UpdateServiceRequest.php

app/Http/Resources/Api/V2/    →  V1/     (2 ملف)
    ServiceCategoryResource.php
    ServiceResource.php
```

بالإضافة لـ `routes/api.php`: دمج بلوك `v2` داخل مجموعة `v1` الموجودة، مع إضافة `->name()` لكل راوت حسب نمط V1.

### مكاسب جانبية من النقل

- **البند 2 بينحلّ تلقائياً** — تغيير الـ namespace بيصلّح `StoreServiceRequest`
- فرصة طبيعية لدمج `AdminServiceController` و `AdminCategoryController` بمنطق موحّد بدل الازدواجية الحالية (البند 5)
- فرصة لحذف `/admin/admin-services-categories` المكرّر (البند 6)
- **لا يوجد breaking change على أي عميل** — V2 عملياً معطّل، معظم endpoints الأدمن بترجع 500

### الترتيب المقترح للتنفيذ

| # | الخطوة | السبب |
|---|---|---|
| 1 | إضافة `use SoftDeletes;` لموديل `ServiceCategory` (البند 4) | خطر فقدان بيانات فعلي — أولوية قصوى |
| 2 | تصليح namespace في `StoreServiceRequest` (البند 2) | سطر واحد، بيفكّ endpoint معطّل |
| 3 | إضافة `ServiceFactory` و `ServiceCategoryFactory` (البند 12) | شرط مسبق لكتابة أي اختبار |
| 4 | نقل V2 → V1 مع إضافة `->name()` (البنود 11 + النقل) | إعادة تنظيم شاملة |
| 5 | كتابة `AdminCategoryController` بشكل كامل + تعبئة `StoreCategoryRequest` وحذف الكود الميت (البنود 1، 3، 5) | تطبيق US-007 |
| 6 | تصليح `phone` unique، الـ pagination، استخدام `UserResource`، توحيد قواعد `unique` (البنود 7، 8، 9، 10) | تشذيب |
| 7 | كتابة Feature tests للكتالوج والبروفايل والأدمن (البند 12) | ضمان عدم التراجع |

---

*تم إعداد التقرير بمراجعة يدوية للملفات مع التحقق العملي عبر `php artisan route:list` و `class_exists()`.*
