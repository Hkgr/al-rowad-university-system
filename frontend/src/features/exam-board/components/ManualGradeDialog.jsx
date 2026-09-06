import { useEffect, useRef } from 'react'

export default function ManualGradeDialog({ title, children, busy = false, disabled = false, onConfirm, onCancel, confirmLabel = 'تأكيد', confirmTone = 'primary' }) {
  const dialog = useRef(null)
  useEffect(() => {
    const previous = document.activeElement
    dialog.current?.showModal()
    return () => { previous?.focus?.() }
  }, [])
  return <dialog ref={dialog} dir="rtl" aria-labelledby="manual-grade-dialog-title"
    onCancel={event => { event.preventDefault(); if (!busy) onCancel() }}
    className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-[16px] border border-primary/12 bg-white p-0 text-text-gray shadow-[0_14px_38px_rgba(26,46,16,0.12)] backdrop:bg-black/45">
    <div className="border-b border-primary/10 px-5 py-4 max-[400px]:px-4"><h2 id="manual-grade-dialog-title" className="text-[16px] font-black leading-7 text-text-dark">{title}</h2></div>
    <div className="space-y-3 break-words px-5 py-4 text-[12.5px] leading-7 max-[400px]:px-4">{children}</div>
    <div className="flex flex-wrap justify-end gap-2 border-t border-primary/10 bg-primary/[0.02] px-5 py-3.5 max-[400px]:px-4">
      <button type="button" disabled={busy || disabled} onClick={onConfirm} className={`rounded-[10px] border px-4 py-2.5 text-[12px] font-bold leading-5 text-white transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50 ${confirmTone === 'discard' ? 'border-red-600 bg-red-600 hover:bg-red-700' : 'border-primary bg-primary hover:bg-primary-dark'}`}>{busy ? 'جاري التنفيذ…' : confirmLabel}</button>
      <button type="button" disabled={busy} onClick={onCancel} className="rounded-[10px] border border-primary/15 bg-white px-4 py-2.5 text-[12px] font-bold leading-5 text-text-gray transition-colors hover:bg-primary/[0.05] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50">إلغاء</button>
    </div>
  </dialog>
}
