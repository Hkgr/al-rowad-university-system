import { Link } from 'react-router-dom'
import { FaSitemap } from 'react-icons/fa'
import { fetchMinistryLeadership } from '../lib/ministryApi'
import { formatDate, formatNumber, viewState } from '../lib/ministryState'
import { Badge, Notice, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

export function HoldersList({ holders, empty = 'لا يوجد شاغل مسجل لهذا المنصب في النظام.' }) {
  if (!holders?.length) return <p className="text-[12.5px] text-amber-800">{empty}</p>
  return (
    <ul className="grid gap-1.5">
      {holders.map((h, i) => (
        <li key={`${h.full_name}-${h.start_date}-${i}`} className="flex items-center gap-2 flex-wrap text-[12.5px]">
          <Badge tone={h.state === 'current' ? 'success' : 'neutral'}>{h.state_label}</Badge>
          <span className="font-bold text-text-dark">{h.full_name}</span>
          <span className="text-text-light">{h.position} — من {formatDate(h.start_date)}{h.end_date ? ` إلى ${formatDate(h.end_date)}` : ''}</span>
        </li>
      ))}
    </ul>
  )
}

export function RoleInfo({ role }) {
  if (!role) return null
  if (!role.exists) return <p className="text-[12.5px] text-amber-800">{role.note ?? 'لا يوجد دور لهذا المنصب في النظام.'}</p>
  return (
    <div className="text-[12.5px]">
      {role.holders.length
        ? <ul className="grid gap-1">{role.holders.map((h, i) => <li key={i}><span className="font-bold">{h.full_name}</span>{h.role_assigned_on && <span className="text-text-light"> — أُسند الدور في {formatDate(h.role_assigned_on)}</span>}</li>)}</ul>
        : <p className="text-text-light">{role.note}</p>}
    </div>
  )
}

function OfficeCard({ unit, title }) {
  return (
    <Section title={title ?? unit.unit_name} subtitle={`${unit.unit_type} — ${formatNumber(unit.direct_units)} وحدة مباشرة، ${formatNumber(unit.all_units)} وحدة تابعة إجمالًا`}
      action={<Link to={`/ministry/leadership/units/${unit.unit_id}`} className="text-[12px] font-bold text-primary hover:underline whitespace-nowrap">تفاصيل المنصب والوحدات</Link>} id={`unit-${unit.unit_id}`}>
      <div className="grid gap-3">
        <div><p className="text-[11.5px] font-bold text-text-light mb-1">الشاغلون المسجلون (قيود المناصب)</p><HoldersList holders={unit.holders} /></div>
        <div><p className="text-[11.5px] font-bold text-text-light mb-1">حسابات تحمل دور هذا المنصب</p><RoleInfo role={unit.role} /></div>
      </div>
    </Section>
  )
}

export default function MinistryLeadership() {
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryLeadership(), [])
  const l = data?.data
  const state = viewState({ loading, error })
  return (
    <>
      <PageHeader title="رئاسة الجامعة ونوابها" en="University leadership" subtitle="المناصب والوحدات كما يسجلها الهيكل التنظيمي في النظام، والشاغلون من السجلات الفعلية فقط." />
      {state !== 'ready' ? <StatePanel state={state} error={error} onRetry={reload} /> : (
        <div className="grid gap-5">
          <Notice>{l.source_note}</Notice>
          {l.presidency ? <OfficeCard unit={l.presidency} title={`${l.presidency.unit_name} (الرئاسة)`} /> : <StatePanel state="empty" emptyTitle="وحدة الرئاسة غير مسجلة في الهيكل التنظيمي." />}
          <h3 className="text-[15px] font-extrabold text-text-dark flex items-center gap-2" dir="rtl"><FaSitemap className="text-primary" />نواب رئيس الجامعة</h3>
          {l.vice_presidencies.length === 0 && <StatePanel state="empty" emptyTitle="لا توجد وحدات نيابة مسجلة." />}
          <div className="grid grid-cols-3 gap-4 max-[1200px]:grid-cols-1">
            {l.vice_presidencies.map(unit => <OfficeCard key={unit.unit_id} unit={unit} />)}
          </div>
          {l.legacy_vice_president_role.exists && l.legacy_vice_president_role.holders.length > 0 && (
            <Section title="حسابات بالدور القديم «نائب رئيس»" subtitle={l.legacy_vice_president_role.note}>
              <RoleInfo role={{ exists: true, holders: l.legacy_vice_president_role.holders }} />
            </Section>
          )}
        </div>
      )}
    </>
  )
}
