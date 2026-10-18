import { Link, useParams } from 'react-router-dom'
import { fetchMinistryDetail } from '../lib/ministryApi'
import { formatDate, formatNumber, viewState } from '../lib/ministryState'
import { Badge, InfoGrid, MiniTable, Notice, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

const RESULT_TONE = { passed: 'success', failed: 'danger', deprived: 'danger', incomplete: 'warning' }

export default function MinistryStudentDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryDetail('students', id), [id])
  const s = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/students', label: 'قائمة الطلاب' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل الطالب" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>

  return (
    <>
      <PageHeader title={s.full_name} en={s.student_number} subtitle="السجل الأكاديمي المعتمد" back={back}>
        <Badge tone={s.status.code === 'active' ? 'success' : 'neutral'}>{s.status.label}</Badge>
      </PageHeader>
      <div className="grid gap-5">
        <Section title="الهوية الأكاديمية" id="identity">
          <InfoGrid items={[
            ['الرقم الجامعي', <span dir="ltr">{s.student_number}</span>],
            ['الكلية', s.college ? <Link className="text-primary-dark hover:underline" to={`/ministry/colleges/${s.college.id}`}>{s.college.name}</Link> : '—'],
            ['القسم', s.department?.name],
            ['البرنامج', s.program?.name],
            ['الدرجة', s.program?.degree_level],
            ['ساعات البرنامج', s.program?.total_credit_hours ? formatNumber(s.program.total_credit_hours) : '—'],
            ['السنة الدراسية الحالية', s.level],
            ['الحالة', s.status.label],
            ['تاريخ الالتحاق', formatDate(s.enrollment_date)],
            ['الخطة الدراسية', s.plan?.label ? `${s.plan.label}${s.plan.version_number ? ` (النسخة ${s.plan.version_number})` : ''}` : 'غير مسجلة'],
          ]} />
        </Section>
        <Notice>{s.publication_note}</Notice>
        <Section title="الفصول المعتمدة" subtitle="المعدلات والساعات من الفصول المُقفلة رسميًا فقط." id="terms">
          <MiniTable rows={s.terms} rowKey={(r, i) => `${r.term}-${i}`} empty="لا توجد فصول معتمدة مسجلة." columns={[
            { key: 'term', header: 'الفصل', render: r => r.term },
            { key: 'gpa', header: 'المعدل الفصلي', render: r => r.term_gpa ?? '—' },
            { key: 'cgpa', header: 'المعدل التراكمي', render: r => r.cumulative_gpa ?? '—' },
            { key: 'att', header: 'الساعات المحاولة', render: r => formatNumber(r.attempted_hours) },
            { key: 'earn', header: 'الساعات المجتازة', render: r => formatNumber(r.earned_hours) },
          ]} />
        </Section>
        <Section title="النتائج المعتمدة" subtitle={`${formatNumber(s.official_results.length)} نتيجة`} id="results">
          <MiniTable rows={s.official_results} rowKey={(r, i) => `${r.course_id}-${r.term}-${i}`} empty="لا توجد نتائج معتمدة رسميًا لهذا الطالب." columns={[
            { key: 'term', header: 'الفصل', render: r => r.term },
            { key: 'code', header: 'الرمز', render: r => <span dir="ltr">{r.course_code}</span> },
            { key: 'name', header: 'المقرر', render: r => <Link className="text-primary-dark hover:underline" to={`/ministry/courses/${r.course_id}`}>{r.course_name}</Link> },
            { key: 'hours', header: 'الساعات', render: r => formatNumber(r.credit_hours) },
            { key: 'mark', header: 'العلامة النهائية', render: r => r.final_mark ?? '—' },
            { key: 'result', header: 'النتيجة', render: r => <Badge tone={RESULT_TONE[r.result_code] ?? 'neutral'}>{r.result}</Badge> },
          ]} />
        </Section>
        <Section title="التخرج" id="graduation">
          {s.graduation
            ? <InfoGrid items={[['تاريخ اعتماد قرار التخرج', formatDate(s.graduation.approved_at)], ['المعدل التراكمي عند التخرج', s.graduation.cumulative_gpa ?? '—'], ['الساعات المجتازة', formatNumber(s.graduation.earned_hours)]]} />
            : <p className="text-[12.5px] text-text-light">لا يوجد قرار تخرج معتمد ونافذ لهذا الطالب.</p>}
        </Section>
      </div>
    </>
  )
}
