import { useNavigate } from 'react-router-dom'

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
    <div className="min-h-screen bg-bg-light flex items-center justify-center px-5 py-10">
      <div className="bg-white rounded-[18px] p-10 w-full max-w-[520px] border border-primary/15 shadow-[0_8px_32px_rgba(86,153,51,0.12)]">

        <h2 className="text-[22px] font-extrabold text-primary-dark mb-1.5">
          Login Successful ✓
        </h2>
        <p className="text-[13px] text-text-light mb-7">
          Here is the data returned from the API
        </p>

        <div className="flex justify-between items-center py-3 border-b border-gray-100">
          <span className="text-[13px] font-semibold text-text-light">Username</span>
          <span className="text-sm font-bold text-text-dark">{user.username}</span>
        </div>
        <div className="flex justify-between items-center py-3 border-b border-gray-100">
          <span className="text-[13px] font-semibold text-text-light">Email</span>
          <span className="text-sm font-bold text-text-dark">{user.email}</span>
        </div>
        <div className="flex justify-between items-center py-3 border-b border-gray-100">
          <span className="text-[13px] font-semibold text-text-light">User ID</span>
          <span className="text-sm font-bold text-text-dark">{user.user_id}</span>
        </div>
        <div className="flex justify-between items-center py-3 border-b border-gray-100">
          <span className="text-[13px] font-semibold text-text-light">Student ID</span>
          <span className="text-sm font-bold text-text-dark">{user.student_id ?? 'None (Admin)'}</span>
        </div>
        <div className="flex justify-between items-center py-3 border-b border-gray-100">
          <span className="text-[13px] font-semibold text-text-light">Employee ID</span>
          <span className="text-sm font-bold text-text-dark">{user.employee_id ?? 'None'}</span>
        </div>

        <div className="mt-5 flex flex-col gap-2">
          <span className="text-[13px] font-semibold text-text-light">Token</span>
          <code className="bg-bg-light border border-primary/20 rounded-lg px-3.5 py-2.5 text-[11px] text-primary-dark break-all font-mono">
            {token}
          </code>
        </div>

        <button
          className="mt-7 w-full py-[13px] bg-gradient-to-br from-primary to-primary-dark text-white border-0 rounded-xl text-[15px] font-bold cursor-pointer transition-opacity duration-200 hover:opacity-90"
          onClick={handleLogout}
        >
          Logout
        </button>

      </div>
    </div>
  )
}
