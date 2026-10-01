import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal, flushSync } from 'react-dom'
import { ACCESS, canAccess } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import { Section, Notice, StatePanel, Badge } from '../../ministry-portal/components/MinistryUi'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { EMAIL_API } from '../lib/universityEmail'
import { operationLabels, printableCredentials, provisioningFailure } from '../lib/emailProvisioning'
import UniversityEmailReceipt from './UniversityEmailReceipt'

const action = 'inline-flex items-center justify-center py-2 px-4 rounded-[10px] bg-primary text-white text-[13px] font-bold disabled:opacity-50'
const secondary = 'inline-flex items-center justify-center py-2 px-4 rounded-[10px] border border-primary/20 bg-white text-primary-dark text-[13px] font-bold disabled:opacity-50'

export default function UniversityEmailProvisioning({ studentId, revision, draftDirty, draftPending, isCurrent, onSensitive, onPending, onDenied, onRefresh }) {
  const [state, setState] = useState(null), [error, setError] = useState(''), [loading, setLoading] = useState(true)
  const [credentials, setCredentials] = useState(null), [receipt, setReceipt] = useState(null), [busy, setBusy] = useState(false)
  const [confirm, setConfirm] = useState(false), [needsReview, setNeedsReview] = useState(false)
  const receiptElement = useRef(null), alive = useRef(true), writing = useRef(false), sequence = useRef(0)
  const api = `${EMAIL_API}/students/${studentId}/provisioning`
  const current = useCallback(() => alive.current && isCurrent(), [isCurrent])
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => { onSensitive(!!credentials); return () => onSensitive(false) }, [credentials, onSensitive])
  const load = useCallback(async () => {
    const seq = ++sequence.current; setLoading(true)
    try {
      const json = await apiRequest(api, { cache: 'no-store' })
      if (seq !== sequence.current || !current()) return false
      setState(json.data); setError(''); setNeedsReview(false); return true
    } catch (failure) {
      if (seq === sequence.current && current()) { onDenied(failure); setError(provisioningFailure(failure)); setNeedsReview(true) }
    } finally { if (seq === sequence.current && current()) setLoading(false) }
    return false
  }, [api, current, onDenied])
  useEffect(() => {
    const requestSequence = sequence, timer = setTimeout(load, 0)
    return () => { clearTimeout(timer); requestSequence.current++ }
  }, [load, revision])

  const run = async (path, payload, success) => {
    if (writing.current || draftPending || draftDirty || !current()) return
    writing.current = true; setBusy(true); onPending(true); setError('')
    try {
      const json = await apiRequest(`${api}/${path}`, { method: 'POST', cache: 'no-store', body: JSON.stringify(payload) })
      if (current()) success(json.data)
    } catch (failure) {
      if (current()) { onDenied(failure); setError(provisioningFailure(failure)); setNeedsReview(true) }
    } finally { writing.current = false; if (current()) { setBusy(false); onPending(false) } }
  }
  const prepare = kind => run(kind === 'create' ? 'password' : 'reissue', { revision }, data => {
    setCredentials(data); setReceipt(null); setNeedsReview(false); load(); onRefresh()
  })
  const execute = () => {
    if (!credentials || needsReview) return
    setConfirm(false)
    run('execute', { operation_id: credentials.operation_id, generation: credentials.generation,
      password: credentials.password, credential_proof: credentials.credential_proof, confirmed: true }, data => { setState(data); onRefresh() })
  }
  const download = async () => {
    if (writing.current || !printableCredentials(state, credentials) || !current()) return
    writing.current = true; setBusy(true); onPending(true); setError('')
    try {
      const json = await apiRequest(`${api}/receipt`, { method: 'POST', cache: 'no-store', body: JSON.stringify({ operation_id: credentials.operation_id, generation: credentials.generation }) })
      if (!current()) return
      flushSync(() => setReceipt(json.data))
      const { downloadEmailReceipt } = await import('../lib/emailReceiptPdf')
      await downloadEmailReceipt(receiptElement.current, current)
    } catch (failure) { if (current()) { onDenied(failure); setError('تعذر تنزيل PDF. بيانات الصفحة باقية؛ راجع الحالة ثم حاول التنزيل دون إعادة إنشاء البريد.') } }
    finally { writing.current = false; if (current()) { setBusy(false); onPending(false) } }
  }
  const mayCreate = canAccess(ACCESS.universityEmailCreate), mayReset = canAccess(ACCESS.universityEmailRecover)
  const mayReceipt = canAccess(ACCESS.universityEmailReceipt)
  const op = credentials && state?.operations?.find(item => item.operation_id === credentials.operation_id)
  const blocked = busy || loading || draftPending || draftDirty
  return <Section title="إنشاء البريد وإيصال PDF" subtitle="إنشاء مؤكد أولًا، ثم تنزيل الإيصال. التنزيل لا يسجل استلامًا أو تسليمًا.">
    {loading && !state && <StatePanel state="loading" />}
    {error && <Notice tone="warning">{error}</Notice>}
    {state?.enabled === false && <Notice tone="warning">إنشاء الصناديق معطّل في إعدادات الخادم إلى حين التحقق من توافق Mailcow.</Notice>}
    <button className={secondary} type="button" disabled={busy || loading || draftPending} onClick={load}>مراجعة الحالة الرسمية</button>
    {state?.operations?.map(item => <p key={item.operation_id} className="mt-3 text-[13px]"><Badge>{item.kind === 'reset' ? 'إعادة إصدار محدودة' : 'إنشاء صندوق'}</Badge> {operationLabels[item.status] || 'حالة غير معروفة'}
      {['preflight', 'in_progress', 'uncertain'].includes(item.status) && mayCreate && <button type="button" className={`${secondary} mr-2`} disabled={blocked} onClick={() => run('reconcile', { operation_id: item.operation_id }, data => { setState(data); onRefresh() })}>مصالحة للقراءة فقط</button>}
    </p>)}
    {!revision && <Notice>احفظ الاسم الإنكليزي والمسودة أولًا.</Notice>}
    {draftDirty && <Notice>احفظ مسودة الاسم أو ألغِ تغييراتها قبل بدء عملية البريد.</Notice>}
    {state?.enabled && revision > 0 && !needsReview && <div className="mt-4 flex flex-wrap gap-2">
      {mayCreate && mayReceipt && state.provisioning_status === 'draft' && !state.operations.some(item => !['prepared', 'failed'].includes(item.status)) && <button type="button" className={action} disabled={blocked} onClick={() => prepare('create')}>توليد كلمة مرور</button>}
      {mayReset && mayReceipt && state.provisioning_status === 'created' && !state.operations.some(item => ['preflight', 'in_progress', 'uncertain'].includes(item.status)) && <button type="button" className={secondary} disabled={blocked} onClick={() => {
        if (window.confirm('إعادة الإصدار تغيّر كلمة المرور الفعلية للصندوق وتبطل بيانات الدخول السابقة، بما فيها أي PDF سابق. هل تريد المتابعة؟')) prepare('reset')
      }}>إعادة إصدار كلمة أولية مفقودة</button>}
    </div>}
    {credentials && <div className="mt-4 rounded-[12px] border border-primary/20 p-4 space-y-3">
      <Notice>كلمة المرور في ذاكرة الصفحة فقط. مغادرة الصفحة تفقدها. الإيصال يحتوي بيانات سرية؛ احفظه وسلّمه بأمان.</Notice>
      <p className="text-[13px]">{printableCredentials(state, credentials) ? 'كلمة المرور الأولية للصندوق المؤكد' : 'كلمة مقترحة — لا تستخدمها قبل تأكيد نجاح العملية'}<span dir="ltr" className="block font-mono text-[18px] break-all">{credentials.password}</span></p>
      {op?.status === 'prepared' && <button className={action} type="button" disabled={blocked || needsReview} onClick={() => setConfirm(true)}>{credentials.kind === 'reset' ? 'تأكيد تغيير الكلمة على خادم البريد' : 'إنشاء الصندوق على خادم البريد'}</button>}
      {mayReceipt && printableCredentials(state, credentials) && <button className={action} type="button" disabled={blocked} onClick={download}>{busy ? 'جارٍ تنزيل الإيصال…' : 'تنزيل الإيصال PDF'}</button>}
      <button className={secondary} type="button" disabled={busy} onClick={() => { setCredentials(null); setReceipt(null); setConfirm(false) }}>إنهاء وإخفاء بيانات الدخول</button>
    </div>}
    {confirm && <ManualGradeDialog title={credentials.kind === 'reset' ? 'تأكيد إعادة إصدار محدودة' : 'تأكيد إنشاء صندوق الطالب'} onCancel={() => setConfirm(false)} onConfirm={execute} disabled={blocked} confirmLabel="تأكيد العملية">
      <p className="text-[13px]">سيُنفّذ طلب واحد للعنوان <span dir="ltr">{state.email_address}</span> بحصة 50 MiB. لن يعاد الطلب تلقائيًا إذا فُقد الرد.</p>
    </ManualGradeDialog>}
    {receipt && credentials && printableCredentials(state, credentials) && createPortal(<div aria-hidden="true" style={{ position: 'fixed', left: -10000, top: 0, pointerEvents: 'none' }}><div ref={receiptElement}><UniversityEmailReceipt receipt={receipt} password={credentials.password} /></div></div>, document.body)}
  </Section>
}
