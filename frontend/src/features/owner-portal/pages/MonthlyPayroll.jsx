import { useCallback, useEffect, useMemo, useReducer, useRef, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { FaFileExcel, FaFilePdf, FaSave, FaCheckCircle, FaCog, FaEye, FaPen, FaExpand, FaCompress } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { canAccess, clearIdentity, getIdentity } from '../../auth/auth'
import { Dialog, Notice, inputClass } from '../../vice-presidency/components/GovernanceUi'
import OfficeHeading from '../../hr-office/components/OfficeHeading'
import { canHr } from '../../hr-office/lib/hrOffice'
import AccountingLedger from '../components/AccountingLedger'
import MonthlyAmountsEditor from '../components/MonthlyAmountsEditor'
import ColumnsDialog from '../components/ColumnsDialog'
import MonthlyGridPanel from '../components/MonthlyGridPanel'
import '../components/monthlyPayroll.css'
import { PayrollApiProvider } from '../lib/PayrollApiContext'
import { accountingError, accountingPath, createMonthlyApi, FINANCE } from '../lib/monthlyApi'
import { formatSyp, formatValue } from '../lib/payrollMoney'
import { createMonthlyConfigApi } from '../lib/monthlyConfigApi'
import { initialMonthlyDrafts, monthlyDraftReducer, monthlyDraftTotals, monthlyExportColumns, monthlyGridRows } from '../lib/monthlyGrid'

const money = value => value == null ? 'لم يحدد' : formatSyp(value)
const statusNames = { unpaid: 'لا صرف مسجل', paid: 'صرف فعلي مسجل', received: 'استلام موثق', voided: 'سجلات ملغاة فقط' }
const defaultAllowed = permission => canAccess({ allRoles: ['university_owner'], assignedPermissions: ['owner_portal.access', permission] })

export default function MonthlyPayroll({ office = 'owner', authorize = defaultAllowed }) {
  const { employee } = useParams(); const identity = JSON.stringify(getIdentity())
  if (!authorize('owner_payroll.view')) return <Notice tone="error">لا تملك صلاحية المحاسبة.</Notice>
  return <AccountingWorkspace key={`${office}:${identity}:${employee || ''}`} office={office} employee={employee} identity={identity} authorize={authorize} />
}

function AccountingWorkspace({ office, employee, identity, authorize }) {
  const navigate = useNavigate(); const [params, setParams] = useSearchParams(); const api = useMemo(() => createMonthlyApi(office), [office])
  const period = params.get('period') || ''; const tab = params.get('tab') === 'reports' ? 'reports' : 'payroll'; const page = Number(params.get('page')) || 1
  const query = useMemo(() => ({ period, employee_id: employee || '', q: params.get('q') || '', body: params.get('body') || '', college_id: params.get('college_id') || '', unit_id: params.get('unit_id') || '', payment_status: params.get('payment_status') || '', completeness: params.get('completeness') || '', revision: params.get('revision') || '', sort: params.get('sort') || 'employee_number', direction: params.get('direction') || 'asc', page }), [period, employee, params, page])
  const [data, setData] = useState(null); const [options, setOptions] = useState(null); const [error, setError] = useState(''); const [notice, setNotice] = useState(''); const [reload, setReload] = useState(0)
  const [draftState, draftDispatch] = useReducer(monthlyDraftReducer, initialMonthlyDrafts); const drafts = draftState.drafts
  const setDrafts = useCallback(update => { if (typeof update === 'function') draftDispatch({ type: 'set', update }); else draftDispatch({ type: 'reset' }) }, [])
  const [editing, setEditing] = useState(null); const [confirmation, setConfirmation] = useState(null); const [formGuard, setFormGuard] = useState({})
  const [busy, setBusy] = useState(false); const [uncertain, setUncertain] = useState(null); const [monthConfig, setMonthConfig] = useState(null)
  const [configApi, setConfigApi] = useState(null); const [configSessionStatus, setConfigSessionStatus] = useState('draft')
  const configSession = useRef(null); const frame = useRef(null)
  const [detailed, setDetailed] = useState(false); const [expanded, setExpanded] = useState(false); const [widths, setWidths] = useState({}); const [gridEditing, setGridEditing] = useState(false)
  const sequence = useRef(0); const busyRef = useRef(false); const lostAccess = useRef(false); const live = useRef(true); const accessEpoch = useRef(0)
  const dirty = Object.keys(drafts).length > 0 || gridEditing || formGuard.dirty || formGuard.uncertain || uncertain !== null || monthConfig !== null
  const blocker = useBlocker(({ currentLocation, nextLocation }) => {
    if (lostAccess.current) return false
    if (busyRef.current) return true
    if (gridEditing || formGuard.dirty || formGuard.busy || formGuard.uncertain || monthConfig) return currentLocation.pathname !== nextLocation.pathname || currentLocation.search !== nextLocation.search
    const next = new URLSearchParams(nextLocation.search)
    return !!dirty && (currentLocation.pathname !== nextLocation.pathname || (next.get('period') || '') !== period || (next.get('tab') === 'reports' ? 'reports' : 'payroll') !== tab)
  })
  const clearSensitive = useCallback(e => { lostAccess.current = true; ++sequence.current; ++accessEpoch.current; setData(null); setOptions(null); setConfigApi(null); configSession.current = null; setDrafts({}); setGridEditing(false); setEditing(null); setConfirmation(null); setMonthConfig(null); setUncertain(null); setFormGuard({}); setError(accountingError(e)); if (e.status === 401) { clearIdentity(); navigate('/login', { replace: true }) } }, [navigate, setDrafts])
  useEffect(() => { live.current = true; return () => { live.current = false } }, [])
  useEffect(() => { if (!dirty && !busy) return undefined; const listener = e => { e.preventDefault(); e.returnValue = '' }; window.addEventListener('beforeunload', listener); return () => window.removeEventListener('beforeunload', listener) }, [dirty, busy])
  useEffect(() => {
    const abort = new AbortController(); let active = true; const epoch = accessEpoch.current
    api.read('months/options', {}, abort.signal).then(json => {
      if (!active || epoch !== accessEpoch.current) return
      setOptions(json.data)
    }).catch(e => { if (active && epoch === accessEpoch.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } })
    return () => { active = false; abort.abort() }
  }, [api, reload, clearSensitive])
  const requestKey = JSON.stringify([query, identity, reload]); const matching = data?.key === requestKey
  useEffect(() => {
    const seq = ++sequence.current; const abort = new AbortController(); let active = true
    if (period) api.read('months/report', query, abort.signal).then(json => { if (active && seq === sequence.current) { lostAccess.current = false; setData({ ...json.data, key: requestKey }); setError('') } }).catch(e => { if (active && seq === sequence.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } })
    return () => { active = false; abort.abort() }
  }, [api, period, query, requestKey, clearSensitive])
  const report = matching ? data : null; const rawRows = useMemo(() => report?.rows || [], [report]); const historical = !!report?.initialized && report.revision !== report.current_revision
  const cap = key => !!options?.capabilities?.[FINANCE[key]] && authorize(FINANCE[key])
  const mutable = tab === 'payroll' && report?.initialized && !historical && (report.status === 'draft' || cap('correct'))
  const staleDraft = Object.values(drafts).some(d => {
    const current = rawRows.find(r => r.employee_id === d.row.employee_id)
    return report && (d.period_revision !== report.revision || (current && (current.current_hr_revision !== d.row.current_hr_revision || current.entry_revision !== d.row.entry_revision)))
  })
  const rows = useMemo(() => monthlyGridRows(rawRows, drafts, report?.config, staleDraft), [rawRows, drafts, report?.config, staleDraft])
  const gridTotals = useMemo(() => monthlyDraftTotals(report, drafts, query, staleDraft), [report, drafts, query, staleDraft])
  const guarded = busy || gridEditing || !!uncertain || !!monthConfig || formGuard.dirty || formGuard.busy || formGuard.uncertain || !!confirmation
  const changed = () => setReload(v => v + 1)
  function change(key, value) {
    if ((params.get(key) || '') === value) return
    if (guarded || editing) { setNotice('أكمل العملية الحالية أو أغلق المسودة أولًا.'); return }
    setParams(p => { const next = new URLSearchParams(p); if (value) next.set(key, value); else next.delete(key); if (key !== 'page') next.delete('page'); if (key === 'period') { next.delete('revision'); next.delete('sort'); next.delete('direction') }; return next })
  }
  const confirm = action => { if (!guarded) setConfirmation({ action, request_id: crypto.randomUUID() }) }
  async function execute(payload, action) {
    if (busyRef.current || uncertain) return
    const epoch = accessEpoch.current
    busyRef.current = true; setBusy(true); setError('')
    try { await api.write(`months/${action}`, payload); if (!live.current || epoch !== accessEpoch.current || JSON.stringify(getIdentity()) !== identity) return; setConfirmation(null); if (action === 'save') setDrafts({}); setNotice('تم حفظ العملية. لا ينشأ صرف أو استلام تلقائيًا.'); changed() }
    catch (e) { if (live.current && epoch === accessEpoch.current) { if ([401, 403].includes(e.status)) clearSensitive(e); else { setError(accountingError(e)); if (!e.status || e.status >= 500) setUncertain({ request_id: payload.request_id, action }); setConfirmation(null) } } }
    finally { busyRef.current = false; if (live.current) setBusy(false) }
  }
  async function reconcile() {
    if (!uncertain || busyRef.current) return
    const epoch = accessEpoch.current
    busyRef.current = true; setBusy(true)
    try { await api.read(`months/results/${uncertain.request_id}`); if (!live.current || epoch !== accessEpoch.current) return; if (uncertain.action === 'save') setDrafts({}); setUncertain(null); setNotice('تأكد حفظ العملية من نتيجتها المسجلة. لم نكرر الطلب.'); changed() }
    catch (e) { if (live.current && epoch === accessEpoch.current) { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(e.status === 404 ? 'لم توجد نتيجة محفوظة بعد. احتفظ بالمسودة وتحقق مجددًا؛ لا يعني ذلك أن الكتابة انتهت بالفشل.' : accountingError(e)) } }
    finally { busyRef.current = false; if (live.current) setBusy(false) }
  }
  async function download(kind) {
    if (guarded || dirty || !report?.initialized) return
    const epoch = accessEpoch.current
    busyRef.current = true; setBusy(true)
    try { const file = await api.download(kind, { ...query, revision: report.revision, view: detailed ? 'detailed' : 'compact', columns: monthlyExportColumns(report.config, detailed) }); if (!live.current || epoch !== accessEpoch.current || JSON.stringify(getIdentity()) !== identity) return; const url = URL.createObjectURL(file.blob); const link = document.createElement('a'); link.href = url; link.download = file.filename || `payroll-${period}.${kind}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 2000) }
    catch (e) { if (live.current && epoch === accessEpoch.current) { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } } finally { busyRef.current = false; if (live.current) setBusy(false) }
  }
  async function openConfig() {
    if (guarded || dirty || !report?.initialized || historical) { setNotice('افتح الشهر الحالي واحسم المسودات قبل تحرير قالبه.'); return }
    const epoch = accessEpoch.current
    try {
      const json = await api.read('months/config', { period })
      if (!live.current || epoch !== accessEpoch.current) return
      if (json.data.config.revision !== report.revision) { setError('تغير الشهر؛ أعد تحميل حالته قبل فتح القالب.'); return }
      const session = { revision: json.data.config.revision, futureRevision: json.data.future_revision, status: json.data.status, scope: { confirmed: false, publishFuture: false, reason: '' } }
      configSession.current = session
      const adapter = createMonthlyConfigApi(api, period, session, error => { if ([401, 403].includes(error?.status)) clearSensitive(error); if (!live.current || epoch !== accessEpoch.current) throw new DOMException('استجابة قديمة بعد تغير التفويض', 'AbortError') })
      setConfigApi(adapter); setConfigSessionStatus(json.data.status); setMonthConfig(json.data.config)
    } catch (e) { if (e.name === 'AbortError') return; if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) }
  }
  const scopeChanged = useCallback(scope => { if (configSession.current) configSession.current.scope = scope }, [])
  async function configChanged(config, result) {
    const current = config ? { config, ...result } : (await configApi.fetchPayrollConfig()).data
    if (configSession.current) { configSession.current.revision = current.config.revision; configSession.current.futureRevision = current.future_revision; configSession.current.status = current.status || configSession.current.status }
    setMonthConfig(current.config); if (current.status) setConfigSessionStatus(current.status); setNotice(config ? 'تم حفظ قالب الشهر وإعادة حسابه؛ لم تتغير دفعات الصرف أو الأشهر السابقة.' : 'تم فتح قالب الشهر الحالي بعد مراجعتك؛ لم يُكرر طلب الحفظ.')
  }
  const toggleView = value => { if (value === detailed) return; if (gridEditing || formGuard.dirty || formGuard.busy || formGuard.uncertain) { setNotice('أكمل تحرير الخلية أو النافذة أولًا؛ التعديلات السابقة محفوظة كمسودات.'); return }; setDetailed(value) }
  async function toggleFullscreen() { if (expanded) { if (document.fullscreenElement === frame.current) await document.exitFullscreen(); setExpanded(false); return }; setExpanded(true); try { await frame.current?.requestFullscreen?.() } catch { /* Keep the in-page expanded view. */ } }
  useEffect(() => { const changed = () => { if (document.fullscreenElement === frame.current) setExpanded(true); else setExpanded(false) }; const escape = e => { if (e.key === 'Escape' && !document.fullscreenElement) setExpanded(false) }; document.addEventListener('fullscreenchange', changed); document.addEventListener('keydown', escape); return () => { document.removeEventListener('fullscreenchange', changed); document.removeEventListener('keydown', escape) } }, [])
  function gridEdit(changes) { if (!mutable || !cap('amounts') || busy || uncertain || monthConfig || confirmation || staleDraft) return; draftDispatch({ type: 'edit', changes, rows: rawRows, config: report.config, revision: report.revision }) }
  function gridSort(key) { if (guarded || editing) return; setParams(p => { const next = new URLSearchParams(p); next.set('sort', key); next.set('direction', query.sort === key && query.direction === 'asc' ? 'desc' : 'asc'); next.delete('page'); return next }) }
  const cell = (row, key) => money(row.cells?.[key]?.v)
  const columns = [
    { key: 'employee', header: 'العامل / الرقم', render: r => <div className="max-w-[220px]"><Link className="font-bold text-primary" to={`${accountingPath(office, r.employee_id)}?period=${encodeURIComponent(period)}`}>{r.full_name}</Link><p className="text-[10px] text-text-light"><bdi>{r.employee_number}</bdi> — {r.status_name || 'حالة غير محددة'}</p></div> },
    { key: 'placement', header: 'الهيئة / الكلية أو الوحدة', render: r => <div className="max-w-[180px] break-words"><b>{r.body_name}</b><p className="text-text-light text-[10px]">{r.college_name ?? r.unit_name ?? 'غير محدد'}</p></div> },
    { key: 'due', header: 'الصافي المستحق', render: r => cell(r, 'total_net_payable') },
    { key: 'paid', header: 'المصروف الفعلي', render: r => r.disbursed == null ? 'لا فترة محفوظة' : money(r.disbursed) },
    { key: 'received', header: 'الاستلام الموثق', render: r => r.received == null ? 'لا فترة محفوظة' : money(r.received) },
    { key: 'remaining', header: 'المتبقي', render: r => money(r.remaining) },
    { key: 'state', header: 'الاستكمال / الصرف', render: r => <div className="space-y-1"><span className="hr-status bg-primary/5">{r.cells?.total_net_payable?.v == null ? 'مبالغ تحتاج استكمالًا' : 'مستحق محسوب'}</span><p className="text-[10px]">{statusNames[r.payment_status] || 'لم تُعدّ الفترة'}</p>{!r.classification_complete && <p className="text-[10px] text-amber-800">بيانات الموارد البشرية غير مكتملة</p>}</div> },
    { key: 'actions', header: 'الإجراءات', render: r => <div className="flex gap-2 whitespace-nowrap"><Link className="hr-btn" to={`${accountingPath(office, r.employee_id)}?period=${encodeURIComponent(period)}`}><FaEye />الملف</Link>{mutable && cap('amounts') && r.id && <button className="hr-btn" disabled={guarded} onClick={() => setEditing({ row: r, config: report.config })}><FaPen />{drafts[r.employee_id] ? 'المسودة' : 'المبالغ'}</button>}</div> },
  ]
  const worker = employee && report?.initialized ? rows.find(r => String(r.employee_id) === employee) : null
  return <PayrollApiProvider value={configApi}><div ref={frame} dir="rtl" className={`office-surface space-y-5 ${expanded ? 'monthly-accounting-expanded' : ''}`}>
    <OfficeHeading title={employee ? 'الملف المحاسبي للعامل' : 'مكتب المحاسبة — الرواتب الشهرية'} code="721" directorate="مديرية الشؤون المالية 72">{office === 'administrative' && canHr('view') && <Link className="hr-btn" to="/vp/administrative/hr">الموارد البشرية</Link>}{employee && <Link className="hr-btn" to={`${accountingPath(office)}?period=${encodeURIComponent(period)}`}>جميع العاملين</Link>}{cap('config') && <button className="hr-btn" onClick={openConfig}><FaCog />إعدادات البنود والمعادلات</button>}</OfficeHeading>
    {error && <Notice tone="error">{error}<button className="hr-btn mr-2" disabled={guarded} onClick={changed}>إعادة تحميل القراءة</button></Notice>}{notice && <Notice onDismiss={() => setNotice('')}>{notice}</Notice>}
    {uncertain && <Notice tone="warning">نتيجة الكتابة غير مؤكدة. المسودة محفوظة في هذه الصفحة، ولم يُكرر الطلب.<button className="hr-btn mr-2" disabled={busy} onClick={reconcile}>التحقق من النتيجة المسجلة</button></Notice>}
    <div className="office-frame"><div className="flex gap-1 overflow-x-auto border-b border-primary/15" role="tablist" aria-label="المحاسبة">{[['payroll', 'الرواتب الشهرية'], ['reports', 'التقارير']].map(([key, title]) => <button key={key} role="tab" aria-selected={tab === key} className="hr-tab" onClick={() => { if (key !== tab) change('tab', key) }}>{title}</button>)}</div>
      <div className="office-toolbar"><label className="flex items-center gap-2 font-bold">شهر الراتب<input type="month" className="office-input" value={period} disabled={guarded || !!editing} onChange={e => change('period', e.target.value)} /></label>{options?.periods.length > 0 && <select aria-label="فتح شهر محفوظ" className="office-input" value={options.periods.some(p => p.period === period) ? period : ''} disabled={guarded || !!editing} onChange={e => change('period', e.target.value)}><option value="">الأشهر المحفوظة</option>{options.periods.map(p => <option key={p.period} value={p.period}>{p.period} — {p.status === 'approved' ? 'معتمد' : 'مسودة'}</option>)}</select>}
        {report && !report.initialized && cap('periods') && <button className="hr-btn primary" disabled={guarded} onClick={() => confirm('prepare')}>إعداد الشهر بمبالغ غير محددة</button>}{mutable && cap('amounts') && <button className="hr-btn primary" disabled={guarded || staleDraft || Object.keys(drafts).length === 0} onClick={() => confirm('save')}><FaSave />حفظ التغييرات ({Object.keys(drafts).length})</button>}{report?.status === 'draft' && tab === 'payroll' && cap('periods') && <button className="hr-btn" disabled={guarded || dirty} onClick={() => confirm('approve')}><FaCheckCircle />اعتماد مستحقات الشهر</button>}{cap('export') && report?.initialized && <><button className="hr-btn" disabled={guarded || dirty} onClick={() => download('pdf')}><FaFilePdf />PDF</button><button className="hr-btn" disabled={guarded || dirty} onClick={() => download('xlsx')}><FaFileExcel />Excel</button></>}
        <div className="inline-flex rounded-[10px] border border-primary/25 overflow-hidden" role="radiogroup" aria-label="طريقة عرض الرواتب">{[[false, 'عرض مختصر'], [true, 'عرض تفصيلي']].map(([value, title]) => <button key={title} type="button" role="radio" aria-checked={detailed === value} className={`px-3 py-2 text-[12px] font-bold ${detailed === value ? 'bg-primary text-white' : 'bg-white text-primary-dark'}`} onClick={() => toggleView(value)}>{title}</button>)}</div><button type="button" className="hr-btn" onClick={toggleFullscreen}>{expanded ? <FaCompress /> : <FaExpand />}{expanded ? 'الخروج من ملء الشاشة' : 'ملء الشاشة'}</button>
        <details className="mr-auto max-w-[520px]"><summary className="font-bold text-primary cursor-pointer">مساعدة</summary><p className="leading-7 mt-2">الهوية والدوام من الموارد البشرية. كل شهر يحتفظ ببنوده ومعادلاته. الحفظ لا يصرف مالًا؛ الصرف ثم الاستلام إجراءان مستقلان. الشهر المعتمد يصحح بسبب صريح وتبقى نسخه السابقة. الفراغ ليس صفرًا.</p></details>
      </div>
      {period && <div className="p-4 space-y-4"><FilterBar search={{ value: query.q, onChange: v => change('q', v), placeholder: 'اسم العامل أو رقمه…' }} filters={[
        { key: 'body', value: query.body, onChange: v => change('body', v), placeholder: 'كل الهيئات', options: [{ value: 'educational', label: 'تعليمية' }, { value: 'administrative', label: 'إدارية' }, { value: 'unknown', label: 'غير محددة' }] },
        { key: 'college', value: query.college_id, onChange: v => change('college_id', v), placeholder: 'كل الكليات', options: (options?.colleges || []).map(c => ({ value: String(c.college_id), label: c.college_name })) },
        { key: 'unit', value: query.unit_id, onChange: v => change('unit_id', v), placeholder: 'كل الوحدات', options: (options?.units || []).map(c => ({ value: String(c.organizational_unit_id), label: c.unit_name })) },
        { key: 'payment', value: query.payment_status, onChange: v => change('payment_status', v), placeholder: 'كل حالات الصرف', options: Object.entries(statusNames).map(([value, label]) => ({ value, label })) },
        { key: 'completeness', value: query.completeness, onChange: v => change('completeness', v), placeholder: 'كل حالات الاستكمال', options: [{ value: 'complete', label: 'مكتملة' }, { value: 'incomplete', label: 'ناقصة أو بها خطأ' }, { value: 'warning', label: 'تحذير حسابي' }] },
      ]} hasActiveFilters={!!(query.q || query.body || query.college_id || query.unit_id || query.payment_status || query.completeness)} onClear={() => { if (!guarded && !editing) setParams({ period, ...(tab === 'reports' ? { tab } : {}) }) }} />
        {staleDraft && <Notice tone="warning">تغيرت مراجعة الشهر. احتفظنا بالقيم المقترحة ومراجعتها الأصلية؛ لم نربطها بالمراجعة الجديدة تلقائيًا. <button className="hr-btn" onClick={() => { if (window.confirm('تجاهل جميع المسودات بعد مراجعة البيانات الحالية؟')) { setDrafts({}); changed() } }}>تجاهل المسودات وعرض الحالة الحالية</button></Notice>}
        {Object.keys(drafts).length > 0 && <Notice tone="warning">القيم والمجاميع المقترحة معاينة لمسودات غير محفوظة؛ لا تصبح مستحقًا معتمدًا ولا تدخل التصدير قبل الحفظ.</Notice>}
        {historical && <Notice>هذه مراجعة سابقة للقراءة فقط؛ الصرف والاستلام المعروضان حالتهما المسجلة حاليًا لهذا الشهر.</Notice>}
        {report?.initialized && <div className="grid grid-cols-4 gap-3 max-[760px]:grid-cols-2 max-[390px]:grid-cols-1">{[['الصافي المستحق المحسوب', gridTotals.columns.total_net_payable?.sum], ['المصروف الفعلي', gridTotals.disbursed.sum], ['الاستلام الموثق', gridTotals.received.sum], ['المتبقي', gridTotals.remaining.sum]].map(([label, value]) => <div key={label} className="rounded-[12px] bg-primary/5 p-4"><p className="text-[11px] text-text-light">{label}</p><p className="font-bold mt-2">{money(value)}</p></div>)}</div>}
        {report && <p className="text-[11px] text-text-light">{report.meta.total} عامل ضمن المرشحات — {period}{report.revision ? ` — مراجعة ${report.revision}` : ' — لم تُعدّ الفترة بعد'} — حُسبت البيانات: <bdi>{report.generated_at}</bdi></p>}
        <div hidden={detailed}><DataTable columns={[...columns.filter(c => c.key !== 'due' || report?.config?.columns.find(item => item.key === 'total_net_payable')?.visible_grid !== false), ...(report?.config?.columns || []).filter(c => !c.is_system && c.kind !== undefined && c.visible_grid && c.compact).map(c => ({ key: c.key, header: c.label, render: row => row.cells[c.key]?.v == null ? 'لم يحدد' : formatValue(c.value_type, row.cells[c.key].v) }))]} rows={rows} rowKey={r => r.employee_id} loading={!matching && !error} emptyTitle="لا يوجد عاملون ضمن المرشحات" page={page} totalPages={report?.meta.last_page || 1} onPageChange={p => change('page', String(p))} /></div>
        {detailed && !matching && !error && <Notice>جارٍ تحميل الشهر…</Notice>}
        {report?.initialized && <MonthlyGridPanel rows={rows} config={report.config} totals={gridTotals} drafts={drafts} active={detailed} canEdit={!!(mutable && cap('amounts') && !busy && !uncertain && !monthConfig && !confirmation && !staleDraft && !editing && !formGuard.busy && !formGuard.dirty && !formGuard.uncertain)} canUndo={draftState.undo.length > 0} canRedo={draftState.redo.length > 0} onEdit={gridEdit} onUndo={() => draftDispatch({ type: 'undo' })} onRedo={() => draftDispatch({ type: 'redo' })} onSort={gridSort} sort={{ key: query.sort, direction: query.direction }} widths={widths} onWidthsChange={setWidths} onEditingChange={setGridEditing} onNotice={setNotice} onOpenWorker={row => navigate(`${accountingPath(office, row.employee_id)}?period=${encodeURIComponent(period)}`)} />}
        {detailed && report && <div className="flex flex-wrap items-center justify-center gap-3 text-[12px]"><button className="hr-btn" disabled={page <= 1 || guarded} onClick={() => change('page', String(page - 1))}>السابق</button><span>{page} / {report.meta.last_page} — {report.meta.total} عامل ضمن المرشحات</span><button className="hr-btn" disabled={page >= report.meta.last_page || guarded} onClick={() => change('page', String(page + 1))}>التالي</button></div>}
        {report?.revisions?.length > 0 && <details><summary className="cursor-pointer font-bold text-primary">التصحيحات والمراجعات المحفوظة</summary><div className="flex flex-wrap gap-2 mt-3"><button className="hr-btn" onClick={() => change('revision', '')}>الحالة الحالية</button>{report.revisions.map(r => <button key={r.revision} className="hr-btn" onClick={() => change('revision', String(r.revision))}>مراجعة {r.revision} — {r.reason}</button>)}</div></details>}
        {worker && <><section className="border-t border-primary/15 pt-4 space-y-3"><h2 className="text-[17px] font-bold">{worker.full_name} — تفاصيل بنود {period}</h2><p>الدوام الموثق من الموارد البشرية: {worker.work_time ? `أيام ${worker.work_time.days ?? 'غير محدد'} / ساعات ${worker.work_time.hours ?? 'غير محدد'} — ${worker.work_time.source_reference}` : 'غير متاح؛ لا يُستنتج من نوع الدوام'}</p><DataTable columns={[{ key: 'label', header: 'البند', render: column => column.label || 'غير محدد' }, { key: 'value', header: 'القيمة', render: c => worker.cells[c.key]?.v == null ? 'لم يحدد' : formatValue(c.value_type, worker.cells[c.key].v) }, { key: 'formula', header: 'طريقة الاحتساب', render: c => c.kind === 'formula' ? <span className="break-words max-w-[320px] block">{c.formula_display}</span> : 'مدخل صريح' }]} rows={report.config.columns} rowKey={c => c.key} emptyTitle="لا توجد بنود" /></section>{worker.id && <AccountingLedger key={`${worker.id}:${period}`} api={api} worker={worker} period={period} periodRevision={report.revision} canWrite={cap('payments') && !historical && report.status === 'approved' && tab === 'payroll'} disabled={guarded || Object.keys(drafts).length > 0 || !!editing} onGuard={setFormGuard} onAccessLost={clearSensitive} onChanged={changed} />}</>}
      </div>}{!period && <p className="p-8 text-text-light">اختر شهر الراتب لعرض جميع العاملين ومبالغهم وحالات الصرف والاستلام.</p>}
    </div>
    {editing && <MonthlyAmountsEditor row={editing.row} config={drafts[editing.row.employee_id]?.config || editing.config} existing={drafts[editing.row.employee_id]} onSave={d => { setDrafts(s => { const next = { ...s }; const keys = new Set([...Object.keys(d.row.inputs || {}), ...Object.keys(d.values)]); if (![...keys].some(k => (d.row.inputs?.[k] ?? null) !== (d.values[k] ?? null))) delete next[d.row.employee_id]; else next[d.row.employee_id] = { ...d, config: s[d.row.employee_id]?.config || editing.config, period_revision: s[d.row.employee_id]?.period_revision ?? report.revision }; return next }); setEditing(null) }} onClose={() => setEditing(null)} onGuard={setFormGuard} />}
    {confirmation && <MonthConfirmation context={confirmation} period={period} report={report} drafts={drafts} busy={busy} execute={execute} onClose={() => { if (!busy) setConfirmation(null) }} />}
    {monthConfig && configApi && <ColumnsDialog config={monthConfig} monthly={{ period, status: configSessionStatus }} employees={rawRows.map(r => ({ id: r.employee_id, label: `${r.employee_number} — ${r.full_name}` }))} canManage={cap('config') && (configSessionStatus !== 'approved' || cap('correct'))} ensureSaved={async () => Object.keys(drafts).length === 0 && !uncertain} onScopeChange={scopeChanged} onGuard={setFormGuard} onChanged={configChanged} onClose={() => { setMonthConfig(null); setConfigApi(null); configSession.current = null; setFormGuard({}); changed() }} />}
    {blocker.state === 'blocked' && <Dialog title="عملية أو مسودة مالية معلقة" onClose={() => blocker.reset()} footer={<><button className="hr-btn" onClick={() => blocker.reset()}>البقاء</button><button className="hr-btn primary" disabled={busy || formGuard.busy || !!monthConfig} onClick={() => { setDrafts({}); setEditing(null); setConfirmation(null); setUncertain(null); blocker.proceed() }}>تجاهل المسودة والانتقال</button></>}><p className="p-5">{monthConfig ? 'أغلق إعدادات البنود من نافذتها بعد حسم عمليتها قبل الانتقال.' : busy || formGuard.busy ? 'انتظر انتهاء الكتابة؛ لا يُكرر الطلب تلقائيًا.' : 'المغادرة تتجاهل المسودة. عند فقدان استجابة الكتابة راجع النتيجة المسجلة قبل طلب جديد.'}</p></Dialog>}
  </div></PayrollApiProvider>
}

function MonthConfirmation({ context, period, report, drafts, busy, execute, onClose }) {
  const [reason, setReason] = useState(''); const [confirmed, setConfirmed] = useState(false); const { action, request_id } = context
  function submit(e) {
    e.preventDefault(); if (!confirmed || busy) return
    const payload = { request_id, period, confirmed: true }
    if (action !== 'prepare') payload.revision = report.revision
    if (action === 'save') { payload.changes = Object.values(drafts).map(d => { const values = {}; for (const key of new Set([...Object.keys(d.row.inputs || {}), ...Object.keys(d.values)])) { if ((d.row.inputs?.[key] ?? null) !== (d.values[key] ?? null)) values[key] = d.values[key] ?? null }; return { employee_id: d.row.employee_id, hr_revision: d.row.current_hr_revision, entry_revision: d.row.entry_revision, values } }).filter(c => Object.keys(c.values).length); if (report.status === 'approved') payload.correction_reason = reason }
    execute(payload, action)
  }
  return <Dialog title={action === 'prepare' ? 'إعداد شهر الراتب' : action === 'approve' ? 'اعتماد مستحقات الشهر' : 'حفظ التغييرات المالية'} as="form" onSubmit={submit} onClose={onClose} footer={<><button type="button" className="hr-btn" disabled={busy} onClick={onClose}>إلغاء</button><button type="submit" className="hr-btn primary" disabled={busy || !confirmed}>{busy ? 'جارٍ الحفظ…' : 'تأكيد'}</button></>}><div className="p-5 space-y-4"><p className="font-bold">الفترة: <bdi>{period}</bdi></p>{action === 'save' ? <ul className="space-y-2">{Object.values(drafts).map(d => <li key={d.row.employee_id}>{d.row.full_name} — {d.row.employee_number}</li>)}</ul> : <p>{action === 'prepare' ? 'يُحفظ قالب الشهر وتبدأ مبالغه غير محددة؛ لا تُنسخ مبالغ سابقة.' : 'يُعتمد المستحق المحسوب بعد اكتمال البنود اللازمة، وتحفظ نسخة ثابتة. لا تنشأ دفعات أو إثباتات استلام.'}</p>}{action === 'save' && report.status === 'approved' && <label className="block font-bold">سبب تصحيح الشهر المعتمد<input className={inputClass} required value={reason} onChange={e => { setReason(e.target.value); setConfirmed(false) }} /></label>}<label className="flex gap-2 leading-7"><input type="checkbox" required checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />راجعت الفترة والعملية؛ الصرف والاستلام إجراءان منفصلان.</label></div></Dialog>
}
