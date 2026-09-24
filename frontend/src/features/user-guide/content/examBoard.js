import { ACCESS, PERMISSIONS } from '../../auth/auth.js'
import { node, branch, source, step } from './helpers.js'

const EB = 'frontend/src/features/exam-board/'
const BOARD = { allPermissions: ['exams.view', 'exams.manage'] }
const both = extra => ({ ...extra, allPermissions: [...BOARD.allPermissions, ...(extra.allPermissions ?? [])] })

export default {
  id: 'examBoard',
  title: 'هيئة الامتحانات والقبول والتسجيل',
  intro: 'تجمع هذه القائمة أعمال هيئة الامتحانات (كشوف الدرجات، اعتماد العلامات، الحرمان، الامتحانات التكميلية، المواد)، وإدخال العلامات اليدوي لموظفي الامتحانات، وصفحات القبول والتسجيل. يظهر لك من هذا الدليل ما يخص صلاحياتك فقط.',
  titles: [
    { access: BOARD, title: 'هيئة الامتحانات' },
    { access: ACCESS.courseRegistration, title: 'القبول والتسجيل' },
    { access: ACCESS.manualGradeEntry, title: 'هيئة الامتحانات' },
  ],
  sections: [
    {
      id: 'board',
      title: 'هيئة الامتحانات',
      access: BOARD,
      tasks: [
        {
          id: 'approvals',
          title: 'اعتماد أجزاء العلامات أو إعادتها للتصحيح',
          summary: 'تراجع الهيئة الأجزاء النظرية والعملية التي أرسلها المدرسون أو موظف الإدخال اليدوي. اعتماد كل الأجزاء يجعل النتيجة رسمية.',
          link: { to: '/exam-board/approvals' },
          steps: [
            step('افتح «اعتماد الدرجات». القائمة تعرض افتراضيًا الأجزاء المرسلة، ويمكن التصفية حسب نوع الجزء.'),
            step('اضغط «مراجعة الجزء» لعرض العلامات.'),
            step('للاعتماد: «اعتماد الجزء» ثم «تأكيد الاعتماد» (الملاحظات اختيارية).'),
            step('للإعادة: «إعادة الجزء للتصحيح» واكتب السبب ثم «تأكيد الإعادة».'),
          ],
          flows: [{
            title: 'مسار اعتماد جزء العلامة',
            nodes: [
              node('submitted', 'start', 'جزء مرسل من المدرس أو الإدخال اليدوي', 'يصل الجزء إلى قائمة الاعتماد بحالة «مرسل».'),
              node('review', 'review', 'مراجعة الهيئة', 'تراجع العلامات ضمن نطاقك.', [branch('عند الاعتماد', 'approved'), branch('عند الإعادة مع سبب', 'returned')]),
              node('returned', 'return', 'معاد للتصحيح', 'يعود الجزء إلى المدرّس ليعدّله ويعيد إرساله.', [branch('إعادة الإرسال', 'submitted')]),
              node('approved', 'end', 'معتمد', 'عند اعتماد جميع الأجزاء المطلوبة تُنشأ النتيجة الرسمية وتظهر للطالب.'),
            ],
          }],
          sources: [
            source(EB + 'pages/ApprovalsPage.jsx', 'مراجعة الجزء', 'اعتماد الجزء', 'إعادة الجزء للتصحيح', 'تأكيد الاعتماد', 'تأكيد الإعادة'),
            source('backend/app/Services/GradePartWorkflowService.php', 'submitted', 'returned', 'approved', 'examination_committee'),
          ],
        },
        {
          id: 'grade-sheet',
          title: 'عرض السجل الأكاديمي للطالب واستخراج الكشف',
          summary: 'صفحة للقراءة تعرض الدرجات المعتمدة والتقدم في الخطة، مع تنزيل الكشف بصيغة PDF.',
          access: both({ permissions: ['grades.view'] }),
          link: { to: '/exam-board/grade-sheet' },
          steps: [
            step('افتح «كشوف الدرجات» وابحث عن الطالب ثم اختره.'),
            step('راجع الملخص الأكاديمي وسجل الدرجات المعتمدة.'),
            step('اضغط «استخراج كشف العلامات الإلكتروني» لتنزيل الكشف.'),
          ],
          sources: [
            source(EB + 'pages/GradeSheetPage.jsx', 'البحث في السجلات الأكاديمية'),
            source('frontend/src/features/academic-record/components/TranscriptPdfExportAction.jsx', 'استخراج كشف العلامات الإلكتروني'),
          ],
        },
        {
          id: 'deprivation-view',
          title: 'متابعة الحضور والحرمان',
          summary: 'صفحة للقراءة تعرض نسب حضور الطالب وشارات «محروم» و«تحذير غياب». تطبيق الحرمان على مادة يتم من صفحة حضور المادة في بوابة الأستاذ لمن يملك صلاحية الهيئة.',
          link: { to: '/exam-board/deprivation' },
          steps: [step('افتح «الحضور والحرمان» واختر الطالب لعرض نسب الحضور والغياب.')],
          sources: [source(EB + 'pages/DeprivationPage.jsx', 'محروم', 'تحذير غياب')],
        },
        {
          id: 'supplementary-overview',
          title: 'نظرة عامة على الامتحانات التكميلية',
          summary: 'صفحة للاطلاع على الدورة والمقررات والطلاب المسجلين.',
          access: both({ permissions: [PERMISSIONS.supplementaryExamsRegistrationsView] }),
          link: { to: '/exam-board/supplementary' },
          steps: [step('افتح «الامتحانات التكميلية» واختر الدورة لعرض المقررات والطلاب المسجلين.')],
          sources: [source(EB + 'pages/SupplementaryExamsPage.jsx', 'إدارة الدرجات التكميلية')],
        },
        {
          id: 'supplementary-grades',
          title: 'إدارة علامات الامتحانات التكميلية',
          summary: 'لموظف الامتحانات المخوّل. كل زر يظهر فقط عندما تسمح به حالة الدورة وصلاحيتك.',
          access: both({ allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review'] }),
          link: { to: '/exam-board/supplementary-grades' },
          steps: [
            step('اختر الدورة وراجع تقرير المطابقة.'),
            step('بعد إغلاق التسجيل اضغط «تثبيت القائمة وفتح العلامات».'),
            step('لكل مقرر اختر المصحح ثم «حفظ الإسناد».'),
            step('بعد إرسال المدرّس: «اعتماد» أو «إرجاع مع سبب».'),
            step('بعد الاعتماد «نشر»، ثم «ترحيل إلى السجل الرسمي» لمن يملك صلاحية الترحيل. تحقق من نتيجة كل عملية قبل تكرارها.'),
          ],
          flows: [{
            title: 'مسار علامات الدورة التكميلية',
            nodes: [
              node('closed', 'start', 'إغلاق التسجيل من مكتب التسجيل', 'تبدأ بعد تثبيت قائمة المسجلين.'),
              node('open', 'action', 'تثبيت القائمة وفتح العلامات', 'تنتقل الدورة إلى مرحلة التصحيح.'),
              node('assign', 'action', 'إسناد المصحح لكل مقرر', 'يظهر المقرر في بوابة الأستاذ المسند إليه.'),
              node('review', 'review', 'مراجعة الدفعة المرسلة', 'تراجع الدفعة التي أرسلها المصحح.', [branch('عند الاعتماد', 'publish'), branch('عند الإرجاع مع سبب', 'returned')]),
              node('returned', 'return', 'إعادة الدفعة للمصحح', 'يعدّل المصحح ويعيد الإرسال فتعود للمراجعة.', [branch('بعد إعادة الإرسال', 'review')]),
              node('publish', 'action', 'نشر النتائج', 'يرى الطالب النتيجة المنشورة قبل تحديث سجله الرسمي.'),
              node('materialize', 'end', 'ترحيل إلى السجل الرسمي', 'تصبح النتيجة جزءًا من السجل الأكاديمي الرسمي.'),
            ],
          }],
          sources: [
            source(EB + 'pages/SupplementaryGradesPage.jsx', 'تثبيت القائمة وفتح العلامات', 'حفظ الإسناد', 'إرجاع مع سبب', 'نشر', 'ترحيل إلى السجل الرسمي'),
            source('frontend/src/features/supplementary-exams/supplementaryStatus.js', 'registration_closed', 'grading_open', 'grading_submitted', 'results_approved', 'results_published', 'results_materialized'),
          ],
        },
      ],
    },
    {
      id: 'manual',
      title: 'إدخال العلامات اليدوي',
      access: ACCESS.manualGradeEntry,
      tasks: [
        {
          id: 'manual-grade-entry',
          title: 'إدخال علامات طالب يدويًا وإرسالها للاعتماد',
          summary: 'مسار خاص لموظف الامتحانات. لا يمنح بقية وظائف هيئة الامتحانات؛ الحفظ لا يعني النشر، والإرسال يشمل جزء الطرح كله لا الطالب وحده.',
          link: { to: '/exam-board/manual-grade-entry' },
          steps: [
            step('ابحث عن الطالب باسمه أو رقمه ثم اختره.'),
            step('اختر سنة العلامات وفصلها، ثم المقرر المطلوب.'),
            step('لمقرر غير مجهّز: أدخل العلامات ثم «مراجعة وحفظ العلامات»، واكتب «سبب الإدخال أو التصحيح» وأكّد.'),
            step('لمقرر مسجل: عدّل العلامات، وأقرّ بصحتها، ثم «حفظ العلامات كمسودة» (تصحيح علامة موجودة يتطلب سببًا).'),
            step('عند الاكتمال اضغط «إرسال الجزء …» وراجع ملخص الجاهزية في نافذة «تأكيد إرسال جزء الطرح بالكامل» قبل التأكيد.'),
          ],
          flows: [{
            title: 'مسار الإدخال اليدوي',
            nodes: [
              node('search', 'start', 'البحث عن الطالب', 'تبدأ من شاشة البحث في «إدخال العلامات اليدوي».'),
              node('draft', 'action', 'إدخال العلامات وحفظها مسودة مع السبب', 'تُحفظ العلامات مسودة؛ لا تُنشر ولا تظهر للطالب.'),
              node('submit', 'action', 'إرسال جزء الطرح بالكامل', 'يُرسل الجزء لكل طلاب الطرح بعد فحص الجاهزية.'),
              node('review', 'review', 'اعتماد الدرجات في هيئة الامتحانات', 'يدخل الجزء مسار الاعتماد نفسه.', [branch('عند الاعتماد', 'official'), branch('عند الإعادة للتصحيح', 'draft')]),
              node('official', 'end', 'نتيجة رسمية بعد اعتماد كل الأجزاء', 'يُقفل الجزء المعتمد أمام التعديل.'),
            ],
          }],
          sources: [
            source(EB + 'components/PreparationGradeRow.jsx', 'مراجعة وحفظ العلامات', 'سبب الإدخال أو التصحيح'),
            source(EB + 'components/RegistrationGridRow.jsx', 'حفظ العلامات كمسودة', 'تأكيد إرسال جزء الطرح بالكامل', 'إرسال الجزء'),
          ],
        },
      ],
    },
    {
      id: 'courses',
      title: 'المواد',
      access: { allPermissions: [...BOARD.allPermissions, ...ACCESS.courseManagement.allPermissions] },
      tasks: [
        {
          id: 'course-catalog',
          title: 'الاطلاع على الطروحات وجدول المواد',
          summary: 'صفحات للقراءة مع تصفية وتنزيل جدول المواد. تغيير المدرسين الفعليين لا يتم من هنا بل عبر مسار التكليف لدى العميد والنائبين.',
          link: { to: '/exam-board/course-offerings' },
          steps: [
            step('«الطروحات الأكاديمية»: صفِّ حسب السنة والفصل والكلية والقسم والبرنامج.', { link: { to: '/exam-board/course-offerings' } }),
            step('«جدول المواد»: صفِّ ثم نزّل الجدول.', { link: { to: '/exam-board/course-table' } }),
          ],
          sources: [source(EB + 'pages/CourseTablePage.jsx', 'تنزيل')],
        },
        {
          id: 'courses-manage',
          title: 'إدارة المواد الدراسية',
          summary: 'إضافة المواد وتعديلها وحذفها تتطلب صلاحية إدارة المواد.',
          access: { permissions: ['courses.manage'] },
          link: { to: '/exam-board/courses' },
          steps: [step('افتح «المواد الدراسية» واستخدم «إضافة مادة» أو «تعديل» أو «حذف» بعد التأكد من البيانات.')],
          sources: [source(EB + 'pages/CoursesPage.jsx', 'إضافة مادة', 'حذف')],
        },
      ],
    },
    {
      id: 'registration',
      title: 'القبول والتسجيل',
      access: ACCESS.courseRegistration,
      tasks: [
        {
          id: 'approved-requests',
          title: 'طلبات التسجيل المعتمدة',
          summary: 'قائمة للقراءة بطلبات التسجيل التي اعتمدها المرشد أو العميد، مع المقررات والساعات وتاريخ الاعتماد.',
          link: { to: '/exam-board/approved-registration-requests' },
          steps: [step('افتح «طلبات التسجيل المعتمدة» وابحث عن الطلب المطلوب.')],
          sources: [source('frontend/src/features/registration-requests/pages/ApprovedRegistrationRequestsPage.jsx', 'رقم الطلب')],
        },
      ],
    },
  ],
  unavailable: [
    { id: 'results', title: 'النتائج والتقارير', text: 'صفحة قيد الإنشاء حاليًا ولا تحتوي وظائف تشغيلية.', access: BOARD, source: source(EB + 'pages/ExamPlaceholder.jsx', 'قيد الإنشاء') },
    { id: 'appeals', title: 'التظلمات', text: 'صفحة قيد الإنشاء حاليًا؛ لا يوجد مسار تظلمات مفعّل في الواجهة.', access: BOARD },
    { id: 'settings', title: 'الإعدادات', text: 'صفحة قيد الإنشاء حاليًا.', access: BOARD },
    { id: 'staff-registration', title: 'تسجيل المواد مباشرة من الموظف', text: 'زرا «تسجيل» و«حذف» في صفحة «تسجيل المواد» لا يعملان: الخادم يرفض التسجيل المباشر للفصل الجاري (409) لأن التسجيل يتم عبر طلب الطالب واعتماد المرشد الأكاديمي.', access: ACCESS.courseRegistration, source: source('backend/app/Services/RegistrationService.php', 'liveWorkflowRequired') },
  ],
  troubleshooting: [
    { id: 'manual-conflict', title: 'تعارض نسخة أثناء الإدخال اليدوي', who: 'user', access: ACCESS.manualGradeEntry, steps: ['إذا ظهرت نافذة التعارض فاختر بين استخدام نسخة الخادم أو إبقاء مقترحاتك ومراجعتها؛ لا تحفظ قبل مقارنة العلامات.'] },
  ],
}
