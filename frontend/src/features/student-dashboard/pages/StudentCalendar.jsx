import { FaCalendarAlt } from 'react-icons/fa'

export default function StudentCalendar() {
  return (
    <section
      className="min-h-[420px] flex items-center justify-center px-4 py-12"
      dir="rtl"
      aria-labelledby="student-calendar-title"
    >
      <div className="w-full max-w-[720px] bg-white border border-primary/15 rounded-[18px] px-7 py-12 text-center shadow-[0_4px_24px_rgba(86,153,51,0.08)] relative overflow-hidden">
        <div
          className="absolute top-0 left-0 right-0 h-1"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327)' }}
        />
        <div className="w-16 h-16 mx-auto mb-5 rounded-[16px] bg-primary/10 text-primary flex items-center justify-center text-[28px]" aria-hidden="true">
          <FaCalendarAlt />
        </div>
        <h1 id="student-calendar-title" className="text-[22px] font-black text-text-dark mb-2">
          التقويم الدراسي
        </h1>
        <p className="text-[13.5px] leading-7 text-text-light max-w-[520px] mx-auto">
          المواعيد والمحطات الأكاديمية خلال العام الجامعي
        </p>
        <p className="mt-6 text-[28px] font-black text-primary">قريباً</p>
        <p className="mt-4 text-[13.5px] leading-8 text-text-dark max-w-[520px] mx-auto">
          سيتم عرض المواعيد الأكاديمية ومواعيد التسجيل والامتحانات والعطل الجامعية هنا لاحقاً.
        </p>
      </div>
    </section>
  )
}
