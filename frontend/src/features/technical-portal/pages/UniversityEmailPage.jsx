import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { FaEnvelope, FaSave, FaSyncAlt } from 'react-icons/fa'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { PageHeader, Section, Notice, StatePanel, InfoGrid, Badge } from '../../ministry-portal/components/MinistryUi'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { EMAIL_API, draftPayload, emailFailure, previewAddress, requestSequence, requiresReview } from '../lib/universityEmail'

const button = 'inline-flex items-center justify-center gap-2 py-2 px-4 rounded-[10px] bg-primary text-white text-[13px] font-bold disabled:opacity-50 hover:bg-primary-dark'
const secondary = 'inline-flex items-center justify-center gap-2 py-2 px-4 rounded-[10px] border border-primary/20 bg-white text-primary-dark text-[13px] font-bold disabled:opacity-50'
const stamp = () => JSON.stringify(getIdentity())

export default function UniversityEmailPage() {
  const [identity, setIdentity] = useState(stamp)
  useEffect(() => {
    const check = () => setIdentity(stamp())
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check) }
  }, [])
  return canAccess(ACCESS.universityEmail) ? <Workspace key={identity} identity={identity} /> : <StatePanel state="forbidden" message="لا تملك صلاحية عرض البريد الجامعي." />
}

