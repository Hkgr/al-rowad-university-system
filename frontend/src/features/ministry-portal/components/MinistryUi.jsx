import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaSpinner, FaInfoCircle, FaExclamationTriangle, FaLock, FaSyncAlt, FaArrowRight } from 'react-icons/fa'
import { errorMessage, formatNumber } from '../lib/ministryState'

// Shared building blocks of the ministry portal. They follow the existing look:
// the technical-portal header card, DataTable/FilterBar, and the VP dashboard KPI cards.

export function PageHeader({ title, en, subtitle, back, children }) {
  return (
    <motion.div
      className="flex items-center justify-between gap-4 flex-wrap bg-white border border-primary/15 rounded-[18px] px-7 py-[20px] mb-5 relative overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)] max-[820px]:px-5"
      initial={{ opacity: 0, y: -10 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.35 }}
    >
      <div className="absolute top-0 left-0 right-0 h-1" style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327)' }} />
      <div dir="rtl" className="min-w-0">
        {back && (
          <Link to={back.to} className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-primary hover:text-primary-dark mb-1.5">
            <FaArrowRight className="text-[10px]" />{back.label}
          </Link>
        )}
        <h2 className="text-[20px] font-black text-text-dark mb-0.5 break-words">
          {title}
          {en && <span className="inline-block text-[13px] font-medium text-text-light mr-3" dir="ltr">{en}</span>}
        </h2>
        {subtitle && <p className="text-[12.5px] text-text-light">{subtitle}</p>}
      </div>
      {children && <div className="flex items-center gap-2 flex-wrap" dir="rtl">{children}</div>}
    </motion.div>
  )
}

export function StatePanel({ state, error, onRetry, emptyTitle = 'لا توجد بيانات مسجلة', emptyHint, message }) {
  if (state === 'loading') {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light text-[14px] font-medium bg-white rounded-[16px] border border-primary/12" role="status">
        <FaSpinner className="text-[26px] animate-[spin_0.7s_linear_infinite]" />
        <span>جاري التحميل…</span>
      </div>
    )
  }
  if (state === 'forbidden' || state === 'error' || state === 'notFound') {
    const Icon = state === 'forbidden' ? FaLock : FaExclamationTriangle
    return (
      <div className={`flex flex-col items-center gap-3 py-12 px-6 rounded-[16px] border text-center ${state === 'forbidden' ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-red-50 border-red-200 text-red-700'}`} role="alert" dir="rtl">
        <Icon className="text-[26px]" />
        <p className="text-[14px] font-bold">{message || errorMessage(error)}</p>
        {onRetry && state === 'error' && (
          <button type="button" onClick={onRetry} className="flex items-center gap-2 px-4 py-2 rounded-[10px] border border-red-300 bg-white text-[13px] font-bold hover:bg-red-100">
            <FaSyncAlt className="text-[11px]" /> إعادة المحاولة
          </button>
        )}
      </div>
    )
  }
  if (state === 'empty' || state === 'emptyFiltered') {
    return (
      <div className="flex flex-col items-center gap-2 py-12 bg-white rounded-[16px] border border-primary/12 text-center" dir="rtl">
        <p className="text-[15px] font-bold text-text-gray">{state === 'emptyFiltered' ? 'لا توجد نتائج مطابقة للمرشحات' : emptyTitle}</p>
        {emptyHint && <p className="text-[12.5px] text-text-light">{emptyHint}</p>}
      </div>
    )
  }
  return null
}

export function Section({ title, subtitle, children, action, id }) {
  return (
    <section className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-w-0" dir="rtl" aria-labelledby={id}>
      <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
        <div className="min-w-0">
          <h3 id={id} className="text-[15px] font-extrabold text-text-dark">{title}</h3>
          {subtitle && <p className="text-[11.5px] text-text-light mt-0.5 leading-5">{subtitle}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  )
}

export function StatCard({ label, value, note, to, definition, Icon, unavailableReason, format = formatNumber }) {
  const unavailable = unavailableReason || value === null || value === undefined
  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="text-[12px] font-bold text-text-light leading-5">{label}</p>
        {Icon && <span className="rounded-xl bg-primary/10 p-2 text-primary flex-shrink-0"><Icon aria-hidden="true" /></span>}
      </div>
      <p className="mt-2 text-[26px] font-black text-primary-dark leading-none">{unavailable ? 'غير متاح' : format(value)}</p>
      {(unavailableReason || note) && <p className="mt-2 text-[11px] text-text-light leading-5">{unavailableReason || note}</p>}
      {definition && <p className="mt-2 text-[10.5px] text-text-light/90 leading-5 border-t border-primary/10 pt-2">{definition}</p>}
    </>
  )
  const className = 'block rounded-2xl border border-primary/10 bg-white p-4 shadow-sm h-full'
  return to && !unavailable
    ? <Link to={to} className={`${className} hover:border-primary/40 focus:outline-none focus:ring-2 focus:ring-primary/40 transition-colors`} aria-label={`${label}: ${format(value)} — فتح القائمة المطابقة`}>{body}</Link>
    : <div className={className}>{body}</div>
}

