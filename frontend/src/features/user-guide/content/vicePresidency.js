import { PERMISSIONS, ROLES } from '../../auth/auth.js'
import { CATALOG_MANAGE } from '../../scientific-courses/catalog.js'
import { PROGRAM_ACCESS } from '../../scientific-programs/programs.js'
import { reportAccessForOffice } from '../../executive-reports/access.js'
import { ADMINISTRATIVE_ACCESS } from '../../vice-presidency/utils/administrativeAccess.js'
import { node, branch, source, step } from './helpers.js'

const V = 'frontend/src/features/vice-presidency/pages/'
const SCI = ROLES.vicePresidentScientific
const ADM = ROLES.vicePresidentAdministrative

const reviewAccess = office => office === 'scientific'
  ? { allRoles: [SCI], assignedPermissions: [PERMISSIONS.teachingAssignmentsReviewScientific] }
  : { allRoles: [ADM], assignedPermissions: [PERMISSIONS.teachingAssignmentsReviewAdministrative] }
const exceptionAccess = office => office === 'scientific'
  ? { allRoles: [SCI], assignedPermissions: [PERMISSIONS.exceptionalOpenReviewScientific] }
  : { allRoles: [ADM], assignedPermissions: [PERMISSIONS.exceptionalOpenReviewAdministrative] }

function parallelReviewTasks(office) {
  const base = `/vp/${office}`
  return [
    {
      id: 'teaching-assignments',
      title: 'مراجعة تكليفات المدرسين',
      summary: 'تراجع اقتراحات العمداء. المراجعتان العلمية والإدارية مستقلتان؛ يصبح التكليف نافذًا عند موافقة الاثنين.',
      access: reviewAccess(office),
      link: { to: `${base}/teaching-assignments` },
      steps: [
        step('افتح «تكليفات المدرسين»؛ القائمة الافتراضية «بانتظار مراجعتي».'),
        step('اضغط «مراجعة» لفتح الطلب.'),
        step('راجع نوع الطلب وسببه وبيانات المدرس المقترح والنافذ ومراجعة النائب الآخر، ثم اقرأ «الأثر المتوقع».'),
        step('اضغط زر «موافقة» الخاص بمكتبك على النسخة المعروضة، أو «إعادة للعميد» واكتب سبب الإعادة ثم «تأكيد الإعادة».'),
        step('إذا ظهر أن الطلب تغيّر أو اعتُمد أو استُبدل، تُعاد الصفحة تحميل الحالة من الخادم؛ راجع النسخة الحالية ثم قرّر.'),
      ],
      flows: [{
        title: 'مسار مراجعة التكليف التدريسي',
        nodes: [
          node('submitted', 'start', 'اقتراح مرسل من العميد', 'يصل الطلب بانتظار مراجعتك ومراجعة النائب الآخر.'),
          node('review', 'review', 'مراجعتك (مستقلة عن مراجعة النائب الآخر)', 'لا يمكن لشخص واحد إعطاء الموافقتين.', [branch('عند الموافقة', 'waiting'), branch('عند الإعادة مع سبب', 'returned')]),
          node('returned', 'return', 'معاد للعميد', 'يعدّل العميد الطلب ويعيد إرساله؛ تُعاد للمراجعة فقط المراجعة التي أرجعته.', [branch('بعد إعادة الإرسال', 'review')]),
          node('waiting', 'system', 'انتظار موافقة النائب الآخر', 'يبقى الطلب مرسلًا حتى تكتمل الموافقتان.'),
          node('approved', 'end', 'معتمد — التكليف نافذ', 'عند موافقة الاثنين يُطبق التكليف على الطرح.'),
        ],
      }],
      sources: [
        source(V + 'TeachingAssignmentQueue.jsx', 'بانتظار مراجعتي', 'مراجعة'),
        source(V + 'TeachingAssignmentDetail.jsx', 'موافقة', 'إعادة للعميد', 'تأكيد الإعادة', 'الأثر المتوقع', 'expected_submission_version'),
        source('backend/app/Support/TeachingAssignmentWorkflow.php', "'submitted'", "'returned'", "'approved'", "'pending'"),
      ],
    },
    {
      id: 'exceptional-openings',
      title: 'مراجعة طلبات الفتح الاستثنائي',
      summary: 'يُفتح الطرح استثنائيًا فقط بعد موافقة النائبين. عند إعادة الإرسال يراجع الاثنان الطلب من جديد.',
      access: exceptionAccess(office),
      link: { to: `${base}/exceptional-openings` },
      steps: [
        step('افتح «الفتح الاستثنائي» واختر الطلب.'),
        step('اضغط «موافقة»، أو «إعادة للعميد» مع السبب ثم «تأكيد الإعادة».'),
      ],
      flows: [{
        title: 'مسار الفتح الاستثنائي',
        nodes: [
          node('submitted', 'start', 'طلب مرسل من العميد', 'يحتاج موافقة النائب العلمي والنائب الإداري.'),
          node('review', 'review', 'مراجعة النائبين', 'كل نائب يراجع بشكل مستقل.', [branch('إذا وافق الاثنان', 'open'), branch('إذا أعاده أي منهما', 'returned')]),
          node('returned', 'return', 'معاد للعميد', 'بعد إعادة الإرسال يراجع الاثنان الطلب من جديد.', [branch('بعد إعادة الإرسال', 'review')]),
          node('open', 'system', 'فتح الطرح أو تجاوز الطلب', 'يُفتح الطرح تلقائيًا، إلا إذا لم يعد الطلب لازمًا (فُتح الطرح أو تغيّر أو اكتمل تكليفه) فيصبح «متجاوزًا».'),
          node('done', 'end', 'انتهاء الطلب', 'تظهر النتيجة في تفاصيل الطلب.'),
        ],
      }],
      sources: [
        source(V + 'ExceptionalOpeningDetail.jsx', 'موافقة', 'إعادة للعميد', 'تأكيد الإعادة'),
        source('backend/app/Support/ExceptionalOpeningWorkflow.php', "'submitted'", "'returned'", "'approved'", "'superseded'"),
      ],
    },
  ]
}

