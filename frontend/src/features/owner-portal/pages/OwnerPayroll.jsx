import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useBlocker } from 'react-router-dom'
import { FaUserPlus, FaLayerGroup, FaFileExcel, FaFilePdf, FaUndo, FaRedo, FaSpinner, FaCheckCircle, FaExclamationTriangle } from 'react-icons/fa'
import FilterBar from '../../../components/table/FilterBar'
import ManualGradeDialog from '../../exam-board/components/ManualGradeDialog'
import { Notice, PageHeader, StatePanel } from '../../ministry-portal/components/MinistryUi'
import { canAccess, PERMISSIONS, ROLES } from '../../auth/auth'
import PayrollGrid from '../components/PayrollGrid'
import EmployeeDialog from '../components/EmployeeDialog'
import BodiesDialog from '../components/BodiesDialog'
import { downloadPayrollExport, fetchPayrollOptions, fetchPayrollSheet, saveAmountChanges } from '../lib/ownerApi'
import { PayrollSheetController } from '../lib/payrollSheetController'
import { COLUMNS, WORKPLACE_OPTIONS, buildSheetQuery, emptyFilters, errorText, hasActiveFilters } from '../lib/payrollView'
import { formatMoney } from '../lib/payrollMoney'

const BTN = 'inline-flex items-center gap-2 rounded-[10px] border px-4 py-2.5 text-[12.5px] font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50'
const PRIMARY = `${BTN} border-primary bg-primary text-white hover:bg-primary-dark`
const SECONDARY = `${BTN} border-primary/20 bg-white text-primary-dark hover:bg-primary/[0.07]`
const MIN_BLOCKING = 'لا يمكن تغيير الترتيب أو المرشحات قبل حلّ مشكلة الحفظ المعروضة.'

