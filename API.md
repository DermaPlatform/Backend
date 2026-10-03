# NADARA API (v1)

مرجع تكامل الفرونت إند. كل الأمثلة مأخوذة من السلوك الفعلي للـ backend ومغطّاة باختبارات.

الأقسام من 1 إلى 11 تغطّي المصادقة. **القسم 12 يغطّي الحجز والدفع والاستشارات (سبرنت 3).**

> **تحديث:** تسجيل الدخول انفصل لثلاث بوابات، باب لكل واجهة. شوف §2.1 قبل ما تربط أي شاشة دخول.

---

## 1. أساسيات

| | |
|---|---|
| **Base URL (تطوير)** | `http://localhost:8000/api/v1` |
| **نوع المصادقة** | Sanctum Bearer Token |
| **صيغة البيانات** | JSON |
| **الإصدار** | داخل المسار (`/api/v1/...`) |

### الهيدرز

على كل الطلبات:

```
Accept: application/json
Content-Type: application/json
```

على الطلبات المحمية فقط:

```
Authorization: Bearer <token>
```

> `Accept: application/json` مهم دائماً — الباك إند بيرجّع JSON على `/api/*` حتى بدونه، بس بعثه بيمنع أي سلوك غير متوقع.

---

## 2. نموذج المصادقة

النظام **stateless** بالكامل: ما في كوكيز، ما في session، ما في CSRF، وما في حاجة تستدعي `/sanctum/csrf-cookie`.

```
register أو login  →  بيرجّعوا token
                   ↓
        خزّن الـ token عند العميل
                   ↓
   كل طلب لاحق: Authorization: Bearer <token>
                   ↓
        logout  →  التوكن بيتلغى فوراً
```

**نقاط مهمة:**

- التوكن بيظهر **مرة وحدة بس** في رد `register` / `login`. ما في endpoint لاسترجاعه لاحقاً.
- التوكن ما بينتهي افتراضياً (`expires_at: null`). لو انضبط `SANCTUM_EXPIRATION` بالـ `.env`، بيرجع تاريخ ISO 8601.
- كل جهاز بياخد توكن مستقل عبر `device_name`. تسجيل الخروج من جهاز ما بيأثر على الباقي.
- **بعد إعادة تعيين كلمة السر بتتلغى كل التوكنات** — المستخدم لازم يسجّل دخول من جديد على كل أجهزته.

### 2.1 بوابات الدخول

الدور بيجاوب "مين هذا الشخص"، والبوابة بتجاوب "من أي واجهة فتحت الجلسة". الاثنين منفصلين عمداً.

عنا **أربع أدوار** و**ثلاث بوابات**، لأن الطبيب وسكرتيره بيتشاركوا لوحة العيادة نفسها:

| البوابة | الأدوار | مسار الدخول |
|---|---|---|
| `patient` | `patient` | `POST /api/v1/auth/login` |
| `clinic` | `doctor` · `secretary` | `POST /api/v1/clinic/auth/login` |
| `admin` | `admin` | `POST /api/v1/admin/auth/login` |

**كل توكن بينختم ببوابته** (Sanctum ability بصيغة `portal:<name>`)، فجلسة انفتحت من تطبيق المرضى ما بتشتغل على الباك أوفيس حتى لو تسرّب التوكن.

```
POST /auth/login          →  token عليه ability: portal:patient
POST /clinic/auth/login   →  token عليه ability: portal:clinic
POST /admin/auth/login    →  token عليه ability: portal:admin
```

**اللي بيفرق مع البوابة:** `login` و `forgot-password` بس.
**اللي مشترك للكل:** `me` و `logout` و `logout-all` و `reset-password` — نقطة واحدة بتشتغل مع أي توكن.

> ⚠️ **التسجيل الذاتي على بوابة المرضى وحدها.** الطبيب والسكرتير والأدمن حساباتهم بتتعمل إلهم، ما في `register` على بوابتهم.

**اللي لازم يعمله الفرونت:** خزّن مفتاح البوابة جنب التوكن، وخلّي كل واجهة تضرب باب بوابتها هي. لو بعتّ توكن بوابة على مسار بوابة تانية بيرجع `403` (§3).

---

### 2.2 وين نخزّن التوكن؟

| الواجهة | التخزين |
|---|---|
| موبايل (React Native / Flutter) | Secure Storage / Keychain |
| ويب SPA | `localStorage` مقبول، بس `sessionStorage` أأمن ضد XSS المستمر |

---

## 3. صيغة الأخطاء الموحّدة

كل الأخطاء بترجع بنفس الشكل، فاعمل معالج واحد مركزي.

### 422 — خطأ تحقق

```json
{
  "message": "The email field is required. (and 2 more errors)",
  "errors": {
    "email": ["The email field is required."],
    "password": ["The password field must be at least 8 characters."]
  }
}
```

`errors` كائن مفاتيحه أسماء الحقول وقيمه مصفوفة رسائل — اعرضها تحت كل حقل مباشرة.

### 401 — غير مصادَق

```json
{ "message": "Unauthenticated." }
```

بيرجع لما التوكن مفقود أو خاطئ أو ملغي. **المعالجة الصحيحة:** امسح التوكن المخزّن ووجّه المستخدم لصفحة تسجيل الدخول.

### 403 — توكن من بوابة تانية

```json
{ "message": "Invalid ability provided." }
```

بيرجع لما التوكن صالح بس صادر عن بوابة ما بتفتح هذا المسار — مثلاً توكن مريض على `/admin/categories`. **المعالجة:** ما تمسح التوكن (الجلسة شغالة بمكانها الصح)، وجّه المستخدم لباب البوابة المطلوبة.

ولما المسار محمي بدور كمان بيرجع `403` بنص تاني:

```json
{ "message": "Forbidden. You do not have access to this resource." }
```

---

### 429 — تجاوز الحد

```json
{
  "message": "Too many login attempts. Please try again in 45 seconds.",
  "errors": { "email": ["Too many login attempts. Please try again in 45 seconds."] }
}
```

في هيدر `Retry-After` بالثواني على أخطاء الـ throttle العامة.

### 404 — مسار غير موجود

```json
{ "message": "" }
```

> بوضع التطوير (`APP_DEBUG=true`) بينضاف للرد حقول `exception` و `file` و `trace`. لا تعتمد عليها — بالإنتاج بتختفي ويضل `message` بس.

---

## 4. الـ Endpoints

### 4.1 تسجيل حساب جديد

```
POST /api/v1/auth/register
```

بيسجّل **مريض** فقط. الـ `role` مثبّت من طرف السيرفر — لو بعثت `role` أو `specialty` بالطلب بينتجاهلوا بصمت.

**Body**

| الحقل | إلزامي | القواعد |
|---|---|---|
| `name` | ✅ | نص، 2–255 حرف |
| `email` | ✅ | إيميل صالح، فريد، **بيتحوّل لحروف صغيرة تلقائياً** |
| `phone` | ✅ | `+` اختيارية ثم أرقام/مسافات/`-`/`()`، 7–20 خانة، فريد |
| `birth_date` | ✅ | `YYYY-MM-DD`، قبل اليوم، وعمر أقل من 120 سنة |
| `gender` | ✅ | `male` أو `female` (غير حسّاس لحالة الأحرف) |
| `password` | ✅ | 8 أحرف على الأقل (شوف §6) |
| `password_confirmation` | ✅ | مطابق لـ `password` |
| `device_name` | ❌ | اسم الجهاز، الافتراضي `"api"` |

```json
{
  "name": "سارة أحمد",
  "email": "sara@example.com",
  "phone": "+966500000001",
  "birth_date": "1995-04-17",
  "gender": "female",
  "password": "password123",
  "password_confirmation": "password123",
  "device_name": "iphone-15"
}
```

**201 Created**

