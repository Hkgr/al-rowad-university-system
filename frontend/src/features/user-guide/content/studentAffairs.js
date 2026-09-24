import { ACCESS, PERMISSIONS } from '../../auth/auth.js'
import { node, branch, source, step } from './helpers.js'

const SA = 'frontend/src/features/student-affairs/pages/'
const MANUAL = { allPermissions: [PERMISSIONS.studentsView, PERMISSIONS.studentsManage] }
const MINISTRY_IMPORT = { assignedPermissions: [PERMISSIONS.admissionsManage], actualUniversityScope: true }
const MINISTRY_VIEW = { assignedPermissions: [PERMISSIONS.admissionsView], actualUniversityScope: true }

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
          summary: 'مراحل متتابعة من ملف المفاضلة إلى إنشاء سجلات الطلاب. التعديل يتطلب صلاحية إدارة القبول؛ العرض وحده يسمح بالاطلاع والتدقيق فقط.',
          link: { to: '/student-affairs/ministry-placements' },
          steps: [
            step('«رفع ملف مفاضلة»: اختر السنة واسم الدفعة والملف، ثم «فحص الملف». صحّح أخطاء الملف قبل «اعتماد واستيراد الدفعة».', { access: MINISTRY_IMPORT }),
            step('«مطابقة البرامج»: طابق كل سجل مع البرنامج الأكاديمي المناسب (فرديًا أو لمجموعة).', { access: MINISTRY_IMPORT }),
            step('«تحويل إلى متقدم»: ينشئ متقدمًا وطلب قبول للسجلات الجاهزة.', { access: MINISTRY_IMPORT }),
            step('«اعتماد وإنشاء طالب»: أدخل رقم القيد والسنة الدراسية وتاريخ القيد لإنشاء سجل الطالب.', { access: MINISTRY_IMPORT }),
            step('«التدقيق النهائي»: راجع تقرير الجاهزية والملاحظات (للقراءة).'),
          ],
          flows: [{
            title: 'مسار المفاضلة الوزارية',
            access: MINISTRY_VIEW,
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
            source(SA + 'MinistryPlacementsPage.jsx', 'رفع ملف مفاضلة', 'فحص الملف', 'اعتماد واستيراد الدفعة', 'مطابقة البرامج', 'تحويل إلى متقدم', 'اعتماد وإنشاء طالب', 'التدقيق النهائي'),
            source('backend/app/Models/MinistryPlacementRecord.php', "'imported'", "'program_matched'"),
            source('backend/app/Services/MinistryPlacementApplicantConversionService.php', "'applicant_created'"),
          ],
        },
      ],
    },
    {
      id: 'registration',
      title: 'التسجيل',
      tasks: [
        {
          id: 'supplementary-office',
          title: 'التسجيل في الامتحانات التكميلية (مكتب التسجيل)',
          summary: 'فتح فترة التسجيل وإغلاقها يتطلب صلاحية النافذة والنطاق الجامعي؛ التسجيل والإلغاء يتطلبان صلاحية الإدارة ضمن نطاق الطالب.',
          access: { allPermissions: ['students.view'], allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] },
          link: { to: '/student-affairs/supplementary-exams' },
          steps: [
            step('اختر الدورة التكميلية، ثم «فتح التسجيل» عندما تكون الدورة معلنة.'),
            step('ابحث عن الطالب («بحث»)، راجع أهليته، ثم «تسجيل».'),
            step('لإلغاء تسجيل اضغط «إلغاء» واكتب السبب.'),
            step('في النهاية «إغلاق التسجيل وتثبيت القائمة»؛ بعدها تصبح القائمة نهائية ولا يمكن إعادة فتحها.'),
          ],
          flows: [{
            title: 'مسار فترة التسجيل التكميلي',
            nodes: [
              node('announced', 'start', 'دورة معلنة', 'تعلنها نيابة الشؤون العلمية.'),
              node('open', 'action', 'فتح التسجيل', 'تنتقل الدورة إلى «التسجيل مفتوح».'),
              node('register', 'action', 'تسجيل الطلاب المؤهلين أو إلغاء تسجيلهم', 'الإلغاء يتطلب كتابة سبب.'),
              node('closed', 'end', 'إغلاق التسجيل وتثبيت القائمة', 'تصبح القائمة نهائية وتنتقل الدورة إلى مرحلة التصحيح لدى هيئة الامتحانات.'),
            ],
          }],
          sources: [
            source(SA + 'SupplementaryExamRegistrations.jsx', 'فتح التسجيل', 'إغلاق التسجيل وتثبيت القائمة'),
            source('backend/app/Services/SupplementaryExamRegistrationWindowService.php', "'registration_open'", "'registration_closed'", "'announced'"),
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
