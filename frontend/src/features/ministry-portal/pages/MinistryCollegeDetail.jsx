import { Link, useParams } from 'react-router-dom'
import { FaUserGraduate, FaChalkboardTeacher, FaBook, FaLayerGroup } from 'react-icons/fa'
import { fetchMinistryDetail } from '../lib/ministryApi'
import { formatNumber, viewState } from '../lib/ministryState'
import { Badge, MiniTable, PageHeader, Section, StatCard, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

export default function MinistryCollegeDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDetail('colleges', id), [id])
  const c = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/colleges', label: 'قائمة الكليات' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل الكلية" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>
  const s = c.summary

  return (
    <>
      <PageHeader title={c.college_name} en={c.college_code} back={back}>
        <Badge tone={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'مفعّلة' : 'غير مفعّلة'}</Badge>
        {c.organizational_unit && <Link className="text-[12px] font-bold text-primary hover:underline" to={`/ministry/leadership/units/${c.organizational_unit.id}`}>موقعها في الهيكل التنظيمي</Link>}
      </PageHeader>
      <div className="grid gap-5">
        <div className="grid grid-cols-4 gap-3 max-[1000px]:grid-cols-2 max-[480px]:grid-cols-1" dir="rtl">
          <StatCard label="الطلاب" value={s.students} note={`منهم ${formatNumber(s.active_students)} نشطون`} to={`/ministry/students?college_id=${c.college_id}`} Icon={FaUserGraduate} />
          <StatCard label="المدرسون (ملف مفعّل)" value={s.faculty} to={`/ministry/faculty?college_id=${c.college_id}&active=1`} Icon={FaChalkboardTeacher} />
          <StatCard label="المقررات المفعّلة" value={s.courses} to={`/ministry/courses?college_id=${c.college_id}&active=1`} Icon={FaBook} />
          <StatCard label="الأقسام / البرامج" value={s.departments} note={`${formatNumber(s.programs)} برنامجًا مفعّلًا`} Icon={FaLayerGroup} />
        </div>
        <Section title="العمادة" id="deans">
          {s.deans.length
            ? <p className="text-[13px]">{s.deans.map((d, i) => <span key={d.person}>{i > 0 && '، '}<Link className="font-bold text-primary-dark hover:underline" to={`/ministry/deans/${d.person}`}>{d.full_name}</Link></span>)}</p>
            : <p className="text-[12.5px] text-amber-800">لا يوجد عميد حالي مسجل لهذه الكلية.</p>}
          <Link to={`/ministry/deans?college_id=${c.college_id}`} className="inline-block mt-2 text-[12px] font-bold text-primary hover:underline">كل العمداء (الحاليون والسابقون)</Link>
        </Section>
        {c.departments.length === 0 && <Section title="الأقسام"><p className="text-[12.5px] text-text-light">لا توجد أقسام مسجلة لهذه الكلية.</p></Section>}
        {c.departments.map(d => (
          <Section key={d.department_id} title={d.department_name} subtitle={`${d.department_code}${d.is_active ? '' : ' — قسم غير مفعّل'}`} id={`dep-${d.department_id}`}>
            <MiniTable rows={d.programs} rowKey={p => p.program_id} empty="لا توجد برامج مسجلة في هذا القسم." columns={[
              { key: 'name', header: 'البرنامج', render: p => <span className="font-bold">{p.program_name}</span> },
              { key: 'degree', header: 'الدرجة / المدة', render: p => `${p.degree_level ?? '—'}${p.duration_years ? ` — ${formatNumber(p.duration_years)} سنوات` : ''}` },
              { key: 'plan', header: 'الخطة الفعّالة', render: p => <span>{p.plan.label}{p.plan_versions > 1 && <span className="text-text-light"> (من {formatNumber(p.plan_versions)} نسخ مسجلة)</span>}</span> },
              { key: 'courses', header: 'مقررات الخطة', render: p => p.plan_courses === null ? <span className="text-text-light">غير متاح</span> : <Link className="font-bold text-primary-dark hover:underline" to={`/ministry/courses?program_id=${p.program_id}`}>{formatNumber(p.plan_courses)}</Link> },
              { key: 'students', header: 'الطلاب', render: p => <Link className="font-bold text-primary-dark hover:underline" to={`/ministry/students?program_id=${p.program_id}`}>{formatNumber(p.students)}</Link> },
              { key: 'active', header: 'النشطون', render: p => <Link className="hover:underline" to={`/ministry/students?program_id=${p.program_id}&status=active`}>{formatNumber(p.active_students)}</Link> },
              { key: 'status', header: 'الحالة', render: p => <Badge tone={p.is_active ? 'success' : 'neutral'}>{p.is_active ? 'مفعّل' : 'غير مفعّل / مؤرشف'}</Badge> },
            ]} />
          </Section>
        ))}
      </div>
    </>
  )
}