const reportsTask = office => ({
  id: 'reports',
  title: 'التقارير والإحصاءات',
  summary: 'منشئ تقارير للقراءة على مستوى الجامعة.',
  access: reportAccessForOffice(office),
  link: { to: `/vp/${office}/reports` },
  steps: [step('اختر نوع التقرير والمرشحات خطوة بخطوة ثم اضغط «إنشاء التقرير».')],
  sources: [source('frontend/src/features/executive-reports/pages/ExecutiveReportsPage.jsx', 'إنشاء التقرير')],
})

const SEMESTER = { allRoles: [SCI], assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView, PERMISSIONS.semesterOfferingGovernanceReviewScientific], actualUniversityScope: true }

export const scientific = {
  id: 'vpScientific',
  title: 'نيابة الشؤون العلمية',
  intro: 'تعتمد من هذه البوابة طروحات الفصل التي يرسلها العمداء، وتقرر في الطروحات دون الحد الأدنى، وتراجع تكليفات المدرسين وطلبات الفتح الاستثنائي، وتعلن الدورات التكميلية، وتدير دليل المواد والبرامج والخطط والتقويم الأكاديمي.',
  sections: [
    {
      id: 'offerings',
      title: 'طروحات الفصل',
      tasks: [
        {
          id: 'semester-offerings',
          title: 'اعتماد طروحات الفصل أو إعادتها',
          summary: 'الاعتماد يفتح الطرح للتسجيل؛ الإعادة ترجعه للعميد مع سبب.',
          access: SEMESTER,
          link: { to: '/vp/scientific/semester-offerings' },
          steps: [
            step('افتح «اعتماد الطروحات الفصلية» واختر طرحًا بانتظار المراجعة.'),
            step('اضغط «اعتماد»، أو «إعادة للتعديل» واكتب سبب الإعادة ثم «تأكيد الإعادة».'),
          ],
          flows: [{
            title: 'مسار اعتماد الطرح',
            nodes: [
              node('submitted', 'start', 'طرح مرسل من العميد', 'يصل الطرح بحالة «بانتظار اعتماد النائب العلمي».'),
              node('review', 'review', 'مراجعتك', 'يُعاد التحقق من الخطة ونوع المادة عند الاعتماد.', [branch('عند الاعتماد', 'approved'), branch('عند الإعادة مع سبب', 'returned')]),
              node('returned', 'return', 'معاد للعميد للتعديل', 'يعدّل العميد ويعيد الإرسال.', [branch('بعد إعادة الإرسال', 'submitted')]),
              node('approved', 'end', 'معتمد — يُفتح الطرح', 'يُفتح الطرح للتسجيل تلقائيًا.'),
            ],
          }],
          sources: [
            source(V + 'SemesterOfferingDetail.jsx', 'اعتماد', 'إعادة للتعديل', 'سبب الإعادة', 'تأكيد الإعادة'),
            source('backend/app/Support/SemesterOfferingGovernance.php', "'submitted'", "'returned'", "'approved'"),
          ],
        },
        {
          id: 'minimum-enrollment',
          title: 'قرارات الطروحات دون الحد الأدنى',
          summary: 'تقرر في الطروحات التي أوصى بها العميد: استمرار استثنائي أو بدء الإلغاء الرسمي. يلزم سبب لا يقل عن 8 أحرف.',
          access: SEMESTER,
          link: { to: '/vp/scientific/semester-offerings/minimum-enrollment' },
          steps: [
            step('اختر السنة والفصل لعرض الطروحات التي أوصى بها العميد.'),
            step('اكتب «سبب القرار العلمي» ثم اختر «استمرار استثنائي» أو «بدء الإلغاء الرسمي».'),
          ],
          flows: [{
            title: 'مسار قرار الحد الأدنى',
            nodes: [
              node('under', 'start', 'طرح دون الحد الأدنى', 'يرصد النظام الطرح دون الحد الأدنى للتسجيل.'),
              node('dean', 'other', 'توصية العميد', 'يوصي العميد بالاستمرار أو الإلغاء.'),
              node('decide', 'review', 'قرارك', 'تقرر مع كتابة السبب.', [branch('استمرار استثنائي', 'continued'), branch('بدء الإلغاء الرسمي', 'closure')]),
              { ...node('closure', 'system', 'إنشاء طلب إغلاق (بانتظار الإغلاق)', 'تُلغى التسجيلات فقط بعد اعتماد الإغلاق؛ شاشة إكمال اعتماد الإغلاق غير مفعّلة حاليًا.'), terminal: true },
              node('continued', 'end', 'استمرار استثنائي', 'يستمر الطرح رغم انخفاض العدد.'),
            ],
          }],
          sources: [
            source(V + 'MinimumEnrollmentQueue.jsx', 'استمرار استثنائي', 'بدء الإلغاء الرسمي', 'dean_recommended'),
            source('backend/app/Services/MinimumEnrollmentReviewService.php', 'dean_recommended', 'continued_exceptionally', 'closure_pending'),
          ],
        },
      ],
    },
    { id: 'reviews', title: 'المراجعات المشتركة مع النيابة الإدارية', tasks: parallelReviewTasks('scientific') },
    {
      id: 'catalog',
      title: 'المواد والبرامج',
      tasks: [
        {
          id: 'course-catalog',
          title: 'إدارة دليل المواد',
          summary: 'إضافة المواد وتعديلها وتصنيفها ضمن البرامج مباشرة دون مرحلة اعتماد. إذا عدّل غيرك المادة في الوقت نفسه تظهر رسالة تعارض (409).',
          access: CATALOG_MANAGE,
          link: { to: '/vp/scientific/courses' },
          steps: [
            step('افتح «إدارة المواد» وابحث عن المادة أو اضغط «إضافة مادة».'),
            step('عدّل البيانات أو التصنيف ثم احفظ. عند ظهور تعارض حدّث الصفحة وراجع التعديلات قبل إعادة الحفظ.'),
          ],
          sources: [source('frontend/src/features/scientific-courses/ScientificCoursesPage.jsx', 'إضافة مادة')],
        },
        {
          id: 'programs',
          title: 'البرامج والخطط الأكاديمية',
          summary: 'تظهر أزرار الخطة بحسب صلاحياتك: إنشاء نسخة للتعديل، اعتمادها، ثم تعيينها للطلاب الجدد.',
          access: PROGRAM_ACCESS,
          link: { to: '/vp/scientific/programs' },
          steps: [
            step('افتح البرنامج ثم «إنشاء نسخة للتعديل» من خطة معتمدة أو انتقالية.', { access: { assignedPermissions: ['vice_presidency.scientific.programs.plans.manage'] } }),
            step('عدّل المسودة ثم «اعتماد الخطة»؛ بعد الاعتماد تُثبّت ولا تُعدّل.', { access: { assignedPermissions: ['vice_presidency.scientific.programs.plans.approve'] } }),
            step('«تعيين للطلاب الجدد» أو نقل الطلاب إجراء مستقل على خطة معتمدة.', { access: { assignedPermissions: ['vice_presidency.scientific.programs.plans.assign'] } }),
            step('بدون هذه الصلاحيات تعرض الصفحة البرامج وخططها للقراءة فقط.'),
          ],
          flows: [{
            title: 'مسار خطة البرنامج',
            nodes: [
              node('copy', 'start', 'إنشاء نسخة للتعديل', 'تُنشأ مسودة من خطة معتمدة أو انتقالية.'),
              node('edit', 'action', 'تعديل المسودة', 'إضافة المواد والتصنيف ومتطلبات التخرج.'),
              node('approve', 'action', 'اعتماد الخطة', 'تُثبّت الخطة ولا يمكن تعديلها بعد ذلك؛ لا توجد مراجعة من جهة أخرى.'),
              node('assign', 'end', 'التعيين للطلاب الجدد', 'تصبح الخطة المعتمدة خطة الطلاب الجدد.'),
            ],
          }],
          sources: [
            source('frontend/src/features/scientific-programs/ScientificProgramsPage.jsx', 'إنشاء نسخة للتعديل', 'اعتماد الخطة', 'تعيين للطلاب الجدد'),
            source('frontend/src/features/scientific-programs/programs.js', 'مسودة للتعديل', 'خطة معتمدة'),
          ],
        },
      ],
    },
    {
      id: 'other',
      title: 'الدورات التكميلية والتقويم والتقارير',
      tasks: [
        {
          id: 'supplementary-periods',
          title: 'إعلان دورة تكميلية',
          summary: 'دورة واحدة لكل سنة وفصل. بعد الإعلان يتولى مكتب التسجيل فتح التسجيل.',
          access: { allRoles: [SCI], assignedPermissions: [PERMISSIONS.supplementaryExamsPeriodsView, PERMISSIONS.supplementaryExamsPeriodsDecide] },
          link: { to: '/vp/scientific/supplementary-exams' },
          steps: [step('اضغط «فتح دورة تكميلية»، أدخل الاسم وتاريخي البداية والنهاية وملاحظة القرار، ثم «اعتماد فتح الدورة».')],
          sources: [
            source(V + 'SupplementaryExamPeriods.jsx', 'فتح دورة تكميلية', 'اعتماد فتح الدورة'),
            source('backend/app/Support/SupplementaryExamPeriodGovernance.php', 'announced'),
          ],
        },
        {
          id: 'calendar',
          title: 'نشر أحداث التقويم الأكاديمي',
          summary: 'تنشئ الحدث مسودة ثم تنشره؛ تعديل حدث منشور يتم بمسودة بديلة تُنشر لاحقًا.',
          access: { allRoles: [SCI], assignedPermissions: [PERMISSIONS.academicCalendarManage] },
          link: { to: '/vp/scientific/calendar' },
          steps: [
            step('اضغط «حدث جديد» واحفظ المسودة.'),
            step('راجع المسودة ثم «نشر». لتعديل حدث منشور أنشئ مسودة بديلة ثم انشرها.'),
          ],
          sources: [source('frontend/src/features/academic-calendar/AcademicCalendarPage.jsx', 'حدث جديد', 'تعديل المسودة', 'نشر')],
        },
        reportsTask('scientific'),
      ],
    },
  ],
  unavailable: [
    { id: 'closure-review', title: 'الموافقة العلمية على إغلاق الشعب', text: 'مدرجة في الصفحة الرئيسية كقسم لاحق غير مفعّل في هذه المرحلة.', source: source(V + 'VicePresidentShell.jsx', 'الموافقة العلمية على إغلاق الشعب', 'غير مفعّلة في هذه المرحلة') },
  ],
  troubleshooting: [
    { id: 'buttons-403', title: 'ظهور 403 عند الموافقة أو الإعادة', who: 'admin', steps: ['الخادم يطلب دور النائب نفسه مع صلاحية المراجعة المسندة فعليًا والنطاق المناسب؛ صلاحيات مدير النظام وحدها لا تكفي لهذه القرارات.'] },
  ],
}

