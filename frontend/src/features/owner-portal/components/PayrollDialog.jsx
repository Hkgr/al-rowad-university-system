import { useEffect, useRef } from 'react'
import { FaTimes } from 'react-icons/fa'

/** Wide modal for the payroll tools (native <dialog>: focus trap, Esc, backdrop). Closing never saves anything by itself. */
export default function PayrollDialog({ title, subtitle, onClose, children, footer, wide = true, labelledBy = 'payroll-dialog-title' }) {
  const dialog = useRef(null)
  useEffect(() => {
    const previous = document.activeElement
    dialog.current?.showModal()
    return () => { previous?.focus?.() }
  }, [])
  return (
    <dialog
      ref={dialog} dir="rtl" aria-labelledby={labelledBy}
      onCancel={event => { event.preventDefault(); onClose() }}
      className={`m-auto flex max-h-[92dvh] w-[calc(100%_-_1.5rem)] ${wide ? 'max-w-5xl' : 'max-w-xl'} flex-col overflow-hidden rounded-[16px] border border-primary/12 bg-white p-0 text-text-gray shadow-[0_14px_38px_rgba(26,46,16,0.14)] backdrop:bg-black/45`}
    >
      <div className="flex items-start justify-between gap-3 border-b border-primary/10 px-5 py-3.5 max-[400px]:px-4">
        <div>
          <h2 id={labelledBy} className="text-[16px] font-black leading-7 text-text-dark">{title}</h2>
          {subtitle && <p className="text-[11.5px] leading-6 text-text-light">{subtitle}</p>}
        </div>
        <button type="button" onClick={onClose} aria-label="إغلاق" className="rounded-[9px] border border-primary/15 p-2 text-text-gray hover:bg-primary/[0.06] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"><FaTimes aria-hidden="true" /></button>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 text-[12.5px] leading-7 max-[400px]:px-4">{children}</div>
      {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-primary/10 bg-primary/[0.02] px-5 py-3 max-[400px]:px-4">{footer}</div>}
    </dialog>
  )
}
