import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useBlocker, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { FaFileExcel, FaFilePdf, FaSave, FaCheckCircle, FaCog, FaEye, FaPen } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { canAccess, clearIdentity, getIdentity } from '../../auth/auth'
import { Dialog, Notice, inputClass } from '../../vice-presidency/components/GovernanceUi'
import OfficeHeading from '../../hr-office/components/OfficeHeading'
import { canHr } from '../../hr-office/lib/hrOffice'
import AccountingLedger from '../components/AccountingLedger'
import MonthlyAmountsEditor from '../components/MonthlyAmountsEditor'
import ColumnsDialog from '../components/ColumnsDialog'
import { PayrollApiProvider } from '../lib/PayrollApiContext'
import { accountingError, accountingPath, createMonthlyApi, FINANCE } from '../lib/monthlyApi'
import { formatSyp } from '../lib/payrollMoney'

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
  const query = useMemo(() => ({ period, employee_id: employee || '', q: params.get('q') || '', body: params.get('body') || '', college_id: params.get('college_id') || '', unit_id: params.get('unit_id') || '', payment_status: params.get('payment_status') || '', completeness: params.get('completeness') || '', revision: params.get('revision') || '', page }), [period, employee, params, page])
  const [data, setData] = useState(null); const [options, setOptions] = useState(null); const [error, setError] = useState(''); const [notice, setNotice] = useState(''); const [reload, setReload] = useState(0)
  const [drafts, setDrafts] = useState({}); const [editing, setEditing] = useState(null); const [confirmation, setConfirmation] = useState(null); const [formGuard, setFormGuard] = useState({})
  const [busy, setBusy] = useState(false); const [uncertain, setUncertain] = useState(null); const [globalConfig, setGlobalConfig] = useState(null)
  const [configApi, setConfigApi] = useState(null)
  const sequence = useRef(0); const busyRef = useRef(false); const lostAccess = useRef(false); const live = useRef(true); const accessEpoch = useRef(0)
  const dirty = Object.keys(drafts).length > 0 || formGuard.dirty || formGuard.uncertain || uncertain !== null || globalConfig !== null
  const blocker = useBlocker(({ currentLocation, nextLocation }) => {
    if (lostAccess.current) return false
    if (busyRef.current) return true
    const next = new URLSearchParams(nextLocation.search)
    return !!dirty && (currentLocation.pathname !== nextLocation.pathname || (next.get('period') || '') !== period || (next.get('tab') === 'reports' ? 'reports' : 'payroll') !== tab)
  })
  const clearSensitive = useCallback(e => { lostAccess.current = true; ++sequence.current; ++accessEpoch.current; setData(null); setOptions(null); setConfigApi(null); setDrafts({}); setEditing(null); setConfirmation(null); setGlobalConfig(null); setUncertain(null); setFormGuard({}); setError(accountingError(e)); if (e.status === 401) { clearIdentity(); navigate('/login', { replace: true }) } }, [navigate])
  useEffect(() => { live.current = true; return () => { live.current = false } }, [])
  useEffect(() => { if (!dirty && !busy) return undefined; const listener = e => { e.preventDefault(); e.returnValue = '' }; window.addEventListener('beforeunload', listener); return () => window.removeEventListener('beforeunload', listener) }, [dirty, busy])
  useEffect(() => {
    const abort = new AbortController(); let active = true; const epoch = accessEpoch.current
    api.read('months/options', {}, abort.signal).then(json => {
      if (!active || epoch !== accessEpoch.current) return
      setOptions(json.data)
      // Install the financial configuration adapter only after the server admitted this access epoch.
      setConfigApi(Object.fromEntries(Object.entries(api.config).map(([key, fn]) => [key, async (...args) => {
        const lease = accessEpoch.current
        try { const result = await fn(...args); if (lease !== accessEpoch.current || !live.current) throw new DOMException('استجابة قديمة بعد تغير التفويض', 'AbortError'); return result }
        catch (e) { if (lease !== accessEpoch.current || !live.current) throw new DOMException('استجابة قديمة', 'AbortError'); if ([401, 403].includes(e.status)) clearSensitive(e); throw e }
      }])))
    }).catch(e => { if (active && epoch === accessEpoch.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } })
    return () => { active = false; abort.abort() }
  }, [api, reload, clearSensitive])
  const requestKey = JSON.stringify([query, identity, reload]); const matching = data?.key === requestKey
  useEffect(() => {
    const seq = ++sequence.current; const abort = new AbortController(); let active = true
    if (period) api.read('months/report', query, abort.signal).then(json => { if (active && seq === sequence.current) { lostAccess.current = false; setData({ ...json.data, key: requestKey }); setError('') } }).catch(e => { if (active && seq === sequence.current && e.name !== 'AbortError') { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } })
    return () => { active = false; abort.abort() }
  }, [api, period, query, requestKey, clearSensitive])
  const report = matching ? data : null; const rows = report?.rows || []; const historical = !!report?.initialized && report.revision !== report.current_revision
  const cap = key => !!options?.capabilities?.[FINANCE[key]] && authorize(FINANCE[key])
  const mutable = tab === 'payroll' && report?.initialized && !historical && (report.status === 'draft' || cap('correct'))
  const staleDraft = Object.values(drafts).some(d => {
    const current = rows.find(r => r.employee_id === d.row.employee_id)
    return report && (d.period_revision !== report.revision || (current && (current.current_hr_revision !== d.row.current_hr_revision || current.entry_revision !== d.row.entry_revision)))
  })
  const guarded = busy || !!uncertain || !!globalConfig || formGuard.dirty || formGuard.busy || formGuard.uncertain || !!confirmation
  const changed = () => setReload(v => v + 1)
  function change(key, value) {
    if ((params.get(key) || '') === value) return
    if (guarded || editing) { setNotice('أكمل العملية الحالية أو أغلق المسودة أولًا.'); return }
    setParams(p => { const next = new URLSearchParams(p); if (value) next.set(key, value); else next.delete(key); if (key !== 'page') next.delete('page'); if (key === 'period') next.delete('revision'); return next })
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
    try { const file = await api.download(kind, query); if (!live.current || epoch !== accessEpoch.current || JSON.stringify(getIdentity()) !== identity) return; const url = URL.createObjectURL(file.blob); const link = document.createElement('a'); link.href = url; link.download = file.filename || `payroll-${period}.${kind}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 2000) }
    catch (e) { if (live.current && epoch === accessEpoch.current) { if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } } finally { busyRef.current = false; if (live.current) setBusy(false) }
  }
  async function openConfig() { if (guarded || dirty || !configApi) { setNotice('احفظ مسودات الشهر أو تجاهلها صراحة قبل فتح الإعدادات.'); return }; try { const json = await configApi.fetchPayrollConfig(); setGlobalConfig(json.data) } catch (e) { if (e.name === 'AbortError') return; if ([401, 403].includes(e.status)) clearSensitive(e); else setError(accountingError(e)) } }
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
  return <PayrollApiProvider value={configApi || api.config}><div dir="rtl" className="office-surface space-y-5">
    <OfficeHeading title={employee ? 'الملف المحاسبي للعامل' : 'مكتب المحاسبة — الرواتب الشهرية'} code="721" directorate="مديرية الشؤون المالية 72">{office === 'administrative' && canHr('view') && <Link className="hr-btn" to="/vp/administrative/hr">الموارد البشرية</Link>}{employee && <Link className="hr-btn" to={`${accountingPath(office)}?period=${encodeURIComponent(period)}`}>جميع العاملين</Link>}{cap('config') && <button className="hr-btn" onClick={openConfig}><FaCog />إعدادات البنود والمعادلات</button>}</OfficeHeading>
    {error && <Notice tone="error">{error}<button className="hr-btn mr-2" disabled={guarded} onClick={changed}>إعادة تحميل القراءة</button></Notice>}{notice && <Notice onDismiss={() => setNotice('')}>{notice}</Notice>}
    {uncertain && <Notice tone="warning">نتيجة الكتابة غير مؤكدة. المسودة محفوظة في هذه الصفحة، ولم يُكرر الطلب.<button className="hr-btn mr-2" disabled={busy} onClick={reconcile}>التحقق من النتيجة المسجلة</button></Notice>}
    <div className="office-frame"><div className="flex gap-1 overflow-x-auto border-b border-primary/15" role="tablist" aria-label="المحاسبة">{[['payroll', 'الرواتب الشهرية'], ['reports', 'التقارير']].map(([key, title]) => <button key={key} role="tab" aria-selected={tab === key} className="hr-tab" onClick={() => { if (key !== tab) change('tab', key) }}>{title}</button>)}</div>
      <div className="office-toolbar"><label className="flex items-center gap-2 font-bold">شهر الراتب<input type="month" className="office-input" value={period} disabled={guarded || !!editing} onChange={e => change('period', e.target.value)} /></label>{options?.periods.length > 0 && <select aria-label="فتح شهر محفوظ" className="office-input" value={options.periods.some(p => p.period === period) ? period : ''} disabled={guarded || !!editing} onChange={e => change('period', e.target.value)}><option value="">الأشهر المحفوظة</option>{options.periods.map(p => <option key={p.period} value={p.period}>{p.period} — {p.status === 'approved' ? 'معتمد' : 'مسودة'}</option>)}</select>}
        {report && !report.initialized && cap('periods') && <button className="hr-btn primary" disabled={guarded} onClick={() => confirm('prepare')}>إعداد الشهر بمبالغ غير محددة</button>}{mutable && cap('amounts') && <button className="hr-btn primary" disabled={guarded || staleDraft || Object.keys(drafts).length === 0} onClick={() => confirm('save')}><FaSave />حفظ التغييرات ({Object.keys(drafts).length})</button>}{report?.status === 'draft' && tab === 'payroll' && cap('periods') && <button className="hr-btn" disabled={guarded || dirty} onClick={() => confirm('approve')}><FaCheckCircle />اعتماد مستحقات الشهر</button>}{cap('export') && report?.initialized && <><button className="hr-btn" disabled={guarded || dirty} onClick={() => download('pdf')}><FaFilePdf />PDF</button><button className="hr-btn" disabled={guarded || dirty} onClick={() => download('xlsx')}><FaFileExcel />Excel</button></>}
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
        {historical && <Notice>هذه مراجعة سابقة للقراءة فقط؛ الصرف والاستلام المعروضان حالتهما المسجلة حاليًا لهذا الشهر.</Notice>}
        {report?.initialized && <div className="grid grid-cols-4 gap-3 max-[760px]:grid-cols-2 max-[390px]:grid-cols-1">{[['الصافي المستحق المحسوب', report.totals.columns.total_net_payable?.sum], ['المصروف الفعلي', report.totals.disbursed.sum], ['الاستلام الموثق', report.totals.received.sum], ['المتبقي', report.totals.remaining.sum]].map(([label, value]) => <div key={label} className="rounded-[12px] bg-primary/5 p-4"><p className="text-[11px] text-text-light">{label}</p><p className="font-bold mt-2">{money(value)}</p></div>)}</div>}
        {report && <p className="text-[11px] text-text-light">{report.meta.total} عامل ضمن المرشحات — {period}{report.revision ? ` — مراجعة ${report.revision}` : ' — لم تُعدّ الفترة بعد'} — حُسبت البيانات: <bdi>{report.generated_at}</bdi></p>}
        <DataTable columns={columns} rows={rows} rowKey={r => r.employee_id} loading={!matching && !error} emptyTitle="لا يوجد عاملون ضمن المرشحات" page={page} totalPages={report?.meta.last_page || 1} onPageChange={p => change('page', String(p))} />
        {report?.revisions?.length > 0 && <details><summary className="cursor-pointer font-bold text-primary">التصحيحات والمراجعات المحفوظة</summary><div className="flex flex-wrap gap-2 mt-3"><button className="hr-btn" onClick={() => change('revision', '')}>الحالة الحالية</button>{report.revisions.map(r => <button key={r.revision} className="hr-btn" onClick={() => change('revision', String(r.revision))}>مراجعة {r.revision} — {r.reason}</button>)}</div></details>}
        {worker && <><section className="border-t border-primary/15 pt-4 space-y-3"><h2 className="text-[17px] font-bold">{worker.full_name} — تفاصيل بنود {period}</h2><p>الدوام الموثق من الموارد البشرية: {worker.work_time ? `أيام ${worker.work_time.days ?? 'غير محدد'} / ساعات ${worker.work_time.hours ?? 'غير محدد'} — ${worker.work_time.source_reference}` : 'غير متاح؛ لا يُستنتج من نوع الدوام'}</p><DataTable columns={[{ key: 'label', header: 'البند', render: column => column.label || 'غير محدد' }, { key: 'value', header: 'القيمة', render: c => c.value_type === 'amount' ? money(worker.cells[c.key]?.v) : worker.cells[c.key]?.v ?? 'لم يحدد' }, { key: 'formula', header: 'طريقة الاحتساب', render: c => c.kind === 'formula' ? <span className="break-words max-w-[320px] block">{c.formula_display}</span> : 'مدخل صريح' }]} rows={report.config.columns} rowKey={c => c.key} emptyTitle="لا توجد بنود" /></section>{worker.id && <AccountingLedger key={`${worker.id}:${period}`} api={api} worker={worker} period={period} periodRevision={report.revision} canWrite={cap('payments') && !historical && report.status === 'approved' && tab === 'payroll'} disabled={guarded || Object.keys(drafts).length > 0 || !!editing} onGuard={setFormGuard} onAccessLost={clearSensitive} onChanged={changed} />}</>}
      </div>}{!period && <p className="p-8 text-text-light">اختر شهر الراتب لعرض جميع العاملين ومبالغهم وحالات الصرف والاستلام.</p>}
    </div>
    {editing && <MonthlyAmountsEditor row={editing.row} config={editing.config} existing={drafts[editing.row.employee_id]} onSave={d => { setDrafts(s => { const next = { ...s }; const keys = new Set([...Object.keys(d.row.inputs || {}), ...Object.keys(d.values)]); if (![...keys].some(k => (d.row.inputs?.[k] ?? null) !== (d.values[k] ?? null))) delete next[d.row.employee_id]; else next[d.row.employee_id] = { ...d, period_revision: s[d.row.employee_id]?.period_revision ?? report.revision }; return next }); setEditing(null) }} onClose={() => setEditing(null)} onGuard={setFormGuard} />}
    {confirmation && <MonthConfirmation context={confirmation} period={period} report={report} drafts={drafts} busy={busy} execute={execute} onClose={() => { if (!busy) setConfirmation(null) }} />}
    {globalConfig && <ColumnsDialog config={globalConfig} employees={[]} canManage={cap('config')} ensureSaved={async () => Object.keys(drafts).length === 0 && !formGuard.dirty && !uncertain} onChanged={async () => { const json = await configApi.fetchPayrollConfig(); setGlobalConfig(json.data); setNotice('تم تحديث القالب للفترات الجديدة؛ لم تتغير بيانات الشهر المحفوظ.') }} onClose={() => setGlobalConfig(null)} />}
    {blocker.state === 'blocked' && <Dialog title="عملية أو مسودة مالية معلقة" onClose={() => blocker.reset()} footer={<><button className="hr-btn" onClick={() => blocker.reset()}>البقاء</button><button className="hr-btn primary" disabled={busy || formGuard.busy || !!globalConfig} onClick={() => { setDrafts({}); setEditing(null); setConfirmation(null); setUncertain(null); blocker.proceed() }}>تجاهل المسودة والانتقال</button></>}><p className="p-5">{globalConfig ? 'أغلق إعدادات البنود من نافذتها بعد حسم عمليتها قبل الانتقال.' : busy || formGuard.busy ? 'انتظر انتهاء الكتابة؛ لا يُكرر الطلب تلقائيًا.' : 'المغادرة تتجاهل المسودة. عند فقدان استجابة الكتابة راجع النتيجة المسجلة قبل طلب جديد.'}</p></Dialog>}
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
