import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useBlocker, useSearchParams } from 'react-router-dom'
import { FaUserPlus, FaLayerGroup, FaFileExcel, FaFilePdf, FaUndo, FaRedo, FaSpinner, FaCheckCircle, FaExclamationTriangle, FaColumns, FaSearch, FaTimes } from 'react-icons/fa'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { Notice, StatePanel } from '../../ministry-portal/components/MinistryUi'
import { canAccess, PERMISSIONS, ROLES } from '../../auth/auth'
import PayrollGrid from '../components/PayrollGrid'
import PayrollLegend from '../components/PayrollLegend'
import EmployeeDialog from '../components/EmployeeDialog'
import BodiesDialog from '../components/BodiesDialog'
import ColumnsDialog from '../components/ColumnsDialog'
import { usePayrollApi } from '../lib/PayrollApiContext'
import { PayrollSheetController } from '../lib/payrollSheetController'
import { WORKPLACE_OPTIONS, buildColumns, buildSheetQuery, emptyFilters, errorText, filtersFromParams, hasActiveFilters } from '../lib/payrollView'
import { formatSyp, formatValue } from '../lib/payrollMoney'

const BTN = 'inline-flex items-center gap-2 rounded-[10px] border px-3 py-1.5 text-[12.5px] font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50'
const PRIMARY = `${BTN} border-primary bg-primary text-white hover:bg-primary-dark`
const SECONDARY = `${BTN} border-primary/20 bg-white text-primary-dark hover:bg-primary/[0.07]`
const SELECT = 'rounded-[10px] border-[1.5px] border-primary/20 bg-white px-2.5 py-1.5 text-[12.5px] text-text-dark outline-none transition-colors focus:border-primary disabled:opacity-60'
const VIEW_KEY = 'owner-payroll-view'
const LEVEL_PREFIX = 'level:'
const NO_LEVEL = 'none'
const BLOCKED = 'لا يمكن تغيير الترتيب أو المرشحات قبل حلّ مشكلة الحفظ المعروضة.'

function SaveStatus({ status }) {
  if (status.resolving) return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-amber-700"><FaSpinner className="animate-spin" aria-hidden="true" />جارٍ تحميل القيم الحالية…</span>
  if (status.phase === 'saving') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-amber-700"><FaSpinner className="animate-spin" aria-hidden="true" />جارٍ الحفظ…</span>
  if (status.phase === 'failed') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-red-600"><FaExclamationTriangle aria-hidden="true" />فشل الحفظ</span>
  if (status.phase === 'conflict' || status.phase === 'config_conflict') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-amber-700"><FaExclamationTriangle aria-hidden="true" />تعارض في الحفظ</span>
  if (status.savedAt) return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-primary-dark"><FaCheckCircle aria-hidden="true" />تم الحفظ</span>
  return <span className="text-[12px] text-text-light">لا تعديلات معلّقة</span>
}

function saveBlob({ blob, filename }, fallback) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename || fallback
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 2000)
}

const readView = () => { try { return window.localStorage.getItem(VIEW_KEY) === 'compact' } catch { return false } }
const writeView = compact => { try { window.localStorage.setItem(VIEW_KEY, compact ? 'compact' : 'detailed') } catch { /* private mode: the choice just isn't remembered */ } }

