import { useCallback, useEffect, useRef, useState } from 'react'
import { createPortal, flushSync } from 'react-dom'
import { ACCESS, canAccess } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import { Section, Notice, StatePanel, Badge, InfoGrid } from '../../ministry-portal/components/MinistryUi'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { EMAIL_API } from '../lib/universityEmail'
import { canCancelOperation, credentialVisible, kindLabels, operationLabels, printableCredentials, provisioningFailure, usageLabel } from '../lib/emailProvisioning'
import UniversityEmailReceipt from './UniversityEmailReceipt'

const action = 'inline-flex items-center justify-center py-2 px-4 rounded-[10px] bg-primary text-white text-[13px] font-bold disabled:opacity-50'
const secondary = 'inline-flex items-center justify-center py-2 px-4 rounded-[10px] border border-primary/20 bg-white text-primary-dark text-[13px] font-bold disabled:opacity-50'

export default function UniversityEmailProvisioning({ studentId, studentName, revision, draftDirty, draftPending, isCurrent, onSensitive, onPending, onDenied, onRefresh, simple = false }) {
  const [state, setState] = useState(null), [error, setError] = useState(''), [loading, setLoading] = useState(true)
  const [credentials, setCredentials] = useState(null), [receipt, setReceipt] = useState(null), [busy, setBusy] = useState(false)
  const [confirm, setConfirm] = useState(false), [needsReview, setNeedsReview] = useState(false)
  const [cancelling, setCancelling] = useState(null)
  const [accountAction, setAccountAction] = useState(null), [reason, setReason] = useState(''), [address, setAddress] = useState('')
  const [linkPreview, setLinkPreview] = useState(null), [attested, setAttested] = useState(false), [executingAccount, setExecutingAccount] = useState(null)
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
  const prepare = kind => {
    setCredentials(null); setReceipt(null); setConfirm(false)
    run(kind === 'create' ? 'password' : 'reissue', { revision }, data => {
      setCredentials(data); setNeedsReview(false); load(); onRefresh()
    })
  }
  const openAccountAction = kind => {
    if (writing.current || draftPending || draftDirty || !current()) return
    setCredentials(null); setReceipt(null); setConfirm(false); setReason(''); setAddress(''); setLinkPreview(null); setAttested(false); setAccountAction(kind)
  }
  const prepareAccount = () => {
    if (!reason.trim() || (accountAction === 'link' && (!linkPreview || !attested || linkPreview.email_address !== address))) return
    if (accountAction === 'password_reset') {
      run('reset-password', { revision, reason }, data => { setCredentials(data); setAccountAction(null); load(); onRefresh() })
    } else {
      run('prepare-account', { kind: accountAction, revision, reason, confirmed: true,
        ...(accountAction === 'link' ? { email_address: address, preview_proof: linkPreview.preview_proof, ownership_confirmed: attested } : {}) }, data => { setState(data); setAccountAction(null); onRefresh() })
    }
  }
  const execute = () => {
    if (!credentials || needsReview) return
    setConfirm(false)
    run('execute', { operation_id: credentials.operation_id, generation: credentials.generation,
      password: credentials.password, credential_proof: credentials.credential_proof, confirmed: true }, data => { setState(data); onRefresh() })
  }
  const cancelOperation = () => {
    if (!cancelling) return
    const operation = cancelling
    setCancelling(null)
    // Clear secrets even if the response is lost: never re-use potentially cancelled credentials.
    setCredentials(null); setReceipt(null); setConfirm(false)
    run('cancel', { operation_id: operation.operation_id, generation: operation.generation, confirmed: true }, data => {
      setState(data); setNeedsReview(false); onRefresh()
    })
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
  const mayGeneralReset = canAccess(ACCESS.universityEmailReset), maySuspend = canAccess(ACCESS.universityEmailSuspend)
  const mayActivate = canAccess(ACCESS.universityEmailActivate), mayLink = canAccess(ACCESS.universityEmailLink)
  const authority = { mayCreate, mayReset, mayGeneralReset, maySuspend, mayActivate, mayLink }
  const permits = { create: mayCreate, reset: mayReset, password_reset: mayGeneralReset, suspend: maySuspend, activate: mayActivate, link: mayLink }
  const op = credentials && state?.operations?.find(item => item.operation_id === credentials.operation_id)
  const blocked = busy || loading || draftPending || draftDirty
  return <Section title="إدارة البريد وإيصال PDF" subtitle="عمليات فردية بصلاحيات مستقلة. التنزيل لا يسجل استلامًا أو تسليمًا.">
    {loading && !state && <StatePanel state="loading" />}
    {error && <Notice tone="warning">{error}</Notice>}
    {state?.enabled === false && <Notice tone="warning">إنشاء الصناديق معطّل في إعدادات الخادم إلى حين التحقق من توافق Mailcow.</Notice>}
    <button className={secondary} type="button" disabled={busy || loading || draftPending} onClick={load}>مراجعة الحالة الرسمية</button>
    {state?.account_schema_ready === false && <Notice tone="warning">إدارة الحسابات غير جاهزة؛ يلزم تطبيق migration المرحلة الثالثة. لا يعني ذلك أن الصندوق غير موجود.</Notice>}
    {state?.account_schema_ready && revision > 0 && <div className="mt-4 space-y-3">
      <InfoGrid items={[[ 'العنوان المحلي', state.email_address ], ['الربط', state.provisioning_status === 'created' ? (state.linkage_origin === 'linked' ? 'حساب سابق مرتبط ومؤكد' : 'حساب منشأ ومؤكد') : 'مسودة — ليست إثبات ملكية' ],
        ['حالة آخر تحقق', state.remote_snapshot ? (state.remote_snapshot.exists ? (state.remote_snapshot.active ? 'فعال' : 'موقوف') : 'الصندوق غير موجود في آخر تحقق') : 'لم يتم التحقق' ],
        ['الاستهلاك', usageLabel(state.remote_snapshot?.used_bytes)], ['الحصة', usageLabel(state.remote_snapshot?.quota_bytes)],
        ['نسبة الاستهلاك', state.remote_snapshot?.usage_percent == null ? 'غير متاح' : `${state.remote_snapshot.usage_percent}%`], ['آخر تحقق ناجح', state.remote_checked_at || 'لم يتم التحقق' ]]} />
      {state.check?.status === 'unavailable' && <Notice tone="warning">تعذر الاتصال؛ القيم المعروضة من آخر تحقق ناجح وليست إثباتًا للحالة الحالية.</Notice>}
      <button type="button" className={secondary} disabled={blocked} onClick={() => run('refresh-account', {}, setState)}>تحديث معلومات الصندوق من Mailcow</button>
      {state.enabled && !needsReview && !state.operations.some(item => ['prepared', 'preflight', 'in_progress', 'uncertain'].includes(item.status)) && <div className="flex flex-wrap gap-2">
        {state.provisioning_status === 'created' && <>
          {mayGeneralReset && mayReceipt && <button className={secondary} type="button" disabled={blocked} onClick={() => openAccountAction('password_reset')}>إعادة تعيين كلمة المرور</button>}
          {maySuspend && <button className={secondary} type="button" disabled={blocked} onClick={() => openAccountAction('suspend')}>إيقاف الحساب</button>}
          {mayActivate && <button className={secondary} type="button" disabled={blocked} onClick={() => openAccountAction('activate')}>تفعيل الحساب</button>}
        </>}
        {state.provisioning_status === 'draft' && mayLink && <button className={secondary} type="button" disabled={blocked} onClick={() => openAccountAction('link')}>التحقق وربط صندوق سابق</button>}
      </div>}
    </div>}
    <details open={state?.operations?.some(item => ['preflight', 'in_progress', 'uncertain', 'conflict', 'failed'].includes(item.status))}><summary className="mt-3 cursor-pointer text-[13px] font-bold">تفاصيل متقدمة</summary>{state?.operations?.map(item => <p key={item.operation_id} className="mt-3 text-[13px]"><Badge>{kindLabels[item.kind] || 'عملية غير معروفة'}</Badge> {operationLabels[item.status] || 'حالة غير معروفة'}
      {canCancelOperation(item, authority) && <button type="button" className={`${secondary} mr-2`} disabled={blocked} onClick={() => setCancelling(item)}>إلغاء العملية قبل الكتابة</button>}
      {['suspend', 'activate', 'link'].includes(item.kind) && item.status === 'prepared' && permits[item.kind] && <button className={`${secondary} mr-2`} type="button" disabled={blocked || needsReview} onClick={() => { setCredentials(null); setReceipt(null); setExecutingAccount(item) }}>تنفيذ {kindLabels[item.kind]}</button>}
      {['preflight', 'in_progress', 'uncertain'].includes(item.status) && (['create', 'reset'].includes(item.kind) ? mayCreate : permits[item.kind]) && <button type="button" className={`${secondary} mr-2`} disabled={blocked} onClick={() => run('reconcile', { operation_id: item.operation_id }, data => { setState(data); onRefresh() })}>مصالحة للقراءة فقط</button>}
    </p>)}</details>
    {!simple && !revision && <Notice>احفظ الاسم الإنكليزي والمسودة أولًا.</Notice>}
    {draftDirty && <Notice>احفظ مسودة الاسم أو ألغِ تغييراتها قبل بدء عملية البريد.</Notice>}
    {state?.enabled && revision > 0 && !needsReview && <div className="mt-4 flex flex-wrap gap-2">
      {!simple && mayCreate && mayReceipt && state.provisioning_status === 'draft' && !state.operations.some(item => !['prepared', 'failed', 'cancelled'].includes(item.status)) && <button type="button" className={action} disabled={blocked} onClick={() => prepare('create')}>توليد كلمة مرور</button>}
      {mayReset && mayReceipt && state.linkage_origin !== 'linked' && state.provisioning_status === 'created' && !state.operations.some(item => ['prepared', 'preflight', 'in_progress', 'uncertain'].includes(item.status)) && <button type="button" className={secondary} disabled={blocked} onClick={() => {
        if (window.confirm('إعادة الإصدار تغيّر كلمة المرور الفعلية للصندوق وتبطل بيانات الدخول السابقة، بما فيها أي PDF سابق. هل تريد المتابعة؟')) prepare('reset')
      }}>إعادة إصدار كلمة أولية مفقودة</button>}
    </div>}
    {credentials && <div className="mt-4 rounded-[12px] border border-primary/20 p-4 space-y-3">
      <Notice>كلمة المرور في ذاكرة الصفحة فقط. مغادرة الصفحة تفقدها. الإيصال يحتوي بيانات سرية؛ احفظه وسلّمه بأمان.</Notice>
      <p className="text-[13px]">{printableCredentials(state, credentials) ? 'كلمة المرور للصندوق المؤكد' : 'لم يُؤكد التنفيذ — لا تتوفر بيانات دخول صالحة بعد'}{credentialVisible(state, credentials) && <span dir="ltr" className="block font-mono text-[18px] break-all">{credentials.password}</span>}</p>
      {op?.status === 'prepared' && <button className={action} type="button" disabled={blocked || needsReview} onClick={() => setConfirm(true)}>{credentials.kind !== 'create' ? 'تأكيد تغيير الكلمة على خادم البريد' : 'إنشاء الصندوق على خادم البريد'}</button>}
      {mayReceipt && printableCredentials(state, credentials) && <button className={action} type="button" disabled={blocked} onClick={download}>{busy ? 'جارٍ تنزيل الإيصال…' : 'تنزيل الإيصال PDF'}</button>}
      <button className={secondary} type="button" disabled={busy} onClick={() => { setCredentials(null); setReceipt(null); setConfirm(false) }}>إنهاء وإخفاء بيانات الدخول</button>
    </div>}
    {confirm && <ManualGradeDialog title={`تأكيد ${kindLabels[credentials.kind]}`} onCancel={() => setConfirm(false)} onConfirm={execute} disabled={blocked} confirmLabel="تأكيد العملية">
      <p className="text-[13px]">الطالب: {studentName}. سيُنفّذ طلب واحد للعنوان <span dir="ltr">{state.email_address}</span>. الحساب الجديد فقط بحصة 50 MiB؛ تغيير الكلمة لا يغيّر الحصة. لن يعاد الطلب تلقائيًا إذا فُقد الرد.</p>
    </ManualGradeDialog>}
    {accountAction && <ManualGradeDialog title={kindLabels[accountAction]} onCancel={() => setAccountAction(null)} onConfirm={prepareAccount} disabled={blocked || !reason.trim() || (accountAction === 'link' && (!linkPreview || !attested || linkPreview.email_address !== address))} confirmLabel="تأكيد وتجهيز العملية">
      <p>الطالب: {studentName}. العنوان: <span dir="ltr" className="break-all">{state.email_address}</span>. لا حذف للرسائل ولا تعديل للبريد الشخصي أو حساب الجامعة.</p>
      {accountAction === 'link' && <div className="space-y-3">
        <label className="block text-[13px]">عنوان الصندوق السابق<input dir="ltr" value={address} onChange={e => { setAddress(e.target.value); setLinkPreview(null); setAttested(false) }} disabled={blocked} autoComplete="off" className="w-full py-2.5 px-3 border border-primary/20 rounded-[10px]" /></label>
        <button className={secondary} type="button" disabled={blocked || !address} onClick={() => run('preview-link', { email_address: address }, setLinkPreview)}>عرض الصندوق والتحقق من العنوان</button>
        {linkPreview && <><InfoGrid items={[[ 'الصندوق', linkPreview.email_address ], ['الحالة', linkPreview.mailbox.active ? 'فعال' : 'موقوف' ], ['الحصة', usageLabel(linkPreview.mailbox.quota_bytes)]]} /><Notice tone="warning">وجود الصندوق وتطابق الاسم أو الرقم لا يثبت ملكية الطالب. الربط سيضيف علامة التطبيق فقط، دون تغيير كلمة المرور أو الحصة أو الحالة.</Notice><label className="flex gap-2 text-[13px]"><input type="checkbox" checked={attested} disabled={blocked} onChange={e => setAttested(e.target.checked)} />تحققت من ملكية هذا الطالب للصندوق وأوثّق المرجع في السبب أدناه.</label></>}
      </div>}
      <label className="block mt-3 text-[13px]">السبب أو مرجع التحقق<textarea value={reason} onChange={e => setReason(e.target.value)} maxLength={500} disabled={blocked} className="w-full mt-2 py-2.5 px-3 border border-primary/20 rounded-[10px]" /></label>
    </ManualGradeDialog>}
    {executingAccount && <ManualGradeDialog title={`تأكيد ${kindLabels[executingAccount.kind]}`} onCancel={() => setExecutingAccount(null)} disabled={blocked} confirmLabel="تنفيذ العملية الفردية" onConfirm={() => {
      const item = executingAccount; setExecutingAccount(null)
      run('execute-account', { operation_id: item.operation_id, generation: item.generation, confirmed: true }, data => { setState(data); onRefresh() })
    }}><p>الطالب: {studentName}. العنوان: <span dir="ltr" className="break-all">{state.email_address}</span>. الإجراء: {kindLabels[executingAccount.kind]}. لا يعاد طلب الكتابة تلقائيًا عند فقدان الرد.</p></ManualGradeDialog>}
    {cancelling && <ManualGradeDialog title="إلغاء عملية لم تبدأ الكتابة" onCancel={() => setCancelling(null)} onConfirm={cancelOperation} disabled={blocked} confirmTone="discard" confirmLabel="إلغاء العملية وإبطال بياناتها">
      <p>سيُحفظ تاريخ العملية وتُبطل بيانات الدخول المقترحة. لن يُحذف أي صندوق بريد. يعيد الخادم التحقق تحت الأقفال؛ إذا بدأت الكتابة فلن يسمح بالإلغاء. بعد إلغاء الإنشاء يمكنك تصحيح الاسم وحفظ مسودة جديدة.</p>
    </ManualGradeDialog>}
    {receipt && credentials && printableCredentials(state, credentials) && createPortal(<div aria-hidden="true" style={{ position: 'fixed', left: -10000, top: 0, pointerEvents: 'none' }}><div ref={receiptElement}><UniversityEmailReceipt receipt={receipt} password={credentials.password} /></div></div>, document.body)}
  </Section>
}
