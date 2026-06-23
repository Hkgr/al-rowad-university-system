import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'

import DashboardLayout     from '../components/layout/DashboardLayout'
import studentAffairsNav   from '../features/student-affairs/layout/studentAffairsNav'

import LoginPage            from '../features/auth/pages/LoginPage'
import StudentAffairsHome   from '../features/student-affairs/pages/StudentAffairsHome'
import StudentsPage         from '../features/students/pages/StudentsPage'
import AddStudentPage       from '../features/students/pages/AddStudentPage'
import EditStudentPage      from '../features/students/pages/EditStudentPage'

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

        {/* ── شؤون الطلاب (Student Affairs) dashboard ── */}
        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout nav={studentAffairsNav} appTitle="شؤون الطلاب" />
            </ProtectedRoute>
          }
        >
          <Route path="/student-affairs"              element={<StudentAffairsHome />} />
          <Route path="/student-affairs/students"     element={<StudentsPage />}       />
          <Route path="/student-affairs/students/add"      element={<AddStudentPage />}  />
          <Route path="/student-affairs/students/:id/edit" element={<EditStudentPage />} />
        </Route>

        {/* Default redirect */}
        <Route path="/"  element={<Navigate to="/student-affairs" replace />} />
        <Route path="*"  element={<Navigate to="/student-affairs" replace />} />

      </Routes>
    </BrowserRouter>
  )
}