```json
{
  "message": "Registration successful.",
  "data": {
    "user": {
      "id": 1,
      "name": "سارة أحمد",
      "email": "sara@example.com",
      "phone": "+966500000001",
      "birth_date": "1995-04-17",
      "age": 31,
      "gender": "female",
      "role": "patient",
      "email_verified": false,
      "created_at": "2026-08-18T10:30:00+00:00"
    },
    "token": "1|k9Lm2pQrStUvWxYz...",
    "token_type": "Bearer",
    "expires_at": null
  }
}
```

المستخدم بيصير مسجّل دخول فوراً — ما في داعي تستدعي `login` بعدها.

**أخطاء محتملة:** `422` (إيميل أو هاتف مكرر، كلمة سر ضعيفة أو غير مطابقة).

---

### 4.2 تسجيل الدخول

**ثلاثة مسارات، نفس الـ Body ونفس شكل الرد تماماً:**

```
POST /api/v1/auth/login            ← المرضى
POST /api/v1/clinic/auth/login     ← الأطباء والسكرتارية
POST /api/v1/admin/auth/login      ← الأدمن
```

**Body**

```json
{
  "email": "sara@example.com",
  "password": "password123",
  "device_name": "iphone-15"
}
```

`device_name` اختياري. الإيميل غير حسّاس لحالة الأحرف والمسافات الزائدة بتنشال.

**200 OK** — نفس شكل رد التسجيل، بس `"message": "Login successful."`

التوكن الراجع مختوم ببوابة المسار اللي ضربته، وما بيفتح غيرها.

**422** — بيانات خاطئة:

```json
{
  "message": "These credentials do not match our records.",
  "errors": { "email": ["These credentials do not match our records."] }
}
```

> الخطأ دائماً تحت مفتاح `email` وبنفس النص في **ثلاث حالات**: الإيميل غير موجود، كلمة السر خاطئة، **أو الحساب بيتبع بوابة تانية**. هذا **مقصود** — لو رجّعنا "هذا الحساب بيدخل من مكان تاني" بنكون دلّينا المهاجم على وين يهاجم. اعرضه كرسالة عامة فوق الفورم، مش تحت حقل الإيميل.

**حالات 422 التانية:**

| الحالة | الرسالة |
|---|---|
| حساب معطّل (`is_active = false`) | `This account has been deactivated. Please contact the clinic.` |
| سكرتير غير مربوط بطبيب | نفس رسالة البيانات الخاطئة |

> الحساب المعطّل بينفحص **بعد** التأكد من كلمة السر، فالرسالة بتوصل صاحب الحساب لا أي حدا بيجرّب إيميلات.
>
> الطبيب اللي انتهى اشتراكه **بيقدر يسجّل دخول** — لازم يفوت عشان يجدّد. الاشتراك بينفحص عند كل إجراء لا عند الباب.

**429** — بعد 5 محاولات فاشلة (شوف §7).

---

### 4.3 بيانات المستخدم الحالي

```
GET /api/v1/auth/me
```

🔒 يتطلب `Authorization`.

**200 OK**

```json
{
  "data": {
    "id": 1,
    "name": "سارة أحمد",
    "email": "sara@example.com",
    "phone": "+966500000001",
    "birth_date": "1995-04-17",
    "age": 31,
    "gender": "female",
    "role": "patient",
    "email_verified": false,
    "created_at": "2026-08-18T10:30:00+00:00"
  }
}
```

استخدمه عند إقلاع التطبيق للتحقق إن التوكن المخزّن لسا صالح واسترجاع بيانات المستخدم. بيشتغل مع توكن أي بوابة.

`birth_date` و `age` و `gender` بيانات سريرية: بتظهر لصاحب الحساب وللدكتور والأدمن فقط، وبتنحذف من الرد لأي مستخدم تاني.

---

### 4.4 تسجيل الخروج

```
POST /api/v1/auth/logout
```

🔒 يتطلب `Authorization`. بيلغي **التوكن الحالي فقط** — باقي الأجهزة بتضل مسجّلة دخول. مسار واحد لكل البوابات.

**200 OK**

```json
{ "message": "Logged out successfully." }
```

---

### 4.5 تسجيل الخروج من كل الأجهزة

```
POST /api/v1/auth/logout-all
```

🔒 يتطلب `Authorization`. بيلغي **كل** توكنات المستخدم بما فيها الحالي.

**200 OK**

```json
{ "message": "Logged out from all devices." }
```

مناسب لزر "تسجيل الخروج من كل الأجهزة" في إعدادات الأمان.

---

### 4.6 طلب رابط إعادة تعيين كلمة السر

**باب لكل بوابة، زي تسجيل الدخول:**

```
POST /api/v1/auth/forgot-password          ← المرضى
POST /api/v1/clinic/auth/forgot-password   ← الأطباء والسكرتارية
POST /api/v1/admin/auth/forgot-password    ← الأدمن
```

**Body**

```json
{ "email": "sara@example.com" }
```

**200 OK**

```json
{
  "message": "If an account matches that email address, a password reset link has been sent."
}
```

> بيرجع **نفس الرد بالضبط** في كل الحالات: الحساب موجود، أو مش موجود، **أو موجود بس بيتبع بوابة تانية**. بالحالة الأخيرة ما بينبعت إشي بالمرة. لا تحاول تستنتج من الرد إذا الإيميل مسجّل — اعرض الرسالة زي ما هي.

**429** — لو طلب رابط جديد قبل مرور 60 ثانية:

```json
{ "message": "Please wait before retrying." }
```

---

### 4.7 إعادة تعيين كلمة السر

```
POST /api/v1/auth/reset-password
```

**نقطة واحدة لكل الأدوار** — الرمز اللي بالرابط بيعرّف صاحبه، فما في داعي لثلاث نسخ. اللي بيفرق هو **مسار الصفحة** اللي بيوصلها الرابط بالإيميل (§8).

**Body**

```json
{
  "token": "a1b2c3d4...",
  "email": "sara@example.com",
  "password": "new-password-123",
  "password_confirmation": "new-password-123"
}
```

**200 OK**

```json
{ "message": "Password reset successfully." }
```

**⚠️ الرد ما بيحتوي على توكن.** بعد النجاح وجّه المستخدم لصفحة تسجيل الدخول.

**422** — التوكن غير صالح أو منتهي أو مستهلك:

```json
{
  "message": "This password reset token is invalid.",
  "errors": { "email": ["This password reset token is invalid."] }
}
```

---

## 5. كائن `User`

| الحقل | النوع | ملاحظات |
|---|---|---|
| `id` | `number` | |
| `name` | `string` | |
| `email` | `string` | دائماً حروف صغيرة |
| `phone` | `string \| null` | |
| `role` | `"patient" \| "doctor" \| "secretary" \| "admin"` | أربع أدوار، ثلاث بوابات (§2.1) |
| `specialty` | `string` | **بيظهر فقط لما `role === "doctor"`** — غير موجود بالمرة لغيرهم |
| `email_verified` | `boolean` | حالياً `false` دائماً، التحقق مش مفعّل |
| `created_at` | `string` | ISO 8601 |

كلمة السر ما بتظهر أبداً بأي رد.

```ts
type UserRole = 'patient' | 'doctor' | 'secretary' | 'admin';
type Portal = 'patient' | 'clinic' | 'admin';

interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: UserRole;
  specialty?: string;
  email_verified: boolean;
  created_at: string;
}

interface AuthPayload {
  user: User;
  token: string;
  token_type: 'Bearer';
  expires_at: string | null;
}
```

> البوابة **مش موجودة بالرد** — إنت بتعرفها أصلاً لأنك اخترت المسار اللي ضربته. خزّنها بنفسك جنب التوكن.

---

## 6. قواعد كلمة السر

القواعد **بتختلف حسب البيئة**:

