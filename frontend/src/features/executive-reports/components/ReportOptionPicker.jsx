import { useEffect, useMemo, useState } from 'react'
import { fetchExecutiveReportOptions } from '../api'

export default function ReportOptionPicker({
  resource, label, values = [], labels = {}, onChange, codeValues = false,
  parentName, parentValues = [], parentLabels = {}, extraParameters = {}, disabled = false,
}) {
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState({ options: [], pagination: null })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [parentFocus, setParentFocus] = useState(parentValues.length === 1 ? String(parentValues[0]) : '')
  const extraParametersKey = JSON.stringify(extraParameters)

  useEffect(() => {
    const timer = setTimeout(() => { setDebouncedSearch(search.trim()); setPage(1) }, 350)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (parentValues.length === 1) setParentFocus(String(parentValues[0]))
    else if (parentFocus && !parentValues.some(value => String(value) === parentFocus)) setParentFocus('')
  }, [parentValues, parentFocus])

  const parameters = useMemo(() => ({
    resource, q: debouncedSearch, page, per_page: 25,
    ...(parentName && parentFocus ? { [parentName]: parentFocus } : {}),
    ...JSON.parse(extraParametersKey),
  }), [resource, debouncedSearch, page, parentName, parentFocus, extraParametersKey])

  useEffect(() => {
    if (disabled) return undefined
    const controller = new AbortController()
    let active = true
    setLoading(true); setError('')
    fetchExecutiveReportOptions(parameters, controller.signal)
      .then(data => { if (active) setResult(data) })
      .catch(requestError => { if (active && requestError.name !== 'AbortError') setError('تعذر تحميل الخيارات.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false; controller.abort() }
  }, [disabled, parameters])

  const toggle = option => {
    const value = codeValues ? String(option.code ?? option.id) : Number(option.id)
    const exists = values.some(item => String(item) === String(value))
    const nextValues = exists ? values.filter(item => String(item) !== String(value)) : [...values, value]
    const nextLabels = { ...labels }
    if (exists) delete nextLabels[String(value)]
    else nextLabels[String(value)] = option.code ? `${option.code} — ${option.label}` : option.label
    onChange(nextValues, nextLabels)
  }

  return (
    <fieldset className="rounded-2xl border border-primary/15 bg-white p-4" disabled={disabled}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <legend className="text-sm font-black text-text-dark">{label}</legend>
        {values.length > 0 && <button type="button" onClick={() => onChange([], {})} className="text-xs font-bold text-primary hover:underline">مسح الاختيار</button>}
      </div>
      {parentName && parentValues.length > 1 && (
        <label className="mt-3 block text-xs font-bold text-text-light">
          نطاق التصفح
          <select value={parentFocus} onChange={event => { setParentFocus(event.target.value); setPage(1) }} className="mt-1 w-full rounded-xl border border-black/10 bg-white px-3 py-2 text-sm text-text-dark">
            <option value="">كل النطاقات المصرح بها</option>
            {parentValues.map(value => <option key={value} value={String(value)}>{parentLabels[String(value)] ?? `المعرف ${value}`}</option>)}
          </select>
        </label>
      )}
      <input value={search} onChange={event => setSearch(event.target.value)} placeholder={`بحث في ${label}`} className="mt-3 w-full rounded-xl border border-black/10 px-3 py-2 text-sm outline-none focus:border-primary" />
      {values.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2" aria-label={`الاختيارات الحالية: ${label}`}>
          {values.map(value => <span key={String(value)} className="rounded-full bg-primary/10 px-3 py-1 text-xs font-bold text-primary-dark">{labels[String(value)] ?? `المعرف ${value}`}</span>)}
        </div>
      )}
      <div className="mt-3 max-h-48 space-y-1 overflow-y-auto" aria-live="polite">
        {loading && <p className="py-3 text-center text-xs text-text-light">جاري تحميل الخيارات...</p>}
        {!loading && error && <p className="py-2 text-xs font-bold text-red-700">{error}</p>}
        {!loading && !error && result.options.length === 0 && <p className="py-2 text-xs text-text-light">لا توجد خيارات مطابقة.</p>}
        {!loading && !error && result.options.map(option => {
          const value = codeValues ? String(option.code ?? option.id) : Number(option.id)
          const checked = values.some(item => String(item) === String(value))
          return <label key={String(value)} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-primary/5"><input type="checkbox" checked={checked} onChange={() => toggle(option)} /><span>{option.code ? `${option.code} — ` : ''}{option.label}</span></label>
        })}
      </div>
      {result.pagination?.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between text-xs">
          <button type="button" disabled={page <= 1} onClick={() => setPage(current => current - 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">السابق</button>
          <span>صفحة {page} من {result.pagination.last_page}</span>
          <button type="button" disabled={page >= result.pagination.last_page} onClick={() => setPage(current => current + 1)} className="rounded-lg border px-3 py-1.5 disabled:opacity-40">التالي</button>
        </div>
      )}
      <p className="mt-2 text-[11px] text-text-light">عرض صفحة البحث الحالية لا يغيّر معنى «الكل» ولا يحذف اختيارات الصفحات الأخرى.</p>
    </fieldset>
  )
}
