import { node, branch, source, step } from './helpers.js'

const ATT = 'frontend/src/features/professor-dashboard/pages/AttendanceDeprivationPage.jsx'

export default {
  id: 'professor',
  title: 'بوابة الأستاذ',
  intro: 'تُدخل من هذه البوابة علامات المواد المكلّف بها وترسلها إلى هيئة الامتحانات للاعتماد، وتسجّل جلسات الحضور، وتصحّح الامتحانات التكميلية المسندة إليك.',
  sections: [
    {
      id: 'grades',
      title: 'العلامات',
      access: { employeeIdentity: true, permissions: ['grades.manage'] },
      tasks: [
        {
          id: 'grade-parts',
          title: 'إدخال العلامات وإرسالها للاعتماد',
          summary: 'تُدخل الجزء المكلّف به (النظري أو العملي)، وتحفظه مسودة، ثم ترسله إلى هيئة الامتحانات التي تعتمده أو تعيده للتصحيح.',
          link: { to: '/professor/grades' },
          steps: [
            step('افتح «إدارة العلامات» واختر المادة من المواد المكلّف بها.'),
            step('أدخل العلامات للجزء المسند إليك؛ الجزء المسند لغيرك يظهر مقفلًا.'),
            step('اضغط «حفظ مسودة» ويمكنك العودة للتعديل لاحقًا.'),
            step('عند الاكتمال اضغط زر «إرسال … إلى هيئة الامتحانات» ثم «تأكيد الإرسال».'),
            step('إذا ظهرت الحالة «معاد للتصحيح» فعدّل العلامات وأعد الإرسال.'),
          ],
          flows: [{
            title: 'مسار اعتماد جزء العلامة',
            nodes: [
              node('open', 'start', 'اختيار المادة المكلّف بها', 'تبدأ من «إدارة العلامات» وتختار المادة.'),
              node('draft', 'action', 'إدخال العلامات وحفظها مسودة', 'تبقى العلامات مسودة قابلة للتعديل ولا يراها الطالب.'),
              node('submit', 'action', 'الإرسال إلى هيئة الامتحانات', 'يصبح الجزء «مرسلًا» ويُقفل التعديل.'),
              node('review', 'review', 'مراجعة هيئة الامتحانات', 'تراجع الهيئة الجزء المرسل.', [branch('عند الاعتماد', 'approved'), branch('عند الإعادة للتصحيح', 'returned')]),
              node('returned', 'return', 'معاد للتصحيح', 'يعود الجزء قابلًا للتعديل مع سبب الإعادة، ثم تعيد إرساله.', [branch('إعادة الإرسال', 'submit')]),
              node('approved', 'end', 'معتمد — تصبح النتيجة رسمية عند اعتماد كل الأجزاء', 'بعد اعتماد جميع الأجزاء المطلوبة تصبح النتيجة رسمية وتظهر للطالب في كشف درجاته.'),
            ],
          }],
          sources: [
            source('frontend/src/features/professor-dashboard/pages/ProfessorGradesPage.jsx', 'حفظ مسودة', 'إلى هيئة الامتحانات', 'تأكيد الإرسال', 'معاد للتصحيح'),
            source('backend/app/Models/GradePartApproval.php', 'draft', 'submitted', 'approved', 'returned'),
          ],
        },
      ],
    },
    {
      id: 'attendance',
      title: 'الحضور والحرمان',
      access: { employeeIdentity: true, permissions: ['attendance.manage'] },
      tasks: [
        {
          id: 'attendance-session',
          title: 'تسجيل جلسة حضور',
          summary: 'تنشئ جلسة لكل محاضرة ثم تسجّل حالة كل طالب. لا توجد مرحلة اعتماد للحضور.',
          link: { to: '/professor/attendance' },
          steps: [
            step('افتح «الحضور والحرمان» ثم تبويب «جلسات الحضور» واختر المادة.'),
            step('أدخل التاريخ والنوع والموضوع ثم اضغط «إضافة جلسة».'),
            step('اختر الجلسة وحدد لكل طالب: حاضر، غائب، غياب بعذر، أو متأخر.'),
            step('اضغط «حفظ الحضور».'),
          ],
          flows: [{
            title: 'مسار تسجيل الحضور',
            nodes: [
              node('course', 'start', 'اختيار المادة', 'تبدأ من «جلسات الحضور».'),
              node('session', 'action', 'إضافة جلسة', 'تحدد تاريخ الجلسة ونوعها وموضوعها.'),
              node('record', 'action', 'تسجيل حالة الطلاب', 'حاضر أو غائب أو غياب بعذر أو متأخر.'),
              node('saved', 'end', 'حفظ الحضور', 'يُحفظ السجل مباشرة ويُحتسب في نسب الغياب؛ لا توجد موافقة لاحقة.'),
            ],
          }],
          sources: [source(ATT, 'جلسات الحضور', 'إضافة جلسة', 'حاضر', 'غائب', 'غياب بعذر', 'متأخر', 'حفظ الحضور')],
        },
        {
          id: 'deprivation',
          title: 'مراجعة المرشحين للحرمان',
          summary: 'يعرض تبويب «الحرمان» الطلاب المتجاوزين لنسبة الغياب المسموحة.',
          link: { to: '/professor/attendance' },
          steps: [
            step('افتح تبويب «الحرمان» واختر المادة لعرض المرشحين.'),
            step('تطبيق الحرمان يتطلب صلاحية هيئة الامتحانات ونطاق المادة؛ يظهر لك هذا الإجراء أدناه فقط إذا كنت تملكها.', { access: { permissions: ['exams.manage'] } }),
            step('اضغط «تطبيق الحرمان» وأكّد؛ راجع النتيجة المعروضة قبل أي محاولة أخرى.', { access: { permissions: ['exams.manage'] } }),
          ],
          sources: [
            source(ATT, 'تطبيق الحرمان', 'apply-deprivation'),
            source('backend/app/Http/Controllers/Api/CourseOfferingController.php', 'assertExaminationCommittee(request()->user())', 'assertCanAccessOffering(request()->user(), $id)'),
          ],
        },
      ],
    },
    {
      id: 'supplementary',
      title: 'الامتحانات التكميلية',
      access: { employeeIdentity: true, allRoles: ['doctor_instructor'], assignedPermissions: ['supplementary_exams.grades.view'] },
      tasks: [
        {
          id: 'supplementary-grading',
          title: 'تصحيح الامتحان التكميلي',
          summary: 'تظهر فقط المواد التي أسندتك هيئة الامتحانات لتصحيحها.',
          link: { to: '/professor/supplementary-exams' },
          steps: [
            step('افتح «الامتحانات التكميلية» واختر المادة المسندة إليك.'),
            step('أدخل العلامات ثم «حفظ المسودة» (متاح أثناء فترة التصحيح).'),
            step('اضغط «إرسال الدفعة»؛ وإذا أُعيدت لك مع سبب فعدّلها ثم «إعادة الإرسال».'),
          ],
          flows: [{
            title: 'مسار دفعة العلامات التكميلية',
            nodes: [
              node('assigned', 'start', 'إسنادك مصححًا', 'تسند هيئة الامتحانات المادة إليك.'),
              node('draft', 'action', 'إدخال العلامات وحفظ المسودة', 'متاح فقط أثناء فترة التصحيح.'),
              node('submit', 'action', 'إرسال الدفعة', 'تصبح الدفعة مرسلة للمراجعة.'),
              node('review', 'review', 'مراجعة موظف الامتحانات', 'يعتمد الدفعة أو يعيدها مع سبب.', [branch('عند الاعتماد', 'approved'), branch('عند الإعادة', 'returned')]),
              node('returned', 'return', 'معادة للتصحيح', 'تعدّل العلامات ثم «إعادة الإرسال».', [branch('إعادة الإرسال', 'submit')]),
              node('approved', 'end', 'معتمدة ثم تُنشر', 'بعد الاعتماد تنشر هيئة الامتحانات النتائج.'),
            ],
          }],
          sources: [
            source('frontend/src/features/professor-dashboard/pages/ProfessorSupplementaryExams.jsx', 'حفظ المسودة', 'إرسال الدفعة', 'إعادة الإرسال'),
            source('backend/app/Services/SupplementaryExamGradingService.php', "'submitted'", "'returned'", "'approved'", "'published'"),
          ],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    {
      id: 'deprivation-403',
      title: 'رسالة 403 عند «تطبيق الحرمان»',
      who: 'admin',
      access: { employeeIdentity: true, permissions: ['attendance.manage'] },
      steps: [
        'قد يظهر زر «تطبيق الحرمان» في صفحة الحضور، لكن الخادم يسمح به فقط لمن يملك صلاحية هيئة الامتحانات وضمن نطاق المادة.',
        'ظهور 403 هنا متوقع إذا لم تكن من هيئة الامتحانات؛ لا تكرر المحاولة وتواصل مع هيئة الامتحانات لتطبيق الحرمان.',
      ],
    },
    { id: 'locked-part', title: 'الجزء مقفل ولا يمكن تعديله', who: 'user', access: { employeeIdentity: true, permissions: ['grades.manage'] }, steps: ['الجزء المرسل أو المعتمد مقفل. انتظر قرار هيئة الامتحانات؛ وإذا أُعيد للتصحيح يصبح قابلًا للتعديل.', 'الجزء المسند لمدرّس آخر يظهر للقراءة فقط.'] },
  ],
}
