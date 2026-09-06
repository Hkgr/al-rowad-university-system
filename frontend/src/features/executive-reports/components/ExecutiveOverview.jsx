import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { FaArrowLeft, FaBookOpen, FaGraduationCap, FaUsers, FaUserTie } from 'react-icons/fa'
import { executeExecutiveReport, fetchAllExecutiveReportOptions, fetchExecutiveOverview, fetchExecutiveReportDefinitions } from '../api'
import { normalizeDefinitions } from '../config'
import { classifyReportError, formatMetric } from '../presentation'
import { HorizontalBarChart } from './ReportCharts'
import { reportPathForOffice } from '../access'

const WIDGETS = {
  students: { subject: 'students', mode: 'summary', metrics: ['student_count'], dimensions: ['college'], page: 1, per_page: 25 },
  faculty: { subject: 'faculty', mode: 'summary', metrics: ['active_faculty_count', 'assigned_sections_count'], dimensions: ['college'], page: 1, per_page: 25 },
}

function Kpi({ Icon, label, value, unavailable = false }) {
  return <div className="rounded-2xl border border-primary/10 bg-white p-4 shadow-sm"><div className="flex items-center justify-between"><p className="text-xs font-bold text-text-light">{label}</p><span className="rounded-xl bg-primary/10 p-2 text-primary"><Icon /></span></div><p className="mt-3 text-2xl font-black text-primary-dark">{unavailable ? 'غير متاح' : new Intl.NumberFormat('ar-SY').format(value ?? 0)}</p></div>
}

