import { useEffect, useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  FaUniversity, FaLayerGroup, FaGraduationCap, FaUserGraduate, FaChalkboardTeacher, FaUserTie, FaSitemap, FaBook,
  FaClipboardCheck, FaCalendarAlt, FaUserCheck,
} from 'react-icons/fa'
import { fetchMinistryDashboard, fetchMinistryFilters } from '../lib/ministryApi'
import { INDICATORS, appliedFilterLabels, buildQuery, formatDateTime, formatNumber, formatPercent, portalLink, programsOf, readFilters, viewState } from '../lib/ministryState'
import { AppliedFilters, FilterSelect, Notice, PageHeader, Section, StatCard, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

const EMPTY_OPTIONS = { academic_years: [], semesters: [], colleges: [], programs: [] }

/** Horizontal bars; each value opens the list that produced it. */
function BarList({ rows, limit }) {
  const shown = limit ? rows.slice(0, limit) : rows
  const max = Math.max(0, ...shown.map(r => r.total))
  if (!shown.length) return <p className="text-[12.5px] text-text-light">لا توجد بيانات لهذا التوزيع.</p>
  return (
    <ul className="grid gap-2.5">
      {shown.map(row => {
        const link = portalLink(row.link)
        const width = max === 0 ? 0 : Math.max(2, row.total / max * 100)
        return (
          <li key={row.key}>
            <div className="flex justify-between gap-3 text-[12px] mb-1">
              <span className="font-bold text-text-dark truncate">{row.label}{row.college && <span className="font-normal text-text-light"> — {row.college}</span>}</span>
              {link ? <Link to={link} className="font-extrabold text-primary-dark hover:underline" aria-label={`${row.label}: ${formatNumber(row.total)} — فتح القائمة`}>{formatNumber(row.total)}</Link> : <span className="font-extrabold">{formatNumber(row.total)}</span>}
            </div>
            <div className="h-2.5 rounded-full bg-slate-100 overflow-hidden" role="img" aria-label={`${row.label}: ${formatNumber(row.total)}`}>
              <div className="h-full rounded-full bg-primary" style={{ width: `${width}%` }} />
            </div>
          </li>
        )
      })}
      {limit && rows.length > limit && <li className="text-[11.5px] text-text-light">وعناصر أخرى ({formatNumber(rows.length - limit)})؛ استخدم مرشح البرنامج أو قائمة الطلاب.</li>}
    </ul>
  )
}

/** Column chart for yearly/term trends, with the numbers as a table for accessibility. */
function TrendColumns({ rows, valueKey = 'total', format = formatNumber, caption }) {
  const max = Math.max(0, ...rows.map(r => r[valueKey] ?? 0))
  if (!rows.length) return <p className="text-[12.5px] text-text-light">لا توجد بيانات مسجلة.</p>
  return (
    <figure>
      <div className="flex items-end gap-3 h-[150px] border-b border-primary/15 px-1 overflow-x-auto" aria-hidden="true">
        {rows.map(r => {
          const value = r[valueKey]
          const height = value === null || value === undefined || max === 0 ? 0 : Math.max(3, value / max * 120)
          return (
            <div key={r.key ?? r.label} className="flex flex-col items-center justify-end gap-1 min-w-[58px] flex-1">
              <span className="text-[11px] font-bold text-primary-dark">{value === null || value === undefined ? '—' : format(value)}</span>
              <div className="w-8 rounded-t-md bg-primary/80" style={{ height }} />
            </div>
          )
        })}
      </div>
      <div className="flex gap-3 px-1 overflow-x-auto" aria-hidden="true">
        {rows.map(r => <span key={r.key ?? r.label} className="min-w-[58px] flex-1 text-center text-[10.5px] text-text-light leading-4 pt-1">{r.label}</span>)}
      </div>
      <table className="sr-only"><caption>{caption}</caption><tbody>{rows.map(r => <tr key={r.key ?? r.label}><th>{r.label}</th><td>{format(r[valueKey])}</td></tr>)}</tbody></table>
    </figure>
  )
}

export default function MinistryHome() {
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => readFilters('dashboard', params), [params])
  const [options, setOptions] = useState(EMPTY_OPTIONS)
  useEffect(() => {
    let active = true
    fetchMinistryFilters().then(r => { if (active) setOptions({ ...EMPTY_OPTIONS, ...(r?.data ?? {}) }) }).catch(() => {})
    return () => { active = false }
  }, [])
  const query = buildQuery('dashboard', filters)
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDashboard(query), [query])
  const d = data?.data
  const state = viewState({ loading, error })

  const set = key => value => {
    const next = { ...filters, [key]: value }
    if (key === 'academic_year_id' && !value) next.semester_id = ''
    if (key === 'college_id') next.program_id = ''
    setParams(new URLSearchParams(buildQuery('dashboard', next)), { replace: true })
  }
  const card = (key, item, extra = {}) => (
    <StatCard key={key} label={INDICATORS[key]?.label ?? key} value={item?.value} to={portalLink(item?.link)} definition={INDICATORS[key]?.definition} note={item?.note} {...extra} />
  )

  return (
    <>
      <PageHeader title="وزارة التربية والتعليم — متابعة الجامعة" en="Ministry of Education" subtitle="جامعة الروّاد للعلوم والتقانة — لوحة اطلاع للقراءة فقط من بيانات النظام الفعلية." />

      <div className="flex items-end gap-3 flex-wrap mb-3" dir="rtl">
        <FilterSelect label="السنة الأكاديمية (للمؤشرات الفصلية)" value={filters.academic_year_id} onChange={set('academic_year_id')} placeholder="السنة الحالية" options={options.academic_years.map(y => ({ value: y.id, label: `${y.name}${y.is_current ? ' (الحالية)' : ''}` }))} minWidth={200} />
        <FilterSelect label="الفصل" value={filters.semester_id} onChange={set('semester_id')} placeholder="كل الفصول" options={options.semesters.map(s => ({ value: s.id, label: s.name }))} minWidth={140} />
        <FilterSelect label="الكلية" value={filters.college_id} onChange={set('college_id')} placeholder="كل الكليات" options={options.colleges.map(c => ({ value: c.id, label: c.name }))} minWidth={220} />
        <FilterSelect label="البرنامج" value={filters.program_id} onChange={set('program_id')} placeholder="كل البرامج" options={programsOf(options.programs, filters.college_id).map(p => ({ value: p.id, label: p.name }))} minWidth={220} />
        {query && <button type="button" onClick={() => setParams(new URLSearchParams(), { replace: true })} className="py-2 px-3 border-[1.5px] border-red-400/30 rounded-[10px] bg-red-50 text-red-500 text-[12.5px] font-semibold">مسح المرشحات</button>}
      </div>
      <div className="mb-5">
        <AppliedFilters labels={appliedFilterLabels(filters, options)} generatedAt={d ? formatDateTime(d.generated_at) : null} />
      </div>

      {state !== 'ready' ? <StatePanel state={state} error={error} onRetry={reload} /> : (
        <div className="grid gap-5">
          <Section title="الجامعة في أرقام (الوضع الحالي)" subtitle="لقطة حالية لا تتأثر بمرشح السنة والفصل. الأرقام المرتبطة فقط تفتح القوائم المطابقة لها." id="counts">
            <div className="grid grid-cols-4 gap-3 max-[1200px]:grid-cols-3 max-[800px]:grid-cols-2 max-[480px]:grid-cols-1">
              {card('colleges', d.counts.colleges, { Icon: FaUniversity, note: d.counts.colleges.inactive ? `و${formatNumber(d.counts.colleges.inactive)} غير مفعّلة` : null })}
              {card('programs', d.counts.programs, { Icon: FaLayerGroup, note: `${formatNumber(d.counts.departments.value)} قسمًا مفعّلًا` })}
              {card('students', d.counts.students, { Icon: FaUserGraduate })}
              {card('active_students', d.counts.active_students, { Icon: FaUserCheck })}
              {card('faculty', d.counts.faculty, { Icon: FaChalkboardTeacher })}
              {card('deans', d.counts.deans, { Icon: FaUserTie, note: d.counts.deans.colleges_without_dean ? `${formatNumber(d.counts.deans.colleges_without_dean)} كلية مفعّلة دون عميد حالي مسجل` : 'لكل كلية مفعّلة عميد مسجل' })}
              {card('vice_presidents', d.counts.vice_presidents, { Icon: FaSitemap })}
              {card('courses', d.counts.courses, { Icon: FaBook })}
            </div>
          </Section>

          <Section title={`مؤشرات الفترة: ${d.period?.label ?? 'غير محددة'}`} subtitle="تُحسب من التسجيلات والطروحات والنتائج المعتمدة ضمن الفترة المختارة (السنة الحالية افتراضيًا)." id="period">
            {!d.period_metrics ? <Notice tone="warning">غير متاح: {d.period?.reason}</Notice> : (
              <div className="grid grid-cols-5 gap-3 max-[1200px]:grid-cols-3 max-[800px]:grid-cols-2 max-[480px]:grid-cols-1">
                {card('registered_students', d.period_metrics.registered_students, { Icon: FaUserCheck })}
                {card('offerings', d.period_metrics.offerings, { Icon: FaCalendarAlt })}
                {card('offered_courses', d.period_metrics.offered_courses, { Icon: FaBook })}
                {card('official_results', d.period_metrics.official_results, {
                  Icon: FaClipboardCheck,
                  note: d.period_metrics.official_results.value
                    ? `نسبة النجاح ${formatPercent(d.period_metrics.official_results.pass_rate)} — ناجح ${formatNumber(d.period_metrics.official_results.passed)}، راسب ${formatNumber(d.period_metrics.official_results.failed)}، محروم ${formatNumber(d.period_metrics.official_results.deprived)}`
                    : 'لا توجد نتائج معتمدة في الفترة؛ نسبة النجاح غير متاحة.',
                })}
                {card('graduates', d.period_metrics.graduates, { Icon: FaGraduationCap, unavailableReason: d.period_metrics.graduates.reason })}
              </div>
            )}
          </Section>

          <div className="grid grid-cols-2 gap-5 max-[1000px]:grid-cols-1">
            <Section title="توزيع الطلاب حسب الكلية" subtitle="كلية البرنامج الحالي للطالب." id="dist-college"><BarList rows={d.distributions.by_college} /></Section>
            <Section title="توزيع الطلاب حسب الحالة" id="dist-status"><BarList rows={d.distributions.by_status} /></Section>
            <Section title="توزيع الطلاب حسب السنة الدراسية" id="dist-level"><BarList rows={d.distributions.by_level} /></Section>
            <Section title="توزيع الطلاب حسب البرنامج" id="dist-program"><BarList rows={d.distributions.by_program} limit={8} /></Section>
          </div>

          <div className="grid grid-cols-2 gap-5 max-[1000px]:grid-cols-1">
            <Section title="اتجاه الالتحاق" subtitle="طلاب غير محذوفين حسب السنة الأكاديمية لتاريخ التحاقهم. لا يتأثر بمرشح السنة." id="trend-intake">
              <TrendColumns rows={d.trends.intake_by_year} caption="الملتحقون حسب السنة" />
            </Section>
            <Section title="اتجاه التخرج" subtitle="قرارات تخرج معتمدة ونافذة حسب سنة اعتمادها." id="trend-grad">
              <TrendColumns rows={d.trends.graduates_by_year} caption="الخريجون حسب السنة" />
            </Section>
            <Section title="الطلاب المسجلون حسب الفصل" subtitle="طلاب فريدون بتسجيل «مسجل» أو «مكتمل»." id="trend-reg">
              <TrendColumns rows={d.trends.registrations_by_term} caption="المسجلون حسب الفصل" />
            </Section>
            <Section title="نسبة النجاح في النتائج المعتمدة حسب الفصل" subtitle="من النتائج المعتمدة رسميًا فقط؛ الفصول بلا نتائج معتمدة لا تظهر." id="trend-results">
              <TrendColumns rows={d.trends.official_results_by_term} valueKey="pass_rate" format={formatPercent} caption="نسبة النجاح حسب الفصل" />
            </Section>
          </div>

          <Section title="مؤشرات غير متاحة بثقة من البيانات الحالية" subtitle="لا تُعرض كأرقام حتى لا تُفهم كنتائج مؤكدة." id="unavailable">
            <ul className="grid gap-2 text-[12.5px]">
              {d.unavailable.map(u => <li key={u.code}><span className="font-bold text-text-dark">{u.label}: </span><span className="text-text-gray">{u.reason}</span></li>)}
            </ul>
          </Section>
          <Notice>تعريف كل مؤشر ومصدره مذكور أسفل رقمه. التفاصيل الكاملة في «دليل الاستخدام». الأرقام محسوبة لحظة التحميل من بيانات النظام الحية؛ لا توجد نسخة مخزنة.</Notice>
        </div>
      )}
    </>
  )
}
