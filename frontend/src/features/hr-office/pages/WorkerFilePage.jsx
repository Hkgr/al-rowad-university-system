import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams } from 'react-router-dom'
import { FaFilePdf } from 'react-icons/fa'
import { canAccess, clearIdentity, getIdentity } from '../../auth/auth'
import { Notice, Dialog, primaryButton, secondaryButton } from '../../vice-presidency/components/GovernanceUi'
import { Editor, Input, RelationFields } from '../components/HrForms'
import WorkerFileContent from '../components/WorkerFileContent'
import OfficeHeading from '../components/OfficeHeading'
import { personName } from '../components/HrOfficeTables'
import { blankProposal, canHr, hrError, payrollAccess, proposalPayload } from '../lib/hrOffice'
import { hrPdf, hrRead, hrWrite } from '../lib/hrApi'

export default function WorkerFilePage() {
  const { employee } = useParams()
  const identity = JSON.stringify(getIdentity())
  return canHr('view') ? <WorkerWorkspace key={`${identity}:${employee}`} employee={employee} identity={identity} /> : <Notice tone="error">لا تملك صلاحية ملف العامل.</Notice>
}

function WorkerWorkspace({ employee, identity }) {
  const navigate = useNavigate()
  const [data, setData] = useState(null); const [error, setError] = useState(''); const [notice, setNotice] = useState(''); const [reload, setReload] = useState(0)
  const [editing, setEditing] = useState(null); const [guard, setGuard] = useState({}); const [exporting, setExporting] = useState(false)
  const sequence = useRef(0); const live = useRef(true); const downloadSequence = useRef(0); const authorityLost = useRef(false)
  const dirty = guard.dirty || guard.uncertain; const busy = guard.busy
  const blocker = useBlocker(() => !authorityLost.current && !!(dirty || busy))
  const requestKey = JSON.stringify([employee, identity, reload])
  const matching = data?.key === requestKey
  const clearSensitive = useCallback(e => { authorityLost.current = true; ++sequence.current; ++downloadSequence.current; setData(null); setEditing(null); setGuard({}); setNotice(''); setError(hrError(e)); if (e.status === 401) { clearIdentity(); navigate('/login', { replace: true }) } }, [navigate, setData, setEditing, setGuard, setNotice, setError])
  useEffect(() => { live.current = true; return () => { live.current = false } }, [])
  useEffect(() => {
    const abort = new AbortController(); const seq = ++sequence.current; let active = true
    hrRead(`workers/${employee}/file`, {}, abort.signal).then(json => { if (active && seq === sequence.current) { authorityLost.current = false; setData({ ...json.data, key: requestKey }); setError('') } }).catch(e => { if (active && seq === sequence.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(hrError(e)) } })
    return () => { active = false; abort.abort() }
  }, [employee, requestKey, clearSensitive])
  useEffect(() => { if (!dirty && !busy) return undefined; const handler = e => { e.preventDefault(); e.returnValue = '' }; window.addEventListener('beforeunload', handler); return () => window.removeEventListener('beforeunload', handler) }, [dirty, busy])
  const worker = matching ? data.worker : null; const options = matching ? data.options : null
  const payrollRead = !!worker?.can_open_payroll && canAccess(payrollAccess('owner_payroll.view'))
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
  return <div dir="rtl" className="office-surface space-y-5 text-text-dark">
    <Link to="/vp/administrative/hr" className="text-primary font-bold text-[12.5px]">مكتب الموارد البشرية / ملف العامل</Link>
    <OfficeHeading title={worker ? personName(worker.employee) : 'ملف العامل'} code="711" directorate="مديرية الشؤون الإدارية 71">{worker?.can_export && canHr('workerExport') && <button type="button" className="hr-btn primary" disabled={exporting || busy || dirty} onClick={download}><FaFilePdf />{exporting ? 'جارٍ إعداد PDF…' : 'تنزيل ملف الموارد البشرية PDF'}</button>}<button type="button" className="hr-btn" disabled={busy || dirty} onClick={refresh}>تحديث البيانات</button></OfficeHeading>
    {error && <Notice tone="error">{error}</Notice>}{notice && <Notice tone="success" onDismiss={() => setNotice('')}>{notice}</Notice>}
    {!matching && !error && <p role="status" className="text-[13px] text-text-light">جارٍ تحميل الملف…</p>}
    {worker && <>
      <WorkerFileContent worker={worker} options={options} payrollRead={payrollRead} onCorrect={row => edit('correct', row)} onCancel={row => edit('cancel', row)} />
      <section className="office-frame p-4 space-y-3"><div className="flex flex-wrap justify-between gap-3"><h2 className="font-bold">الدوام الفعلي الموثق للمحاسبة</h2>{options.capabilities['administrative_hr.work_time.manage'] && <button type="button" className="hr-btn primary" disabled={busy || dirty} onClick={() => edit('time')}>توثيق بيانات الدوام</button>}</div><p className="text-[11px] text-text-light">أيام وساعات يثبتها المكتب بمصدر صريح؛ لا تُستنتج من كلي/جزئي ولا تتضمن تتبع حضور.</p>{data.work_times === null ? <Notice tone="warning">مخطط الدوام غير جاهز.</Notice> : data.work_times.length === 0 ? <p>لا توجد بيانات دوام شهرية موثقة.</p> : data.work_times.map(t => <div key={t.id} className="flex flex-wrap gap-3 rounded-[10px] bg-primary/5 p-3"><bdi>{t.period}</bdi><span>أيام: {t.days ?? 'غير محدد'} / ساعات: {t.hours ?? 'غير محدد'}</span><span className="flex-1 break-words">{t.source_reference}</span>{options.capabilities['administrative_hr.work_time.manage'] && <button type="button" className="hr-btn" onClick={() => edit('time', t)}>تصحيح موثق</button>}</div>)}
      </section>
    </>}
    {editing && worker && <WorkerEditor context={editing} worker={worker} options={options} times={data.work_times || []} onSaved={saved} onClose={() => setEditing(null)} onGuard={setGuard} onAccessLost={clearSensitive} />}
    {blocker.state === 'blocked' && <Dialog title="تغييرات غير محفوظة" onClose={() => blocker.reset()} footer={<><button type="button" className={secondaryButton} onClick={() => blocker.reset()}>البقاء</button><button type="button" className={primaryButton} disabled={busy} onClick={() => blocker.proceed()}>تجاهل المسودة والانتقال</button></>}><p className="p-6">{busy ? 'انتظر انتهاء الحفظ؛ لا يُعاد الطلب تلقائيًا.' : 'هل تريد تجاهل المسودة والانتقال؟'}</p></Dialog>}
  </div>
}

