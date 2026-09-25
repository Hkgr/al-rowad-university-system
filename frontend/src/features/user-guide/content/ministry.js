import { ACCESS } from '../../auth/auth.js'
import { source, step } from './helpers.js'

const PAGES = 'frontend/src/features/ministry-portal/pages/'
const SERVER = 'backend/app/Services/Ministry/'

// Guide of the read-only Ministry of Education portal. Every task mirrors a page and its own permission.
const ministry = {
  id: 'ministry',
  title: 'بوابة وزارة التربية والتعليم',
  intro: 'بوابة اطلاع للقراءة فقط تتابع بها الوزارة جامعة الروّاد للعلوم والتقانة: مؤشرات شاملة، والعمداء، والطلاب، والكليات، والمواد، والمدرسون، ورئاسة الجامعة ونوابها. تُعرض البيانات كما يسجلها النظام فعلًا؛ ما لم يُسجَّل يظهر «غير مسجل» أو «غير متاح» ولا يُفترض. لا توجد في البوابة أي عملية إنشاء أو تعديل أو حذف أو اعتماد.',
  sections: [
    {
      id: 'dashboard',
      title: 'الرئيسية والمؤشرات',
      tasks: [
        {
          id: 'dashboard-read',
          title: 'قراءة المؤشرات وتصفيتها والانتقال إلى القوائم',
          summary: 'تحت كل رقم تعريفه ومصدره. الأرقام العامة لقطة حالية، ومؤشرات الفترة تتبع السنة والفصل المختارين (السنة الحالية افتراضيًا).',
          access: ACCESS.ministryDashboard,
          link: { to: '/ministry' },
          steps: [
            step('اختر السنة الأكاديمية والفصل لمؤشرات الفترة، والكلية والبرنامج لحصر كل المؤشرات. تظهر المرشحات المطبقة ووقت آخر تحديث أعلى اللوحة.'),
            step('اضغط أي رقم لفتح القائمة المفلترة التي تكوّنه؛ إجمالي القائمة يساوي الرقم.'),
            step('النتائج المعتمدة لا تشمل العلامات قيد الإدخال أو المراجعة أو المعادة للتصحيح. المؤشرات التي لا يمكن حسابها بثقة تظهر في قسم «غير متاحة» لا كأرقام.'),
          ],
          sources: [source(PAGES + 'MinistryHome.jsx', 'مؤشرات غير متاحة بثقة من البيانات الحالية'), source('frontend/src/features/ministry-portal/components/MinistryUi.jsx', 'المرشحات المطبقة', 'آخر تحديث'), source(SERVER + 'MinistryDashboardService.php', 'UNAVAILABLE', 'officialResultsQuery')],
        },
      ],
    },
    {
      id: 'people',
      title: 'العمداء والطلاب والمدرسون',
      tasks: [
        {
          id: 'deans',
          title: 'العمداء الحاليون والسابقون',
          summary: 'الحالي: حساب فعّال بدور عميد ونطاق كلية فعّالين، أو قيد منصب سارٍ. تُعرض حالة الدور والنطاق وقيد المنصب منفصلة، مع تاريخي القيد كما سُجلا وتنبيه عند التعارض.',
          access: ACCESS.ministryDeans,
          link: { to: '/ministry/deans' },
          steps: [step('صفِّ حسب الكلية أو «الحاليون/السابقون»، ثم افتح اسم العميد لعرض تكليفاته ومناصبه المسجلة.')],
          sources: [source(PAGES + 'MinistryDeans.jsx', 'ملاحظات السجل'), source(SERVER + 'MinistryStaffService.php', 'deanRows')],
        },
        {
          id: 'students',
          title: 'قائمة الطلاب والسجل الأكاديمي المعتمد',
          summary: 'بحث بالاسم أو الرقم الجامعي وتصفية بالكلية والبرنامج والحالة والسنة الدراسية. التفاصيل تعرض الهوية الأكاديمية والفصول المُقفلة والنتائج المعتمدة فقط، دون بيانات الاتصال.',
          access: ACCESS.ministryStudents,
          link: { to: '/ministry/students' },
          steps: [step('ابحث أو صفِّ، ثم افتح اسم الطالب. العلامات غير المعتمدة وعلامات الأجزاء والملاحظات الداخلية لا تظهر.')],
          sources: [source(PAGES + 'MinistryStudentDetail.jsx', 'النتائج المعتمدة', 'الفصول المعتمدة'), source(SERVER + 'MinistryStudentService.php', 'publication_note')],
        },
        {
          id: 'faculty',
          title: 'المدرسون وانتماؤهم وتدريسهم',
          summary: 'الانتماء للكلية من الوحدة الأساسية أو تكليف فعّال، وقد يتعدد. التدريس من تكليفات الطروحات الفعّالة.',
          access: ACCESS.ministryFaculty,
          link: { to: '/ministry/faculty' },
          steps: [step('صفِّ حسب الكلية أو حالة الملف، ثم افتح اسم المدرس لعرض كلياته وطروحاته والمقررات المؤهل لها.')],
          sources: [source(PAGES + 'MinistryFaculty.jsx', 'الانتماء ليس تكليفًا بالتدريس')],
        },
      ],
    },
    {
      id: 'structure',
      title: 'الكليات والمواد ورئاسة الجامعة',
      tasks: [
        {
          id: 'colleges',
          title: 'الكليات وأقسامها وبرامجها وأعدادها',
          summary: 'لكل كلية: الأقسام والبرامج والخطة الفعّالة لكل برنامج، وأعداد الطلاب والمدرسين والمقررات مع روابط للقوائم.',
          access: ACCESS.ministryColleges,
          link: { to: '/ministry/colleges' },
          steps: [step('اضغط اسم الكلية لتفاصيلها، أو أي عدد لفتح القائمة المطابقة.')],
          sources: [source(PAGES + 'MinistryCollegeDetail.jsx', 'مقررات الخطة', 'الخطة الفعّالة')],
        },
        {
          id: 'courses',
          title: 'المواد: التعريف والخطط والطروحات',
          summary: 'تفاصيل المقرر تفصل بين تعريفه، وإدراجه في نسخ الخطط (مع تمييز الخطة الفعّالة)، وطروحاته في الفصول.',
          access: ACCESS.ministryCourses,
          link: { to: '/ministry/courses' },
          steps: [step('ابحث برمز المقرر أو اسمه، أو صفِّ حسب الكلية أو البرنامج أو الطرح في سنة وفصل، ثم افتح المقرر.')],
          sources: [source(PAGES + 'MinistryCourseDetail.jsx', 'تعريف المقرر', 'إدراجه في الخطط الدراسية', 'طروحاته في الفصول')],
        },
        {
          id: 'leadership',
          title: 'رئاسة الجامعة ونوابها والوحدات التابعة',
          summary: 'الوحدات من الهيكل التنظيمي المسجل؛ الشاغلون من قيود المناصب وأدوار الحسابات. المنصب بلا شاغل مسجل يظهر كذلك.',
          access: ACCESS.ministryLeadership,
          link: { to: '/ministry/leadership' },
          steps: [step('افتح «تفاصيل المنصب والوحدات» لعرض الشاغلين الحاليين والسابقين وشجرة الوحدات التابعة.')],
          sources: [source(PAGES + 'MinistryLeadership.jsx', 'الشاغلون المسجلون'), source(SERVER + 'MinistryStaffService.php', 'VP_ROLE_BY_UNIT_NAME')],
        },
      ],
    },
  ],
  unavailable: [
    { id: 'no-actions', title: 'إجراءات التعديل', text: 'البوابة للاطلاع فقط: لا إنشاء ولا تعديل ولا حذف ولا اعتماد ولا نشر ولا إعادة تعيين كلمات مرور. الخادم يرفض أي مسار آخر بحساب الوزارة.' },
    { id: 'community-vp', title: 'نائب رئيس الجامعة للشؤون المجتمعية', text: 'الوحدة مسجلة في الهيكل التنظيمي، لكن لا يوجد في النظام دور أو حساب أو شاغل مسجل لهذا المنصب؛ تُعرض الوحدة وحدها.' },
    { id: 'status-history', title: 'الحالات في تواريخ سابقة', text: 'حالة الطالب لقطة حالية؛ لا يحفظ النظام تاريخ تغيّرها، لذلك لا تُعرض نسب تسرب أو توزيعات حالة لسنوات سابقة.' },
  ],
  troubleshooting: [
    { id: 'ministry-403', title: 'رسالة «لا يملك حسابك صلاحية…»', who: 'admin', steps: ['كل صفحة تحتاج صلاحية مستقلة ضمن دور الوزارة. إن اختفى قسم فاطلب من مدير النظام التحقق من صلاحيات دور «متابعة وزارة التربية والتعليم».'] },
    { id: 'ministry-number', title: 'رقم لا يطابق توقعك', who: 'user', steps: ['اقرأ تعريف المؤشر أسفل الرقم وتحقق من المرشحات المطبقة ووقت التحديث، ثم افتح القائمة المرتبطة بالرقم لمراجعة مكوناته.'] },
  ],
}

export default ministry
