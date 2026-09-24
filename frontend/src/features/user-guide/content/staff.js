import { ACCESS } from '../../auth/auth.js'
import { node, branch, source, step } from './helpers.js'

const HR = 'frontend/src/features/hr-dashboard/pages/'
const AS = 'frontend/src/features/academic-structure/pages/'
const RESOURCE_AUTH = source('backend/app/Services/ResourceAuthorizationService.php', "'hr' => ['employees'", "'academic_structure' =>")

export const hr = {
  id: 'hr',
  title: 'الموارد البشرية',
  intro: 'تدير هذه البوابة بيانات الموظفين والمناصب الوظيفية، وتعرض قائمة أعضاء هيئة التدريس. التعديلات تُحفظ مباشرة ولا تمر بمرحلة اعتماد.',
  sections: [
    {
      id: 'employees',
      title: 'الموظفون',
      tasks: [
        {
          id: 'employees-view',
          title: 'البحث عن موظف وعرض ملفه',
          summary: 'قائمة الموظفين مع ملف لكل موظف يعرض بياناته ومناصبه.',
          link: { to: '/hr/employees' },
          steps: [step('افتح «الموظفون» وابحث بالاسم أو الرقم أو البريد، ثم اضغط «عرض الملف».')],
          sources: [source(HR + 'EmployeesPage.jsx', 'عرض الملف')],
        },
        {
          id: 'employees-manage',
          title: 'إضافة موظف أو تعديله أو حذفه',
          summary: 'تتطلب صلاحية إدارة الموارد البشرية؛ بدونها يرفض الخادم الحفظ حتى لو ظهر الزر.',
          access: { permissions: ['hr.manage'] },
          link: { to: '/hr/employees' },
          steps: [
            step('اضغط «إضافة موظف» وأدخل البيانات الأساسية ثم احفظ.', { link: { to: '/hr/employees/add' } }),
            step('لتعديل موظف اضغط «تعديل» ثم «حفظ».'),
            step('تحقق من الموظف المطلوب قبل «حذف» والتأكيد.'),
          ],
          flows: [{
            title: 'مسار تحديث بيانات موظف',
            nodes: [
              node('open', 'start', 'قائمة الموظفين', 'تبدأ من «الموظفون».'),
              node('edit', 'action', 'الإضافة أو التعديل أو الحذف', 'تنفذ العملية بعد مراجعة البيانات.'),
              node('saved', 'end', 'حفظ مباشر', 'تُحفظ العملية فورًا؛ لا توجد مرحلة موافقة.', [branch('عند رفض الحفظ (403/422)', 'open')]),
            ],
          }],
          sources: [source(HR + 'EmployeesPage.jsx', 'إضافة موظف', 'تعديل', 'حذف'), RESOURCE_AUTH],
        },
        {
          id: 'positions',
          title: 'المناصب الوظيفية',
          summary: 'عرض المناصب، وإضافتها وتعديلها وحذفها لمن يملك صلاحية الإدارة.',
          link: { to: '/hr/positions' },
          steps: [
            step('افتح «المناصب» لعرض القائمة.'),
            step('استخدم «إضافة منصب» أو التعديل أو الحذف.', { access: { permissions: ['hr.manage'] } }),
          ],
          sources: [source(HR + 'PositionsPage.jsx', 'إضافة منصب')],
        },
        {
          id: 'faculty',
          title: 'قائمة هيئة التدريس',
          summary: 'صفحة للعرض والبحث فقط، مع الانتقال إلى ملف الموظف.',
          link: { to: '/hr/faculty' },
          steps: [step('افتح «هيئة التدريس»، ابحث بالاسم أو التخصص أو الرتبة، ثم اضغط «عرض الملف».')],
          sources: [source(HR + 'FacultyPage.jsx', 'عرض الملف', 'ابحث بالاسم أو التخصص أو الرتبة')],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    { id: 'hr-403', title: 'الزر ظاهر لكن الحفظ يُرفض (403)', who: 'admin', steps: ['بعض أزرار الإضافة والتعديل تظهر لمن يملك صلاحية العرض فقط، لكن الخادم يطلب صلاحية إدارة الموارد البشرية. اطلبها من مسؤول الحسابات إن كانت من مهامك.'] },
  ],
}

export const academicStructure = {
  id: 'academicStructure',
  title: 'الهيكل الأكاديمي',
  intro: 'تعرض هذه البوابة الكليات والأقسام والاختصاصات، وتتيح لمن يملك صلاحية الإدارة إضافتها وتعديلها وحذفها مباشرة.',
  sections: [
    {
      id: 'structure',
      title: 'الكليات والأقسام والاختصاصات',
      tasks: [
        {
          id: 'structure-view',
          title: 'عرض الهيكل الأكاديمي',
          summary: 'قوائم الكليات والأقسام والاختصاصات.',
          link: { to: '/academic-structure/colleges' },
          steps: [
            step('«الكليات».', { link: { to: '/academic-structure/colleges' } }),
            step('«الأقسام».', { link: { to: '/academic-structure/departments' } }),
            step('«الاختصاصات».', { link: { to: '/academic-structure/programs' } }),
          ],
          sources: [source('frontend/src/features/academic-structure/nav.js', "'/academic-structure/colleges'", "'/academic-structure/departments'", "'/academic-structure/programs'")],
        },
        {
          id: 'structure-manage',
          title: 'إضافة كلية أو قسم أو اختصاص وتعديلها',
          summary: 'تتطلب صلاحية إدارة الهيكل الأكاديمي؛ تُحفظ مباشرة دون اعتماد.',
          access: { permissions: ['academic_structure.manage'] },
          link: { to: '/academic-structure/colleges' },
          steps: [
            step('استخدم «إضافة كلية» أو «إضافة قسم» أو «إضافة اختصاص» ثم «حفظ».'),
            step('الحذف بعد التأكيد؛ تأكد أولًا من عدم ارتباط العنصر بطلاب أو برامج.'),
          ],
          sources: [
            source(AS + 'CollegesPage.jsx', 'إضافة كلية'),
            source(AS + 'DepartmentsPage.jsx', 'إضافة قسم'),
            source(AS + 'ProgramsPage.jsx', 'إضافة اختصاص'),
            RESOURCE_AUTH,
          ],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    { id: 'as-403', title: 'الزر ظاهر لكن الحفظ يُرفض (403)', who: 'admin', steps: ['أزرار الإضافة والتعديل تظهر لمن يملك صلاحية العرض، لكن الخادم يطلب صلاحية إدارة الهيكل الأكاديمي.'] },
  ],
}

export const technical = {
  id: 'technical',
  title: 'المكتب التقني',
  intro: 'تنشئ من هذه البوابة حسابات المستخدمين، وتسند الأدوار المسموح بها أو تسحبها، وتفعّل الحسابات أو تعطّلها. الصلاحيات تُكتسب من الأدوار فقط، والتبعية التنظيمية للمكتب لا تمنح صلاحية.',
  sections: [
    {
      id: 'accounts',
      title: 'الحسابات والصلاحيات',
      tasks: [
        {
          id: 'accounts-view',
          title: 'البحث عن حساب وعرض أدواره وصلاحياته',
          summary: 'تعرض تفاصيل الحساب الأدوار المسندة والصلاحيات الناتجة عن كل دور.',
          access: ACCESS.technicalAccounts,
          link: { to: '/technical/accounts' },
          steps: [step('افتح «الحسابات والصلاحيات»، ابحث أو صفِّ حسب الحالة والدور، ثم اضغط أيقونة العرض.')],
          sources: [source('frontend/src/features/technical-portal/pages/AccountsPermissionsPage.jsx', 'الحسابات والصلاحيات')],
        },
        {
          id: 'accounts-manage',
          title: 'إنشاء حساب وإسناد الأدوار وتفعيله أو تعطيله',
          summary: 'يُسمح للفريق التقني بإسناد الأدوار التشغيلية المحددة فقط؛ الأدوار الأخرى وتعديل حسابك الشخصي محصوران بمدير النظام.',
          access: ACCESS.technicalAccountsManage,
          link: { to: '/technical/accounts' },
          steps: [
            step('اضغط «إنشاء حساب»، أدخل اسم المستخدم والبريد وكلمة مرور قوية وتأكيدها، واختر الأدوار المتاحة.'),
            step('من تفاصيل الحساب اختر دورًا ثم «إسناد الدور»، أو «سحب الدور» لدور مسند.'),
            step('«تعطيل الحساب» ينهي جلساته الحالية؛ «تفعيل الحساب» يعيده.'),
          ],
          flows: [{
            title: 'مسار إدارة حساب',
            nodes: [
              node('create', 'start', 'إنشاء الحساب', 'تُشفَّر كلمة المرور على الخادم ولا تُعرض مجددًا.'),
              node('assign', 'action', 'إسناد الأدوار المسموح بها', 'يتحقق الخادم من كل دور؛ الأدوار المحجوزة تُرفض (403).'),
              node('check', 'review', 'تحقق الخادم من القواعد', 'يرفض الخادم تعديل حسابك الشخصي أو حساب محمي أو سحب دور آخر مدير نظام.', [branch('إذا كان الإجراء مسموحًا', 'done'), branch('إذا خالف القواعد', 'denied')]),
              node('denied', 'return', 'رفض الإجراء', 'تظهر رسالة توضح السبب ولا يتغير شيء.'),
              node('done', 'end', 'تحديث الحساب وتسجيله في سجل التدقيق', 'تتغير الصلاحيات الفعلية للحساب تبعًا لأدواره.'),
            ],
          }],
          sources: [
            source('frontend/src/features/technical-portal/pages/AccountsPermissionsPage.jsx', 'إنشاء حساب'),
            source('frontend/src/features/technical-portal/components/AccountDetailPanel.jsx', 'إسناد الدور', 'سحب الدور', 'تعطيل الحساب', 'تفعيل الحساب'),
            source('backend/app/Services/AccountAdministrationService.php', 'role_not_assignable', 'self_change_forbidden', 'last_super_admin'),
          ],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    { id: 'role-denied', title: 'رسالة «هذا الدور ليس ضمن الأدوار المسموح…»', who: 'admin', steps: ['الأدوار القيادية والإدارية ودور الفريق التقني لا يسندها إلا مدير النظام؛ ارفع الطلب إليه.'] },
  ],
}
