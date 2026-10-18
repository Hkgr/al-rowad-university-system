import { FaCheckCircle, FaClock, FaExclamationCircle } from 'react-icons/fa'
import { Link } from 'react-router-dom'
import { SECTIONS, label, payrollFilePath } from '../lib/hrOffice'
import { Notice, secondaryButton } from '../../vice-presidency/components/GovernanceUi'

// Reuse the administrative faculty table's restrained badge/text treatment, locally to this office.
export function HrStatus({ value, children }) {
  const positive = ['approved', 'accepted', 'completed', 'open', 'effective', 'linked'].includes(value)
  const warning = ['submitted', 'returned', 'proposed', 'future', 'unavailable'].includes(value)
  return <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold leading-5 ${positive ? 'bg-green-100 text-green-700' : warning ? 'bg-amber-50 text-amber-800' : ['rejected', 'declined'].includes(value) ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-text-gray'}`}>
    {positive ? <FaCheckCircle aria-hidden="true" /> : warning ? <FaClock aria-hidden="true" /> : <FaExclamationCircle aria-hidden="true" />}{children || label(value)}
  </span>
}

export function HrText({ children, secondary }) {
  return <div className="max-w-[260px] min-w-[120px] text-[12.5px] leading-6"><span className="line-clamp-2 break-words font-bold text-text-dark" title={typeof children === 'string' ? children : undefined}>{children || 'غير محدد'}</span>{secondary && <span className="mt-0.5 block text-[11px] text-text-light break-words">{secondary}</span>}</div>
}

// Manual keyboard activation: moving focus never discards a draft; Enter/Space runs the guarded change.
export function HrTabs({ active, onChange }) {
  function moveFocus(event, index) {
    const target = event.key === 'ArrowLeft' ? (index + 1) % SECTIONS.length : event.key === 'ArrowRight' ? (index - 1 + SECTIONS.length) % SECTIONS.length : event.key === 'Home' ? 0 : event.key === 'End' ? SECTIONS.length - 1 : null
    if (target === null) return
    event.preventDefault()
    event.currentTarget.parentElement.querySelectorAll('[role="tab"]')[target]?.focus()
  }
  return <div role="tablist" aria-label="أقسام الموارد البشرية" dir="rtl" className="flex min-w-0 gap-1 overflow-x-auto overscroll-x-contain rounded-t-[12px] border-b border-primary/15 bg-white px-1">
    {SECTIONS.map(([id, title], index) => <button type="button" key={id} id={`hr-tab-${id}`} role="tab" aria-selected={id === active} aria-controls="hr-office-panel" tabIndex={id === active ? 0 : -1}
      className="hr-tab shrink-0 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
      onKeyDown={event => moveFocus(event, index)} onClick={() => { if (id !== active) onChange(id) }}>{title}</button>)}
  </div>
}

export function PayrollLinkStatus({ detail, canView }) {
  if (!detail.payroll_status) return null
  if (detail.payroll_status === 'unavailable') return <Notice tone="warning">حالة الربط المالي غير متاحة؛ مخطط الربط غير جاهز.</Notice>
  const linked = detail.payroll_status === 'linked'
  return <section className="rounded-[12px] border border-primary/15 bg-primary/[0.035] p-4 space-y-3" aria-label="الربط المالي">
    <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="text-[13px] font-bold">الملف المحاسبي</h3><HrStatus value={detail.payroll_status}>{linked ? 'سجل مالي من هوية العامل' : 'بانتظار مزامنة العاملين'}</HrStatus></div>
    {linked && <div className="text-[12.5px] leading-6"><p className="font-bold break-words">{detail.employee.first_name} {detail.employee.last_name}</p><p className="text-text-light">الرقم الوظيفي: <bdi>{detail.employee.employee_number}</bdi></p></div>}
    <div className="flex flex-wrap gap-2">{linked && detail.can_open_payroll && canView && <Link className={secondaryButton} to={payrollFilePath(detail.employee.employee_id)}>فتح الملف المحاسبي</Link>}</div>
    <p className="text-[11px] text-text-light">الهوية من سجل العامل. إدارة المبالغ والصرف والاستلام في المحاسبة فقط.</p>
  </section>
}
