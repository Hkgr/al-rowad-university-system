# بوابة نائب رئيس الجامعة للشؤون الإدارية

يغطي هذا التطوير أربعة أجزاء:

1. تحسين مراجعة تكليفات المدرسين.
2. تطوير «الرئيسية» لتعرض المؤشرات الإدارية.
3. إضافة «إدارة المدرسين».
4. إضافة «عمداء الكليات».

لم تُستخدم الهيكلية التنظيمية مصدرًا للصلاحيات؛ فالتبعية التنظيمية لا تمنح شيئًا.

## الصلاحيات

الإضافات أربع صلاحيات في وحدة `vice_presidency`، وتُسند إلى الدور `vice_president_administrative` عبر حزمة SQL يدوية.

| الصلاحية | ما تسمح به |
|---|---|
| `vice_presidency.administrative.faculty.view` | عرض الملفات التدريسية وانتماءها وسجلها. |
| `vice_presidency.administrative.faculty.manage` | إنشاء ملف تدريسي أو ربط موظف قائم. تعديل الحقول المسموحة. إسناد الانتماء أو نقله أو إنهاؤه. |
| `vice_presidency.administrative.deans.view` | عرض الكليات وعمدائها. |
| `vice_presidency.administrative.deans.manage` | تعيين عميد أو نقله أو إنهاء تكليفه. |

**قاعدة الخادم** (`App\Support\AdministrativeGovernance`) تشترط ثلاثة أمور معًا:

- أن يكون الحساب مفعّلًا؛
- أن يملك نطاق جامعة فعليًا؛
- إما دور `vice_president_administrative` مع الصلاحية مسندةً عبر الأدوار، أو دور `super_admin`. ويبقى `super_admin` خاضعًا لشرط نطاق الجامعة.

**قاعدة الواجهة** تطابق قاعدة الخادم، وهي معرّفة في `features/vice-presidency/utils/administrativeAccess.js`. الخادم يعيد التحقق من كل طلب.

**مؤشرات الرئيسية** تشترط الدور مع `vice_presidency.administrative.access`، أو `super_admin`، مع نطاق الجامعة في الحالتين.

**النائب «للعرض فقط»:** تُحذف منه صلاحيتا `manage`، كما يشرح README الحزمة.

## المسارات

واجهة البرمجة تحت البادئة `/api/v1/vice-presidency/administrative`:

| الطريقة والمسار | الصلاحية |
|---|---|
| `GET dashboard?academic_year_id&semester_id&college_id` | الوصول إلى الرئيسية |
| `GET faculty`، `GET faculty/{id}` | faculty.view |
| `GET faculty/employee-lookup`، `POST faculty`، `PATCH faculty/{id}`، `POST faculty/{id}/affiliation` | faculty.manage |
| `GET deans` | deans.view |
| `GET deans/account-lookup`، `POST deans`، `POST deans/{college}/transfer`، `POST deans/{college}/end` | deans.manage |

**التعديلات على المسارات القائمة:**

- `GET /api/v1/vice-presidency/teaching-assignments`:
  - فلاتر جديدة: `search`، `department_id`، `academic_year_id`، `semester_id`، `instructor_role`، `status`؛
  - كل صف يحمل `viewer_context`؛
  - الاستجابة تحمل `filter_options`.
- `approve` و`return` يقبلان `expected_submission_version`. عند عدم التطابق يعيدان 409 `teaching_assignment_version_mismatch`. تعيد الاستجابة التفاصيل الحديثة من الخادم.
- `GET /api/v1/teaching-staff/assignment-instructors` يقبل `course_offering_id`. يرتّب أعضاء كلية الطرح أولًا، ويضيف لكل صف `in_offering_college` و`affiliated_colleges`. القائمة تبقى على مستوى الجامعة (قرار مؤكد).

**صفحات الواجهة:**

- `/vp/administrative` (الرئيسية)؛
- `/vp/administrative/teaching-assignments`، مع حفظ الفلاتر في الرابط؛
- `/vp/administrative/faculty`؛
- `/vp/administrative/deans`.

## مصدر كل مؤشر (`AdministrativeDashboardService`)

كل رقم ناتج عن استعلام `COUNT … GROUP BY`، ولا تُحمَّل قوائم كاملة. البيانات غير المتاحة تُعرض «غير متاح» مع السبب، ولا تُعرض صفرًا.

- **الطلاب النشطون:**
  - المصدر: جدول `students` حيث `status_code = active` وغير محذوفين.
  - الكلية تُؤخذ من قسم البرنامج.
  - المؤشر لقطة حالية لا تتأثر بالسنة ولا بالفصل.
  - «إجمالي الجامعة» يُحسب دون فلتر كلية فقط.
- **المدرسون النشطون:**
  - ملف تدريسي مفعّل لموظف حالته `active`.
  - الانتماء إلى الكلية يكون عبر أحد أمرين: الوحدة الأساسية للموظف، أو إسناد وحدة نافذ لم ينتهِ.
  - مجموعات الكليات قد تتداخل؛ الإجمالي يعدّ كل مدرس مرة واحدة، ويُعرض معه عدد من لا ينتمون إلى أي كلية.