| البيئة | القواعد |
|---|---|
| تطوير (`APP_ENV=local`) | 8 أحرف كحد أدنى |
| إنتاج (`APP_ENV=production`) | 8 أحرف + حرف كبير وصغير + رقم + **غير مسرّبة في اختراقات معروفة** |

> ⚠️ كلمة سر بتنجح محلياً ممكن ترفض بالإنتاج. لا تعتمد على تحقق الفرونت لوحده — اعرض دائماً رسالة الخطأ الراجعة من `errors.password`.

الشرط الأخير بيفحص كلمة السر مقابل قاعدة بيانات haveibeenpwned، فرسالة الخطأ ممكن تكون "The given password has appeared in a data leak."

---

## 7. حدود الطلبات (Rate Limiting)

طبقتين:

**أ. حد عام لكل IP** — 20 طلب/دقيقة على `register`, `login`, `forgot-password`, `reset-password` مجتمعة، **على البوابات الثلاث كلها**. تجاوزه بيرجّع `429` مع هيدر `Retry-After`.

**ج. حد عام لكل الـ API** — 120 طلب/دقيقة **لكل مستخدم** (أو لكل IP للزائر) على كل المسارات. يتغيّر من `NADARA_RATE_LIMIT_PER_MINUTE`. تجاوزه `429`.

**ب. حد خاص بتسجيل الدخول** — 5 محاولات **فاشلة** لكل تركيبة (إيميل + IP + **بوابة**)، ثم قفل 60 ثانية. أي محاولة ناجحة بتصفّر العدّاد.

> البوابة جزء من مفتاح العدّاد عمداً: محاولات فاشلة على باب المرضى ما بتقفل الطبيب برّا لوحة العيادة.

اقرأ عدد الثواني من نص الرسالة أو من `Retry-After` واعمل عدّاد تنازلي بالواجهة.

---

## 8. تدفق نسيان كلمة السر — دور الفرونت

```
[1] المستخدم يدخل إيميله  →  POST /<portal>/auth/forgot-password
                                      ↓
[2] الباك إند يبعت إيميل فيه رابط:
    ${PORTAL_FRONTEND_URL}/reset-password?token=<TOKEN>&email=<EMAIL>
                                      ↓
[3] المستخدم يضغط الرابط  →  يفتح صفحة بالفرونت
                                      ↓
[4] الفرونت يقرأ token و email من الـ query string
                                      ↓
[5] يعرض فورم كلمة سر جديدة + تأكيدها
                                      ↓
[6] POST /auth/reset-password مع القيم الأربعة   ← مسار واحد لكل البوابات
                                      ↓
[7] نجاح  →  وجّه لباب البوابة اللي بيتبعها الحساب
```

**المطلوب منك:**

1. أنشئ مسار `reset-password` تحت **كل بوابة** بالفرونت.
2. اقرأ `token` و `email` من الـ query params ومرّرهم كما هم (`email` مُرمّز URL-encoded، فك ترميزه قبل الإرسال — معظم أدوات التوجيه بتعمل هذا تلقائياً).
3. الـ `token` صالح **60 دقيقة** ويُستهلك مرة واحدة.

### أي واجهة بيوديها الرابط؟

**دور صاحب الحساب هو اللي بيقرّر، مش المسار اللي انطلب منه.** يعني لو حدا طلب رابط لطبيب من باب المرضى، بينبعت ولا إشي أصلاً (§4.6)؛ ولو انطلب من باب العيادة، الرابط بيروح على واجهة العيادة حتى لو الطلب انبعت من مكان تاني.

**الإعداد المطلوب بالـ `.env`:**

```
PATIENT_FRONTEND_URL=https://app.nadara.com
CLINIC_FRONTEND_URL=https://app.nadara.com/clinic
ADMIN_FRONTEND_URL=https://app.nadara.com/admin
```

أي متغيّر منهم لو انترك فاضي بيرجع لـ `FRONTEND_URL` العام — يعني **كل الروابط بتنزل على واجهة المرضى**، والطبيب بيلاقي حاله ببابهم بعد ما يحفظ كلمة السر. اضبط الثلاثة.

> الواجهات الثلاث حالياً تطبيق واحد، فالثلاث قيم على نفس الدومين بمسارات مختلفة. لو انفصلوا لتطبيقات مستقلة بكرا، بتتغيّر القيم بس ولا شي بالكود.

---

## 9. مثال عميل JavaScript

```js
const BASE_URL = 'http://localhost:8000/api/v1';

// البوابة بتنخزن جنب التوكن: بتحدد لأي باب نرجّع المستخدم عند الخروج أو عند 403
const PORTALS = {
  patient: { login: '/auth/login', forgotPassword: '/auth/forgot-password' },
  clinic: { login: '/clinic/auth/login', forgotPassword: '/clinic/auth/forgot-password' },
  admin: { login: '/admin/auth/login', forgotPassword: '/admin/auth/forgot-password' },
};

const tokenStore = {
  get: () => localStorage.getItem('auth_token'),
  set: (token, portal) => {
    localStorage.setItem('auth_token', token);
    localStorage.setItem('auth_portal', portal);
  },
  portal: () => localStorage.getItem('auth_portal'),
  clear: () => {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('auth_portal');
  },
};

class ApiError extends Error {
  constructor(status, body) {
    super(body?.message ?? 'Request failed');
    this.status = status;
    this.errors = body?.errors ?? {};
  }

  /** أول رسالة خطأ لحقل معيّن */
  fieldError(field) {
    return this.errors[field]?.[0] ?? null;
  }
}

async function api(path, { method = 'GET', body, auth = true } = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  };

  const token = tokenStore.get();
  if (auth && token) {
    headers.Authorization = `Bearer ${token}`;
  }

  const response = await fetch(`${BASE_URL}${path}`, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  });

  const data = response.status === 204 ? null : await response.json();

  if (!response.ok) {
    // التوكن انتهى أو انلغى — نظّف ووجّه لتسجيل الدخول
    if (response.status === 401) {
      tokenStore.clear();
    }

    // 403 مع هذا النص = توكن بوابة تانية. الجلسة سليمة، بس المسار مش إلها —
    // وجّه لباب البوابة المطلوبة ولا تمسح إشي.
    if (response.status === 403 && data?.message === 'Invalid ability provided.') {
      // handleWrongPortal();
    }
    throw new ApiError(response.status, data);
  }

  return data;
}

export const auth = {
  // التسجيل الذاتي ببوابة المرضى وحدها
  register: async (payload) => {
    const { data } = await api('/auth/register', {
      method: 'POST',
      body: { ...payload, device_name: 'web' },
      auth: false,
    });
    tokenStore.set(data.token, 'patient');
    return data.user;
  },

  /** @param {'patient'|'clinic'|'admin'} portal */
  login: async (email, password, portal = 'patient') => {
    const { data } = await api(PORTALS[portal].login, {
      method: 'POST',
      body: { email, password, device_name: 'web' },
      auth: false,
    });
    tokenStore.set(data.token, portal);
    return data.user;
  },

  me: async () => (await api('/auth/me')).data,

  logout: async () => {
    try {
      await api('/auth/logout', { method: 'POST' });
    } finally {
      // نظّف محلياً حتى لو فشل الطلب
      tokenStore.clear();
    }
  },

  logoutAll: async () => {
    try {
      await api('/auth/logout-all', { method: 'POST' });
    } finally {
      tokenStore.clear();
    }
  },

  forgotPassword: (email, portal = 'patient') =>
    api(PORTALS[portal].forgotPassword, { method: 'POST', body: { email }, auth: false }),

  // مسار واحد لكل البوابات — الرمز بيعرّف صاحبه
  resetPassword: (payload) =>
    api('/auth/reset-password', { method: 'POST', body: payload, auth: false }),
};
```

### استعادة الجلسة عند إقلاع التطبيق

