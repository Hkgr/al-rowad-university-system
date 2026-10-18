import { Notice } from '../scientific-courses/CatalogControls'
import { SCOPES, TYPES, sixGroups, budgetDraftSummary, requirementDisplayGroups } from './programs'

export default function PlanRequirements({ data }) {
  const budget = budgetDraftSummary({ groups: sixGroups(data.groups), total_credit_hours: data.version.total_credit_hours })
  return <><p className="text-[12.5px]">إجمالي التخرج المسجل: {budget.total ?? 'غير محدد'} — مجموع توزيع المتطلبات: {budget.sum ?? 'غير مكتمل'} — الفرق: {budget.difference ?? 'غير محدد'}</p>
    <div className="overflow-x-auto"><table className="w-full text-right text-[12.5px]"><thead><tr>{['نوع المتطلب', 'الساعات المطلوبة المسجلة', 'مواد المجموعة', 'ساعات المواد المتاحة'].map(label => <th key={label} className="border-b border-primary/15 px-3 py-3 font-bold">{label}</th>)}</tr></thead><tbody>{requirementDisplayGroups(data).map(({ scope, type, groups }) => {
      const pool = data.requirement_pools?.available ? data.requirement_pools.groups.find(p => p.requirement_scope === scope && p.requirement_type === type) : null
      return <tr key={scope + ':' + type} className="border-b border-primary/10"><th className="px-3 py-3 font-medium">{SCOPES[scope]} — {TYPES[type]}</th>
        <td className="px-3 py-3">{groups.length ? groups.map(g => <div key={g.requirement_group_id}>{g.required_credit_hours ?? 'غير محدد'}{g.required_credit_hours == null && ' — ساعات المجموعة لم تُحدد'}{!g.is_active && ' — المجموعة غير فعّالة'}</div>) : 'غير محدد — المجموعة غير موجودة'}{groups.length > 1 && <Notice>يوجد أكثر من تعريف؛ يلزم مراجعة الإعداد.</Notice>}</td>
        <td className="px-3 py-3">{groups.length ? groups.map(g => <div key={g.requirement_group_id}><span>عدد الروابط: {g.courses.length}</span><ul>{g.courses.map(c => <li key={c.program_course_id}>{c.course?.course_name || 'مادة غير متاحة'} ({c.course?.course_code || 'غير محدد'}){!c.is_active && ' — ارتباط غير فعّال'}</li>)}</ul></div>) : 'لا توجد مجموعة لربط المواد'}</td>
        <td className="px-3 py-3">{pool?.pool_credit_hours ?? 'غير متاح'}</td></tr>
    })}</tbody></table></div>
    {!data.requirement_pools?.available && <Notice>تعذر التحقق الكامل من إعداد المتطلبات؛ القيم والروابط المسجلة معروضة كما هي. لا تُعتبر القيم غير المتاحة صفرًا، ولا تتغير ميزانية التخرج من مجموع ساعات المواد.</Notice>}
  </>
}
