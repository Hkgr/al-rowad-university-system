import { useState } from 'react'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { createPayrollBody, deletePayrollBody, renamePayrollBody, setPayrollBodyActive } from '../lib/ownerApi'
import { errorText, fieldErrors } from '../lib/payrollView'

const INPUT = 'min-w-0 flex-1 px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark outline-none focus:border-primary'
const SMALL = 'rounded-[9px] border border-primary/20 bg-white px-3 py-1.5 text-[11.5px] font-bold text-text-gray hover:bg-primary/[0.06] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50'

/** Payroll bodies (الهيئات): user-managed classifications. List, add, rename, deactivate/reactivate, delete unreferenced. */
export default function BodiesDialog({ bodies, onChanged, onClose }) {
  const [name, setName] = useState('')
  const [editing, setEditing] = useState(null) // { id, name }
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')

  async function run(action) {
    setBusy(true); setMessage('')
    try { await action(); await onChanged() } catch (error) {
      const first = Object.values(fieldErrors(error.details))[0]
      setMessage(error.status === 422 && first ? first : errorText(error))
      if (error.status === 409) await onChanged()
    } finally { setBusy(false) }
  }

  const add = () => run(async () => { await createPayrollBody(name); setName('') })
  const rename = () => run(async () => { const body = bodies.find(b => b.id === editing.id); await renamePayrollBody(body.id, editing.name, body.revision); setEditing(null) })

  return (
    <ManualGradeDialog title="إدارة الهيئات" onConfirm={onClose} onCancel={onClose} confirmLabel="إغلاق" hideCancel>
      <p className="text-[11.5px] leading-6 text-text-light">الهيئات تصنيفات خاصة بورقة الرواتب، وليست أدوارًا في النظام ولا وحدات تنظيمية. تعطيل الهيئة يُبقي موظفيها ظاهرين لكن يمنع إسناد موظفين جدد إليها.</p>
      <form onSubmit={event => { event.preventDefault(); add() }} className="flex gap-2" aria-label="إضافة هيئة">
        <input aria-label="اسم الهيئة الجديدة" className={INPUT} dir="rtl" placeholder={bodies.length ? 'اسم هيئة جديدة' : 'اكتب اسم أول هيئة'} value={name} onChange={e => setName(e.target.value)} maxLength={150} />
        <button type="submit" disabled={busy || !name.trim()} className="rounded-[9px] border border-primary bg-primary px-4 py-2 text-[12px] font-bold text-white hover:bg-primary-dark disabled:opacity-50">إضافة هيئة</button>
      </form>
      {message && <p role="alert" className="rounded-[9px] border border-red-200 bg-red-50 px-3 py-2 text-[12.5px] font-semibold text-red-700">{message}</p>}
      {bodies.length === 0 ? (
        <p className="rounded-[9px] border border-primary/12 bg-primary/[0.03] px-3 py-4 text-center text-[12.5px] text-text-gray">لا توجد هيئات بعد. أضف أول هيئة لتتمكن من إضافة الموظفين.</p>
      ) : (
        <ul className="grid gap-2" aria-label="الهيئات">
          {bodies.map(body => (
            <li key={body.id} className="rounded-[10px] border border-primary/12 px-3 py-2.5">
              {editing?.id === body.id ? (
                <form onSubmit={event => { event.preventDefault(); rename() }} className="flex gap-2">
                  <input aria-label={`اسم جديد للهيئة ${body.name}`} className={INPUT} dir="rtl" autoFocus value={editing.name} onChange={e => setEditing({ id: body.id, name: e.target.value })} maxLength={150} />
                  <button type="submit" disabled={busy || !editing.name.trim()} className={SMALL}>حفظ</button>
                  <button type="button" disabled={busy} onClick={() => setEditing(null)} className={SMALL}>إلغاء</button>
                </form>
              ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className="break-words text-[13.5px] font-bold text-text-dark">{body.name}</p>
                    <p className="text-[11px] text-text-light">{body.employee_count} موظفًا · {body.is_active ? 'نشطة' : 'معطّلة'}</p>
                  </div>
                  <div className="flex flex-wrap gap-1.5">
                    <button type="button" disabled={busy} onClick={() => setEditing({ id: body.id, name: body.name })} className={SMALL} aria-label={`إعادة تسمية ${body.name}`}>إعادة تسمية</button>
                    <button type="button" disabled={busy} onClick={() => run(() => setPayrollBodyActive(body.id, !body.is_active, body.revision))} className={SMALL} aria-label={`${body.is_active ? 'تعطيل' : 'تفعيل'} ${body.name}`}>{body.is_active ? 'تعطيل' : 'تفعيل'}</button>
                    {body.employee_count === 0 && <button type="button" disabled={busy} onClick={() => run(() => deletePayrollBody(body.id, body.revision))} className={`${SMALL} !border-red-300 !text-red-600`} aria-label={`حذف ${body.name}`}>حذف</button>}
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </ManualGradeDialog>
  )
}