function WorkerEditor({ context, worker, options, times, ...rest }) {
  const { type, row } = context
  if (type === 'time') return <Editor {...rest} title="توثيق الدوام الفعلي للمحاسبة" initial={{ period: row?.period || '', revision: row?.revision || 0, days: row?.days ?? '', hours: row?.hours ?? '', source_reference: row?.source_reference || '', confirmed: false }} write={f => hrWrite(`workers/${worker.employee.employee_id}/work-time`, { ...f, days: f.days === '' ? null : f.days, hours: f.hours === '' ? null : f.hours })}>{(f, set) => <><Input title="شهر البيانات" type="month" value={f.period} set={v => { const old = times.find(t => t.period === v); set(s => ({ ...s, period: v, revision: old?.revision || 0, days: old?.days ?? '', hours: old?.hours ?? '', source_reference: old?.source_reference || '', confirmed: false })) }} required /><div className="grid grid-cols-2 gap-4"><Input title="الأيام الفعلية المعروفة" value={f.days} set={v => set(s => ({ ...s, days: v, confirmed: false }))} /><Input title="الساعات الفعلية المعروفة" value={f.hours} set={v => set(s => ({ ...s, hours: v, confirmed: false }))} /></div><Input title="المصدر / مرجع التوثيق وسبب التصحيح" value={f.source_reference} set={v => set(s => ({ ...s, source_reference: v }))} required /><Confirmation form={f} set={set}>تحققت من المصدر الفعلي؛ لا أستنتج البيانات من نوع الدوام.</Confirmation></>}</Editor>
  if (type === 'cancel') return <Editor {...rest} title="إلغاء نفاذ العلاقة مع حفظ الأصل" initial={{ effective_on: '', reason: '', confirmed: false }} write={f => hrWrite(`relationships/${row.id}/action`, { ...f, action: 'cancel', revision: row.revision })}>{(f, set) => <><Notice>الأصل واعتماده محفوظان. الإلغاء يوقف نفاذ هذه العلاقة من التاريخ المختار ولا يحذف العامل أو تكليفاته أو رواتبه.</Notice><Input title="تاريخ نفاذ الإلغاء" type="date" value={f.effective_on} set={v => set(s => ({ ...s, effective_on: v }))} required /><Input title="سبب الإلغاء" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>أؤكد إلغاء النفاذ من التاريخ المحدد مع حفظ التاريخ.</Confirmation></>}</Editor>
  if (type === 'correct') {
    const proposal = Object.fromEntries(Object.keys(blankProposal()).map(key => [key, row[key] ?? '']))
    proposal.starts_on = ''; proposal.ends_on = ''; proposal.employee_number = ''; proposal.employee_type_id = ''; proposal.predecessor_id = row.id
    return <Editor {...rest} title="تصحيح العلاقة — الأصل محفوظ" initial={{ proposal, reason: '', confirmed: false }} write={f => hrWrite(`relationships/${row.id}/action`, { action: 'correct', revision: row.revision, effective_on: f.proposal.starts_on, reason: f.reason, confirmed: f.confirmed, proposal: proposalPayload(f.proposal) })}>{(f, set) => <><Notice>يُحفظ سجل بديل نافذ من تاريخ البداية الجديد، ويبقى العقد والاعتماد الأصليان كما هما. لا تُعاد كتابة فترات سابقة أو مبالغ مالية.</Notice><RelationFields form={f.proposal} set={change => set(s => ({ ...s, proposal: change(s.proposal) }))} options={options} /><Input title="سبب التصحيح" value={f.reason} set={v => set(s => ({ ...s, reason: v }))} required /><Confirmation form={f} set={set}>راجعت القيم والتاريخ وأؤكد التصحيح مع حفظ الأصل.</Confirmation></>}</Editor>
  }
}

function Confirmation({ form, set, children }) {
  return <label className="flex gap-2 text-[12.5px] leading-7"><input type="checkbox" required checked={form.confirmed} onChange={e => set(s => ({ ...s, confirmed: e.target.checked }))} />{children}</label>
}