```js
async function bootstrapSession() {
  if (!tokenStore.get()) return null;

  try {
    return await auth.me();      // التوكن صالح
  } catch (error) {
    if (error.status === 401) {
      return null;               // انلغى — تم تنظيفه أصلاً داخل api()
    }
    throw error;
  }
}
```

---

## 10. CORS

الأصول المسموحة تُضبط عبر `CORS_ALLOWED_ORIGINS` في `.env` (مفصولة بفواصل):

```
CORS_ALLOWED_ORIGINS=http://localhost:5173,https://app.nadara.com
```

لو الواجهات الثلاث انفصلت لدومينات مستقلة، ضيفهم كلهم هون مفصولين بفواصل.

`supports_credentials` مطفأ لأن المصادقة بالتوكنات — **لا تستخدم `withCredentials: true`** ولا ترسل كوكيز.

---

## 11. جدول مرجعي سريع

**خاص ببوابة:**

| الطريقة | المسار | البوابة | نجاح |
|---|---|:---:|---|
| `POST` | `/api/v1/auth/register` | `patient` | `201` |
| `POST` | `/api/v1/auth/login` | `patient` | `200` |
| `POST` | `/api/v1/auth/forgot-password` | `patient` | `200` |
| `POST` | `/api/v1/clinic/auth/login` | `clinic` | `200` |
| `POST` | `/api/v1/clinic/auth/forgot-password` | `clinic` | `200` |
| `POST` | `/api/v1/admin/auth/login` | `admin` | `200` |
| `POST` | `/api/v1/admin/auth/forgot-password` | `admin` | `200` |

**مشترك لكل البوابات:**

| الطريقة | المسار | مصادقة | نجاح |
|---|---|:---:|---|
| `GET` | `/api/v1/auth/me` | 🔒 | `200` |
| `POST` | `/api/v1/auth/logout` | 🔒 | `200` |
| `POST` | `/api/v1/auth/logout-all` | 🔒 | `200` |
| `POST` | `/api/v1/auth/reset-password` | — | `200` |

---

## 12. الحجز والدفع والاستشارات (سبرنت 3)

### 12.1 رحلة المريض من أولها لآخرها

الترتيب مُلزِم — كل خطوة تعطيك المُعرّف الذي تحتاجه للتي بعدها:

```
GET  /doctors?service_id=3           → اختر طبيباً        → doctor_id
GET  /doctors/{doctor}/availability  → اختر فترة فاضية    → start_at
POST /appointments                   → احجز (201)         → appointment_id
POST /appointments/{id}/payment-receipt → ارفع الإيصال (201)
        … الموظف يراجع …
POST /payments/{id}/verify           → الموعد يصير approved
```

---

### 12.2 دليل الأطباء

#### `GET /doctors` — عام

يرجّع الأطباء **القابلين للحجز فقط**: مفعّلين، واشتراكهم ساري. الطبيب المعطَّل أو منتهي الاشتراك يختفي تماماً.

| الفلتر | مثال |
|---|---|
| `service_id` | `?service_id=3` — من يقدّم هذه الخدمة |
| `q` | `?q=ليلى` — بحث بالاسم |
| `accepts_online_consultations` | `?accepts_online_consultations=1` |
| `per_page` | `?per_page=20` (الحد الأقصى 100) |

```json
{
  "data": [{
    "id": 2,
    "name": "Dr. Layla Dermatologist",
    "image_url": null,
    "specialty": "Dermatology",
    "accepts_appointments": true,
    "accepts_online_consultations": true,
    "services": [
      { "id": 1, "name": "General Skin Examination", "price": "60.00", "duration_minutes": 30 }
    ]
  }],
  "links": { }, "meta": { }
}
```

> **`price` و `duration_minutes` هنا من الطبيب لا من الخدمة.** كل طبيب يسعّر الخدمة نفسها بسعره ومدّته. هذا هو مصدر الحقيقة الوحيد لما ستدفعه.

#### `GET /doctors/{doctor}` — عام

نفس الكائن لطبيب واحد. **`404`** إن كان معطَّلاً أو اشتراكه منتهياً.

#### الكتالوج صار يحمل سعر «يبدأ من»

`GET /services` و `GET /services/{service}` أضافا حقلين:

```json
{
  "id": 1,
  "name": "General Skin Examination",
  "price": "80.00",                  ← سعر إرشادي قديم، لا تعرضه للمريض
  "starting_price": "60.00",         ← أرخص طبيب — اعرض هذا: «يبدأ من 60»
  "bookable_doctors_count": 2
}
```

`starting_price` **يغيب من الرد** إذا لم يسعّر أي طبيب الخدمة بعد. و`GET /services/{service}` يرجّع معه مصفوفة `doctors` كاملة.

---

### 12.3 الإتاحة

#### `GET /doctors/{doctor}/availability?date=YYYY-MM-DD` — عام

| المعامل | القاعدة |
|---|---|
| `date` | **مطلوب**، صيغة `Y-m-d`، من اليوم حتى 90 يوماً |

```json
{ "data": [
  { "start_at": "2026-09-20T09:00:00+03:00", "end_at": "2026-09-20T09:30:00+03:00", "available": true },
  { "start_at": "2026-09-20T09:30:00+03:00", "end_at": "2026-09-20T10:00:00+03:00", "available": false }
]}
```

ثلاث نقاط مهمة:

1. **الفترات المشغولة تُرجَع كذلك** مع `available: false` — اعرضها مشطوبة لا تخفها.
2. **الأوقات بتوقيت العيادة** (`Asia/Hebron`) مع الإزاحة `+03:00` ملحقة. اعرض النص كما هو أو حلّله، كلاهما صحيح.
3. **مصفوفة فارغة `[]`** معناها الطبيب لا يداوم هذا اليوم أصلاً — مختلف عن 16 فترة كلها `false` (يداوم لكنه مجاز).

---

### 12.4 الحجز

#### `POST /appointments` 🔒 مريض فقط

```json
{ "doctor_id": 2, "service_id": 1, "start_at": "2026-09-20T09:00:00+03:00" }
```

| الاستجابة | متى |
|---|---|
| `201` | نجح الحجز، الحالة `pending_payment` |
| **`409`** | **الفترة حُجزت قبلك** — أعد تحميل `/availability` |
| `422` | الطبيب لا يقدّم الخدمة / اشتراكه منتهٍ / الوقت في الماضي / الوقت خارج دوام الطبيب أو في إجازته (`start_at`) |
| `403` | لست مريضاً |

> **`409` وليس `422` عمداً.** طلبك كان صحيحاً، لكن أحدهم سبقك بجزء من الثانية. الشيء الوحيد المفيد هو إعادة تحميل الفترات لا تصحيح المدخلات.

الرد يحمل `hold_expires_at` — **مهلة رفع الإيصال**، ثلاثون دقيقة افتراضياً. بعدها يصير الموعد `expired` وتتحرر الفترة.

#### `GET /appointments` 🔒

القائمة تتكيّف مع دورك تلقائياً:

| دورك | ترى |
|---|---|
| مريض | حجوزاتك أنت |
| طبيب | رزنامتك أنت |
| سكرتير | رزنامة طبيبك فقط |
| أدمن | كل شيء |

فلتر `?status=pending_payment` متاح. الحالات: `pending_payment` · `awaiting_review` · `approved` · `completed` · `expired` · `rejected` · `cancelled`.

#### `POST /appointments/{id}/cancel` 🔒 صاحب الحجز فقط

```json
{ "reason": "ظرف طارئ" }
```

`422` إن كان الموعد مغلقاً أصلاً، أو إن بقي أقل من **24 ساعة** على موعده. الرد يحمل `refund_required` — يصير `true` إذا كان المبلغ قد تُحقِّق منه، أو كان إيصاله ما زال قيد المراجعة (المريض حوّل المال فعلاً).

---

### 12.5 الدفع

#### `POST /appointments/{id}/payment-receipt` 🔒 `multipart/form-data`

