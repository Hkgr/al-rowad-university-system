import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FaCheckCircle, FaClock, FaUndo, FaUsers, FaUserTie } from 'react-icons/fa'
import FilterBar from '../../../components/table/FilterBar'
import { HorizontalBarChart } from '../../executive-reports/components/ReportCharts'
import { fetchAdministrativeDashboard } from '../lib/administrativeApi'
import { collegeLabels, collegeSeries, dashboardFilters, formatCount, queueLink, unavailableText } from '../utils/administrativeGovernance'

const STUDENT_METRIC = { code: 'total', unit: 'students' }
const FACULTY_METRIC = { code: 'total', unit: 'faculty' }

function Kpi({ Icon, label, value, note, to, unavailableReason }) {
  const body = (
    <>
      <div className="flex items-center justify-between gap-2">
        <p className="text-xs font-bold text-text-light">{label}</p>
        <span className="rounded-xl bg-primary/10 p-2 text-primary"><Icon aria-hidden="true" /></span>
      </div>
      <p className="mt-3 text-2xl font-black text-primary-dark">{unavailableReason ? 'غير متاح' : formatCount(value)}</p>
      {(unavailableReason || note) && <p className="mt-1 text-[11px] text-text-light leading-5">{unavailableReason ? unavailableText(unavailableReason) : note}</p>}
    </>
  )
  const className = 'block rounded-2xl border border-primary/10 bg-white p-4 shadow-sm'
  return to && !unavailableReason
    ? <Link to={to} className={`${className} hover:border-primary/40 focus:outline-none focus:ring-2 focus:ring-primary/40`} aria-label={`${label}: فتح القائمة المطابقة`}>{body}</Link>
    : <div className={className}>{body}</div>
}

function Unavailable({ reason, onRetry }) {
  return (
    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
      <p>غير متاح: {unavailableText(reason)}</p>
      {onRetry && <button type="button" onClick={onRetry} className="mt-3 rounded-lg border border-amber-400 px-3 py-1.5 font-bold">إعادة المحاولة</button>}
    </div>
  )
}

