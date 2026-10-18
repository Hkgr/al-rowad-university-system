import { useEffect, useRef, useState } from 'react'
import DataTable from '../../../components/table/DataTable'
import { Notice } from '../../vice-presidency/components/GovernanceUi'
import { Editor, Input } from '../../hr-office/components/HrForms'
import { hrDate } from '../../hr-office/lib/hrOffice'
import { formatSyp, INPUT_ERRORS, parseInput } from '../lib/payrollMoney'
import { accountingError } from '../lib/monthlyApi'

const status = { paid: 'مصروف — الاستلام غير موثق', received: 'استلام موثق', voided: 'سجل ملغى' }
export default function AccountingLedger({ api, worker, period, periodRevision, canWrite, disabled, onGuard, onAccessLost, onChanged }) {
  const [page, setPage] = useState(1); const [data, setData] = useState(null); const [error, setError] = useState(''); const [editing, setEditing] = useState(null); const [reload, setReload] = useState(0)
  const sequence = useRef(0); const key = JSON.stringify([worker.id, period, page, reload]); const matching = data?.key === key
  useEffect(() => {
    const seq = ++sequence.current; const abort = new AbortController(); let active = true
    api.read(`employees/${worker.id}/payments`, { period, page }, abort.signal).then(json => { if (active && seq === sequence.current) { setData({ ...json.data, key }); setError('') } }).catch(e => { if (active && seq === sequence.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) onAccessLost(e); else setError(accountingError(e)) } })
    return () => { active = false; abort.abort() }
  }, [api, worker.id, period, page, key, onAccessLost])
  const saved = () => { setEditing(null); setReload(v => v + 1); onChanged() }
  const columns = [
    { key: 'reference', header: 'مرجع الصرف', render: r => <span className="break-words max-w-[150px] block">{r.reference}</span> },
    { key: 'amount', header: 'المبلغ', render: r => formatSyp(r.amount) },
    { key: 'paid_on', header: 'تاريخ الصرف', render: r => hrDate(r.paid_on) },
    { key: 'status', header: 'الحالة', render: r => <span className={`hr-status ${r.status === 'received' ? 'bg-green-100 text-green-800' : r.status === 'voided' ? 'bg-gray-100' : 'bg-amber-50 text-amber-800'}`}>{status[r.status]}</span> },
    { key: 'received_on', header: 'الاستلام', render: r => r.received_on ? hrDate(r.received_on) : 'غير موثق' },
    { key: 'actions', header: 'الإجراءات', render: r => <div className="flex flex-wrap gap-2">{canWrite && r.status === 'paid' && <button className="hr-btn" disabled={disabled} onClick={() => setEditing({ type: 'receive', row: r })}>توثيق الاستلام</button>}{canWrite && r.status !== 'voided' && <button className="hr-btn" disabled={disabled} onClick={() => setEditing({ type: 'void', row: r })}>إلغاء السجل الخاطئ</button>}<details><summary className="font-bold text-primary cursor-pointer">التفاصيل</summary><p>{r.reason}</p><p>{r.receipt_evidence}</p><p>{r.void_reason}</p></details></div> },
  ]
  return <section className="space-y-3 border-t border-primary/15 pt-4"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-[16px] font-bold">الصرف الفعلي والاستلام — {period}</h2>{canWrite && <button className="hr-btn primary" disabled={disabled} onClick={() => setEditing({ type: 'record', expected_period_revision: periodRevision })}>تسجيل صرف فعلي</button>}</div><p className="text-[11px] text-text-light">المبلغ المصروف لا يُستنتج من المستحق. التصحيح بإلغاء السجل الخاطئ مع حفظه، ثم تسجيل البيان الصحيح. الإلغاء ليس إثبات استرداد المال.</p>{error && <Notice tone="error">{error}<button className="hr-btn mr-2" onClick={() => setReload(v => v + 1)}>تحديث السجل</button></Notice>}<DataTable columns={columns} rows={matching ? data.rows : []} rowKey={r => r.id} loading={!matching && !error} emptyTitle="لا توجد دفعات مسجلة لهذه الفترة" page={page} totalPages={matching ? data.meta.last_page : 1} onPageChange={p => { if (!disabled) setPage(p) }} />{editing && <PaymentEditor context={editing} worker={worker} period={period} api={api} onGuard={onGuard} onSaved={saved} onClose={() => setEditing(null)} onAccessLost={onAccessLost} />}</section>
}

