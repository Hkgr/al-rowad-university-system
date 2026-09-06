export const UNIT_LABELS = Object.freeze({
  students: 'طالب', faculty: 'عضو', assignments: 'تكليف', offerings: 'طرح', results: 'نتيجة', parts: 'جزء',
  credit_hours: 'ساعة', mark: 'علامة', gpa: 'نقطة', percent: '%', ratio: 'نسبة', count: '',
})

export function metricValue(metric, raw) {
  if (raw && typeof raw === 'object' && Object.hasOwn(raw, 'value')) return raw.value === null ? null : Number(raw.value)
  if (raw === null || raw === undefined || raw === '') return null
  const number = Number(raw)
  return Number.isFinite(number) ? number : null
}

export function formatMetric(metric, raw) {
  if (raw && typeof raw === 'object' && raw.reason === 'zero_denominator') return { text: 'غير متاح', note: 'لا يوجد مقام صالح للحساب', value: null }
  const value = metricValue(metric, raw)
  if (value === null) return { text: 'غير متاح', note: metric?.code === 'official_gpa' ? 'لا توجد نتائج رسمية مساهمة' : '', value: null }
  const fractionDigits = ['percent', 'ratio', 'mark', 'gpa'].includes(metric?.unit) ? 2 : Number.isInteger(value) ? 0 : 2
  const text = new Intl.NumberFormat('ar-SY', { maximumFractionDigits: fractionDigits }).format(value)
  const unit = UNIT_LABELS[metric?.unit] ?? metric?.unit ?? ''
  const note = raw && typeof raw === 'object' && Object.hasOwn(raw, 'numerator')
    ? `البسط ${raw.numerator ?? '—'} من المقام ${raw.denominator ?? '—'}`
    : raw && typeof raw === 'object' && Object.hasOwn(raw, 'contributing_students')
      ? `${raw.contributing_students} طالب مساهم`
      : ''
  return { text: unit ? `${text} ${unit}` : text, note, value }
}

export function stableGroupKey(row, dimensions = []) {
  return dimensions.map(dimension => `${dimension}:${row?.[dimension] ?? ''}`).join('|')
}

export function alignComparison(primary = [], baseline = [], dimensions = []) {
  const left = new Map(primary.map(row => [stableGroupKey(row, dimensions), row]))
  const right = new Map(baseline.map(row => [stableGroupKey(row, dimensions), row]))
  return [...new Set([...left.keys(), ...right.keys()])].sort().map(key => ({ key, primary: left.get(key) ?? null, baseline: right.get(key) ?? null }))
}

export function reportRowKey(subject, row, index = 0) {
  if (subject === 'faculty') return `${row.faculty_member_id ?? 'faculty'}:${row.college_id ?? 'unassigned'}:${row.teaching_assignment_event_id ?? 'current'}`
  if (subject === 'grade_workflow') return row.grade_part_approval_event_id
    ? `${row.course_offering_id ?? 'offering'}:event:${row.grade_part_approval_event_id}`
    : `${row.course_offering_id ?? 'offering'}:${row.component_type ?? index}`
  return String(row.student_id ?? row.student_course_result_id ?? row.course_offering_id ?? row.enrollment_id ?? index)
}

const DETAIL_COLUMNS = Object.freeze({
  students: [['student_number', 'رقم الطالب'], ['full_name', 'اسم الطالب'], ['college_name', 'الكلية', 'college'], ['program_name', 'البرنامج', 'program'], ['level_name', 'المستوى', 'academic_level'], ['student_status', 'الحالة', 'student_status'], ['official_result_count', 'النتائج الرسمية'], ['official_average', 'متوسط العلامة'], ['official_gpa', 'المعدل الرسمي'], ['attempted_credit_hours', 'الساعات المحاولة'], ['earned_credit_hours', 'الساعات المجتازة']],
  enrollments: [['student_number', 'رقم الطالب'], ['full_name', 'اسم الطالب'], ['college_name', 'الكلية', 'college'], ['program_name', 'البرنامج', 'program'], ['student_status', 'الحالة', 'student_status'], ['enrollment_date', 'تاريخ الالتحاق', 'enrollment_count']],
  faculty: [['employee_number', 'الرقم الوظيفي'], ['full_name', 'عضو الهيئة', 'faculty_member'], ['academic_rank', 'الرتبة'], ['college_name', 'الكلية', 'college'], ['is_active', 'نشط', 'active_faculty_count'], ['event_type', 'الحدث'], ['created_at', 'وقت الحدث'], ['course_code', 'المقرر', 'course']],
  course_offerings: [['course_code', 'رمز المقرر', 'course'], ['course_name', 'المقرر', 'course'], ['program_name', 'البرنامج', 'program'], ['department_name', 'القسم', 'department'], ['college_name', 'الكلية', 'college'], ['year_name', 'السنة', 'academic_year'], ['semester_name', 'الفصل', 'semester'], ['status', 'حالة الطرح', 'offering_status'], ['capacity', 'السعة'], ['available_seats', 'المتاح']],
  academic_performance: [['student_number', 'رقم الطالب'], ['full_name', 'اسم الطالب'], ['college_name', 'الكلية', 'college'], ['program_name', 'البرنامج', 'program'], ['course_code', 'رمز المقرر', 'course'], ['course_name', 'المقرر', 'course'], ['credit_hours', 'الساعات', 'attempted_credit_hours'], ['final_mark', 'العلامة النهائية', 'official_average'], ['result_status', 'حالة النتيجة', 'result_status']],
  grade_workflow: [['course_code', 'رمز المقرر', 'course'], ['course_name', 'المقرر', 'course'], ['component_type', 'جزء العلامة', 'required_parts_count'], ['grade_workflow_status', 'حالة الجزء', 'grade_workflow_status'], ['final_approval_status', 'الاعتماد النهائي'], ['performed_at', 'وقت الحدث', 'day']],
})

export function detailColumns(subject, rows = []) {
  const available = new Set(rows.flatMap(row => Object.keys(row ?? {})))
  return (DETAIL_COLUMNS[subject] ?? []).filter(([key]) => available.has(key))
}

export function classifyReportError(error) {
  if (error?.name === 'AbortError') return { kind: 'aborted', message: '' }
  if (error?.status === 401) return { kind: 'authentication', message: 'انتهت جلسة الدخول. سجّل الدخول مجددًا.' }
  if (error?.status === 403) return { kind: 'authorization', message: 'لا تملك صلاحية الوصول إلى هذه التقارير.' }
  if (error?.status === 422) {
    const flattened = JSON.stringify(error.details ?? {})
    const points = /500|point|grouped report|dimensions/i.test(`${error.message ?? ''} ${flattened}`)
    return { kind: points ? 'point_limit' : 'validation', message: points ? 'حجم التجميع أكبر من الحد المتاح. ضيّق النطاق أو قلّل أبعاد التجميع ثم أعد المحاولة.' : 'راجع إعدادات التقرير والحقول الموضحة أدناه.', details: error.details ?? {} }
  }
  if (error?.errorCode?.includes('contract') || `${error?.message}`.includes('contract')) return { kind: 'contract', message: 'تعذر قراءة عقد خدمة التقارير المتوافق.' }
  return { kind: 'network', message: 'تعذر تحميل التقرير من الخادم. تحقق من الاتصال ثم أعد المحاولة.' }
}

export function isLatestResponse(sequence, currentSequence) {
  return sequence === currentSequence
}
