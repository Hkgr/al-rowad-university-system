import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import {
  FaHome, FaGraduationCap, FaChartBar, FaCog,
} from 'react-icons/fa'

import DashboardLayout  from '../components/layout/DashboardLayout'
import LoginPage        from '../features/auth/pages/LoginPage'
import DashboardPage    from '../features/dashboard/pages/DashboardPage'
import StudentsPage     from '../features/students/pages/StudentsPage'
import AddStudentPage   from '../features/students/pages/AddStudentPage'

// ── Nav config for the main dashboard ────────────────────────────────────────
// To add a new dashboard later: copy this block, change the items, add a new
// <Route element={<DashboardLayout nav={newNav} />}> group below.
const mainNav = [
  {
    label: 'القائمة الرئيسية',
    items: [
      { to: '/dashboard', Icon: FaHome,          ar: 'الرئيسية', en: 'Dashboard' },
      { to: '/students',  Icon: FaGraduationCap, ar: 'الطلاب',   en: 'Students'  },
    ],
  },
  {
    label: 'أخرى',
    items: [
      { to: '/reports',  Icon: FaChartBar, ar: 'التقارير',  en: 'Reports'  },
      { to: '/settings', Icon: FaCog,      ar: 'الإعدادات', en: 'Settings' },
    ],
  },
]

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

        {/* Protected — all share the same DashboardLayout */}
        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout nav={mainNav} />
            </ProtectedRoute>
          }
        >
          <Route path="/dashboard"    element={<DashboardPage />} />
          <Route path="/students"     element={<StudentsPage />} />
          <Route path="/students/add" element={<AddStudentPage />} />
        </Route>

        {/* Default redirect */}
        <Route path="/"  element={<Navigate to="/dashboard" replace />} />
        <Route path="*"  element={<Navigate to="/dashboard" replace />} />

      </Routes>
    </BrowserRouter>
  )
}
