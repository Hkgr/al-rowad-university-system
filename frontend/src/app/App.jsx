import { useEffect, useState } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'

import DashboardLayout from '../components/layout/DashboardLayout'

// ── Auth ────────────────────────────────────────────────────────────────────
import LoginPage from '../features/auth/pages/LoginPage'
import ForbiddenPage from '../features/auth/pages/ForbiddenPage'
import { ACCESS, PERMISSIONS, canAccess, clearIdentity, getIdentity, landingRoute, storeIdentity } from '../features/auth/auth'

// ── شؤون الطلاب (Student Affairs) ──────────────────────────────────────────
import studentAffairsNav, { ministryPlacementNav } from '../features/student-affairs/nav'
import StudentAffairsHome   from '../features/student-affairs/pages/StudentAffairsHome'
import StudentsPage         from '../features/student-affairs/pages/StudentsPage'
import AddStudentPage       from '../features/student-affairs/pages/AddStudentPage'
import EditStudentPage      from '../features/student-affairs/pages/EditStudentPage'
import StudentProfilePage   from '../features/student-affairs/pages/StudentProfilePage'
import ArchivedStudentsPage from '../features/student-affairs/pages/ArchivedStudentsPage'
import GraduatesPage       from '../features/student-affairs/pages/GraduatesPage'
import SupplementaryExamRegistrations from '../features/student-affairs/pages/SupplementaryExamRegistrations'
import MinistryPlacementsPage from '../features/student-affairs/pages/MinistryPlacementsPage'

// ── بوابة الطالب (Student Dashboard) ────────────────────────────────────────
import studentNav        from '../features/student-dashboard/nav'
import StudentHome         from '../features/student-dashboard/pages/StudentHome'
import StudentTranscript  from '../features/student-dashboard/pages/StudentTranscript'
import StudentGPA         from '../features/student-dashboard/pages/StudentGPA'
import StudentAttendance  from '../features/student-dashboard/pages/StudentAttendance'
import StudentRegistration from '../features/student-dashboard/pages/StudentRegistration'
import StudentCalendar from '../features/student-dashboard/pages/StudentCalendar'
import StudentRequirements from '../features/student-dashboard/pages/StudentRequirements'
import StudentSupplementaryExams from '../features/student-dashboard/pages/StudentSupplementaryExams'

// ── الموارد البشرية (HR) ────────────────────────────────────────────────────
import hrNav                from '../features/hr-dashboard/nav'
import HRHome               from '../features/hr-dashboard/pages/HRHome'
import EmployeesPage        from '../features/hr-dashboard/pages/EmployeesPage'
import AddEmployeePage      from '../features/hr-dashboard/pages/AddEmployeePage'
import EmployeeProfilePage  from '../features/hr-dashboard/pages/EmployeeProfilePage'
import FacultyPage          from '../features/hr-dashboard/pages/FacultyPage'
import PositionsPage        from '../features/hr-dashboard/pages/PositionsPage'

// ── الهيكل الأكاديمي (Academic Structure) ───────────────────────────────────
import academicStructureNav  from '../features/academic-structure/nav'
import AcademicStructureHome from '../features/academic-structure/pages/AcademicStructureHome'
import CollegesPage          from '../features/academic-structure/pages/CollegesPage'
import DepartmentsPage       from '../features/academic-structure/pages/DepartmentsPage'
import ProgramsPage          from '../features/academic-structure/pages/ProgramsPage'

// ── هيئة الامتحانات (Exam Board) ────────────────────────────────────────────
import examBoardNav      from '../features/exam-board/nav'
import ExamBoardHome     from '../features/exam-board/pages/ExamBoardHome'
import GradeSheetPage    from '../features/exam-board/pages/GradeSheetPage'
import ApprovalsPage     from '../features/exam-board/pages/ApprovalsPage'
import DeprivationPage        from '../features/exam-board/pages/DeprivationPage'
import CoursesPage            from '../features/exam-board/pages/CoursesPage'
import CourseRegistrationPage from '../features/exam-board/pages/CourseRegistrationPage'
import CourseOfferingsPage    from '../features/exam-board/pages/CourseOfferingsPage'
import CourseTablePage        from '../features/exam-board/pages/CourseTablePage'
import ExamPlaceholder        from '../features/exam-board/pages/ExamPlaceholder'
import SupplementaryExamsPage from '../features/exam-board/pages/SupplementaryExamsPage'
import ApprovedRegistrationRequestsPage from '../features/registration-requests/pages/ApprovedRegistrationRequestsPage'

