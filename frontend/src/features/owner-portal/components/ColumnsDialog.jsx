import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaArrowUp, FaArrowDown, FaPen, FaTrash, FaPlus } from 'react-icons/fa'
import PayrollDialog from './PayrollDialog'
import FormulaInput from './FormulaInput'
import {
  createPayrollColumn, deletePayrollColumn, previewPayrollConfig, restorePayrollColumnFormula, savePayrollLayout, savePayrollSettings, updatePayrollColumn,
} from '../lib/ownerApi'
import { errorText, fieldErrors, GROUP_LABELS, GROUP_ORDER } from '../lib/payrollView'
import { formatAmount, formatSyp, INPUT_ERRORS, parseInput, pctText, SYMBOL } from '../lib/payrollMoney'
import { compare, parseDec } from '../lib/payrollDecimal'

const BTN = 'inline-flex items-center gap-1.5 rounded-[10px] border px-3.5 py-2 text-[12px] font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50'
const PRIMARY = `${BTN} border-primary bg-primary text-white hover:bg-primary-dark`
const SECONDARY = `${BTN} border-primary/20 bg-white text-primary-dark hover:bg-primary/[0.07]`
const DANGER = `${BTN} border-red-300 bg-white text-red-700 hover:bg-red-50`
const INPUT = 'w-full rounded-[9px] border border-primary/25 px-3 py-2 text-[13px] text-text-dark outline-none transition-colors focus:border-primary disabled:bg-black/[0.03]'
const TYPE_LABEL = { text: 'نص', number: 'رقم', amount: `مبلغ (${SYMBOL})`, percent: 'نسبة مئوية' }

const emptyColumn = () => ({
  label: '', group: 'salary', kind: 'input', value_type: 'amount', formula: '', blank_as_zero: true, allow_negative: false, warn_negative: false,
  aggregation: 'none', visible_grid: true, visible_export: true, compact: false,
})

function Field({ id, label, error, hint, children }) {
  return (
    <div>
      <label htmlFor={id} className="mb-1 block text-[11.5px] font-bold text-text-dark">{label}</label>
      {children}
      {hint && !error && <p className="mt-1 text-[11px] leading-5 text-text-light">{hint}</p>}
      {error && <p role="alert" className="mt-1 text-[11.5px] font-semibold leading-5 text-red-600">{error}</p>}
    </div>
  )
}

function Check({ checked, onChange, disabled, children }) {
  return (
    <label className="inline-flex items-center gap-2 text-[12px] font-semibold text-text-gray">
      <input type="checkbox" checked={checked} disabled={disabled} onChange={event => onChange(event.target.checked)} className="size-4 accent-[#569933]" />
      {children}
    </label>
  )
}

/** Before → after of one proposed change (column or settings), as the server computed it over the saved values. */
function ImpactSummary({ impact, columnLabel }) {
  if (!impact) return null
  const { net_payable: net, employees_changed: changed, errors_introduced: errorsIntroduced } = impact
  const cell = impact.preview && impact.focus_key ? impact.preview.cells[impact.focus_key] : null
  const before = impact.preview && impact.focus_key ? impact.preview.before[impact.focus_key] : null
  return (
    <div className="rounded-[12px] border border-primary/15 bg-primary/[0.03] px-4 py-3" data-testid="impact-summary" aria-live="polite">
      <p className="text-[12px] font-extrabold text-text-dark">أثر التغيير قبل الحفظ</p>
      {cell && (
        <p className="mt-1 text-[12px]" data-testid="impact-cell">
          {columnLabel ? `«${columnLabel}» للموظف المحدد: ` : 'القيمة للموظف المحدد: '}
          {cell.st === 'missing' ? <span className="font-bold text-stone-600">غير متاحة — {cell.m}</span> : cell.st === 'error' ? <span className="font-bold text-red-700">خطأ — {cell.m}</span> : <span className="font-black text-primary-dark" dir="ltr">{cell.v === null ? '—' : formatAmount(cell.v)}</span>}
          {before && before.v !== cell.v && <span className="text-text-light"> (قبل: {before.st ? '—' : before.v === null ? 'فارغ' : formatAmount(before.v)})</span>}
        </p>
      )}
      <ul className="mt-1 list-inside list-disc text-[12px] text-text-gray">
        <li>إجمالي الصافي المستحق: {formatSyp(net.before.sum)} ← <b dir="ltr">{formatSyp(net.after.sum)}</b>{net.after.excluded > 0 ? ` (يستثني ${net.after.excluded} سجلًا غير متاح)` : ''}</li>
        <li>موظفون تتغير نتيجتهم: {changed} من {impact.employees}</li>
        {errorsIntroduced > 0 && <li className="font-bold text-red-700">سجلات يصبح فيها الصافي غير متاح بسبب هذا التغيير: {errorsIntroduced}</li>}
      </ul>
    </div>
  )
}