function SaveStatus({ status }) {
  if (status.phase === 'saving') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-amber-700"><FaSpinner className="animate-spin" aria-hidden="true" />جارٍ الحفظ…</span>
  if (status.phase === 'failed') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-red-600"><FaExclamationTriangle aria-hidden="true" />فشل الحفظ</span>
  if (status.phase === 'conflict') return <span className="inline-flex items-center gap-1.5 text-[12px] font-bold text-amber-700"><FaExclamationTriangle aria-hidden="true" />تعارض في الحفظ</span>
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

export default function OwnerPayroll() {
  const controller = useMemo(() => new PayrollSheetController({ saveAmounts: saveAmountChanges }), [])
  const snapshot = useSyncExternalStore(controller.subscribe, controller.getSnapshot)
  const [filters, setFilters] = useState(emptyFilters)
  const [searchText, setSearchText] = useState('')
  const [options, setOptions] = useState({ bodies: [], workplaces: [], academic_levels: [] })
  const [optionsReady, setOptionsReady] = useState(false)
  const [load, setLoad] = useState({ state: 'loading', error: null, meta: null, query: null })
  const [reloadKey, setReloadKey] = useState(0)
  const [dialog, setDialog] = useState(null) // { type: 'employee'|'bodies', employee? }
  const [notice, setNotice] = useState(null) // { tone, text }
  const [pasteErrors, setPasteErrors] = useState(null)
  const [invalid, setInvalid] = useState(() => new Set())
  const [exporting, setExporting] = useState('')
  const live = useRef(null)
  // Owner role AND the specific assigned permission (the central administrator keeps its existing authority).
  const allowed = permission => canAccess({ allRoles: [ROLES.universityOwner], assignedPermissions: [PERMISSIONS.ownerPortalAccess, permission] })
  const canEdit = allowed(PERMISSIONS.ownerPayrollAmountsEdit)
  const canEmployees = allowed(PERMISSIONS.ownerPayrollEmployeesManage)
  const canBodies = allowed(PERMISSIONS.ownerPayrollBodiesManage)
  const canExport = allowed(PERMISSIONS.ownerPayrollExport)
  const query = buildSheetQuery(filters)
  const blockedByError = snapshot.status.phase === 'failed' || snapshot.status.phase === 'conflict'
  const announce = useCallback(text => { if (live.current) live.current.textContent = text }, [])

  // Warnings fade on their own so a stale message never sits above a different operation.
  useEffect(() => {
    if (!notice) return undefined
    const timer = setTimeout(() => setNotice(null), 10000)
    return () => clearTimeout(timer)
  }, [notice])

  // Search is applied after a short pause, like the other list pages.
  useEffect(() => {
    const timer = setTimeout(() => setFilters(f => (f.search === searchText.trim() ? f : { ...f, search: searchText.trim() })), 300)
    return () => clearTimeout(timer)
  }, [searchText])

  const refreshOptions = useCallback(async () => {
    const response = await fetchPayrollOptions()
    setOptions(response.data)
    setOptionsReady(true)
    return response.data
  }, [])
  useEffect(() => { refreshOptions().catch(error => setLoad(l => ({ ...l, state: 'error', error }))) }, [refreshOptions])

  // Sheet: every filter/sort change refetches from the server (so grid, totals and exports share one order).
  // Pending edits are flushed first; unresolved failures keep the user on the current view.
  useEffect(() => {
    let active = true
    setLoad(l => ({ ...l, state: 'loading', error: null }))
    controller.flush().then(async ({ ok }) => {
      if (!active) return
      if (!ok) { setLoad(l => ({ ...l, state: l.meta ? 'ready' : 'error', error: l.meta ? null : new Error(MIN_BLOCKING) })); return }
      try {
        const response = await fetchPayrollSheet(query)
        if (!active) return
        controller.load(response.data)
        setLoad({ state: 'ready', error: null, meta: response.meta, query })
      } catch (error) { if (active) setLoad(l => ({ ...l, state: 'error', error })) }
    })
    return () => { active = false }
  }, [query, reloadKey, controller])

  // Leaving with unsaved or failed edits is guarded (reload and in-app navigation).
  useEffect(() => {
    const handler = event => { if (controller.unsavedCount > 0) { event.preventDefault(); event.returnValue = '' } }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [controller])
  const blocker = useBlocker(() => controller.unsavedCount > 0)

  const setSort = key => {
    if (blockedByError) { setNotice({ tone: 'warning', text: MIN_BLOCKING }); return }
    setFilters(f => ({ ...f, sort: key, direction: f.sort === key && f.direction === 'asc' ? 'desc' : 'asc' }))
  }
  const setFilter = key => value => { if (blockedByError) { setNotice({ tone: 'warning', text: MIN_BLOCKING }); return } setFilters(f => ({ ...f, [key]: value })) }
  const clearFilters = () => { setSearchText(''); setFilters(f => ({ ...emptyFilters(), sort: f.sort, direction: f.direction })) }

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
    controller.edit(plan.changes.map(({ id, field, cents }) => ({ id, field, cents })))
    announce(`تم لصق ${plan.changes.length} خلية.`)
  }
  const handleClear = selection => {
    if (!canEdit) return
    const rows = snapshot.rows.slice(selection.row, selection.row + selection.rows)
    const props = COLUMNS.slice(selection.col, selection.col + selection.cols).filter(c => c.editable)
    const changes = rows.flatMap(row => props.map(c => ({ id: row.id, field: c.prop, cents: null })))
    if (!changes.length) { announce('الخلايا المحددة محمية ولا يمكن مسحها.'); setNotice({ tone: 'warning', text: 'الخلايا المحددة محمية (بيانات الموظف أو المستحق المحسوب) ولا تُمسح.' }); return }
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
    if (wasCreate && hasActiveFilters(filters)) {
      // Show the new row immediately even if the current filters would hide it.
      clearFilters()
    } else setReloadKey(k => k + 1)
  }

  // ── export: pending edits must be saved first; the file is built from the saved snapshot ──
  async function exportFile(kind) {
    setExporting(kind)
    setNotice(null)
    try {
      const { ok } = await controller.flush()
      if (!ok) { setNotice({ tone: 'warning', text: 'لا يمكن التصدير قبل حلّ مشكلة الحفظ المعروضة؛ لن يُصدَّر أي تعديل غير محفوظ.' }); return }
      const file = await downloadPayrollExport(kind, query)
      saveBlob(file, `payroll-working-sheet.${kind}`)
      setNotice({ tone: 'info', text: `تم إنشاء ملف ${kind === 'xlsx' ? 'Excel' : 'PDF'} من البيانات المحفوظة (${scopeText}).` })
    } catch (error) {
      setNotice({ tone: 'warning', text: `تعذّر التصدير: ${errorText(error)}` })
    } finally { setExporting('') }
  }

  const rowsCount = snapshot.rows.length
  const totals = snapshot.totals
  const filtered = hasActiveFilters(filters)
  const scopeText = filtered ? `الصفوف المطابقة للمرشحات الحالية: ${rowsCount}` : `كل الصفوف: ${rowsCount}`
  const bodyOptions = options.bodies.map(body => ({ value: String(body.id), label: body.name + (body.is_active ? '' : ' (معطّلة)') }))
  const levelOptions = [...options.academic_levels.map(level => ({ value: level, label: level })), { value: '__blank__', label: 'غير محدد' }]
  const noBodies = optionsReady && options.bodies.length === 0
  const initialLoad = load.state === 'loading' && !load.meta
  const showGrid = load.meta && (rowsCount > 0 || filtered)

  return (
    <>
      <PageHeader title="الرواتب" en="Payroll" subtitle="ورقة العمل الحالية: تعديل مباشر على الجدول، والمستحق يُحسب تلقائيًا. ليست سجلًا شهريًا.">
        {canEmployees && <button type="button" className={PRIMARY} onClick={() => setDialog({ type: 'employee' })}><FaUserPlus aria-hidden="true" />إضافة موظف</button>}
        {canBodies && <button type="button" className={SECONDARY} onClick={() => setDialog({ type: 'bodies' })}><FaLayerGroup aria-hidden="true" />إدارة الهيئات</button>}
        {canExport && <button type="button" className={SECONDARY} disabled={Boolean(exporting) || load.state !== 'ready'} onClick={() => exportFile('xlsx')}>{exporting === 'xlsx' ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFileExcel aria-hidden="true" />}تصدير Excel</button>}
        {canExport && <button type="button" className={SECONDARY} disabled={Boolean(exporting) || load.state !== 'ready'} onClick={() => exportFile('pdf')}>{exporting === 'pdf' ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFilePdf aria-hidden="true" />}تصدير PDF</button>}
      </PageHeader>

      <div ref={live} className="sr-only" role="status" aria-live="polite" />
      <div className="grid gap-4">
        {notice && <div role="status"><Notice tone={notice.tone === 'warning' ? 'warning' : 'info'}>{notice.text} <button type="button" className="mr-2 font-bold underline" onClick={() => setNotice(null)}>إخفاء</button></Notice></div>}

        {snapshot.status.phase === 'failed' && (
          <div role="alert" className="rounded-[14px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-7 text-red-800" dir="rtl">
            <p className="font-extrabold">فشل حفظ آخر عملية. قيمك ما زالت ظاهرة لكنها غير محفوظة.</p>
            <p>{snapshot.status.failure.message}</p>
            <div className="mt-2 flex flex-wrap gap-2">
              {snapshot.status.failure.retryable && <button type="button" className={SECONDARY} onClick={() => controller.retry()}>إعادة المحاولة</button>}
              <button type="button" className={SECONDARY} onClick={() => controller.useServer()}>تجاهل تعديلاتي غير المحفوظة</button>
            </div>
          </div>
        )}
        {snapshot.status.phase === 'conflict' && (
          <div role="alert" className="rounded-[14px] border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] leading-7 text-amber-900" dir="rtl">
            <p className="font-extrabold">تعارض: عُدّلت هذه الصفوف من جهة أخرى بعد تحميلها. لم يُحفظ شيء من عمليتك، وقيمك المعلّقة ما زالت ظاهرة بإطار كهرماني.</p>
            <ul className="my-2 list-inside list-disc text-[12.5px]">
              {snapshot.status.conflict.rows.map(row => (
                <li key={row.id}>{row.employee_number} — {row.full_name}: القيم الحالية في الخادم — الراتب {row.fixed_salary === null ? 'فارغ' : formatMoney(Math.round(Number(row.fixed_salary) * 100))}، الاقتطاع {row.deduction === null ? 'فارغ' : formatMoney(Math.round(Number(row.deduction) * 100))}، التعويض {row.compensation === null ? 'فارغ' : formatMoney(Math.round(Number(row.compensation) * 100))}</li>
              ))}
            </ul>
            <div className="flex flex-wrap gap-2">
              <button type="button" className={PRIMARY} onClick={() => controller.keepMine()}>الاحتفاظ بقيمي وإعادة الحفظ</button>
              <button type="button" className={SECONDARY} onClick={() => controller.useServer()}>اعتماد قيم الخادم وتجاهل تعديلاتي</button>
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

        <FilterBar
          search={{ value: searchText, onChange: setSearchText, placeholder: 'ابحث برقم الموظف أو الاسم أو الصفة أو الهيئة أو مكان العمل…' }}
          filters={[
            { key: 'body', value: filters.body_id, onChange: setFilter('body_id'), placeholder: 'كل الهيئات', options: bodyOptions, minWidth: 170 },
            { key: 'workplace', value: filters.workplace, onChange: setFilter('workplace'), placeholder: 'كل أماكن العمل', options: WORKPLACE_OPTIONS, minWidth: 170 },
            { key: 'level', value: filters.academic_level, onChange: setFilter('academic_level'), placeholder: 'كل المستويات الأكاديمية', options: levelOptions, minWidth: 190 },
          ]}
          hasActiveFilters={filtered}
          onClear={clearFilters}
          disabled={blockedByError}
        />

        {initialLoad ? <StatePanel state="loading" /> : load.state === 'error' && !load.meta ? <StatePanel state={load.error?.status === 403 ? 'forbidden' : 'error'} error={load.error} message={load.error?.status === 403 ? 'لا يملك حسابك صلاحية عرض ورقة الرواتب.' : errorText(load.error)} onRetry={() => setReloadKey(k => k + 1)} /> : (
          <>
            <section aria-label="إجماليات الصفوف المطابقة" className="rounded-[16px] border border-primary/12 bg-white px-5 py-4 shadow-[0_2px_16px_rgba(26,46,16,0.06)]" dir="rtl" data-testid="payroll-totals">
              <div className="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                <p className="text-[12.5px] font-extrabold text-text-dark" data-testid="totals-scope">الإجماليات — {scopeText}{filtered ? ' (تتبع المرشحات وتشمل كل الصفوف المطابقة وليس المعروضة فقط)' : ''}</p>
                <div className="flex flex-wrap items-center gap-3">
                  <SaveStatus status={snapshot.status} />
                  {canEdit && <>
                    <button type="button" className={SECONDARY} disabled={!snapshot.canUndo} onClick={() => controller.undo()} aria-label="تراجع (Ctrl+Z)"><FaUndo aria-hidden="true" />تراجع</button>
                    <button type="button" className={SECONDARY} disabled={!snapshot.canRedo} onClick={() => controller.redo()} aria-label="إعادة (Ctrl+Y)"><FaRedo aria-hidden="true" />إعادة</button>
                  </>}
                </div>
              </div>
              <dl className="grid grid-cols-4 gap-3 max-[820px]:grid-cols-2">
                {[['fixed_salary', 'الراتب المقطوع $'], ['deduction', 'الاقتطاع $'], ['compensation', 'التعويض $'], ['payable', 'المستحق $']].map(([key, label]) => (
                  <div key={key} className="rounded-[12px] border border-primary/10 bg-primary/[0.03] px-3 py-2">
                    <dt className="text-[11px] font-bold text-text-light">{label}</dt>
                    <dd className={`text-[18px] font-black leading-7 ${totals[key] < 0 ? 'text-red-700' : 'text-primary-dark'}`} dir="ltr" data-testid={`total-${key}`}>{formatMoney(totals[key])}</dd>
                  </div>
                ))}
              </dl>
              <p className="mt-2 text-[11px] leading-5 text-text-light">المستحق = الراتب المقطوع − الاقتطاع + التعويض (الفارغ في الاقتطاع/التعويض = صفر، ويبقى المستحق فارغًا بلا راتب مقطوع). إجمالي المستحق يجمع الصفوف التي لها راتب مقطوع فقط.</p>
            </section>

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
                <PayrollGrid
                  snapshot={snapshot} sort={{ key: filters.sort, direction: filters.direction }} canEdit={canEdit} controller={controller} invalid={invalid} announce={announce}
                  onSort={setSort} onEditMeta={row => canEmployees && setDialog({ type: 'employee', employee: row })}
                  onUndo={() => controller.undo()} onRedo={() => controller.redo()} onClear={handleClear} onPaste={handlePaste} onInvalidEdit={handleInvalidEdit}
                />
                <p className="text-[11.5px] leading-6 text-text-light" dir="rtl">
                  الأسهم للتنقل · Tab / Shift+Tab وEnter / Shift+Enter للانتقال · الكتابة أو النقر المزدوج أو F2 للتحرير · Esc للإلغاء · Delete للمسح · Ctrl+C / Ctrl+V نسخ ولصق من Excel · Ctrl+Z / Ctrl+Y تراجع وإعادة. التحديد المستطيل لا يعبر الحد بين العمودين الثابتين وبقية الأعمدة.
                </p>
              </>
            )}
          </>
        )}
      </div>

      {dialog?.type === 'employee' && <EmployeeDialog key={dialog.employee?.id ?? 'new'} employee={dialog.employee ?? null} bodies={options.bodies} onSaved={onEmployeeSaved} onCancel={closeDialog} onManageBodies={() => setDialog({ type: 'bodies' })} />}
      {dialog?.type === 'bodies' && <BodiesDialog bodies={options.bodies} onChanged={() => refreshOptions().then(() => setReloadKey(k => k + 1))} onClose={closeDialog} />}
      {blocker.state === 'blocked' && (
        <ManualGradeDialog title="تعديلات غير محفوظة" confirmTone="discard" confirmLabel="المغادرة وتجاهل التعديلات" onConfirm={() => blocker.proceed()} onCancel={() => blocker.reset()}>
          <p>توجد تعديلات في الرواتب لم يؤكد الخادم حفظها بعد. إن غادرت الآن فقد لا تُحفظ.</p>
        </ManualGradeDialog>
      )}
    </>
  )
}
