import { useEffect, useId, useState } from 'react'
import { FaSpinner, FaTimes } from 'react-icons/fa'
import { periodOperationalMessage, periodStatusLabel, workflowStatusLabel } from './supplementaryStatus'

export function SupplementaryStatusBadge({ status, kind = 'period' }) {
  const label = kind === 'workflow' ? workflowStatusLabel(status) : periodStatusLabel(status)
  const positive = ['registration_open', 'grading_open', 'published', 'results_published', 'results_materialized'].includes(status)
  const warning = ['returned', 'grading_submitted', 'results_approved'].includes(status)
  const tone = positive
    ? 'border-primary/20 bg-primary/10 text-primary-dark'
    : warning ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-text-gray'
  return <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-bold ${tone}`}>{label}</span>
}

export function SupplementaryPeriodHeader({ period, title, children }) {
  const status = period?.status
  return <section className="rounded-[18px] border border-primary/15 bg-white p-5 shadow-sm" dir="rtl">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p className="text-xs font-bold text-primary">الامتحانات التكميلية</p>
        <h1 className="mt-1 text-xl font-black text-text-dark">{title}</h1>
        <p className="mt-2 text-sm text-text-gray">{period?.period_name ?? 'لم تُحدد دورة بعد'}</p>
      </div>
      {period && <SupplementaryStatusBadge status={status} />}
    </div>
    {period && <p className="mt-4 rounded-xl bg-primary/5 px-4 py-3 text-sm text-text-gray">{periodOperationalMessage(status)}</p>}
    {children}
  </section>
}

export function SupplementaryWorkflowSteps({ steps = [] }) {
  return <ol className="grid gap-2 sm:grid-cols-2 xl:grid-cols-4" aria-label="مراحل دورة الامتحانات التكميلية">
    {steps.map((step, index) => <li key={step.code ?? index} className={`rounded-xl border px-3 py-3 text-sm ${step.state === 'current' ? 'border-primary bg-primary/10 text-primary-dark' : step.state === 'complete' ? 'border-primary/20 bg-white text-text-dark' : 'border-slate-200 bg-slate-50 text-text-light'}`}>
      <span className="ml-2 font-black">{index + 1}</span>{step.label ?? periodStatusLabel(step.code)}
    </li>)}
  </ol>
}

export function SupplementaryMetricCard({ label, value, hint }) {
  return <article className="rounded-[16px] border border-primary/10 bg-white p-4 shadow-sm">
    <p className="text-xs font-bold text-text-light">{label}</p>
    <p className="mt-1 text-2xl font-black text-primary-dark">{value ?? 0}</p>
    {hint && <p className="mt-1 text-xs text-text-gray">{hint}</p>}
  </article>
}

export function SupplementaryEmptyState({ title, description }) {
  return <div className="rounded-[16px] border border-dashed border-primary/20 bg-primary/5 p-8 text-center" role="status">
    <h3 className="font-black text-text-dark">{title}</h3>
    {description && <p className="mt-2 text-sm text-text-gray">{description}</p>}
  </div>
}

export function SupplementaryNotice({ tone = 'info', children }) {
  const classes = tone === 'error' ? 'border-red-200 bg-red-50 text-red-700' : tone === 'warning' ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-primary/20 bg-primary/5 text-primary-dark'
  return <p className={`rounded-xl border px-4 py-3 text-sm font-semibold ${classes}`} role={tone === 'error' ? 'alert' : 'status'}>{children}</p>
}

export function SupplementaryConfirmDialog({
  title,
  description,
  confirmLabel = 'تأكيد',
  reasonLabel,
  reasonRequired = false,
  busy = false,
  danger = false,
  onCancel,
  onConfirm,
}) {
  const titleId = useId()
  const [reason, setReason] = useState('')
  useEffect(() => {
    const close = (event) => event.key === 'Escape' && !busy && onCancel()
    window.addEventListener('keydown', close)
    return () => window.removeEventListener('keydown', close)
  }, [busy, onCancel])

  return <div className="fixed inset-0 z-[90] flex items-end justify-center bg-black/45 sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby={titleId} dir="rtl">
    <div className="max-h-[96vh] w-full overflow-y-auto rounded-t-[18px] bg-white shadow-2xl sm:max-w-[520px] sm:rounded-[18px]">
      <header className="flex items-center justify-between border-b border-primary/10 px-5 py-4">
        <h2 id={titleId} className="font-black text-text-dark">{title}</h2>
        <button type="button" onClick={onCancel} disabled={busy} aria-label="إغلاق" className="p-2 text-text-light"><FaTimes /></button>
      </header>
      <div className="space-y-4 p-5">
        {description && <p className="text-sm leading-7 text-text-gray">{description}</p>}
        {reasonLabel && <label className="block text-sm font-bold text-text-dark">{reasonLabel}
          <textarea autoFocus rows={4} maxLength={2000} value={reason} onChange={(event) => setReason(event.target.value)} className="mt-2 w-full rounded-xl border border-primary/20 p-3 outline-none focus:border-primary" />
        </label>}
      </div>
      <footer className="flex justify-end gap-2 border-t border-primary/10 p-4">
        <button type="button" onClick={onCancel} disabled={busy} className="rounded-lg border border-primary/20 px-4 py-2 font-bold">إلغاء</button>
        <button type="button" onClick={() => onConfirm(reason.trim())} disabled={busy || (reasonRequired && !reason.trim())} className={`flex items-center gap-2 rounded-lg px-4 py-2 font-bold text-white disabled:opacity-50 ${danger ? 'bg-red-600' : 'bg-primary'}`}>
          {busy && <FaSpinner className="animate-spin" />}{confirmLabel}
        </button>
      </footer>
    </div>
  </div>
}