| الحقل | القاعدة |
|---|---|
| `receipt` | **مطلوب** · `jpg,jpeg,png,pdf` · حد أقصى 4 ميجا |
| `method` | اختياري، نص ≤ 50 |
| `reference` | اختياري، نص ≤ 100 |

النتيجة: الدفعة `submitted`، الموعد `awaiting_review`، و`hold_expires_at` يُمسَح (الفترة لم تعد مؤقتة). يُولَّد `receipt_number` بصيغة `NDR-2026-000001`.

`422` على `status` إذا انتهت مهلة الحجز قبل وصول الإيصال: الموعد يصير `expired` فوراً، وعلى المريض الحجز من جديد.

#### `GET /appointments/{id}/payment-receipt` 🔒

يرجّع **الملف نفسه** لا JSON. مسموح لصاحب الحجز، وللطبيب، ولسكرتيره، وللأدمن — لا أحد غيرهم. الملفات على قرص خاص ولا يوجد رابط عام لها إطلاقاً.

#### `GET /payments/{id}/receipt` 🔒

يرجّع ملف الإيصال لأي دفعة، موعداً كانت أو استشارة. هذا ما يفتحه الطاقم من طابور الإيصالات، وهو الرابط في `receipt_url`. الصلاحية حسب الدفعة: صاحبها، أو طبيبها، أو سكرتيره، أو الأدمن. السكرتير يرى إيصال الاستشارة دون أن يرى الاستشارة نفسها.

#### `POST /payments/{id}/verify` و `/reject` 🔒 طاقم

`verify` ← الموعد يصير `approved` (أو الاستشارة `awaiting_reply`).
`reject` ← يتطلب `reason`، والموعد يعود `pending_payment` **بمهلة جديدة** ليرسل المريض إيصالاً صحيحاً.

`422` إذا لم يعد الموعد `awaiting_review` (ألغاه المريض مثلاً) أو لم تعد الاستشارة `pending_payment`.

**بوابتان فقط:** توكن `clinic` أو `admin`. توكن المريض يُرَدّ بـ `403` (`Invalid ability provided.`) قبل أن تُستشار الـ Policy أصلاً.

وبعد البوابة تأتي الصلاحية بالـ Policy لا بالدور: الأدمن، أو طبيب هذا الموعد، أو سكرتيره حصراً.

---

### 12.6 الاستشارات

#### `POST /consultations` 🔒 مريض · `multipart/form-data`

| الحقل | القاعدة |
|---|---|
| `doctor_id` · `service_id` | مطلوبان |
| `symptoms` | مطلوب، **20 حرفاً على الأقل**، حتى 5000 |
| `photos[]` | اختياري، **حتى 5 صور**، `jpg,jpeg,png,webp`، 2 ميجا لكل صورة |

`422` إذا كان الطبيب لا يقبل الاستشارات عن بُعد.

**الحالة تبقى `pending_payment` حتى يتحقق الطاقم من الدفع** — رفع الإيصال وحده لا يكفي. الطبيب لا يقرأ الأعراض قبل تأكيد المبلغ.

#### بقية المسارات

| المسار | ملاحظة |
|---|---|
| `GET /consultations` | مريضك/طبيبك/الأدمن — **السكرتير يرى `[]`** |
| `GET /consultations/{id}` | يحمل `messages` و `attachments` |
| `POST /consultations/{id}/payment-receipt` | نفس قواعد إيصال الموعد. `422` إذا كان هناك إيصال قيد المراجعة أو مؤكَّد |
| `POST /consultations/{id}/messages` | `message` مطلوب، 2–5000 حرف. `422` قبل تأكيد الدفع وبعد إغلاق الاستشارة |
| `GET /consultations/{id}/attachments/{attachment}` | يرجّع الصورة نفسها |

ردّ الطبيب ← الحالة `replied`. ردّ المريض ← تعود `awaiting_reply` حتى لا تضيع متابعة بلا جواب.

> **السكرتير محجوب عن الاستشارات كلها عمداً.** الدور للرزنامة والمصاري، لا للتشخيص. لكنه **مسموح** في مراجعة الإيصالات.

---

### 12.7 جدول مرجعي — سبرنت 3

| الطريقة | المسار | مصادقة | نجاح |
|---|---|:---:|---|
| `GET` | `/api/v1/doctors` | — | `200` |
| `GET` | `/api/v1/doctors/{doctor}` | — | `200` |
| `GET` | `/api/v1/doctors/{doctor}/availability` | — | `200` |
| `GET` | `/api/v1/appointments` | 🔒 | `200` |
| `POST` | `/api/v1/appointments` | 🔒 مريض | `201` / **`409`** |
| `GET` | `/api/v1/appointments/{id}` | 🔒 | `200` |
| `POST` | `/api/v1/appointments/{id}/cancel` | 🔒 مريض | `200` |
| `POST` | `/api/v1/appointments/{id}/payment-receipt` | 🔒 مريض | `201` |
| `GET` | `/api/v1/appointments/{id}/payment-receipt` | 🔒 | `200` (ملف) |
| `GET` | `/api/v1/payments/{id}/receipt` | 🔒 | `200` (ملف) |
| `POST` | `/api/v1/payments/{id}/verify` | 🔒 `clinic`/`admin` | `200` |
| `POST` | `/api/v1/payments/{id}/reject` | 🔒 `clinic`/`admin` | `200` |
| `GET` | `/api/v1/consultations` | 🔒 | `200` |
| `POST` | `/api/v1/consultations` | 🔒 مريض | `201` |
| `GET` | `/api/v1/consultations/{id}` | 🔒 | `200` |
| `POST` | `/api/v1/consultations/{id}/payment-receipt` | 🔒 مريض | `201` |
| `POST` | `/api/v1/consultations/{id}/messages` | 🔒 | `201` |
| `GET` | `/api/v1/consultations/{id}/attachments/{attachment}` | 🔒 | `200` (ملف) |

---

## 13. إدارة حسابات الطاقم — أدمن فقط (US-025)

كل المسارات هنا تحت `/api/v1/admin` وتتطلب توكن بوابة `admin` ودور `admin`. أي توكن آخر ← `403`.

الطبيب والسكرتير **لا يسجّلون بأنفسهم**: الأدمن ينشئ الحساب ويحدد كلمة السر ويسلّمها لصاحبها. **لا يوجد حذف** — الحساب يُعطَّل فقط، لأن المواعيد والمدفوعات مربوطة به.

### 13.1 كائن `StaffAccount`

```json
{
  "id": 12,
  "name": "د. سامي الخطيب",
  "email": "sami@nadara.test",
  "phone": "+970599000111",
  "image_url": null,
  "role": "doctor",
  "is_active": true,
  "specialty": "Dermatology",
  "subscription": { "status": "trial", "ends_at": "2026-10-17T09:00:00+00:00", "grace_ends_at": "2026-10-24T09:00:00+00:00" },
  "secretaries": [ { "id": 13, "name": "رنا", "role": "secretary", "is_active": true, "doctor_id": 12 } ],
  "created_at": "2026-10-03T09:00:00+00:00"
}
```

- `specialty` و `subscription` و `secretaries` تظهر للطبيب فقط.
- `doctor_id` يظهر للسكرتير فقط.

### 13.2 الأطباء

#### `GET /admin/doctors`

كل الأطباء، بمن فيهم المعطَّلون والمنتهية اشتراكاتهم. مع pagination.

| باراميتر | الوصف |
|---|---|
| `q` | بحث بالاسم أو الإيميل أو الهاتف |
| `is_active` | `1` أو `0` |
| `per_page` | حجم الصفحة |

#### `POST /admin/doctors` ← `201`

| الحقل | القواعد |
|---|---|
| `name` | مطلوب |
| `email` | مطلوب، فريد |
| `phone` | مطلوب، فريد |
| `password` | مطلوب، نفس قواعد القسم 6، **بدون** `password_confirmation` |
| `specialty` | اختياري |

