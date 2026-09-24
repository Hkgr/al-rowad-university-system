import { ACCESS, PERMISSIONS } from '../../auth/auth.js'
import { node, branch, source, step } from './helpers.js'

const SA = 'frontend/src/features/student-affairs/pages/'
const MANUAL = { allPermissions: [PERMISSIONS.studentsView, PERMISSIONS.studentsManage] }
const MINISTRY_IMPORT = { assignedPermissions: [PERMISSIONS.admissionsManage], actualUniversityScope: true }
const MINISTRY_VIEW = { assignedPermissions: [PERMISSIONS.admissionsView], actualUniversityScope: true }
// Writes: MinistryPlacementAccess::canManage (assigned admissions.manage + university scope) on a page reachable with admissions.view.
const MINISTRY_MANAGE = { assignedPermissions: [PERMISSIONS.admissionsView, PERMISSIONS.admissionsManage], actualUniversityScope: true }
// Supplementary registration office: page guard, then per-action server rules
// (SupplementaryExamRegistrationWindowService::assertCanGovernPeriod, SupplementaryExamRegistrationService::staff).
const SUPP_VIEW = { allPermissions: ['students.view'], allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] }
const SUPP_WINDOW = { ...SUPP_VIEW, assignedPermissions: ['supplementary_exams.registrations.view', 'supplementary_exams.registrations.window'], actualUniversityScope: true }
const SUPP_MANAGE = { ...SUPP_VIEW, assignedPermissions: ['supplementary_exams.registrations.view', 'supplementary_exams.registrations.manage'] }

