# NADARA API (v1)

مرجع تكامل الفرونت إند. كل الأمثلة مأخوذة من السلوك الفعلي للـ backend ومغطّاة باختبارات.

الأقسام من 1 إلى 11 تغطّي المصادقة. **القسم 12 يغطّي الحجز والدفع والاستشارات (سبرنت 3).**

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

### وين نخزّن التوكن؟

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

```
POST /api/v1/auth/login
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

**422** — بيانات خاطئة:

```json
{
  "message": "These credentials do not match our records.",
  "errors": { "email": ["These credentials do not match our records."] }
}
```

> الخطأ دائماً تحت مفتاح `email` وبنفس النص، سواء الإيميل غير موجود أو كلمة السر خاطئة. هذا **مقصود** لمنع كشف الحسابات المسجّلة. اعرضه كرسالة عامة فوق الفورم، مش تحت حقل الإيميل.

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

استخدمه عند إقلاع التطبيق للتحقق إن التوكن المخزّن لسا صالح واسترجاع بيانات المستخدم.

`birth_date` و `age` و `gender` بيانات سريرية: بتظهر لصاحب الحساب وللدكتور والأدمن فقط، وبتنحذف من الرد لأي مستخدم تاني.

---

### 4.4 تسجيل الخروج

```
POST /api/v1/auth/logout
```

🔒 يتطلب `Authorization`. بيلغي **التوكن الحالي فقط** — باقي الأجهزة بتضل مسجّلة دخول.

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

```
POST /api/v1/auth/forgot-password
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

> بيرجع **نفس الرد بالضبط** سواء الحساب موجود أو لا. لا تحاول تستنتج من الرد إذا الإيميل مسجّل — اعرض الرسالة زي ما هي.

**429** — لو طلب رابط جديد قبل مرور 60 ثانية:

```json
{ "message": "Please wait before retrying." }
```

---

### 4.7 إعادة تعيين كلمة السر

```
POST /api/v1/auth/reset-password
```

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
| `role` | `"patient" \| "doctor" \| "admin"` | |
| `specialty` | `string` | **بيظهر فقط لما `role === "doctor"`** — غير موجود بالمرة لغيرهم |
| `email_verified` | `boolean` | حالياً `false` دائماً، التحقق مش مفعّل |
| `created_at` | `string` | ISO 8601 |

كلمة السر ما بتظهر أبداً بأي رد.

```ts
type UserRole = 'patient' | 'doctor' | 'admin';

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

**أ. حد عام لكل IP** — 20 طلب/دقيقة على `register`, `login`, `forgot-password`, `reset-password` مجتمعة. تجاوزه بيرجّع `429` مع هيدر `Retry-After`.

**ب. حد خاص بتسجيل الدخول** — 5 محاولات **فاشلة** لكل تركيبة (إيميل + IP)، ثم قفل 60 ثانية. أي محاولة ناجحة بتصفّر العدّاد.

اقرأ عدد الثواني من نص الرسالة أو من `Retry-After` واعمل عدّاد تنازلي بالواجهة.

---

## 8. تدفق نسيان كلمة السر — دور الفرونت

```
[1] المستخدم يدخل إيميله  →  POST /auth/forgot-password
                                      ↓
[2] الباك إند يبعت إيميل فيه رابط:
    ${FRONTEND_URL}/reset-password?token=<TOKEN>&email=<EMAIL>
                                      ↓
[3] المستخدم يضغط الرابط  →  يفتح صفحة بالفرونت
                                      ↓
[4] الفرونت يقرأ token و email من الـ query string
                                      ↓
[5] يعرض فورم كلمة سر جديدة + تأكيدها
                                      ↓
[6] POST /auth/reset-password مع القيم الأربعة
                                      ↓
[7] نجاح  →  وجّه لصفحة تسجيل الدخول
```

**المطلوب منك:**

1. أنشئ مسار `/reset-password` بالفرونت.
2. اقرأ `token` و `email` من الـ query params ومرّرهم كما هم (`email` مُرمّز URL-encoded، فك ترميزه قبل الإرسال — معظم أدوات التوجيه بتعمل هذا تلقائياً).
3. الـ `token` صالح **60 دقيقة** ويُستهلك مرة واحدة.

**الإعداد المطلوب بالـ backend:** المتغيّر `FRONTEND_URL` في `.env` لازم يطابق دومين الفرونت (حالياً `http://localhost:5173`).