الطبيب الجديد يبدأ `is_active = true` وبفترة تجريبية **14 يوماً**، فيظهر في الكتالوج فور إضافة خدماته ودوامه.

#### `GET /admin/doctors/{id}` · `PUT /admin/doctors/{id}`

التعديل يقبل نفس حقول الإنشاء وكلها اختيارية. إرسال `password` يعني إعادة تعيين: **كل جلسات الطبيب تُغلق**.
`role` و `is_active` لا يتغيران من هنا.

#### `POST /admin/doctors/{id}/toggle-active`

يقلب `is_active`. عند التعطيل: يختفي الطبيب من الكتالوج، تُغلق جلساته، ولا يستطيع الدخول. **مواعيده الموجودة لا تُلغى.**

> أي `id` ليس لطبيب ← `404`.

### 13.3 السكرتيرون

#### `GET /admin/doctors/{id}/secretaries` · `POST /admin/doctors/{id}/secretaries` ← `201`

نفس حقول الطبيب ما عدا `specialty`. الطبيب يؤخذ **من المسار** لا من الـ body، والسكرتير لا ينتقل لطبيب آخر.

#### `PUT /admin/secretaries/{id}` · `POST /admin/secretaries/{id}/toggle-active`

نفس سلوك الطبيب: تغيير كلمة السر أو التعطيل يغلق الجلسات.

### 13.4 جدول مرجعي

| الطريقة | المسار | نجاح |
|---|---|---|
| `GET` | `/api/v1/admin/doctors` | `200` |
| `POST` | `/api/v1/admin/doctors` | `201` |
| `GET` | `/api/v1/admin/doctors/{id}` | `200` |
| `PUT` | `/api/v1/admin/doctors/{id}` | `200` |
| `POST` | `/api/v1/admin/doctors/{id}/toggle-active` | `200` |
| `GET` | `/api/v1/admin/doctors/{id}/secretaries` | `200` |
| `POST` | `/api/v1/admin/doctors/{id}/secretaries` | `201` |
| `PUT` | `/api/v1/admin/secretaries/{id}` | `200` |
| `POST` | `/api/v1/admin/secretaries/{id}/toggle-active` | `200` |

---

## 14. الاشتراكات — أدمن فقط (US-028)

لا يوجد نظام فوترة. التحصيل يتم بتحويل أو واتساب، والأدمن يسجّل النتيجة بتحريك تاريخ الانتهاء.

### 14.1 حالة الاشتراك

`subscription.status` في كائن `StaffAccount` محسوبة لحظة الطلب، وهي واحدة من أربع:

| القيمة | المعنى | يظهر في الكتالوج ويستقبل حجوزات؟ |
|---|---|:---:|
| `trial` | فترة تجريبية لم تنتهِ | ✅ |
| `active` | اشتراك مدفوع لم ينتهِ | ✅ |
| `grace` | انتهى التاريخ وما زال ضمن مهلة السماح (**7 أيام**) | ✅ |
| `expired` | انتهت المهلة، أو أوقفه الأدمن | ❌ |

- `ends_at`: تاريخ انتهاء الاشتراك أو التجربة. `null` يعني مفتوح بلا نهاية.
- `grace_ends_at`: اللحظة التي يختفي فيها الطبيب من الكتالوج (`ends_at` + 7 أيام).
- الطبيب المنتهي **لا تُلغى مواعيده المدفوعة ولا تُمسح بياناته**، ويستطيع الدخول لبوابته.

### 14.2 `GET /admin/subscriptions`

كل الأطباء مرتّبين بالأقرب انتهاءً أولاً (والمفتوحون بلا تاريخ في الآخر). مع pagination.

| باراميتر | الوصف |
|---|---|
| `status` | `trial` · `active` · `grace` · `expired` |
| `per_page` | حجم الصفحة |

### 14.3 `POST /admin/doctors/{id}/extend-subscription`

| الحقل | القواعد |
|---|---|
| `ends_at` | مطلوب، `YYYY-MM-DD`، اليوم أو بعده. الاشتراك يغطي ذلك اليوم كاملاً بتوقيت العيادة |
| `status` | اختياري: `active` (الافتراضي — الطبيب دفع) أو `trial` (تمديد التجربة بلا دفع) |

يرجّع كائن `StaffAccount`. طبيب منتهٍ يعود للكتالوج فوراً بعد التمديد.

| الطريقة | المسار | نجاح |
|---|---|---|
| `GET` | `/api/v1/admin/subscriptions` | `200` |
| `POST` | `/api/v1/admin/doctors/{id}/extend-subscription` | `200` |

---

## 15. سجل التدقيق — أدمن فقط

كل قرار يتخذه الطاقم ويغيّر مالاً أو صلاحية يُسجَّل تلقائياً: مين عمله، على أي سجل، ومتى. السجل **للقراءة فقط** — لا يوجد مسار يعدّل أو يحذف منه.

### 15.1 ما الذي يُسجَّل

| `action` | متى | `subject.type` | `changes` |
|---|---|---|---|
| `payment.verified` | التحقق من إيصال | `payment` | `amount`, `appointment_id`, `consultation_id` |
| `payment.rejected` | رفض إيصال | `payment` | `reason`, `appointment_id`, `consultation_id` |
| `account.created` | إنشاء طبيب أو سكرتير | `user` | `role`, `email` (و `doctor_id` للسكرتير) |
| `account.activated` | إعادة تفعيل حساب | `user` | — |
| `account.deactivated` | تعطيل حساب | `user` | — |
| `account.password_reset` | الأدمن غيّر كلمة سر حساب | `user` | — |
| `subscription.extended` | تمديد اشتراك أو تجربة | `user` (الطبيب) | `from` و `to`، كلٌّ فيه `status` و `ends_at` |
| `appointment.booked_by_phone` | حجز تلفوني من العيادة | `appointment` | `patient_id`, `doctor_id`, `start_at`, `price` |
| `appointment.cancelled_by_clinic` | إلغاء من العيادة | `appointment` | `reason`, `refund_required` |
| `appointment.duration_changed` | تعديل مدة موعد | `appointment` | `from_minutes`, `to_minutes` |
| `appointment.completed` | تعليم موعد مكتملاً | `appointment` | `price` |

كلمات السر لا تُكتب في السجل إطلاقاً.

### 15.2 `GET /admin/audit-logs`

الأحدث أولاً، مع pagination.

| باراميتر | الوصف |
|---|---|
| `action` | واحدة من قيم الجدول أعلاه |
| `user_id` | مين عمل القرار |
| `from` · `to` | `YYYY-MM-DD` بتوقيت العيادة، شاملة للطرفين |
| `per_page` | حجم الصفحة |

```json
{
  "id": 41,
  "action": "subscription.extended",
  "actor": { "id": 1, "name": "Admin", "role": "admin" },
  "subject": { "type": "user", "id": 12 },
  "changes": {
    "from": { "status": "trial", "ends_at": "2026-10-17T09:00:00+00:00" },
    "to": { "status": "active", "ends_at": "2026-11-30T20:59:59+00:00" }
  },
  "created_at": "2026-10-03T09:00:00+00:00"
}
```

---

## 16. تشغيل العيادة — `/staff/*`

كل المسارات هنا تحت `/api/v1/staff` وتتطلب توكن بوابة `clinic` أو `admin`. المريض ← `403`.

### 16.1 مين بشوف شو

| الدور | النطاق |
|---|---|
| طبيب | عيادته فقط |
| سكرتير | عيادة طبيبه فقط |
| أدمن | كل العيادات، ويضيّق بـ `doctor_id` |

- `doctor_id` **ممنوع** على الطبيب والسكرتير (`422`): العيادة تتحدد من هوية المستخدم لا من الطلب.
- الأدمن **ملزم** بإرسال `doctor_id` في كل ما يخص عيادة بعينها: الحجز التلفوني، وجدول الدوام، والإجازات.
- سجل يخص عيادة أخرى ← `403`.

