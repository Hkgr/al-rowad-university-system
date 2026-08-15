import { useEffect } from 'react'
import { FaSpinner, FaTimes } from 'react-icons/fa'

export default function StudentConfirmDialog({
  title,
  confirmLabel,
  cancelLabel = 'إلغاء',
  confirmTone = 'primary',
  busy = false,
  disabled = false,
  onConfirm,
  onCancel,
  children,
}) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === 'Escape' && !busy) onCancel()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busy, onCancel])

  const confirmClass = confirmTone === 'danger'
    ? 'bg-red-600 hover:bg-red-700 text-white'
    : 'bg-primary hover:bg-primary-dark text-white'

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
      dir="rtl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="student-confirm-title"
      onClick={event => {
        if (event.target === event.currentTarget && !busy) onCancel()
      }}
    >
      <div className="w-full sm:max-w-[520px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
        <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
          <h3 id="student-confirm-title" className="text-[16px] font-black text-text-dark">{title}</h3>
          <button
            type="button"
            className="p-2 text-text-light hover:text-text-dark disabled:opacity-40"
            onClick={onCancel}
            disabled={busy}
            aria-label="إغلاق"
            title="إغلاق"
          >
            <FaTimes aria-hidden="true" />
          </button>
        </div>
        <div className="px-5 py-4 space-y-3">{children}</div>
        <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-primary/10">
          <button
            type="button"
            className="px-4 py-2 border border-primary/20 rounded-[10px] text-[13px] font-bold text-text-gray hover:bg-primary/5 disabled:opacity-40"
            onClick={onCancel}
            disabled={busy}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            className={`flex items-center justify-center gap-2 px-4 py-2 rounded-[10px] text-[13px] font-bold disabled:opacity-50 ${confirmClass}`}
            onClick={onConfirm}
            disabled={busy || disabled}
          >
            {busy ? <FaSpinner className="animate-spin text-[12px]" aria-hidden="true" /> : null}
            <span>{busy ? 'جاري التنفيذ' : confirmLabel}</span>
          </button>
        </div>
      </div>
    </div>
  )
}
