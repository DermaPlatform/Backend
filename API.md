# NADARA API — دليل المصادقة (v1)

مرجع تكامل الفرونت إند مع نظام المصادقة. كل الأمثلة مأخوذة من السلوك الفعلي للـ backend ومغطّاة باختبارات.

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
| `password` | ✅ | 8 أحرف على الأقل (شوف §6) |
| `password_confirmation` | ✅ | مطابق لـ `password` |
| `device_name` | ❌ | اسم الجهاز، الافتراضي `"api"` |

```json
{
  "name": "سارة أحمد",
  "email": "sara@example.com",
  "phone": "+966500000001",
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
    "role": "patient",
    "email_verified": false,
    "created_at": "2026-08-18T10:30:00+00:00"
  }
}
```

استخدمه عند إقلاع التطبيق للتحقق إن التوكن المخزّن لسا صالح واسترجاع بيانات المستخدم.

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