> **الجدول اليومي وقوائم المواعيد** ليست هنا: `GET /appointments` (قسم 12.4) يقيّد نفسه بعيادة المتصل أصلاً.
> جدول اليوم المؤكَّد = `GET /appointments?period=today&status=approved`. الأدمن يضيف `doctor_id` إن أراد.

### 16.2 المرضى — `GET /staff/patients`

مرضى العيادة: كل من له موعد عند هذا الطبيب. الأدمن يرى كل المرضى. مع pagination.

| باراميتر | الوصف |
|---|---|
| `q` | بحث بالاسم أو الهاتف |
| `per_page` | حجم الصفحة |

```json
{ "id": 7, "name": "أحمد", "phone": "+970599123456", "email": null, "image_url": null,
  "appointments_count": 3, "last_appointment_at": "2026-09-20T10:00:00+03:00" }
```

**لا شيء تشخيصياً:** لا `birth_date` ولا `gender` ولا `age`، لأي دور. `email` قد يكون `null` لمريض حُجز له تلفونياً.

### 16.3 المدفوعات — `GET /staff/payments`

طابور مراجعة الإيصالات، الأحدث أولاً. يشمل مدفوعات المواعيد والاستشارات. مع pagination.

| باراميتر | الوصف |
|---|---|
| `status` | `pending` · `submitted` · `verified` · `rejected` |
| `from` · `to` | `YYYY-MM-DD` بتوقيت العيادة |
| `q` | رقم الإيصال، أو المرجع، أو اسم/هاتف المريض |

كل عنصر هو كائن الدفع المعتاد مضافاً إليه: `appointment_id`، `consultation_id`، `patient` (بصيغة 16.2)، و `appointment` (`id`, `status`, `start_at`, `service`, `doctor`) إن كان الدفع لموعد.
التحقق والرفض يبقيان على `POST /payments/{id}/verify` و `/reject`.

### 16.4 الحجز التلفوني — `POST /staff/appointments` ← `201`

السكرتير يحجز لمريض اتصل. الموعد يُنشأ **مؤكَّداً** (`approved`) ومدفوعاً **نقداً** (`method: cash`, `verified`) في خطوة واحدة، بلا مهلة ولا إيصال.

| الحقل | القواعد |
|---|---|
| `patient_phone` | مطلوب. به يُعرَّف المريض |
| `patient_name` | مطلوب. يُستخدم فقط إن كان الرقم جديداً |
| `service_id` | مطلوب، خدمة يقدمها الطبيب |
| `start_at` | مطلوب، في المستقبل |
| `doctor_id` | للأدمن فقط، ومطلوب منه |

- رقم موجود لمريض ← يُستخدم حسابه كما هو. رقم جديد ← يُفتح له حساب بلا إيميل وبكلمة سر عشوائية.
- رقم يخص طبيباً أو سكرتيراً ← `422`.
- الفترة مأخوذة ← `409`، ولا يُنشأ حساب.

### 16.5 إلغاء من العيادة — `POST /staff/appointments/{id}/cancel`

| الحقل | القواعد |
|---|---|
| `reason` | **مطلوب** |

لا تنطبق مهلة الـ 24 ساعة الخاصة بالمريض. إن كان الموعد مدفوعاً يعود `refund_required: true` — **الاسترجاع نفسه يتم خارج النظام**. موعد مغلق أصلاً ← `422`.

### 16.6 تعديل المدة — `PATCH /staff/appointments/{id}/duration`

| الحقل | القواعد |
|---|---|
| `duration_minutes` | مطلوب، من 5 إلى 480 |

يغيّر `end_at` فقط. إن تداخلت المدة الجديدة مع موعد آخر ← `409`. موعد مغلق ← `422`.

### 16.6.1 تعليم الموعد مكتملاً — `POST /staff/appointments/{id}/complete`

بلا body. للطبيب أو سكرتيره أو الأدمن. الموعد يجب أن يكون `approved` وأن يكون وقته قد بدأ، وإلا `422`.
**هذا ما يُدخل الموعد في تقرير دخل الطبيب.**

### 16.7 جدول الدوام — `/staff/schedules`

كل صف وردية واحدة في يوم واحد من الأسبوع. التعديل يظهر للمرضى فوراً.

```json
{ "id": 3, "doctor_id": 12, "weekday": 0, "starts_at": "09:00", "ends_at": "13:00", "slot_minutes": 30, "is_active": true }
```

| الحقل | القواعد |
|---|---|
| `weekday` | `0` الأحد … `6` السبت |
| `starts_at` · `ends_at` | `HH:MM` بتوقيت العيادة، والنهاية بعد البداية |
| `slot_minutes` | من 5 إلى 240 |
| `is_active` | اختياري، الافتراضي `true` |

- `GET` · `POST` · `PUT /{id}` (ترسل الوردية كاملة) · `DELETE /{id}`.
- وردية تتداخل مع وردية فعّالة أخرى في نفس اليوم ← `422` على `starts_at`.
- حذف وردية أو تقليصها أو تعطيلها وفيها مواعيد قادمة ← **`409`** مع `stranded_appointments` (العدد). ألغِ تلك المواعيد أو انقلها أولاً.

### 16.8 الإجازات — `/staff/schedule-exceptions`

| الحقل | القواعد |
|---|---|
| `date` | مطلوب، `YYYY-MM-DD`، اليوم أو بعده |
| `starts_at` · `ends_at` | اختياريان معاً. بدونهما = **يوم كامل** |
| `reason` | مطلوب |

- `GET` (اليوم وما بعده) · `POST` · `DELETE /{id}`.
- إجازة فوق مواعيد قادمة ← **`409`** مع `stranded_appointments`.

### 16.9 جدول مرجعي

| الطريقة | المسار | نجاح |
|---|---|---|
| `GET` | `/api/v1/staff/patients` | `200` |
| `GET` | `/api/v1/staff/payments` | `200` |
| `POST` | `/api/v1/staff/appointments` | `201` / `409` |
| `POST` | `/api/v1/staff/appointments/{id}/cancel` | `200` |
| `PATCH` | `/api/v1/staff/appointments/{id}/duration` | `200` / `409` |
| `POST` | `/api/v1/staff/appointments/{id}/complete` | `200` |
| `GET` | `/api/v1/staff/schedules` | `200` |
| `POST` | `/api/v1/staff/schedules` | `201` |
| `PUT` | `/api/v1/staff/schedules/{id}` | `200` / `409` |
| `DELETE` | `/api/v1/staff/schedules/{id}` | `200` / `409` |
| `GET` | `/api/v1/staff/schedule-exceptions` | `200` |
| `POST` | `/api/v1/staff/schedule-exceptions` | `201` / `409` |
| `DELETE` | `/api/v1/staff/schedule-exceptions/{id}` | `200` |

---

## 17. أدوات الطبيب — `/doctor/*`

كل المسارات هنا تحت `/api/v1/doctor`، بتوكن بوابة `clinic` ودور **طبيب فقط**. السكرتير والأدمن ← `403`.

### 17.1 خدماتي وأسعاري

#### `GET /doctor/services`

كتالوج المنصة كاملاً (الخدمات الفعّالة)، وكل خدمة معها شروط الطبيب أو `null` إن لم يسعّرها بعد. بلا pagination.

```json
{ "id": 4, "name": "ليزر", "category": "تجميلي", "image_url": null, "default_duration_minutes": 30,
  "offering": { "price": "150.00", "duration_minutes": 45, "is_active": true } }
```

#### `PUT /doctor/services/{service}`

يسعّر خدمة لأول مرة أو يعدّل شروطها.