function Workspace({ identity }) {
  const [search, setSearch] = useState(''), [applied, setApplied] = useState(''), [page, setPage] = useState(1)
  const [list, setList] = useState(null), [listError, setListError] = useState(null), [loading, setLoading] = useState(true)
  const [selected, setSelected] = useState(null), [snapshot, setSnapshot] = useState(null), [detailError, setDetailError] = useState(null), [detailBusy, setDetailBusy] = useState(false)
  const [dirty, setDirty] = useState(false), [pending, setPending] = useState(false), [notice, setNotice] = useState('')
  const [health, setHealth] = useState(null), [healthError, setHealthError] = useState(''), [healthBusy, setHealthBusy] = useState(false)
  const [retry, setRetry] = useState(0)
  const [denied, setDenied] = useState(false)
  const listSequence = useRef(requestSequence()), listAbort = useRef(null), detailSequence = useRef(requestSequence()), detailAbort = useRef(null)
  const currentIntent = useRef(''), currentPage = useRef(1), authorized = useRef(true)
  const isCurrent = useCallback(() => authorized.current && identity === stamp() && canAccess(ACCESS.universityEmail), [identity])
  const deny = useCallback(error => {
    if ([401,403].includes(error?.status)) {
      authorized.current = false; listSequence.current.invalidate(); detailSequence.current.invalidate()
      setDenied(true)
      listAbort.current?.abort(); detailAbort.current?.abort()
      setList(null); setSnapshot(null); setSelected(null); setDirty(false); setPending(false); setDetailError(error); setListError(error)
      return true
    }
    return false
  }, [])
  const blocker = useBlocker(() => isCurrent() && (dirty || pending))
  useEffect(() => { if (blocker.state === 'blocked' && !isCurrent()) blocker.proceed() }, [blocker, isCurrent, dirty, pending])
  useEffect(() => {
    const warn = event => { if (isCurrent() && (dirty || pending)) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty, pending, isCurrent])
  useEffect(() => {
    authorized.current = true
    const searches = listSequence.current, details = detailSequence.current
    return () => { authorized.current = false; searches.invalidate(); details.invalidate(); listAbort.current?.abort(); detailAbort.current?.abort() }
  }, [])

  const changeSearch = value => {
    const normalized = value.trim()
    setSearch(value)
    if (normalized === currentIntent.current) return
    currentIntent.current = normalized; currentPage.current = 1
    listSequence.current.invalidate(); listAbort.current?.abort(); setList(null); setListError(null); setPage(1); setLoading(true)
  }
  useEffect(() => {
    const timer = setTimeout(() => { setApplied(search.trim()); setRetry(value => value + 1) }, 350)
    return () => clearTimeout(timer)
  }, [search])
  useEffect(() => {
    const sequence = listSequence.current, seq = sequence.next(), controller = new AbortController()
    listAbort.current = controller
    const load = async () => {
      try {
        const json = await apiRequest(`${EMAIL_API}/students?${new URLSearchParams({ q: applied, page, per_page: 15 })}`, { signal: controller.signal })
        if (!Array.isArray(json?.data) || !json?.meta) throw new Error('استجابة البحث غير صالحة؛ أعد المحاولة.')
        if (sequence.accepts(seq) && isCurrent() && applied === currentIntent.current && page === currentPage.current) { setList(json); setLoading(false); setListError(null) }
      } catch (error) {
        if (error.name !== 'AbortError' && sequence.accepts(seq) && isCurrent()) { deny(error); setListError(error); setLoading(false) }
      }
    }
    load()
    return () => { sequence.invalidate(); controller.abort() }
  }, [applied, page, retry, isCurrent, deny])

  const loadStudent = useCallback(async id => {
    detailAbort.current?.abort(); const controller = new AbortController(); detailAbort.current = controller
    const seq = detailSequence.current.next(); setDetailBusy(true); setDetailError(null)
    try {
      const json = await apiRequest(`${EMAIL_API}/students/${id}`, { signal: controller.signal })
      if (Number(json?.data?.student?.student_id) !== Number(id) || !json?.data?.settings) throw new Error('استجابة الطالب غير صالحة؛ أعد المحاولة.')
      if (detailSequence.current.accepts(seq) && isCurrent()) { setSnapshot({ id, data: json.data }); return true }
    } catch (error) { if (error.name !== 'AbortError' && detailSequence.current.accepts(seq) && isCurrent()) { deny(error); setDetailError(error) } }
    finally { if (detailSequence.current.accepts(seq) && isCurrent()) setDetailBusy(false) }
    return false
  }, [isCurrent, deny])
  const select = id => {
    if (pending) { setNotice('انتظر اكتمال الحفظ قبل تغيير الطالب.'); return }
    if (selected === id) return
    if (dirty && !window.confirm('هل تريد إلغاء المسودة غير المحفوظة والانتقال إلى الطالب الآخر؟')) return
    setDirty(false); setSnapshot(null); setSelected(id); setNotice(''); loadStudent(id)
  }
  const checkHealth = async () => {
    if (healthBusy) return
    setHealthBusy(true); setHealth(null); setHealthError('')
    try { const json = await apiRequest(`${EMAIL_API}/connection`); if (isCurrent()) setHealth(json.data) }
    catch (error) { if (isCurrent()) { deny(error); setHealthError(error.message || 'تعذر فحص الاتصال.') } }
    finally { if (isCurrent()) setHealthBusy(false) }
  }
  const columns = [
    { key: 'student', header: 'الطالب', dir: 'rtl', render: row => <div><div className="font-semibold">{row.full_name}</div><div className="text-[12px] text-text-light" dir="ltr">{row.student_number}</div></div> },
    { key: 'college', header: 'الكلية', render: row => row.college || 'غير محدد' },
    { key: 'program', header: 'البرنامج', render: row => row.program || 'غير محدد' },
    { key: 'action', header: 'الإجراء', render: row => <button type="button" className={secondary} onClick={() => select(row.student_id)} disabled={pending}>تجهيز البريد</button> },
  ]
  if (denied) return <StatePanel state="forbidden" message="انتهى الوصول المصرح؛ أُخفيت بيانات الطلاب. أعد تسجيل الدخول بعد التحقق من صلاحياتك." />
  return <div dir="rtl" className="space-y-4">
    <PageHeader title="البريد الجامعي" en="University email" subtitle="تجهيز مسودات الطلاب — المرحلة الأولى" />
    <Notice>الحفظ محلي فقط، ولا يؤكد وجود الصندوق أو توافر العنوان على خادم البريد. إنشاء الصندوق وكلمة المرور وورقة التسليم الموقعة مؤجلة للمرحلة الثانية.</Notice>
    {notice && <Notice>{notice}</Notice>}
    {canAccess(ACCESS.universityEmailCheck) && <Section title="اتصال خادم البريد — قراءة فقط" action={<button type="button" className={secondary} disabled={healthBusy} onClick={checkHealth}><FaSyncAlt />{healthBusy ? 'جاري الفحص…' : 'فحص اتصال Mailcow'}</button>}>
      {healthError && <Notice tone="warning">{healthError} البحث والحفظ المحلي مستقلان عن هذا الفحص.</Notice>}
      {health ? <InfoGrid items={[[ 'النطاق', health.domain ], ['حالة النطاق', health.active ? 'فعال' : 'غير فعال'], ['الصناديق الحالية', health.mailbox_count], ['الحد الحالي', health.mailbox_limit], ['المتاح حاليًا', health.remaining_mailboxes], ['وقت الفحص', health.checked_at]]} /> : !healthError && <p className="text-[12px] text-text-light">لا يتم الاتصال تلقائيًا أو لكل طالب. هذا الفحص لا يغير إعدادات Mailcow.</p>}
    </Section>}
    <FilterBar search={{ value: search, onChange: changeSearch, placeholder: 'ابحث باسم الطالب بالعربية أو رقمه الجامعي…' }} />
    {listError && <StatePanel state={[401,403].includes(listError.status) ? 'forbidden' : 'error'} message={listError.message} onRetry={() => { setLoading(true); setRetry(r => r + 1) }} />}
    {!listError && <DataTable columns={columns} rows={list?.data || []} rowKey={row => row.student_id} loading={loading} page={page} totalPages={list?.meta?.last_page || 1} onPageChange={value => { if (value === page) return; currentPage.current = value; listSequence.current.invalidate(); listAbort.current?.abort(); setLoading(true); setPage(value) }} emptyIcon={FaEnvelope} emptyTitle="لا توجد نتائج ضمن نطاقك" />}
    {selected && detailBusy && !snapshot && <StatePanel state="loading" />}
    {detailError && <StatePanel state={[401,403].includes(detailError.status) ? 'forbidden' : 'error'} message={detailError.message} onRetry={() => loadStudent(selected)} />}
    {snapshot?.id === selected && <DraftEditor key={selected} data={snapshot.data} canManage={canAccess(ACCESS.universityEmailManage)} isCurrent={isCurrent} onDirty={setDirty} onPending={setPending} onDenied={deny} onRefresh={() => loadStudent(selected)} refreshing={detailBusy} />}
    {blocker.state === 'blocked' && <ManualGradeDialog title={pending ? 'عملية حفظ قيد التنفيذ' : 'مسودة غير محفوظة'} disabled={pending} onConfirm={() => { if (!pending) blocker.proceed() }} confirmLabel="إلغاء المسودة والمتابعة" confirmTone="discard" onCancel={() => blocker.reset()}>
      <p>{pending ? 'انتظر نتيجة الحفظ قبل مغادرة الصفحة.' : 'هل تريد إلغاء المسودة والانتقال إلى الصفحة المطلوبة؟'}</p>
    </ManualGradeDialog>}
  </div>
}

function DraftEditor({ data, canManage, isCurrent, onDirty, onPending, onDenied, onRefresh, refreshing }) {
  const [baseline, setBaseline] = useState(data.draft), [name, setName] = useState(data.draft?.english_first_name || '')
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [success, setSuccess] = useState(''), [review, setReview] = useState(false)
  const [reviewed, setReviewed] = useState(false)
  const writing = useRef(false), alive = useRef(true)
  const dirty = name !== (baseline?.english_first_name || '')
  const serverChanged = (data.draft?.revision ?? 0) !== (baseline?.revision ?? 0)
  const readOnly = !canManage || (baseline && (baseline.provisioning_status !== 'draft' || baseline.handover_status !== 'not_delivered'))
  const preview = previewAddress(name, data.student.student_number, data.settings.domain)
  useEffect(() => { onDirty(dirty) }, [dirty, onDirty])
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  const save = async event => {
    event.preventDefault()
    if (writing.current || readOnly || review || serverChanged || !preview || !isCurrent()) return
    writing.current = true; setBusy(true); onPending(true); setError(''); setSuccess('')
    const proposed = name
    try {
      const json = await apiRequest(`${EMAIL_API}/students/${data.student.student_id}/draft`, { method: 'PUT', body: JSON.stringify(draftPayload(proposed, baseline)) })
      if (!alive.current || !isCurrent()) return
      setBaseline(json.data.draft); setName(json.data.draft.english_first_name); setReview(false)
      setSuccess('حُفظت المسودة محليًا — لم يتم إنشاء الصندوق أو تسليمه.'); onRefresh()
    } catch (failure) {
      if (!alive.current || !isCurrent()) return
      if (onDenied(failure)) return
      setError(emailFailure(failure)); setReview(requiresReview(failure)); setReviewed(false)
    } finally { writing.current = false; if (alive.current && isCurrent()) { setBusy(false); onPending(false) } }
  }
  const useServer = () => {
    if (review && !reviewed) return
    setBaseline(data.draft); setName(data.draft?.english_first_name || ''); setReview(false); setError(''); setSuccess('')
  }
  const reviewServer = async () => { setReviewed(false); if (await onRefresh()) setReviewed(true) }
  // A refresh never silently replaces/rebases the local proposal or its original revision.
  return <Section title={`تجهيز بريد ${data.student.full_name}`} subtitle="المسودة مستقلة عن البريد الشخصي وحساب الدخول. حصة الطالب 50 MiB.">
    <InfoGrid items={[[ 'الرقم الجامعي', data.student.student_number ], ['الكلية', data.student.college || 'غير محدد'], ['التجهيز', baseline ? (baseline.provisioning_status === 'draft' ? 'مسودة — لم ينشأ الصندوق' : 'تم إنشاء الصندوق') : 'لم تُحفظ مسودة'], ['التسليم', baseline?.handover_status === 'delivered' ? 'تم التسليم' : 'غير مسلّم']]} />
    {success && <div className="bg-green-50 border border-green-200 rounded-[12px] px-5 py-3 mt-3 text-[13px] text-green-700" role="status">{success}</div>}
    {error && <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mt-3 text-[13px] text-red-700" role="alert">{error}</div>}
    {(review || serverChanged) && <div className="mt-4 space-y-3"><Notice tone="warning">قيمك المقترحة محفوظة محليًا. لا يمكن الحفظ حتى مراجعة الحالة الرسمية واتخاذ قرار صريح.</Notice>
      <button type="button" className={secondary} onClick={reviewServer} disabled={refreshing || busy}>مراجعة النسخة المحفوظة</button>
      <p className="text-[13px] break-all">النسخة المحفوظة: <span dir="ltr">{data.draft?.email_address || 'لا توجد مسودة'}</span> <Badge>إصدار {data.draft?.revision || 0}</Badge></p>
      <button type="button" className={secondary} disabled={refreshing || busy || (review && !reviewed)} onClick={useServer}>اعتماد النسخة المحفوظة وإلغاء مسودتي</button>
      {(serverChanged || (review && reviewed)) && <button type="button" className={secondary} disabled={refreshing || busy || (review && !reviewed)} onClick={() => { setBaseline(data.draft); setReview(false); setError(''); setSuccess('') }}>متابعة بقيمي بعد مراجعة النسخة الجديدة</button>}
    </div>}
    <form onSubmit={save} className="mt-4 space-y-3">
      <label htmlFor="english-first-name" className="block text-[13px] font-bold text-text-dark">الاسم الأول بالإنكليزي</label>
      <input id="english-first-name" value={name} onChange={e => { setName(e.target.value); setSuccess('') }} disabled={busy || readOnly} required maxLength={64} dir="ltr" autoComplete="off" aria-describedby="name-help" className="w-full py-2.5 px-3 border-[1.5px] border-primary/20 rounded-[10px] text-[14px] outline-none focus:border-primary" />
      <p id="name-help" className="text-[12px] text-text-light">أحرف إنكليزية فقط دون أرقام أو رموز أو مسافات داخلية. الاسم المركب متصل مثل abdulrahman. ستزال المسافات الطرفية وتصبح الأحرف صغيرة.</p>
      <div className="rounded-[12px] border border-primary/15 bg-primary/5 p-3"><p className="text-[12px] text-text-light">معاينة مقترحة — يعيد الخادم التحقق عند الحفظ</p><p id="email-preview" dir="ltr" className="text-[14px] font-bold break-all">{preview || 'أدخل اسمًا صالحًا ضمن الطول المسموح'}</p></div>
      {readOnly && <Notice>العرض فقط؛ تعديل المسودة يتطلب صلاحية التجهيز، والعنوان المنشأ أو المسلّم غير قابل للتحرير هنا.</Notice>}
      {!readOnly && <button type="submit" className={button} disabled={busy || review || serverChanged || !preview}><FaSave />{busy ? 'جاري الحفظ…' : 'حفظ المسودة'}</button>}
    </form>
  </Section>
}
