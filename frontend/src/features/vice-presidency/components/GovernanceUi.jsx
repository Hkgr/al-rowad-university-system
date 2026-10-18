import { motion } from 'framer-motion'
import { FaTimes } from 'react-icons/fa'

// Same dialog / field / notice styling as the technical portal account pages
// (features/technical-portal/components/*), which follow the HR EmployeesPage reference.

export const inputClass = 'w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark bg-white outline-none focus:border-primary disabled:bg-gray-50'

export function Field({ label, error, hint, children, wide = false }) {
  return (
    <div className={wide ? 'col-span-2 max-[560px]:col-span-1' : undefined}>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1">
        {label}
        {children}
      </label>
      {hint && <p className="mt-1 text-[11px] text-text-light">{hint}</p>}
      {error && <p className="mt-1 text-[11.5px] text-red-600">{error}</p>}
    </div>
  )
}

export function Dialog({ title, onClose, children, footer, as = 'div', onSubmit, maxWidth = 'max-w-[680px]' }) {
  const Component = as === 'form' ? motion.form : motion.div
  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <Component
        onSubmit={onSubmit}
        className={`bg-white rounded-[18px] shadow-2xl w-full ${maxWidth} max-h-[92vh] overflow-y-auto`}
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }}
        dir="rtl" role="dialog" aria-label={title} autoComplete="off"
      >
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10 sticky top-0 bg-white z-[1]">
          <h3 className="text-[16px] font-extrabold text-text-dark">{title}</h3>
          <button type="button" onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors" aria-label="إغلاق"><FaTimes /></button>
        </div>
        {children}
        {footer && <div className="flex items-center justify-end gap-3 px-6 pb-5 pt-1 flex-wrap">{footer}</div>}
      </Component>
    </div>
  )
}

export function Notice({ tone = 'info', children, onDismiss, action }) {
  const tones = {
    info: 'bg-primary/5 border-primary/15 text-text-gray',
    success: 'bg-green-50 border-green-200 text-green-700',
    warning: 'bg-amber-50 border-amber-200 text-amber-800',
    error: 'bg-red-50 border-red-200 text-red-600',
  }
  return (
    <div className={`flex items-center justify-between gap-3 border rounded-[12px] px-4 py-2.5 text-[12.5px] ${tones[tone]}`} role={tone === 'error' ? 'alert' : 'status'}>
      <span>{children}</span>
      <span className="flex items-center gap-2">
        {action}
        {onDismiss && <button type="button" onClick={onDismiss} aria-label="إخفاء الرسالة" className="opacity-70 hover:opacity-100"><FaTimes /></button>}
      </span>
    </div>
  )
}

export const primaryButton = 'flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors'
export const secondaryButton = 'px-5 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors disabled:opacity-50'
export const dangerButton = 'flex items-center gap-2 px-4 py-2 border border-red-200 bg-red-50 text-red-600 rounded-[9px] text-[12.5px] font-bold hover:bg-red-100 disabled:opacity-50 transition-colors'
export const headerButton = 'flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)] transition-all duration-[220ms]'
