import { useState } from 'react'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { createPayrollEmployee, updatePayrollEmployee } from '../lib/ownerApi'
import { WORKPLACE_OPTIONS, errorText, fieldErrors } from '../lib/payrollView'

const INPUT = 'w-full px-3 py-2.5 border rounded-[9px] text-[13.5px] text-text-dark outline-none transition-colors focus:border-primary disabled:bg-black/[0.03]'
const CODE_PATTERN = /^[\p{L}\p{N}][\p{L}\p{N} ._/-]*$/u

function Field({ id, label, required, error, hint, children }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-[11.5px] font-bold text-text-dark">{label}{required && <span className="mr-0.5 text-red-500" aria-hidden="true">*</span>}</label>
      {children}
      {hint && !error && <p className="mt-1 text-[11px] leading-5 text-text-light">{hint}</p>}
      {error && <p id={`${id}-error`} role="alert" className="mt-1 text-[11.5px] font-semibold leading-5 text-red-600">{error}</p>}
    </div>
  )
}

const blank = bodies => ({ employee_number: '', full_name: '', job_title: '', body_id: '', workplace: '', workplace_other: '', academic_level: '', _bodies: bodies })

/** Add (employee === null) or edit the metadata of one payroll employee. Amounts are never touched here. */
export default function EmployeeDialog({ employee = null, bodies, onSaved, onCancel, onManageBodies }) {
  const [form, setForm] = useState(() => (employee ? {
    employee_number: employee.employee_number, full_name: employee.full_name, job_title: employee.job_title, body_id: String(employee.body_id),
    workplace: employee.workplace, workplace_other: employee.workplace_other ?? '', academic_level: employee.academic_level ?? '',
  } : blank(bodies)))
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const [failure, setFailure] = useState('')

  const selectable = bodies.filter(body => body.is_active || (employee && body.id === employee.body_id))
  const set = (key, value) => { setForm(f => ({ ...f, [key]: value })); setErrors(e => ({ ...e, [key]: '' })) }
  const setWorkplace = value => setForm(f => ({ ...f, workplace: value, workplace_other: value === 'other' ? f.workplace_other : '' })) // obsolete custom text is cleared

  function validate() {
    const next = {}
    const number = form.employee_number.trim()
    if (!number) next.employee_number = 'رقم الموظف مطلوب.'
    else if (number.length > 64 || !CODE_PATTERN.test(number)) next.employee_number = 'رقم الموظف يقبل الحروف والأرقام و - _ . / فقط (حتى 64 محرفًا).'
    if (!form.full_name.trim()) next.full_name = 'الاسم الكامل مطلوب.'
    if (!form.job_title.trim()) next.job_title = 'الصفة الوظيفية مطلوبة.'
    if (!form.body_id) next.body_id = 'اختر الهيئة.'
    if (!form.workplace) next.workplace = 'اختر مكان العمل.'
    if (form.workplace === 'other' && !form.workplace_other.trim()) next.workplace_other = 'اكتب مكان العمل عند اختيار «أخرى».'
    return next
  }

  async function submit() {
    if (busy) return
    const local = validate()
    setErrors(local)
    if (Object.keys(local).length) return
    setBusy(true); setFailure('')
    const payload = {
      employee_number: form.employee_number.trim(), full_name: form.full_name.trim(), job_title: form.job_title.trim(), body_id: Number(form.body_id),
      workplace: form.workplace, workplace_other: form.workplace === 'other' ? form.workplace_other.trim() : null, academic_level: form.academic_level.trim() || null,
    }
    try {
      const response = employee ? await updatePayrollEmployee(employee.id, payload, employee.employee_revision) : await createPayrollEmployee(payload)
      onSaved(response.data)
    } catch (error) {
      if (error.status === 422) { setErrors(fieldErrors(error.details)); setFailure('راجع الحقول المحددة.') } else if (error.status === 409) setFailure(`${errorText(error)} أغلق النافذة وحدّث الصف ثم أعد المحاولة.`)
      else setFailure(errorText(error))
    } finally { setBusy(false) }
  }

  const err = key => errors[key]
  const cls = key => `${INPUT} ${err(key) ? 'border-red-400 bg-red-50' : 'border-primary/20'}`
  const aria = key => ({ 'aria-invalid': Boolean(err(key)), 'aria-describedby': err(key) ? `emp-${key}-error` : undefined })

  return (
    <ManualGradeDialog title={employee ? 'تعديل بيانات الموظف' : 'إضافة موظف'} busy={busy} onConfirm={submit} onCancel={onCancel} confirmLabel={employee ? 'حفظ التعديلات' : 'إضافة'}>
      <form onSubmit={event => { event.preventDefault(); submit() }} noValidate className="grid grid-cols-2 gap-x-3 gap-y-3 max-[520px]:grid-cols-1" autoComplete="off">
        <p className="col-span-2 text-[11.5px] leading-6 text-text-light max-[520px]:col-span-1">سجل مستقل لورقة الرواتب فقط: لا يُنشئ حسابًا ولا يرتبط بسجلات الموارد البشرية أو المدرسين.</p>
        <Field id="emp-employee_number" label="رقم الموظف" required error={err('employee_number')} hint="يُدخل يدويًا ولا يُولَّد تلقائيًا؛ تُحفظ الأصفار البادئة كما كُتبت.">
          <input id="emp-employee_number" className={cls('employee_number')} dir="ltr" value={form.employee_number} onChange={e => set('employee_number', e.target.value)} required {...aria('employee_number')} />
        </Field>
        <Field id="emp-full_name" label="الاسم الكامل" required error={err('full_name')}>
          <input id="emp-full_name" className={cls('full_name')} dir="rtl" value={form.full_name} onChange={e => set('full_name', e.target.value)} required {...aria('full_name')} />
        </Field>
        <Field id="emp-job_title" label="الصفة الوظيفية" required error={err('job_title')}>
          <input id="emp-job_title" className={cls('job_title')} dir="rtl" value={form.job_title} onChange={e => set('job_title', e.target.value)} required {...aria('job_title')} />
        </Field>
        <Field id="emp-body_id" label="الهيئة" required error={err('body_id')}>
          {selectable.length === 0 ? (
            <div className="rounded-[9px] border border-amber-200 bg-amber-50 px-3 py-2.5 text-[12.5px] leading-6 text-amber-900">
              لا توجد هيئات نشطة بعد. أنشئ هيئة أولًا.
              <button type="button" onClick={onManageBodies} className="mr-2 font-bold underline">إدارة الهيئات</button>
            </div>
          ) : (
            <select id="emp-body_id" className={cls('body_id')} dir="rtl" value={form.body_id} onChange={e => set('body_id', e.target.value)} required {...aria('body_id')}>
              <option value="">اختر الهيئة</option>
              {selectable.map(body => <option key={body.id} value={body.id}>{body.name}{body.is_active ? '' : ' (معطّلة)'}</option>)}
            </select>
          )}
        </Field>
        <Field id="emp-workplace" label="مكان العمل" required error={err('workplace')}>
          <select id="emp-workplace" className={cls('workplace')} dir="rtl" value={form.workplace} onChange={e => setWorkplace(e.target.value)} required {...aria('workplace')}>
            <option value="">اختر مكان العمل</option>
            {WORKPLACE_OPTIONS.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </Field>
        {form.workplace === 'other' && (
          <Field id="emp-workplace_other" label="مكان العمل (أخرى)" required error={err('workplace_other')}>
            <input id="emp-workplace_other" className={cls('workplace_other')} dir="rtl" value={form.workplace_other} onChange={e => set('workplace_other', e.target.value)} required {...aria('workplace_other')} />
          </Field>
        )}
        <Field id="emp-academic_level" label="المستوى الأكاديمي" error={err('academic_level')} hint="اختياري ونص حر، منفصل عن الصفة الوظيفية.">
          <input id="emp-academic_level" className={cls('academic_level')} dir="rtl" value={form.academic_level} onChange={e => set('academic_level', e.target.value)} {...aria('academic_level')} />
        </Field>
        {failure && <p role="alert" className="col-span-2 max-[520px]:col-span-1 rounded-[9px] border border-red-200 bg-red-50 px-3 py-2 text-[12.5px] font-semibold leading-6 text-red-700">{failure}</p>}
        <button type="submit" className="sr-only" tabIndex={-1}>حفظ</button>
      </form>
    </ManualGradeDialog>
  )
}
