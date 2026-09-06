import { useEffect, useRef, useState } from 'react'
import { FaChartBar, FaRedo, FaSlidersH } from 'react-icons/fa'
import { useLocation } from 'react-router-dom'
import { executeExecutiveReport, fetchAllExecutiveReportOptions, fetchExecutiveReportDefinitions } from '../api'
import {
  COMPARISON_LABELS, DIMENSION_RESOURCES, FILTERS, MODE_LABELS, PERIOD_LABELS, REPORT_PRESETS, SUBJECT_LABELS,
  applyNavigationSeed, applyPreset, availableComparisons, buildReportPayload, changeSubject, clearDescendantSelections,
  filterKeysForDefinition, initialReportConfig, normalizeDefinitions, validateReportConfig,
} from '../config'
import { classifyReportError, isLatestResponse } from '../presentation'
import ReportOptionPicker from '../components/ReportOptionPicker'
import ReportResults from '../components/ReportResults'

function clone(value) { return JSON.parse(JSON.stringify(value)) }

function Choice({ checked, onChange, children, type = 'checkbox' }) {
  return <label className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm transition ${checked ? 'border-primary bg-primary/10 text-primary-dark' : 'border-black/10 bg-white'}`}><input type={type} checked={checked} onChange={onChange} /><span className="font-bold">{children}</span></label>
}

function ScopeFilters({ definition, config, onChange, onNotice, title = 'نطاق التقرير' }) {
  const update = (key, values, labels) => {
    let next = { ...config, filters: { ...config.filters, [key]: values }, labels: { ...config.labels, [key]: labels }, page: 1 }
    next = clearDescendantSelections(next, key)
    const removed = Object.keys(config.filters).filter(existing => existing !== key && config.filters[existing]?.length && !next.filters[existing])
    if (removed.length) onNotice?.('تم مسح الاختيارات التابعة لأنها لم تعد متوافقة مع نطاق الأب الجديد.')
    onChange(next)
  }
  return <div className="space-y-3"><h3 className="font-black text-text-dark">{title}</h3><p className="text-xs text-text-light">ترك الكليات دون اختيار يعني جميع الكليات المصرح بها، وليس خيارات الصفحة الظاهرة فقط.</p><div className="grid gap-3 xl:grid-cols-2">{filterKeysForDefinition(definition).map(key => {
    const filter = FILTERS[key]
    const parentValues = filter.parentFilter ? config.filters[filter.parentFilter] ?? [] : []
    const extraParameters = key === 'offering_ids' ? {
      ...(config.period?.academic_year_ids?.length === 1 ? { academic_year_id: config.period.academic_year_ids[0] } : {}),
      ...(config.period?.semester_ids?.length === 1 ? { semester_id: config.period.semester_ids[0] } : {}),
    } : {}
    return <ReportOptionPicker key={key} resource={filter.resource} label={filter.label} values={config.filters[key] ?? []} labels={config.labels[key] ?? {}} codeValues={filter.codeValues} parentName={filter.parent} parentValues={parentValues} parentLabels={config.labels[filter.parentFilter] ?? {}} extraParameters={extraParameters} onChange={(values, labels) => update(key, values, labels)} />
  })}</div></div>
}

function PeriodEditor({ definition, config, onChange, prefix = '' }) {
  const supported = definition.supportedPeriods
  const semestersSupported = definition.subject !== 'enrollments' && definition.filters.includes('semester_ids')
  const period = config.period ?? { type: 'none' }
  const setPeriod = patch => onChange({ ...config, period: { ...period, ...patch }, page: 1 })
  const setType = type => onChange({ ...config, period: type === 'academic' ? { type, academic_year_ids: [], semester_ids: [] } : type === 'date_range' ? { type, date_from: '', date_to: '' } : { type }, page: 1 })
  return <div className="space-y-3"><div className="flex flex-wrap gap-2">{supported.map(type => <Choice type="radio" key={type} checked={period.type === type} onChange={() => setType(type)}>{PERIOD_LABELS[type] ?? type}</Choice>)}</div>{period.type === 'academic' && <div className="grid gap-3 xl:grid-cols-2"><ReportOptionPicker resource="academic_years" label={`${prefix}السنة الأكاديمية`} values={period.academic_year_ids ?? []} labels={config.labels?.academic_year_ids ?? {}} onChange={(values, labels) => onChange({ ...config, period: { ...period, academic_year_ids: values, semester_ids: [] }, labels: { ...config.labels, academic_year_ids: labels, semester_ids: {} }, page: 1 })} />{semestersSupported && <ReportOptionPicker resource="semesters" label={`${prefix}الفصل`} values={period.semester_ids ?? []} labels={config.labels?.semester_ids ?? {}} parentName="academic_year_id" parentValues={period.academic_year_ids ?? []} parentLabels={config.labels?.academic_year_ids ?? {}} onChange={(values, labels) => onChange({ ...config, period: { ...period, semester_ids: values }, labels: { ...config.labels, semester_ids: labels }, page: 1 })} />}</div>}{period.type === 'date_range' && <div className="grid gap-3 sm:grid-cols-2"><label className="text-sm font-bold">من<input type="date" value={period.date_from ?? ''} onChange={event => setPeriod({ date_from: event.target.value })} className="mt-1 block w-full rounded-xl border border-black/10 px-3 py-2" /></label><label className="text-sm font-bold">إلى<input type="date" value={period.date_to ?? ''} onChange={event => setPeriod({ date_to: event.target.value })} className="mt-1 block w-full rounded-xl border border-black/10 px-3 py-2" /></label></div>}</div>
}

function ConfigurationSummary({ config }) {
  const subject = SUBJECT_LABELS[config.subject] ?? config.subject
  const scope = Object.entries(config.filters).flatMap(([key, values]) => values.map(value => config.labels[key]?.[String(value)] ?? `المعرف ${value}`)).join('، ') || 'كل النطاق المصرح'
  const academicLabels = [...(config.period.academic_year_ids ?? []).map(value => config.labels.academic_year_ids?.[String(value)] ?? `السنة ${value}`), ...(config.period.semester_ids ?? []).map(value => config.labels.semester_ids?.[String(value)] ?? `الفصل ${value}`)]
  const period = config.period.type === 'academic' ? academicLabels.join(' — ') : config.period.type === 'date_range' ? `${config.period.date_from || '؟'} إلى ${config.period.date_to || '؟'}` : 'الوضع الحالي'
  return <p className="rounded-xl bg-primary/5 p-3 text-sm leading-7 text-text-dark"><strong>{subject}</strong> — {scope} — {period} — {MODE_LABELS[config.mode] ?? config.mode}</p>
}

export default function ExecutiveReportsPage({ office }) {
  const location = useLocation()
  const [contract, setContract] = useState(null)
  const [definitions, setDefinitions] = useState(null)
  const [draft, setDraft] = useState(null)
  const [applied, setApplied] = useState(null)
  const [report, setReport] = useState(null)
  const [dimensionLabels, setDimensionLabels] = useState({})
  const [loading, setLoading] = useState(false)
  const [definitionError, setDefinitionError] = useState('')
  const [reportError, setReportError] = useState(null)
  const [fieldErrors, setFieldErrors] = useState({})
  const [scopeNotice, setScopeNotice] = useState('')
  const requestSequence = useRef(0)
  const activeRequest = useRef(null)

  useEffect(() => {
    const controller = new AbortController(); let active = true
    setDefinitionError('')
    fetchExecutiveReportDefinitions(controller.signal).then(data => {
      if (!active) return
      const parsed = normalizeDefinitions(data)
      const requested = location.state?.executiveReportPreset
      const first = requested?.subject && parsed[requested.subject] ? requested.subject : Object.keys(parsed)[0]
      setContract(data); setDefinitions(parsed); setDraft(requested ? applyNavigationSeed(parsed[first], requested) : initialReportConfig(parsed[first]))
    }).catch(error => { if (active && error.name !== 'AbortError') setDefinitionError(classifyReportError(error).message) })
    return () => { active = false; controller.abort(); requestSequence.current += 1; activeRequest.current?.abort(); setReport(null); setApplied(null) }
  }, [office, location.key, location.state])

  useEffect(() => {
    const scopeDimensions = { college_ids: 'college', department_ids: 'department', program_ids: 'program', academic_level_ids: 'academic_level', course_ids: 'course' }
    const dimensions = [...new Set([...(report?.grouping?.dimensions ?? []), ...Object.keys(report?.scope ?? {}).map(key => scopeDimensions[key]).filter(Boolean), ...Object.keys(report?.comparison?.scope ?? {}).map(key => scopeDimensions[key]).filter(Boolean)])]
    if (!dimensions.length) { setDimensionLabels({}); return undefined }
    const controller = new AbortController(); let active = true
    const resources = [...new Set(dimensions.map(dimension => DIMENSION_RESOURCES[dimension]).filter(Boolean))]
    Promise.all(resources.map(resource => fetchAllExecutiveReportOptions({ resource }, controller.signal).then(options => [resource, options])))
      .then(entries => { if (!active) return; const next = {}; for (const dimension of dimensions) { const resource = DIMENSION_RESOURCES[dimension]; if (!resource) continue; const options = entries.find(([name]) => name === resource)?.[1] ?? []; next[dimension] = Object.fromEntries(options.flatMap(option => [[String(option.id), option.label], ...(option.code ? [[String(option.code), option.label]] : [])])) } setDimensionLabels(next) })
      .catch(error => { if (active && error.name !== 'AbortError') setDimensionLabels({}) })
    return () => { active = false; controller.abort() }
  }, [report])

  const definition = draft && definitions?.[draft.subject]
  const draftSignature = draft ? JSON.stringify({ ...buildReportPayload({ ...draft, page: 1, sort: draft.sort }), page: 1 }) : ''
  const appliedSignature = applied ? JSON.stringify({ ...buildReportPayload({ ...applied.config, page: 1, sort: applied.config.sort }), page: 1 }) : ''
  const stale = Boolean(report && draftSignature !== appliedSignature)
  const comparisons = draft && definition ? availableComparisons(draft, definition, contract.comparisons) : []
  const sortOptions = definition ? (draft.mode === 'details' ? definition.detail_sortable ?? [] : definition.sortable ?? []).filter(code => draft.metrics.includes(code) || draft.dimensions.includes(code)) : []
  const unavailableHistoricalMetrics = draft?.period?.type === 'date_range' ? draft.metrics.filter(code => !(definition.period_capabilities?.date_range_metrics ?? []).includes(code)) : []
  const historicalMetricLabels = (definition.period_capabilities?.date_range_metrics ?? []).map(code => definition.metrics.find(metric => metric.code === code)?.label ?? code)

  const run = async (configuration, options = {}) => {
    const target = clone(configuration)
    const targetDefinition = definitions[target.subject]
    const errors = validateReportConfig(target, targetDefinition, contract.limits)
    setFieldErrors(errors)
    if (Object.keys(errors).length) return
    activeRequest.current?.abort()
    const controller = new AbortController(); activeRequest.current = controller
    const sequence = ++requestSequence.current
    setLoading(true); setReportError(null)
    try {
      const data = await executeExecutiveReport(buildReportPayload(target), controller.signal)
      if (!isLatestResponse(sequence, requestSequence.current)) return
      setReport(data); setApplied({ config: target, definition: targetDefinition })
      if (!options.preserveDraft) setDraft(target)
    } catch (error) {
      if (!isLatestResponse(sequence, requestSequence.current) || error.name === 'AbortError') return
      const classified = classifyReportError(error)
      setReportError(classified); setFieldErrors(classified.details ?? {})
      if (classified.kind === 'authentication' || classified.kind === 'authorization') { setReport(null); setApplied(null) }
    } finally { if (isLatestResponse(sequence, requestSequence.current)) setLoading(false) }
  }

  const changeMode = mode => {
    let next = { ...draft, mode, page: 1, sort: null, comparison: mode === 'comparison' ? draft.comparison : null }
    if (mode === 'trend') next = { ...next, period: { type: 'date_range', date_from: '', date_to: '' }, dimensions: [definition.dimensions.some(item => item.code === 'month') ? 'month' : 'day'] }
    setDraft(next)
  }
  const updateSelection = (key, code, maximum) => {
    if (!draft[key].includes(code) && draft[key].length >= maximum) { setFieldErrors(current => ({ ...current, [key]: `الحد الأقصى ${maximum}.` })); return }
    setFieldErrors(current => ({ ...current, [key]: undefined }))
    setDraft(current => ({ ...current, [key]: current[key].includes(code) ? current[key].filter(item => item !== code) : [...current[key], code], page: 1, sort: null }))
  }
  const selectSubject = subject => setDraft(changeSubject(definitions[subject]))
  const reset = () => { activeRequest.current?.abort(); requestSequence.current += 1; setDraft(initialReportConfig(definition)); setApplied(null); setReport(null); setReportError(null); setFieldErrors({}) }
  const applyPage = page => run({ ...applied.config, page }, { preserveDraft: true })
  const applySort = field => {
    const target = { ...applied.config, page: 1, sort: { field, direction: applied.config.sort?.field === field && applied.config.sort.direction === 'asc' ? 'desc' : 'asc' } }
    if (!stale) setDraft(target)
    run(target, { preserveDraft: true })
  }

  if (definitionError) return <div className="rounded-2xl border border-red-200 bg-red-50 p-6 text-red-800" dir="rtl"><p className="font-black">تعذر فتح منشئ التقارير</p><p className="mt-2 text-sm">{definitionError}</p><button onClick={() => window.location.reload()} className="mt-4 rounded-xl bg-red-700 px-4 py-2 text-white">إعادة المحاولة</button></div>
  if (!draft || !definition) return <div className="py-20 text-center text-text-light">جاري تحميل عقد التقارير...</div>

  return <div className="space-y-6 pb-12" dir="rtl">
    <header className="rounded-3xl bg-gradient-to-l from-[#244317] to-[#417327] p-6 text-white shadow-lg"><p className="text-xs font-bold text-white/70">التقارير التنفيذية — {office === 'scientific' ? 'نيابة الشؤون العلمية' : 'نيابة الشؤون الإدارية'}</p><h1 className="mt-2 text-2xl font-black">التقارير والإحصاءات الديناميكية</h1><p className="mt-2 max-w-3xl text-sm leading-7 text-white/80">أنشئ تقريرًا من البيانات الرسمية وفق النطاق والفترة والمؤشرات التي يتيحها الخادم.</p><span className="mt-3 inline-block rounded-full bg-white/10 px-3 py-1 text-[11px]">العقد: {contract.version}</span></header>

    <section className="rounded-3xl border border-black/5 bg-white p-5 shadow-sm"><h2 className="text-lg font-black">أ. ماذا تريد أن تعرف؟</h2><div className="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">{Object.keys(definitions).map(subject => <button key={subject} type="button" onClick={() => selectSubject(subject)} className={`rounded-xl border p-3 text-right font-bold ${draft.subject === subject ? 'border-primary bg-primary/10 text-primary-dark' : 'border-black/10'}`}>{SUBJECT_LABELS[subject]}</button>)}</div><div className="mt-5 flex flex-wrap gap-2">{REPORT_PRESETS.filter(preset => definitions[preset.subject]).map(preset => <button key={preset.code} type="button" onClick={() => { const selectedDefinition = definitions[preset.subject]; setDraft(applyPreset(selectedDefinition, preset)) }} className="rounded-full border border-primary/20 px-3 py-2 text-xs font-bold text-primary-dark hover:bg-primary/5">{preset.label}</button>)}</div></section>

    <section className="rounded-3xl border border-black/5 bg-[#f8fbf6] p-5 shadow-sm"><h2 className="text-lg font-black">ب. نطاق التقرير</h2><div className="mt-4"><ScopeFilters definition={definition} config={draft} onChange={setDraft} onNotice={setScopeNotice} /></div>{scopeNotice && <p className="mt-3 rounded-lg bg-amber-50 p-2 text-xs text-amber-800" role="status">{scopeNotice}</p>}{fieldErrors.filters && <p className="mt-2 text-xs font-bold text-red-700">{Array.isArray(fieldErrors.filters) ? fieldErrors.filters.join('، ') : fieldErrors.filters}</p>}</section>

    <section className="rounded-3xl border border-black/5 bg-white p-5 shadow-sm"><h2 className="text-lg font-black">ج. الفترة</h2><div className="mt-4"><PeriodEditor definition={definition} config={draft} onChange={setDraft} /></div>{fieldErrors.period && <p className="mt-2 text-xs font-bold text-red-700">{fieldErrors.period}</p>}{draft.period.type === 'date_range' && <p className="mt-3 text-xs text-text-light">المؤشرات ذات التاريخ الموثوق لهذا الموضوع: {historicalMetricLabels.length ? historicalMetricLabels.join('، ') : 'لا توجد مؤشرات تاريخية موثوقة'}.</p>}<p className="mt-3 text-xs text-text-light">السنة والفصل يُرسلان ضمن الفترة فقط، ولا يُكرران كمرشحات.</p></section>

    <section className="rounded-3xl border border-black/5 bg-white p-5 shadow-sm"><h2 className="text-lg font-black">د. المؤشرات وطريقة العرض</h2><div className="mt-4"><p className="mb-2 text-sm font-black">طريقة العرض</p><div className="flex flex-wrap gap-2">{definition.modes.map(mode => <Choice type="radio" key={mode} checked={draft.mode === mode} onChange={() => changeMode(mode)}>{MODE_LABELS[mode] ?? mode}</Choice>)}</div></div><div className="mt-5"><p className="mb-2 text-sm font-black">المؤشرات</p><div className="flex flex-wrap gap-2">{definition.metrics.map(metric => <Choice key={metric.code} checked={draft.metrics.includes(metric.code)} onChange={() => updateSelection('metrics', metric.code, contract.limits.metrics)}>{metric.label}</Choice>)}</div>{fieldErrors.metrics && <p className="mt-2 text-xs font-bold text-red-700">{fieldErrors.metrics}</p>}{unavailableHistoricalMetrics.length > 0 && <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">بعض المؤشرات المحددة لقطات حالية ولا تملك تاريخًا موثوقًا ضمن نطاق زمني؛ سيعيد الخادم حالة «التاريخ غير متاح» بدل أصفار مصطنعة.</p>}</div><div className="mt-5"><p className="mb-2 text-sm font-black">أبعاد التجميع</p><div className="flex flex-wrap gap-2">{definition.dimensions.map(dimension => <Choice key={dimension.code} checked={draft.dimensions.includes(dimension.code)} onChange={() => updateSelection('dimensions', dimension.code, contract.limits.dimensions)}>{dimension.label}</Choice>)}</div>{fieldErrors.dimensions && <p className="mt-2 text-xs font-bold text-red-700">{fieldErrors.dimensions}</p>}</div>{sortOptions.length > 0 && <label className="mt-5 block max-w-md text-sm font-black">ترتيب الخادم<select value={draft.sort ? `${draft.sort.field}:${draft.sort.direction}` : ''} onChange={event => { const [field, direction] = event.target.value.split(':'); setDraft({ ...draft, sort: field ? { field, direction } : null }) }} className="mt-2 w-full rounded-xl border border-black/10 bg-white px-3 py-2 font-normal"><option value="">الترتيب الافتراضي</option>{sortOptions.flatMap(code => [<option key={`${code}:asc`} value={`${code}:asc`}>{code} — تصاعدي</option>, <option key={`${code}:desc`} value={`${code}:desc`}>{code} — تنازلي</option>])}</select></label>}{draft.mode === 'comparison' && <div className="mt-5 rounded-2xl border border-primary/15 bg-primary/5 p-4"><p className="mb-2 text-sm font-black">نوع المقارنة</p><div className="flex flex-wrap gap-2">{comparisons.map(type => <Choice type="radio" key={type} checked={draft.comparison?.type === type} onChange={() => setDraft({ ...draft, comparison: type === 'custom' ? { type, baseline: { filters: {}, labels: {}, period: { type: 'none' } } } : { type } })}>{COMPARISON_LABELS[type]}</Choice>)}</div>{comparisons.length === 0 && <p className="text-xs text-text-light">أكمل الفترة أو اختر بُعدًا غير زمني وقيمتين على الأقل لإتاحة المقارنة المناسبة.</p>}{fieldErrors.comparison && <p className="mt-2 text-xs font-bold text-red-700">{fieldErrors.comparison}</p>}{draft.comparison?.type === 'custom' && <div className="mt-5 space-y-5 border-t border-primary/15 pt-5"><PeriodEditor definition={definition} config={draft.comparison.baseline} prefix="خط الأساس: " onChange={baseline => setDraft({ ...draft, comparison: { ...draft.comparison, baseline } })} /><ScopeFilters title="نطاق خط الأساس" definition={definition} config={draft.comparison.baseline} onChange={baseline => setDraft({ ...draft, comparison: { ...draft.comparison, baseline } })} /></div>}</div>}</section>

    <section className="rounded-3xl border border-primary/20 bg-white p-5 shadow-sm"><h2 className="text-lg font-black">هـ. إنشاء التقرير</h2><div className="mt-4"><ConfigurationSummary config={draft} /></div><div className="mt-4 flex flex-wrap gap-3"><button type="button" onClick={() => run(draft)} disabled={loading} className="flex items-center gap-2 rounded-xl bg-primary px-5 py-3 font-black text-white disabled:opacity-50"><FaChartBar />{loading ? 'جاري إنشاء التقرير...' : 'إنشاء التقرير'}</button><button type="button" onClick={reset} className="flex items-center gap-2 rounded-xl border border-black/10 px-5 py-3 font-bold"><FaRedo />إعادة الضبط</button><span className="flex items-center gap-2 text-xs text-text-light"><FaSlidersH />لن يُغيّر النظام إعداداتك تلقائيًا إذا تجاوزت النتيجة حد التجميع.</span></div>{reportError && <div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800"><p className="font-black">{reportError.message}</p>{reportError.kind === 'point_limit' && <p className="mt-1">قلّل عدد أبعاد التجميع أو اختر نطاقًا أضيق، ثم أعد إنشاء التقرير.</p>}</div>}</section>

    <ReportResults report={report} applied={applied} labels={dimensionLabels} stale={stale} loading={loading} onRefresh={() => run(applied.config, { preserveDraft: true })} onPage={applyPage} onSort={applySort} />
  </div>
}
