import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import LoginPage      from '../features/auth/pages/LoginPage'
import DashboardPage  from '../features/dashboard/pages/DashboardPage'
import StudentsPage   from '../features/students/pages/StudentsPage'
import AddStudentPage from '../features/students/pages/AddStudentPage'

function ProtectedRoute({ children }) {
  const token = localStorage.getItem('token')
  return token ? children : <Navigate to="/login" replace />
}

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />

        <Route path="/dashboard" element={
          <ProtectedRoute><DashboardPage /></ProtectedRoute>
        } />
        <Route path="/students" element={
          <ProtectedRoute><StudentsPage /></ProtectedRoute>
        } />
        <Route path="/students/add" element={
          <ProtectedRoute><AddStudentPage /></ProtectedRoute>
        } />

        {/* Default: go straight to dashboard (login redirects if no token) */}
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
