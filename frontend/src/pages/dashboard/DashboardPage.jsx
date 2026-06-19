import { useNavigate } from 'react-router-dom'
import './DashboardPage.css'

export default function DashboardPage() {
  const navigate  = useNavigate()
  const user      = JSON.parse(localStorage.getItem('user') || '{}')
  const token     = localStorage.getItem('token') || ''

  const handleLogout = () => {
    localStorage.removeItem('token')
    localStorage.removeItem('user')
    navigate('/login')
  }

  return (
    <div className="dash-page">
      <div className="dash-card">
        <h2 className="dash-title">Login Successful ✓</h2>
        <p className="dash-sub">Here is the data returned from the API</p>

        <div className="dash-row">
          <span className="dash-label">Username</span>
          <span className="dash-value">{user.username}</span>
        </div>
        <div className="dash-row">
          <span className="dash-label">Email</span>
          <span className="dash-value">{user.email}</span>
        </div>
        <div className="dash-row">
          <span className="dash-label">User ID</span>
          <span className="dash-value">{user.user_id}</span>
        </div>
        <div className="dash-row">
          <span className="dash-label">Student ID</span>
          <span className="dash-value">{user.student_id ?? 'None (Admin)'}</span>
        </div>
        <div className="dash-row">
          <span className="dash-label">Employee ID</span>
          <span className="dash-value">{user.employee_id ?? 'None'}</span>
        </div>

        <div className="dash-token-box">
          <span className="dash-label">Token</span>
          <code className="dash-token">{token}</code>
        </div>

        <button className="dash-logout" onClick={handleLogout}>
          Logout
        </button>
      </div>
    </div>
  )
}
