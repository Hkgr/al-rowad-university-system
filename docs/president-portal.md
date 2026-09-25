# بوابة رئيس الجامعة — قراءة ومتابعة

## الأساس والتدقيق

- الفرع `codex/university-president-portal` من `develop` عند `e87843b467a98d3373f8ff5dcc10e14a2780f56c` (دمج PR #142).
- راجعت خدمات الوزارة وتعريفات المؤشرات وقواعد النطاق والمسارات وRBAC قبل التنفيذ. المرجع المحلي للمخطط: `D:/UNI/alrowad_uni_rust24-9-2.sql`، قراءة تعريفات فقط، دون استيراده أو استعمال سجلات الجامعة في الاختبارات. المرجع ليس إثباتًا لحالة الإنتاج الحالية.
- `roles.role_code=university_president` موجود في المرجع. لا landing أو بوابة خاصة به في الكود السابق. لا تنشئ الحزمة هذا الدور ولا شاغل رئاسة ولا حسابًا ولا كلمة مرور ولا نطاقًا.
- لا يوجد فرع تفويض للرئيس في خدمات قرارات التكليفات والطروحات والتسجيل والترفيع والتخرج. `AcademicRecordWorkflow::AUTHORITY_ROLE` هو **registration_officer** للترفيع والتخرج، وليس الرئيس ولا النائب العلمي.
- ظهور `university_president` في `StoreDisciplinaryCaseRequest::decided_by_authority` هو تسجيل جهة قرار، لا إثبات إحالة إلكترونية للرئيس أو صلاحية اعتماد خاصة به. لا نوصل هذا المدخل العام كعملية قرار في البوابة الجديدة.
- لا إعادة تفسير للهيكل من الرسم؛ `MinistryStaffService::leadership/unit` يقرأ `organizational_units` وقيود المناصب والأدوار المسجلة. المجتمعية وحدة بلا شاغل/دور عند غيابهما.

## التصميم والحدود

استخدمت `DashboardLayout`, `DataTable` و`MinistryUi` و`DeanStatus` الحاليين. مراجع الواجهة: `MinistryHome.jsx`, `MinistryColleges.jsx`, `MinistryDeanDetail.jsx` و`docs/images/ministry-portal/desktop-11-deans.png`. لم تتغير أنماط Cairo أو RTL أو المكونات العامة. وافق المستخدم على التنفيذ المباشر دون مسودة تصميم منفصلة.

الأقسام الثمانية في `/president`: الرئيسية، الكليات والمعاهد، الطلاب والشؤون الأكاديمية، الامتحانات والنتائج، المدرسون والكوادر، النيابات والإدارات، القرارات والمتابعة، التقارير. تتبعها قوائم البرامج والمواد والنتائج والعمداء، وتفاصيل لكل مورد. القوائم تستخدم البحث والترقيم الخادمي (25 افتراضيًا، 100 أقصى)، والمرشحات المسموحة فقط؛ تفاصيل التقارير تنتقل إلى قوائم المؤشرات المطابقة.

«المعاهد» تسمية البوابة؛ لا ينشئ الكود معاهد وهمية ولا يستنتج أن برنامجًا أو قسمًا معهد. يعرض كيانات `colleges` المسجلة ووحداتها كما هي.

## التفويض

كل endpoint يتطلب حسابًا فعالًا ودور `university_president` فعالًا وصلاحيات **معينة فعلًا** من `effectivePermissions()` ونطاق جامعة فعلي من `DataScopeService::hasActualUniversityScope()` المرتبط بـPRES. لا تستخدم الحماية `hasPermission()` أو تجاوز super-admin.

| القسم | الصلاحية بالإضافة إلى `president_portal.access` |
|---|---|
| الرئيسية | `president_portal.dashboard.view` |
| الكليات/البرامج/المواد | `president_portal.colleges.view` |
| الطلاب | `president_portal.students.view` |
| الامتحانات/النتائج | `president_portal.exams.view` |
| المدرسون/العمداء | `president_portal.staff.view` |
| الوحدات | `president_portal.leadership.view` |
| المتابعة | `president_portal.followup.view` |
| التقارير | `president_portal.reports.view` |

SQL يربط هذه القراءات بالدور الموجود فقط. لا منح لدور الوزارة أو نائب أو مدير النظام، ولا تعديل للمنح السابقة. امتلاك super-admin وحده لا يكفي؛ الرئيس متعدد الأدوار يحتاج المنح الفعلية نفسها. هوية تحمل الوزارة تظل محصورة في بوابة الوزارة بالخادم وتوجيه الدخول الحالي؛ الوزارة تسبق الرئيس، ثم الرئيس المخول يسبق العميد/النواب، وتبقى أولوية بقية الحسابات دون تغيير. عند غياب صلاحية الرئيسية يمكن الهبوط في أول قسم مخول. لا توسعة لواجهات النواب أو إدارة الحسابات أو العلامات.

## API ومشاركة المنطق

كل المسارات تحت `/api/v1/president` هي **GET فقط**:

- `filters`, `dashboard`, `reports`.
- `colleges|programs|courses|students|faculty|deans|leadership|exams|results` و`/{id}`.
- `followup`, `followup/{source}`, `followup/{source}/{id}`.

`PresidentReadService` يركّب خدمات `MinistryDashboardService`, `MinistryAcademicService`, `MinistryStaffService`, `MinistryStudentService` واستعلامات `MinistryQueries`؛ لا نسخ لصيغ المؤشرات أو GPA. يعيد ربط روابط المؤشرات إلى قوائم الرئيس، ويضيف قوائم الطروحات والنتائج والبرامج المطابقة. `filters` يعيد استخدام الإسقاط المرجعي الموجود في Controller الوزارة، خلف حارس الرئيس المستقل. لم تتغير استجابة الوزارة أو صلاحياتها أو خدماتها الإنتاجية.

نتائج الطلاب تستخدم `officialResults()`؛ أحدث `grade_approval_id` هو المرجع، ولا تظهر نتيجة عرض عاد اعتماده إلى returned. شاشة الامتحانات تعرض حالة الجزء (غيابه draft) دون علاماته، وأحدث اعتماد نهائي بصورة مستقلة. الطلاب: الهوية الأكاديمية الضرورية فقط؛ لا اتصال أو ميلاد أو حسابات أو ملاحظات تحقيق/مراجعة. أزيل gender من تفصيل الرئيس لعدم حاجته. سجل المتابعة يعرض معرف السجل وحالته فقط؛ القرار التأديبي يضيف جهة القرار وتاريخه لا تفاصيل المخالفة.

## جدول المؤشرات

| المؤشر | التعريف والمصدر | الزمن والنطاق / الوجهة |
|---|---|---|
| الطلاب | طلاب غير محذوفين، مرة واحدة؛ `MinistryQueries::students/filterStudents` | لقطة حالية، كلية البرنامج الحالي؛ قائمة الطلاب |
| الطلاب النشطون | المجموعة نفسها مع `student_statuses.status_code=active` | لقطة حالية |
| البرامج | `academic_programs.is_active=1` وغير مؤرشفة | الكلية/البرنامج الحالي؛ قائمة البرامج بـactive=1 |
| المدرسون | أعضاء هيئة فعّالون، انتماء الوحدة الأساسية أو تكليف وحدة فعّال وفق المصدر الحالي | فريدون إجمالًا؛ مرشح البرنامج يعرض كلية البرنامج مع توضيح؛ قائمة faculty |
| الكليات | `colleges.is_active=1` مع الكلية المختارة أو كلية البرنامج | قائمة active=1 تحفظ الكلية؛ غير الفعّالة متاحة من القائمة |
| العمداء | شخص × كلية: حساب فعال بدور ونطاق كلية فعالين على الحساب نفسه **أو** قيد منصب سارٍ | تعريف الوزارة نفسه؛ قيد منتهٍ مع حساب مخول يظهر تعارضًا وتاريخ النهاية الحقيقي |
| المواد | مواد فعالة ضمن عضويات الخطة النافذة فقط، لا جمع للمسودات/التاريخ | قائمة courses بنفس المرشحات |
| المسجلون | طلاب فريدون لهم registered أو completed في عروض السنة/الفصل | قائمة الطلاب بمرشحات registered_year/semester |
| الطروحات | الصفوف التشغيلية المحفوظة في CourseOffering | السنة/الفصل الفعليان؛ قائمة exams |
| النتائج الرسمية | نتائج تسجيلات registered/completed لطلاب غير محذوفين، أحدث اعتماد للطرح approved | السنة/الفصل للطرح؛ نطاق برنامج الطالب الحالي كما في الوزارة؛ قائمة results |
| الخريجون | طلاب فريدون بقرار approved وmaterialized وغير superseded | سنة تاريخ الاعتماد؛ مع مرشح فصل يظهر غير متاح لأن القرار لا يثبت فصل تخرج |
| مقارنة الكليات | العد التجميعي الحالي من خدمة الكليات | لا يدعي تطبيق السنة/الفصل؛ أعداد المدرسين متداخلة ولا تجمع |
| اتجاه الالتحاق | تاريخ التحاق الطالب ضمن حدود كل سنة | جميع السنوات، نطاق الكلية/البرنامج الحالي؛ جدول بجوار الرسم |
| اتجاه التخرج | تاريخ اعتماد قرار التخرج النافذ | جميع السنوات؛ جدول بجوار الرسم |
| اتجاه النتائج | النتائج الرسمية حسب سنة وفصل الطرح | لا تاريخ «كما كانت العلامة حينها»؛ جدول بجوار الرسم |

غياب سنة حالية واحدة يجعل مؤشرات الفترة غير متاحة مع السبب، لا صفرًا مصطنعًا. يمكن اختيار سنة صراحة. اللقطة الحالية لا تتغير باختيار السنة. الساعة UTC من الخادم، والعرض بواسطة formatter المشروع. الاختبارات تقارن كل بطاقة ذات رابط مع `meta.total` تحت مرشحات عامة وكلية غير فعالة وبرنامج وسنة/فصل.

## جدول تغطية «تحتاج متابعتك» والقرارات

**صندوق إجراءات الرئيس: غير متاح مع السبب، وليس عداد صفر يدعي عدم وجود عمل.** لم يثبت مسار إحالة/إجراء للرئيس، لذلك لا POST أو اعتماد أو رفض ولا مهلة «متأخر». قائمة المتابعة منفصلة: تعرض الحالات المحفوظة وصاحب القرار، ولا تسميها طلبات تنتظر الرئيس.

| المصدر / خدمة التدقيق | الحالات | صاحب القرار الحالي | وصوله للرئيس في هذه المرحلة |
|---|---|---|---|
| teaching_assignment_requests / TeachingAssignmentWorkflowService | الحالة المخزنة لكل طلب وإجمالي كل حالة | العميد ثم العلمي والإداري | لا إحالة مثبتة؛ قراءة |
| semester_offering_requests / SemesterOfferingGovernanceService | draft/submitted/returned/approved | العميد ثم العلمي | قراءة |
| course_offering_closure_requests / CourseOfferingClosureWorkflowService | حالات الإغلاق المخزنة | العلمي والإداري | قراءة |
| course_offering_exception_requests / CourseOfferingExceptionWorkflowService | حالات الفتح الاستثنائي المخزنة | العلمي والإداري | قراءة |
| course_offering_minimum_enrollment_reviews / MinimumEnrollmentReviewService | satisfied/under_minimum/dean_recommended/continued_exceptionally/closure_pending/cancelled/superseded | توصية العميد ثم العلمي؛ الإلغاء عبر الإغلاق الثنائي | قراءة |
| grade_part_approvals / GradePartWorkflowService | draft/submitted/returned/approved | صلاحية المراجعة الحالية في هيئة الامتحانات | قراءة دون علامات المسودة |
| student_registration_requests / RegistrationRequestService | حالات الطلب الأولي | الطالب ثم المرشد | قراءة |
| student_registration_modification_requests / RegistrationModificationService | draft/submitted/returned/approved/expired/superseded | الطالب ثم المرشد | قراءة |
| student_registration_replacement_requests / RegistrationReplacementService | حالات طلب الاستبدال | الطالب ثم المرشد | قراءة |
| student_registration_withdrawal_requests / RegistrationWithdrawalService | حالات طلب الانسحاب | الطالب ثم المرشد | قراءة |
| student_progression_decisions / AcademicProgressionService | submitted/returned/approved/superseded | registration_officer + academic_progression.review | قراءة؛ لا نقل اختصاص |
| student_graduation_decisions / GraduationDecisionService | submitted/returned/approved/superseded | registration_officer + graduation_decisions.review | قراءة |
| academic_plan_versions / AcademicPlanWorkflow | مسودة/معتمد/مرجع انتقالي وفق الحالة المحفوظة | العلمي بصلاحية الخطط | قراءة |
| academic_calendar_event_versions / AcademicCalendarService | publication_status | العلمي + academic_calendar.manage | قراءة، لا مهل متابعة مختلقة |
| supplementary_exam_periods / SupplementaryExamPeriodGovernanceService | status | العلمي بصلاحية قرار الدورة | قراءة |
| supplementary_exam_grade_submissions / SupplementaryExamGradingService | status | الأستاذ وهيئة الامتحانات بصلاحيات التكميلي | قراءة، لا تعديل للتكميلي |
| student_disciplinary_cases / DisciplinaryCaseService | case_status + جهة/تاريخ القرار المسجلين | جهة القرار المسجلة؛ وجود اسم الرئيس في الحقل ليس مسار اعتماد | قراءة فقط؛ لا تحويل قرار سابق إلى طلب معلق |

عند غياب جدول/أعمدة مسار، يظهر غير متاح. الحالات الفعلية تُجمع من الصفوف لا من عدد مفترض. لا تسقط حالة غير مألوفة ولا تُحوَّل إلى «موافق».

فجوات إضافية راجعت مداخلها ولم أحولها إلى إجراءات للرئيس: `BoardDecisionController` و`GradeAppealController` CRUD عام، و`DisciplinaryCaseAppealController::decide` يقبل accepted/rejected عبر الخدمة الحالية؛ لا يوجد تحقق اختصاص/إحالة رئاسية يبرر إضافة زر هنا. كذلك تكليفات العمداء/الكوادر الإدارية ضمن `AdministrativeGovernance` بصلاحيات الإداري، لا تتحول إلى قرارات رئاسية. هذه المداخل ليست مضافة إلى صندوق أعمال الرئيس. أي تطوير لمسار رئاسي حقيقي يحتاج مواصفة وتفويضًا وتدقيقًا مستقلاً.

## SQL وترتيب الدمج والنشر

الحزمة `backend/database/sql/president-portal/` أربع ملفات، RBAC فقط، تستهدف `alrowad_uni_rust` صراحة. لا migrations أو seeders أو تغيير جداول الأعمال أو بياناتها أو فهارسها. لا SQL منفذ على الإنتاج.

1. راجع PR واختباراته ثم ادمجه بإجراء الفريق (لم يدمجه المنفذ).
2. نسخة احتياطية ونافذة صيانة لتعديل RBAC؛ تأكد أن الخادم يشير إلى القاعدة الصحيحة.
3. `00_preflight.sql`: المتوقع `OVERALL READY`. يتحقق من الدور الحالي وتعارض العلامة/الوحدة/الصلاحيات والروابط والمفاتيح الفريدة، ويعرض المرجع PRES والمنح السابقة. جداول RBAC الأساسية المنشورة متطلب سابق؛ إذا كانت مفقودة توقف عند خطأ المخطط ولا تنفذ apply.
4. `01_apply.sql`: يكرر الحراس داخله؛ ينشئ module وتسع صلاحيات قراءة ويربطها بدور الرئيس الموجود فقط، ذريًا داخل transaction. المتوقع `APPLIED_OR_ALREADY_APPLIED`، وإعادة التطبيق أو استكمال جزئي متوافق لا يكرر الصفوف. توقف عند أي خطأ SQL.
5. `02_verify.sql`: المتوقع `OVERALL PASS` وتسعة منح قراءة. OPERATOR_READINESS منفصل: صفر يعني أن الحساب/الدور/النطاق يحتاج تهيئة من الإدارة المخولة، وليس دعوة لإنشاء حساب تلقائيًا. لا تنشئ الحزمة حسابًا أو تمنح نطاقًا.
6. انشر الكود المتوافق وملفات `frontend/dist` كاملة مع أصول Cairo، وأعد تحميل cache/routes وفق إجراء النشر الحالي.
7. فحص ما بعد النشر بحساب اختبار مصرح: التوجيه إلى `/president`، رفض مدير النظام وحده/العميد/النائب/الوزارة، رفض الرئيس بلا النطاق أو صلاحية القسم، مطابقة الأرقام للقوائم، غياب نتائج مسودة/معادة، والتأكد أن لا كتابة في GET. راجع عينة بيانات فعلية بواسطة المشغّل؛ لا تفترض أن الاختبارات الاصطناعية أثبتتها.
8. rollback عند الحاجة: أوقف المرور للبوابة، راجع `03_rollback.sql` ثم طبقه. يحذف الروابط/الصلاحيات/module الموسومة فقط مع حراس ملكيتها. لا يحذف دور الرئيس أو حسابًا أو نطاقًا أو تاريخ أعمال. روابط أجنبية/عناصر غير موسومة تمنع rollback. المتوقع `ROLLED_BACK_OR_ABSENT` أو `BLOCKED`.

لا قرار أكاديمي أو إداري جديد، لا إدارة حسابات، لا نشر/اعتماد درجات، لا صلاحيات نيابة تلقائية.

## التحقق الفعلي والقيود

- Laravel/SQLite: `PresidentPortalTest`, `MinistryPortalTest`, `MinistryPortalSqlContractTest`: **26 اختبارًا / 2951 assertion ناجحة**. تشمل منع الصلاحية بلا الدور، الدور غير الفعال، إرجاع أحدث اعتماد وإخفاء النتيجة، وقرارات الترفيع المعتمدة والنافذة فقط. Fixture اصطناعي مشترك مستخرج كما هو إلى `tests/Concerns/MinistryReadFixture.php` دون إضعاف اختبارات الوزارة.
- Node: **243 اختبارًا ناجحًا**؛ اختبارات صلاحيات الرئيس وأولوية الدخول والمرشحات والعقد مصدرية/منطقية، لا تثبت React وحدها.
- React الإنتاجي على Chrome محلي: **26 زيارة صفحة** للأقسام والتفاصيل على 1440 و390، مع الفراغ والخطأ وإعادة المحاولة وتعارض العميد وتفصيل النتائج الرسمية ومنع الكتابة. الاستجابات مأخوذة من fixture Laravel/SQLite واعترضها الاختبار؛ **ليس تكامل HTTP حيًا مع Laravel**. تعذر المتصفح المدمج فاستُخدم Chrome مع profile اختبار منفصل.
- شاهد المنفذ لقطات سطح المكتب والهاتف وقارن مكونات الوزارة؛ لا ادعاء بقبول كل حالة بصرية أو كل شاشة. سكربت إعادة الفحص `frontend/tests/browser/president-portal.mjs` يحتاج artifact صادرًا فقط من اختبار SQLite عبر `PRESIDENT_BROWSER_FIXTURE` وChrome loopback المنفصل وVite preview.
- نجحت `php -l` لكل ملفات PHP المتغيرة والجديدة، والعقد المستقل `president_portal_contract.php`، وESLint للملفات المتغيرة والجديدة في الواجهة والاختبارات، وbuild، وComposer validate وplatform requirements، و`git diff --check`. تحذير build المعروف لحزمة أكبر من 500 kB ليس فشلًا ولم يُوسَّع النطاق لمعالجته. لم يُشغّل lint شاملًا لكل الملفات غير المرتبطة.
- لم تُنفذ حزمة SQL الجديدة على MariaDB، ولا فحص بيانات إنتاج، ولا browser/Laravel حي، ولا كامل PHPUnit للنظام. العقد الثابت لا يثبت تنفيذ SQL أو خطط الاستعلام على قاعدة الإنتاج؛ يلزم اختبار الحزمة على قاعدة اختبار معزولة قبل اعتماد النشر.
- لا تثبيت dependencies. البوابة **قراءة ومتابعة فقط**؛ اكتمال صلاحيات اتخاذ القرار غير مُدّعى.
