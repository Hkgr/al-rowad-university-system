import { useEffect, useRef } from 'react'

export default function ManualGradeDialog({ title, children, busy = false, disabled = false, onConfirm, onCancel, confirmLabel = 'تأكيد' }) {
  const dialog = useRef(null)
  useEffect(() => {
    const previous = document.activeElement
    dialog.current?.showModal()
    return () => { previous?.focus?.() }
  }, [])
  return <dialog ref={dialog} dir="rtl" aria-labelledby="manual-grade-dialog-title"
    onCancel={event => { event.preventDefault(); if (!busy) onCancel() }}
    className="w-[95vw] max-w-xl rounded-2xl border border-primary/20 p-6 shadow-xl backdrop:bg-black/50">
    <h2 id="manual-grade-dialog-title" className="text-lg font-bold text-primary mb-4">{title}</h2>
    <div className="space-y-3">{children}</div>
    <div className="flex gap-3 mt-5">
      <button type="button" disabled={busy || disabled} onClick={onConfirm} className="rounded-lg bg-primary px-4 py-2 text-white disabled:opacity-50">{busy ? 'جاري التنفيذ…' : confirmLabel}</button>
      <button type="button" disabled={busy} onClick={onCancel} className="rounded-lg border px-4 py-2">إلغاء</button>
    </div>
  </dialog>
}
