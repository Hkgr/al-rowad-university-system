import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'

import DashboardLayout from '../components/layout/DashboardLayout'

// ── Auth ────────────────────────────────────────────────────────────────────
import LoginPage from '../features/auth/pages/LoginPage'

// ── شؤون الطلاب (Student Affairs) ──────────────────────────────────────────
import studentAffairsNav    from '../features/student-affairs/nav'
import StudentAffairsHome   from '../features/student-affairs/pages/StudentAffairsHome'
import StudentsPage         from '../features/student-affairs/pages/StudentsPage'
import AddStudentPage       from '../features/student-affairs/pages/AddStudentPage'
import EditStudentPage      from '../features/student-affairs/pages/EditStudentPage'
import StudentProfilePage   from '../features/student-affairs/pages/StudentProfilePage'
import ArchivedStudentsPage from '../features/student-affairs/pages/ArchivedStudentsPage'

// ── بوابة الطالب (Student Dashboard) ────────────────────────────────────────
import studentNav        from '../features/student-dashboard/nav'
import StudentHome       from '../features/student-dashboard/pages/StudentHome'
import StudentTranscript from '../features/student-dashboard/pages/StudentTranscript'
import StudentGPA        from '../features/student-dashboard/pages/StudentGPA'
import StudentAttendance from '../features/student-dashboard/pages/StudentAttendance'

// ── هيئة الامتحانات (Exam Board) ────────────────────────────────────────────
import examBoardNav      from '../features/exam-board/nav'
import ExamBoardHome     from '../features/exam-board/pages/ExamBoardHome'
import GradeSheetPage    from '../features/exam-board/pages/GradeSheetPage'
import GradeEntryPage    from '../features/exam-board/pages/GradeEntryPage'
import ApprovalsPage     from '../features/exam-board/pages/ApprovalsPage'
import DeprivationPage        from '../features/exam-board/pages/DeprivationPage'
import CourseDepartmentPage   from '../features/exam-board/pages/CourseDepartmentPage'
import CoursesPage            from '../features/exam-board/pages/CoursesPage'
import ExamPlaceholder        from '../features/exam-board/pages/ExamPlaceholder'

function ProtectedRoute({ children }) {
  const token = localStorage.getItem('token')
  return token ? children : <Navigate to="/login" replace />
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>

        {/* Public */}
        <Route path="/login" element={<LoginPage />} />

        {/* ── شؤون الطلاب dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout nav={studentAffairsNav} appTitle="شؤون الطلاب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student-affairs"                   element={<StudentAffairsHome />}   />
          <Route path="/student-affairs/students"          element={<StudentsPage />}          />
          <Route path="/student-affairs/students/add"      element={<AddStudentPage />}        />
          <Route path="/student-affairs/students/archived" element={<ArchivedStudentsPage />}  />
          <Route path="/student-affairs/students/:id"      element={<StudentProfilePage />}    />
          <Route path="/student-affairs/students/:id/edit" element={<EditStudentPage />}       />
        </Route>

        {/* ── بوابة الطالب dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout nav={studentNav} appTitle="بوابة الطالب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student"            element={<StudentHome />}       />
          <Route path="/student/transcript" element={<StudentTranscript />} />
          <Route path="/student/gpa"        element={<StudentGPA />}        />
          <Route path="/student/attendance" element={<StudentAttendance />} />
        </Route>

        {/* ── هيئة الامتحانات dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout nav={examBoardNav} appTitle="هيئة الامتحانات" />
            </ProtectedRoute>
          }
        >
          <Route path="/exam-board"                element={<ExamBoardHome />} />
          <Route path="/exam-board/grade-entry"   element={<GradeEntryPage />} />
          <Route path="/exam-board/grade-sheet"   element={<GradeSheetPage />} />
          <Route path="/exam-board/approvals"     element={<ApprovalsPage />} />
          <Route path="/exam-board/deprivation"   element={<DeprivationPage />} />
          <Route path="/exam-board/supplementary" element={<ExamPlaceholder title="الامتحانات التكميلية" en="Supplementary Exams" />} />
          <Route path="/exam-board/results"       element={<ExamPlaceholder title="النتائج والتقارير"    en="Results" />} />
          <Route path="/exam-board/courses"             element={<CoursesPage />} />
          <Route path="/exam-board/courses-departments" element={<CourseDepartmentPage />} />
          <Route path="/exam-board/appeals"          element={<ExamPlaceholder title="التظلمات"             en="Appeals" />} />
          <Route path="/exam-board/settings"         element={<ExamPlaceholder title="الإعدادات"            en="Settings" />} />
        </Route>

        {/* Default redirect */}
        <Route path="/"  element={<Navigate to="/student-affairs" replace />} />
        <Route path="*"  element={<Navigate to="/student-affairs" replace />} />

      </Routes>
    </BrowserRouter>
  )
}
