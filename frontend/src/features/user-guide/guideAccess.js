// Access rules for the per-portal user guides. Pure module (importable in node tests).
// Every guide route mirrors the guard of the portal route group(s) that render the
// same sidebar; ROUTE_ACCESS mirrors App.jsx so guide links never point to a page
// the viewer cannot open.
import { ACCESS, PERMISSIONS, ROLES } from '../auth/auth.js'
import { reportAccess } from '../portal-reports/reports.js'
import { CATALOG_ACCESS } from '../scientific-courses/catalog.js'
import { PROGRAM_ACCESS } from '../scientific-programs/programs.js'
import { reportAccessForOffice } from '../executive-reports/access.js'
import { ADMINISTRATIVE_ACCESS } from '../vice-presidency/utils/administrativeAccess.js'

// Route-group guards exactly as written in App.jsx.
export const GROUP_GUARDS = Object.freeze({
  student: { studentIdentity: true, permissions: ['registration.view', 'grades.view', 'attendance.view'] },
  professor: { employeeIdentity: true, permissions: ['grades.manage', 'attendance.manage', 'supplementary_exams.grades.view'] },
  studentAffairs: { permissions: ['students.view'] },
  studentAffairsAdd: ACCESS.studentAffairsAddStudent,
  ministryPlacements: { assignedPermissions: [PERMISSIONS.admissionsView], actualUniversityScope: true },
  examBoard: { allPermissions: ['exams.view', 'exams.manage'] },
  courseRegistration: ACCESS.courseRegistration,
  manualGradeEntry: ACCESS.manualGradeEntry,
  dean: { roles: [ROLES.dean] },
  hr: { permissions: ['hr.view'] },
  academicStructure: { permissions: ['academic_structure.view'] },
  vpScientific: ACCESS.scientificVicePresident,
  vpAdministrative: ACCESS.administrativeVicePresident,
  technical: ACCESS.technicalPortal,
  ministry: ACCESS.ministryPortal,
})

const G = GROUP_GUARDS

// One guide per sidebar. Shared sidebars use the union of the groups rendering them.
export const GUIDE_ACCESS = Object.freeze({
  student: G.student,
  professor: G.professor,
  studentAffairs: { anyAccess: [G.studentAffairs, G.studentAffairsAdd, G.ministryPlacements] },
  examBoard: { anyAccess: [G.examBoard, G.courseRegistration, G.manualGradeEntry] },
  dean: G.dean,
  hr: G.hr,
  academicStructure: G.academicStructure,
  vpScientific: G.vpScientific,
  vpAdministrative: G.vpAdministrative,
  technical: G.technical,
  ministry: G.ministry,
})

export const GUIDE_PATHS = Object.freeze({
  student: '/student/guide',
  professor: '/professor/guide',
  studentAffairs: '/student-affairs/guide',
  examBoard: '/exam-board/guide',
  dean: '/dean/guide',
  hr: '/hr/guide',
  academicStructure: '/academic-structure/guide',
  vpScientific: '/vp/scientific/guide',
  vpAdministrative: '/vp/administrative/guide',
  technical: '/technical/guide',
  ministry: '/ministry/guide',
})

const semesterGovernanceVp = { allRoles: [ROLES.vicePresidentScientific], assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView], actualUniversityScope: true }

