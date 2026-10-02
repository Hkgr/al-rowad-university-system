import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal, flushSync } from 'react-dom'
import { FaCopy, FaDownload, FaKey, FaPause, FaPlay, FaSyncAlt, FaTrashAlt } from 'react-icons/fa'
import { ACCESS, canAccess } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import { Notice, StatePanel, Badge } from '../../ministry-portal/components/MinistryUi'
import { EMAIL_API, previewAddress } from '../lib/universityEmail'
import { printableCredentials, provisioningFailure } from '../lib/emailProvisioning'
import { creationMode, loadCreationState, sendCreation, unresolvedCreation } from '../lib/emailCreation'
import { accountPayload, deletionAllowed, deletionConfirmation, pendingAccountCheck } from '../lib/emailAccount'
import { downloadCurrentCredentialReceipt, runMailboxAction } from '../lib/emailCredentialReceipt'
import { lostInitialCredentials, pendingCreationReconciliation, reconciliationUnresolved, reconciliationWaiting, startCreationReconciliation } from '../lib/emailReconciliation'
import UniversityEmailDialog from './UniversityEmailDialog'
import UniversityEmailReceipt from './UniversityEmailReceipt'

const secondary = 'inline-flex items-center justify-center gap-2 rounded-[10px] border border-primary/20 bg-white px-4 py-2.5 text-[13px] font-bold text-primary-dark disabled:opacity-50'
const primary = 'inline-flex items-center justify-center gap-2 rounded-[10px] bg-primary px-5 py-2.5 text-[13px] font-bold text-white hover:bg-primary-dark disabled:opacity-50'
const field = 'w-full rounded-[10px] border-[1.5px] border-primary/20 px-3 py-2.5 text-[14px] outline-none focus:border-primary disabled:opacity-50'
const permission = kind => ({ create: ACCESS.universityEmailCreate, reset: ACCESS.universityEmailRecover, password_reset: ACCESS.universityEmailReset,
  activate: ACCESS.universityEmailActivate, suspend: ACCESS.universityEmailSuspend, delete: ACCESS.universityEmailDelete, link: ACCESS.universityEmailLink })[kind]

