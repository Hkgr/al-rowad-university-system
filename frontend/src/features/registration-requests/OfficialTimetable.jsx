import { TIMETABLE_COMPONENT_LABELS, timetableConflictLabel, timetableSlotLabel, timetableStatusLabel } from './courseOfferingTimetable'

export default function OfficialTimetable({ schedule, conflicts = [], incompleteSources = [], compact = false }) {
  return (
    <div className={`rounded-[10px] border px-3 py-2 ${schedule?.complete ? 'border-green-200 bg-green-50/60' : 'border-amber-200 bg-amber-50/60'}`} dir="rtl">
      <p className="text-[11.5px] font-bold text-text-dark">الجدول الأسبوعي: {timetableStatusLabel(schedule)}</p>
      {(schedule?.missing_components ?? []).length > 0 ? (
        <p className="mt-1 text-[11px] text-amber-900">
          المكونات الناقصة: {(schedule.missing_components ?? []).map(component => TIMETABLE_COMPONENT_LABELS[component] || component).join('، ')}
        </p>
      ) : null}
      {(schedule?.slots ?? []).length > 0 ? (
        <ul className={`mt-1 ${compact ? 'space-y-0' : 'space-y-1'}`}>
          {(schedule.slots ?? []).map((slot, index) => (
            <li key={slot.course_offering_schedule_slot_id ?? `${slot.component_type}-${slot.day_of_week}-${slot.start_time}-${index}`} className="text-[11.5px] text-text-light">
              {timetableSlotLabel(slot)}
            </li>
          ))}
        </ul>
      ) : null}
      {(conflicts ?? []).map((conflict, index) => (
        <p key={`${conflict.course_offering_id}-${index}`} className="mt-1 text-[11.5px] font-bold text-red-700">
          {timetableConflictLabel(conflict)}
        </p>
      ))}
      {(incompleteSources ?? []).length > 0 ? (
        <p className="mt-1 text-[11.5px] font-bold text-amber-900">
          تعذر التحقق من التعارض لأن جدول أحد المقررات المسجلة أو المطلوبة غير مكتمل.
        </p>
      ) : null}
    </div>
  )
}
