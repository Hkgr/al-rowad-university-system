import { catalogError } from './catalog'
import { Button, CatalogLookup, Field, Notice, Select } from './CatalogControls'

export default function CourseDistributionFields({ value, onChange, read, disabled, retry }) {
  const update = (key, next) => onChange({ ...value, [key]: next,
    ...(key === 'scope' ? { college: null, department: null } : key === 'college' ? { department: null } : {}) })
  return <fieldset disabled={disabled} className="space-y-3 rounded-[12px] border border-primary/15 p-3">
    <legend className="font-bold text-[12.5px]">نطاق إضافة المادة وتصنيفها</legend>
    <Field label="إضافة المادة على مستوى"><Select aria-label="إضافة المادة على مستوى" value={value.scope} onChange={e => update('scope', e.target.value)}><option value="">الدليل فقط — دون ربط ببرامج</option><option value="university">الجامعة — جميع برامج الجامعة</option><option value="college">الكلية — جميع برامج الكلية</option><option value="department">القسم — جميع برامج القسم</option></Select></Field>
    {value.scope && <><div className="grid gap-3 sm:grid-cols-2">
      {value.scope !== 'university' && <CatalogLookup label="كلية الربط" resource="colleges" value={value.college} onChange={o => update('college', o)} disabled={disabled} />}
      {value.scope === 'department' && <CatalogLookup key={value.college?.id || 'none'} label="قسم الربط" resource="departments" context={{ college_id: value.college?.id }} value={value.department} onChange={o => update('department', o)} disabled={disabled || !value.college} />}
      <Field label="تصنيف المادة في البرامج المستهدفة"><Select aria-label="تصنيف المادة في البرامج المستهدفة" value={value.course_type} onChange={e => update('course_type', e.target.value)}><option value="mandatory">إجباري</option><option value="elective">اختياري</option></Select></Field>
      <CatalogLookup label="المستوى الإرشادي للربط" resource="levels" value={value.level} onChange={o => update('level', o)} disabled={disabled} />
      <CatalogLookup label="الفصل الإرشادي للربط" resource="semesters" value={value.semester} onChange={o => update('semester', o)} disabled={disabled} />
    </div><p className="text-[12px] text-text-light">يشمل الربط البرامج الموجودة الآن فقط. لن تُنشأ ميزانيات متطلبات أو تتغير ساعات التخرج تلقائيًا. المستوى والفصل إرشاديان للمواد الجديدة في البرامج المستهدفة.</p>
      <Notice>{read.loading && 'جارٍ التحقق من جميع البرامج المستهدفة…'}</Notice><Notice error>{read.error && catalogError(read.error)}</Notice>
      {read.error && <Button onClick={retry}>إعادة فحص البرامج</Button>}
      {read.data && <div aria-label="معاينة البرامج المستهدفة"><b>{read.data.program_count} برنامجًا</b><Notice error={!read.data.can_apply}>{read.data.can_apply ? 'جميع البرامج قابلة للربط؛ يلزم تأكيدك قبل الحفظ.' : 'لا يمكن إتمام الربط كاملًا. لن تُحفظ المادة أو أي ارتباط جزئي.'}</Notice><div className="max-h-48 overflow-auto">{read.data.targets.map(p => <p key={p.academic_program_id} className="border-b border-primary/10 py-2 text-[12px]">{p.college_name} / {p.department_name} / {p.program_name}<span className={p.block_reason ? 'block text-red-700' : 'block text-text-light'}>{p.block_reason || 'جاهز للربط'}</span></p>)}</div></div>}
    </>}
  </fieldset>
}
