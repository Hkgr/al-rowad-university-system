import { useEffect, useId, useRef, useState } from 'react'
import useCatalogRead from './useCatalogRead'
import { catalogError, queryString } from './catalog'

export function Button({ children, primary = false, danger = false, ...props }) {
  return <button type="button" className={`rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-50 disabled:cursor-not-allowed ${primary ? 'bg-primary text-white border-primary' : danger ? 'text-red-700 border-red-200 bg-white' : 'bg-white border-black/10 text-text-dark'}`} {...props}>{children}</button>
}
export function Field({ label, children, error, note }) {
  return <label className="flex min-w-0 flex-col gap-1 text-sm text-text-dark"><span className="font-bold">{label}</span>{children}{note && <span className="text-xs text-text-light">{note}</span>}{error && <span role="alert" className="text-xs text-red-700">{[].concat(error).join('، ')}</span>}</label>
}
export function Input(props) { return <input className="w-full min-w-0 rounded-xl border border-black/15 bg-white px-3 py-2 disabled:bg-gray-100 disabled:text-gray-500" {...props} /> }
export function Select({ children, ...props }) { return <select className="w-full min-w-0 rounded-xl border border-black/15 bg-white px-3 py-2 disabled:bg-gray-100" {...props}>{children}</select> }
export function Notice({ children, error = false }) { return children ? <div role={error ? 'alert' : 'status'} className={`rounded-xl border p-3 text-sm ${error ? 'border-red-200 bg-red-50 text-red-800' : 'border-primary/15 bg-primary/5 text-text-dark'}`}>{children}</div> : null }
export function Pager({ meta, onPage, disabled }) {
  return meta ? <nav aria-label="ترقيم الصفحات" className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span>الصفحة {meta.current_page} من {meta.last_page} — {meta.total} نتيجة</span><div className="flex gap-2"><Button disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>السابق</Button><Button disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>التالي</Button></div></nav> : null
}
export function CatalogDialog({ title, children, onClose }) {
  const ref = useRef(null), heading = useId()
  useEffect(() => { const node = ref.current, previous = document.activeElement; node.showModal(); return () => { node.close(); if (previous?.isConnected) previous.focus() } }, [])
  return <dialog ref={ref} aria-labelledby={heading} dir="rtl" onCancel={e => { e.preventDefault(); onClose() }} className="m-auto w-[min(94vw,720px)] max-h-[90dvh] overflow-y-auto rounded-2xl border border-primary/15 p-5 shadow-xl backdrop:bg-black/40"><h2 id={heading} className="mb-4 text-lg font-black text-primary">{title}</h2>{children}</dialog>
}

/** Search is paginated; the selected label survives query/page changes. */
export function CatalogLookup({ label, resource, value, onChange, context = {}, disabled = false, clearLabel = 'كل الخيارات' }) {
  const [q, setQ] = useState(''), [page, setPage] = useState(1), [retry, setRetry] = useState(0)
  const read = useCatalogRead(disabled ? null : `/options?${queryString({ resource, ...context, q: q.trim(), page })}`, { delay: 350, refresh: retry })
  const options = read.data?.data || [], selectedAbsent = value && !options.some(o => String(o.id) === String(value.id))
  return <div className="min-w-0 space-y-1"><Field label={label}><Select aria-label={label} value={value?.id ?? ''} disabled={disabled} onChange={e => onChange(options.find(o => String(o.id) === e.target.value) || null)}><option value="">{clearLabel}</option>{selectedAbsent && <option value={value.id}>{value.label}</option>}{options.map(o => <option key={o.id} value={o.id}>{o.label}</option>)}</Select></Field>
    {!disabled && <><Input aria-label={`بحث ${label}`} placeholder={`بحث ${label}`} value={q} onChange={e => { setQ(e.target.value); setPage(1) }} />{read.loading && <p className="text-xs" role="status">تحميل الخيارات…</p>}{read.error && <div><Notice error>{catalogError(read.error)}</Notice><Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل الخيارات</Button></div>}{read.data?.meta.last_page > 1 && <Pager meta={read.data.meta} onPage={setPage} disabled={read.loading} />}</>}
  </div>
}
