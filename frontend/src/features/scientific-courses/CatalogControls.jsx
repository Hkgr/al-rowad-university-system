import { useEffect, useId, useRef, useState } from 'react'
import useCatalogRead from './useCatalogRead'
import { catalogError, queryString } from './catalog'
import { FaTimes } from 'react-icons/fa'

export function Button({ children, primary = false, danger = false, ...props }) {
  return <button type="button" className={`inline-flex items-center justify-center gap-2 rounded-[10px] border px-4 py-2.5 text-[12px] font-bold leading-5 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50 disabled:cursor-not-allowed ${primary ? 'bg-primary text-white border-primary hover:bg-primary-dark' : danger ? 'text-red-600 border-red-300 bg-white hover:bg-red-50' : 'bg-white border-primary/15 text-text-gray hover:bg-primary/[0.05]'}`} {...props}>{children}</button>
}
export function Field({ label, children, error, note }) {
  return <label className="flex min-w-0 flex-col gap-1.5 text-[12px] text-text-dark"><span className="font-bold">{label}</span>{children}{note && <span className="text-[11.5px] text-text-light">{note}</span>}{error && <span role="alert" className="text-[12px] text-red-600">{[].concat(error).join('، ')}</span>}</label>
}
export function Input(props) { return <input className="w-full min-w-0 px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] leading-normal text-text-dark outline-none focus:border-primary disabled:bg-gray-100 disabled:text-text-light" {...props} /> }
export function Select({ children, ...props }) { return <select className="w-full min-w-0 px-3 py-2.5 border border-primary/20 rounded-[10px] bg-white text-[13.5px] leading-normal text-text-dark outline-none focus:border-primary disabled:bg-gray-100" {...props}>{children}</select> }
export function Notice({ children, error = false }) { return children ? <div role={error ? 'alert' : 'status'} className={`rounded-[10px] border px-4 py-2.5 text-[12.5px] leading-7 ${error ? 'border-red-200 bg-red-50 text-red-600' : 'border-primary/15 bg-primary/[0.05] text-text-gray'}`}>{children}</div> : null }
export function Pager({ meta, onPage, disabled }) {
  return meta ? <nav aria-label="ترقيم الصفحات" className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span>الصفحة {meta.current_page} من {meta.last_page} — {meta.total} نتيجة</span><div className="flex gap-2"><Button disabled={disabled || meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>السابق</Button><Button disabled={disabled || meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>التالي</Button></div></nav> : null
}
// Native focus/escape behavior; geometry and typography match ManualGradeDialog.
// Wide editing surface follows the existing DeanTimetableDialog, not a new shell.
export function CatalogDialog({ title, children, onClose, wide = false, closeButton = false }) {
  const ref = useRef(null), heading = useId()
  useEffect(() => { const node = ref.current, previous = document.activeElement; node.showModal(); return () => { node.close(); if (previous?.isConnected) previous.focus() } }, [])
  return <dialog ref={ref} aria-labelledby={heading} dir="rtl" onCancel={e => { e.preventDefault(); onClose() }} className={`m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] ${wide ? 'max-w-3xl' : 'max-w-xl'} overflow-y-auto rounded-[16px] border border-primary/12 bg-white p-0 text-text-gray shadow-[0_14px_38px_rgba(26,46,16,0.12)] backdrop:bg-black/45`}><div className="flex items-start justify-between gap-3 border-b border-primary/10 px-5 py-4 max-[400px]:px-4"><h2 id={heading} className="text-[16px] font-black leading-7 text-text-dark">{title}</h2>{closeButton && <button type="button" aria-label="إغلاق النافذة" onClick={onClose} className="p-1 text-text-light hover:text-text-dark"><FaTimes /></button>}</div><div className="space-y-3 break-words px-5 py-4 text-[12.5px] leading-7 max-[400px]:px-4">{children}</div></dialog>
}

/** Search is paginated; the selected label survives query/page changes. */
export function CatalogLookup({ label, resource, value, onChange, context = {}, disabled = false, clearLabel = 'كل الخيارات' }) {
  const [q, setQ] = useState(''), [page, setPage] = useState(1), [retry, setRetry] = useState(0)
  const read = useCatalogRead(disabled ? null : `/options?${queryString({ resource, ...context, q: q.trim(), page })}`, { delay: 350, refresh: retry })
  const options = read.data?.data || [], selectedAbsent = value && !options.some(o => String(o.id) === String(value.id))
  return <div className="min-w-0 space-y-1"><Field label={label}><Select aria-label={label} value={value?.id ?? ''} disabled={disabled} onChange={e => onChange(options.find(o => String(o.id) === e.target.value) || null)}><option value="">{clearLabel}</option>{selectedAbsent && <option value={value.id}>{value.label}</option>}{options.map(o => <option key={o.id} value={o.id}>{o.label}</option>)}</Select></Field>
    {!disabled && <details className="text-[11.5px] text-text-light"><summary className="cursor-pointer py-1">بحث في الخيارات</summary><Input aria-label={`بحث ${label}`} placeholder={`بحث ${label}`} value={q} onChange={e => { setQ(e.target.value); setPage(1) }} />{read.data?.meta.last_page > 1 && <Pager meta={read.data.meta} onPage={setPage} disabled={read.loading} />}</details>}{!disabled && read.error && <div><Notice error>{catalogError(read.error)}</Notice><Button onClick={() => setRetry(x => x + 1)}>إعادة تحميل الخيارات</Button></div>}
  </div>
}
