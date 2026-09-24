# تطوير بوابة النائب الإداري

## الواجهات المرجعية

- `frontend/src/features/vice-presidency/pages/TeachingAssignmentQueue.jsx` و`TeachingAssignmentDetail.jsx` للمراجعات.
- `frontend/src/features/vice-presidency/pages/VicePresidentShell.jsx` و`frontend/src/features/executive-reports/components/ExecutiveOverview.jsx` لمؤشرات الرئيسية الحالية وتوزيع الكليات.
- `frontend/src/features/dean-dashboard/pages/DeanTeachers.jsx` و`frontend/src/features/dean-dashboard/components/DashboardBarChart.jsx` لعرض أعضاء الهيئة والإحصاءات.
- `frontend/src/features/technical-portal/pages/AccountsPermissionsPage.jsx` لقائمة الحسابات وحالاتها، و`frontend/src/features/hr-dashboard/pages/AddEmployeePage.jsx` لحقول الموظف.

## الهوية والنطاق

المسارات الجديدة تحت `/api/v1/vice-presidency/administrative/personnel` تتطلب على الخادم دور `vice_president_administrative` الفعّال ونطاق جامعة فعليًا وصلاحية مخصصة مسندة من الدور؛ `super_admin` الافتراضي وحده لا يجتاز المسار. الصلاحيات الأربع هي `administrative_staff.view/manage` و`administrative_deans.view/manage`. حزمة `backend/database/sql/administrative-vp-personnel/` تربطها بدور النائب الإداري فقط. لا تغيّر قائمة الأدوار التي يمكن للمكتب التقني إسنادها.

المدرس هو سجل `faculty_members` مرتبط بـ`employees`. كليته تُحل من وحدة الموظف التنظيمية أو إسناد وحدة فعّال وفق `DataScopeService::facultyMemberBelongsToCollege`؛ لذلك ينتقل ظهوره للعميد عند نقل انتمائه. لا تكتب هذه الصفحة على `course_offering_instructors` ولا تنشئ تكليف مادة. ملف موظف جديد يأخذ النوع الأكاديمي والحالة الفعّالة؛ ويمكن ربط موظف أكاديمي قائم بلا ملف تدريسي.

إنشاء العميد يحفظ `employees`، وحساب `users` بكلمة مرور مجزأة، وربط `user_roles` بدور `dean`، ونطاق `user_access_scopes` من نوع `college` لكلية واحدة داخل معاملة. للحساب القائم تُرفض الأدوار الأخرى أو النطاقات الفعالة الأخرى؛ ويُرفض تعيين عميد آخر لكلية لها عميد نشط. إنهاء التكليف يعطل رابط الدور والنطاق ويُبقي الحساب والموظف والتاريخ. تسجَّل تغييرات المدرسين والعمداء في `user_activity_logs` دون كلمات مرور.

## المؤشرات والمراجعات

تظل `ExecutiveOverview` تعرض مؤشرات الجامعة والطلاب وأعضاء الهيئة بحسب الكلية لمن يملك صلاحية التقرير. يعرض قسم جديد عدد طلبات تكليف المدرسين حسب حالة المراجعة الإدارية ويقسم المنتظر حسب كلية الطرح من استعلام مجمع على الخادم. القيم الجامعة ليست قيمة كلية محددة. رابط كل حالة يفتح قائمة التكليفات بمرشحها. تعرض القائمة نوع الطلب ومقدمه، وتتيح البحث باسم المدرس أو المادة أو رمزها، وتصفية الكلية ونوع الطلب. التفاصيل تعرض سبب الطلب ونسخة الإرسال وموافقات النائبين وأثر القرار؛ طلب `409` يعيد جلب الحالة قبل إعادة المحاولة.

## التحقق

اختبارات الواجهة `frontend/tests/administrativePersonnelAccess.test.mjs` تغطي حراس المسارات والفصل بين العرض والإدارة. اختبار Laravel `backend/tests/Feature/AdministrativePersonnelTest.php` يستخدم SQLite مع HTTP للتحقق من حفظ المدرس ونقله بين الكليات، وتصفية البحث، وتعيين العميد الجديد أو ربط حساب قائم، وتشفير كلمة المرور ورفض المستخدم بلا صلاحية أو نطاق. في بيئة إعداد التغيير نجحت اختبارات الواجهة (220/220) و`eslint` للملفات المعدلة و`vite build` وفحص صياغة PHP بمحلل مستقل. لم تتوفر هنا أدوات PHP أو MariaDB أو متصفح للقطات سطح المكتب والهاتف؛ يجب تشغيل اختبار Laravel وملفات SQL على قاعدة تجريبية ثم مقارنة لقطات الواجهات مع المرجع قبل اعتماد النشر. بناء الواجهة استخدم اعتماد `xlsx@0.18.5` من npm في نسخة مؤقتة بسبب تعذر الوصول إلى `cdn.sheetjs.com`.