- **التكليفات:**
  - الأساس: الطلبات الحالية (`current_slot = 1`) ضمن نطاق الاستعلام نفسه الذي تستخدمه قائمة المراجعة الإدارية.
  - `pending`: المراجعة الإدارية حالتها «بانتظار».
  - `returned` و`approved`: حسب حالة الطلب.
  - الكلية: كلية قسم الطرح، وإن لم يوجد فكلية قسم البرنامج.
  - فلتر السنة والفصل يُطبَّق على الطرح.
  - يُعرض المؤشر «غير متاح» في حالتين: `review_permission_missing`، و`workflow_schema_missing`.

## قرارات وضمانات

- **دورة الاعتماد لم تتغير.** لا توجد كتابة مباشرة على `course_offering_instructors` من هذه الصفحات. تغيير الانتماء لا يغيّر أي تكليف نافذ، ولا يُنشأ حساب دخول للمدرس.
- **سجل الانتماء:**
  - الانتماء يُسجَّل في `employee_unit_assignments`.
  - النقل والإنهاء يُغلقان الصف القديم (`end_date`، `is_active = 0`) ولا يحذفانه.
  - تتغير الوحدة الأساسية فقط إذا كانت تشير إلى الكلية السابقة.
  - `DataScopeService` صار يتجاهل الإسنادات المنتهية، بما يتوافق مع المؤشرات.
- **العميد:**
  - يُمنح دور `dean` ونطاق كلية واحدة. تُعطَّل نطاقاته الأكاديمية الأخرى، ويُنشأ منصب `DEAN` بتاريخ البدء.
  - النقل والإنهاء يسحبان النطاق القديم في المعاملة نفسها ويغلقان المنصب.
  - الإنهاء يعطّل دور العميد إن لم يبقَ عميدًا لكلية أخرى، ولا يحذف الحساب.
- **حسابات لا يعدّلها المسار أبدًا:**
  - حساب `super_admin`؛
  - أي حساب يملك نطاق جامعة نافذًا؛
  - أي حساب يحمل أدوارًا خارج `dean` و`doctor_instructor` و`academic_advisor`؛
  - حساب المنفّذ نفسه.

  ويُرفض أيضًا دور العميد إذا حمل صلاحيات محجوزة.
- **مدخلات مرفوضة من الواجهة:** `role_ids` و`scopes` و`scope_type` و`password_hash`.
- **كلمة المرور:**
  - يشترط الخادم فيها 10 محارف على الأقل، وحروفًا كبيرة وصغيرة، ورقمًا.
  - تُشفَّر بـ`Hash::make`.
  - لا تُعاد في الاستجابات ولا تُسجَّل.
- **التزامن:**
  - معاملات مع `lockForUpdate`.
  - توقع الحالة الحالية عبر `expected_current_dean_user_id` و`expected_target_dean_user_id` و`expected_assignment_id`، ويعيد الخادم 409 عند الاختلاف.
  - الطلب المكرر لا يغيّر شيئًا (`changed: false`)، والتعارض الفريد يعيد 409.
- **التدقيق:** السجلات في `user_activity_logs` (`module_code = vice_presidency`). تسجّل المنفّذ والحساب والكلية، والقيم السابقة واللاحقة للأدوار والنطاقات. العمليات المسجلة:
  - `faculty.profile_created`؛
  - `faculty.profile_updated`؛
  - `faculty.affiliation_*`؛
  - `dean.appointed`، `dean.transferred`، `dean.ended`.
- **CRUD العام لم يُوسَّع:**
  - `employees` و`faculty_members` و`employee_unit_assignments` ما زالت تتطلب `hr.*`؛
  - `users` و`user-roles` للقراءة فقط؛
  - لا يوجد مسار لـ`user_access_scopes`.

## المرجع البصري

اتُّبعت صفحات المكتب التقني المبنية على `features/hr-dashboard/pages/EmployeesPage.jsx`، وهي:

- `features/technical-portal/pages/AccountsPermissionsPage.jsx`؛
- `components/CreateAccountModal.jsx`؛
- `components/AccountDetailPanel.jsx`.

واستُعمل كذلك `ExecutiveOverview.jsx` و`ReportCharts.jsx` للمؤشرات. لم تُعدَّل المكوّنات المشتركة (`DataTable`، `FilterBar`، `DashboardLayout`).

لقطات الشاشة في `docs/images/vp-administrative-portal/`:

- لقطات على قاعدة `alrowad_uni_rust` محلية مع بيانات اختبار؛
- اللقطة `desktop-09-home-unavailable-synthetic.png` حالة «غير متاح» مصطنعة باعتراض الطلب.

## حدود معروفة

- قائمة اختيار المدرس لدى العميد تبقى على مستوى الجامعة؛ العلامة «من كلية الطرح» للترتيب والتمييز فقط.
- `user_access_scopes` بلا تواريخ؛ تاريخ العمادة محفوظ في `employee_positions` وسجل التدقيق.
- اختبار `SupplementaryExamEndToEndHardeningContractTest` يفشل لأي فرع يضيف ملفات تحت `database/sql/`. فهو يقارن فرق الفرع بـ`origin/develop`، وليس هذا انحدارًا في السلوك.
