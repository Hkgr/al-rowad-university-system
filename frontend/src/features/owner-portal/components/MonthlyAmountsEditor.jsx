import { useEffect, useState } from 'react'
import { Dialog, Notice, inputClass, primaryButton, secondaryButton } from '../../vice-presidency/components/GovernanceUi'
import { editText, INPUT_ERRORS, parseInput } from '../lib/payrollMoney'
import { compare, parseDec } from '../lib/payrollDecimal'

export default function MonthlyAmountsEditor({ row, config, existing, onSave, onClose, onGuard }) {
  const inputs = config.columns.filter(c => c.kind === 'input')
  const [baseline] = useState(() => Object.fromEntries(inputs.map(c => {
    const stored = (existing?.values || row.inputs)?.[c.key]
    return [c.key, editText(c.value_type, stored === '' ? null : stored)]
  })))
  const [values, setValues] = useState(baseline); const [error, setError] = useState('')
  const dirty = inputs.some(c => values[c.key] !== baseline[c.key])
  useEffect(() => { onGuard({ dirty }); return () => onGuard({}) }, [dirty, onGuard])
  function stage(event) {
    event.preventDefault(); const normalized = {}; const source = existing?.row || row
    for (const column of inputs) {
      const value = parseInput(column, values[column.key] ?? '')
      if (!value.ok) { setError(`${column.label}: ${INPUT_ERRORS[value.error]}`); return }
      if (value.value === null) continue
      const stored = source.inputs?.[column.key]
      // Preserve the original storage representation for equal values, including explicit zero.
      const unchanged = stored != null && stored !== '' && (column.value_type === 'text' ? value.value === stored : compare(parseDec(value.value), parseDec(stored)) === 0)
      normalized[column.key] = unchanged ? stored : value.value
    }
    onSave({ row: source, values: normalized })
  }
  const groups = [...new Set(inputs.map(c => c.group))]
  return <Dialog title={`مبالغ الشهر — ${row.full_name}`} as="form" onSubmit={stage} onClose={() => { if (dirty && !window.confirm('تجاهل مدخلات هذه النافذة؟')) return; onClose() }} footer={<><button type="button" className={secondaryButton} onClick={onClose}>إلغاء</button><button type="submit" className={primaryButton}>إضافة إلى التغييرات</button></>}><div className="p-5 space-y-4">{error && <Notice tone="error">{error}</Notice>}<Notice>الحقول من تعريفات الرواتب الرسمية. الفراغ «لم يحدد»، والصفر الصريح قيمة مدخلة. الحفظ النهائي لاحقًا لجميع الصفوف المعدّلة.</Notice>{groups.map(group => <fieldset key={group} className="rounded-[12px] border border-primary/15 p-4"><legend className="px-2 font-bold">{config.groups.find(g => g.key === group)?.label || group}</legend><div className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">{inputs.filter(c => c.group === group).map(c => <label key={c.key} className="space-y-1 text-[12px]"><span className="block font-bold">{c.label}</span><input className={inputClass} dir={c.value_type === 'text' ? 'rtl' : 'ltr'} value={values[c.key] ?? ''} onChange={e => setValues(s => ({ ...s, [c.key]: e.target.value }))} /><span className="block text-[10px] text-text-light">{c.value_type === 'amount' ? 'ل.س — مبلغ مدخل' : c.value_type === 'percent' ? 'نسبة وفق إعداد البند' : 'مدخل صريح'}</span></label>)}</div></fieldset>)}</div></Dialog>
}
