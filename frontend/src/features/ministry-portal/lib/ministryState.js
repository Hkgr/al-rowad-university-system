// Pure helpers for the Ministry of Education portal (importable in node tests).

/** Query keys each list page accepts; anything else in the URL is ignored. */
export const LIST_FILTERS = Object.freeze({
  students: ['search', 'college_id', 'department_id', 'program_id', 'status', 'level_id', 'enrollment_year_id', 'registered_year_id', 'registered_semester_id', 'graduated_year_id'],
  courses: ['search', 'college_id', 'department_id', 'program_id', 'active', 'offered_year_id', 'offered_semester_id'],
  faculty: ['search', 'college_id', 'active'],
  deans: ['search', 'college_id', 'state'],
})

export const DASHBOARD_FILTERS = Object.freeze(['academic_year_id', 'semester_id', 'college_id', 'program_id'])

/** Reads the allowed filters of a page from URLSearchParams (or a plain object). */
export function readFilters(page, params) {
  const get = key => (typeof params?.get === 'function' ? params.get(key) : params?.[key]) ?? ''
  const keys = page === 'dashboard' ? DASHBOARD_FILTERS : (LIST_FILTERS[page] ?? [])
  return Object.fromEntries(keys.map(key => [key, String(get(key)).trim()]))
}

/** Builds a stable query string: allowed, non-empty keys in declared order, then page/per_page. */
export function buildQuery(page, filters, { pageNumber, perPage } = {}) {
  const keys = page === 'dashboard' ? DASHBOARD_FILTERS : (LIST_FILTERS[page] ?? [])
  const params = new URLSearchParams()
  for (const key of keys) {
    const value = String(filters?.[key] ?? '').trim()
    if (value !== '') params.set(key, value)
  }
  if (pageNumber && pageNumber > 1) params.set('page', String(pageNumber))
  if (perPage) params.set('per_page', String(perPage))
  return params.toString()
}

export const hasActiveFilters = filters => Object.values(filters ?? {}).some(value => String(value ?? '').trim() !== '')

/** A server link (/ministry/students?…) is a portal route; anything else is refused. */
export function portalLink(link) {
  return typeof link === 'string' && /^\/ministry(\/[a-z-]+)*(\/[0-9A-Za-z-]+)?(\?[A-Za-z0-9_=&%.-]*)?$/.test(link) ? link : null
}

export function formatNumber(value) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return 'غير متاح'
  return new Intl.NumberFormat('ar-SY').format(Number(value))
}

export function formatPercent(value) {
  if (value === null || value === undefined) return 'غير متاح'
  return `${new Intl.NumberFormat('ar-SY', { maximumFractionDigits: 1 }).format(Number(value))}٪`
}

export function formatDate(value) {
  if (!value) return '—'
  const date = new Date(String(value).length === 10 ? `${value}T00:00:00` : value)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('ar-SY', { year: 'numeric', month: 'long', day: 'numeric' })
}

export function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ar-SY', { dateStyle: 'medium', timeStyle: 'short' })
}

/** loading → error (forbidden / notFound / error) → empty (filtered or not) → ready */
export function viewState({ loading, error, rows, filtered }) {
  if (loading) return 'loading'
  if (error) return error.status === 403 ? 'forbidden' : error.status === 404 ? 'notFound' : 'error'
  if (Array.isArray(rows) && rows.length === 0) return filtered ? 'emptyFiltered' : 'empty'
  return 'ready'
}

export function errorMessage(error) {
  if (!error) return ''
  if (error.status === 403) return 'لا يملك حسابك صلاحية الاطلاع على هذا القسم من بوابة الوزارة.'
  if (error.status === 404) return 'السجل المطلوب غير موجود.'
  if (error.status === 422) return Object.values(error.details ?? {}).flat()[0] ?? error.message ?? 'قيمة مرشح غير صالحة.'
  if (error.status === 0 || error.message === 'Failed to fetch') return 'تعذّر الاتصال بالخادم. تحقق من الشبكة ثم أعد المحاولة.'
  return error.message || 'تعذّر تحميل البيانات.'
}

/** Programs offered in a select after a college filter (or all). */
export const programsOf = (programs, collegeId) => (programs ?? []).filter(p => !collegeId || String(p.college_id) === String(collegeId))
export const departmentsOf = (departments, collegeId) => (departments ?? []).filter(d => !collegeId || String(d.college_id) === String(collegeId))

