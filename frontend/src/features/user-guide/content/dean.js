import { PERMISSIONS, ROLES } from '../../auth/auth.js'
import { node, branch, source, step } from './helpers.js'
import { reportAccess } from '../../portal-reports/reports.js'

const D = 'frontend/src/features/dean-dashboard/'
const GOV_VIEW = { allRoles: [ROLES.dean], assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView] }
const GOV_MANAGE = { allRoles: [ROLES.dean], assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView, PERMISSIONS.semesterOfferingGovernanceManage] }

export default {
  id: 'dean',
  title: 'بوابة عميد الكلية',
  intro: 'تتابع من هذه البوابة طلاب الكلية ومدرسيها وموادها، وتراجع طلبات تسجيل الطلاب، وتجهّز طروحات الفصل وترسلها للاعتماد العلمي، وتقترح تكليفات التدريس، وتدير الطروحات التكميلية ضمن كليتك.',
  sections: [
    {
      id: 'reports', title: 'تقارير الكلية', access: reportAccess('dean'),
      tasks: [{id:'college-reports',title:'عرض تقرير الكلية',summary:'اختر الطلاب أو المدرسين أو الطروحات أو التسجيلات أو النتائج الرسمية. الفترة متاحة للمؤشرات المرتبطة بالطرح فقط.',access:reportAccess('dean'),link:{to:'/dean/reports'},steps:[step('اختر التقرير والفترة، ثم اضغط عدد الحالة لعرض قائمتها المطابقة.')],sources:[source('frontend/src/features/portal-reports/PortalReportsPage.jsx','ماذا تريد أن تعرف؟','إجمالي القائمة المعروضة:')]}],
    },
    {
      id: 'requests',
      title: 'طلبات تسجيل الطلاب',
      tasks: [
        {
          id: 'registration-review',
          title: 'مراجعة طلبات التسجيل والتعديل والاستبدال',
          summary: 'تعتمد الطلب أو تعيده للطالب مع سبب. الأزرار تظهر فقط للطلب «المرسل» وما دامت مهلة القرار مفتوحة.',
          access: { permissions: ['registration_requests.review'] },
          link: { to: '/dean/registration-requests' },
          steps: [
            step('افتح «طلبات تسجيل الطلاب» واختر التبويب: «طلبات التسجيل» أو «طلبات تعديل التسجيل» أو «طلبات استبدال المقررات الملغاة».'),
            step('اضغط «عرض» لفتح الطلب ومراجعة المقررات والساعات.'),
            step('للاعتماد: «اعتماد الطلب» ثم «تأكيد الاعتماد» (للتعديل: «اعتماد وتثبيت التعديل»).'),
            step('للإرجاع: «إعادة للتعديل» واكتب سببًا واضحًا (8 أحرف على الأقل) ثم «إعادة الطلب».'),
          ],
          flows: [{
            title: 'مسار مراجعة طلب التسجيل',
            nodes: [
              node('submitted', 'start', 'طلب مرسل من الطالب', 'يصل الطلب إلى القائمة بحالة «مرسل».'),
              node('review', 'review', 'مراجعة العميد', 'تراجع المقررات والساعات وأهلية الطالب.', [branch('عند الاعتماد', 'approved'), branch('عند الإرجاع مع سبب', 'returned'), branch('إذا انتهت المهلة دون قرار', 'expired')]),
              node('returned', 'return', 'معاد للطالب', 'يعدّل الطالب الطلب ويعيد إرساله فيعود إلى القائمة.', [branch('بعد إعادة الإرسال', 'submitted')]),
              { ...node('expired', 'system', 'انتهت المهلة', 'لا يمكن اعتماد الطلب بعد انتهاء مهلة القرار.'), terminal: true },
              node('approved', 'end', 'معتمد', 'تُنشأ تسجيلات الطالب الرسمية.'),
            ],
          }],
          sources: [
            source(D + 'pages/DeanRegistrationRequests.jsx', 'طلبات التسجيل', 'طلبات تعديل التسجيل', 'طلبات استبدال المقررات الملغاة'),
            source(D + 'pages/DeanRegistrationRequestDetail.jsx', 'اعتماد الطلب', 'تأكيد الاعتماد', 'إعادة للتعديل', 'إعادة الطلب'),
            source(D + 'pages/DeanRegistrationModificationDetail.jsx', 'اعتماد وتثبيت التعديل'),
            source('backend/app/Services/RegistrationRequestService.php', "hasPermission('registration_requests.review')"),
          ],
        },
      ],
    },
    {
      id: 'governance',
      title: 'حوكمة طروحات الفصل',
      access: GOV_VIEW,
      tasks: [
        {
          id: 'semester-offerings',
          title: 'تجهيز طروحات الفصل وإرسالها للاعتماد العلمي',
          summary: 'تجهّز المواد المطروحة للفصل ثم ترسل كل طرح إلى نائب رئيس الجامعة للشؤون العلمية الذي يعتمده (فيُفتح للتسجيل) أو يعيده للتعديل.',
          access: GOV_MANAGE,
          link: { to: '/dean/registration-offerings' },
          steps: [
            step('اختر السنة والفصل والقسم والبرنامج والخطة المصدر.'),
            step('أضف المواد («إضافة الخطة الإرشادية» أو إضافة مادة) ثم «حفظ التجهيز».'),
            step('لكل طرح: «تحديد الطرح»، وأكمل الجدول الأسبوعي وتكليف المدرسين.'),
            step('اضغط «إرسال للاعتماد العلمي». إذا أُعيد الطرح عدّله ثم «تصحيح وإعادة الإرسال».'),
          ],
          flows: [{
            title: 'مسار اعتماد طرح الفصل',
            nodes: [
              node('prepare', 'start', 'تجهيز الطروحات (مسودة)', 'تحفظ التجهيز ويبقى الطرح مسودة.'),
              node('submit', 'action', 'الإرسال للاعتماد العلمي', 'يشترط أن يكون الطرح محددًا ومغلقًا ومكتمل تكليف المدرسين.'),
              node('review', 'review', 'مراجعة النائب العلمي', 'يراجع النائب العلمي الطرح.', [branch('عند الاعتماد', 'approved'), branch('عند الإعادة مع سبب', 'returned')]),
              node('returned', 'return', 'معاد للتعديل', 'تعدّل الطرح ثم «تصحيح وإعادة الإرسال».', [branch('إعادة الإرسال', 'submit')]),
              node('approved', 'end', 'معتمد — يُفتح الطرح للتسجيل', 'يُفتح الطرح تلقائيًا بعد الاعتماد.'),
            ],
          }],
          sources: [
            source(D + 'pages/DeanRegistrationOfferings.jsx', 'إضافة الخطة الإرشادية', 'حفظ التجهيز', 'تحديد الطرح', 'إرسال للاعتماد العلمي', 'تصحيح وإعادة الإرسال'),
            source('backend/app/Support/SemesterOfferingGovernance.php', "'draft'", "'submitted'", "'returned'", "'approved'"),
          ],
        },
        {
          id: 'minimum-enrollment',
          title: 'التوصية بشأن الطروحات دون الحد الأدنى',
          summary: 'العميد يوصي فقط؛ القرار للنائب العلمي.',
          access: GOV_MANAGE,
          link: { to: '/dean/registration-offerings' },
          steps: [step('في قسم الحد الأدنى اختر «التوصية بالاستمرار» أو «التوصية بالإلغاء» مع ملاحظات، ثم انتظر قرار النائب العلمي.')],
          sources: [source(D + 'pages/DeanRegistrationOfferings.jsx', 'التوصية بالاستمرار', 'التوصية بالإلغاء')],
        },
        {
          id: 'exception-closure',
          title: 'طلب فتح استثنائي أو إغلاق تسجيل لطرح',
          summary: 'يُرسل الطلب إلى النائبين العلمي والإداري؛ تظهر حالة كل مراجعة في الصفحة.',
          access: { anyAccess: [{ ...GOV_VIEW, permissions: [PERMISSIONS.exceptionalOpenRequest] }, { ...GOV_VIEW, permissions: [PERMISSIONS.closureRequest] }] },
          link: { to: '/dean/registration-offerings' },
          steps: [
            step('من بطاقة الطرح اضغط «طلب فتح استثنائي» واكتب المبرر. إذا أُعيد فعدّله وأعد الإرسال.', { access: { permissions: [PERMISSIONS.exceptionalOpenRequest] } }),
            step('اضغط «طلب إغلاق التسجيل» واكتب المبرر، ثم تابع حالة المراجعتين العلمية والإدارية.', { access: { permissions: [PERMISSIONS.closureRequest] } }),
          ],
          sources: [
            source(D + 'pages/DeanRegistrationOfferings.jsx', 'طلب فتح استثنائي', 'طلب إغلاق التسجيل'),
            source('backend/app/Support/ExceptionalOpeningWorkflow.php', "'submitted'", "'returned'", "'approved'"),
          ],
        },
      ],
    },
    {
      id: 'teaching',
      title: 'المدرسون والمواد',
      tasks: [
        {
          id: 'college-views',
          title: 'متابعة طلاب الكلية ومدرسيها وموادها',
          summary: 'صفحات للقراءة ضمن نطاق كليتك.',
          link: { to: '/dean/students' },
          steps: [
            step('«الطلاب»: ابحث عن الطالب وافتح ملفه.', { link: { to: '/dean/students' } }),
            step('«المدرسين»: اعرض ملف المدرس وتكليفاته وجلساته.', { link: { to: '/dean/teachers' } }),
            step('«المواد»: اعرض الطرح ومدرسيه وطلابه وملخص نتائجه (للقراءة).', { link: { to: '/dean/courses' } }),
          ],
          sources: [source(D + 'nav.js', "'/dean/students'", "'/dean/teachers'", "'/dean/courses'")],
        },
        {
          id: 'teaching-assignment',
          title: 'اقتراح تكليف تدريسي',
          summary: 'اقتراحك لا يصبح نافذًا إلا بعد موافقة النائب العلمي والنائب الإداري.',
          access: { allRoles: [ROLES.dean], assignedPermissions: [PERMISSIONS.teachingAssignmentsManage], permissions: [PERMISSIONS.teachingStaffManage] },
          link: { to: '/dean/courses' },
          steps: [
            step('من «المواد» اضغط «إدارة التكليف» أو من ملف المدرس «إضافة تكليف تدريسي».'),
            step('اختر الطرح والجزء والمدرس، ثم «مراجعة الإرسال» ثم «إرسال للمراجعة».'),
            step('تابع حالة المراجعتين. إذا أُعيد الطلب من أي منهما فعدّله وأعد إرساله.'),
          ],
          flows: [{
            title: 'مسار التكليف التدريسي',
            nodes: [
              node('propose', 'start', 'اقتراح التكليف', 'يرسل العميد الاقتراح فيصبح «مرسلًا» بانتظار مراجعتين مستقلتين.'),
              node('review', 'review', 'مراجعة النائب العلمي والنائب الإداري (كل منهما مستقل)', 'لا ترتيب بين المراجعتين، ولا يجوز أن يعطي شخص واحد الموافقتين.', [branch('إذا وافق الاثنان', 'approved'), branch('إذا أعاده أي منهما مع سبب', 'returned')]),
              node('returned', 'return', 'معاد للعميد', 'تعدّل الاقتراح وتعيد إرساله؛ تُعاد فقط المراجعة التي أرجعته وتبقى الموافقة الأخرى.', [branch('إعادة الإرسال', 'review')]),
              node('approved', 'end', 'معتمد — يصبح التكليف نافذًا', 'يُطبق التكليف على الطرح.'),
            ],
          }],
          sources: [
            source(D + 'components/TeacherAssignmentManagerModal.jsx', 'إرسال للمراجعة'),
            source('backend/app/Support/TeachingAssignmentWorkflow.php', "'submitted'", "'returned'", "'approved'", "'pending'"),
          ],
        },
      ],
    },
    {
      id: 'supplementary',
      title: 'الامتحانات التكميلية',
      access: { allRoles: ['dean'], assignedPermissions: ['supplementary_exams.offerings.view'] },
      tasks: [
        {
          id: 'supplementary-offerings',
          title: 'طرح المقررات في الدورة التكميلية',
          summary: 'اختيار المقررات التي تُطرح في الدورة التكميلية لكليتك. لا توجد مرحلة موافقة لاحقة.',
          access: { allRoles: ['dean'], assignedPermissions: ['supplementary_exams.offerings.view', 'supplementary_exams.offerings.manage'] },
          link: { to: '/dean/supplementary-exams' },
          steps: [
            step('اختر السنة والدورة والقسم والبرنامج.'),
            step('اضغط «طرح في التكميلي» للمقرر المطلوب؛ ويمكنك «إغلاق» الطرح أو «إعادة فتح» ما دامت الدورة تسمح.'),
          ],
          sources: [source(D + 'pages/DeanSupplementaryExams.jsx', 'طرح في التكميلي', 'إغلاق', 'إعادة فتح')],
        },
      ],
    },
  ],
  unavailable: [
    { id: 'closure-approval', title: 'إكمال اعتماد إغلاق التسجيل', text: 'يمكنك إرسال طلب الإغلاق ومتابعة حالته، لكن شاشات مراجعة النائبين لطلبات الإغلاق غير مفعّلة حاليًا في الواجهة.', access: { permissions: [PERMISSIONS.closureRequest] } },
  ],
  troubleshooting: [
    { id: 'submit-blocked', title: 'تعذّر إرسال الطرح للاعتماد العلمي', who: 'user', access: GOV_MANAGE, steps: ['تأكد أن الطرح محدد، ومغلق، ومكتمل تكليف المدرسين، وأن قيمة الحد الأدنى مدخلة عند طلبها؛ الرسالة الظاهرة تحدد الشرط الناقص.'] },
  ],
}
