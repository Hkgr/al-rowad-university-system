import { useEffect, useId, useRef } from 'react'
import { FaEnvelope, FaTimes } from 'react-icons/fa'

/** Email-only layout; shared grade/portal dialogs are intentionally unchanged. */
export default function UniversityEmailDialog({ title, subtitle, children, footer, busy, onClose }) {
  const dialog = useRef(null), titleId = useId()
  useEffect(() => { const previous = document.activeElement; dialog.current?.showModal(); return () => previous?.focus?.() }, [])
  return <dialog ref={dialog} dir="rtl" aria-labelledby={titleId} onCancel={event => { event.preventDefault(); if (!busy) onClose() }}
    className="m-auto w-[calc(100%_-_2rem)] max-w-[780px] max-h-[calc(100dvh_-_32px)] overflow-hidden rounded-[16px] border border-primary/12 bg-white p-0 text-text-gray shadow-[0_14px_38px_rgba(26,46,16,0.12)] backdrop:bg-black/45">
    <div className="flex max-h-[calc(100dvh_-_34px)] flex-col">
      <header className="flex shrink-0 items-center gap-3 border-b border-primary/10 px-5 py-4">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/8 text-primary"><FaEnvelope /></span>
        <div className="min-w-0 flex-1"><h2 id={titleId} className="text-[16px] font-black text-text-dark">{title}</h2><p className="mt-1 text-[12px] text-text-light">{subtitle}</p></div>
        <button type="button" aria-label="إغلاق" disabled={busy} onClick={onClose} className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[8px] text-text-light hover:bg-primary/5 disabled:opacity-50"><FaTimes /></button>
      </header>
      <div className="min-h-0 space-y-4 overflow-y-auto px-5 py-5 text-[13px] leading-7">{children}</div>
      <footer className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-primary/10 bg-primary/[0.02] px-5 py-3.5">{footer}</footer>
    </div>
  </dialog>
}