// ── بوابة الأستاذ (Professor Dashboard) ─────────────────────────────────────
import professorNav             from '../features/professor-dashboard/nav'
import ProfessorHome            from '../features/professor-dashboard/pages/ProfessorHome'
import AttendanceDeprivationPage from '../features/professor-dashboard/pages/AttendanceDeprivationPage'
import ProfessorGradesPage       from '../features/professor-dashboard/pages/ProfessorGradesPage'
import ProfessorSupplementaryExams from '../features/professor-dashboard/pages/ProfessorSupplementaryExams'
import SupplementaryGradesPage from '../features/exam-board/pages/SupplementaryGradesPage'

// ── بوابة عميد الكلية (Dean Dashboard) ─────────────────────────────────────
import DeanLayout          from '../features/dean-dashboard/DeanLayout'
import DeanHome            from '../features/dean-dashboard/pages/DeanHome'
import DeanStudents        from '../features/dean-dashboard/pages/DeanStudents'
import DeanStudentProfile  from '../features/dean-dashboard/pages/DeanStudentProfile'
import DeanTeachers        from '../features/dean-dashboard/pages/DeanTeachers'
import DeanTeacherProfile  from '../features/dean-dashboard/pages/DeanTeacherProfile'
import DeanCourses         from '../features/dean-dashboard/pages/DeanCourses'
import DeanCourseOfferingProfile from '../features/dean-dashboard/pages/DeanCourseOfferingProfile'
import DeanRegistrationOfferings from '../features/dean-dashboard/pages/DeanRegistrationOfferings'
import DeanSupplementaryExams from '../features/dean-dashboard/pages/DeanSupplementaryExams'
import DeanRegistrationRequests from '../features/dean-dashboard/pages/DeanRegistrationRequests'
import DeanRegistrationRequestDetail from '../features/dean-dashboard/pages/DeanRegistrationRequestDetail'
import DeanReports         from '../features/dean-dashboard/pages/DeanReports'
import DeanCalendar        from '../features/dean-dashboard/pages/DeanCalendar'
import AcademicCalendarPage from '../features/academic-calendar/AcademicCalendarPage'

// ── نيابة رئاسة الجامعة (Vice Presidency shells) ───────────────────────────
import { administrativeVicePresidentNav, scientificVicePresidentNav } from '../features/vice-presidency/nav'
import VicePresidentShell from '../features/vice-presidency/pages/VicePresidentShell'
import TeachingAssignmentQueue from '../features/vice-presidency/pages/TeachingAssignmentQueue'
import TeachingAssignmentDetail from '../features/vice-presidency/pages/TeachingAssignmentDetail'
import ExceptionalOpeningQueue from '../features/vice-presidency/pages/ExceptionalOpeningQueue'
import ExceptionalOpeningDetail from '../features/vice-presidency/pages/ExceptionalOpeningDetail'
import SupplementaryExamPeriodsPage from '../features/vice-presidency/pages/SupplementaryExamPeriods'

function ProtectedRoute({ children, permissions = [], allPermissions = [], roles = [], allRoles = [], assignedPermissions = [], actualUniversityScope = false, studentIdentity = false, employeeIdentity = false }) {
  const token = localStorage.getItem('token')
  const [identity, setIdentity] = useState(getIdentity())
  const [checking, setChecking] = useState(Boolean(token))

  useEffect(() => {
    if (!token) return
    const api = import.meta.env.VITE_API_BASE_URL || 'https://rust.alrowaduni.edu.sy/api'
    fetch(`${api}/user`, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } })
      .then(async response => ({ response, json: await response.json() }))
      .then(({ response, json }) => {
        if (!response.ok || !json.success) {
          clearIdentity(); setIdentity(null); return
        }
        storeIdentity(json.data); setIdentity(json.data)
      })
      .catch(() => {})
      .finally(() => setChecking(false))
  }, [token])

  if (!token) return <Navigate to="/login" replace />
  if (checking) return null
  if (!identity) return <Navigate to="/login" replace />
  return canAccess({ permissions, allPermissions, roles, allRoles, assignedPermissions, actualUniversityScope, studentIdentity, employeeIdentity }, identity) ? children : <Navigate to="/forbidden" replace />
}

