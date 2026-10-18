# بوابة المكتب التقني — «الحسابات والصلاحيات»

## النطاق
- بوابة بسيطة للفريق التقني: مسار الدخول `/technical` (صفحة رئيسية موجزة بلا إحصاءات) وعنصر واحد في الشريط الجانبي: `/technical/accounts`.
- المكتب التقني (الوحدة `715`) تابع تنظيميًا لمديرية الشؤون الإدارية (`71`). **هذه التبعية لا تمنح أي صلاحية**؛ الوصول يأتي فقط من دور `technical_team` وصلاحياته.

## نموذج التفويض
| العنصر | القيمة |
|---|---|
| الدور | `technical_team` |
| صلاحية الدخول | `technical_portal.access` (هوية فقط) |
| العرض | `user_accounts.view` |
| الإدارة | `user_accounts.manage` |
| صلاحية «إدارة الصلاحيات» | `users_permissions.manage`، محصورة بـ `super_admin` ولا تُمنح للفريق التقني |

- **قائمة سماح صريحة** بالأدوار التي يمكن للفريق التقني إسنادها أو سحبها: `App\Support\AccountAdministration::TECHNICAL_ASSIGNABLE_ROLES`
  (`doctor_instructor`، `academic_advisor`، `registration_officer`، `exam_officer`، `finance_officer`، `hr_officer`، `librarian`).
  أي دور آخر، بما فيها الأدوار التي تُضاف لاحقًا، محصور بـ `super_admin`.
- فحص إضافي مغلق افتراضيًا: الدور المسموح يُرفض إذا صار يحمل `users_permissions.*` أو `user_accounts.*` أو `technical_portal.access` أو `system_settings.manage`.
- لا يعدّل عضو الفريق التقني أدوار حسابه أو حالته، ولا حسابًا يحمل دورًا خارج قائمة السماح.
- لا يمكن سحب دور آخر `super_admin` نشط أو تعطيل حسابه.
- كل عملية ذرية (`DB::transaction` مع `lockForUpdate`) ومدققة في `user_activity_logs`
  (`account.created`، `account.role_assigned`، `account.role_revoked`، `account.status_changed`).
  يُسجَّل `assigned_by_user_id` و`assigned_at` على الخادم.

## واجهات API
مسارات `/api/v1/technical/accounts`:

| الطريقة | المسار | الصلاحية |
|---|---|---|
| GET | `/`، `/options`، `/{user}` | `user_accounts.view` |
| POST | `/` | `user_accounts.manage` |
| POST | `/{user}/roles` | `user_accounts.manage` |
| DELETE | `/{user}/roles/{role}` | `user_accounts.manage` |
| PUT | `/{user}/status` | `user_accounts.manage` |

- يُرسل `password` (مع `password_confirmation`) ويُشفّر بـ `Hash::make` إلى `password_hash`. لا يُقبل `password_hash` من الواجهة، ولا تُعاد كلمة المرور أو قيمتها المشفّرة.
- **إغلاق المسارات العامة:**
  - `users` و`user-roles` و`user-activity-logs` للقراءة فقط لجميع المستخدمين، بما فيهم `super_admin`.
  - الكتابة على `roles` و`permissions` و`role-permissions` محصورة بـ `super_admin`.
  - دور `super_admin` لا يُعاد تسميته ولا يُعطَّل ولا يُحذف.
- إصلاح: `RequireSystemAdministrator` كان يستقبل الخدمة كوسيط middleware فيُعيد 500، وصار يحقنها عبر الـ constructor.

## الواجهة
- صفحات مرجعية للتصميم: `frontend/src/features/hr-dashboard/pages/EmployeesPage.jsx` و`HRHome.jsx`، والمكوّنات المشتركة `components/table/DataTable.jsx` و`FilterBar.jsx` و`components/layout/DashboardLayout.jsx`. لم تُعدَّل المكوّنات المشتركة.
- الملفات: `frontend/src/features/technical-portal/` (`nav.js`، `lib/`، `pages/`، `components/`).
- عند تعديل أدوار الحساب الحالي، تُعاد قراءة `/api/user` وتُحدَّث الهوية المخزنة، ويُعاد التوجيه إذا فُقدت صلاحية الصفحة.

## قاعدة البيانات
SQL يدوي فقط: `backend/database/sql/technical-team-portal/`. انظر `README.md` هناك لترتيب التنفيذ.
