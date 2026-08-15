import { useEffect, useState } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'

import DashboardLayout from '../components/layout/DashboardLayout'

// ── Auth ────────────────────────────────────────────────────────────────────
import LoginPage from '../features/auth/pages/LoginPage'
import ForbiddenPage from '../features/auth/pages/ForbiddenPage'
import { ACCESS, canAccess, clearIdentity, getIdentity, landingRoute, storeIdentity } from '../features/auth/auth'

// ── شؤون الطلاب (Student Affairs) ──────────────────────────────────────────
import studentAffairsNav    from '../features/student-affairs/nav'
import StudentAffairsHome   from '../features/student-affairs/pages/StudentAffairsHome'
import StudentsPage         from '../features/student-affairs/pages/StudentsPage'
import AddStudentPage       from '../features/student-affairs/pages/AddStudentPage'
import EditStudentPage      from '../features/student-affairs/pages/EditStudentPage'
import StudentProfilePage   from '../features/student-affairs/pages/StudentProfilePage'
import ArchivedStudentsPage from '../features/student-affairs/pages/ArchivedStudentsPage'
import GraduatesPage       from '../features/student-affairs/pages/GraduatesPage'

// ── بوابة الطالب (Student Dashboard) ────────────────────────────────────────
import studentNav        from '../features/student-dashboard/nav'
import StudentHome         from '../features/student-dashboard/pages/StudentHome'
import StudentTranscript  from '../features/student-dashboard/pages/StudentTranscript'
import StudentGPA         from '../features/student-dashboard/pages/StudentGPA'
import StudentAttendance  from '../features/student-dashboard/pages/StudentAttendance'
import StudentRegistration from '../features/student-dashboard/pages/StudentRegistration'

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

// ── بوابة الأستاذ (Professor Dashboard) ─────────────────────────────────────
import professorNav             from '../features/professor-dashboard/nav'
import ProfessorHome            from '../features/professor-dashboard/pages/ProfessorHome'
import AttendanceDeprivationPage from '../features/professor-dashboard/pages/AttendanceDeprivationPage'
import ProfessorGradesPage       from '../features/professor-dashboard/pages/ProfessorGradesPage'

// ── بوابة عميد الكلية (Dean Dashboard) ─────────────────────────────────────
import DeanLayout          from '../features/dean-dashboard/DeanLayout'
import DeanHome            from '../features/dean-dashboard/pages/DeanHome'
import DeanStudents        from '../features/dean-dashboard/pages/DeanStudents'
import DeanStudentProfile  from '../features/dean-dashboard/pages/DeanStudentProfile'
import DeanTeachers        from '../features/dean-dashboard/pages/DeanTeachers'
import DeanTeacherProfile  from '../features/dean-dashboard/pages/DeanTeacherProfile'
import DeanCourses         from '../features/dean-dashboard/pages/DeanCourses'
import DeanReports         from '../features/dean-dashboard/pages/DeanReports'
import DeanCalendar        from '../features/dean-dashboard/pages/DeanCalendar'

function ProtectedRoute({ children, permissions = [], allPermissions = [], roles = [], studentIdentity = false, employeeIdentity = false }) {
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
  return canAccess({ permissions, allPermissions, roles, studentIdentity, employeeIdentity }, identity) ? children : <Navigate to="/forbidden" replace />
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
          <Route path="/exam-board/supplementary" element={protect(<ExamPlaceholder title="الامتحانات التكميلية" en="Supplementary Exams" />, { permissions: ['exams.view'] })} />
          <Route path="/exam-board/results"       element={protect(<ExamPlaceholder title="النتائج والتقارير" en="Results" />, { permissions: ['grades.view'] })} />
          <Route path="/exam-board/courses"             element={protect(<CoursesPage />, ACCESS.courseManagement)} />
          <Route path="/exam-board/course-offerings"    element={protect(<CourseOfferingsPage />, ACCESS.courseManagement)} />
          <Route path="/exam-board/course-table"        element={protect(<CourseTablePage />, ACCESS.courseManagement)} />
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
        </Route>

        {/* ── بوابة الأستاذ dashboard ── */}
        <Route
          element={
            <ProtectedRoute employeeIdentity permissions={['grades.manage', 'attendance.manage']}>
              <DashboardLayout nav={professorNav} appTitle="بوابة الأستاذ" />
            </ProtectedRoute>
          }
        >
          <Route path="/professor"             element={<ProfessorHome />}             />
          <Route path="/professor/attendance"  element={<AttendanceDeprivationPage />}  />
          <Route path="/professor/grades" element={protect(<ProfessorGradesPage />, { employeeIdentity: true, permissions: ['grades.manage'] })} />
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
          <Route path="/dean/reports"       element={<DeanReports />} />
          <Route path="/dean/calendar"      element={<DeanCalendar />} />
        </Route>

        {/* Default redirect */}
        <Route path="/"  element={<Navigate to={landingRoute(getIdentity())} replace />} />
        <Route path="*"  element={<Navigate to={landingRoute(getIdentity())} replace />} />

      </Routes>
    </BrowserRouter>
  )
}
