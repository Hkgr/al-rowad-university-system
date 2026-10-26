import { useEffect, useRef } from 'react'
import { FaFilePdf, FaSpinner, FaTimes } from 'react-icons/fa'

// Uses the exam office's existing ApprovalsPage dialog/control presentation.
// Request metadata is optional, local to this export, and never persisted.
export default function ExamTranscriptRequestDialog({ busy, error, onClose, onExport }) {
  const dialog = useRef(null)
  useEffect(() => {
    const element = dialog.current
    element.showModal()
    return () => element.close()
  }, [])

  return (
    <dialog
      ref={dialog}
      dir="rtl"
      aria-labelledby="exam-transcript-dialog-title"
      onCancel={event => { if (busy) event.preventDefault(); else onClose() }}
      className="fixed inset-0 m-auto max-h-[90vh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-[16px] border-0 bg-white p-0 text-text-dark shadow-2xl backdrop:bg-black/45"
    >
      <div className="flex items-center justify-between border-b border-primary/10 p-5">
        <h3 id="exam-transcript-dialog-title" className="font-black">استخراج كشف الدرجات المعتمد</h3>
        <button type="button" disabled={busy} onClick={onClose} aria-label="إغلاق" className="p-2 text-text-light disabled:opacity-40"><FaTimes /></button>
      </div>
      <form className="space-y-4 p-5" onSubmit={event => {
        event.preventDefault()
        const data = new FormData(event.currentTarget)
        onExport({ number: String(data.get('number') || '').trim(), date: String(data.get('date') || '') })
      }}>
        <p className="text-[12.5px] leading-7 text-text-gray">يمكن إدخال رقم طلب الكشف وتاريخه إن توفرا. الحقلان اختياريان، وتبقى الخانات فارغة عند عدم إدخالهما.</p>
        <div className="grid grid-cols-2 gap-4 max-[460px]:grid-cols-1">
          <label className="text-xs font-bold">رقم الطلب (اختياري)
            <input name="number" maxLength={80} disabled={busy} autoComplete="off" className="mt-2 w-full rounded-[10px] border border-primary/20 p-3 text-[13px] outline-none focus:border-primary" />
          </label>
          <label className="text-xs font-bold">تاريخ الطلب (اختياري)
            <input name="date" type="date" disabled={busy} className="mt-2 w-full rounded-[10px] border border-primary/20 p-3 text-[13px] outline-none focus:border-primary" />
          </label>
        </div>
        {error ? <p className="text-[12.5px] font-semibold text-red-700" role="alert">{error}</p> : null}
        <div className="flex justify-end gap-3 pt-1">
          <button type="button" disabled={busy} onClick={onClose} className="rounded-[8px] border border-primary/20 px-4 py-2 font-bold disabled:opacity-50">إلغاء</button>
          <button type="submit" disabled={busy} className="inline-flex items-center gap-2 rounded-[8px] bg-primary px-4 py-2 font-bold text-white disabled:opacity-50">
            {busy ? <FaSpinner className="animate-spin" aria-hidden="true" /> : <FaFilePdf aria-hidden="true" />}
            {busy ? 'جاري إنشاء الملف...' : 'تصدير PDF'}
          </button>
        </div>
      </form>
    </dialog>
  )
}
