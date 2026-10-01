import { useCallback, useEffect, useRef, useState } from 'react'
import { useBlocker } from 'react-router-dom'
import { FaEnvelope, FaSyncAlt } from 'react-icons/fa'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { PageHeader, Section, Notice, StatePanel, InfoGrid, Badge } from '../../ministry-portal/components/MinistryUi'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import UniversityEmailMailboxDialog from '../components/UniversityEmailMailboxDialog'
import { EMAIL_API, preparationLabels, requestSequence, studentSearchQuery } from '../lib/universityEmail'

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
  const [sensitive, setSensitive] = useState(false)
  const [health, setHealth] = useState(null), [healthError, setHealthError] = useState(''), [healthBusy, setHealthBusy] = useState(false)
  const [retry, setRetry] = useState(0)
  const [denied, setDenied] = useState(false)
  const listSequence = useRef(requestSequence()), listAbort = useRef(null), detailSequence = useRef(requestSequence()), detailAbort = useRef(null)
  const currentIntent = useRef(''), currentPage = useRef(1), authorized = useRef(true)
  const healthInFlight = useRef(false)
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
  const blocker = useBlocker(() => isCurrent() && (dirty || pending || sensitive))
  useEffect(() => { if (blocker.state === 'blocked' && !isCurrent()) blocker.proceed() }, [blocker, isCurrent, dirty, pending])
  useEffect(() => {
    const warn = event => { if (isCurrent() && (dirty || pending || sensitive)) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty, pending, sensitive, isCurrent])
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
        const json = await apiRequest(`${EMAIL_API}/students?${studentSearchQuery(applied, page)}`, { signal: controller.signal })
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
    if ((dirty || sensitive) && !window.confirm('الانتقال يلغي المسودة غير المحفوظة ويفقد بيانات الدخول المؤقتة. هل تريد المتابعة؟')) return
    setDirty(false); setSnapshot(null); setSelected(id); setNotice(''); loadStudent(id)
  }
  const checkHealth = useCallback(async () => {
    if (healthInFlight.current || !isCurrent() || !canAccess(ACCESS.universityEmailCheck)) return
    healthInFlight.current = true
    setHealthBusy(true); setHealth(null); setHealthError('')
    try { const json = await apiRequest(`${EMAIL_API}/connection`); if (isCurrent()) setHealth(json.data) }
    catch (error) { if (isCurrent()) { deny(error); setHealthError(error.message || 'تعذر فحص الاتصال.') } }
    finally { healthInFlight.current = false; if (isCurrent()) setHealthBusy(false) }
  }, [isCurrent, deny])
  useEffect(() => { const timer = setTimeout(checkHealth, 0); return () => clearTimeout(timer) }, [checkHealth])
  const columns = [
    { key: 'student', header: 'الطالب', dir: 'rtl', render: row => <span className="font-semibold">{row.full_name}</span> },
    { key: 'number', header: 'الرقم الجامعي', render: row => <span dir="ltr">{row.student_number}</span> },
    { key: 'college', header: 'الكلية', render: row => row.college || 'غير محدد' },
    { key: 'program', header: 'البرنامج', render: row => row.program || 'غير محدد' },
    { key: 'email', header: 'البريد الجامعي', render: row => <span dir="ltr" className="break-all">{row.email_preparation?.email_address || '—'}</span> },
    { key: 'preparation', header: 'الحالة', render: row => <Badge>{row.email_preparation?.provisioning_status === 'created' ? 'بريد مؤكد' : row.email_preparation?.available === true ? 'لم يُنشأ صندوق مؤكد' : preparationLabels(row.email_preparation).preparation}</Badge> },
    { key: 'action', header: 'الإجراء', render: row => <button type="button" className={secondary} onClick={() => select(row.student_id)} disabled={pending}>{row.email_preparation?.provisioning_status === 'created' ? 'إدارة البريد' : 'إنشاء بريد'}</button> },
  ]
  if (denied) return <StatePanel state="forbidden" message="انتهى الوصول المصرح؛ أُخفيت بيانات الطلاب. أعد تسجيل الدخول بعد التحقق من صلاحياتك." />
  return <div dir="rtl" className="space-y-4">
    <PageHeader title="البريد الجامعي" en="University email" subtitle="إنشاء بريد فردي للطالب وإدارة الحسابات المؤكدة" />
    <Notice>اختر الطالب ثم «إنشاء بريد». بيانات الدخول مؤقتة، وتنزيل PDF لا يسجل استلامًا أو تسليمًا.</Notice>
    {notice && <Notice>{notice}</Notice>}
    {canAccess(ACCESS.universityEmailCheck) && <Section title="اتصال خادم البريد — قراءة فقط" action={<button type="button" className={secondary} disabled={healthBusy} onClick={checkHealth}><FaSyncAlt />{healthBusy ? 'جاري الفحص…' : 'فحص اتصال Mailcow'}</button>}>
      {healthError && <Notice tone="warning">{healthError} البحث والحفظ المحلي مستقلان عن هذا الفحص.</Notice>}
      {health ? <InfoGrid items={[[ 'النطاق', health.domain ], ['حالة النطاق', health.active ? 'فعال' : 'غير فعال'], ['الصناديق الحالية', health.mailbox_count], ['الحد الحالي', health.mailbox_limit], ['المتاح حاليًا', health.remaining_mailboxes], ['وقت الفحص', health.checked_at]]} /> : !healthError && <p className="text-[12px] text-text-light">يُفحص الاتصال مرة عند فتح الصفحة، لا لكل طالب. هذا الفحص لا يغير إعدادات Mailcow.</p>}
    </Section>}
    <FilterBar search={{ value: search, onChange: changeSearch, placeholder: 'ابحث باسم الطالب بالعربية أو رقمه الجامعي…' }} />
    {list?.email_schema_ready === false && <Notice tone="warning">تجهيز البريد غير جاهز؛ تعذر قراءة حالته المحلية. يلزم تطبيق migration المرحلة الأولى قبل التجهيز، ولا يعني ذلك أن الطلاب بلا مسودات.</Notice>}
    {listError && <StatePanel state={[401,403].includes(listError.status) ? 'forbidden' : 'error'} message={listError.message} onRetry={() => { setLoading(true); setRetry(r => r + 1) }} />}
    {!listError && <DataTable columns={columns} rows={list?.data || []} rowKey={row => row.student_id} loading={loading} page={page} totalPages={list?.meta?.last_page || 1} onPageChange={value => { if (value === page) return; currentPage.current = value; listSequence.current.invalidate(); listAbort.current?.abort(); setLoading(true); setPage(value) }} emptyIcon={FaEnvelope} emptyTitle="لا توجد نتائج ضمن نطاقك" />}
    {selected && detailBusy && !snapshot && <StatePanel state="loading" />}
    {detailError && <StatePanel state={[401,403].includes(detailError.status) ? 'forbidden' : 'error'} message={detailError.message} onRetry={() => loadStudent(selected)} />}
    {snapshot?.id === selected && <UniversityEmailMailboxDialog key={selected} data={snapshot.data} externalPending={pending} isCurrent={isCurrent} onDirty={setDirty} onSensitive={setSensitive} onPending={setPending} onDenied={deny} onClose={() => { setSelected(null); setSnapshot(null); setDirty(false); setSensitive(false) }} onRefresh={async () => {
      if (await loadStudent(selected)) setRetry(r => r + 1)
    }} />}
    {blocker.state === 'blocked' && <ManualGradeDialog title={pending ? 'عملية حفظ قيد التنفيذ' : 'مسودة غير محفوظة'} disabled={pending} onConfirm={() => { if (!pending) blocker.proceed() }} confirmLabel="إلغاء المسودة والمتابعة" confirmTone="discard" onCancel={() => blocker.reset()}>
      <p>{pending ? 'انتظر نتيجة العملية قبل مغادرة الصفحة.' : 'المغادرة تلغي المسودة غير المحفوظة وتفقد بيانات الدخول المؤقتة. هل تريد المتابعة؟'}</p>
    </ManualGradeDialog>}
  </div>
}