export default function ColumnsDialog({ config, employees, canManage, ensureSaved, onChanged, onClose, initialTab = 'columns' }) {
  const [tab, setTab] = useState(initialTab)
  const [editing, setEditing] = useState(null) // { key|null, draft }
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState(null) // { tone: 'error' | 'info', text, conflict?: true }
  // The layout draft keeps unsaved order/visibility changes until «حفظ الترتيب والظهور».
  const [layout, setLayout] = useState(() => config.columns.map(c => ({ key: c.key, visible_grid: c.visible_grid, visible_export: c.visible_export, compact: c.compact })))
  const [layoutDirty, setLayoutDirty] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)

  // A newer configuration (after a save or a reload) replaces the draft only when nothing is being edited.
  useEffect(() => {
    if (layoutDirty) return
    setLayout(config.columns.map(c => ({ key: c.key, visible_grid: c.visible_grid, visible_export: c.visible_export, compact: c.compact })))
  }, [config, layoutDirty])

  const byKey = useMemo(() => new Map(config.columns.map(c => [c.key, c])), [config])
  const references = useMemo(() => [
    ...config.settings.map(s => ({ key: s.key, label: s.label, kind: 'setting', type: 'number' })),
    ...config.columns.map(c => ({ key: c.key, label: c.label, kind: 'column', type: c.value_type })),
  ], [config])

  const fail = useCallback(error => {
    const conflict = error?.errorCode === 'payroll_config_conflict'
    setMessage({ tone: 'error', conflict, text: conflict ? 'عُدّلت الإعدادات من جهة أخرى بعد فتح هذه النافذة. لم يُحفظ شيء. حدّث الإعدادات ثم أعد المحاولة.' : errorText(error) })
  }, [])

  async function guard(action) {
    if (busy) return null
    setBusy(true); setMessage(null)
    try {
      const saved = await ensureSaved()
      if (!saved) { setMessage({ tone: 'error', text: 'احفظ تعديلات الجدول أو حلّ مشكلة الحفظ أولًا، ثم عدّل الأعمدة.' }); return null }
      return await action()
    } catch (error) { fail(error); return undefined } finally { setBusy(false) }
  }

  // ── layout tab ──
  const groupOf = key => byKey.get(key).group
  const move = (key, delta) => {
    setLayout(current => {
      const group = groupOf(key)
      const indexes = current.map((item, i) => [item, i]).filter(([item]) => groupOf(item.key) === group).map(([, i]) => i)
      const position = indexes.findIndex(i => current[i].key === key)
      const target = position + delta
      if (target < 0 || target >= indexes.length) return current
      const next = [...current]
      ;[next[indexes[position]], next[indexes[target]]] = [next[indexes[target]], next[indexes[position]]]
      return next
    })
    setLayoutDirty(true)
  }
  const toggle = (key, field, value) => { setLayout(current => current.map(item => (item.key === key ? { ...item, [field]: value } : item))); setLayoutDirty(true) }
  const saveLayout = () => guard(async () => {
    const result = await savePayrollLayout(layout, config.revision)
    setLayoutDirty(false)
    setMessage({ tone: 'info', text: 'تم حفظ الترتيب والظهور. لم تتغير أي قيمة أو معادلة.' })
    await onChanged(result.data.config)
  })

  const startNew = () => { setEditing({ key: null, draft: emptyColumn() }); setTab('editor'); setMessage(null) }
  const startEdit = key => {
    const c = byKey.get(key)
    setEditing({ key, draft: { ...c, formula: c.formula_display ?? '' } })
    setTab('editor'); setMessage(null)
  }

  const requestDelete = key => {
    const c = byKey.get(key)
    setConfirmDelete({ key, label: c.label, dependents: c.dependents ?? [], values: false })
  }
  const doDelete = () => guard(async () => {
    try {
      const result = await deletePayrollColumn(confirmDelete.key, config.revision, confirmDelete.values)
      setConfirmDelete(null)
      setMessage({ tone: 'info', text: `تم حذف «${confirmDelete.label}».` })
      await onChanged(result.data.config)
    } catch (error) {
      if (error?.errorCode === 'payroll_column_has_values') { setConfirmDelete(c => ({ ...c, values: true, valuesCount: error.details?.values_count })); return }
      throw error
    }
  })

  const tabs = [['columns', 'الأعمدة'], ['editor', editing ? (editing.key ? 'تعديل عمود' : 'عمود جديد') : 'إضافة عمود'], ['settings', 'الإعدادات العامة']]

  return (
    <PayrollDialog
      title="إدارة الأعمدة والمعادلات" subtitle="الأعمدة والمعادلات والنسب تُطبّق على كل الموظفين دون استثناء. كل حفظ عملية واحدة ذرّية."
      onClose={onClose} footer={<button type="button" className={SECONDARY} onClick={onClose}>إغلاق</button>}
    >
      <div role="tablist" aria-label="أقسام الإدارة" className="mb-4 flex flex-wrap gap-2 border-b border-primary/10 pb-2">
        {tabs.map(([id, label]) => (
          <button key={id} role="tab" type="button" aria-selected={tab === id} id={`tab-${id}`} aria-controls={`panel-${id}`} onClick={() => { if (id === 'editor' && !editing) startNew(); else setTab(id); setMessage(null) }}
            className={`rounded-[10px] px-4 py-2 text-[12.5px] font-bold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${tab === id ? 'bg-primary text-white' : 'bg-primary/[0.06] text-primary-dark hover:bg-primary/10'}`}>{label}</button>
        ))}
      </div>
      {!canManage && <p role="note" className="mb-3 rounded-[10px] bg-amber-50 px-3 py-2 text-[12px] text-amber-900">حسابك يعرض الأعمدة فقط ولا يملك صلاحية تعديلها.</p>}
      {message && (
        <div role={message.tone === 'error' ? 'alert' : 'status'} className={`mb-3 rounded-[10px] px-3 py-2 text-[12.5px] font-semibold ${message.tone === 'error' ? 'bg-red-50 text-red-800' : 'bg-primary/[0.07] text-primary-dark'}`}>
          {message.text}
          {message.conflict && <button type="button" className="mr-3 underline" onClick={async () => { setBusy(true); try { await onChanged(null); setLayoutDirty(false); setEditing(null); setMessage({ tone: 'info', text: 'تم تحديث الإعدادات من الخادم.' }) } finally { setBusy(false) } }}>تحديث الإعدادات</button>}
        </div>
      )}

      {tab === 'columns' && (
        <div role="tabpanel" id="panel-columns" aria-labelledby="tab-columns">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p className="text-[11.5px] text-text-light">رتّب الأعمدة داخل مجموعاتها وحدّد ما يظهر في الجدول والتصدير والعرض المختصر. أعمدة القالب لا تُحذف ولا تتغير معادلاتها، ويمكن إخفاؤها أو إعادة تسميتها.</p>
            {canManage && <button type="button" className={PRIMARY} onClick={startNew}><FaPlus aria-hidden="true" />إضافة عمود</button>}
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] border-collapse text-[12px]" data-testid="columns-table">
              <thead>
                <tr className="bg-primary/[0.06] text-text-dark">
                  <th className="px-2 py-2 text-start">العمود</th><th className="px-2 py-2 text-start">النوع</th><th className="px-2 py-2 text-start">التعبئة</th>
                  <th className="px-2 py-2">الجدول</th><th className="px-2 py-2">التصدير</th><th className="px-2 py-2">مختصر</th><th className="px-2 py-2">الإجمالي</th><th className="px-2 py-2">إجراءات</th>
                </tr>
              </thead>
              {GROUP_ORDER.map(group => {
                const items = layout.filter(item => groupOf(item.key) === group)
                if (!items.length) return null
                return (
                  <tbody key={group}>
                    <tr><th colSpan={8} scope="colgroup" className="bg-primary/[0.12] px-2 py-1.5 text-start text-[11.5px] font-black text-primary-dark">{GROUP_LABELS[group]}</th></tr>
                    {items.map((item, index) => {
                      const c = byKey.get(item.key)
                      return (
                        <tr key={item.key} className="border-b border-primary/10" data-column={item.key}>
                          <td className="px-2 py-1.5 font-bold text-text-dark">{c.label}{c.is_system && <span className="mr-1.5 rounded bg-primary/10 px-1.5 text-[10px] font-semibold text-primary-dark">قالب</span>}{c.formula_editable && c.is_template_default === false && <span className="mr-1.5 rounded bg-amber-100 px-1.5 text-[10px] font-semibold text-amber-900" data-testid="formula-modified">معادلة معدّلة</span>}</td>
                          <td className="px-2 py-1.5">{TYPE_LABEL[c.value_type]}</td>
                          <td className="px-2 py-1.5">{c.kind === 'formula' ? <span title={c.formula_display}>معادلة</span> : 'إدخال يدوي'}</td>
                          <td className="px-2 py-1.5 text-center"><input type="checkbox" aria-label={`إظهار ${c.label} في الجدول`} disabled={!canManage} checked={item.visible_grid} onChange={event => toggle(item.key, 'visible_grid', event.target.checked)} className="size-4 accent-[#569933]" /></td>
                          <td className="px-2 py-1.5 text-center"><input type="checkbox" aria-label={`تضمين ${c.label} في التصدير`} disabled={!canManage} checked={item.visible_export} onChange={event => toggle(item.key, 'visible_export', event.target.checked)} className="size-4 accent-[#569933]" /></td>
                          <td className="px-2 py-1.5 text-center"><input type="checkbox" aria-label={`إظهار ${c.label} في العرض المختصر`} disabled={!canManage} checked={item.compact} onChange={event => toggle(item.key, 'compact', event.target.checked)} className="size-4 accent-[#569933]" /></td>
                          <td className="px-2 py-1.5 text-center">{c.aggregation === 'sum' ? 'مجموع' : '—'}</td>
                          <td className="px-2 py-1.5">
                            <div className="flex items-center justify-center gap-1">
                              <button type="button" className={SECONDARY} aria-label={`نقل ${c.label} للأعلى`} disabled={!canManage || index === 0} onClick={() => move(item.key, -1)}><FaArrowUp aria-hidden="true" /></button>
                              <button type="button" className={SECONDARY} aria-label={`نقل ${c.label} للأسفل`} disabled={!canManage || index === items.length - 1} onClick={() => move(item.key, 1)}><FaArrowDown aria-hidden="true" /></button>
                              {canManage && <button type="button" className={SECONDARY} aria-label={`تعديل ${c.label}`} onClick={() => startEdit(item.key)}><FaPen aria-hidden="true" /></button>}
                              {canManage && !c.is_system && <button type="button" className={DANGER} aria-label={`حذف ${c.label}`} onClick={() => requestDelete(item.key)}><FaTrash aria-hidden="true" /></button>}
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                )
              })}
            </table>
          </div>
          {canManage && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              <button type="button" className={PRIMARY} disabled={!layoutDirty || busy} onClick={saveLayout}>حفظ الترتيب والظهور</button>
              {layoutDirty && <><span className="text-[12px] font-bold text-amber-700">تغييرات غير محفوظة</span><button type="button" className={SECONDARY} onClick={() => { setLayoutDirty(false) }}>تراجع عن التغييرات</button></>}
            </div>
          )}
          {confirmDelete && (
            <div role="alertdialog" aria-labelledby="delete-title" className="mt-4 rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-red-900" data-testid="delete-confirm">
              <p id="delete-title" className="font-extrabold">حذف العمود «{confirmDelete.label}»</p>
              {confirmDelete.dependents.length > 0 ? (
                <p>لا يمكن الحذف: تعتمد عليه المعادلات التالية — {confirmDelete.dependents.map(d => `«${d.label}»`).join('، ')}. عدّل هذه المعادلات أو احذفها أولًا.</p>
              ) : confirmDelete.values ? (
                <p>يحتوي العمود على {confirmDelete.valuesCount ?? 'عدة'} قيمة مدخلة ستُحذف نهائيًا مع العمود. هل أنت متأكد؟</p>
              ) : <p>سيُحذف العمود من الجدول والتصدير. لا تعتمد عليه أي معادلة.</p>}
              <div className="mt-2 flex gap-2">
                {confirmDelete.dependents.length === 0 && <button type="button" className={DANGER} disabled={busy} onClick={doDelete}>{confirmDelete.values ? 'حذف العمود وقيمه' : 'تأكيد الحذف'}</button>}
                <button type="button" className={SECONDARY} onClick={() => setConfirmDelete(null)}>إلغاء</button>
              </div>
            </div>
          )}
        </div>
      )}

      {tab === 'editor' && editing && (
        <ColumnEditor
          key={editing.key ?? 'new'} editing={editing} config={config} references={references} employees={employees} canManage={canManage} busy={busy}
          onCancel={() => { setEditing(null); setTab('columns') }}
          onSave={draft => guard(async () => {
            const payload = columnPayload(draft, editing.key ? byKey.get(editing.key) : null)
            const result = editing.key ? await updatePayrollColumn(editing.key, payload, config.revision) : await createPayrollColumn(payload, config.revision)
            setEditing(null); setTab('columns'); setMessage({ tone: 'info', text: editing.key ? 'تم حفظ التعديل.' : 'تمت إضافة العمود. لم تتغير أي قيمة قائمة.' })
            await onChanged(result.data.config)
          })}
          reportError={fail}
          onRestore={() => guard(async () => {
            const result = await restorePayrollColumnFormula(editing.key, config.revision)
            setEditing(null); setTab('columns'); setMessage({ tone: 'info', text: 'تمت استعادة معادلة القالب. لم تتغير الإعدادات ولا القيم المدخلة ولا الأعمدة المخصصة.' })
            await onChanged(result.data.config)
          })}
        />
      )}

      {tab === 'settings' && (
        <SettingsPanel
          key={config.revision} config={config} employees={employees} canManage={canManage} busy={busy}
          onSave={values => guard(async () => {
            const result = await savePayrollSettings(values, config.revision)
            setMessage({ tone: 'info', text: 'تم حفظ الإعدادات وإعادة حساب كل الموظفين.' })
            await onChanged(result.data.config)
          })}
        />
      )}
    </PayrollDialog>
  )
}

/** Fields the API accepts for a column (system columns: only presentation fields). */
function columnPayload(draft, existing) {
  if (existing?.is_system) {
    // Template columns: presentation only — plus the formula of the one template column whose formula the owner may edit (the net payable).
    const presentation = { label: draft.label, visible_grid: draft.visible_grid, visible_export: draft.visible_export, compact: draft.compact }
    return existing.formula_editable ? { ...presentation, formula: draft.formula, key: existing.key } : presentation
  }
  const payload = {
    label: draft.label, group: draft.group, kind: draft.kind, value_type: draft.value_type, blank_as_zero: draft.blank_as_zero, allow_negative: draft.allow_negative,
    warn_negative: draft.warn_negative, aggregation: draft.aggregation, visible_grid: draft.visible_grid, visible_export: draft.visible_export, compact: draft.compact,
  }
  if (draft.kind === 'formula') payload.formula = draft.formula
  if (existing) payload.key = existing.key
  return payload
}

function ColumnEditor({ editing, config, references, employees, canManage, busy, onSave, onCancel, onRestore, reportError }) {
  const existing = editing.key ? config.columns.find(c => c.key === editing.key) : null
  const system = Boolean(existing?.is_system)
  const formulaEditable = Boolean(existing?.formula_editable) // the net payable: its formula (only) can be changed and restored
  const [draft, setDraft] = useState(editing.draft)
  const [errors, setErrors] = useState({})
  const [impact, setImpact] = useState(null)
  const [previewError, setPreviewError] = useState('')
  const [employeeId, setEmployeeId] = useState(employees[0]?.id ?? '')
  const seq = useRef(0)
  const set = (field, value) => { setDraft(d => ({ ...d, [field]: value })); setErrors(e => ({ ...e, [field]: '' })) }
  const isFormula = draft.kind === 'formula'
  const numeric = draft.value_type !== 'text'
  const lockedType = Boolean(editing.key) && !system

  // Live preview against the selected employee (and the effect on totals) before anything is saved.
  const previewable = (!system || formulaEditable) && canManage && draft.label.trim() !== '' && !(isFormula && draft.formula.trim() === '')
  useEffect(() => {
    if (!previewable) return undefined
    const run = ++seq.current
    const timer = setTimeout(async () => {
      try {
        const column = columnPayload(draft, editing.key ? config.columns.find(c => c.key === editing.key) : null)
        const response = await previewPayrollConfig({ column, employee_id: employeeId || null })
        if (run === seq.current) { setImpact(response.data); setPreviewError('') }
      } catch (error) {
        if (run !== seq.current) return
        setImpact(null)
        setPreviewError(Object.values(fieldErrors(error.details))[0] || errorText(error))
      }
    }, 500)
    return () => clearTimeout(timer)
  }, [draft, employeeId, previewable, editing.key, config.columns])
  const shownImpact = previewable ? impact : null
  const shownPreviewError = previewable ? previewError : ''

  async function submit() {
    const local = {}
    if (!draft.label.trim()) local.label = 'اسم العمود مطلوب.'
    if (isFormula && !draft.formula.trim()) local.formula = 'المعادلة مطلوبة للعمود المحسوب.'
    setErrors(local)
    if (Object.keys(local).length) return
    try { await onSave(draft) } catch (error) { setErrors(fieldErrors(error.details)); reportError(error) }
  }

  return (
    <div role="tabpanel" id="panel-editor" aria-labelledby="tab-editor" className="grid gap-4">
      <div className="grid grid-cols-2 gap-3 max-[640px]:grid-cols-1">
        <Field id="col-label" label="اسم العمود" error={errors.label}>
          <input id="col-label" className={INPUT} value={draft.label} maxLength={100} disabled={!canManage} onChange={event => set('label', event.target.value)} />
        </Field>
        <Field id="col-group" label="المجموعة" error={errors.group}>
          <select id="col-group" className={INPUT} value={draft.group} disabled={!canManage || system} onChange={event => set('group', event.target.value)}>
            {GROUP_ORDER.map(group => <option key={group} value={group}>{GROUP_LABELS[group]}</option>)}
          </select>
        </Field>
        <Field id="col-type" label="النوع" error={errors.value_type} hint={lockedType ? 'لا يتغير النوع بعد الإنشاء إذا كان العمود يحمل بيانات (يمكن التبديل بين رقم ومبلغ).' : undefined}>
          <select id="col-type" className={INPUT} value={draft.value_type} disabled={!canManage || system} onChange={event => { set('value_type', event.target.value); if (event.target.value === 'text') set('aggregation', 'none') }}>
            {Object.entries(TYPE_LABEL).filter(([key]) => !(isFormula && key === 'text')).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
          </select>
        </Field>
        <Field id="col-kind" label="طريقة التعبئة" error={errors.kind}>
          <select id="col-kind" className={INPUT} value={draft.kind} disabled={!canManage || system || Boolean(editing.key)} onChange={event => { set('kind', event.target.value); if (event.target.value === 'formula' && draft.value_type === 'text') set('value_type', 'amount') }}>
            <option value="input">إدخال يدوي</option>
            <option value="formula">محسوب بمعادلة</option>
          </select>
        </Field>
      </div>

      {isFormula && (
        <div>
          <p className="mb-1 text-[11.5px] font-bold text-text-dark">المعادلة</p>
          {system && !formulaEditable ? (
            <p className="rounded-[9px] bg-primary/[0.05] px-3 py-2 font-mono text-[13px] text-text-dark" dir="rtl" data-testid="system-formula">{draft.formula}</p>
          ) : (
            <FormulaInput value={draft.formula} onChange={value => set('formula', value)} references={references.filter(r => r.key !== editing.key)} disabled={!canManage} invalid={Boolean(errors.formula || shownPreviewError)} describedBy="formula-help" />
          )}
          {formulaEditable && (
            <div className="mt-2 flex flex-wrap items-center gap-3 rounded-[10px] bg-amber-50 px-3 py-2 text-[12px] text-amber-950" data-testid="net-formula-note">
              <span>هذه هي معادلة <b>الصافي المستحق المعتمد</b> التي تعتمد عليها الرئيسية والإجماليات والتصدير. أي عمود مخصص لا يدخل فيها إلا إذا ذكرتَه هنا. المعادلة الافتراضية: <span className="font-mono" dir="rtl" data-testid="template-formula">{existing.template_formula_display}</span></span>
              {canManage && <button type="button" className={SECONDARY} disabled={busy || existing.is_template_default} onClick={onRestore}>استعادة معادلة القالب</button>}
            </div>
          )}
          <p id="formula-help" className="mt-1 text-[11px] leading-5 text-text-light">
            العمليات: + − * / وأقواس ونسبة (10%) ومقارنات (= &lt;&gt; &lt; &lt;= &gt; &gt;=) والدوال SUM وIF وMAX وMIN وROUND. اكتب [ لاختيار عمود أو إعداد بالاسم.
            {system && !formulaEditable && ' معادلات القالب محمية ولا تُعدَّل؛ يمكنك استبدال أثرها بعمود محسوب جديد.'}
          </p>
          {(errors.formula || shownPreviewError) && <p role="alert" className="mt-1 text-[12px] font-semibold text-red-600" data-testid="formula-error">{errors.formula || shownPreviewError}</p>}
          {(!system || formulaEditable) && <div className="mt-2 flex flex-wrap gap-1.5" aria-label="أعمدة وإعدادات متاحة">
            {references.filter(r => r.key !== editing.key && r.type !== 'text').slice(0, 30).map(r => (
              <button key={r.key} type="button" disabled={!canManage} onClick={() => set('formula', `${draft.formula}${draft.formula && !/\s$/.test(draft.formula) ? ' ' : ''}[${r.label}]`)} className={`rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${r.kind === 'setting' ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-primary/20 bg-primary/[0.05] text-primary-dark'} hover:bg-primary/10`}>{r.label}</button>
            ))}
          </div>}
        </div>
      )}

      {!system && (
        <fieldset className="grid gap-2 rounded-[12px] border border-primary/12 px-4 py-3">
          <legend className="px-1 text-[11.5px] font-bold text-text-dark">سلوك العمود</legend>
          {!isFormula && numeric && <Check checked={draft.blank_as_zero} disabled={!canManage} onChange={v => set('blank_as_zero', v)}>الفراغ يُحسب صفرًا في المعادلات (دون كتابة صفر في الخلية). إن أُلغي، يصبح العمود مطلوبًا وتبقى النتائج المعتمدة عليه غير متاحة حتى إدخاله.</Check>}
          {!isFormula && numeric && draft.value_type !== 'percent' && <Check checked={draft.allow_negative} disabled={!canManage} onChange={v => set('allow_negative', v)}>السماح بقيم سالبة (مثل الفروقات)</Check>}
          {isFormula && numeric && <Check checked={draft.warn_negative} disabled={!canManage} onChange={v => set('warn_negative', v)}>تنبيه حسابي عند النتيجة السالبة (لا يُصفَّر شيء)</Check>}
          {numeric && draft.value_type !== 'percent' && (
            <label className="inline-flex items-center gap-2 text-[12px] font-semibold text-text-gray">
              التجميع في آخر الجدول والتصدير:
              <select aria-label="التجميع" className="rounded-[8px] border border-primary/25 px-2 py-1 text-[12px]" disabled={!canManage} value={draft.aggregation} onChange={event => set('aggregation', event.target.value)}>
                <option value="none">بدون</option><option value="sum">مجموع</option>
              </select>
            </label>
          )}
          {draft.value_type === 'percent' && <p className="text-[11px] text-text-light">النسب المئوية لا تُجمع تلقائيًا. تُدخل كرقم بين 0 و 100 (مثل 7 أو 7%) وتُخزَّن ككسر.</p>}
          {errors.aggregation && <p role="alert" className="text-[11.5px] font-semibold text-red-600">{errors.aggregation}</p>}
        </fieldset>
      )}

      <fieldset className="flex flex-wrap gap-x-6 gap-y-2 rounded-[12px] border border-primary/12 px-4 py-3">
        <legend className="px-1 text-[11.5px] font-bold text-text-dark">الظهور</legend>
        <Check checked={draft.visible_grid} disabled={!canManage} onChange={v => set('visible_grid', v)}>في الجدول</Check>
        <Check checked={draft.visible_export} disabled={!canManage} onChange={v => set('visible_export', v)}>في التصدير (Excel وPDF)</Check>
        <Check checked={draft.compact} disabled={!canManage} onChange={v => set('compact', v)}>في العرض المختصر</Check>
      </fieldset>

      {(!system || formulaEditable) && canManage && (
        <div className="grid gap-2">
          <div className="flex flex-wrap items-center gap-3">
            <label htmlFor="preview-employee" className="text-[11.5px] font-bold text-text-dark">معاينة على الموظف:</label>
            <select id="preview-employee" className="min-w-[220px] rounded-[9px] border border-primary/25 px-3 py-1.5 text-[12.5px]" value={employeeId} onChange={event => setEmployeeId(event.target.value ? Number(event.target.value) : '')}>
              {employees.length === 0 && <option value="">لا يوجد موظفون</option>}
              {employees.map(e => <option key={e.id} value={e.id}>{e.label}</option>)}
            </select>
          </div>
          <ImpactSummary impact={shownImpact} columnLabel={draft.label.trim()} />
        </div>
      )}

      <div className="flex flex-wrap items-center gap-2">
        {canManage && <button type="button" className={PRIMARY} disabled={busy || Boolean(shownPreviewError)} onClick={submit}>{editing.key ? 'حفظ التعديل' : 'إضافة العمود'}</button>}
        <button type="button" className={SECONDARY} onClick={onCancel}>إلغاء</button>
      </div>
    </div>
  )
}

/** Global settings (rates and the exemption): edited as plain numbers, previewed on every employee, saved atomically. */
function SettingsPanel({ config, employees, canManage, busy, onSave }) {
  const textOf = s => (s.value_type === 'percent' ? pctText(parseDec(s.value)) : s.value)
  const [values, setValues] = useState(() => Object.fromEntries(config.settings.map(s => [s.key, textOf(s)])))
  const [impact, setImpact] = useState(null)
  const [previewError, setPreviewError] = useState('')
  const seq = useRef(0)

  const { out: wireValues, problems: errors } = useMemo(() => {
    const out = {}
    const problems = {}
    for (const s of config.settings) {
      const parsed = parseInput({ value_type: s.value_type, allow_negative: false }, values[s.key])
      if (!parsed.ok || parsed.value === null) problems[s.key] = parsed.ok ? 'القيمة مطلوبة.' : INPUT_ERRORS[parsed.error]
      else out[s.key] = parsed.value
    }
    return { out, problems }
  }, [config.settings, values])

  const changed = useMemo(() => Object.fromEntries(Object.entries(wireValues).filter(([key, value]) => {
    const current = config.settings.find(s => s.key === key)
    return compare(parseDec(current.value), parseDec(value)) !== 0
  })), [wireValues, config.settings])

  const previewable = canManage && Object.keys(errors).length === 0 && Object.keys(changed).length > 0
  const firstEmployee = employees[0]?.id ?? null
  useEffect(() => {
    if (!previewable) return undefined
    const run = ++seq.current
    const timer = setTimeout(async () => {
      try {
        const response = await previewPayrollConfig({ settings: changed, employee_id: firstEmployee })
        if (run === seq.current) { setImpact(response.data); setPreviewError('') }
      } catch (error) { if (run === seq.current) { setImpact(null); setPreviewError(Object.values(fieldErrors(error.details))[0] || errorText(error)) } }
    }, 400)
    return () => clearTimeout(timer)
  }, [previewable, changed, firstEmployee])
  const shownImpact = previewable ? impact : null
  const shownError = previewable ? previewError : ''

  return (
    <div role="tabpanel" id="panel-settings" aria-labelledby="tab-settings" className="grid gap-4">
      <p className="text-[11.5px] leading-6 text-text-light">القيم الابتدائية مأخوذة من ملف «شرح حساب الرواتب والأجور» المرجعي، وهي إعدادات يعدّلها المالك وليست تقريرًا عن نظام قانوني نافذ. تُخزَّن مرة واحدة وتسري على كل الموظفين.</p>
      <div className="grid grid-cols-3 gap-3 max-[760px]:grid-cols-1">
        {config.settings.map(s => (
          <Field key={s.key} id={`setting-${s.key}`} label={`${s.label}${s.value_type === 'percent' ? ' (%)' : ` (${SYMBOL})`}`} error={errors[s.key]}>
            <input id={`setting-${s.key}`} className={INPUT} dir="ltr" inputMode="decimal" value={values[s.key]} disabled={!canManage} onChange={event => setValues(v => ({ ...v, [s.key]: event.target.value }))} />
          </Field>
        ))}
      </div>
      {shownError && <p role="alert" className="text-[12px] font-semibold text-red-600">{shownError}</p>}
      <ImpactSummary impact={shownImpact} />
      {canManage && (
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" className={PRIMARY} disabled={busy || Object.keys(changed).length === 0 || Object.keys(errors).length > 0} onClick={() => onSave(changed)}>حفظ الإعدادات</button>
          {Object.keys(changed).length === 0 && <span className="text-[12px] text-text-light">لا توجد تغييرات.</span>}
        </div>
      )}
    </div>
  )
}