---

## 9. مثال عميل JavaScript

```js
const BASE_URL = 'http://localhost:8000/api/v1';

const tokenStore = {
  get: () => localStorage.getItem('auth_token'),
  set: (token) => localStorage.setItem('auth_token', token),
  clear: () => localStorage.removeItem('auth_token'),
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
    throw new ApiError(response.status, data);
  }

  return data;
}

export const auth = {
  register: async (payload) => {
    const { data } = await api('/auth/register', {
      method: 'POST',
      body: { ...payload, device_name: 'web' },
      auth: false,
    });
    tokenStore.set(data.token);
    return data.user;
  },

  login: async (email, password) => {
    const { data } = await api('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: 'web' },
      auth: false,
    });
    tokenStore.set(data.token);
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

  forgotPassword: (email) =>
    api('/auth/forgot-password', { method: 'POST', body: { email }, auth: false }),

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

`supports_credentials` مطفأ لأن المصادقة بالتوكنات — **لا تستخدم `withCredentials: true`** ولا ترسل كوكيز.

---

## 11. جدول مرجعي سريع

| الطريقة | المسار | مصادقة | نجاح |
|---|---|:---:|---|
| `POST` | `/api/v1/auth/register` | — | `201` |
| `POST` | `/api/v1/auth/login` | — | `200` |
| `GET` | `/api/v1/auth/me` | 🔒 | `200` |
| `POST` | `/api/v1/auth/logout` | 🔒 | `200` |
| `POST` | `/api/v1/auth/logout-all` | 🔒 | `200` |
| `POST` | `/api/v1/auth/forgot-password` | — | `200` |
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
| `422` | الطبيب لا يقدّم الخدمة / اشتراكه منتهٍ / الوقت في الماضي |
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

`422` إن كان الموعد مغلقاً أصلاً، أو إن بقي أقل من **24 ساعة** على موعده. الرد يحمل `refund_required` — يصير `true` إذا كان المبلغ قد تُحقِّق منه فعلاً.

---

### 12.5 الدفع

#### `POST /appointments/{id}/payment-receipt` 🔒 `multipart/form-data`

| الحقل | القاعدة |
|---|---|
| `receipt` | **مطلوب** · `jpg,jpeg,png,pdf` · حد أقصى 4 ميجا |
| `method` | اختياري، نص ≤ 50 |
| `reference` | اختياري، نص ≤ 100 |

النتيجة: الدفعة `submitted`، الموعد `awaiting_review`، و`hold_expires_at` يُمسَح (الفترة لم تعد مؤقتة). يُولَّد `receipt_number` بصيغة `NDR-2026-000001`.

#### `GET /appointments/{id}/payment-receipt` 🔒

يرجّع **الملف نفسه** لا JSON. مسموح لصاحب الحجز، وللطبيب، ولسكرتيره، وللأدمن — لا أحد غيرهم. الملفات على قرص خاص ولا يوجد رابط عام لها إطلاقاً.

#### `POST /payments/{id}/verify` و `/reject` 🔒 طاقم

`verify` ← الموعد يصير `approved` (أو الاستشارة `awaiting_reply`).
`reject` ← يتطلب `reason`، والموعد يعود `pending_payment` **بمهلة جديدة** ليرسل المريض إيصالاً صحيحاً.

الصلاحية بالـ Policy لا بالدور: الأدمن، أو طبيب هذا الموعد، أو سكرتيره حصراً.

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
| `POST /consultations/{id}/payment-receipt` | نفس قواعد إيصال الموعد |
| `POST /consultations/{id}/messages` | `message` مطلوب، 2–5000 حرف |
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
| `POST` | `/api/v1/payments/{id}/verify` | 🔒 طاقم | `200` |
| `POST` | `/api/v1/payments/{id}/reject` | 🔒 طاقم | `200` |
| `GET` | `/api/v1/consultations` | 🔒 | `200` |
| `POST` | `/api/v1/consultations` | 🔒 مريض | `201` |
| `GET` | `/api/v1/consultations/{id}` | 🔒 | `200` |
| `POST` | `/api/v1/consultations/{id}/payment-receipt` | 🔒 مريض | `201` |
| `POST` | `/api/v1/consultations/{id}/messages` | 🔒 | `201` |
| `GET` | `/api/v1/consultations/{id}/attachments/{attachment}` | 🔒 | `200` (ملف) |
