import { academicRankLabel, displayValue } from '../utils/teacherDisplay'
import { teacherSlotLabel } from '../utils/courseOfferingDisplay'

function SlotCard({ title, slot }) {
  const available = Boolean(slot?.available)
  const assigned = Boolean(slot?.faculty_member_id || slot?.full_name)
  const name = teacherSlotLabel(slot)
  const rank = available && assigned ? academicRankLabel(slot.academic_rank) : ''

  return (
    <div className="min-w-0 bg-primary/[0.03] border border-primary/10 rounded-[14px] px-4 py-3.5">
      <p className="text-[11.5px] text-text-light font-semibold mb-1.5">{title}</p>
      {!available ? (
        <p className="text-[14px] font-bold text-text-light">غير موجود</p>
      ) : assigned ? (
        <>
          <p className="text-[15px] font-extrabold text-text-dark break-words">{name}</p>
          {rank && rank !== '—' ? (
            <p className="text-[12px] text-text-gray mt-0.5">{rank}</p>
          ) : null}
        </>
      ) : (
        <p className="text-[14px] font-bold text-amber-700">بدون مدرس</p>
      )}
    </div>
  )
}

export default function DeanCourseTeachersPanel({ offering, canManage, onManage }) {
  return (
    <section>
      <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
        <div>
          <h3 className="text-[15px] font-extrabold text-text-dark">الكادر التدريسي</h3>
          <p className="text-[12px] text-text-light mt-0.5">
            التكليف النظري والعملي لهذه المادة المطروحة
          </p>
        </div>
        {canManage && (
          <button
            type="button"
            className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
            onClick={onManage}
            aria-label="إدارة التكليف"
            title="إدارة التكليف"
          >
            إدارة التكليف
          </button>
        )}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <SlotCard title="النظري" slot={offering?.teachers?.theoretical} />
        <SlotCard title="العملي" slot={offering?.teachers?.practical} />
      </div>

      <p className="text-[11.5px] text-text-light mt-3">
        {displayValue(offering?.course?.course_code)} — الساعات النظرية: {displayValue(offering?.course?.theoretical_hours)}، الساعات العملية: {displayValue(offering?.course?.practical_hours)}
      </p>
    </section>
  )
}
