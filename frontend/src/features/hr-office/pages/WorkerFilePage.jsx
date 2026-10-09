import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom'
import { FaFilePdf, FaCoins } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import { canAccess, clearIdentity, getIdentity } from '../../auth/auth'
import { Notice, Dialog, primaryButton, secondaryButton } from '../../vice-presidency/components/GovernanceUi'
import { Editor, Input, Lookup, RelationFields } from '../components/HrForms'
import WorkerFileContent from '../components/WorkerFileContent'
import { HrStatus } from '../components/HrOfficeUi'
import { personName } from '../components/HrOfficeTables'
import { HR, PAYMENT_MANAGE, blankProposal, canHr, hrDate, hrError, payrollAccess, payrollFilePath, proposalPayload } from '../lib/hrOffice'
import { hrPdf, hrRead, hrWrite, paymentWrite } from '../lib/hrApi'
import { INPUT_ERRORS, formatSyp, parseInput } from '../../owner-portal/lib/payrollMoney'

export default function WorkerFilePage() {
  const { employee } = useParams()
  const identity = JSON.stringify(getIdentity())
  return canHr('view') ? <WorkerWorkspace key={`${identity}:${employee}`} employee={employee} identity={identity} /> : <Notice tone="error">لا تملك صلاحية ملف العامل.</Notice>
}

