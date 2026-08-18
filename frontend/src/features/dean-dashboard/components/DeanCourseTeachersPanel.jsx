import {
  academicRankLabel,
  displayValue,
  reviewStatusLabel,
  workflowStatusLabel,
} from '../utils/teacherDisplay'
import { teacherSlotLabel } from '../utils/courseOfferingDisplay'
import CourseRequirementBadges, { pickRequirementClassification } from '../../../components/academic/CourseRequirementBadges'

function SlotCard({ title, slot }) {
  const available = Boolean(slot?.available)
  const assigned = Boolean(slot?.faculty_member_id || slot?.full_name)
  const name = teacherSlotLabel(slot)
  const rank = available && assigned ? academicRankLabel(slot.academic_rank) : ''
  const workflow = slot?.workflow
  const proposed = workflow?.proposed_faculty_member

  return (
    <div className="min-w-0 bg-primary/[0.03] border border-primary/10 rounded-[14px] px-4 py-3.5">
      <p className="text-[11.5px] text-text-light font-semibold mb-1.5">{title}</p>
      {!available ? (
        <p className="text-[14px] font-bold text-text-light">غير موجود</p>
      ) : (
        <div className="space-y-2">
          <div>
            <p className="text-[11px] text-text-light font-semibold">المدرس المعتمد</p>
            {assigned ? (
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
          {workflow && (
            <>
              <div>
                <p className="text-[11px] text-text-light font-semibold">المدرس المقترح</p>
                <p className="text-[13.5px] font-bold text-text-dark">
                  {proposed?.full_name || '—'}
                </p>
              </div>
              <p className="text-[12px] text-text-dark">
                موافقة النائب العلمي: <span className="font-bold">{reviewStatusLabel(workflow.scientific_review?.status)}</span>
              </p>
              <p className="text-[12px] text-text-dark">
                موافقة النائب الإداري: <span className="font-bold">{reviewStatusLabel(workflow.administrative_review?.status)}</span>
              </p>
              <p className="text-[12px] text-text-dark">
                الحالة: <span className="font-bold">{workflowStatusLabel(workflow.status)}</span>
              </p>
              {workflow.scientific_review?.status === 'returned' && workflow.scientific_review?.reason && (
                <p className="text-[12px] text-amber-800">علمي: {workflow.scientific_review.reason}</p>
              )}
              {workflow.administrative_review?.status === 'returned' && workflow.administrative_review?.reason && (
                <p className="text-[12px] text-amber-800">إداري: {workflow.administrative_review.reason}</p>
              )}
            </>
          )}
        </div>
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
            التكليف يصبح نافذًا بعد موافقة النائب العلمي والنائب الإداري معًا
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
      <div className="mt-2">
        <CourseRequirementBadges classification={pickRequirementClassification(offering)} compact />
      </div>
    </section>
  )
}
