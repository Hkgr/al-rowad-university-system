import { Button, Notice } from './CatalogControls'

export default function CatalogConflict({ mutation, onRestart }) {
  return <div className="space-y-2"><Notice error>{mutation.error}</Notice>{Object.entries(mutation.fields).map(([key, value]) => <p key={key} role="alert" className="text-sm text-red-700">{[].concat(value).join('، ')}</p>)}{mutation.blocked && <>
    <Notice>القيم المقترحة والنسخة الأصلية محفوظتان في هذا المحرر. لا تعِد الحفظ قبل مراجعة النسخة الرسمية؛ ربما تم الحفظ رغم فقدان الاستجابة. لن يعاد إرسال الطلب تلقائيًا.</Notice>
    <Button disabled={mutation.checking} onClick={mutation.inspect}>جلب النسخة الرسمية للمراجعة</Button>
    {mutation.current && <div className="space-y-2 rounded-xl border p-3"><p>الإصدار الرسمي الحالي: <b dir="ltr">{mutation.current.revision}</b></p><p>{mutation.current.data?.course_name || mutation.current.data?.program_name || 'دليل المواد الحالي'}</p>{mutation.current.data?.course_id && <dl className="grid grid-cols-2 gap-2 text-sm">{[['الرمز', 'course_code'], ['الساعات المعتمدة', 'credit_hours'], ['النظري', 'theoretical_hours'], ['العملي', 'practical_hours'], ['الوصف', 'description']].map(([label, key]) => <div key={key}><dt>{label}</dt><dd>{mutation.current.data[key] ?? '—'}</dd></div>)}</dl>}
      {mutation.current.groups && <ul className="text-sm">{mutation.current.groups.map(g => <li key={g.requirement_group_id}>{g.group_name}: الميزانية {g.required_credit_hours} — المتاح {g.available_credit_hours}</li>)}</ul>}
      <Button danger onClick={() => onRestart(mutation.current)}>تجاهل مسودتي وفتح النسخة الحالية</Button><p className="text-xs text-text-light">يمكنك بدلًا من ذلك إبقاء المحرر مفتوحًا ونسخ قيمك للمراجعة. لا تُربط المسودة تلقائيًا بالإصدار الجديد.</p>
    </div>}
  </>}</div>
}
