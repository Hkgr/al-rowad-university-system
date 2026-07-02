import { Link } from 'react-router-dom'
import { FaClipboardList, FaCheckDouble, FaExclamationTriangle, FaCalendarAlt } from 'react-icons/fa'

const API = 'http://127.0.0.1:8000/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

export default function ExamBoardHome() {
  const user    = JSON.parse(localStorage.getItem('user') || '{}')
  const dateStr = new Date().toLocaleDateString('ar-SY', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })

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
              هيئة الامتحانات
            </span>
          </div>
          <div className="flex flex-col items-center px-5 py-3.5 rounded-[14px] bg-primary/[0.05] border border-primary/15 flex-shrink-0">
            <FaClipboardList className="text-[22px] text-primary mb-1" />
            <span className="text-[11.5px] font-bold text-primary-dark">جامعة الرواد</span>
            <span className="text-[10px] text-text-light">Al-Rowad University</span>
          </div>
        </div>
      </div>

      {/* Quick access cards */}
      <div className="grid grid-cols-2 max-[600px]:grid-cols-1 gap-4 mb-6">
        {[
          { Icon: FaClipboardList,       color: '#569933', ar: 'كشوف الدرجات',         en: 'Grade Sheets',       to: '/exam-board/grade-sheet'   },
          { Icon: FaCheckDouble,         color: '#3b82f6', ar: 'اعتماد الدرجات',       en: 'Grade Approvals',    to: '/exam-board/approvals'      },
          { Icon: FaExclamationTriangle, color: '#f59e0b', ar: 'الحضور والحرمان',       en: 'Deprivation',        to: '/exam-board/deprivation'    },
          { Icon: FaCalendarAlt,         color: '#8b5cf6', ar: 'الامتحانات التكميلية', en: 'Supplementary Exams', to: '/exam-board/supplementary'  },
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
  )
}
