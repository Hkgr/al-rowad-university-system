# Al Rowad University API

هذا المجلد هو المرجع الرسمي للـ API بين فريق الباك إند والفرونت إند.

## الملفات المهمة

- openapi.yaml
  التوثيق الرسمي القابل للعرض عبر Swagger / OpenAPI.

- contracts/
  عقود تفصيلية لكل ميزة أثناء التطوير.

- eports/laravel-api-routes.txt
  تقرير فعلي مستخرج من Laravel routes.

- runo/
  اختبارات عملية للـ API.

## Base URLs

### Local Laravel API

`	xt
http://127.0.0.1:8000/api
`

### Version 1

`	xt
http://127.0.0.1:8000/api/v1
`

## Authentication

معظم endpoints داخل /api/v1 تحتاج Bearer Token.

أولاً يجب تسجيل الدخول:

`http
POST /api/login
`

ثم استخدام التوكن في الطلبات التالية:

`http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
`

## أهم API حالياً

### Auth

- POST /api/login
- GET /api/user
- POST /api/logout

### Students

- GET /api/v1/students
- POST /api/v1/students
- GET /api/v1/students/search
- GET /api/v1/students/{student}/profile
- GET /api/v1/students/{student}/academic-info
- GET /api/v1/students/{student}/registration-summary

### Course Registration

- POST /api/v1/registrations/register-student
- POST /api/v1/registrations/{id}/drop
- POST /api/v1/registrations/{id}/withdraw

### Lookups needed by frontend

- GET /api/v1/academic-programs
- GET /api/v1/academic-levels
- GET /api/v1/student-statuses
- GET /api/v1/course-offerings/open

## قاعدة الفريق

أي API جديد لا يعتبر جاهزاً إلا إذا توفرت معه:

- Route في Laravel
- Request validation إن احتاج
- Response واضح
- تحديث في openapi.yaml
- Bruno request شغال
- عقد داخل contracts/ إذا كان جزءاً من ميزة جديدة