export function InfoGrid({ items }) {
  return (
    <dl className="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-x-5 gap-y-3" dir="rtl">
      {items.filter(Boolean).map(([label, value]) => (
        <div key={label} className="min-w-0">
          <dt className="text-[11px] font-bold text-text-light">{label}</dt>
          <dd className="text-[13.5px] font-semibold text-text-dark break-words">{value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  )
}

export function Badge({ tone = 'neutral', children }) {
  const tones = {
    success: 'bg-green-50 text-green-700 border-green-200',
    warning: 'bg-amber-50 text-amber-800 border-amber-200',
    danger: 'bg-red-50 text-red-600 border-red-200',
    neutral: 'bg-gray-50 text-text-gray border-gray-200',
    info: 'bg-primary/8 text-primary-dark border-primary/20',
  }
  return <span className={`inline-flex items-center px-2 py-0.5 rounded-full border text-[11px] font-bold whitespace-nowrap ${tones[tone]}`}>{children}</span>
}

export function Notice({ children, tone = 'info' }) {
  const tones = { info: 'bg-primary/5 border-primary/15 text-text-gray', warning: 'bg-amber-50 border-amber-200 text-amber-900' }
  return (
    <div className={`flex items-start gap-2 border rounded-[12px] px-4 py-3 text-[12.5px] leading-6 ${tones[tone]}`} dir="rtl">
      <FaInfoCircle className="mt-1 flex-shrink-0" />
      <div>{children}</div>
    </div>
  )
}

/** Compact table for detail pages, same look as DataTable (header on dark, horizontal scroll on phones). */
export function MiniTable({ columns, rows, rowKey, empty = 'لا توجد سجلات.' }) {
  if (!rows?.length) return <p className="text-[12.5px] text-text-light py-3">{empty}</p>
  return (
    <div className="overflow-x-auto rounded-[12px] border border-primary/12">
      <table className="w-full border-collapse text-[12.5px]">
        <thead>
          <tr>{columns.map(col => <th key={col.key} className="px-3 py-2.5 text-right text-[11.5px] font-bold text-white/90 bg-text-dark whitespace-nowrap">{col.header}</th>)}</tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={rowKey(row, index)} className="border-b border-primary/7 last:border-b-0">
              {columns.map(col => <td key={col.key} className={`px-3 py-2.5 align-middle min-w-[92px] ${col.className ?? ''}`}>{col.render(row)}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function FilterSelect({ label, value, onChange, options, placeholder = 'الكل', minWidth = 170, disabled = false }) {
  return (
    <label className={`flex flex-col gap-1 text-[11.5px] font-bold text-text-light ${disabled ? 'opacity-70' : ''}`} dir="rtl">
      {label}
      <select
        disabled={disabled}
        className="py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none focus:border-primary disabled:cursor-not-allowed disabled:bg-black/[0.03]"
        style={{ minWidth }} value={value} onChange={event => onChange(event.target.value)}
      >
        <option value="">{placeholder}</option>
        {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
      </select>
    </label>
  )
}

export function AppliedFilters({ labels, generatedAt }) {
  return (
    <p className="text-[11.5px] text-text-light leading-6" dir="rtl">
      <span className="font-bold">المرشحات المطبقة: </span>{labels.length ? labels.join(' · ') : 'لا شيء (الجامعة كاملة)'}
      {generatedAt && <><span className="mx-2">|</span><span className="font-bold">آخر تحديث: </span>{generatedAt}</>}
    </p>
  )
}