export default function AdministrativeDashboard() {
  const navigate = useNavigate()
  const [state, setState] = useState({ academic_year_id: '', semester_id: '', college_id: '' })
  const [data, setData] = useState(null)
  const [options, setOptions] = useState({ academic_years: [], semesters: [], colleges: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [revision, setRevision] = useState(0)
  const filters = useMemo(() => dashboardFilters(state), [state])

  useEffect(() => {
    let active = true
    const timer = setTimeout(() => {
    setLoading(true)
    setError(null)
    fetchAdministrativeDashboard(filters)
      .then((response) => {
        if (!active) return
        setData(response?.data ?? null)
        if (response?.data?.filter_options) setOptions(response.data.filter_options)
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.status === 401) { navigate('/login', { replace: true }); return }
        setData(null)
        setError(requestError.status === 403
          ? 'لا يملك حسابك صلاحية مؤشرات النيابة الإدارية مع نطاق الجامعة.'
          : (requestError.message || 'تعذّر تحميل المؤشرات.'))
      })
      .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; clearTimeout(timer) }
  }, [filters, navigate, revision])

  const labels = useMemo(() => ({ college: collegeLabels(options.colleges) }), [options.colleges])
  const set = key => value => setState(current => ({ ...current, [key]: value, ...(key === 'academic_year_id' && !value ? { semester_id: '' } : {}) }))
  const hasFilters = Boolean(state.academic_year_id || state.semester_id || state.college_id)
  const selectedCollege = state.college_id ? labels.college[state.college_id] : null

  const students = data?.students
  const faculty = data?.faculty
  const assignments = data?.teaching_assignments
  const assignmentsReason = assignments && assignments.available === false ? assignments.reason : null
  const collegeValue = (block) => (block?.by_college ?? []).find(row => String(row.college_id) === String(state.college_id))?.total ?? 0
  const studentTotal = selectedCollege ? collegeValue(students) : students?.university_total
  const facultyTotal = selectedCollege ? collegeValue(faculty) : faculty?.university_total

  return (
    <section className="space-y-5" aria-label="مؤشرات النيابة الإدارية">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-black text-text-dark">مؤشرات النيابة الإدارية</h2>
          <p className="mt-1 text-xs text-text-light">آخر تحديث: {data?.generated_at ? new Date(data.generated_at).toLocaleString('ar-SY') : '—'}</p>
        </div>
      </div>

      <FilterBar
        filters={[
          { key: 'year', value: state.academic_year_id, onChange: set('academic_year_id'), placeholder: 'كل السنوات', options: options.academic_years.map(item => ({ value: String(item.id), label: item.is_current ? `${item.label} (الحالية)` : item.label })) },
          { key: 'semester', value: state.semester_id, onChange: set('semester_id'), placeholder: state.academic_year_id ? 'كل الفصول' : 'اختر السنة أولًا', options: state.academic_year_id ? options.semesters.map(item => ({ value: String(item.id), label: item.label })) : [] },
          { key: 'college', value: state.college_id, onChange: set('college_id'), placeholder: 'كل الكليات', minWidth: 200, options: options.colleges.map(item => ({ value: String(item.id), label: item.label })) },
        ]}
        hasActiveFilters={hasFilters}
        onClear={() => setState({ academic_year_id: '', semester_id: '', college_id: '' })}
        disabled={loading}
      />

      {error && <Unavailable reason="request_failed" onRetry={() => setRevision(value => value + 1)} />}
      {error && <p className="text-[12px] text-red-600">{error}</p>}
      {loading && !data && <div className="rounded-2xl bg-white p-8 text-center text-text-light">جاري تحميل المؤشرات…</div>}

      {data && (
        <>
          <div>
            <h3 className="mb-2 text-[14px] font-black text-text-dark">{selectedCollege ? `الكلية المختارة: ${selectedCollege}` : 'إجمالي الجامعة'}</h3>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              <Kpi Icon={FaUsers} label="الطلاب النشطون" value={studentTotal} note="لقطة حالية؛ لا تتأثر بالسنة والفصل." />
              <Kpi Icon={FaUserTie} label="المدرسون النشطون" value={facultyTotal} note={selectedCollege ? 'المنتسبون إلى الكلية (أساسي أو إسناد نافذ).' : `منهم ${formatCount(faculty?.without_college)} بلا انتماء لكلية.`} />
              <Kpi Icon={FaClock} label="تكليفات بانتظار مراجعتي" value={assignments?.totals?.pending} unavailableReason={assignmentsReason} to={queueLink('pending', filters)} note="اضغط لفتح القائمة المطابقة." />
              <Kpi Icon={FaUndo} label="تكليفات معادة للعميد" value={assignments?.totals?.returned} unavailableReason={assignmentsReason} to={queueLink('returned', filters)} />
              <Kpi Icon={FaCheckCircle} label="تكليفات معتمدة" value={assignments?.totals?.approved} unavailableReason={assignmentsReason} to={queueLink('approved', filters)} />
            </div>
            {(state.academic_year_id || state.semester_id) && (
              <p className="mt-2 text-[11.5px] text-text-light">السنة والفصل يُطبّقان على التكليفات فقط (حسب طرح المادة)؛ أعداد الطلاب والمدرسين لقطة حالية.</p>
            )}
          </div>

          <div>
            <h3 className="mb-2 text-[14px] font-black text-text-dark">التوزيع حسب الكلية</h3>
            <div className="grid gap-5 xl:grid-cols-2">
              <HorizontalBarChart title="الطلاب النشطون حسب كلية البرنامج — لقطة حالية" rows={collegeSeries(students?.by_college)} metric={STUDENT_METRIC} dimension="college" labels={labels} />
              <HorizontalBarChart title="المدرسون النشطون حسب الانتماء — مجموعات قد تتداخل" rows={collegeSeries(faculty?.by_college)} metric={FACULTY_METRIC} dimension="college" labels={labels} />
            </div>
            <p className="mt-2 text-[11.5px] text-text-light">قد يُحسب المدرس المنتسب إلى كليتين في كلتيهما، لذلك لا يساوي مجموع الكليات إجمالي الجامعة بالضرورة.</p>
          </div>

          <section className="rounded-2xl border border-black/5 bg-white p-5 shadow-sm" aria-labelledby="assignments-by-college">
            <h3 id="assignments-by-college" className="font-black text-text-dark">طلبات التكليف حسب الكلية</h3>
            {assignmentsReason ? (
              <div className="mt-3"><Unavailable reason={assignmentsReason} /></div>
            ) : (assignments?.by_college ?? []).length === 0 ? (
              <p className="mt-3 text-sm text-text-light">لا توجد طلبات تكليف حالية ضمن الفلاتر المختارة.</p>
            ) : (
              <div className="mt-3 overflow-x-auto">
                <table className="w-full text-sm">
                  <thead><tr className="text-text-light text-xs"><th className="p-2 text-right">الكلية</th><th className="p-2 text-center">بانتظار مراجعتي</th><th className="p-2 text-center">معادة</th><th className="p-2 text-center">معتمدة</th></tr></thead>
                  <tbody>
                    {assignments.by_college.map(row => (
                      <tr key={String(row.college_id)} className="border-t border-primary/7">
                        <td className="p-2 font-bold">{row.college_id === null ? 'غير محدد' : (labels.college[String(row.college_id)] ?? `الكلية ${row.college_id}`)}</td>
                        {['pending', 'returned', 'approved'].map(key => (
                          <td key={key} className="p-2 text-center">
                            {row.college_id === null
                              ? formatCount(row[key])
                              : <Link className="font-bold text-primary-dark underline-offset-2 hover:underline" to={queueLink(key, filters, row.college_id)}>{formatCount(row[key])}</Link>}
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </section>
  )
}