const protect = (element, access) => <ProtectedRoute {...access}>{element}</ProtectedRoute>

export default function App() {
  return (
    <BrowserRouter>
      <Routes>

        {/* Public */}
        <Route path="/login" element={<LoginPage />} />
        <Route path="/forbidden" element={<ForbiddenPage />} />

        {/* ── شؤون الطلاب dashboard ── */}
        <Route
          element={
            <ProtectedRoute permissions={['students.view']}>
              <DashboardLayout nav={studentAffairsNav} appTitle="شؤون الطلاب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student-affairs"                   element={<StudentAffairsHome />}   />
          <Route path="/student-affairs/students"          element={<StudentsPage />}          />
          <Route path="/student-affairs/students/add"      element={protect(<AddStudentPage />, { permissions: ['students.manage'] })} />
          <Route path="/student-affairs/students/archived" element={protect(<ArchivedStudentsPage />, { permissions: ['students.manage'] })} />
          <Route path="/student-affairs/graduates"         element={<GraduatesPage />}         />
          <Route path="/student-affairs/students/:id"      element={<StudentProfilePage />}    />
          <Route path="/student-affairs/students/:id/edit" element={protect(<EditStudentPage />, { permissions: ['students.manage'] })} />
          <Route path="/student-affairs/supplementary-exams" element={protect(<SupplementaryExamRegistrations />, { allRoles: ['registration_officer'], assignedPermissions: ['supplementary_exams.registrations.view'] })} />
          <Route path="/student-affairs/approved-registration-requests" element={protect(<ApprovedRegistrationRequestsPage />, { permissions: ['registration.view'] })} />
          <Route path="/student-affairs/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* ── Ministry Placement dashboard: admissions authority, not student-record authority ── */}
        <Route
          element={
            <ProtectedRoute assignedPermissions={[PERMISSIONS.admissionsView]} actualUniversityScope>
              <DashboardLayout nav={ministryPlacementNav} appTitle="شؤون الطلاب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student-affairs/ministry-placements" element={<MinistryPlacementsPage />} />
        </Route>

        {/* ── بوابة الطالب dashboard ── */}
        <Route
          element={
            <ProtectedRoute studentIdentity permissions={['registration.view', 'grades.view', 'attendance.view']}>
              <DashboardLayout nav={studentNav} appTitle="بوابة الطالب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student"            element={<StudentHome />}       />
          <Route path="/student/transcript" element={<StudentTranscript />} />
          <Route path="/student/gpa"        element={<StudentGPA />}        />
          <Route path="/student/attendance"    element={<StudentAttendance />} />
          <Route path="/student/registration" element={<StudentRegistration />} />
          <Route path="/student/requirements" element={<StudentRequirements />} />
          <Route path="/student/calendar" element={<StudentCalendar />} />
          <Route path="/student/supplementary-exams" element={protect(<StudentSupplementaryExams />, { studentIdentity: true, allRoles: ['student'], assignedPermissions: ['supplementary_exams.deferrals.self', 'supplementary_exams.registrations.self'] })} />
        </Route>

        {/* ── هيئة الامتحانات dashboard ── */}
        <Route
          element={
            <ProtectedRoute {...ACCESS.courseRegistration}>
              <DashboardLayout nav={examBoardNav} appTitle="القبول والتسجيل" />
            </ProtectedRoute>
          }
        >
          <Route path="/exam-board/course-registration" element={<CourseRegistrationPage />} />
          <Route path="/exam-board/approved-registration-requests" element={<ApprovedRegistrationRequestsPage />} />
        </Route>

        <Route
          element={
            <ProtectedRoute allPermissions={['exams.view', 'exams.manage']}>
              <DashboardLayout nav={examBoardNav} appTitle="هيئة الامتحانات" />
            </ProtectedRoute>
          }
        >
          <Route path="/exam-board"                element={protect(<ExamBoardHome />, { allPermissions: ['exams.view', 'exams.manage'] })} />
          <Route path="/exam-board/grade-sheet"   element={protect(<GradeSheetPage />, { permissions: ['grades.view'] })} />
          <Route path="/exam-board/approvals"     element={protect(<ApprovalsPage />, { permissions: ['exams.manage'] })} />
          <Route path="/exam-board/deprivation"   element={protect(<DeprivationPage />, { permissions: ['exams.manage'] })} />
          <Route path="/exam-board/supplementary" element={protect(<SupplementaryExamsPage />, { permissions: [PERMISSIONS.supplementaryExamsRegistrationsView] })} />
          <Route path="/exam-board/supplementary-grades" element={protect(<SupplementaryGradesPage />, { allRoles: ['exam_officer'], assignedPermissions: ['supplementary_exams.grades.review'] })} />
          <Route path="/exam-board/results"       element={protect(<ExamPlaceholder title="النتائج والتقارير" en="Results" />, { permissions: ['grades.view'] })} />
          <Route path="/exam-board/courses"             element={protect(<CoursesPage />, ACCESS.courseManagement)} />
          <Route path="/exam-board/course-offerings"    element={protect(<CourseOfferingsPage />, ACCESS.courseManagement)} />
          <Route path="/exam-board/course-table"        element={protect(<CourseTablePage />, ACCESS.courseManagement)} />
          <Route path="/exam-board/calendar" element={<AcademicCalendarPage />} />
          <Route path="/exam-board/appeals"          element={protect(<ExamPlaceholder title="التظلمات" en="Appeals" />, { permissions: ['exams.view'] })} />
          <Route path="/exam-board/settings"         element={protect(<ExamPlaceholder title="الإعدادات" en="Settings" />, { permissions: ['exams.view'] })} />
        </Route>

        {/* ── الهيكل الأكاديمي dashboard ── */}
        <Route
          element={
            <ProtectedRoute permissions={['academic_structure.view']}>
              <DashboardLayout nav={academicStructureNav} appTitle="الهيكل الأكاديمي" />
            </ProtectedRoute>
          }
        >
          <Route path="/academic-structure"              element={<AcademicStructureHome />} />
          <Route path="/academic-structure/colleges"      element={<CollegesPage />}          />
          <Route path="/academic-structure/departments"   element={<DepartmentsPage />}       />
          <Route path="/academic-structure/programs"      element={<ProgramsPage />}          />
          <Route path="/academic-structure/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* ── الموارد البشرية dashboard ── */}
        <Route
          element={
            <ProtectedRoute permissions={['hr.view']}>
              <DashboardLayout nav={hrNav} appTitle="الموارد البشرية" />
            </ProtectedRoute>
          }
        >
          <Route path="/hr"                    element={<HRHome />}              />
          <Route path="/hr/employees"          element={<EmployeesPage />}       />
          <Route path="/hr/employees/add"      element={protect(<AddEmployeePage />, { permissions: ['hr.manage'] })} />
          <Route path="/hr/employees/:id"      element={<EmployeeProfilePage />} />
          <Route path="/hr/faculty"            element={<FacultyPage />}         />
          <Route path="/hr/positions"          element={<PositionsPage />}       />
          <Route path="/hr/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* ── بوابة الأستاذ dashboard ── */}
        <Route
          element={
            <ProtectedRoute employeeIdentity permissions={['grades.manage', 'attendance.manage', 'supplementary_exams.grades.view']}>
              <DashboardLayout nav={professorNav} appTitle="بوابة الأستاذ" />
            </ProtectedRoute>
          }
        >
          <Route path="/professor"             element={<ProfessorHome />}             />
          <Route path="/professor/attendance"  element={<AttendanceDeprivationPage />}  />
          <Route path="/professor/grades" element={protect(<ProfessorGradesPage />, { employeeIdentity: true, permissions: ['grades.manage'] })} />
          <Route path="/professor/supplementary-exams" element={protect(<ProfessorSupplementaryExams />, { employeeIdentity: true, allRoles: ['doctor_instructor'], assignedPermissions: ['supplementary_exams.grades.view'] })} />
          <Route path="/professor/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* ── بوابة عميد الكلية dashboard ── */}
        <Route
          element={
            <ProtectedRoute roles={['dean']}>
              <DeanLayout />
            </ProtectedRoute>
          }
        >
          <Route path="/dean"               element={<DeanHome />} />
          <Route path="/dean/students"      element={<DeanStudents />} />
          <Route path="/dean/students/:id"  element={<DeanStudentProfile />} />
          <Route path="/dean/teachers"      element={<DeanTeachers />} />
          <Route path="/dean/teachers/:id"  element={<DeanTeacherProfile />} />
          <Route path="/dean/courses"       element={<DeanCourses />} />
          <Route path="/dean/courses/:id"   element={<DeanCourseOfferingProfile />} />
          <Route path="/dean/registration-offerings" element={<DeanRegistrationOfferings />} />
          <Route path="/dean/registration-requests" element={<DeanRegistrationRequests />} />
          <Route path="/dean/registration-requests/:id" element={<DeanRegistrationRequestDetail />} />
          <Route path="/dean/supplementary-exams" element={protect(<DeanSupplementaryExams />, { allRoles: ['dean'], assignedPermissions: ['supplementary_exams.offerings.view'] })} />
          <Route path="/dean/reports"       element={<DeanReports />} />
          <Route path="/dean/calendar"      element={<DeanCalendar />} />
        </Route>

        {/* ── نيابة الشؤون العلمية ── */}
        <Route
          element={
            <ProtectedRoute {...ACCESS.scientificVicePresident}>
              <DashboardLayout nav={scientificVicePresidentNav} appTitle="نيابة الشؤون العلمية" />
            </ProtectedRoute>
          }
        >
          <Route path="/vp/scientific" element={<VicePresidentShell office="scientific" />} />
          <Route path="/vp/scientific/teaching-assignments" element={<TeachingAssignmentQueue office="scientific" />} />
          <Route path="/vp/scientific/teaching-assignments/:id" element={<TeachingAssignmentDetail office="scientific" />} />
          <Route path="/vp/scientific/exceptional-openings" element={<ExceptionalOpeningQueue office="scientific" />} />
          <Route path="/vp/scientific/exceptional-openings/:id" element={<ExceptionalOpeningDetail office="scientific" />} />
          <Route path="/vp/scientific/supplementary-exams" element={protect(<SupplementaryExamPeriodsPage />, { allRoles: ['vice_president_scientific'], assignedPermissions: [PERMISSIONS.supplementaryExamsPeriodsView] })} />
          <Route path="/vp/scientific/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* ── نيابة الشؤون الإدارية ── */}
        <Route
          element={
            <ProtectedRoute {...ACCESS.administrativeVicePresident}>
              <DashboardLayout nav={administrativeVicePresidentNav} appTitle="نيابة الشؤون الإدارية" />
            </ProtectedRoute>
          }
        >
          <Route path="/vp/administrative" element={<VicePresidentShell office="administrative" />} />
          <Route path="/vp/administrative/teaching-assignments" element={<TeachingAssignmentQueue office="administrative" />} />
          <Route path="/vp/administrative/teaching-assignments/:id" element={<TeachingAssignmentDetail office="administrative" />} />
          <Route path="/vp/administrative/exceptional-openings" element={<ExceptionalOpeningQueue office="administrative" />} />
          <Route path="/vp/administrative/exceptional-openings/:id" element={<ExceptionalOpeningDetail office="administrative" />} />
          <Route path="/vp/administrative/calendar" element={<AcademicCalendarPage />} />
        </Route>

        {/* Default redirect */}
        <Route path="/"  element={<Navigate to={landingRoute(getIdentity())} replace />} />
        <Route path="*"  element={<Navigate to={landingRoute(getIdentity())} replace />} />

      </Routes>
    </BrowserRouter>
  )
}
