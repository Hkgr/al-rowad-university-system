import { FaHome, FaUserTie, FaUserGraduate, FaUniversity, FaBook, FaChalkboardTeacher, FaSitemap, FaQuestionCircle } from 'react-icons/fa'
import { ACCESS } from '../auth/auth'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

// بوابة وزارة التربية والتعليم: اطلاع للقراءة فقط، كل عنصر خلف صلاحيته الخاصة.
const ministryNav = [
  {
    label: 'متابعة الجامعة',
    items: [
      { to: '/ministry', Icon: FaHome, ar: 'الرئيسية', en: 'Dashboard', end: true, ...ACCESS.ministryDashboard },
      { to: '/ministry/deans', Icon: FaUserTie, ar: 'العمداء', en: 'Deans', ...ACCESS.ministryDeans },
      { to: '/ministry/students', Icon: FaUserGraduate, ar: 'الطلاب', en: 'Students', ...ACCESS.ministryStudents },
      { to: '/ministry/colleges', Icon: FaUniversity, ar: 'الكليات', en: 'Colleges', ...ACCESS.ministryColleges },
      { to: '/ministry/courses', Icon: FaBook, ar: 'المواد', en: 'Courses', ...ACCESS.ministryCourses },
      { to: '/ministry/faculty', Icon: FaChalkboardTeacher, ar: 'المدرسون', en: 'Teaching staff', ...ACCESS.ministryFaculty },
      { to: '/ministry/leadership', Icon: FaSitemap, ar: 'النواب', en: 'Vice presidents', ...ACCESS.ministryLeadership },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.ministry, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.ministry }] },
]

export default ministryNav