function PaymentEditor({ context, worker, period, api, ...rest }) {
  const [uuid] = useState(() => crypto.randomUUID()); const { row, type } = context
  if (type === 'record') return <Editor {...rest} title="تسجيل صرف حدث فعلًا" initial={{ paid_on: '', amount: '', reference: '', reason: '', confirmed: false }} write={f => { const amount = parseInput({ value_type: 'amount', allow_negative: false }, f.amount); if (!amount.ok || !amount.value || amount.value === '0.00') return Promise.reject({ status: 422, message: amount.ok ? 'المبلغ الفعلي يجب أن يكون موجبًا.' : INPUT_ERRORS[amount.error] }); return api.write(`employees/${worker.id}/payments`, { ...f, request_id: uuid, period, amount: amount.value, expected_period_revision: context.expected_period_revision, expected_payroll_revision: worker.current_employee_revision, expected_employee_revision: worker.current_hr_revision }) }}>{(f, set) => <><Notice>{worker.full_name} — {period}. لا ينفذ النظام تحويلًا ماليًا ولا يسجل استلامًا تلقائيًا.</Notice>{[['paid_on', 'تاريخ الصرف', 'date'], ['amount', 'المبلغ الفعلي — ل.س', 'text'], ['reference', 'رقم المستند / مرجع الصرف', 'text'], ['reason', 'بيان الصرف', 'text']].map(([key, title, type]) => <Input key={key} title={title} type={type} value={f[key]} set={v => set(s => ({ ...s, [key]: v, confirmed: false }))} required />)}<Confirmation form={f} set={set}>أؤكد أن الصرف حدث فعلًا لهذا العامل ولهذا الشهر.</Confirmation></>}</Editor>
  if (type === 'receive') return <Editor {...rest} title="توثيق الاستلام الفعلي" initial={{ received_on: '', receipt_evidence: '', confirmed: false }} write={f => api.write(`payments/${row.id}/receive`, { ...f, revision: row.revision })}>{(f, set) => <><Notice>{worker.full_name} — {formatSyp(row.amount)} — المرجع {row.reference}.</Notice><Input title="تاريخ الاستلام" type="date" value={f.received_on} set={v => set(s => ({ ...s, received_on: v, confirmed: false }))} required /><Input title="مرجع إثبات الاستلام" value={f.receipt_evidence} set={v => set(s => ({ ...s, receipt_evidence: v, confirmed: false }))} required /><Confirmation form={f} set={set}>تحققت من استلام العامل الدفعة وأوثق مرجعها صراحة.</Confirmation></>}</Editor>
  return <Editor {...rest} title="إلغاء سجل مالي خاطئ — التاريخ محفوظ" initial={{ reason: '', confirmed: false }} write={f => api.write(`payments/${row.id}/cancel`, { ...f, revision: row.revision })}>{(f, set) => <><Notice tone="warning">يبقى السجل والاستلام السابقان محفوظين. لا ينفذ الإلغاء رد مال.</Notice><Input title="سبب الإلغاء" value={f.reason} set={v => set(s => ({ ...s, reason: v, confirmed: false }))} required /><Confirmation form={f} set={set}>أؤكد إلغاء احتساب هذا السجل الخاطئ مع حفظ تاريخه.</Confirmation></>}</Editor>
}
function Confirmation({ form, set, children }) { return <label className="flex gap-2 leading-7"><input type="checkbox" required checked={form.confirmed} onChange={e => set(s => ({ ...s, confirmed: e.target.checked }))} />{children}</label> }