function WorkerWorkspace({ employee, identity }) {
  const navigate = useNavigate()
  const [data, setData] = useState(null); const [error, setError] = useState(''); const [notice, setNotice] = useState(''); const [reload, setReload] = useState(0); const [page, setPage] = useState(1)
  const [editing, setEditing] = useState(null); const [guard, setGuard] = useState({}); const [exporting, setExporting] = useState(false)
  const sequence = useRef(0); const live = useRef(true); const downloadSequence = useRef(0); const authorityLost = useRef(false)
  const dirty = guard.dirty || guard.uncertain; const busy = guard.busy
  const blocker = useBlocker(() => !authorityLost.current && !!(dirty || busy))
  const requestKey = JSON.stringify([employee, identity, page, reload])
  const matching = data?.key === requestKey
  const clearSensitive = useCallback(e => { authorityLost.current = true; ++sequence.current; ++downloadSequence.current; setData(null); setEditing(null); setGuard({}); setNotice(''); setError(hrError(e)); if (e.status === 401) { clearIdentity(); navigate('/login', { replace: true }) } }, [navigate, setData, setEditing, setGuard, setNotice, setError])
  useEffect(() => { live.current = true; return () => { live.current = false } }, [])
  useEffect(() => {
    const abort = new AbortController(); const seq = ++sequence.current; let active = true
    hrRead(`workers/${employee}/file`, { payment_page: page }, abort.signal).then(json => { if (active && seq === sequence.current) { authorityLost.current = false; setData({ ...json.data, key: requestKey }); setError('') } }).catch(e => { if (active && seq === sequence.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(hrError(e)) } })
    return () => { active = false; abort.abort() }
  }, [employee, page, requestKey, clearSensitive])
  useEffect(() => { if (!dirty && !busy) return undefined; const handler = e => { e.preventDefault(); e.returnValue = '' }; window.addEventListener('beforeunload', handler); return () => window.removeEventListener('beforeunload', handler) }, [dirty, busy])
  const worker = matching ? data.worker : null; const options = matching ? data.options : null; const payments = matching ? data.payments : null
  const payrollRead = !!worker?.can_open_payroll && canAccess(payrollAccess('owner_payroll.view'))
  const canLink = !!options?.capabilities?.[HR.payrollLink] && canHr('payrollLink')
  const canPay = !!options?.capabilities?.[PAYMENT_MANAGE] && canAccess(payrollAccess(PAYMENT_MANAGE))
  const edit = (type, row) => { if (busy || dirty) return; setEditing({ type, row }) }
  const refresh = () => { if (busy || dirty) return; setReload(v => v + 1) }
  const saved = () => { setEditing(null); setNotice('تم الحفظ مع الاحتفاظ بالتاريخ.'); setReload(v => v + 1) }
  async function download() {
    if (exporting || !worker?.can_export || dirty || busy) return
    const seq = ++downloadSequence.current; setExporting(true); setError('')
    try {
      const file = await hrPdf(employee)
      if (!live.current || seq !== downloadSequence.current || JSON.stringify(getIdentity()) !== identity) return
      const url = URL.createObjectURL(file.blob); const link = document.createElement('a'); link.href = url; link.download = file.filename || `worker-${employee}.pdf`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 2000)
    } catch (e) { if (live.current && seq === downloadSequence.current) { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(hrError(e)) } } finally { if (live.current && seq === downloadSequence.current) setExporting(false) }
  }
  const paymentColumns = [
    { key: 'period', header: 'فترة الراتب / المرجع', render: row => <div className="text-[12.5px] leading-6"><bdi className="font-bold">{row.period}</bdi><p className="text-[11px] text-text-light break-words max-w-[180px]">{row.reference}</p></div> },
    { key: 'amount', header: 'المبلغ الفعلي', render: row => <span className="font-bold text-[13px] whitespace-nowrap">{formatSyp(row.amount)}</span> },
    { key: 'paid', header: 'تاريخ الصرف', render: row => <span className="text-[12px] whitespace-nowrap">{hrDate(row.paid_on)}</span> },
    { key: 'status', header: 'الحالة', render: row => <HrStatus value={row.status === 'received' ? 'approved' : row.status === 'paid' ? 'submitted' : 'cancelled'}>{({ paid: 'صرف مسجل — الاستلام غير موثق', received: 'استلام موثق', voided: 'سجل ملغى' })[row.status]}</HrStatus> },
    { key: 'received', header: 'تاريخ الاستلام', render: row => <span className="text-[12px]">{row.received_on ? hrDate(row.received_on) : 'غير موثق'}</span> },
    { key: 'actions', header: 'الإجراءات', render: row => <div className="flex flex-wrap gap-2">{canPay && row.status === 'paid' && <button type="button" className={secondaryButton} onClick={() => edit('receive', row)}>توثيق الاستلام</button>}{canPay && row.status !== 'voided' && <button type="button" className={secondaryButton} onClick={() => edit('voidPayment', row)}>إلغاء السجل الخاطئ</button>}<details className="text-[11.5px] max-w-[220px]"><summary className="cursor-pointer text-primary font-bold">التفاصيل</summary><p className="break-words">{row.reason}</p>{row.receipt_evidence && <p className="break-words">إثبات الاستلام: {row.receipt_evidence}</p>}{row.void_reason && <p className="break-words">سبب الإلغاء: {row.void_reason}</p>}</details></div> },
  ]
  return <div dir="rtl" className="space-y-5 text-text-dark">
    <Link to="/vp/administrative/hr" className="text-primary font-bold text-[12.5px]">مكتب الموارد البشرية / ملف العامل</Link>
    <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-[24px] font-extrabold">{worker ? personName(worker.employee) : 'ملف العامل'}</h1>{worker && <p className="mt-1 text-[12.5px] text-text-light">الرقم الوظيفي: <bdi>{worker.employee.employee_number}</bdi></p>}</div><div className="flex flex-wrap gap-2">{worker?.can_export && canHr('workerExport') && <button type="button" className={primaryButton} disabled={exporting || busy || dirty} onClick={download}><FaFilePdf aria-hidden="true" />{exporting ? 'جارٍ إعداد PDF…' : 'تنزيل ملف العامل PDF'}</button>}<button type="button" className={secondaryButton} disabled={busy || dirty} onClick={refresh}>تحديث البيانات</button></div></div>
    {error && <Notice tone="error">{error}</Notice>}{notice && <Notice tone="success" onDismiss={() => setNotice('')}>{notice}</Notice>}
    {!matching && !error && <p role="status" className="text-[13px] text-text-light">جارٍ تحميل الملف…</p>}
    {worker && <>
      <WorkerFileContent worker={worker} options={options} payrollRead={payrollRead} canLink={canLink} onLink={() => edit('link')} onCorrect={row => edit('correct', row)} onCancel={row => edit('cancel', row)} />
      <section className="space-y-4 rounded-[16px] border border-primary/15 bg-white p-4"><div className="flex flex-wrap justify-between gap-3"><h2 className="text-[16px] font-bold">الرواتب المصروفة والاستلام</h2>{canPay && payments?.schema_ready && worker.payroll && <button type="button" className={primaryButton} onClick={() => edit('payment')}><FaCoins aria-hidden="true" />تسجيل دفعة فعلية</button>}</div>
        {payments === null ? <Notice>بيانات الرواتب ليست ضمن صلاحياتك.</Notice> : !payments.schema_ready ? <Notice tone="warning">سجل الصرف والاستلام غير متاح حتى تثبيت مخططه. لم نعتبر الرواتب السابقة صفرًا.</Notice> : <><div className="grid grid-cols-2 gap-3 max-[560px]:grid-cols-1"><div className="rounded-[12px] bg-primary/5 p-3"><p className="text-[11.5px] text-text-light">الصرف المسجل غير الملغى</p><p className="font-bold">{formatSyp(payments.summary.disbursed)}</p></div><div className="rounded-[12px] bg-primary/5 p-3"><p className="text-[11.5px] text-text-light">الاستلام الموثق غير الملغى</p><p className="font-bold">{formatSyp(payments.summary.received)}</p></div></div><p className="text-[11.5px] text-text-light">دفعات أُدخلت صراحة، وليست مبالغ مستنتجة من ورقة الرواتب. لم تُرحّل رواتب تاريخية تلقائيًا.</p><DataTable columns={paymentColumns} rows={payments.rows} rowKey={row => row.id} emptyTitle="لا توجد دفعات مسجلة في هذا السجل" page={page} totalPages={payments.meta.last_page} onPageChange={value => { if (!busy && !dirty) setPage(value) }} /></>}
        {worker.payroll && payrollRead && <Link to={payrollFilePath(worker.payroll.id)} className="font-bold text-primary text-[12.5px]">فتح ورقة الرواتب الحالية — ليست سجل استلام</Link>}
      </section>
    </>}
    {editing && worker && <WorkerEditor context={editing} worker={worker} options={options} onSaved={saved} onClose={() => setEditing(null)} onGuard={setGuard} onAccessLost={clearSensitive} />}
    {blocker.state === 'blocked' && <Dialog title="تغييرات غير محفوظة" onClose={() => blocker.reset()} footer={<><button type="button" className={secondaryButton} onClick={() => blocker.reset()}>البقاء</button><button type="button" className={primaryButton} disabled={busy} onClick={() => blocker.proceed()}>تجاهل المسودة والانتقال</button></>}><p className="p-6">{busy ? 'انتظر انتهاء الحفظ؛ لا يُعاد الطلب تلقائيًا.' : 'هل تريد تجاهل المسودة والانتقال؟'}</p></Dialog>}
  </div>
}