/** Names of the filters currently applied, for the "المرشحات المطبقة" line. */
export function appliedFilterLabels(filters, options) {
  const find = (list, id) => (list ?? []).find(item => String(item.id ?? item.code) === String(id))?.name
  const labels = []
  if (filters.academic_year_id) labels.push(`السنة: ${find(options.academic_years, filters.academic_year_id) ?? filters.academic_year_id}`)
  if (filters.semester_id) labels.push(`الفصل: ${find(options.semesters, filters.semester_id) ?? filters.semester_id}`)
  if (filters.college_id) labels.push(`الكلية: ${find(options.colleges, filters.college_id) ?? filters.college_id}`)
  if (filters.program_id) labels.push(`البرنامج: ${find(options.programs, filters.program_id) ?? filters.program_id}`)
  if (filters.department_id) labels.push(`القسم: ${find(options.departments, filters.department_id) ?? filters.department_id}`)
  if (filters.status) labels.push(`الحالة: ${find(options.student_statuses, filters.status) ?? filters.status}`)
  if (filters.level_id) labels.push(`السنة الدراسية: ${find(options.levels, filters.level_id) ?? filters.level_id}`)
  if (filters.enrollment_year_id) labels.push(`سنة الالتحاق: ${find(options.academic_years, filters.enrollment_year_id) ?? filters.enrollment_year_id}`)
  if (filters.registered_year_id) labels.push(`مسجل في: ${find(options.academic_years, filters.registered_year_id) ?? filters.registered_year_id}${filters.registered_semester_id ? ` — ${find(options.semesters, filters.registered_semester_id) ?? ''}` : ''}`)
  if (filters.graduated_year_id) labels.push(`تخرج في: ${find(options.academic_years, filters.graduated_year_id) ?? filters.graduated_year_id}`)
  if (filters.offered_year_id) labels.push(`مطروح في: ${find(options.academic_years, filters.offered_year_id) ?? filters.offered_year_id}${filters.offered_semester_id ? ` — ${find(options.semesters, filters.offered_semester_id) ?? ''}` : ''}`)
  if (filters.active === '1') labels.push('النشط فقط')
  if (filters.active === '0') labels.push('غير النشط فقط')
  if (filters.state === 'current') labels.push('الحاليون')
  if (filters.state === 'historical') labels.push('السابقون')
  if (filters.search) labels.push(`بحث: «${filters.search}»`)
  return labels
}

/** Definitions shown next to each dashboard indicator (mirrors docs/ministry-portal.md). */
export const INDICATORS = Object.freeze({
  colleges: { label: 'الكليات', definition: 'الكليات المفعّلة في النظام (السجل الحالي). غير المفعّلة تُذكر منفصلة.' },
  departments: { label: 'الأقسام', definition: 'الأقسام المفعّلة.' },
  programs: { label: 'البرامج', definition: 'البرامج المفعّلة وغير المؤرشفة.' },
  students: { label: 'الطلاب', definition: 'طلاب فريدون غير محذوفين، بكل الحالات (لقطة حالية). الكلية = كلية برنامج الطالب الحالي.' },
  active_students: { label: 'الطلاب النشطون', definition: 'الطلاب الفريدون الذين حالتهم الحالية «نشط».' },
  faculty: { label: 'أعضاء الهيئة التدريسية', definition: 'أعضاء فريدون ملفهم التدريسي مفعّل. مع مرشح الكلية: المنتمون إليها بالوحدة الأساسية أو بتكليف فعّال (قد ينتمي عضو لأكثر من كلية).' },
  deans: { label: 'العمداء الحاليون', definition: 'تكليفات عمادة حالية (دور العميد ونطاق الكلية فعّالان، أو قيد منصب عميد مفتوح).' },
  vice_presidents: { label: 'نواب الرئيس (حسابات)', definition: 'حسابات فعّالة تحمل دور نائب رئيس. المناصب غير المسجلة لا تُحسب.' },
  courses: { label: 'المقررات', definition: 'تعريفات المقررات المفعّلة، كل مقرر مرة واحدة. مع مرشح البرنامج: مقررات خطته الفعّالة فقط.' },
  registered_students: { label: 'الطلاب المسجلون في الفترة', definition: 'طلاب فريدون لهم تسجيل «مسجل» أو «مكتمل» في طرح من الفترة. المنسحب والملغى لا يُحسبان.' },
  offerings: { label: 'الطروحات في الفترة', definition: 'عدد طروحات المقررات في الفترة (كل طرح مرة). الكلية = كلية برنامج الطرح أو قسمه.' },
  offered_courses: { label: 'المقررات المطروحة', definition: 'مقررات فريدة لها طرح واحد على الأقل في الفترة.' },
  official_results: { label: 'النتائج المعتمدة', definition: 'نتائج مقررات اعتُمدت علاماتها رسميًا (آخر اعتماد للطرح «معتمد»). المسودات وقيد المراجعة والمعادة للتصحيح مستبعدة.' },
  graduates: { label: 'الخريجون', definition: 'طلاب فريدون لهم قرار تخرج معتمد ونافذ غير مستبدل، بتاريخ اعتماد ضمن السنة الأكاديمية.' },
})
