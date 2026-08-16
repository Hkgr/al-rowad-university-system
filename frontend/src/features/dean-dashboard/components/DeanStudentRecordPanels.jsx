import { useState } from 'react'
import { FaCheckCircle, FaClock, FaDownload, FaFolderOpen, FaGraduationCap, FaSpinner, FaTimesCircle } from 'react-icons/fa'
import { formatDate, genderLabel } from '../utils/studentDisplay'
import { downloadAuthorizedFile } from '../utils/authorizedDownload'
import CourseRequirementBadges, { pickRequirementClassification } from '../../../components/academic/CourseRequirementBadges'

const SEMESTER_ORDER = [
  { code: 'first', ar: 'الفصل الأول', accent: 'primary' },
  { code: 'second', ar: 'الفصل الثاني', accent: 'blue' },
  { code: 'summer', ar: 'الفصل الصيفي', accent: 'amber' },
]

const ACCENT = {
  primary: {
    header: 'bg-primary/[0.07] border-primary/15',
    label: 'text-primary-dark',
    badge: 'bg-primary/8 text-primary-dark border-primary/20',
  },
  blue: {
    header: 'bg-blue-500/[0.06] border-blue-200',
    label: 'text-blue-700',
    badge: 'bg-blue-50 text-blue-700 border-blue-200',
  },
  amber: {
    header: 'bg-amber-500/[0.06] border-amber-200',
    label: 'text-amber-700',
    badge: 'bg-amber-50 text-amber-700 border-amber-200',
  },
}

const DOCUMENT_STATUS = {
  pending: { bg: 'bg-amber-500/10', text: 'text-amber-700', border: 'border-amber-500/25', ar: 'قيد المراجعة', Icon: FaClock },
  verified: { bg: 'bg-green-500/10', text: 'text-green-700', border: 'border-green-500/25', ar: 'موثّق', Icon: FaCheckCircle },
  rejected: { bg: 'bg-red-500/10', text: 'text-red-600', border: 'border-red-500/25', ar: 'مرفوض', Icon: FaTimesCircle },
}

function displayValue(value) {
  if (value === null || value === undefined || value === '') return '—'
  return value
}

function gradeColor(letter) {
  if (!letter) return 'text-text-gray'
  const value = String(letter).toUpperCase()
  if (value.startsWith('A')) return 'text-green-600'
  if (value.startsWith('B')) return 'text-blue-600'
  if (value.startsWith('C')) return 'text-amber-600'
  if (value.startsWith('D')) return 'text-orange-500'
  return 'text-red-600'
}

export function SectionTitle({ title, subtitle }) {
  return (
    <div className="mb-4 pb-2.5 border-b border-primary/12" dir="rtl">
      <h3 className="text-[15px] font-extrabold text-text-dark">{title}</h3>
      {subtitle && <p className="text-[12px] text-text-light mt-1">{subtitle}</p>}
    </div>
  )
}

export function InfoField({ label, value }) {
  return (
    <div className="flex flex-col gap-1 min-w-0">
      <span className="text-[11px] font-bold text-text-light uppercase tracking-wide">{label}</span>
      <span className="text-[14px] font-semibold text-text-dark break-words">{displayValue(value)}</span>
    </div>
  )
}

export function TabState({ loading, error, empty, emptyIcon: EmptyIcon = FaFolderOpen, children }) {
  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
        <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[13.5px] font-medium">جاري التحميل…</span>
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 py-16 px-4" dir="rtl">
        <p className="text-[14px] font-bold text-red-600 text-center">⚠ {error}</p>
      </div>
    )
  }

  if (empty) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 py-16" dir="rtl">
        <EmptyIcon className="text-[40px] text-primary/20 mb-1" aria-hidden="true" />
        <p className="text-[14px] font-semibold text-text-light">{empty}</p>
      </div>
    )
  }

  return children
}