| الحقل | القواعد |
|---|---|
| `price` | مطلوب، رقم ≥ 0 بخانتين عشريتين كحد أقصى |
| `duration_minutes` | مطلوب، من 5 إلى 480 |
| `is_active` | اختياري. `false` يخفي الخدمة من صفحة الطبيب دون فقدان السعر. إن لم يُرسل يبقى كما هو (`true` لخدمة جديدة) |

المواعيد المحجوزة سابقاً **لا تتأثر**: سعرها ومدتها محفوظان عليها منذ لحظة الحجز.

### 17.2 التفضيلات — `GET` · `PUT /doctor/preferences`

```json
{ "accepts_appointments": true, "accepts_online_consultations": false }
```

كلا الحقلين اختياري في `PUT`. الإيقاف يمنع العمل **الجديد** فقط؛ المحجوز والمدفوع يستمر.

### 17.3 تقرير الدخل — `GET /doctor/income`

| باراميتر | الوصف |
|---|---|
| `from` · `to` | `YYYY-MM-DD` بتوقيت العيادة. الافتراضي: الشهر الحالي كاملاً |

```json
{
  "from": "2026-09-01", "to": "2026-09-30", "total": "370.50",
  "appointments": {
    "count": 3, "total": "330.50",
    "by_service": [ { "service_id": 4, "name": "ليزر", "count": 2, "total": "250.50" } ]
  },
  "consultations": { "count": 1, "total": "40.00" }
}
```

- **المواعيد:** المكتملة (`completed`) فقط، مؤرَّخة بوقت الموعد.
- **الاستشارات:** المدفوعة التي ردّ عليها الطبيب، مؤرَّخة بأول رد.
- الأرقام من السعر المحفوظ على الموعد/الاستشارة، فتعديل السعر اليوم **لا يغيّر** دخل شهر مضى.
- هذا تتبّع دخل لا محاسبة: لا مصاريف ولا ضرائب.

### 17.4 الملاحظات الطبية

ملاحظة واحدة لكل زيارة. **لا يقرؤها ولا يكتبها إلا طبيب الموعد نفسه** — لا السكرتير ولا الأدمن ولا طبيب آخر ولا المريض (`403`).

- `GET /doctor/appointments/{id}/medical-note` ← الملاحظة أو `data: null`.
- `PUT /doctor/appointments/{id}/medical-note` · `{ "notes": "..." }` ← ينشئها أو يعدّلها. الموعد يجب أن يكون `approved` أو `completed`.

#### `GET /doctor/patients/{id}/history`

أرشيف زيارات المريض **لهذا الطبيب فقط**، الأحدث أولاً، مع ملاحظة كل زيارة. من لم يزر هذا الطبيب ← `404`.

```json
{
  "patient": { "id": 7, "name": "أحمد", "birth_date": "1990-01-01", "age": 36, "gender": "male" },
  "visits": [
    { "appointment_id": 31, "status": "completed", "start_at": "2026-09-20T10:00:00+03:00",
      "service": "ليزر", "note": { "id": 5, "appointment_id": 31, "notes": "..." } }
  ]
}
```

### 17.5 جدول مرجعي

| الطريقة | المسار | نجاح |
|---|---|---|
| `GET` | `/api/v1/doctor/services` | `200` |
| `PUT` | `/api/v1/doctor/services/{service}` | `200` |
| `GET` · `PUT` | `/api/v1/doctor/preferences` | `200` |
| `GET` | `/api/v1/doctor/income` | `200` |
| `GET` · `PUT` | `/api/v1/doctor/appointments/{id}/medical-note` | `200` |
| `GET` | `/api/v1/doctor/patients/{id}/history` | `200` |

---

## 18. الإشعارات — داخل المنصة

إشعارات تُحفظ في قاعدة البيانات وتُقرأ من التطبيق (جرس). **لا إيميل ولا واتساب.** لأي مستخدم مسجَّل الدخول، وكلٌّ يرى إشعاراته فقط.

الـ API يرسل **النوع والمعرّفات لا الجملة**؛ الصياغة على الفرونت.

| `kind` | لمن | متى | `details` |
|---|---|---|---|
| `appointment.confirmed` | المريض | تم التحقق من إيصال موعده | `appointment_id` |
| `payment.rejected` | المريض | رُفض إيصاله | `appointment_id` أو `consultation_id`، `reason` |
| `appointment.cancelled_by_clinic` | المريض | العيادة ألغت موعده | `appointment_id`, `reason`, `refund_required` |
| `consultation.opened` | المريض | تم التحقق من إيصال استشارته | `consultation_id` |
| `consultation.replied` | المريض | الطبيب ردّ على الاستشارة | `consultation_id` |

### `GET /notifications`

الأحدث أولاً، مع pagination. `?unread=1` لغير المقروءة فقط. الرد يحمل `unread_count` بجانب `data` لرسم الجرس بطلب واحد.

```json
{ "id": "9b1c…", "kind": "payment.rejected", "details": { "appointment_id": 31, "reason": "الصورة غير واضحة" },
  "read_at": null, "created_at": "2026-10-03T09:00:00+00:00" }
```

### `POST /notifications/{id}/read` · `POST /notifications/read-all`

إشعار يخص مستخدماً آخر ← `404`.

---

## 19. الأمان والأداء (سبرنت 6)

### 19.1 مصفوفة الصلاحيات

كل مسار مسموح لأدوار محددة. غير المسموح: الزائر ← `401`، والمسجَّل ← `403`.

| المسارات | زائر | مريض | طبيب | سكرتير | أدمن |
|---|:---:|:---:|:---:|:---:|:---:|
| تسجيل الدخول والتسجيل ونسيان كلمة السر | ✅ | ✅ | ✅ | ✅ | ✅ |
| الكتالوج، دليل الأطباء، الإتاحة | ✅ | ✅ | ✅ | ✅ | ✅ |
| `/auth/me`، `/profile`، `/notifications` | ❌ | ✅ | ✅ | ✅ | ✅ |
| `GET /appointments` وعرض موعد وإيصاله (كلٌّ في نطاقه) | ❌ | ✅ | ✅ | ✅ | ✅ |
| الحجز، الإلغاء كمريض، رفع الإيصال | ❌ | ✅ | ❌ | ❌ | ❌ |
| `GET /consultations` (السكرتير يستلم قائمة فارغة) | ❌ | ✅ | ✅ | ✅ | ✅ |
| عرض استشارة ومرفقاتها | ❌ | ✅ | ✅ | ❌ | ✅ |
| فتح استشارة ورفع إيصالها | ❌ | ✅ | ❌ | ❌ | ❌ |
| الكتابة في الاستشارة | ❌ | ✅ | ✅ | ❌ | ❌ |
| التحقق من إيصال أو رفضه | ❌ | ❌ | ✅ | ✅ | ✅ |
| `/staff/*` | ❌ | ❌ | ✅ | ✅ | ✅ |
| `/doctor/*` | ❌ | ❌ | ✅ | ❌ | ❌ |
| `/admin/*` | ❌ | ❌ | ❌ | ❌ | ✅ |

فوق الدور يأتي **النطاق**: الطبيب والسكرتير لا يصلان إلا لسجلات عيادتهما، والمريض لسجلاته فقط.

المصفوفة كاملة، مساراً مساراً، في `tests/Feature/Security/RoleMatrixTest.php`، وتُفحص مع كل push. مسار جديد غير مُدرج فيها يُفشل الاختبارات.

### 19.2 الأداء

- **لا N+1:** عدد الاستعلامات في كل قائمة ثابت مهما زاد عدد الصفوف (`tests/Feature/Performance/QueryCountTest.php`). وخارج الإنتاج، أي تحميل علاقة صفاً صفاً يرمي خطأ بدل أن يمرّ صامتاً.
- **الفهارس** مضافة للفلاتر والترتيبات التي تستعملها القوائم فعلاً.
- كل القوائم مرقَّمة: `per_page` افتراضياً 15 وأقصاه 100.
