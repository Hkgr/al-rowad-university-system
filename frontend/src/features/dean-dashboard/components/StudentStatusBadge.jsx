import { resolveStudentStatus } from '../utils/studentDisplay'

export default function StudentStatusBadge({ statusId, status, className = '' }) {
  const presentation = resolveStudentStatus(status ?? { student_status_id: statusId })
  if (!presentation) {
    return <span className={`text-[11px] text-text-light ${className}`.trim()}>—</span>
  }

  return (
    <span
      className={`inline-block px-2 py-[3px] rounded-full text-[11px] font-bold whitespace-nowrap ${className}`.trim()}
      style={{ color: presentation.color, background: presentation.bg }}
    >
      {presentation.ar}
    </span>
  )
}