export function InfoTab({ profile }) {
  const longDateOptions = { year: 'numeric', month: 'long', day: 'numeric' }
  const identityFields = [
    { label: 'رقم القيد', value: profile.student_number },
    { label: 'الاسم الكامل', value: profile.full_name || `${profile.first_name ?? ''} ${profile.last_name ?? ''}`.trim() },
    { label: 'اسم الأب', value: profile.father_name },
    { label: 'اسم الأم', value: profile.mother_name },
    { label: 'الجنس', value: genderLabel(profile.gender) },
    { label: 'تاريخ الميلاد', value: formatDate(profile.date_of_birth, longDateOptions) },
    { label: 'الجنسية', value: profile.nationality },
  ]
  const contactFields = [
    { label: 'رقم الهاتف', value: profile.phone_number },
    { label: 'البريد الإلكتروني', value: profile.email },
    { label: 'العنوان', value: profile.address },
  ]
  const academicFields = [
    { label: 'التخصص', value: profile.program?.program_name },
    { label: 'القسم', value: profile.department?.department_name },
    { label: 'الكلية', value: profile.college?.college_name },
    { label: 'السنة الدراسية', value: profile._levelLabel },
    { label: 'تاريخ القبول', value: formatDate(profile.enrollment_date, longDateOptions) },
    { label: 'الحالة', value: profile._statusLabel },
  ]

  return (
    <div className="space-y-7">
      <section>
        <SectionTitle title="بيانات الهوية" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {identityFields.map(field => <InfoField key={field.label} {...field} />)}
        </div>
      </section>
      <section>
        <SectionTitle title="معلومات التواصل" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {contactFields.map(field => <InfoField key={field.label} {...field} />)}
        </div>
      </section>
      <section>
        <SectionTitle title="البيانات الأكاديمية" />
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-x-8 gap-y-5">
          {academicFields.map(field => <InfoField key={field.label} {...field} />)}
        </div>
      </section>
    </div>
  )
}

