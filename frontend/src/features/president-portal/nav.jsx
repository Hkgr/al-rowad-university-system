import { FaHome, FaUniversity, FaUserGraduate, FaClipboardCheck, FaChalkboardTeacher, FaSitemap, FaTasks, FaChartBar } from 'react-icons/fa'
import { presidentAccess } from './president'
export default [{ label: 'رئاسة الجامعة', items: [
  ['','الرئيسية','dashboard',FaHome], ['colleges','الكليات والمعاهد','colleges',FaUniversity],
  ['students','الطلاب والشؤون الأكاديمية','students',FaUserGraduate], ['exams','الامتحانات والنتائج','exams',FaClipboardCheck],
  ['faculty','المدرسون والكوادر','staff',FaChalkboardTeacher], ['leadership','النيابات والإدارات','leadership',FaSitemap],
  ['followup','القرارات والمتابعة','followup',FaTasks], ['reports','التقارير','reports',FaChartBar],
].map(([path, ar, section, Icon]) => ({ to: '/president'+(path ? '/'+path : ''), ar, Icon, end: !path, ...presidentAccess(section) })) }]