function WorkerEditor({ context, worker, options, ...rest }) {
  const { type, row } = context
  const [requestId] = useState(() => crypto.randomUUID())
  if (type === 'link') return <Editor {...rest} title="ربط ملف رواتب موجود" initial={{ payroll: null, reason: '', confirmed: false }} write={f => hrWrite(`workers/${worker.employee.employee_id}/payroll-link`, { payroll_employee_id: f.payroll?.id, payroll_revision: f.payroll?.revision, revision: worker.employee.hr_revision, reason: f.reason, confirmed: f.confirmed })}>{(f, set) => <><Lookup title="ملف الرواتب" path="payroll-lookup" value={f.payroll?.id} set={(_, selected) => set(s => ({ ...s, payroll: selected, confirmed: false }))} describe={p => `${p.employee_number} — ${p.full_name}`} /><Input title="سبب الربط" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>تحققت من أن السجلين للشخص نفسه. لا ينشأ ملف أو مبلغ جديد.</Confirmation></>}</Editor>
  if (type === 'cancel') return <Editor {...rest} title="إلغاء نفاذ العلاقة مع حفظ الأصل" initial={{ effective_on: '', reason: '', confirmed: false }} write={f => hrWrite(`relationships/${row.id}/action`, { ...f, action: 'cancel', revision: row.revision })}>{(f, set) => <><Notice>الأصل واعتماده محفوظان. الإلغاء يوقف نفاذ هذه العلاقة من التاريخ المختار ولا يحذف العامل أو تكليفاته أو رواتبه.</Notice><Input title="تاريخ نفاذ الإلغاء" type="date" value={f.effective_on} set={v => set(s => ({ ...s, effective_on: v }))} required /><Input title="سبب الإلغاء" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>أؤكد إلغاء النفاذ من التاريخ المحدد مع حفظ التاريخ.</Confirmation></>}</Editor>
  if (type === 'correct') {
    const proposal = Object.fromEntries(Object.keys(blankProposal()).map(key => [key, row[key] ?? '']))
    proposal.starts_on = ''; proposal.ends_on = ''; proposal.employee_number = ''; proposal.employee_type_id = ''; proposal.predecessor_id = row.id
    return <Editor {...rest} title="تصحيح العلاقة — الأصل محفوظ" initial={{ proposal, reason: '', confirmed: false }} write={f => hrWrite(`relationships/${row.id}/action`, { action: 'correct', revision: row.revision, effective_on: f.proposal.starts_on, reason: f.reason, confirmed: f.confirmed, proposal: proposalPayload(f.proposal) })}>{(f, set) => <><Notice>يُحفظ سجل بديل نافذ من تاريخ البداية الجديد، ويبقى العقد والاعتماد الأصليان كما هما. لا تُعاد كتابة فترات سابقة أو مبالغ مالية.</Notice><RelationFields form={f.proposal} set={change => set(s => ({ ...s, proposal: change(s.proposal) }))} options={options} /><Input title="سبب التصحيح" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>راجعت القيم والتاريخ وأؤكد التصحيح مع حفظ الأصل.</Confirmation></>}</Editor>
  }
  if (type === 'payment') return <Editor {...rest} title="تسجيل دفعة راتب فعلية" initial={{ request_id: requestId, period: '', paid_on: '', amount: '', reference: '', reason: '', confirmed: false }} write={f => { const amount = parseInput({ value_type: 'amount', allow_negative: false }, f.amount); if (!amount.ok || amount.value === null || amount.value === '0.00') return Promise.reject({ status: 422, message: amount.ok ? 'أدخل مبلغًا فعليًا أكبر من الصفر.' : INPUT_ERRORS[amount.error] }); return paymentWrite(`employees/${worker.payroll.id}/payments`, { ...f, amount: amount.value, expected_payroll_revision: worker.payroll.revision, expected_employee_revision: worker.employee.hr_revision }) }}>{(f, set) => <><Notice>هذا تسجيل لدفعة أُجريت فعلًا، وليس أمر تحويل أو إثبات استلام. المبلغ لا يغيّر ورقة الرواتب.</Notice>{[['period', 'فترة الراتب', 'month'], ['paid_on', 'تاريخ الصرف الفعلي', 'date'], ['amount', 'المبلغ الفعلي — ل.س', 'text'], ['reference', 'مرجع الصرف / رقم المستند', 'text'], ['reason', 'وصف الصرف', 'text']].map(([key, title, inputType]) => <Input key={key} title={title} type={inputType} value={f[key]} set={v => set(s => ({ ...s, [key]: v }))} required />)}<Confirmation form={f} set={set}>أؤكد أن الصرف حدث فعلًا وأنني تحققت من العامل والمرجع.</Confirmation></>}</Editor>
  if (type === 'receive') return <Editor {...rest} title="توثيق استلام الدفعة" initial={{ received_on: '', receipt_evidence: '', confirmed: false }} write={f => paymentWrite(`payments/${row.id}/receive`, { ...f, revision: row.revision })}>{(f, set) => <><Notice>{worker.employee.first_name} {worker.employee.last_name} — {formatSyp(row.amount)} — المرجع {row.reference}. لا يولد النظام توقيعًا أو تأكيدًا بنكيًا.</Notice><Input title="تاريخ الاستلام الفعلي" type="date" value={f.received_on} set={v => set(s => ({ ...s, received_on: v }))} required /><Input title="مرجع إثبات الاستلام / التحويل" value={f.receipt_evidence} set={v => set(s => ({ ...s, receipt_evidence: v }))} required /><Confirmation form={f} set={set}>تحققت من استلام العامل هذه الدفعة وأوثق المرجع صراحة.</Confirmation></>}</Editor>
  return <Editor {...rest} title="إلغاء سجل دفعة خاطئ — التاريخ محفوظ" initial={{ reason: '', confirmed: false }} write={f => paymentWrite(`payments/${row.id}/cancel`, { ...f, revision: row.revision })}>{(f, set) => <><Notice tone="warning">يبقى المبلغ والاستلام السابقان في التاريخ. إلغاء السجل لا يثبت استرداد المال ولا ينفذ رد مبلغ.</Notice><Input title="سبب إلغاء السجل" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>أؤكد أن السجل خاطئ وأريد إلغاء احتسابه مع حفظ تاريخه.</Confirmation></>}</Editor>
}

function Confirmation({ form, set, children }) {
  return <label className="flex gap-2 text-[12.5px] leading-7"><input type="checkbox" required checked={form.confirmed} onChange={e => set(s => ({ ...s, confirmed: e.target.checked }))} />{children}</label>
}
