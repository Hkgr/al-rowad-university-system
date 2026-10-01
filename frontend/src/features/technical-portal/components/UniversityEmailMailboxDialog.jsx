import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal, flushSync } from 'react-dom'
import { ACCESS, canAccess } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { InfoGrid, Notice, StatePanel } from '../../ministry-portal/components/MinistryUi'
import { EMAIL_API, previewAddress } from '../lib/universityEmail'
import { printableCredentials, provisioningFailure } from '../lib/emailProvisioning'
import UniversityEmailReceipt from './UniversityEmailReceipt'
import UniversityEmailProvisioning from './UniversityEmailProvisioning'

const secondary = 'inline-flex items-center justify-center py-2 px-4 rounded-[10px] border border-primary/20 bg-white text-primary-dark text-[13px] font-bold disabled:opacity-50'

/** Credentials exist only in this mounted student's dialog, never in browser storage. */
export default function UniversityEmailMailboxDialog({ data, externalPending, isCurrent, onDirty, onSensitive, onPending, onDenied, onRefresh, onClose }) {
  const student = data.student, api = `${EMAIL_API}/students/${student.student_id}`
  const [name, setName] = useState(data.draft?.english_first_name || '')
  const [state, setState] = useState(null), [credentials, setCredentials] = useState(null), [receipt, setReceipt] = useState(null)
  const [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useState('')
  const [reviewRequired, setReviewRequired] = useState(false), [advanced, setAdvanced] = useState(data.draft?.provisioning_status === 'created')
  const alive = useRef(true), writing = useRef(false), sequence = useRef(0), receiptElement = useRef(null)
  const current = useCallback(() => alive.current && isCurrent(), [isCurrent])
  useEffect(() => { alive.current = true; const requestSequence = sequence; return () => { alive.current = false; requestSequence.current++ } }, [])
  useEffect(() => { onSensitive(!!credentials); return () => onSensitive(false) }, [credentials, onSensitive])
  const load = useCallback(async () => {
    const seq = ++sequence.current; setLoading(true)
    try {
      const json = await apiRequest(`${api}/provisioning`, { cache: 'no-store' })
      if (!current() || sequence.current !== seq) return
      setState(json.data); setReviewRequired(false)
    } catch (failure) {
      if (current() && sequence.current === seq) { onDenied(failure); setError(provisioningFailure(failure)); setReviewRequired(true) }
    } finally { if (current() && sequence.current === seq) setLoading(false) }
  }, [api, current, onDenied])
  useEffect(() => { const timer = setTimeout(load, 0); return () => clearTimeout(timer) }, [load])

  const confirmed = state?.provisioning_status === 'created'
  const usable = canAccess(ACCESS.universityEmailReceipt) && printableCredentials(state, credentials)
  const previousAttempt = state?.operations?.some(op => op.status !== 'cancelled')
  const mayCreate = canAccess(ACCESS.universityEmailCreate) && canAccess(ACCESS.universityEmailManage) && canAccess(ACCESS.universityEmailReceipt)
  const preview = previewAddress(name, student.student_number, data.settings.domain)
  const blocked = busy || externalPending || loading
  const create = async () => {
    if (writing.current || blocked || !mayCreate || !preview || confirmed || previousAttempt || reviewRequired || !current()) return
    writing.current = true; setBusy(true); onPending(true); setError('')
    try {
      const json = await apiRequest(`${api}/create`, { method: 'POST', cache: 'no-store', body: JSON.stringify({ english_first_name: name, confirmed: true }) })
      if (!current()) return
      if (!printableCredentials(json.data, json.data.credentials)) throw new Error('لم يصل تأكيد صالح للإنشاء.')
      setState(json.data); setCredentials(json.data.credentials); onDirty(false); onRefresh()
    } catch (failure) {
      if (!current() || onDenied(failure)) return
      // Never retry a write. A lost response cannot authorize another create or recover its password.
      setCredentials(null); setReceipt(null); setError(provisioningFailure(failure)); setReviewRequired(true); onRefresh()
    } finally { writing.current = false; if (current()) { setBusy(false); onPending(false) } }
  }
  const download = async () => {
    if (writing.current || blocked || !usable || !current()) return
    writing.current = true; setBusy(true); onPending(true); setError('')
    try {
      const json = await apiRequest(`${api}/provisioning/receipt`, { method: 'POST', cache: 'no-store', body: JSON.stringify({ operation_id: credentials.operation_id, generation: credentials.generation }) })
      if (!current()) return
      flushSync(() => setReceipt(json.data))
      const { downloadEmailReceipt } = await import('../lib/emailReceiptPdf')
      await downloadEmailReceipt(receiptElement.current, current)
    } catch (failure) {
      if (current() && !onDenied(failure)) setError('تعذر تنزيل PDF؛ بيانات الدخول باقية في النافذة. لا تعِد إنشاء البريد.')
    } finally { writing.current = false; if (current()) { setBusy(false); onPending(false) } }
  }
  const copy = async value => {
    if (blocked || !usable || !current()) return
    try { await navigator.clipboard.writeText(value) }
    catch { if (current()) setError('تعذر النسخ؛ يمكنك تحديد النص ونسخه يدويًا.') }
  }
  const close = () => {
    if (writing.current || externalPending) return
    if (!credentials && name !== (data.draft?.english_first_name || '') && !window.confirm('هل تريد إلغاء الاسم المقترح وإغلاق النافذة؟')) return
    setCredentials(null); setReceipt(null); onSensitive(false); onDirty(false); onClose()
  }
  const review = async () => {
    setCredentials(null); setReceipt(null); setError('')
    await load(); onRefresh()
  }
  return <ManualGradeDialog title={confirmed ? 'إدارة البريد الجامعي' : 'إنشاء بريد جامعي'} busy={busy || externalPending}
    disabled={!confirmed && (blocked || !mayCreate || !preview || previousAttempt || reviewRequired || !state?.enabled)}
    onConfirm={confirmed ? close : create} onCancel={close} confirmLabel={confirmed ? 'إنهاء' : 'إنشاء البريد'}>
    <InfoGrid items={[[ 'الطالب', student.full_name ], ['الرقم الجامعي', student.student_number ], ['الكلية', student.college || 'غير محدد'], ['البرنامج', student.program || 'غير محدد']]} />
    {loading && <StatePanel state="loading" />}
    {error && <Notice tone="warning">{error}</Notice>}
    {state?.enabled === false && <Notice tone="warning">الإنشاء معطّل في إعدادات الخادم؛ لا يمكن تنفيذ كتابة على البريد.</Notice>}
    {!confirmed && <>
      <label htmlFor="english-first-name" className="block text-[13px] font-bold">الاسم الأول بالإنكليزي</label>
      <input id="english-first-name" value={name} onChange={e => { setName(e.target.value); onDirty(true) }} disabled={blocked || previousAttempt || reviewRequired || !mayCreate}
        maxLength={64} autoComplete="off" dir="ltr" className="w-full py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[14px] outline-none focus:border-primary" />
      <p className="text-[12px] text-text-light">أحرف إنكليزية فقط؛ يعيد الخادم التحقق من الاسم والرقم والعنوان.</p>
      <p dir="ltr" id="email-preview" className="rounded-[12px] border border-primary/15 bg-primary/5 p-3 font-bold break-all">{preview || 'أدخل اسمًا صالحًا لمعاينة البريد'}</p>
      <Notice>سيُنشأ صندوق الطالب بحصة 50 MiB بطلب واحد. لن تعاد الكتابة تلقائيًا عند فقدان الرد.</Notice>
    </>}
    {usable && <div className="space-y-3">
      <p className="font-bold text-primary-dark" role="status">تم إنشاء البريد الجامعي بنجاح</p>
      <p>البريد: <span dir="ltr" className="block break-all font-bold">{state.email_address}</span></p>
      <p>كلمة المرور الأولية: <span dir="ltr" className="block break-all font-mono text-[18px]">{credentials.password}</span></p>
      <Notice>بيانات الدخول في ذاكرة هذه النافذة فقط. إغلاقها أو إنهاء الجلسة يفقد كلمة المرور؛ لا تشاركها مع الآخرين.</Notice>
      <div className="flex flex-wrap gap-2">
        <button type="button" className={secondary} disabled={blocked} onClick={() => copy(state.email_address)}>نسخ البريد</button>
        <button type="button" className={secondary} disabled={blocked} onClick={() => copy(credentials.password)}>نسخ كلمة المرور</button>
        <button type="button" className={secondary} disabled={blocked} onClick={download}>تنزيل بيانات الدخول PDF</button>
      </div>
    </div>}
    {!usable && (reviewRequired || previousAttempt || confirmed) && <>
      {reviewRequired && <Notice tone="warning">تعذر تأكيد نتيجة الإنشاء؛ راجع الحالة. لن يُرسل طلب إنشاء ثانٍ تلقائيًا.</Notice>}
      <button type="button" className={secondary} disabled={blocked} onClick={review}>مراجعة الحالة</button>
      {confirmed && <Notice>البريد مؤكد. كلمة المرور السابقة غير قابلة للاسترجاع؛ إعادة تعيينها إجراء مستقل.</Notice>}
      {!confirmed && previousAttempt && <Notice tone="warning">توجد عملية سابقة. راجعها أو ألغِ عملية لم تبدأ الكتابة قبل تصحيح الاسم أو الإنشاء مجددًا.</Notice>}
      <button type="button" className={secondary} disabled={blocked} onClick={() => setAdvanced(value => !value)}>{confirmed ? 'عرض إدارة البريد' : 'تفاصيل متقدمة ومراجعة العملية'}</button>
    </>}
    {advanced && <UniversityEmailProvisioning studentId={student.student_id} studentName={student.full_name} revision={data.draft?.revision || 0}
      simple draftDirty={false} draftPending={false} isCurrent={current} onSensitive={onSensitive} onPending={onPending} onDenied={onDenied} onRefresh={() => { load(); onRefresh() }} />}
    {receipt && usable && createPortal(<div aria-hidden="true" style={{ position: 'fixed', left: -10000, top: 0, pointerEvents: 'none' }}><div ref={receiptElement}><UniversityEmailReceipt receipt={receipt} password={credentials.password} /></div></div>, document.body)}
  </ManualGradeDialog>
}