export default function OwnerPayroll({ authorize }) {
  const { downloadPayrollExport, fetchPayrollOptions, fetchPayrollSheet, saveValueChanges } = usePayrollApi()
  const [params] = useSearchParams()
  const applied = useRef({ query: null }) // what the grid shows right now (read by the controller's reload)
  const controller = useMemo(() => new PayrollSheetController({
    saveValues: saveValueChanges,
    reload: async () => { const response = await fetchPayrollSheet(applied.current.query ?? ''); return { rows: response.data, config: response.config } },
  }), [fetchPayrollSheet, saveValueChanges])
  const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot)
  // `desired` is what the controls show; `shown` is the dataset on screen (grid, totals, scope label and exports all describe `shown`).
  const [desired, setDesired] = useState(() => filtersFromParams(params.toString()))
  const [searchText, setSearchText] = useState(() => desired.search)
  const [shown, setShown] = useState(null) // { query, filters, meta }
  const [options, setOptions] = useState({ bodies: [], workplaces: [], academic_levels: [] })
  const [optionsReady, setOptionsReady] = useState(false)
  const [load, setLoad] = useState({ state: 'loading', error: null })
  const [reloadKey, setReloadKey] = useState(0)
  const [dialog, setDialog] = useState(null) // { type: 'employee'|'bodies'|'columns', employee? }
  const [notice, setNotice] = useState(null) // { tone, text }
  const [pasteErrors, setPasteErrors] = useState(null)
  const [invalid, setInvalid] = useState(() => new Set())
  const [exporting, setExporting] = useState('')
  const [compact, setCompact] = useState(readView)
  const [selected, setSelected] = useState(null) // { id, prop } focused grid cell (formula bar)
  const selectCell = useCallback(next => setSelected(current => (current && current.id === next.id && current.prop === next.prop ? current : { id: next.id, prop: next.prop })), [])
  const [filtersOpen, setFiltersOpen] = useState(false) // narrow screens keep the filter selects behind one button
  const live = useRef(null)
  // Owner role AND the specific assigned permission (the central administrator keeps its existing authority).
  const allowed = permission => authorize ? authorize(permission) : canAccess({ allRoles: [ROLES.universityOwner], assignedPermissions: [PERMISSIONS.ownerPortalAccess, permission] })
  const canEdit = allowed(PERMISSIONS.ownerPayrollAmountsEdit)
  const canEmployees = allowed(PERMISSIONS.ownerPayrollEmployeesManage)
  const canBodies = allowed(PERMISSIONS.ownerPayrollBodiesManage)
  const canExport = allowed(PERMISSIONS.ownerPayrollExport)
  const canConfig = allowed(PERMISSIONS.ownerPayrollConfigManage)
  const desiredQuery = buildSheetQuery(desired)
  const blockedByError = ['failed', 'conflict', 'config_conflict'].includes(snapshot.status.phase)
  const announce = useCallback(text => { if (live.current) live.current.textContent = text }, [])
  const pendingQuery = shown !== null && shown.query !== desiredQuery // the controls are ahead of the grid (save in progress or unresolved)

  // Notices fade on their own so a stale message never sits above a different operation.
  useEffect(() => {
    if (!notice) return undefined
    const timer = setTimeout(() => setNotice(null), 10000)
    return () => clearTimeout(timer)
  }, [notice])

  // Search is applied after a short pause, like the other list pages.
  useEffect(() => {
    const timer = setTimeout(() => setDesired(f => (f.search === searchText.trim() ? f : { ...f, search: searchText.trim() })), 300)
    return () => clearTimeout(timer)
  }, [searchText])

  const refreshOptions = useCallback(async () => {
    const response = await fetchPayrollOptions()
    setOptions(response.data)
    setOptionsReady(true)
    return response.data
  }, [fetchPayrollOptions])
  useEffect(() => { refreshOptions().catch(error => setLoad(l => ({ ...l, state: 'error', error }))) }, [refreshOptions])

  // The dataset follows the controls, but only once pending saves are settled. If a save failed, the controls keep what the user chose
  // and the grid keeps showing the dataset the unsaved values belong to; the new query is applied as soon as the problem is resolved
  // (blockedByError flips back) — never silently dropped, and exports always describe `shown`.
  useEffect(() => {
    let active = true
    setLoad(l => ({ ...l, state: shown ? l.state : 'loading', error: null }))
    controller.flush().then(async ({ ok }) => {
      if (!active) return
      if (!ok) { setLoad(l => ({ ...l, state: shown ? 'ready' : 'error', error: shown ? null : new Error(BLOCKED) })); return }
      try {
        const response = await fetchPayrollSheet(desiredQuery)
        if (!active) return
        applied.current = { query: desiredQuery }
        controller.load(response.data, response.config)
        setShown({ query: desiredQuery, filters: desired, meta: response.meta })
        setLoad({ state: 'ready', error: null })
      } catch (error) { if (active) setLoad(l => ({ ...l, state: shown ? 'ready' : 'error', error })) }
    })
    return () => { active = false }
    // `shown`/`desired` are read only to label the result; the query string and the resolution state drive the fetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [desiredQuery, reloadKey, controller, blockedByError])

  // Leaving with unsaved or failed edits is guarded (reload and in-app navigation).
  useEffect(() => {
    const handler = event => { if (controller.unsavedCount > 0) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [controller])
  const blocker = useBlocker(() => controller.unsavedCount > 0)

  const setSort = key => {
    if (blockedByError) { setNotice({ tone: 'warning', text: BLOCKED }); return }
    setDesired(f => ({ ...f, sort: key, direction: f.sort === key && f.direction === 'asc' ? 'desc' : 'asc' }))
  }
  const setFilter = key => value => { if (blockedByError) { setNotice({ tone: 'warning', text: BLOCKED }); return } setDesired(f => ({ ...f, [key]: value })) }
  const setLevel = value => {
    if (blockedByError) { setNotice({ tone: 'warning', text: BLOCKED }); return }
    // "Not specified" is its own flag; the option values are prefixed so no level a user typed can ever equal it (or another option).
    setDesired(f => ({ ...f, academic_level: value.startsWith(LEVEL_PREFIX) ? value.slice(LEVEL_PREFIX.length) : '', academic_level_blank: value === NO_LEVEL }))
  }
  const clearFilters = () => { setSearchText(''); setDesired(f => ({ ...emptyFilters(), sort: f.sort, direction: f.direction })) }
  const toggleView = value => { setCompact(value); writeView(value) }

  const config = snapshot.config
  const columns = useMemo(() => (config ? buildColumns(config, { compact }) : []), [config, compact])

  // ── grid callbacks ──
  const flagInvalid = ids => { setInvalid(new Set(ids)); setTimeout(() => setInvalid(new Set()), 9000) }
  const handlePaste = (plan, rows) => {
    if (plan.errors.length) {
      setPasteErrors(plan.errors.map(e => ({ ...e, number: rows?.[e.row]?.employee_number })))
      flagInvalid(plan.errors.filter(e => e.id && e.prop).map(e => `${e.id}:${e.prop}`))
      announce('لم يُلصق شيء: توجد خلايا غير صالحة.')
      return
    }
    setPasteErrors(null)
    if (!plan.changes.length) { announce('اللصق لا يغيّر أي قيمة.'); return }
    controller.edit(plan.changes.map(({ id, key, value }) => ({ id, key, value })))
    announce(`تم لصق ${plan.changes.length} خلية.`)
  }
  const handleClear = (selection, order) => {
    if (!canEdit) return
    const rows = snapshot.rows.slice(selection.row, selection.row + selection.rows)
    const props = order.slice(selection.col, selection.col + selection.cols).filter(c => c.editable)
    const changes = rows.flatMap(row => props.map(c => ({ id: row.id, key: c.prop, value: null })))
    if (!changes.length) { announce('الخلايا المحددة محمية ولا يمكن مسحها.'); setNotice({ tone: 'warning', text: 'الخلايا المحددة محمية (بيانات الموظف أو أعمدة محسوبة) ولا تُمسح.' }); return }
    controller.edit(changes)
  }
  const handleInvalidEdit = ({ title, message }) => {
    setNotice({ tone: 'warning', text: `قيمة غير مقبولة في «${title}»: ${message} لم يُحفظ شيء؛ صحّح القيمة أو اضغط Esc للإلغاء.` })
    announce(`قيمة غير مقبولة: ${message}`)
  }

  // ── dialogs ──
  const closeDialog = () => setDialog(null)
  const onEmployeeSaved = async row => {
    setDialog(null)
    controller.upsertRow(row)
    setNotice({ tone: 'info', text: dialog?.employee ? 'تم حفظ بيانات الموظف.' : `تمت إضافة الموظف ${row.full_name} بمبالغ فارغة.` })
    const wasCreate = !dialog?.employee
    await refreshOptions().catch(() => {})
    if (wasCreate && hasActiveFilters(desired)) clearFilters() // show the new row even if the current filters would hide it
    else setReloadKey(k => k + 1)
  }

  // Column/config changes: the sheet is reloaded under the new configuration (calculated cells change for every row).
  const onConfigChanged = useCallback(async () => {
    const response = await fetchPayrollSheet(applied.current.query ?? '')
    controller.load(response.data, response.config)
    return response.config
  }, [controller, fetchPayrollSheet])
  const ensureSaved = useCallback(async () => (await controller.flush()).ok, [controller])

  // ── export: pending edits must be saved first; the file is built from the saved snapshot of the dataset ON SCREEN ──
  async function exportFile(kind) {
    setExporting(kind)
    setNotice(null)
    try {
      const { ok } = await controller.flush()
      if (!ok) { setNotice({ tone: 'warning', text: 'لا يمكن التصدير قبل حلّ مشكلة الحفظ المعروضة؛ لن يُصدَّر أي تعديل غير محفوظ.' }); return }
      const file = await downloadPayrollExport(kind, shown.query)
      saveBlob(file, `payroll-working-sheet.${kind}`)
      setNotice({ tone: 'info', text: `تم إنشاء ملف ${kind === 'xlsx' ? 'Excel' : 'PDF'} من البيانات المحفوظة (${scopeText}).` })
    } catch (error) {
      setNotice({ tone: 'warning', text: `تعذّر التصدير: ${errorText(error)}` })
    } finally { setExporting('') }
  }

  const rowsCount = snapshot.rows.length
  const totals = snapshot.totals
  const shownFilters = shown?.filters ?? desired
  const gridSort = useMemo(() => ({ key: shownFilters.sort, direction: shownFilters.direction }), [shownFilters.sort, shownFilters.direction])
  const filtered = hasActiveFilters(shownFilters)
  const scopeText = filtered ? `الصفوف المطابقة للمرشحات: ${rowsCount}` : `كل الصفوف: ${rowsCount}`
  const bodyOptions = options.bodies.map(body => ({ value: String(body.id), label: body.name + (body.is_active ? '' : ' (معطّلة)') }))
  const levelOptions = [...options.academic_levels.map(level => ({ value: `${LEVEL_PREFIX}${level}`, label: level })), { value: NO_LEVEL, label: 'غير محدد' }]
  const noBodies = optionsReady && options.bodies.length === 0
  const initialLoad = load.state === 'loading' && !shown
  const showGrid = shown && (rowsCount > 0 || filtered)
  const previewEmployees = useMemo(() => snapshot.rows.slice(0, 300).map(row => ({ id: row.id, label: `${row.employee_number} — ${row.full_name}` })), [snapshot.rows])
  const levelValue = desired.academic_level_blank ? NO_LEVEL : desired.academic_level ? `${LEVEL_PREFIX}${desired.academic_level}` : ''

  // Formula bar: the selected column's formula and the selected cell's value or reason.
  const selectedColumn = selected ? columns.find(c => c.prop === selected.prop) : null
  const selectedRow = selected ? snapshot.rows.find(r => r.id === selected.id) : null
  const selectedCell = selectedRow && selectedColumn && !selectedColumn.identity ? selectedRow.cells[selectedColumn.prop] : null

  const net = totals.columns.total_net_payable
  const netText = net ? formatSyp(net.sum) : ''

  return (
    <>
      <header className="relative mb-3 flex flex-wrap items-center justify-between gap-2 overflow-hidden rounded-[16px] border border-primary/15 bg-white px-4 py-2.5 shadow-[0_4px_24px_rgba(86,153,51,0.08)]" dir="rtl">
        <div className="absolute inset-x-0 top-0 h-1" style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327)' }} />
        <div className="min-w-0">
          <h2 className="text-[18px] font-black text-text-dark">الرواتب <span className="mr-2 inline-block text-[12px] font-medium text-text-light" dir="ltr">Payroll</span></h2>
          <p className="text-[11.5px] text-text-light">ورقة العمل الحالية · كل المبالغ بالليرة السورية ({`ل.س`}) · ليست سجلًا شهريًا</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {canEmployees && <button type="button" className={PRIMARY} onClick={() => setDialog({ type: 'employee' })}><FaUserPlus aria-hidden="true" />إضافة موظف</button>}
          {config && <button type="button" className={SECONDARY} onClick={() => setDialog({ type: 'columns' })}><FaColumns aria-hidden="true" />إدارة الأعمدة والمعادلات</button>}
          {canBodies && <button type="button" className={SECONDARY} onClick={() => setDialog({ type: 'bodies' })}><FaLayerGroup aria-hidden="true" />إدارة الهيئات</button>}
          {canExport && <button type="button" className={SECONDARY} disabled={Boolean(exporting) || load.state !== 'ready' || pendingQuery} onClick={() => exportFile('xlsx')}>{exporting === 'xlsx' ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFileExcel aria-hidden="true" />}Excel</button>}
          {canExport && <button type="button" className={SECONDARY} disabled={Boolean(exporting) || load.state !== 'ready' || pendingQuery} onClick={() => exportFile('pdf')}>{exporting === 'pdf' ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFilePdf aria-hidden="true" />}PDF</button>}
        </div>
      </header>

      <div ref={live} className="sr-only" role="status" aria-live="polite" />
      <div className="grid gap-3">
        {notice && <div role="status"><Notice tone={notice.tone === 'warning' ? 'warning' : 'info'}>{notice.text} <button type="button" className="mr-2 font-bold underline" onClick={() => setNotice(null)}>إخفاء</button></Notice></div>}

        {snapshot.status.resolveError && <div role="alert" className="rounded-[14px] border border-red-200 bg-red-50 px-4 py-2.5 text-[12.5px] font-semibold text-red-800">{snapshot.status.resolveError}</div>}
        {snapshot.status.phase === 'failed' && (
          <div role="alert" className="rounded-[14px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-7 text-red-800" dir="rtl">
            <p className="font-extrabold">فشل حفظ آخر عملية. قيمك ما زالت ظاهرة لكنها غير محفوظة.</p>
            <p>{snapshot.status.failure.message}</p>
            <p className="text-[12px]">إن لم تكن متأكدًا من وصول الطلب إلى الخادم، فإن «تجاهل تعديلاتي» يحمّل القيم الحالية من الخادم أولًا ولا يعرض قيمًا قديمة.</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {snapshot.status.failure.retryable && <button type="button" className={SECONDARY} onClick={() => controller.retry()}>إعادة المحاولة</button>}
              <button type="button" className={SECONDARY} disabled={snapshot.status.resolving} onClick={() => controller.useServer()}>تجاهل تعديلاتي غير المحفوظة</button>
            </div>
          </div>
        )}
        {snapshot.status.phase === 'conflict' && (
          <div role="alert" className="rounded-[14px] border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] leading-7 text-amber-900" dir="rtl">
            <p className="font-extrabold">تعارض: عُدّلت هذه الصفوف من جهة أخرى بعد تحميلها. لم يُحفظ شيء من عمليتك، وقيمك المعلّقة ما زالت ظاهرة بإطار كهرماني.</p>
            <ul className="my-2 list-inside list-disc text-[12.5px]">
              {snapshot.status.conflict.rows.map(row => (
                <li key={row.id}>{row.employee_number} — {row.full_name}: القيم الحالية في الخادم — {config.columns.filter(c => c.kind === 'input' && c.value_type !== 'text' && c.visible_grid).slice(0, 6).map(c => `${c.label} ${row.cells[c.key]?.v == null ? 'فارغ' : formatValue(c.value_type, row.cells[c.key].v)}`).join('، ')}</li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-2">
              <button type="button" className={PRIMARY} onClick={() => controller.keepMine()}>الاحتفاظ بقيمي وإعادة الحفظ</button>
              <button type="button" className={SECONDARY} disabled={snapshot.status.resolving} onClick={() => controller.useServer()}>اعتماد قيم الخادم وتجاهل تعديلاتي</button>
            </div>
          </div>
        )}
        {snapshot.status.phase === 'config_conflict' && (
          <div role="alert" className="rounded-[14px] border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] leading-7 text-amber-900" dir="rtl">
            <p className="font-extrabold">تغيّرت الأعمدة أو المعادلات أو الإعدادات بعد تحميل الصفحة. لم يُحفظ شيء من عمليتك، وقيمك المعلّقة ما زالت ظاهرة.</p>
            <p className="text-[12.5px]">يمكنك تحميل الإعدادات الحالية وإعادة تطبيق قيمك (القيم المخصصة لأعمدة لم تعد تقبل الإدخال ستُسقط وسيُبلَّغ بها)، أو تجاهل تعديلاتك.</p>
            <div className="mt-2 flex flex-wrap gap-2">
              <button type="button" className={PRIMARY} disabled={snapshot.status.resolving} onClick={async () => {
                const result = await controller.adoptConfigAndRetry()
                if (result.dropped?.length) setNotice({ tone: 'warning', text: `سُقطت قيم أعمدة لم تعد تقبل الإدخال: ${result.dropped.join('، ')}.` })
              }}>تحميل الإعدادات الحالية وإعادة قيمي</button>
              <button type="button" className={SECONDARY} disabled={snapshot.status.resolving} onClick={() => controller.useServer()}>تجاهل تعديلاتي</button>
            </div>
          </div>
        )}
        {pasteErrors && (
          <div role="alert" className="rounded-[14px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-7 text-red-800" dir="rtl">
            <p className="font-extrabold">لم يُلصق شيء: يوجد {pasteErrors.length} خلية غير صالحة. العملية كلها مرفوضة.</p>
            <ul className="my-1 max-h-32 list-inside list-disc overflow-y-auto text-[12.5px]">
              {pasteErrors.slice(0, 12).map((e, i) => <li key={i}>{e.number ? `الموظف ${e.number}` : `الصف ${e.row + 1}`} — العمود {e.col + 1}: {e.message}</li>)}
              {pasteErrors.length > 12 && <li>و{pasteErrors.length - 12} أخرى…</li>}
            </ul>
            <button type="button" className={SECONDARY} onClick={() => setPasteErrors(null)}>إخفاء</button>
          </div>
        )}

        {shownFilters.payroll_employee_id && <Notice tone="info" action={<button type="button" className={SECONDARY} disabled={blockedByError} onClick={() => setFilter('payroll_employee_id')('')}>عرض كل ملفات الرواتب</button>}>{shown?.meta?.scope_labels?.[0] || 'عرض ملف الرواتب المحدد'}</Notice>}

        {/* One compact toolbar: search, filters, view, save state, undo/redo. */}
        <div className="flex flex-wrap items-center gap-2" dir="rtl" role="toolbar" aria-label="أدوات الجدول" data-testid="payroll-toolbar">
          <div className="relative min-w-[220px] flex-1">
            <FaSearch className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[12px] text-primary-light" aria-hidden="true" />
            <input type="search" value={searchText} onChange={event => setSearchText(event.target.value)} aria-label="بحث في الرواتب" placeholder="ابحث برقم الموظف أو الاسم أو الصفة أو الهيئة…"
              className="w-full rounded-[10px] border-[1.5px] border-primary/20 bg-white py-1.5 pr-8 pl-3 text-[12.5px] text-text-dark outline-none transition-colors placeholder:text-text-light focus:border-primary" />
          </div>
          <button type="button" className={`${SECONDARY} min-[641px]:hidden`} aria-expanded={filtersOpen} onClick={() => setFiltersOpen(open => !open)}>المرشحات{hasActiveFilters(desired) ? ' •' : ''}</button>
          <div className={`${filtersOpen ? 'flex' : 'hidden'} flex-wrap items-center gap-2 min-[641px]:flex max-[640px]:w-full`} data-testid="filters">
          <select aria-label="الهيئة" className={SELECT} value={desired.body_id} onChange={event => setFilter('body_id')(event.target.value)} disabled={blockedByError}>
              <option value="">كل الهيئات</option>{bodyOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <select aria-label="مكان العمل" className={SELECT} value={desired.workplace} onChange={event => setFilter('workplace')(event.target.value)} disabled={blockedByError}>
              <option value="">كل أماكن العمل</option>{WORKPLACE_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <select aria-label="المستوى الأكاديمي" className={SELECT} value={levelValue} onChange={event => setLevel(event.target.value)} disabled={blockedByError}>
              <option value="">كل المستويات</option>{levelOptions.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
            <select aria-label="حالة الاكتمال" className={SELECT} value={desired.completeness} onChange={event => setFilter('completeness')(event.target.value)} disabled={blockedByError}>
              <option value="">كل الحالات</option><option value="incomplete">ناقصة أو بها خطأ</option><option value="warning">بها تحذير حسابي</option><option value="complete">مكتملة</option>
            </select>
          </div>
          {hasActiveFilters(desired) && <button type="button" className={`${BTN} border-red-300 bg-red-50 text-red-600 hover:bg-red-100`} onClick={clearFilters}><FaTimes aria-hidden="true" />مسح المرشحات</button>}
        </div>

        <div className="flex flex-wrap items-center gap-2" dir="rtl" role="toolbar" aria-label="العرض والحفظ" data-testid="payroll-toolbar-2">
          <div role="radiogroup" aria-label="طريقة العرض" className="inline-flex overflow-hidden rounded-[10px] border border-primary/25" data-testid="view-toggle">
            {[[true, 'عرض مختصر'], [false, 'عرض تفصيلي']].map(([value, label]) => (
              <button key={label} type="button" role="radio" aria-checked={compact === value} onClick={() => toggleView(value)}
                className={`px-3 py-1.5 text-[12px] font-bold focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary ${compact === value ? 'bg-primary text-white' : 'bg-white text-primary-dark hover:bg-primary/[0.07]'}`}>{label}</button>
            ))}
          </div>
          <SaveStatus status={snapshot.status} />
          {canEdit && <>
            <button type="button" className={SECONDARY} disabled={!snapshot.canUndo} onClick={() => controller.undo()} aria-label="تراجع (Ctrl+Z)"><FaUndo aria-hidden="true" />تراجع</button>
            <button type="button" className={SECONDARY} disabled={!snapshot.canRedo} onClick={() => controller.redo()} aria-label="إعادة (Ctrl+Y)"><FaRedo aria-hidden="true" />إعادة</button>
          </>}

          <div className="flex min-h-[38px] min-w-[260px] flex-1 flex-wrap items-center gap-x-4 gap-y-0.5 rounded-[10px] border border-primary/12 bg-white px-3 py-1 text-[12px]" dir="rtl" data-testid="formula-bar" aria-live="polite">
            <span className="font-black text-text-dark">{selectedColumn ? selectedColumn.title : 'الشريط الحسابي'}</span>
            {!selectedColumn && <span className="text-text-light">اختر خلية لعرض معادلتها وقيمتها.</span>}
            {selectedColumn?.computed && <span className="font-mono text-primary-dark" data-testid="formula-text">ƒ = {selectedColumn.definition.formula_display}</span>}
            {selectedColumn && !selectedColumn.computed && !selectedColumn.identity && <span className="text-text-light">إدخال يدوي{selectedColumn.definition.blank_as_zero && selectedColumn.type !== 'text' ? ' (الفراغ يُحسب صفرًا)' : ''}</span>}
            {selectedColumn?.identity && <span className="text-text-light">بيانات الموظف (تُعدَّل من نافذة الموظف).</span>}
            {selectedCell && (selectedCell.st === 'missing' || selectedCell.st === 'error' || selectedCell.st === 'warning') && <span className={`font-bold ${selectedCell.st === 'warning' ? 'text-amber-700' : selectedCell.st === 'error' ? 'text-red-700' : 'text-stone-600'}`} data-testid="cell-message">{selectedCell.m}</span>}
            {selectedCell && !selectedCell.st && selectedCell.v !== null && <span className="font-black text-primary-dark" dir="ltr" data-testid="cell-value">{formatValue(selectedColumn.type, selectedCell.v)}</span>}
          </div>
        </div>

        {pendingQuery && !blockedByError && <p role="status" className="text-[12px] font-semibold text-amber-700" data-testid="pending-query">جارٍ تطبيق المرشحات الجديدة بعد اكتمال الحفظ… الجدول والإجماليات والتصدير تصف الآن المرشحات السابقة.</p>}
        {pendingQuery && blockedByError && <p role="status" className="text-[12px] font-semibold text-amber-700" data-testid="pending-query">اخترت مرشحات جديدة؛ ستُطبَّق تلقائيًا بعد حلّ مشكلة الحفظ المعروضة. الجدول والإجماليات والتصدير تصف الآن المرشحات السابقة.</p>}

        {initialLoad ? <StatePanel state="loading" /> : load.state === 'error' && !shown ? <StatePanel state={load.error?.status === 403 ? 'forbidden' : 'error'} error={load.error} message={load.error?.status === 403 ? 'لا يملك حسابك صلاحية عرض ورقة الرواتب.' : errorText(load.error)} onRetry={() => setReloadKey(k => k + 1)} /> : (
          <>
            {noBodies && !filtered ? (
              <div className="rounded-[16px] border border-primary/12 bg-white py-12 text-center" dir="rtl" data-testid="empty-bodies">
                <p className="text-[15px] font-bold text-text-gray">لا توجد هيئات بعد</p>
                <p className="mx-auto mt-1 max-w-md text-[12.5px] leading-6 text-text-light">الهيئة تصنيف خاص بالرواتب. ابدأ بإنشاء أول هيئة، ثم أضف الموظفين.</p>
                {canBodies && <button type="button" className={`${PRIMARY} mt-4`} onClick={() => setDialog({ type: 'bodies' })}><FaLayerGroup aria-hidden="true" />إنشاء أول هيئة</button>}
              </div>
            ) : !showGrid ? (
              <div className="rounded-[16px] border border-primary/12 bg-white py-12 text-center" dir="rtl" data-testid="empty-employees">
                <p className="text-[15px] font-bold text-text-gray">لا يوجد موظفون في ورقة الرواتب بعد</p>
                <p className="mt-1 text-[12.5px] text-text-light">أضف موظفًا بخانات مالية فارغة، ثم أدخل المبالغ مباشرة في الجدول.</p>
                {canEmployees && <button type="button" className={`${PRIMARY} mt-4`} onClick={() => setDialog({ type: 'employee' })}><FaUserPlus aria-hidden="true" />إضافة موظف</button>}
              </div>
            ) : rowsCount === 0 ? (
              <div className="rounded-[16px] border border-primary/12 bg-white py-12 text-center" dir="rtl">
                <p className="text-[15px] font-bold text-text-gray">لا توجد نتائج مطابقة للمرشحات</p>
                <button type="button" className={`${SECONDARY} mt-3`} onClick={clearFilters}>مسح المرشحات</button>
              </div>
            ) : (
              <>
                <PayrollLegend columns={columns} canEdit={canEdit} />
                <PayrollGrid
                  snapshot={snapshot} columns={columns} totals={totals} sort={gridSort} canEdit={canEdit} controller={controller} invalid={invalid} announce={announce}
                  onSort={setSort} onEditMeta={row => canEmployees && setDialog({ type: 'employee', employee: row })} onFocusCell={selectCell}
                  onUndo={() => controller.undo()} onRedo={() => controller.redo()} onClear={handleClear} onPaste={handlePaste} onInvalidEdit={handleInvalidEdit}
                />
                <section aria-label="ملخص الجدول" className="flex flex-wrap items-center gap-x-5 gap-y-1 text-[12px]" dir="rtl" data-testid="payroll-totals">
                  <span className="font-bold text-text-dark" data-testid="totals-scope">{scopeText}{filtered ? ' — الإجماليات تتبع كل الصفوف المطابقة' : ''}</span>
                  {net && <span>إجمالي الصافي المستحق: <b className="text-primary-dark" dir="ltr" data-testid="total-net">{netText}</b></span>}
                  {totals.incomplete > 0 && <span className="font-bold text-amber-700" data-testid="totals-excluded">* {totals.incomplete} سجلًا غير مكتمل أو به خطأ مستثنى من المجاميع</span>}
                  {totals.warnings > 0 && <span className="text-amber-700">{totals.warnings} بها تحذير حسابي</span>}
                  <span className="text-text-light">الأسهم للتنقل · Tab وEnter · F2 للتحرير · Esc إلغاء · Delete مسح · Ctrl+C/V نسخ ولصق · Ctrl+Z/Y تراجع وإعادة</span>
                </section>
              </>
            )}
          </>
        )}
      </div>

      {dialog?.type === 'employee' && <EmployeeDialog key={dialog.employee?.id ?? 'new'} employee={dialog.employee ?? null} bodies={options.bodies} onSaved={onEmployeeSaved} onCancel={closeDialog} onManageBodies={() => setDialog({ type: 'bodies' })} />}
      {dialog?.type === 'bodies' && <BodiesDialog bodies={options.bodies} onChanged={() => refreshOptions().then(() => setReloadKey(k => k + 1))} onClose={closeDialog} />}
      {dialog?.type === 'columns' && config && <ColumnsDialog config={config} employees={previewEmployees} canManage={canConfig} ensureSaved={ensureSaved} onChanged={onConfigChanged} onClose={closeDialog} />}
      {blocker.state === 'blocked' && (
        <ManualGradeDialog title="تعديلات غير محفوظة" confirmTone="discard" confirmLabel="المغادرة وتجاهل التعديلات" onConfirm={() => blocker.proceed()} onCancel={() => blocker.reset()}>
          <p>توجد تعديلات في الرواتب لم يؤكد الخادم حفظها بعد. إن غادرت الآن فقد لا تُحفظ.</p>
        </ManualGradeDialog>
      )}
    </>
  )
}