const FACULTY_PAGE = V + 'AdministrativeFacultyPage.jsx'
const DEANS_PAGE = V + 'AdministrativeDeansPage.jsx'

const governanceTasks = [
  {
    id: 'home-indicators',
    title: 'قراءة مؤشرات الصفحة الرئيسية',
    summary: 'أعداد الطلاب والمدرسين النشطين وطلبات التكليف (بانتظار مراجعتك، معادة، معتمدة) لإجمالي الجامعة وحسب الكلية.',
    access: ADMINISTRATIVE_ACCESS.dashboard,
    link: { to: '/vp/administrative' },
    steps: [
      step('افتح «الرئيسية» واختر عند الحاجة السنة والفصل والكلية.'),
      step('السنة والفصل يُطبّقان على التكليفات فقط؛ الطلاب والمدرسون لقطة حالية.'),
      step('اضغط بطاقة تكليفات أو رقمًا في جدول الكليات لفتح قائمة التكليفات بالتصفية نفسها.'),
      step('عبارة «غير متاح» تعني أن البيانات لا يمكن حسابها لحسابك أو للنظام، وليست صفرًا.'),
    ],
    sources: [source('frontend/src/features/vice-presidency/components/AdministrativeDashboard.jsx', 'إجمالي الجامعة', 'التوزيع حسب الكلية', 'غير متاح')],
  },
  {
    id: 'faculty-view',
    title: 'عرض المدرسين وانتمائهم إلى الكليات',
    summary: 'قائمة الملفات التدريسية مع الكليات المنتسب إليها كل مدرس وعدد تكليفاته النافذة.',
    access: ADMINISTRATIVE_ACCESS.facultyView,
    link: { to: '/vp/administrative/faculty' },
    steps: [
      step('افتح «إدارة المدرسين» وابحث بالاسم أو رقم الموظف، أو صفِّ حسب الكلية («بلا انتماء لكلية» متاح).'),
      step('اضغط زر العرض لفتح الملف: الانتماء الحالي، سجل الانتماء، والتكليفات النافذة للاطلاع.'),
    ],
    sources: [source(FACULTY_PAGE, 'إدارة المدرسين', 'بلا انتماء لكلية', 'سجل الانتماء')],
  },
  {
    id: 'faculty-manage',
    title: 'إضافة مدرس وتغيير انتمائه',
    summary: 'إنشاء ملف تدريسي أو ربط موظف قائم، ثم إسناده إلى كلية أو نقله أو إنهاء انتمائه مع حفظ السجل. لا يُنشأ تكليف بمادة ولا حساب دخول.',
    access: ADMINISTRATIVE_ACCESS.facultyManage,
    link: { to: '/vp/administrative/faculty' },
    steps: [
      step('اضغط «إضافة مدرس» واختر «موظف جديد + ملف تدريسي» أو «ربط موظف قائم».'),
      step('في الربط: أدخل رقم الموظف واضغط البحث، ثم أدخل الكنية للتحقق.'),
      step('اختر الكلية إن أردت، ثم «حفظ الملف التدريسي».'),
      step('لتغيير الانتماء: افتح الملف، اختر العملية في «تغيير الانتماء» ثم «نقل الانتماء» أو «إسناد الانتماء» أو «إنهاء الانتماء» وأكّد.'),
    ],
    flows: [{
      title: 'من الملف التدريسي إلى ظهوره لدى العميد',
      nodes: [
        node('profile', 'start', 'ملف تدريسي نشط لموظف نشط'),
        node('affiliate', 'action', 'إسناد الانتماء إلى كلية', 'يُسجَّل إسناد وحدة بتاريخ بدء؛ النقل يغلق الإسناد السابق ولا يحذفه.', [branch('بعد الإسناد', 'dean')]),
        node('dean', 'system', 'يظهر في قائمة مدرسي الكلية لدى عميدها', 'ويظهر أولًا بعلامة «من كلية الطرح» عند اختيار مدرس لطرح من الكلية.'),
        node('assignment', 'end', 'التكليف بمادة مسار مستقل', 'يقترحه العميد ويعتمده النائبان؛ لا يتغير التكليف النافذ بتغيير الانتماء.'),
      ],
    }],
    sources: [
      source(FACULTY_PAGE, 'إضافة مدرس', 'ربط موظف قائم', 'حفظ الملف التدريسي', 'نقل الانتماء', 'إنهاء الانتماء'),
      source('backend/app/Services/AdministrativeFacultyService.php', 'faculty.affiliation_', 'NOTE_TAG'),
    ],
  },
  {
    id: 'deans-view',
    title: 'عرض عمداء الكليات',
    summary: 'كل كلية مع عميدها النشط وتاريخ بدء منصبه، وتنبيه عند غياب العميد أو تعدده.',
    access: ADMINISTRATIVE_ACCESS.deansView,
    link: { to: '/vp/administrative/deans' },
    steps: [step('افتح «عمداء الكليات» لعرض الكليات وعمدائها.')],
    sources: [source(DEANS_PAGE, 'عمداء الكليات', 'العميد الحالي')],
  },
  {
    id: 'deans-manage',
    title: 'تعيين عميد أو نقله أو إنهاء تكليفه',
    summary: 'يمنح المسار دور العميد ونطاق كلية واحدة فقط مع ربط الحساب بالموظف. النقل والإنهاء يسحبان نطاق الكلية السابقة ويبقيان الحساب والسجل.',
    access: ADMINISTRATIVE_ACCESS.deansManage,
    link: { to: '/vp/administrative/deans' },
    steps: [
      step('اضغط «تعيين» (أو «استبدال») بجانب الكلية.'),
      step('حدّد سجل الموظف (جديد أو قائم بالرقم والكنية) وحساب الدخول (جديد بكلمة مرور قوية أو ربط حساب قائم قابل للربط).'),
      step('إن كان للكلية عميد فأكّد استبداله صراحة، ثم أكّد التحقق من الهوية واضغط «تعيين العميد».'),
      step('للنقل اضغط «نقل» واختر الكلية الجديدة؛ للإنهاء اضغط «إنهاء» وأكّد.'),
    ],
    flows: [{
      title: 'مسار تكليف العميد',
      nodes: [
        node('college', 'start', 'كلية مفعّلة مرتبطة بوحدة تنظيمية', 'بلا عميد، أو بعميد حالي يلزم تأكيد استبداله صراحة.', [branch('بعد التحقق من الهوية', 'appoint')]),
        node('appoint', 'action', 'تعيين عميد لكلية', 'دور العميد + نطاق هذه الكلية فقط + منصب العميد بتاريخ البدء.', [branch('عند النقل', 'transfer'), branch('عند الإنهاء', 'end')]),
        node('transfer', 'action', 'نقل إلى كلية أخرى', 'يُسحب نطاق الكلية السابقة ويُغلق منصبها في العملية نفسها.', [branch('لاحقًا', 'end')]),
        node('end', 'end', 'إنهاء التكليف', 'يُسحب النطاق والدور (إن لم يبقَ عميدًا لكلية أخرى) ويبقى الحساب وسجله.'),
      ],
    }],
    notes: [{ text: 'لا يعدّل هذا المسار حساب مدير النظام، ولا الحسابات ذات نطاق الجامعة أو الأدوار الأخرى، ولا حسابك الشخصي.' }],
    sources: [
      source(DEANS_PAGE, 'تعيين العميد', 'نقل', 'إنهاء'),
      source('backend/app/Services/AdministrativeDeanService.php', 'dean.appointed', 'dean.transferred', 'dean.ended', 'protected_account'),
    ],
  },
]