export function RegistrationsTab({
  loading,
  error,
  rows,
  page,
  totalPages,
  onPageChange,
}) {
  return (
    <TabState
      loading={loading}
      error={error}
      empty={!rows?.length ? 'لا توجد تسجيلات مقررات لهذا الطالب' : null}
      emptyIcon={FaGraduationCap}
    >
      <div className="space-y-4">
        <SectionTitle title="سجل التسجيلات" subtitle="عرض فقط — بدون أي إجراءات تسجيل" />
        <div className="overflow-x-auto border border-primary/12 rounded-[14px]">
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="bg-text-dark text-white/90">
                <th className="px-4 py-3 text-right font-bold" dir="rtl">المقرر</th>
                <th className="px-4 py-3 text-right font-bold" dir="rtl">التصنيف الأكاديمي</th>
                <th className="px-4 py-3 text-center font-bold">الساعات</th>
                <th className="px-4 py-3 text-center font-bold" dir="rtl">العام</th>
                <th className="px-4 py-3 text-center font-bold" dir="rtl">الفصل</th>
                <th className="px-4 py-3 text-center font-bold" dir="rtl">حالة التسجيل</th>
                <th className="px-4 py-3 text-center font-bold" dir="rtl">حالة النتيجة</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(row => {
                const course = row.course_offering?.course
                return (
                  <tr key={row.student_course_registration_id} className="border-t border-primary/8 hover:bg-primary/[0.02]">
                    <td className="px-4 py-3" dir="rtl">
                      <div className="font-semibold text-text-dark">{displayValue(course?.course_name)}</div>
                      <div className="text-[11px] text-text-light font-mono mt-0.5">{displayValue(course?.course_code)}</div>
                    </td>
                    <td className="px-4 py-3" dir="rtl">
                      <CourseRequirementBadges classification={pickRequirementClassification(row)} compact />
                    </td>
                    <td className="px-4 py-3 text-center font-bold text-text-dark">{displayValue(course?.credit_hours)}</td>
                    <td className="px-4 py-3 text-center text-text-gray" dir="rtl">{displayValue(row.course_offering?.academic_year?.year_name)}</td>
                    <td className="px-4 py-3 text-center text-text-gray" dir="rtl">{displayValue(row.course_offering?.semester?.semester_name)}</td>
                    <td className="px-4 py-3 text-center" dir="rtl">{displayValue(row.registration_status?.status_name)}</td>
                    <td className="px-4 py-3 text-center" dir="rtl">
                      {displayValue(row.result_status?.status_name ?? row.student_course_result?.result_status?.status_name)}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>

        {totalPages > 1 && (
          <div className="flex items-center justify-center gap-4 pt-1">
            <button
              type="button"
              className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-semibold text-primary-dark disabled:opacity-40"
              disabled={page <= 1}
              onClick={() => onPageChange(page - 1)}
            >
              السابق
            </button>
            <div className="text-[13px] text-text-gray" dir="rtl">
              <span className="font-extrabold text-primary">{page}</span>
              <span className="mx-1">من</span>
              <span className="font-semibold text-text-dark">{totalPages}</span>
            </div>
            <button
              type="button"
              className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-semibold text-primary-dark disabled:opacity-40"
              disabled={page >= totalPages}
              onClick={() => onPageChange(page + 1)}
            >
              التالي
            </button>
          </div>
        )}
      </div>
    </TabState>
  )
}

function SemesterTable({ courses }) {
  if (!courses.length) {
    return <p className="text-[12px] text-text-light italic px-5 py-4" dir="rtl">لا يوجد مقررات مسجّلة في هذا الفصل</p>
  }

  const totalHours = courses.reduce((sum, course) => sum + (course.credit_hours || 0), 0)

  return (
    <div className="overflow-x-auto">
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="bg-[#fafaf9]">
            <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">المقرر</th>
            <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">التصنيف الأكاديمي</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الساعات</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">المجموع</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">التقدير</th>
            <th className="px-4 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
          </tr>
        </thead>
        <tbody>
          {courses.map((course, index) => (
            <tr key={`${course.course_code}-${index}`} className="border-t border-primary/6">
              <td className="px-4 py-3" dir="rtl">
                <div className="font-semibold text-text-dark">{displayValue(course.course_name)}</div>
                <div className="text-[11px] text-text-light font-mono mt-0.5">{displayValue(course.course_code)}</div>
              </td>
              <td className="px-4 py-3" dir="rtl">
                <CourseRequirementBadges classification={course.requirement_classification} compact />
              </td>
              <td className="px-4 py-3 text-center font-bold">{displayValue(course.credit_hours)}</td>
              <td className="px-4 py-3 text-center">{displayValue(course.theoretical_mark)}</td>
              <td className="px-4 py-3 text-center">{displayValue(course.practical_mark)}</td>
              <td className="px-4 py-3 text-center font-bold">{displayValue(course.final_mark)}</td>
              <td className={`px-4 py-3 text-center text-[16px] font-black ${gradeColor(course.letter_grade)}`}>
                {displayValue(course.letter_grade)}
              </td>
              <td className="px-4 py-3 text-center" dir="rtl">
                <span className={`inline-block px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${
                  course.result_status?.status_code === 'passed'
                    ? 'bg-green-500/10 text-green-700 border-green-500/25'
                    : course.result_status?.status_code === 'failed'
                      ? 'bg-red-500/10 text-red-600 border-red-500/25'
                      : 'bg-gray-100 text-text-light border-gray-200'
                }`}
                >
                  {course.result_status?.status_code === 'passed'
                    ? 'ناجح'
                    : course.result_status?.status_code === 'failed'
                      ? 'راسب'
                      : displayValue(course.result_status?.status_name)}
                </span>
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-primary/10 bg-[#fafaf9]">
            <td className="px-4 py-2.5 text-[11.5px] font-bold text-text-gray" dir="rtl">
              الإجمالي — {courses.length} مقرر
            </td>
            <td />
            <td className="px-4 py-2.5 text-center text-[12px] font-extrabold text-primary-dark">{totalHours}</td>
            <td colSpan={5} />
          </tr>
        </tfoot>
      </table>
    </div>
  )
}

export function TranscriptTab({ loading, error, transcript }) {
  const terms = transcript?.terms ?? []

  return (
    <TabState
      loading={loading}
      error={error}
      empty={!terms.length ? 'لا توجد بيانات دراسية بعد' : null}
      emptyIcon={FaGraduationCap}
    >
      {(() => {
        const byYear = {}
        terms.forEach(term => {
          const yearName = term.academic_year?.year_name ?? '—'
          if (!byYear[yearName]) byYear[yearName] = { semesters: {} }
          const code = term.semester?.semester_code ?? 'unknown'
          byYear[yearName].semesters[code] = term.courses ?? []
        })

        const sortedYears = Object.keys(byYear).sort()

        return (
          <div className="space-y-6">
            <SectionTitle title="كشف الدرجات" subtitle="سجل أكاديمي للقراءة فقط" />
            {sortedYears.map(yearName => {
              const { semesters } = byYear[yearName]
              const yearCourseCount = SEMESTER_ORDER.reduce((sum, semester) => sum + (semesters[semester.code]?.length ?? 0), 0)
              const yearTotal = SEMESTER_ORDER.reduce((sum, semester) => {
                const courses = semesters[semester.code] ?? []
                return sum + courses.reduce((hours, course) => hours + (course.credit_hours || 0), 0)
              }, 0)

              return (
                <div key={yearName} className="border border-primary/15 rounded-[16px] overflow-hidden">
                  <div className="flex items-center justify-between px-5 py-3.5 bg-text-dark" dir="rtl">
                    <span className="text-[15px] font-extrabold text-white">العام الدراسي {yearName}</span>
                    <span className="text-[12px] text-white/60">{yearCourseCount} مقرر • {yearTotal} ساعة</span>
                  </div>
                  <div className="divide-y divide-primary/8">
                    {SEMESTER_ORDER.map(semester => {
                      const courses = semesters[semester.code] ?? []
                      const accent = ACCENT[semester.accent]
                      const semesterHours = courses.reduce((hours, course) => hours + (course.credit_hours || 0), 0)
                      return (
                        <div key={semester.code}>
                          <div className={`flex items-center justify-between px-5 py-2.5 border-b ${accent.header}`} dir="rtl">
                            <span className={`text-[13px] font-extrabold ${accent.label}`}>{semester.ar}</span>
                            {courses.length > 0 && (
                              <span className={`text-[11px] font-semibold px-2.5 py-0.5 rounded-full border ${accent.badge}`}>
                                {courses.length} مقرر • {semesterHours} ساعة
                              </span>
                            )}
                          </div>
                          <SemesterTable courses={courses} />
                        </div>
                      )
                    })}
                  </div>
                </div>
              )
            })}
          </div>
        )
      })()}
    </TabState>
  )
}

export function GpaTab({ loading, error, cgpa }) {
  const cgpaValue = cgpa?.cgpa
  const hours = cgpa?.total_included_credit_hours ?? 0
  const includedCount = cgpa?.included_courses_count ?? 0

  return (
    <TabState
      loading={loading}
      error={error}
      empty={cgpa == null ? 'لا تتوفر بيانات المعدل حالياً' : null}
      emptyIcon={FaGraduationCap}
    >
      <div className="space-y-5">
        <SectionTitle title="المعدل التراكمي" subtitle="عرض المعدل التراكمي فقط بدون احتساب فصلي" />
        <div className="flex items-stretch gap-4 flex-wrap">
          <div className="flex-1 min-w-[220px] bg-gradient-to-br from-primary/[0.06] to-primary/[0.02] border border-primary/15 rounded-[16px] px-6 py-5 flex items-center gap-5" dir="rtl">
            <div className="text-[52px] font-black leading-none text-primary">
              {cgpaValue == null ? '—' : Number(cgpaValue).toFixed(2)}
            </div>
            <div className="flex flex-col gap-1">
              <span className="text-[13px] font-extrabold text-text-dark">المعدل التراكمي</span>
              <span className="text-[12px] text-text-light">Cumulative GPA</span>
              <span className="text-[12px] text-text-gray mt-1">{hours} ساعة معتمدة</span>
            </div>
          </div>
          <div className="flex flex-col justify-center items-center gap-1 px-6 py-4 border border-primary/12 rounded-[14px] bg-white min-w-[120px]" dir="rtl">
            <span className="text-[24px] font-black text-primary">{includedCount}</span>
            <span className="text-[11px] text-text-light text-center">مقرر محتسب</span>
          </div>
        </div>
      </div>
    </TabState>
  )
}

export function AttendanceTab({ loading, error, attendance }) {
  const courses = attendance?.courses ?? []

  return (
    <TabState
      loading={loading}
      error={error}
      empty={!courses.length ? 'لا توجد بيانات حضور بعد' : null}
    >
      <div className="space-y-4">
        <SectionTitle title="الحضور والغياب" subtitle="عرض فقط — بدون تعديل الجلسات" />
        {courses.map((course, index) => {
          const pct = course.absence_percentage || 0
          const deprived = course.deprivation_status === 'deprived'
          const warning = !deprived && (course.deprivation_status === 'candidate' || pct > 10)

          return (
            <div
              key={`${course.course_offering_id}-${index}`}
              className={`border rounded-[14px] p-5 ${
                deprived
                  ? 'border-red-500/30 bg-red-500/[0.025]'
                  : warning
                    ? 'border-amber-500/30 bg-amber-500/[0.025]'
                    : 'border-primary/12 bg-white'
              }`}
            >
              <div className="flex items-start justify-between gap-3 mb-3" dir="rtl">
                <div className="min-w-0">
                  <div className="font-bold text-[14px] text-text-dark break-words">{displayValue(course.course_name)}</div>
                  <div className="text-[11.5px] text-text-light font-mono mt-0.5">
                    {displayValue(course.course_code)} — {displayValue(course.academic_year?.year_name)} / {displayValue(course.semester?.semester_name)}
                  </div>
                  <div className="mt-1.5">
                    <CourseRequirementBadges classification={pickRequirementClassification(course)} compact />
                  </div>
                </div>
                {deprived && (
                  <span className="flex-shrink-0 px-2.5 py-1 bg-red-500/10 border border-red-500/25 text-red-600 text-[11px] font-bold rounded-full">محروم</span>
                )}
                {warning && !deprived && (
                  <span className="flex-shrink-0 px-2.5 py-1 bg-amber-500/10 border border-amber-500/25 text-amber-700 text-[11px] font-bold rounded-full">تحذير غياب</span>
                )}
              </div>
              <div className="h-2.5 bg-gray-100 rounded-full overflow-hidden mb-3">
                <div
                  className={`h-full rounded-full ${deprived ? 'bg-red-500' : warning ? 'bg-amber-400' : 'bg-primary'}`}
                  style={{ width: `${Math.min(pct, 100)}%` }}
                />
              </div>
              <div className="flex items-center gap-5 text-[12.5px] flex-wrap" dir="rtl">
                <span className="text-text-gray">إجمالي: <strong className="text-text-dark">{course.total_sessions ?? 0}</strong></span>
                <span className="text-green-600">حضور: <strong>{course.present_count ?? 0}</strong></span>
                <span className="text-red-500">غياب: <strong>{course.absent_count ?? 0}</strong></span>
                <span className={`font-bold ${deprived ? 'text-red-600' : warning ? 'text-amber-600' : 'text-text-dark'}`}>
                  نسبة الغياب: {Number(pct).toFixed(1)}%
                </span>
              </div>
            </div>
          )
        })}
      </div>
    </TabState>
  )
}

export function DocumentsTab({
  loading,
  error,
  documents,
  page,
  totalPages,
  onPageChange,
}) {
  const [downloadError, setDownloadError] = useState('')
  const [downloadingId, setDownloadingId] = useState(null)

  return (
    <TabState
      loading={loading}
      error={error}
      empty={!documents?.length ? 'لا توجد ملفات مرفقة لهذا الطالب' : null}
      emptyIcon={FaFolderOpen}
    >
      <div className="space-y-4">
        <SectionTitle title="ملفات الطالب" subtitle="عرض وتنزيل فقط — بدون رفع أو حذف" />
        {downloadError && (
          <div className="bg-amber-500/8 border border-amber-500/25 rounded-[12px] px-4 py-2.5 text-[13px] text-amber-700" dir="rtl">
            ⚠ {downloadError}
          </div>
        )}
        <div className="space-y-3">
          {documents.map(doc => {
            const status = DOCUMENT_STATUS[doc.verification_status] || {
              bg: 'bg-gray-100',
              text: 'text-text-gray',
              border: 'border-gray-200',
              ar: doc.verification_status || '—',
              Icon: FaFolderOpen,
            }
            const StatusIcon = status.Icon

            return (
              <div
                key={doc.student_document_id}
                className="flex items-center justify-between gap-3 flex-wrap border border-primary/12 rounded-[14px] px-4 py-3.5 bg-white"
                dir="rtl"
              >
                <div className="min-w-0 flex-1">
                  <div className="font-bold text-[13.5px] text-text-dark break-words">
                    {displayValue(doc.document_type?.type_name || doc.document_type?.type_code)}
                  </div>
                  <div className="text-[12px] text-text-light mt-0.5 break-all">{displayValue(doc.file_name)}</div>
                  <div className="flex items-center gap-2 flex-wrap mt-2">
                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${status.bg} ${status.text} ${status.border}`}>
                      <StatusIcon className="text-[10px]" aria-hidden="true" />
                      {status.ar}
                    </span>
                    <span className="text-[11.5px] text-text-light">
                      {formatDate(doc.uploaded_at || doc.created_at, { year: 'numeric', month: 'long', day: 'numeric' })}
                    </span>
                  </div>
                </div>

                <button
                  type="button"
                  className="inline-flex items-center gap-2 px-3.5 py-2 rounded-[10px] border border-primary/20 text-primary-dark text-[12.5px] font-bold hover:bg-primary/6 disabled:opacity-45"
                  disabled={!doc.download_url || downloadingId === doc.student_document_id}
                  title={doc.download_url ? 'تنزيل الملف' : 'رابط التنزيل غير متاح'}
                  aria-label={doc.download_url ? `تنزيل ${doc.file_name || 'الملف'}` : 'رابط التنزيل غير متاح'}
                  onClick={async () => {
                    setDownloadError('')
                    setDownloadingId(doc.student_document_id)
                    try {
                      await downloadAuthorizedFile(doc.download_url, doc.file_name || 'document')
                    } catch {
                      setDownloadError('تعذّر تنزيل الملف.')
                    } finally {
                      setDownloadingId(null)
                    }
                  }}
                >
                  {downloadingId === doc.student_document_id
                    ? <FaSpinner className="text-[11px] animate-spin" aria-hidden="true" />
                    : <FaDownload className="text-[11px]" aria-hidden="true" />}
                  <span>{downloadingId === doc.student_document_id ? 'جارٍ التنزيل…' : 'تنزيل'}</span>
                </button>
              </div>
            )
          })}
        </div>

        {totalPages > 1 && (
          <div className="flex items-center justify-center gap-4 pt-1">
            <button
              type="button"
              className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-semibold text-primary-dark disabled:opacity-40"
              disabled={page <= 1}
              onClick={() => onPageChange(page - 1)}
            >
              السابق
            </button>
            <div className="text-[13px] text-text-gray" dir="rtl">
              <span className="font-extrabold text-primary">{page}</span>
              <span className="mx-1">من</span>
              <span className="font-semibold text-text-dark">{totalPages}</span>
            </div>
            <button
              type="button"
              className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-semibold text-primary-dark disabled:opacity-40"
              disabled={page >= totalPages}
              onClick={() => onPageChange(page + 1)}
            >
              التالي
            </button>
          </div>
        )}
      </div>
    </TabState>
  )
}
