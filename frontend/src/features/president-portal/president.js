export const SECTIONS = ['dashboard', 'colleges', 'students', 'exams', 'staff', 'leadership', 'followup', 'reports']
export const presidentAccess = (section = 'access') => ({ allRoles: ['university_president'], assignedPermissions: ['president_portal.access', ...(section === 'access' ? [] : [`president_portal.${section}.view`])], actualUniversityScope: true })
export const RESOURCES = {
  colleges: ['الكليات والمعاهد', 'colleges', 'college_id', ['college_name', 'is_active', 'departments', 'programs', 'students', 'faculty']],
  programs: ['البرامج الأكاديمية', 'colleges', 'id', ['program_name', 'college_name', 'department_name', 'is_active', 'plan_state']],
  courses: ['المواد', 'colleges', 'course_id', ['course_code', 'course_name', 'credit_hours', 'is_active']],
  students: ['الطلاب والشؤون الأكاديمية', 'students', 'student_id', ['student_number', 'full_name', 'college', 'program', 'level', 'status']],
  faculty: ['المدرسون والكوادر', 'staff', 'faculty_member_id', ['full_name', 'academic_rank', 'colleges', 'is_active']],
  deans: ['العمداء المسجلون', 'staff', 'person', ['full_name', 'college', 'state_label', 'position_state_label', 'start_date', 'end_date']],
  leadership: ['النيابات والإدارات', 'leadership', 'id', ['unit_name', 'unit_code', 'type_name', 'is_active']],
  exams: ['الامتحانات والنتائج', 'exams', 'id', ['course_code', 'course_name', 'academic_year_id', 'semester_id', 'status', 'final_approval_status']],
  results: ['النتائج الرسمية', 'exams', 'id', ['student_number', 'course_code', 'course_name', 'final_mark', 'status_code']],
}
export const FILTERS = {
  colleges: ['college_id', 'active'], programs: ['college_id', 'program_id', 'active'],
  courses: ['college_id', 'department_id', 'program_id', 'active', 'offered_year_id', 'offered_semester_id'],
  students: ['college_id', 'department_id', 'program_id', 'level_id', 'status', 'enrollment_year_id', 'registered_year_id', 'registered_semester_id', 'graduated_year_id'],
  faculty: ['college_id', 'active'], deans: ['college_id', 'state'], leadership: [],
  exams: ['college_id', 'program_id', 'academic_year_id', 'semester_id'], results: ['college_id', 'program_id', 'academic_year_id', 'semester_id'],
  dashboard: ['academic_year_id', 'semester_id', 'college_id', 'program_id'], reports: ['academic_year_id', 'semester_id', 'college_id', 'program_id'],
}
export function queryFor(resource, params) {
  const allowed = [...(FILTERS[resource] || []), ...(!['dashboard', 'reports'].includes(resource) ? ['search', 'page', 'per_page'] : [])]
  return new URLSearchParams([...params].filter(([k, v]) => allowed.includes(k) && v !== '')).toString()
}
export function changedFilter(params, key, value) {
  const next = new URLSearchParams(params); next.delete('page')
  if (value === '') next.delete(key); else next.set(key, value)
  if (key === 'college_id') { next.delete('program_id'); next.delete('department_id') }
  const dependent = { academic_year_id: 'semester_id', registered_year_id: 'registered_semester_id', offered_year_id: 'offered_semester_id' }
  if (dependent[key]) next.delete(dependent[key])
  return next
}
export const LABELS = {
  summary: 'ملخص الوحدة', organizational_unit: 'الوحدة التنظيمية', official_results: 'النتائج الرسمية', publication_note: 'حدود عرض النتائج',
  result: 'النتيجة', result_code: 'حالة النتيجة', college_code: 'رمز الكلية', department_code: 'رمز القسم', program_code: 'رمز البرنامج',
  duration_years: 'المدة بالسنوات', plan_courses: 'مواد الخطة النافذة', plan_versions: 'الإصدارات المسجلة',
  employee_status: 'حالة الموظف', active_offerings: 'تكليفات الطروحات الفعّالة', qualified_courses: 'المواد المرتبط بها',
  source: 'المصدر', type: 'النوع', linked_to_employee: 'مرتبط بسجل موظف', role_assigned_on: 'تاريخ إسناد الدور التقني (ليس تاريخ المنصب)',
  progression: 'قرارات الترفيع النافذة', progression_note: 'مصدر الترفيع', decision_result: 'نتيجة القرار',
  college_name: 'الكلية', department_name: 'القسم', program_name: 'البرنامج', course_code: 'رمز المادة', course_name: 'المادة',
  credit_hours: 'الساعات', total_credit_hours: 'الساعات المطلوبة', degree_level: 'الدرجة العلمية', plan_state: 'حالة تهيئة الخطة',
  is_active: 'مفعّل', departments: 'الأقسام', programs: 'البرامج', students: 'الطلاب', faculty: 'المدرسون', courses: 'المواد', deans: 'العمداء',
  student_number: 'رقم الطالب', full_name: 'الاسم', college: 'الكلية', program: 'البرنامج', department: 'القسم', level: 'المستوى', status: 'الحالة', status_code: 'حالة النتيجة',
  academic_rank: 'الرتبة', specialization: 'التخصص', colleges: 'الكليات المرتبطة', state_label: 'الحالة الإجمالية', position_state_label: 'حالة قيد المنصب',
  start_date: 'البداية المسجلة', end_date: 'النهاية المسجلة', unit_name: 'الوحدة', unit_code: 'رمز الوحدة', type_name: 'النوع',
  academic_year_id: 'السنة الأكاديمية', semester_id: 'الفصل', final_approval_status: 'أحدث اعتماد نهائي', final_mark: 'العلامة الرسمية',
  parts: 'أجزاء العلامات المطلوبة', component_type: 'الجزء', notice: 'توضيح', source_note: 'المصدر', notes: 'ملاحظات السجل',
  terms: 'الفصول الرسمية', results: 'النتائج الرسمية', graduation: 'التخرج', plan: 'الخطة', label: 'التسمية', version_number: 'الإصدار',
  term: 'الفصل', term_gpa: 'المعدل الفصلي الرسمي', cumulative_gpa: 'المعدل التراكمي الرسمي', attempted_hours: 'الساعات المحاولة', earned_hours: 'الساعات المكتسبة',
  enrollment_date: 'تاريخ الالتحاق', approved_at: 'تاريخ الاعتماد', positions: 'المناصب المسجلة', position: 'المنصب', unit: 'الوحدة',
  dean_assignments: 'قيود العمادة', role: 'الدور التقني', holders: 'الشاغلون المسجلون', name: 'الاسم', title: 'المسمى', code: 'الرمز',
  ancestors: 'التسلسل التنظيمي', tree: 'الوحدات التابعة', children: 'الوحدات التابعة', direct_units: 'الوحدات المباشرة', all_units: 'إجمالي الوحدات التابعة', unit_type: 'نوع الوحدة',
  teaching: 'تكليفات التدريس', assignments: 'التكليفات', offerings: 'الطروحات', instructor_role: 'دور التدريس',
  account_active: 'الحساب فعّال', role_active: 'دور العميد فعّال', college_scope_active: 'نطاق الكلية فعّال', account_current: 'الحساب مخوّل حاليًا لهذه الكلية', has_conflict: 'تعارض في السجل',
  active_students: 'الطلاب النشطون', available: 'متاح', reason: 'السبب', note: 'توضيح', exists: 'مسجل في النظام',
}
export const STATES = { active: 'فعّال', inactive: 'غير فعّال', draft: 'مسودة', submitted: 'مرسل', returned: 'معاد', approved: 'معتمد', pending: 'بانتظار المراجعة', rejected: 'مرفوض', passed: 'ناجح', failed: 'راسب', deprived: 'محروم', registered: 'مسجل', completed: 'مكتمل', cancelled: 'ملغى', closed: 'مغلق', open: 'مفتوح', OPEN: 'مفتوح', CLOSED: 'مغلق', legacy: 'الخطة السابقة', initializing: 'قيد التهيئة', ready: 'جاهز', theoretical: 'نظري', practical: 'عملي', current: 'حالي', historical: 'سابق', superseded: 'مستبدل', expired: 'منتهٍ' }