// Each route: every listed access object must pass (group guard, then route-level protect()).
export const ROUTE_ACCESS = Object.freeze({
  '/student/registration': [G.student],
  '/student/requirements': [G.student],
  '/student/transcript': [G.student],
  '/student/gpa': [G.student],
  '/student/attendance': [G.student],
  '/student/calendar': [G.student],
  '/student/supplementary-exams': [G.student, { studentIdentity: true, allRoles: ['student'], assignedPermissions: ['supplementary_exams.deferrals.self', 'supplementary_exams.registrations.self'] }],

  '/professor/grades': [G.professor, { employeeIdentity: true, permissions: ['grades.manage'] }],
  '/professor/attendance': [G.professor],
  '/professor/supplementary-exams': [G.professor, { employeeIdentity: true, allRoles: ['doctor_instructor'], assignedPermissions: ['supplementary_exams.grades.view'] }],

  '/student-affairs/students': [G.studentAffairs],
  '/student-affairs/students/add': [G.studentAffairsAdd],
  '/student-affairs/students/archived': [G.studentAffairs, { permissions: ['students.manage'] }],
  '/student-affairs/graduates': [G.studentAffairs],
  '/student-affairs/supplementary-exams': [G.studentAffairs, { allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] }],
  '/student-affairs/approved-registration-requests': [G.studentAffairs, { permissions: ['registration.view'] }],
  '/student-affairs/ministry-placements': [G.ministryPlacements],

  '/exam-board/manual-grade-entry': [G.manualGradeEntry],
  '/exam-board/grade-sheet': [G.examBoard, { permissions: ['grades.view'] }],
  '/exam-board/approvals': [G.examBoard, { permissions: ['exams.manage'] }],
  '/exam-board/deprivation': [G.examBoard, { permissions: ['exams.manage'] }],
  '/exam-board/supplementary': [G.examBoard, { permissions: [PERMISSIONS.supplementaryExamsRegistrationsView] }],
  '/exam-board/supplementary-grades': [G.examBoard, { allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review'] }],
  '/exam-board/courses': [G.examBoard, ACCESS.courseManagement],
  '/exam-board/course-offerings': [G.examBoard, ACCESS.courseManagement],
  '/exam-board/course-table': [G.examBoard, ACCESS.courseManagement],
  '/exam-board/approved-registration-requests': [G.courseRegistration],

  '/dean/students': [G.dean],
  '/dean/teachers': [G.dean],
  '/dean/courses': [G.dean],
  '/dean/reports': [G.dean, reportAccess('dean')],
  '/dean/registration-offerings': [G.dean, { allRoles: [ROLES.dean], assignedPermissions: [PERMISSIONS.semesterOfferingGovernanceView] }],
  '/dean/registration-requests': [G.dean],
  '/dean/supplementary-exams': [G.dean, { allRoles: ['dean'], assignedPermissions: ['supplementary_exams.offerings.view'] }],
  '/dean/calendar': [G.dean],

  '/hr/employees': [G.hr],
  '/hr/employees/add': [G.hr, { permissions: ['hr.manage'] }],
  '/hr/faculty': [G.hr],
  '/hr/positions': [G.hr],

  '/academic-structure/colleges': [G.academicStructure],
  '/academic-structure/departments': [G.academicStructure],
  '/academic-structure/programs': [G.academicStructure],

  '/vp/scientific/courses': [G.vpScientific, CATALOG_ACCESS],
  '/vp/scientific/programs': [G.vpScientific, PROGRAM_ACCESS],
  '/vp/scientific/reports': [G.vpScientific, reportAccessForOffice('scientific')],
  '/vp/scientific/teaching-assignments': [G.vpScientific],
  '/vp/scientific/semester-offerings': [G.vpScientific, semesterGovernanceVp],
  '/vp/scientific/semester-offerings/minimum-enrollment': [G.vpScientific, semesterGovernanceVp],
  '/vp/scientific/exceptional-openings': [G.vpScientific],
  '/vp/scientific/supplementary-exams': [G.vpScientific, { allRoles: ['vice_president_scientific'], assignedPermissions: [PERMISSIONS.supplementaryExamsPeriodsView] }],
  '/vp/scientific/calendar': [G.vpScientific],
  '/vp/administrative': [G.vpAdministrative],
  '/vp/administrative/reports': [G.vpAdministrative, reportAccessForOffice('administrative')],
  '/vp/administrative/teaching-assignments': [G.vpAdministrative],
  '/vp/administrative/exceptional-openings': [G.vpAdministrative],
  '/vp/administrative/faculty': [G.vpAdministrative, ADMINISTRATIVE_ACCESS.facultyView],
  '/vp/administrative/deans': [G.vpAdministrative, ADMINISTRATIVE_ACCESS.deansView],
  '/vp/administrative/calendar': [G.vpAdministrative],

  '/technical/accounts': [G.technical, ACCESS.technicalAccounts],
  '/technical/activity': [G.technical, ACCESS.technicalActivity],

  '/ministry': [G.ministry, ACCESS.ministryDashboard],
  '/ministry/deans': [G.ministry, ACCESS.ministryDeans],
  '/ministry/students': [G.ministry, ACCESS.ministryStudents],
  '/ministry/colleges': [G.ministry, ACCESS.ministryColleges],
  '/ministry/courses': [G.ministry, ACCESS.ministryCourses],
  '/ministry/faculty': [G.ministry, ACCESS.ministryFaculty],
  '/ministry/leadership': [G.ministry, ACCESS.ministryLeadership],
})
