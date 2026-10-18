// Presentation-only classification. Codes are NOT persisted units, counts or authority.
export const ADMINISTRATIVE_PORTAL_TITLE = 'الشؤون الإدارية'
export const ADMINISTRATIVE_HEAD = Object.freeze({ code: '7', title: 'نائب رئيس الجامعة للشؤون الإدارية' })

const office = (code, title, servicePaths = []) => Object.freeze({ code, title, servicePaths: Object.freeze(servicePaths) })
const directorate = (code, title, offices) => Object.freeze({ code, title, offices: Object.freeze(offices) })

export const ADMINISTRATIVE_DIRECTORATES = Object.freeze([
  directorate('71', 'مديرية الشؤون الإدارية', [
    office('711', 'مكتب الموارد البشرية', [
      '/vp/administrative/hr', '/vp/administrative/faculty', '/vp/administrative/deans',
      '/vp/administrative/teaching-assignments', '/vp/administrative/exceptional-openings', '/vp/administrative/personnel/employees', '/vp/administrative/personnel/positions', '/vp/administrative/personnel/reports',
    ]),
    office('712', 'مكتب الديوان والأرشيف'),
    office('713', 'مكتب الرعاية الصحية'),
    office('714', 'مكتب الخدمات الإدارية'),
    office('715', 'المكتب التقني', ['/vp/administrative/technical/accounts', '/vp/administrative/technical/activity', '/vp/administrative/technical/university-email', '/vp/administrative/technical/reports']),
  ]),
  directorate('72', 'مديرية الشؤون المالية', [
    office('721', 'مكتب المحاسبة', ['/vp/administrative/payroll']), office('722', 'أمين الصندوق'), office('723', 'أمين المستودع'),
  ]),
  directorate('73', 'مديرية شؤون الطلاب', [
    office('731', 'مكتب الإرشاد والتوجيه', ['/vp/administrative/calendar']),
    office('732', 'مكتب القبول والتسجيل', ['/vp/administrative/student-affairs/students/add', '/vp/administrative/student-affairs/ministry-placements', '/vp/administrative/student-affairs/supplementary-exams', '/vp/administrative/exams/course-registration', '/vp/administrative/exams/approved-registration-requests', '/vp/administrative/exams/registration-reports']),
    office('733', 'مكتب الخدمات الطلابية', ['/vp/administrative/student-affairs/students', '/vp/administrative/student-affairs/students/archived', '/vp/administrative/student-affairs/graduates', '/vp/administrative/student-affairs/reports']),
    office('734', 'مكتب المنح والإيفاد والتبادل الطلابي'),
    office('735', 'إدارة الامتحانات', ['/vp/administrative/exams/incomplete-results', '/vp/administrative/exams/manual-grade-entry', '/vp/administrative/exams/grade-sheet', '/vp/administrative/exams/approvals', '/vp/administrative/exams/deprivation', '/vp/administrative/exams/supplementary', '/vp/administrative/exams/supplementary-grades', '/vp/administrative/exams/courses', '/vp/administrative/exams/course-offerings', '/vp/administrative/exams/course-table', '/vp/administrative/exams/results']),
    office('736', 'مكتب التوثيق والتدقيق', ['/vp/administrative/student-affairs/approved-registration-requests']),
  ]),
])

// Input is the existing permission-filtered navigation, not a new access decision.
export function administrativeDirectorates(items) {
  const byPath = new Map(items.map(item => [item.to, item]))
  return ADMINISTRATIVE_DIRECTORATES.map(group => ({ ...group,
    offices: group.offices.map(unit => ({ ...unit, services: unit.servicePaths.map(path => byPath.get(path)).filter(Boolean) })),
  }))
}

export function administrativeActiveDirectorate(pathname, groups) {
  return groups.find(group => group.offices.some(unit => unit.services.some(item =>
    pathname === item.to || pathname.startsWith(`${item.to}/`),
  )))?.code ?? null
}

export function isAdministrativePortalPath(path) {
  return path === '/vp/administrative' || path.startsWith('/vp/administrative/')
}

export function partitionAdministrativeSections(sections) {
  const isPortalSection = section => section.items.every(item => isAdministrativePortalPath(item.to))
  return {
    items: sections.filter(isPortalSection).flatMap(section => section.items),
    otherSections: sections.filter(section => !isPortalSection(section)),
  }
}
