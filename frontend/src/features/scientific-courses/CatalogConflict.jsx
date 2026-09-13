import { Button, Notice } from './CatalogControls'

export default function CatalogConflict({ mutation, onRestart }) {
  return <div className="space-y-2"><Notice error>{mutation.error}</Notice>{Object.entries(mutation.fields).map(([key, value]) => <p key={key} role="alert" className="text-sm text-red-700">{[].concat(value).join('، ')}</p>)}{mutation.blocked && <>
    <Notice>احتُفظ بتعديلاتك. قد يكون الحفظ نجح رغم انقطاع الاتصال؛ راجع البيانات قبل المحاولة مجددًا.</Notice>
    <Button disabled={mutation.checking} onClick={mutation.inspect}>مراجعة البيانات الحالية</Button>
    {mutation.current && <div className="space-y-2 rounded-[10px] border border-primary/15 p-3"><p className="font-bold">{mutation.current.data?.course_name || mutation.current.data?.program_name || 'المواد الحالية'}</p>{mutation.current.data?.course_id && <dl className="grid grid-cols-2 gap-2 text-[12.5px]">{[['الرمز', 'course_code'], ['الساعات المعتمدة', 'credit_hours'], ['النظري', 'theoretical_hours'], ['العملي', 'practical_hours'], ['الوصف', 'description']].map(([label, key]) => <div key={key}><dt>{label}</dt><dd>{mutation.current.data[key] ?? '—'}</dd></div>)}</dl>}
      {mutation.current.groups && <ul>{mutation.current.groups.map(g => <li key={g.requirement_group_id}>{g.group_name}: المطلوبة {g.required_credit_hours} — المتاحة {g.available_credit_hours}</li>)}</ul>}
      <Button danger onClick={() => onRestart(mutation.current)}>تجاهل تعديلاتي وفتح البيانات الحالية</Button>
    </div>}
  </>}</div>
}
