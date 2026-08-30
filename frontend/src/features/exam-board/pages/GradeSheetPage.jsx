import { useNavigate } from 'react-router-dom'
import { FaClipboardList } from 'react-icons/fa'
import StudentPicker from '../components/StudentPicker'

export default function GradeSheetPage() {
  const navigate = useNavigate()

  return (
    <div dir="rtl">
      <div className="mb-5">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">كشوف الدرجات</h2>
        <p className="text-[12.5px] text-text-light">Grade Sheets</p>
      </div>

      <section className="mb-5 rounded-[18px] border border-primary/12 bg-primary/[0.045] px-5 py-4">
        <div className="flex items-start gap-3">
          <FaClipboardList className="mt-1 shrink-0 text-primary" aria-hidden="true" />
          <div>
            <h3 className="text-[14.5px] font-black text-text-dark">البحث في السجلات الأكاديمية</h3>
            <p className="mt-1 text-[12.5px] leading-7 text-text-light">
              اختر طالباً لفتح سجله الأكاديمي الكامل ومراجعة الدرجات المعتمدة وتقدم الخطة قبل استخراج الكشف الإلكتروني.
            </p>
          </div>
        </div>
      </section>

      <StudentPicker
        onSelect={student => navigate(`/exam-board/grade-sheet/${student.student_id}`)}
        selected={null}
      />
    </div>
  )
}
