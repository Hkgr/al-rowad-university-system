import { Link, useParams } from 'react-router-dom'
import { fetchMinistryUnit } from '../lib/ministryApi'
import { formatNumber, viewState } from '../lib/ministryState'
import { Badge, PageHeader, Section, StatePanel } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'
import { HoldersList, RoleInfo } from './MinistryLeadership'

function Tree({ nodes }) {
  if (!nodes?.length) return null
  return (
    <ul className="grid gap-1.5 border-r-2 border-primary/15 pr-4 mt-1.5">
      {nodes.map(node => (
        <li key={node.unit_id}>
          <div className="flex items-center gap-2 flex-wrap text-[12.5px]">
            <Link to={`/ministry/leadership/units/${node.unit_id}`} className="font-bold text-primary-dark hover:underline">{node.unit_name}</Link>
            <span className="text-text-light">{node.unit_type}</span>
            {!node.is_active && <Badge>غير مفعّلة</Badge>}
            {node.college_id && <Link to={`/ministry/colleges/${node.college_id}`} className="text-[11.5px] font-bold text-primary hover:underline">صفحة الكلية</Link>}
          </div>
          <Tree nodes={node.children} />
        </li>
      ))}
    </ul>
  )
}

export default function MinistryUnitDetail() {
  const { id } = useParams()
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryUnit(id), [id])
  const u = data?.data
  const state = viewState({ loading, error })
  const back = { to: '/ministry/leadership', label: 'رئاسة الجامعة ونوابها' }
  if (state !== 'ready') return <><PageHeader title="تفاصيل الوحدة" back={back} /><StatePanel state={state} error={error} onRetry={reload} /></>

  return (
    <>
      <PageHeader title={u.unit_name} en={u.unit_code} subtitle={u.unit_type} back={back}>
        <Badge tone={u.is_active ? 'success' : 'neutral'}>{u.is_active ? 'مفعّلة' : 'غير مفعّلة'}</Badge>
        {u.college_id && <Link to={`/ministry/colleges/${u.college_id}`} className="text-[12px] font-bold text-primary hover:underline">صفحة الكلية</Link>}
      </PageHeader>
      <div className="grid gap-5">
        {u.ancestors.length > 0 && (
          <nav aria-label="موقع الوحدة في الهيكل" className="text-[12px] text-text-light" dir="rtl">
            {u.ancestors.map(a => <span key={a.id}><Link to={`/ministry/leadership/units/${a.id}`} className="hover:underline">{a.name}</Link> ‹ </span>)}
            <span className="font-bold text-text-dark">{u.unit_name}</span>
          </nav>
        )}
        <Section title="الشاغلون المسجلون" subtitle="من قيود المناصب؛ يُميَّز الحالي عن السابق." id="holders">
          <HoldersList holders={u.holders} />
        </Section>
        {u.role && <Section title="الحسابات التي تحمل دور هذا المنصب" id="role"><RoleInfo role={u.role} /></Section>}
        <Section title="الوحدات التابعة" subtitle={`${formatNumber(u.direct_units)} وحدة مباشرة، ${formatNumber(u.all_units)} إجمالًا`} id="tree">
          {u.tree.length ? <Tree nodes={u.tree} /> : <p className="text-[12.5px] text-text-light">لا توجد وحدات تابعة مسجلة.</p>}
        </Section>
      </div>
    </>
  )
}
