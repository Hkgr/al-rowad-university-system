import {
  FaHome, FaClipboardList, FaCheckDouble, FaExclamationTriangle,
  FaCalendarAlt, FaUsers, FaCog, FaBook,
  FaLockOpen, FaBookOpen, FaTable, FaQuestionCircle,
} from 'react-icons/fa'
import { ACCESS, PERMISSIONS } from '../auth/auth'
import { GUIDE_ACCESS, GUIDE_PATHS } from '../user-guide/guideAccess'

import { reportNav } from '../portal-reports/reportNav'

const examBoardNav = [
  {
    label: 'الرئيسية',
    items: [
      { to: '/exam-board',                  Icon: FaHome,                ar: 'الرئيسية',             en: 'Home',          end: true, permissions: ['exams.view', 'grades.view'] },
      { to: '/exam-board/calendar', Icon: FaCalendarAlt, ar: 'التقويم الأكاديمي', en: 'Calendar', permissions: ['exams.view'] },
    ],
  },
  {
    label: 'المواد والتسجيل',
    items: [
      { to: '/exam-board/courses',             Icon: FaBook,     ar: 'المواد الدراسية', en: 'Courses',             end: true, ...ACCESS.courseManagement },
      { to: '/exam-board/course-offerings',     Icon: FaLockOpen, ar: 'الطروحات الأكاديمية', en: 'Course Offerings', end: true, ...ACCESS.courseManagement },
      { to: '/exam-board/course-table',        Icon: FaTable,    ar: 'جدول المواد',     en: 'Course Table',        end: true, ...ACCESS.courseManagement },
      { to: '/exam-board/course-registration', Icon: FaBookOpen, ar: 'تسجيل المواد',    en: 'Course Registration', end: true, ...ACCESS.courseRegistration },
      { to: '/exam-board/approved-registration-requests', Icon: FaCheckDouble, ar: 'طلبات التسجيل المعتمدة', en: 'Approved requests', end: true, ...ACCESS.courseRegistration },
    ],
  },
  {
    label: 'العلامات والنتائج',
    items: [
      { to: '/exam-board/deprivation',      Icon: FaExclamationTriangle, ar: 'الحضور والحرمان',      en: 'Deprivation', permissions: ['exams.manage'] },
      { to: '/exam-board/manual-grade-entry', Icon: FaClipboardList, ar: 'إدخال العلامات اليدوي', en: 'Manual grade entry', ...ACCESS.manualGradeEntry },
      { to: '/exam-board/grade-sheet',      Icon: FaClipboardList,       ar: 'كشوف الدرجات',         en: 'Grade Sheets', permissions: ['grades.view'] },
      { to: '/exam-board/approvals',        Icon: FaCheckDouble,         ar: 'اعتماد الدرجات',       en: 'Approvals', permissions: ['exams.manage'] },
      { to: '/exam-board/incomplete-results', Icon: FaClipboardList, ar: 'النتائج غير المكتملة I', en: 'Incomplete results', ...ACCESS.manualGradeEntry },
    ],
  },
  {
    label: 'الامتحانات التكميلية',
    items: [
      { to: '/exam-board/supplementary',    Icon: FaCalendarAlt,         ar: 'الامتحانات التكميلية', en: 'Supplementary', permissions: [PERMISSIONS.supplementaryExamsRegistrationsView] },
      { to: '/exam-board/supplementary-grades', Icon: FaCheckDouble, ar: 'علامات الامتحانات التكميلية', en: 'Supplementary Grades', allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review'] },
    ],
  },
  {
    label: 'المراجعات',
    items: [
      { to: '/exam-board/appeals',             Icon: FaUsers,              ar: 'التظلمات',             en: 'Appeals', permissions: ['exams.view'] },
    ],
  },
  {
    label: 'التقارير',
    items: [
      ...reportNav('exam-board').items,
      ...reportNav('admissions').items,
    ],
  },
  {
    label: 'الإعدادات',
    items: [
      { to: '/exam-board/settings',            Icon: FaCog,                ar: 'الإعدادات',            en: 'Settings', permissions: ['exams.view'] },
    ],
  },
  { label: 'المساعدة', items: [{ to: GUIDE_PATHS.examBoard, Icon: FaQuestionCircle, ar: 'دليل الاستخدام', en: 'User guide', ...GUIDE_ACCESS.examBoard }] },
]

export default examBoardNav
