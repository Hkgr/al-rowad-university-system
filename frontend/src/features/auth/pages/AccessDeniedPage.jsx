import { FaArrowRight, FaLock } from 'react-icons/fa'
import { useNavigate } from 'react-router-dom'
import useAuth from '../useAuth'

export default function AccessDeniedPage() {
  const navigate = useNavigate()
  const { user, logout } = useAuth()

  const goBack = () => {
    if (window.history.length > 1) {
      navigate(-1)
      return
    }

    navigate(user?.default_dashboard || '/login', { replace: true })
  }

  const handleLogout = async () => {
    await logout()
    navigate('/login', { replace: true })
  }

  return (
    <main className="min-h-screen flex items-center justify-center bg-[#f0f5ec] px-5" dir="rtl">
      <section className="w-full max-w-[540px] rounded-[24px] bg-white border border-primary/15 shadow-[0_20px_60px_rgba(65,115,39,0.12)] p-9 text-center">
        <div className="w-20 h-20 mx-auto rounded-full bg-red-50 text-red-500 flex items-center justify-center text-[30px]">
          <FaLock />
        </div>
        <p className="mt-5 text-xs font-black tracking-[2px] text-red-500">403 · ACCESS DENIED</p>
        <h1 className="mt-2 text-2xl font-black text-text-dark">لا تملك صلاحية الوصول</h1>
        <p className="mt-3 text-sm leading-7 text-text-gray">
          حسابك مسجّل بنجاح، لكن الدور المعيّن له لا يسمح بفتح هذه الصفحة.
          إن كنت تحتاجها، تواصل مع مدير النظام لتعديل دورك.
        </p>
        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <button
            type="button"
            onClick={goBack}
            className="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-bold text-white hover:bg-primary-dark"
          >
            <FaArrowRight />
            العودة
          </button>
          {user?.default_dashboard && (
            <button
              type="button"
              onClick={() => navigate(user.default_dashboard, { replace: true })}
              className="rounded-xl border border-primary/25 bg-white px-5 py-3 text-sm font-bold text-primary-dark hover:bg-primary/5"
            >
              لوحتي الرئيسية
            </button>
          )}
          <button
            type="button"
            onClick={handleLogout}
            className="rounded-xl border border-red-200 bg-white px-5 py-3 text-sm font-bold text-red-600 hover:bg-red-50"
          >
            تسجيل الخروج
          </button>
        </div>
      </section>
    </main>
  )
}
