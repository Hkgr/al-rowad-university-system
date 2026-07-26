import { BrowserRouter, Routes, Route } from 'react-router-dom'

import DashboardLayout from '../components/layout/DashboardLayout'

// ── Auth ────────────────────────────────────────────────────────────────────
import LoginPage from '../features/auth/pages/LoginPage'
import AccessDeniedPage from '../features/auth/pages/AccessDeniedPage'
import NotFoundPage from '../features/auth/pages/NotFoundPage'
import {
  HomeRedirect,
  ProtectedRoute,
  PublicOnlyRoute,
} from '../features/auth/components/RouteGuards'

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
import GradeEntryPage    from '../features/exam-board/pages/GradeEntryPage'
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

export default function App() {
  return (
    <BrowserRouter>
      <Routes>

        {/* Public */}
        <Route
          path="/login"
          element={(
            <PublicOnlyRoute>
              <LoginPage />
            </PublicOnlyRoute>
          )}
        />

        {/* ── شؤون الطلاب dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <ProtectedRoute dashboard="student-affairs">
                <DashboardLayout nav={studentAffairsNav} appTitle="شؤون الطلاب" />
              </ProtectedRoute>
            </ProtectedRoute>
          }
        >
          <Route
            path="/student-affairs"
            element={(
              <ProtectedRoute permission="students.view">
                <StudentAffairsHome />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/students"
            element={(
              <ProtectedRoute permission="students.view">
                <StudentsPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/students/add"
            element={(
              <ProtectedRoute permission="students.manage">
                <AddStudentPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/students/archived"
            element={(
              <ProtectedRoute permission="students.manage">
                <ArchivedStudentsPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/graduates"
            element={(
              <ProtectedRoute permission="students.view">
                <GraduatesPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/students/:id"
            element={(
              <ProtectedRoute permission="students.view">
                <StudentProfilePage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/students/:id/edit"
            element={(
              <ProtectedRoute permission="students.manage">
                <EditStudentPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/student-affairs/course-registration"
            element={(
              <ProtectedRoute permission="registration.manage">
                <CourseRegistrationPage />
              </ProtectedRoute>
            )}
          />
        </Route>

        {/* ── بوابة الطالب dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <ProtectedRoute dashboard="student">
                <DashboardLayout nav={studentNav} appTitle="بوابة الطالب" />
              </ProtectedRoute>
            </ProtectedRoute>
          }
        >
          <Route path="/student"            element={<StudentHome />}       />
          <Route path="/student/transcript" element={<StudentTranscript />} />
          <Route path="/student/gpa"        element={<StudentGPA />}        />
          <Route path="/student/attendance"    element={<StudentAttendance />} />
        </Route>

        {/* ── هيئة الامتحانات dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <ProtectedRoute dashboard="exam-board">
                <DashboardLayout nav={examBoardNav} appTitle="هيئة الامتحانات" />
              </ProtectedRoute>
            </ProtectedRoute>
          }
        >
          <Route
            path="/exam-board"
            element={(
              <ProtectedRoute permission="exams.view">
                <ExamBoardHome />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/grade-entry"
            element={(
              <ProtectedRoute permission="grades.manage">
                <GradeEntryPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/grade-sheet"
            element={(
              <ProtectedRoute permission="grades.view">
                <GradeSheetPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/approvals"
            element={(
              <ProtectedRoute permission="grades.manage">
                <ApprovalsPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/deprivation"
            element={(
              <ProtectedRoute permission="exams.manage">
                <DeprivationPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/supplementary"
            element={(
              <ProtectedRoute permission="exams.manage">
                <ExamPlaceholder title="الامتحانات التكميلية" en="Supplementary Exams" />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/results"
            element={(
              <ProtectedRoute permission="grades.view">
                <ExamPlaceholder title="النتائج والتقارير" en="Results" />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/courses"
            element={(
              <ProtectedRoute permission="courses.manage">
                <CoursesPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/course-offerings"
            element={(
              <ProtectedRoute permission="courses.manage">
                <CourseOfferingsPage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/course-table"
            element={(
              <ProtectedRoute permission="courses.manage">
                <CourseTablePage />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/appeals"
            element={(
              <ProtectedRoute permission="exams.manage">
                <ExamPlaceholder title="التظلمات" en="Appeals" />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/exam-board/settings"
            element={(
              <ProtectedRoute permission="system_settings.manage">
                <ExamPlaceholder title="الإعدادات" en="Settings" />
              </ProtectedRoute>
            )}
          />
        </Route>

        {/* ── الهيكل الأكاديمي dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <ProtectedRoute dashboard="academic-structure">
                <DashboardLayout nav={academicStructureNav} appTitle="الهيكل الأكاديمي" />
              </ProtectedRoute>
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
            <ProtectedRoute>
              <ProtectedRoute dashboard="hr">
                <DashboardLayout nav={hrNav} appTitle="الموارد البشرية" />
              </ProtectedRoute>
            </ProtectedRoute>
          }
        >
          <Route path="/hr"                    element={<HRHome />}              />
          <Route path="/hr/employees"          element={<EmployeesPage />}       />
          <Route path="/hr/employees/add"      element={<AddEmployeePage />}     />
          <Route path="/hr/employees/:id"      element={<EmployeeProfilePage />} />
          <Route path="/hr/faculty"            element={<FacultyPage />}         />
          <Route path="/hr/positions"          element={<PositionsPage />}       />
        </Route>

        {/* ── بوابة الأستاذ dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <ProtectedRoute dashboard="professor">
                <DashboardLayout nav={professorNav} appTitle="بوابة الأستاذ" />
              </ProtectedRoute>
            </ProtectedRoute>
          }
        >
          <Route
            path="/professor"
            element={(
              <ProtectedRoute permission="courses.view">
                <ProfessorHome />
              </ProtectedRoute>
            )}
          />
          <Route
            path="/professor/attendance"
            element={(
              <ProtectedRoute permission="attendance.manage">
                <AttendanceDeprivationPage />
              </ProtectedRoute>
            )}
          />
        </Route>

        <Route
          path="/403"
          element={(
            <ProtectedRoute>
              <AccessDeniedPage />
            </ProtectedRoute>
          )}
        />

        <Route path="/" element={<HomeRedirect />} />
        <Route
          path="*"
          element={(
            <ProtectedRoute>
              <NotFoundPage />
            </ProtectedRoute>
          )}
        />

      </Routes>
    </BrowserRouter>
  )
}
