# School System - Monorepo

إدارة المدارس الأهلية باستخدام PHP 8.3 وMySQL 8.4. يتضمن المشروع حاليًا أساس النظام، المصادقة والصلاحيات، وإدارة المدارس والفروع.

## التشغيل المحلي

1. انسخ `.env.example` إلى `.env` وغيّر كلمات مرور قاعدة البيانات و`JWT_SECRET` إلى قيم عشوائية. لا ترفع `.env` إلى Git.
2. أنشئ سر JWT: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`.
3. شغّل: `docker compose up -d --build`.
4. أنشئ أول مدير نظام بكلمة مرور قوية: `docker compose exec api php bin/create-admin.php admin@example.com 'مدير النظام' 'ضع-كلمة-مرور-قوية-هنا'`.
5. افحص الخدمة: `curl http://localhost:8080/health`.

## المصادقة

- `POST /api/v1/auth/login` — JSON: `email`, `password`.
- `POST /api/v1/auth/refresh` — JSON: `refresh_token`، مع تدوير الرمز وإبطال السابق.
- `POST /api/v1/auth/logout` — Bearer access token وJSON اختياري يحوي `refresh_token`.
- `POST /api/v1/auth/me` — Bearer access token لإرجاع الحساب والأدوار والصلاحيات.

## المدارس والفروع

جميع هذه المسارات تتطلب `Authorization: Bearer <access_token>`:

| Method | Endpoint | العملية |
| --- | --- | --- |
| GET | `/api/v1/schools?limit=50&offset=0` | قائمة المدارس ضمن نطاق المستخدم |
| POST | `/api/v1/schools` | إنشاء مدرسة |
| GET | `/api/v1/schools/{schoolId}` | تفاصيل مدرسة |
| PATCH | `/api/v1/schools/{schoolId}` | تعديل بيانات مدرسة |
| DELETE | `/api/v1/schools/{schoolId}` | أرشفة مدرسة بدل حذفها |
| GET | `/api/v1/schools/{schoolId}/branches` | قائمة فروع المدرسة |
| POST | `/api/v1/schools/{schoolId}/branches` | إنشاء فرع |
| GET | `/api/v1/schools/{schoolId}/branches/{branchId}` | تفاصيل فرع |
| PATCH | `/api/v1/schools/{schoolId}/branches/{branchId}` | تعديل فرع |
| DELETE | `/api/v1/schools/{schoolId}/branches/{branchId}` | أرشفة فرع بدل حذفه |

مثال جسم إنشاء مدرسة: `{"name_ar":"مدرسة النور","name_en":"Al Noor School","code":"NOOR-01","email":"info@example.com","phone":"0500000000","address":"الرياض"}`. الحقول المطلوبة `name_ar` و`code`. حالات المنشأة: `active`, `inactive`, `archived`. تدعم القوائم `limit` (حتى 100) و`offset`.

الصلاحيات المطلوبة: `schools.read`, `schools.manage`, `branches.manage`. مدير النظام لديه جميع الصلاحيات. تقيّد قاعدة `user_schools` وصول المستخدمين الآخرين بالمدارس المعيّنة لهم؛ تُدار التعيينات مباشرةً من قاعدة البيانات إلى حين إضافة واجهة إدارة المستخدمين.

## قاعدة البيانات

ملفات التهيئة تعمل تلقائيًا على قاعدة بيانات جديدة. لقواعد البيانات القائمة، شغّل ترحيل المرحلة المطلوبة يدويًا مرة واحدة: `packages/database/init/002_auth_and_rbac.sql` ثم `packages/database/init/003_schools_and_branches.sql`.

تُحفظ رموز التجديد كتجزئات، وتُسجل عمليات إنشاء وتحديث وأرشفة المدارس والفروع في `audit_logs`. استخدم HTTPS والأسرار الآمنة في بيئة الإنتاج.