/** One student's RAM-only credentials. No secret persistence or automatic write retries. */
export default function UniversityEmailMailboxDialog({ data, externalPending, isCurrent, onDirty, onSensitive, onPending, onDenied, onRefresh, onClose }) {
  const student = data.student, api = `${EMAIL_API}/students/${student.student_id}`
  const [name, setName] = useState(data.draft?.english_first_name || '')
  const [state, setState] = useState(null), [credentials, setCredentials] = useState(null), [receipt, setReceipt] = useState(null)
  const [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useState('')
  const [receiptStatus, setReceiptStatus] = useState('idle'), [receiptError, setReceiptError] = useState('')
  const [autoChecking, setAutoChecking] = useState(false), [exhausted, setExhausted] = useState(false), [recoveryEpoch, setRecoveryEpoch] = useState(0)
  const [reviewRequired, setReviewRequired] = useState(false), [technical, setTechnical] = useState(false)
  const [action, setAction] = useState(null), [reason, setReason] = useState(''), [confirmation, setConfirmation] = useState('')
  const [recreateForm, setRecreateForm] = useState(false)
  const [linkAddress, setLinkAddress] = useState(''), [linkPreview, setLinkPreview] = useState(null), [attested, setAttested] = useState(false)
  const alive = useRef(true), writing = useRef(false), sequence = useRef(0), receiptElement = useRef(null)
  const current = useCallback(() => alive.current && isCurrent(), [isCurrent])
  const recoveryState = useRef(null)
  const recoveryRefresh = useRef(onRefresh)
  const mayCreate = canAccess(ACCESS.universityEmailCreate) && canAccess(ACCESS.universityEmailManage) && canAccess(ACCESS.universityEmailReceipt)
  useEffect(() => { alive.current = true; const seq = sequence; return () => { alive.current = false; seq.current++ } }, [])
  useEffect(() => { onSensitive(!!credentials); return () => onSensitive(false) }, [credentials, onSensitive])
  const load = useCallback(async () => {
    const seq = ++sequence.current; setLoading(true)
    try {
      let result = await loadCreationState(api, apiRequest, () => current() && sequence.current === seq, mayCreate)
      if (!current() || sequence.current !== seq) return
      const pending = pendingAccountCheck(result)
      if (pending && pending.kind !== 'create' && canAccess(permission(pending.kind))) {
        try { result = (await apiRequest(`${api}/provisioning/reconcile`, { method: 'POST', cache: 'no-store', body: JSON.stringify({ operation_id: pending.operation_id }) })).data }
        catch (failure) {
          if (failure.status !== 409) throw failure
          result = (await apiRequest(`${api}/provisioning`, { cache: 'no-store' })).data
        }
      }
      if (!current() || sequence.current !== seq) return
      if (result.provisioning_status === 'created' && !result.pending_operation) {
        result = (await apiRequest(`${api}/provisioning/refresh-account`, { method: 'POST', cache: 'no-store', body: '{}' })).data
      }
      if (!current() || sequence.current !== seq) return
      setState(result); setReviewRequired(false)
      if (!pendingCreationReconciliation(result)) setAutoChecking(false)
      if (pendingCreationReconciliation(result) || result.provisioning_status === 'created') setError('')
    } catch (failure) {
      if (current() && sequence.current === seq && !onDenied(failure)) { setError(provisioningFailure(failure)); setReviewRequired(true); setAutoChecking(false) }
    } finally { if (current() && sequence.current === seq) setLoading(false) }
  }, [api, current, onDenied, mayCreate])
  useEffect(() => { const timer = setTimeout(load, 0); return () => clearTimeout(timer) }, [load])

  useEffect(() => { recoveryState.current = state; recoveryRefresh.current = onRefresh }, [state, onRefresh])
  const recoveryOperation = mayCreate && pendingCreationReconciliation(state) ? state.pending_operation.operation_id : null
  useEffect(() => {
    if (!recoveryOperation) return
    const stop = startCreationReconciliation({ api, initialState: recoveryState.current, request: apiRequest, isCurrent: current,
      onChecking: setAutoChecking, onExhausted: () => setExhausted(true), onError: onDenied,
      onState: async next => {
        setState(next); setReviewRequired(false); setError('')
        if (next.provisioning_status === 'created') { setAutoChecking(false); await load(); if (current()) recoveryRefresh.current() }
      },
    })
    return stop
  }, [api, recoveryOperation, recoveryEpoch, current, load, onDenied])

  const mode = creationMode(state, reviewRequired), confirmed = mode === 'existing', deleted = mode === 'deleted'
  const usable = canAccess(ACCESS.universityEmailReceipt) && printableCredentials(state, credentials)
  const preview = previewAddress(name, student.student_number, data.settings.domain)
  const blocked = busy || externalPending || loading
  const requiresCheck = ['verify', 'waiting'].includes(mode) || !!recoveryOperation || !!state?.pending_operation && confirmed
  const waiting = !exhausted && (!!recoveryOperation || autoChecking)
  const lostCredentials = confirmed && !usable && !state?.credential_operation_id && state?.linkage_origin !== 'linked'
  const accountVerified = confirmed && state.check?.owned && state.check.status === 'verified' && !state.pending_operation
  const mayDelete = deletionAllowed(state, canAccess(ACCESS.universityEmailDelete))
  const receiptFailure = (failure, credential) => {
    if (!current() || onDenied(failure)) return
    setReceiptStatus('failed')
    setReceiptError(credential.kind === 'password_reset' ? 'تمت إعادة تعيين كلمة المرور بنجاح، لكن تعذر تنزيل الإيصال.' : 'تم إنشاء البريد بنجاح، لكن تعذر تنزيل الإيصال.')
  }
  const downloadReceipt = async (confirmedState, credential) => {
    if (!current()) return false
    setReceiptStatus('preparing'); setReceiptError('')
    const downloaded = await downloadCurrentCredentialReceipt({ api, studentId: student.student_id,
      state: confirmedState, credentials: credential, request: apiRequest, isCurrent: current,
      render: metadata => flushSync(() => setReceipt(metadata)),
      download: async isStillCurrent => {
        const { downloadEmailReceipt } = await import('../lib/emailReceiptPdf')
        return downloadEmailReceipt(receiptElement.current, isStillCurrent)
      },
    })
    if (downloaded && current()) setReceiptStatus('downloaded')
    return downloaded
  }
  const runWrite = (task, returnsCredentials = false) => runMailboxAction({
    task, returnsCredentials, inFlight: writing, blocked, isCurrent: current,
    onStart: () => {
      setBusy(true); onPending(true); setError(''); setCredentials(null); setReceipt(null)
      setReceiptStatus('idle'); setReceiptError('')
      setExhausted(false)
    },
    onConfirmed: async (confirmedState, credential) => {
      setState(confirmedState); setCredentials(credential)
      setAction(null); setReason(''); setConfirmation(''); setRecreateForm(false); setTechnical(false); onDirty(false); onRefresh()
      if (confirmedState.provisioning_status === 'created' && !returnsCredentials) await load()
    },
    downloadReceipt, onReceiptFailure: receiptFailure,
    onWriteFailure: async failure => {
      if (onDenied(failure)) return
      if (returnsCredentials && !action && ![422, 503].includes(failure.status)) { setError(''); setAutoChecking(true) }
      else setError(provisioningFailure(failure))
      setReviewRequired(true)
      // A lost response is not rollback evidence. Only canonical read-only checking follows.
      await load(); onRefresh()
    },
    onFinish: () => { setBusy(false); onPending(false) },
  })
  const create = () => {
    if (!mayCreate || !preview || !['ready', 'retry', 'deleted'].includes(mode) || mode !== 'retry' && state?.pending_operation) return
    runWrite(() => sendCreation(api, state, name, apiRequest), true)
  }
  const executeAction = () => {
    if (!action || !reason.trim() || !canAccess(permission(action))) return
    if (action === 'link') {
      if (state?.provisioning_status !== 'draft' || state.pending_operation || !attested || !linkPreview || linkPreview.email_address !== linkAddress) return
      runWrite(() => apiRequest(`${api}/account-action`, { method: 'POST', cache: 'no-store', body: JSON.stringify({
        kind: 'link', revision: state.revision, confirmed: true, reason: reason.trim(), email_address: linkAddress,
        preview_proof: linkPreview.preview_proof, ownership_confirmed: attested,
      }) }))
      return
    }
    if (!accountVerified) return
    if (action === 'delete' && (!mayDelete || !deletionConfirmation(confirmation, student.student_number))) return
    const path = action === 'delete' ? 'delete-mailbox' : action === 'password_reset' ? 'reset-password-now' : 'account-action'
    runWrite(() => apiRequest(`${api}/${path}`, { method: 'POST', cache: 'no-store', body: JSON.stringify(accountPayload(action, state, reason, confirmation)) }), action === 'password_reset')
  }
  const download = async () => {
    if (writing.current || blocked || !usable || !current()) return
    writing.current = true; setBusy(true); onPending(true)
    try {
      await downloadReceipt(state, credentials)
    } catch (failure) { receiptFailure(failure, credentials) }
    finally { writing.current = false; if (current()) { setBusy(false); onPending(false) } }
  }
  const copy = async value => { try { if (!blocked && usable && current()) await navigator.clipboard.writeText(value) } catch { if (current()) setError('تعذر النسخ؛ يمكنك تحديد النص ونسخه يدويًا.') } }
  const close = () => {
    if (writing.current || externalPending) return
    if (!confirmed && !deleted && name !== (data.draft?.english_first_name || '') && !window.confirm('هل تريد إلغاء الاسم المقترح وإغلاق النافذة؟')) return
    setCredentials(null); setReceipt(null); onSensitive(false); onDirty(false); onClose()
  }
  const review = async () => {
    if (blocked || writing.current) return
    setError(''); setExhausted(false); setRecoveryEpoch(value => value + 1); await load(); onRefresh()
  }
  const startAction = kind => { if (blocked || !accountVerified) return; setAction(kind); setReason(''); setConfirmation(''); setError('') }
  const previewLink = async () => {
    if (blocked || !current() || !canAccess(ACCESS.universityEmailLink)) return
    const seq = ++sequence.current; setBusy(true); onPending(true); setError(''); setLinkPreview(null); setAttested(false)
    try {
      const json = await apiRequest(`${api}/provisioning/preview-link`, { method: 'POST', cache: 'no-store', body: JSON.stringify({ email_address: linkAddress }) })
      if (current() && sequence.current === seq) setLinkPreview(json.data)
    } catch (failure) { if (current() && !onDenied(failure)) setError(provisioningFailure(failure)) }
    finally { if (current() && sequence.current === seq) { setBusy(false); onPending(false) } }
  }
  const cancelSafe = () => {
    const op = state?.pending_operation
    if (!op || op.write_started_at || !['prepared', 'preflight', 'failed', 'conflict'].includes(op.status) || !canAccess(permission(op.kind))) return
    runWrite(() => apiRequest(`${api}/provisioning/cancel`, { method: 'POST', body: JSON.stringify({ operation_id: op.operation_id, generation: op.generation, confirmed: true }) }))
  }
  const showForm = !loading && !action && !confirmed && !requiresCheck && !waiting && (!deleted || recreateForm)
  return <UniversityEmailDialog title={action === 'delete' ? 'حذف البريد الجامعي' : confirmed ? 'إدارة البريد الجامعي' : 'إنشاء بريد جامعي'} subtitle={student.full_name} busy={busy || externalPending} onClose={close} footer={<>
    {action ? <><button type="button" className={action === 'delete' ? 'rounded-[10px] bg-red-600 px-5 py-2.5 text-[13px] font-bold text-white disabled:opacity-50' : primary} disabled={blocked || !reason.trim() || action === 'delete' && !deletionConfirmation(confirmation, student.student_number) || action === 'link' && (!attested || !linkPreview || linkPreview.email_address !== linkAddress)} onClick={executeAction}>{busy ? action === 'password_reset' ? 'جارٍ إعادة تعيين كلمة المرور…' : 'جارٍ التنفيذ…' : action === 'delete' ? 'حذف البريد نهائيًا' : action === 'password_reset' ? 'تأكيد إعادة التعيين' : 'تأكيد الإجراء'}</button><button type="button" className={secondary} disabled={blocked} onClick={() => setAction(null)}>إلغاء</button></>
      : <>{showForm && <button type="button" className={primary} disabled={blocked || !mayCreate || !preview || !state?.enabled || deleted && !state?.deletion_schema_ready} onClick={create}>{busy ? 'جارٍ إنشاء البريد…' : deleted ? 'إنشاء البريد الجديد' : mode === 'retry' ? 'إعادة المحاولة' : 'إنشاء البريد'}</button>}
        {deleted && !recreateForm && <button type="button" className={primary} disabled={blocked || !mayCreate || !!state.pending_operation} onClick={() => setRecreateForm(true)}>إنشاء بريد جديد</button>}
        {requiresCheck && !waiting && !loading && <button type="button" className={primary} disabled={blocked} onClick={review}><FaSyncAlt />{exhausted ? 'إعادة التحقق' : 'التحقق مرة أخرى'}</button>}
        {!waiting && <button type="button" className={secondary} disabled={busy || externalPending} onClick={close}>{usable || confirmed || deleted ? 'إنهاء' : 'إلغاء'}</button>}</>}
  </>}>
    <div className="grid grid-cols-1 gap-x-5 gap-y-2 rounded-[12px] border border-primary/10 bg-primary/[0.03] p-4 sm:grid-cols-2">
      {[[ 'الطالب', student.full_name ], ['الرقم الجامعي', student.student_number ], ['الكلية', student.college || 'غير محدد'], ['البرنامج', student.program || 'غير محدد']].map(([label, value]) => <div key={label}><span className="block text-[11px] text-text-light">{label}</span><span className="text-[13px] font-bold text-text-dark">{value}</span></div>)}
    </div>
    {loading && !waiting && <StatePanel state="loading" />}
    {error && <Notice tone="warning">{error}</Notice>}
    {showForm && <section className="space-y-2">
      <h3 className="font-bold text-text-dark">بيانات البريد</h3><label htmlFor="english-first-name" className="block text-[12px] font-bold">الاسم الأول بالإنكليزي</label>
      <input id="english-first-name" value={name} onChange={e => { setName(e.target.value); onDirty(true) }} disabled={blocked || !mayCreate} maxLength={64} autoComplete="off" dir="ltr" className={field} />
      <p className="text-[11px] text-text-light">سيتم إنشاء البريد فعالًا مع كلمة مرور أولية تلقائية.</p>
      <div className="rounded-[12px] bg-primary/5 p-3"><span className="text-[11px] text-text-light">البريد الذي سيتم إنشاؤه</span><p dir="ltr" id="email-preview" className="break-all font-mono font-bold text-primary-dark">{preview || 'أدخل اسمًا صالحًا لمعاينة البريد'}</p></div>
      {state?.enabled === false && <Notice tone="warning">إنشاء البريد غير مفعّل على الخادم بعد.</Notice>}
      {state?.provisioning_status === 'draft' && !state.pending_operation && canAccess(ACCESS.universityEmailLink) && <button type="button" className={secondary} disabled={blocked} onClick={() => { setAction('link'); setLinkAddress(state.email_address || ''); setLinkPreview(null); setAttested(false); setReason(''); setError('') }}>ربط بريد سابق بعد التحقق من ملكيته</button>}
    </section>}
    {usable && <section className="space-y-3" aria-label="بيانات الدخول">
      <p className="font-bold text-primary-dark" role="status">{credentials.kind === 'password_reset' ? 'تمت إعادة تعيين كلمة المرور بنجاح' : 'تم إنشاء البريد الجامعي بنجاح'}</p>
      <div className="space-y-2 rounded-[12px] bg-primary/5 p-4"><p>البريد الجامعي</p><p dir="ltr" className="break-all font-mono font-bold">{state.email_address}</p><p>{credentials.kind === 'password_reset' ? 'كلمة المرور الجديدة' : 'كلمة المرور الأولية'}</p><p dir="ltr" className="break-all font-mono text-[18px]">{credentials.password}</p></div>
      <p className="text-[12px] text-text-light">بيانات الدخول مؤقتة؛ لا يمكن استعادة كلمة المرور بعد إغلاق النافذة. حافظ على سريتها.</p>
      {receiptStatus === 'preparing' && <p className="text-[12px] text-text-light" role="status">جارٍ تجهيز الإيصال…</p>}
      {receiptStatus === 'downloaded' && <p className="text-[12px] text-primary-dark" role="status">تم تنزيل الإيصال</p>}
      {receiptError && <Notice tone="warning">{receiptError} بيانات الدخول باقية في النافذة؛ أعد التنزيل فقط.</Notice>}
      <div className="flex flex-wrap gap-2"><button type="button" className={secondary} disabled={blocked} onClick={() => copy(state.email_address)}><FaCopy />نسخ البريد</button><button type="button" className={secondary} disabled={blocked} onClick={() => copy(credentials.password)}><FaCopy />نسخ كلمة المرور</button>{['downloaded', 'failed'].includes(receiptStatus) && <button type="button" className={secondary} disabled={blocked} onClick={download}><FaDownload />إعادة تنزيل الإيصال</button>}</div>
    </section>}
    {deleted && !recreateForm && <section className="space-y-2"><p className="font-bold text-primary-dark">تم حذف البريد الجامعي</p><p dir="ltr" className="break-all font-mono text-text-light">{state.email_address}</p><p>وقت الحذف: <span dir="ltr">{state.deleted_at}</span></p><p>المسؤول: {state.deleted_by}</p></section>}
    {waiting && <div className="flex items-center gap-2 rounded-[12px] bg-primary/5 p-4 text-[13px] text-primary-dark" role="status"><FaSyncAlt className="animate-spin" aria-hidden="true" />{reconciliationWaiting}</div>}
    {requiresCheck && !waiting && !loading && <><Notice tone="warning">{exhausted ? reconciliationUnresolved : unresolvedCreation}</Notice><button type="button" className={secondary} disabled={blocked} onClick={() => setTechnical(value => !value)}>تفاصيل تقنية</button>{technical && <div className="space-y-2 text-[12px]"><p>تعذر حسم حالة البريد. لم تُرسل كتابة إضافية.</p>{state?.pending_operation && !state.pending_operation.write_started_at && canAccess(permission(state.pending_operation.kind)) && <button type="button" className={secondary} disabled={blocked} onClick={cancelSafe}>إلغاء المحاولة الآمنة</button>}</div>}</>}
    {confirmed && !usable && !requiresCheck && !action && <section className="space-y-4">
      {lostCredentials && <Notice>{lostInitialCredentials}</Notice>}
      <div className="flex flex-wrap items-center justify-between gap-2"><p dir="ltr" className="break-all font-mono font-bold text-primary-dark">{state.email_address}</p><Badge>{state.remote_snapshot?.exists ? state.remote_snapshot.active ? 'فعال' : 'موقوف' : 'يحتاج تحقق'}</Badge></div>
      <dl className="grid grid-cols-1 gap-2 text-[12px] sm:grid-cols-3"><div><dt className="text-text-light">الحصة</dt><dd>{state.remote_snapshot?.quota_bytes != null ? `${Math.round(state.remote_snapshot.quota_bytes / 1048576)} MiB` : 'غير متاح'}</dd></div><div><dt className="text-text-light">الاستخدام</dt><dd>{state.remote_snapshot?.used_bytes != null ? `${(state.remote_snapshot.used_bytes / 1048576).toFixed(1)} MiB` : 'غير متاح'}</dd></div><div><dt className="text-text-light">آخر تحقق</dt><dd dir="ltr">{state.remote_checked_at || 'غير متاح'}</dd></div></dl>
      <h3 className="font-bold text-text-dark">إجراءات الحساب</h3><div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button type="button" className={secondary} disabled={blocked} onClick={review}><FaSyncAlt />تحديث معلومات البريد</button>
        {canAccess(ACCESS.universityEmailReset) && canAccess(ACCESS.universityEmailReceipt) && <button type="button" className={secondary} disabled={blocked || !accountVerified} onClick={() => startAction('password_reset')}><FaKey />{lostCredentials ? 'إصدار كلمة مرور جديدة' : 'إعادة تعيين كلمة المرور'}</button>}
        {state.remote_snapshot?.active && canAccess(ACCESS.universityEmailSuspend) && <button type="button" className={secondary} disabled={blocked || !accountVerified} onClick={() => startAction('suspend')}><FaPause />إيقاف الحساب</button>}
        {state.remote_snapshot?.active === false && canAccess(ACCESS.universityEmailActivate) && <button type="button" className={secondary} disabled={blocked || !accountVerified} onClick={() => startAction('activate')}><FaPlay />تفعيل الحساب</button>}
      </div>
      {!accountVerified && <Notice tone="warning">لم تُؤكد حالة الصندوق وملكيته. الإجراءات التي تغيّره غير متاحة حتى ينجح التحقق.</Notice>}
      {mayDelete && <section className="space-y-2 border-t border-red-200/60 pt-4"><h3 className="text-[13px] font-bold text-text-dark">إجراءات حساسة</h3><button type="button" disabled={blocked} onClick={() => startAction('delete')} className="inline-flex items-center gap-2 rounded-[10px] border border-red-200 bg-red-50 px-4 py-2.5 font-bold text-red-700 disabled:opacity-50"><FaTrashAlt />حذف البريد</button></section>}
    </section>}
    {action && <section className="space-y-3" aria-label="تأكيد الإجراء">
      <p className="font-bold text-text-dark">{action === 'delete' ? 'حذف البريد الجامعي' : action === 'link' ? 'ربط بريد سابق' : action === 'password_reset' ? 'إعادة تعيين كلمة المرور' : action === 'activate' ? 'تفعيل الحساب' : 'إيقاف الحساب'}</p>
      <p dir="ltr" className="break-all font-mono">{state.email_address}</p>
      {action === 'delete' ? <><Notice tone="warning">سيتم حذف صندوق البريد من خادم البريد. هذا الإجراء قد يؤدي إلى فقدان الرسائل الموجودة داخل الصندوق ولا يمكن التراجع عنه من النظام.</Notice><label htmlFor="delete-confirmation" className="block font-bold">للتأكيد، اكتب الرقم الجامعي {student.student_number}</label><input id="delete-confirmation" dir="ltr" autoComplete="off" className={field} value={confirmation} disabled={blocked} onChange={e => setConfirmation(e.target.value)} /></> : action === 'link' ? <>
        <label htmlFor="link-email" className="block font-bold">عنوان البريد السابق</label><input id="link-email" type="email" dir="ltr" className={field} value={linkAddress} disabled={blocked} onChange={e => { setLinkAddress(e.target.value); setLinkPreview(null); setAttested(false) }} />
        <button type="button" className={secondary} disabled={blocked || !linkAddress} onClick={previewLink}>التحقق من الصندوق</button>
        {linkPreview && <><Notice tone="warning">وجود الصندوق لا يثبت ملكية الطالب. الربط لا يغير كلمة المرور أو الحصة أو التفعيل.</Notice><label className="flex gap-2"><input type="checkbox" checked={attested} disabled={blocked} onChange={e => setAttested(e.target.checked)} />تحققت من ملكية الطالب لهذا البريد وأوثّق المرجع في السبب.</label></>}
      </> : <p>سيُنفذ الإجراء بعد التأكيد فقط. تغيير كلمة المرور يبطل كلمة المرور السابقة.</p>}
      <label htmlFor="email-action-reason" className="block font-bold">سبب الإجراء</label><textarea id="email-action-reason" className={field} maxLength={500} value={reason} disabled={blocked} onChange={e => setReason(e.target.value)} />
    </section>}
    {receipt && usable && createPortal(<div aria-hidden="true" style={{ position: 'fixed', left: -10000, top: 0, pointerEvents: 'none' }}><div ref={receiptElement}><UniversityEmailReceipt receipt={receipt} password={credentials.password} /></div></div>, document.body)}
  </UniversityEmailDialog>
}
