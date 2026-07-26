import { FaHome } from 'react-icons/fa'
import { useNavigate } from 'react-router-dom'
import useAuth from '../useAuth'

export default function NotFoundPage() {
  const navigate = useNavigate()
  const { user } = useAuth()

  return (
    <main className="min-h-screen flex items-center justify-center bg-[#f0f5ec] px-5" dir="rtl">
      <section className="w-full max-w-[520px] rounded-[24px] bg-white border border-primary/15 shadow-[0_20px_60px_rgba(65,115,39,0.12)] p-9 text-center">
        <p className="text-6xl font-black text-primary/25">404</p>
        <h1 className="mt-2 text-2xl font-black text-text-dark">الصفحة غير موجودة</h1>
        <p className="mt-3 text-sm leading-7 text-text-gray">
          قد يكون الرابط غير صحيح أو نُقلت الصفحة إلى مسار آخر.
        </p>
        <button
          type="button"
          onClick={() => navigate(user?.default_dashboard || '/login', { replace: true })}
          className="mt-7 inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-bold text-white hover:bg-primary-dark"
        >
          <FaHome />
          الصفحة الرئيسية
        </button>
      </section>
    </main>
  )
}
