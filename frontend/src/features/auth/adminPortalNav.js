import { FaUniversity, FaBuilding, FaUserTie, FaUsers, FaBook, FaChalkboardTeacher, FaClipboardList } from 'react-icons/fa'

/** Only actual administrators see these existing portals; no roles or scopes are assigned. */
export const adminPortalNav = {
  label: 'بوابات إدارة النظام',
  items: [
    ['/technical', 'المكتب التقني', FaBuilding], ['/president', 'رئاسة الجامعة', FaUniversity],
    ['/ministry', 'بوابة الوزارة', FaUniversity], ['/owner', 'بوابة مالك الجامعة', FaUniversity], ['/vp/scientific', 'النيابة العلمية', FaUserTie],
    ['/vp/administrative', 'النيابة الإدارية', FaUserTie], ['/dean', 'بوابة العميد', FaBuilding],
    ['/student-affairs', 'شؤون الطلاب والقبول', FaUsers], ['/exam-board', 'هيئة الامتحانات', FaClipboardList],
    ['/hr', 'الموارد البشرية', FaUsers], ['/academic-structure', 'الهيكل الأكاديمي', FaBook],
    ['/professor/reports', 'تقارير التدريس', FaChalkboardTeacher],
  ].map(([to, ar, Icon]) => ({ to, ar, Icon, end: true, allRoles: ['super_admin'] })),
}
