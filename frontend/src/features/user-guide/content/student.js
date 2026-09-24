import { node, branch, source, step } from './helpers.js'

const SUPP_ACCESS = { studentIdentity: true, allRoles: ['student'], assignedPermissions: ['supplementary_exams.deferrals.self', 'supplementary_exams.registrations.self'] }
const REG = 'frontend/src/features/student-dashboard/pages/StudentRegistration.jsx'

export default {
  id: 'student',
  title: 'بوابة الطالب',
  intro: 'من هذه البوابة ترسل طلب تسجيل المواد إلى المرشد الأكاديمي وتتابع حالته، وتطّلع على نتائجك الرسمية المعتمدة ومعدلك وحضورك وخطتك الدراسية، وتدير طلبات الامتحانات التكميلية عند فتح فترتها.',
  sections: [
    {
      id: 'registration',
      title: 'تسجيل المواد',
      access: { permissions: ['registration.view'] },
      tasks: [
        {
          id: 'registration-request',
          title: 'إرسال طلب تسجيل المواد',
          summary: 'التسجيل طلب يراجعه المرشد الأكاديمي المخوّل؛ لا يصبح رسميًا إلا بعد اعتماده.',
          link: { to: '/student/registration' },
          steps: [
            step('افتح «تسجيل المواد» واختر الفصل الدراسي.'),
            step('راجع «المواد المتاحة»؛ تظهر بجانب كل مادة حالتها (متاح، متطلب سابق غير محقق، تجاوز الساعات…).'),
            step('أضف المواد المطلوبة إلى الطلب، ويمكنك حذفها أو كتابة ملاحظات قبل الإرسال.'),
            step('اضغط «إرسال الطلب للمرشد الأكاديمي» ثم أكّد الإرسال.'),
            step('تابع حالة الطلب في الصفحة نفسها. إذا أُعيد إليك تظهر «ملاحظات المرشد الأكاديمي»؛ عدّل الطلب ثم اضغط «إعادة إرسال الطلب».'),
          ],
          flows: [{
            title: 'مسار طلب التسجيل',
            nodes: [
              node('open', 'start', 'فتح صفحة تسجيل المواد', 'تبدأ من صفحة «تسجيل المواد» خلال فترة التسجيل المفتوحة.'),
              node('draft', 'action', 'تجهيز الطلب (مسودة)', 'تضيف المواد وتحذفها؛ يبقى الطلب مسودة قابلة للتعديل.'),
              node('submit', 'action', 'إرسال الطلب للمرشد الأكاديمي', 'بعد الإرسال يصبح الطلب «مرسل» ولا يمكن تعديله إلا إذا أُعيد إليك.'),
              node('review', 'review', 'مراجعة المرشد الأكاديمي أو العميد المخوّل', 'يراجع الطلبَ صاحبُ صلاحية مراجعة طلبات التسجيل ضمن نطاقك.', [
                branch('عند الاعتماد', 'approved'),
                branch('عند الإرجاع مع سبب', 'returned'),
                branch('عند انتهاء المهلة دون قرار', 'expired'),
              ]),
              node('returned', 'return', 'معاد للتعديل', 'تقرأ ملاحظات المرشد، تعدّل الطلب، ثم تعيد إرساله فيعود إلى خطوة الإرسال.', [branch('إعادة الإرسال', 'submit')]),
              { ...node('expired', 'system', 'انتهت مهلة الاعتماد', 'إذا انتهت مهلة قرار المرشد يصبح الطلب منتهيًا ولا يُعتمد تلقائيًا.'), terminal: true },
              node('approved', 'end', 'معتمد — تصبح المواد مسجلة رسميًا', 'عند الاعتماد تُنشأ تسجيلاتك الرسمية وتظهر في «مسجل ومعتمد سابقاً».'),
            ],
          }],
          sources: [
            source(REG, 'إرسال الطلب للمرشد الأكاديمي', 'إعادة إرسال الطلب', 'ملاحظات المرشد الأكاديمي', 'انتهت مهلة اعتماد المرشد الأكاديمي'),
            source('backend/app/Models/StudentRegistrationRequest.php', "'draft'", "'submitted'", "'returned'", "'approved'", "'expired'"),
            source('backend/routes/api.php', 'academic-advising/registration-requests'),
          ],
        },
        {
          id: 'registration-modification',
          title: 'تعديل تسجيل معتمد أو استبدال مقرر ملغى',
          summary: 'بعد اعتماد تسجيلك يمكنك — ضمن المهلة — تقديم طلب تعديل، أو طلب استبدال مقرر أُلغي لعدم اكتمال الحد الأدنى. يمرّ كلاهما بالمراجعة نفسها.',
          link: { to: '/student/registration' },
          steps: [
            step('في «تعديل التسجيل المعتمد» حدّد المواد المراد إزالتها أو أضف مواد جديدة، ثم اضغط «إرسال التعديل».'),
            step('لاستبدال مقرر ملغى استخدم «إنشاء طلب استبدال» ثم «إضافة البديل» ثم «إرسال الطلب».'),
            step('إذا أُعيد الطلب فعدّله وأعد إرساله؛ وعند الاعتماد يُحدَّث تسجيلك الرسمي.'),
          ],
          sources: [
            source(REG, 'تعديل التسجيل المعتمد', 'إرسال التعديل', 'إنشاء طلب استبدال', 'إضافة البديل'),
            source('backend/app/Support/RegistrationModificationWorkflow.php', "'submitted'", "'returned'", "'approved'", "'superseded'"),
          ],
        },
      ],
    },
    {
      id: 'results',
      title: 'النتائج والتقدم الأكاديمي',
      tasks: [
        {
          id: 'transcript',
          title: 'الاطلاع على كشف الدرجات والمعدل',
          summary: 'يعرض الكشف النتائج المعتمدة رسميًا فقط؛ العلامة التي لم تُعتمد بعد لا تظهر فيه.',
          access: { permissions: ['grades.view'] },
          link: { to: '/student/transcript' },
          steps: [
            step('افتح «كشف الدرجات» لعرض المواد ونتائجها المعتمدة.', { link: { to: '/student/transcript' } }),
            step('افتح «المعدل» لمتابعة معدلك الفصلي والتراكمي.', { link: { to: '/student/gpa' } }),
            step('إذا ظهرت رسالة «لا توجد نتائج معتمدة متاحة حتى الآن» فالنتائج لم تُعتمد رسميًا بعد.'),
          ],
          sources: [source('frontend/src/features/student-dashboard/pages/StudentTranscript.jsx', 'لا توجد نتائج معتمدة متاحة حتى الآن')],
        },
        {
          id: 'progress',
          title: 'متابعة الخطة والتقدم',
          summary: 'صفحة للقراءة تعرض متطلبات خطتك وما أنجزته منها وأهلية التخرج.',
          access: { permissions: ['grades.view'] },
          link: { to: '/student/requirements' },
          steps: [step('افتح «الخطة والتقدم» وراجع المتطلبات المنجزة والمتبقية.')],
          sources: [source('backend/routes/api.php', 'student/requirements')],
        },
        {
          id: 'attendance',
          title: 'متابعة الحضور والغياب',
          summary: 'صفحة للقراءة تعرض نسب حضورك وغيابك لكل مادة.',
          access: { permissions: ['attendance.view'] },
          link: { to: '/student/attendance' },
          steps: [step('افتح «الحضور والغياب». عند ارتفاع نسبة الغياب راجع مدرّس المادة مبكرًا.')],
          sources: [source('backend/routes/api.php', 'student/attendance-overview')],
        },
      ],
    },
    {
      id: 'supplementary',
      title: 'الامتحانات التكميلية',
      access: SUPP_ACCESS,
      tasks: [
        {
          id: 'supplementary',
          title: 'تأجيل النظري أو التسجيل في الامتحان التكميلي',
          summary: 'تظهر الإجراءات فقط عندما تسمح بها حالة الدورة التكميلية وأهليتك.',
          link: { to: '/student/supplementary-exams' },
          steps: [
            step('عندما تكون الدورة «معلنة» يمكنك «تأجيل النظري إلى التكميلي» أو «إلغاء التأجيل».'),
            step('عندما يُفتح التسجيل اضغط «التسجيل في التكميلي» للمادة المؤهلة، ويمكنك «إلغاء التسجيل» ما دام التسجيل مفتوحًا.'),
            step('بعد النشر تظهر النتيجة، وعند ترحيلها رسميًا تظهر رسالة «تم تحديث نتيجتك الأكاديمية الرسمية» ورابط «عرض كشف الدرجات».'),
          ],
          flows: [{
            title: 'مسار الامتحان التكميلي للطالب',
            nodes: [
              node('announced', 'start', 'إعلان الدورة التكميلية', 'تعلن نيابة الشؤون العلمية الدورة؛ عندها يمكن تأجيل النظري لمن لم تصبح نتيجته رسمية.'),
              node('register', 'action', 'التسجيل في التكميلي', 'عندما يفتح مكتب التسجيل الفترة تسجّل في المواد المؤهلة ويمكنك الإلغاء ما دامت الفترة مفتوحة.'),
              node('grading', 'review', 'التصحيح والمراجعة', 'يصحح الأستاذ المكلّف ثم يراجع موظف الامتحانات ويعتمد أو يعيد الدفعة للتصحيح.'),
              node('published', 'system', 'نشر النتيجة', 'تظهر لك النتيجة المنشورة قبل تحديث السجل الرسمي.'),
              node('official', 'end', 'ترحيل النتيجة إلى السجل الرسمي', 'بعد الترحيل تظهر النتيجة في كشف الدرجات الرسمي.'),
            ],
          }],
          sources: [
            source('frontend/src/features/student-dashboard/pages/StudentSupplementaryExams.jsx', 'تأجيل النظري إلى التكميلي', 'إلغاء التأجيل', 'التسجيل في التكميلي', 'إلغاء التسجيل', 'تم تحديث نتيجتك الأكاديمية الرسمية'),
            source('frontend/src/features/supplementary-exams/supplementaryStatus.js', 'announced', 'registration_open', 'results_published', 'results_materialized'),
          ],
        },
      ],
    },
  ],
  unavailable: [],
  troubleshooting: [
    { id: 'reg-window', title: 'زر الإرسال غير ظاهر في تسجيل المواد', who: 'user', access: { permissions: ['registration.view'] }, steps: ['التعديل متاح فقط والطلب «مسودة» أو «معاد للتعديل» وفترة التسجيل مفتوحة.', 'إذا كان الطلب «مرسل» فانتظر قرار المرشد، ولا ترسل طلبًا آخر.'] },
  ],
}