function WidgetError({ message, onRetry }) { return <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900"><p>{message}</p>{onRetry && <button type="button" onClick={onRetry} className="mt-3 rounded-lg border border-amber-400 px-3 py-1.5 font-bold">إعادة المحاولة</button>}</div> }

export default function ExecutiveOverview({ office }) {
  const [overview, setOverview] = useState(null)
  const [reports, setReports] = useState({})
  const [errors, setErrors] = useState({})
  const [collegeLabels, setCollegeLabels] = useState({})
  const [loading, setLoading] = useState(true)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    const controller = new AbortController(); let active = true
    setLoading(true); setOverview(null); setReports({}); setErrors({}); setCollegeLabels({})
    const fail = (key, error) => {
      if (!active || error.name === 'AbortError') return
      const classified = classifyReportError(error)
      if (classified.kind === 'authentication' || classified.kind === 'authorization') {
        controller.abort(); setOverview(null); setReports({}); setErrors({ overview: classified.message }); return
      }
      setErrors(current => ({ ...current, [key]: classified.message }))
    }
    Promise.all([fetchExecutiveReportDefinitions(controller.signal), fetchExecutiveOverview(controller.signal)])
      .then(async ([contract, overviewData]) => {
        if (!active) return
        const definitions = normalizeDefinitions(contract)
        setOverview(overviewData)
        fetchAllExecutiveReportOptions({ resource: 'colleges' }, controller.signal).then(options => { if (active) setCollegeLabels(Object.fromEntries(options.map(option => [String(option.id), option.label]))) }).catch(error => fail('labels', error))
        Object.entries(WIDGETS).forEach(([key, payload]) => {
          if (!definitions[payload.subject]) return
          executeExecutiveReport(payload, controller.signal).then(data => { if (active) setReports(current => ({ ...current, [key]: data })) }).catch(error => fail(key, error))
        })
        if (overviewData.academic_sections?.available && definitions.academic_performance) {
          const payload = { subject: 'academic_performance', mode: 'summary', metrics: ['official_average', 'official_gpa', 'pass_rate'], dimensions: ['college'], period: { type: 'academic', academic_year_ids: [overviewData.academic_sections.academic_year_id] }, page: 1, per_page: 25 }
          executeExecutiveReport(payload, controller.signal).then(data => { if (active) setReports(current => ({ ...current, performance: data })) }).catch(error => fail('performance', error))
        }
      })
      .catch(error => fail('overview', error))
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort(); setOverview(null); setReports({}) }
  }, [office, revision])

  const labels = useMemo(() => ({ college: collegeLabels }), [collegeLabels])
  const studentsMetric = reports.students?.metrics?.find(metric => metric.code === 'student_count')
  const facultyMetric = reports.faculty?.metrics?.find(metric => metric.code === 'active_faculty_count')
  const performanceMetric = reports.performance?.metrics?.find(metric => metric.code === 'official_gpa')
  const academic = overview?.academic_sections

  if (errors.overview) return <WidgetError message={errors.overview} onRetry={() => setRevision(value => value + 1)} />
  if (loading && !overview) return <div className="rounded-2xl bg-white p-8 text-center text-text-light">جاري تحميل النظرة التنفيذية...</div>
  return <section className="space-y-5" aria-label="النظرة التنفيذية">
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-xl font-black text-text-dark">النظرة التنفيذية</h2><p className="mt-1 text-xs text-text-light">آخر تحديث ناجح: {overview?.generated_at ? new Date(overview.generated_at).toLocaleString('ar-SY') : '—'}</p></div><Link to={reportPathForOffice(office)} className="flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-black text-white">إنشاء تقرير مخصص <FaArrowLeft /></Link></div>
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><Kpi Icon={FaUsers} label="الطلاب الحاليون" value={overview?.current_snapshots?.students} /><Kpi Icon={FaUserTie} label="أعضاء الهيئة النشطون" value={overview?.current_snapshots?.active_faculty} /><Kpi Icon={FaBookOpen} label="طروحات السنة الحالية" value={academic?.offerings} unavailable={!academic?.available} /><Kpi Icon={FaGraduationCap} label="طلاب مسجلون حاليًا" value={academic?.registered_students} unavailable={!academic?.available} /><Kpi Icon={FaGraduationCap} label="النتائج الرسمية" value={academic?.official_results} unavailable={!academic?.available} /></div>
    {!academic?.available && overview && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">تعذر تحديد سنة أكاديمية حالية وحيدة؛ بقيت اللقطات الحالية متاحة ولم تُخمن مؤشرات أكاديمية.</div>}
    <div className="grid gap-5 xl:grid-cols-2">{errors.students ? <WidgetError message={errors.students} onRetry={() => setRevision(value => value + 1)} /> : studentsMetric && <HorizontalBarChart title="الطلاب حسب الكلية — لقطة حالية" rows={reports.students.series} metric={studentsMetric} dimension="college" labels={labels} />}{errors.faculty ? <WidgetError message={errors.faculty} onRetry={() => setRevision(value => value + 1)} /> : facultyMetric && <HorizontalBarChart title="الهيئة التدريسية النشطة حسب الكلية — مجموعات قد تتداخل" rows={reports.faculty.series} metric={facultyMetric} dimension="college" labels={labels} />}{academic?.available && (errors.performance ? <WidgetError message={errors.performance} onRetry={() => setRevision(value => value + 1)} /> : performanceMetric && <HorizontalBarChart title="المعدل الرسمي حسب الكلية — السنة الحالية" rows={reports.performance.series} metric={performanceMetric} dimension="college" labels={labels} />)}</div>
    {reports.students?.series?.some(row => Number(row.college) > 0) && <div className="flex flex-wrap gap-2" aria-label="تقارير الكليات"><span className="w-full text-xs font-bold text-text-light">فتح تقرير مخصص لكلية:</span>{reports.students.series.filter(row => Number(row.college) > 0).map(row => <Link key={String(row.college)} to={reportPathForOffice(office)} state={{ executiveReportPreset: { subject: 'students', mode: 'summary', metrics: ['student_count', 'official_gpa'], dimensions: ['program'], filters: { college_ids: [Number(row.college)] }, labels: { college_ids: { [String(row.college)]: collegeLabels[String(row.college)] ?? `الكلية ${row.college}` } } } }} className="rounded-full border border-primary/20 bg-white px-3 py-1.5 text-xs font-bold text-primary-dark">{collegeLabels[String(row.college)] ?? `الكلية ${row.college}`}</Link>)}</div>}
    {reports.performance?.summary?.official_gpa && <p className="rounded-xl bg-white p-3 text-xs text-text-light">المعدل الرسمي الإجمالي كما أعاده الخادم: {formatMetric(performanceMetric, reports.performance.summary.official_gpa).text}</p>}
  </section>
}
