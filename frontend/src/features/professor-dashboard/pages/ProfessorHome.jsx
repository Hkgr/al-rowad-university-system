import { Link } from 'react-router-dom'
import { FaSpinner, FaChalkboardTeacher, FaCalendarCheck, FaExclamationCircle, FaBook } from 'react-icons/fa'
import useAuth from '../../auth/useAuth'
import useMyOfferings from '../hooks/useMyOfferings'

const RANK_AR = {
  'Professor': 'أستاذ', 'Associate Professor': 'أستاذ مشارك',
  'Assistant Professor': 'أستاذ مساعد', 'Lecturer': 'محاضر', 'Instructor': 'مدرس',
}

export default function ProfessorHome() {
  const { user = {} } = useAuth()
  const dateStr = new Date().toLocaleDateString('ar-SY', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
  const { facultyMember, offerings, loading, error } = useMyOfferings()

  return (
    <>
      {/* Welcome banner */}
      <div className="relative bg-white border border-primary/15 rounded-[18px] px-7 py-6 mb-6 overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)]">
        <div
          className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327,#569933)', backgroundSize: '250% 100%' }}
        />
        <div className="flex items-center justify-between gap-4 flex-wrap" dir="rtl">
          <div>
            <h2 className="text-[22px] font-black text-text-dark mb-1">
              مرحباً، {user.username} 👋
            </h2>
            <p className="text-[13px] text-text-gray">{dateStr}</p>
            <span className="inline-block mt-2 text-[12px] text-primary-dark bg-primary/6 border border-primary/15 px-3 py-1 rounded-full font-bold">
              {facultyMember ? (RANK_AR[facultyMember.academic_rank] ?? facultyMember.academic_rank ?? 'عضو هيئة تدريس') : 'بوابة الأستاذ'}
            </span>
          </div>
          <div className="flex flex-col items-center px-5 py-3.5 rounded-[14px] bg-primary/[0.05] border border-primary/15 flex-shrink-0">
            <FaChalkboardTeacher className="text-[22px] text-primary mb-1" />
            <span className="text-[11.5px] font-bold text-primary-dark">جامعة الرواد</span>
            <span className="text-[10px] text-text-light">Al-Rowad University</span>
          </div>
        </div>
      </div>

      {loading && (
        <div className="flex justify-center py-12 text-primary"><FaSpinner className="animate-spin text-[24px]" /></div>
      )}

      {!loading && error && (
        <div className="bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">⚠ {error}</div>
      )}

      {!loading && !error && !facultyMember && (
        <div className="flex flex-col items-center justify-center gap-2 py-16">
          <FaExclamationCircle className="text-[40px] text-amber-400 mb-1" />
          <p className="text-[14px] font-bold text-text-gray" dir="rtl">لم يتم العثور على سجل عضو هيئة تدريس مرتبط بحسابك</p>
          <p className="text-[12px] text-text-light" dir="rtl">تواصل مع الموارد البشرية للتحقق من ربط حسابك بسجل التدريس</p>
        </div>
      )}

      {!loading && facultyMember && (
        <>
          <div className="bg-white border border-primary/12 rounded-[16px] px-5 py-4 mb-6 flex items-center gap-3" dir="rtl">
            <span className="text-[13px] text-text-light">أنت أستاذ</span>
            <span className="text-[18px] font-black text-primary">{offerings.length}</span>
            <span className="text-[13px] text-text-light">مادة مفتوحة حالياً</span>
          </div>

          <div className="flex items-center gap-2 mb-3 text-[13px] font-bold text-text-dark" dir="rtl">
            <FaBook className="text-primary text-[13px]" /> موادي
          </div>

          {offerings.length === 0 && (
            <div className="bg-white border border-primary/12 rounded-[16px] px-5 py-10 mb-6 text-center" dir="rtl">
              <p className="text-[13px] text-text-light">لا توجد مواد مسندة إليك حالياً</p>
            </div>
          )}

          {offerings.length > 0 && (
            <div className="grid grid-cols-3 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
              {offerings.map(o => (
                <Link
                  key={o.course_offering_id}
                  to="/professor/attendance"
                  state={{ offeringId: o.course_offering_id }}
                  className="text-right bg-white border border-primary/12 rounded-[16px] px-5 py-4 flex items-center gap-3 shadow-[0_2px_10px_rgba(26,46,16,0.05)] no-underline transition-all duration-200 hover:-translate-y-[2px] hover:shadow-[0_6px_20px_rgba(26,46,16,0.1)]"
                  dir="rtl"
                >
                  <div className="w-11 h-11 rounded-[11px] bg-primary/10 flex items-center justify-center text-[18px] text-primary flex-shrink-0">
                    <FaBook />
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="font-bold text-[13.5px] text-text-dark truncate">{o.course?.course_name || `مادة #${o.course_offering_id}`}</div>
                    <div className="text-[11px] text-text-light font-mono mt-0.5">{o.course?.course_code}</div>
                    <div className="text-[10.5px] text-text-light mt-0.5">
                      {o.academic_year?.year_name} {o.semester?.semester_name ? `— ${o.semester.semester_name}` : ''}
                    </div>
                  </div>
                </Link>
              ))}
            </div>
          )}

          <div className="grid grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
            {[
              { Icon: FaCalendarCheck, color: '#f59e0b', ar: 'الحضور ومتابعة الحرمان', en: 'Attendance', to: '/professor/attendance' },
            ].map(({ Icon, color, ar, en, to }) => (
              <Link
                key={ar}
                to={to}
                className="bg-white border border-primary/12 rounded-[16px] px-5 py-5 flex items-center gap-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)] no-underline transition-all duration-200 hover:-translate-y-[3px] hover:shadow-[0_6px_20px_rgba(26,46,16,0.1)] group"
                dir="rtl"
              >
                <div
                  className="w-[52px] h-[52px] rounded-[13px] flex items-center justify-center text-[22px] flex-shrink-0"
                  style={{ background: `${color}18`, color }}
                >
                  <Icon />
                </div>
                <div className="flex-1">
                  <div className="text-[15px] font-bold text-text-dark">{ar}</div>
                  <div className="text-[11px] text-text-light">{en}</div>
                </div>
                <span className="text-[14px] text-gray-300 transition-all duration-200 group-hover:-translate-x-1" style={{ color }}>←</span>
              </Link>
            ))}
          </div>
        </>
      )}
    </>
  )
}