export const administrative = {
  id: 'vpAdministrative',
  title: 'نيابة الشؤون الإدارية',
  intro: 'تراجع من هذه البوابة تكليفات المدرسين وطلبات الفتح الاستثنائي بالتوازي مع نيابة الشؤون العلمية، وتتابع المؤشرات الإدارية، وتدير ملفات المدرسين وانتماءهم وعمداء الكليات ضمن صلاحياتك، وتطّلع على التقارير والتقويم الأكاديمي.',
  sections: [
    { id: 'reviews', title: 'المراجعات المشتركة مع النيابة العلمية', tasks: parallelReviewTasks('administrative') },
    { id: 'governance', title: 'المؤشرات والمدرسون وعمداء الكليات', tasks: governanceTasks },
    {
      id: 'other',
      title: 'التقارير والتقويم',
      tasks: [
        reportsTask('administrative'),
        {
          id: 'calendar',
          title: 'الاطلاع على التقويم الأكاديمي',
          summary: 'عرض الأحداث المنشورة للقراءة؛ إدارة التقويم من صلاحيات النيابة العلمية.',
          link: { to: '/vp/administrative/calendar' },
          steps: [step('افتح «التقويم الأكاديمي» لعرض الأحداث المنشورة.')],
          sources: [source('backend/app/Services/AcademicCalendarService.php', 'published')],
        },
      ],
    },
  ],
  unavailable: [
    { id: 'closure-review', title: 'مراجعة طلبات إغلاق الشعب', text: 'لا توجد حاليًا شاشة في هذه البوابة لمراجعة طلبات الإغلاق.' },
  ],
  troubleshooting: [
    { id: 'buttons-403', title: 'ظهور 403 عند الموافقة أو الإعادة', who: 'admin', steps: ['الخادم يطلب دور النائب نفسه مع صلاحية المراجعة المسندة فعليًا؛ راجع مسؤول الحسابات إذا كنت مكلفًا بالمراجعة.'] },
  ],
}
