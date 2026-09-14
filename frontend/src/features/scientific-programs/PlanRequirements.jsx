import { Notice } from '../scientific-courses/CatalogControls'
import { SCOPES, TYPES, sixGroups, budgetDraftSummary } from './programs'

export default function PlanRequirements({ data }) {
  const groups = sixGroups(data.groups)
  const budget = budgetDraftSummary({ groups, total_credit_hours: data.version.total_credit_hours })
  return <><p className="text-[12.5px]">إجمالي التخرج: {budget.total ?? 'لم يحدد'} — مجموع التوزيع: {budget.sum ?? 'لم يكتمل'} — الفرق: {budget.difference ?? 'لم يحدد'}</p>
    <div className="overflow-x-auto"><table className="w-full text-right text-[12.5px]"><thead><tr>{['نوع المتطلب', 'الساعات المطلوبة', 'ساعات المواد المتاحة', 'عدد المواد'].map(label => <th key={label} className="border-b border-primary/15 px-3 py-3 font-bold">{label}</th>)}</tr></thead><tbody>{groups.map(g => {
      const pool = data.requirement_pools?.available ? data.requirement_pools.groups.find(p => p.requirement_scope === g.requirement_scope && p.requirement_type === g.requirement_type) : null
      return <tr key={`${g.requirement_scope}:${g.requirement_type}`} className="border-b border-primary/10"><th className="px-3 py-3 font-medium">{SCOPES[g.requirement_scope]} — {TYPES[g.requirement_type]}</th><td className="px-3 py-3">{g.required_credit_hours === '' ? 'لم يحدد' : g.required_credit_hours}</td><td className="px-3 py-3">{pool?.pool_credit_hours ?? 'غير متاح'}</td><td className="px-3 py-3">{pool?.course_count ?? 'غير متاح'}</td></tr>
    })}</tbody></table></div>
    {!data.requirement_pools?.available && <Notice>لا تتوفر قراءة المواد وفق الخدمة الرسمية حتى مراجعة إعداد المتطلبات. لا تُعتبر القيم غير المتاحة صفرًا.</Notice>}
  </>
}