export default {
  id: 'studentAffairs',
  title: 'شؤون الطلاب',
  intro: 'تدير هذه البوابة سجلات الطلاب: البحث وعرض الملفات، إضافة الطلاب وتعديلهم وأرشفتهم، طلاب المفاضلة الوزارية، التسجيل في الامتحانات التكميلية لمكتب التسجيل، والاطلاع على طلبات التسجيل المعتمدة.',
  sections: [
    {
      id: 'students',
      title: 'سجلات الطلاب',
      tasks: [
        {
          id: 'students-list',
          title: 'البحث عن طالب وعرض ملفه',
          summary: 'قائمة الطلاب ضمن نطاقك، مع ملف لكل طالب يضم بياناته ودرجاته وحضوره ووثائقه.',
          access: ACCESS.studentAffairs,
          link: { to: '/student-affairs/students' },
          steps: [
            step('افتح «قائمة الطلاب» وابحث بالاسم أو الرقم.'),
            step('اضغط «عرض الملف» لرؤية المعلومات الشخصية وكشف الدرجات والحضور وملفات الطالب.'),
          ],
          sources: [source(SA + 'StudentsPage.jsx', 'عرض الملف')],
        },
        {
          id: 'students-edit',
          title: 'تعديل بيانات طالب أو أرشفته واستعادته',
          summary: 'التعديل والأرشفة والاستعادة تتطلب صلاحية إدارة الطلاب وأن يكون الطالب ضمن نطاقك.',
          access: MANUAL,
          link: { to: '/student-affairs/students' },
          steps: [
            step('من «قائمة الطلاب» اضغط «تعديل»، صحّح البيانات ثم احفظ.'),
            step('«أرشفة» تنقل الطالب إلى قائمة «الطلاب الموقوفون» (أرشفة وليست فصلًا أكاديميًا).'),
            step('من «الطلاب الموقوفون» اضغط «استعادة» لإرجاع الطالب إلى القائمة.', { link: { to: '/student-affairs/students/archived' } }),
          ],
          flows: [{
            title: 'مسار الأرشفة والاستعادة',
            nodes: [
              node('list', 'start', 'قائمة الطلاب', 'تبدأ من «قائمة الطلاب».'),
              node('archive', 'action', 'أرشفة الطالب بعد التأكيد', 'يُخفى الطالب من القائمة النشطة ولا يُحذف نهائيًا.'),
              node('archived', 'end', 'يظهر في «الطلاب الموقوفون»', 'يبقى السجل محفوظًا في قائمة المؤرشفين.', [branch('عند الاستعادة', 'list')]),
            ],
          }],
          sources: [
            source(SA + 'StudentsPage.jsx', 'تعديل', 'أرشفة'),
            source(SA + 'ArchivedStudentsPage.jsx', 'استعادة'),
            source('backend/app/Policies/StudentPolicy.php', "hasPermission('students.manage')"),
          ],
        },
        {
          id: 'students-add',
          title: 'إضافة طالب',
          summary: 'لصفحة «إضافة طالب» مساران؛ يظهر لك منهما ما تسمح به صلاحياتك فقط.',
          access: ACCESS.studentAffairsAddStudent,
          link: { to: '/student-affairs/students/add' },
          steps: [
            step('الإدخال اليدوي: املأ نموذج «إضافة طالب جديد» (رقم القيد، الاسم، البرنامج، السنة، تاريخ القيد، الحالة…) ثم احفظ.', { access: MANUAL }),
            step('قبل الحفظ تأكد من عدم وجود الطالب مسبقًا؛ رقم القيد والبريد يجب أن يكونا غير مكررين.', { access: MANUAL }),
            step('طلاب المفاضلة: اضغط «رفع طلاب المفاضلة» للانتقال إلى صفحة المفاضلة الوزارية واتبع مراحلها الموضحة أدناه.', { access: MINISTRY_IMPORT, link: { to: '/student-affairs/ministry-placements' } }),
          ],
          sources: [source(SA + 'AddStudentPage.jsx', 'إضافة طالب جديد', 'رفع طلاب المفاضلة', 'canCreateManualStudent', 'canImportMinistry')],
        },
        {
          id: 'graduates',
          title: 'قائمة الخريجين',
          summary: 'قائمة للقراءة بالطلاب ذوي حالة «متخرج».',
          access: ACCESS.studentAffairs,
          link: { to: '/student-affairs/graduates' },
          steps: [step('افتح «قائمة الخريجين» وابحث عن الطالب المطلوب.')],
          sources: [source(SA + 'GraduatesPage.jsx', 'graduated')],
        },
      ],
    },
    {
      id: 'ministry',
      title: 'المفاضلة الوزارية',
      access: MINISTRY_VIEW,
      tasks: [
        {
          id: 'ministry-placements',
          title: 'استيراد طلاب المفاضلة وقيدهم',
          summary: 'مراحل متتابعة من ملف المفاضلة إلى إنشاء سجلات الطلاب. تتطلب صلاحية إدارة القبول المسندة مع النطاق الجامعي.',
          access: MINISTRY_MANAGE,
          link: { to: '/student-affairs/ministry-placements' },
          steps: [
            step('«رفع ملف مفاضلة»: اختر السنة واسم الدفعة والملف، ثم «فحص الملف». صحّح أخطاء الملف قبل «اعتماد واستيراد الدفعة».'),
            step('«مطابقة البرامج»: طابق كل سجل مع البرنامج الأكاديمي المناسب (فرديًا أو لمجموعة).'),
            step('«تحويل إلى متقدم»: ينشئ متقدمًا وطلب قبول للسجلات الجاهزة.'),
            step('«اعتماد وإنشاء طالب»: أدخل رقم القيد والسنة الدراسية وتاريخ القيد لإنشاء سجل الطالب.'),
            step('«التدقيق النهائي»: راجع تقرير الجاهزية والملاحظات قبل إغلاق العمل على الدفعة.'),
          ],
          flows: [{
            title: 'مسار المفاضلة الوزارية',
            nodes: [
              node('file', 'start', 'رفع ملف المفاضلة وفحصه', 'يُفحص الملف أولًا؛ لا يُسمح بالاستيراد مع وجود صفوف خاطئة أو مكررة.'),
              node('imported', 'action', 'اعتماد واستيراد الدفعة', 'تُنشأ السجلات بحالة «مستورد».'),
              node('matched', 'action', 'مطابقة البرامج', 'يُربط كل سجل ببرنامج أكاديمي؛ يمكن تعديل المطابقة أو إزالتها قبل التحويل.'),
              node('applicant', 'action', 'التحويل إلى متقدم', 'يُنشأ متقدم وطلب قبول قيد القرار.'),
              node('enrolled', 'action', 'الاعتماد وإنشاء الطالب', 'يُقبل الطلب ويُنشأ سجل الطالب.'),
              node('audit', 'end', 'التدقيق النهائي', 'تقرير للقراءة يبين الجاهز والمحظور. لا يوجد في هذه الصفحة مسار رفض أو إرجاع.'),
            ],
          }],
          sources: [
            source(SA + 'MinistryPlacementsPage.jsx', 'رفع ملف مفاضلة', 'فحص الملف', 'اعتماد واستيراد الدفعة', 'مطابقة البرامج', 'تحويل إلى متقدم', 'اعتماد وإنشاء طالب', 'التدقيق النهائي', 'hasAssignedPermission(PERMISSIONS.admissionsManage)'),
            source('backend/app/Support/MinistryPlacementAccess.php', "public const MANAGE = 'admissions.manage'", 'hasActualUniversityScope'),
            source('backend/app/Models/MinistryPlacementRecord.php', "'imported'", "'program_matched'"),
            source('backend/app/Services/MinistryPlacementApplicantConversionService.php', "'applicant_created'"),
          ],
        },
        {
          id: 'ministry-review',
          title: 'الاطلاع على دفعات المفاضلة وتدقيقها',
          summary: 'اطلاع وتدقيق فقط: تعرض الصفحة الدفعات المستوردة وحالة سجلاتها في كل مرحلة. أزرار الاستيراد والمطابقة والتحويل والقيد لا تظهر إلا لمن يملك صلاحية إدارة القبول، وتظهر المراحل لغيره «للقراءة فقط».',
          link: { to: '/student-affairs/ministry-placements' },
          steps: [
            step('اختر دفعة من «الدفعات المستوردة».'),
            step('راجع تبويب «السجلات» وابحث فيها، ثم تبويبات المراحل لمعرفة ما طُوبق وما حُوّل وما قُيّد.'),
            step('افتح «التدقيق النهائي» لعرض تقرير الجاهزية والملاحظات.'),
            step('إذا وجدت سجلًا يحتاج تصحيحًا فأبلغ صاحب صلاحية إدارة القبول؛ لا يمكن تعديله من حساب العرض.'),
          ],
          sources: [
            source(SA + 'MinistryPlacementsPage.jsx', 'الدفعات المستوردة', 'بحث في السجلات', 'التدقيق النهائي'),
            source(SA + '../components/MinistryApplicantConversionPanel.jsx', 'للقراءة فقط'),
            source('backend/app/Support/MinistryPlacementAccess.php', "public const VIEW = 'admissions.view'"),
          ],
        },
      ],
    },
    {
      id: 'registration',
      title: 'التسجيل',
      tasks: [
        {
          id: 'supplementary-office-view',
          title: 'متابعة التسجيل في الامتحانات التكميلية',
          summary: 'اطلاع على حالة الدورة وقائمة المسجلين (أولية أو نهائية). أزرار الفتح والإغلاق والتسجيل تبقى معطّلة ما لم يمنحك الخادم صلاحيتها.',
          access: SUPP_VIEW,
          link: { to: '/student-affairs/supplementary-exams' },
          steps: [
            step('اختر الدورة التكميلية لعرض حالتها وحالة القائمة.'),
            step('ابحث في قائمة المسجلين عن طالب معين.'),
          ],
          sources: [source(SA + 'SupplementaryExamRegistrations.jsx', 'حالة القائمة', 'أولية', 'نهائية', 'can_manage_window', 'can_manage_registrations')],
        },
        {
          id: 'supplementary-window',
          title: 'فتح التسجيل التكميلي وإغلاقه',
          summary: 'يتطلب دور موظف التسجيل الفعلي وصلاحية نافذة التسجيل المسندة والنطاق الجامعي.',
          access: SUPP_WINDOW,
          link: { to: '/student-affairs/supplementary-exams' },
          steps: [
            step('اختر دورة معلنة ثم «فتح التسجيل».'),
            step('بعد انتهاء التسجيل اضغط «إغلاق التسجيل وتثبيت القائمة»؛ تصبح القائمة نهائية ولا يمكن إعادة فتح التسجيل.'),
          ],
          flows: [{
            title: 'مسار نافذة التسجيل التكميلي',
            nodes: [
              node('announced', 'start', 'دورة معلنة', 'تعلنها نيابة الشؤون العلمية.'),
              node('open', 'action', 'فتح التسجيل', 'تنتقل الدورة إلى «التسجيل مفتوح»؛ يشترط وجود مقرر مطروح مفتوح.'),
              node('registering', 'other', 'تسجيل الطلاب', 'يسجّل الطلابُ أنفسهم أو يسجّلهم موظف يملك صلاحية إدارة التسجيل.'),
              node('closed', 'action', 'إغلاق التسجيل وتثبيت القائمة', 'تصبح القائمة نهائية.'),
              node('exam', 'end', 'انتقال الدورة إلى هيئة الامتحانات', 'تبدأ هيئة الامتحانات مرحلة العلامات على القائمة النهائية.'),
            ],
          }],
          sources: [
            source(SA + 'SupplementaryExamRegistrations.jsx', 'فتح التسجيل', 'إغلاق التسجيل وتثبيت القائمة', 'can_manage_window'),
            source('backend/app/Services/SupplementaryExamRegistrationWindowService.php', "'registration_open'", "'registration_closed'", "'announced'", 'SupplementaryExamRegistrationGovernance::WINDOW', 'hasActualUniversityScope'),
          ],
        },
        {
          id: 'supplementary-register',
          title: 'تسجيل طالب في الامتحان التكميلي أو إلغاء تسجيله',
          summary: 'يتطلب دور موظف التسجيل وصلاحية إدارة التسجيل المسندة، وأن يكون الطالب ضمن نطاقك، وأن تكون نافذة التسجيل مفتوحة.',
          access: SUPP_MANAGE,
          link: { to: '/student-affairs/supplementary-exams' },
          steps: [
            step('ابحث عن الطالب («بحث») واختره؛ تُعرض المقررات المؤهل لها.'),
            step('اضغط «تسجيل» للمقرر المطلوب.'),
            step('لإلغاء تسجيل اضغط «إلغاء» واكتب السبب (إلزامي).'),
          ],
          flows: [{
            title: 'مسار تسجيل طالب',
            nodes: [
              node('find', 'start', 'البحث عن الطالب', 'يُشترط أن تكون نافذة التسجيل مفتوحة.'),
              node('eligible', 'system', 'فحص الأهلية', 'يعرض النظام المقررات التي يحق للطالب التسجيل فيها فقط.'),
              node('register', 'action', 'تسجيل الطالب في المقرر', 'يُسجَّل الطالب ويظهر في القائمة الأولية.', [branch('عند الإلغاء مع سبب', 'cancelled'), branch('عند إغلاق التسجيل', 'fixed')]),
              { ...node('cancelled', 'return', 'إلغاء التسجيل', 'يُلغى التسجيل مع حفظ السبب؛ متاح فقط ما دامت النافذة مفتوحة.'), terminal: true },
              node('fixed', 'end', 'تثبيت ضمن القائمة النهائية', 'بعد الإغلاق لا يمكن التعديل من هذه الصفحة.'),
            ],
          }],
          sources: [
            source(SA + 'SupplementaryExamRegistrations.jsx', 'تسجيل', 'إلغاء', 'بحث', 'can_manage_registrations'),
            source('backend/app/Services/SupplementaryExamRegistrationService.php', 'SupplementaryExamRegistrationGovernance::MANAGE', 'isRegistrationOfficer'),
          ],
        },
        {
          id: 'approved-requests',
          title: 'طلبات التسجيل المعتمدة',
          summary: 'قائمة للقراءة بطلبات التسجيل التي اعتمدها المرشد أو العميد، ضمن نطاقك.',
          access: ACCESS.studentAffairsApprovedRegistrationRequests,
          link: { to: '/student-affairs/approved-registration-requests' },
          steps: [step('افتح «طلبات التسجيل المعتمدة» واستخدم البحث؛ لا توجد إجراءات تعديل في هذه الصفحة.')],
          sources: [source('backend/routes/api.php', 'registration-requests/approved')],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    { id: 'duplicate', title: 'رقم القيد أو البريد مكرر', who: 'user', access: MANUAL, steps: ['ابحث عن الطالب في القائمة وفي «الطلاب الموقوفون» قبل إنشائه مجددًا؛ قد يكون مؤرشفًا فيكفي استعادته.'] },
  ],
}
