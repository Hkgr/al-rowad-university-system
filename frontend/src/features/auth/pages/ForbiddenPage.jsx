import { Link } from 'react-router-dom'

export default function ForbiddenPage() {
  return <div className="min-h-screen flex items-center justify-center bg-[#f0f5ec] px-4" dir="rtl">
    <div className="bg-white border border-red-200 rounded-[18px] p-8 text-center shadow-sm max-w-md">
      <div className="text-[44px] font-black text-red-500 mb-2">403</div>
      <h1 className="text-[18px] font-black text-text-dark mb-2">غير مصرح لك بالوصول</h1>
      <p className="text-[13px] text-text-light mb-5">لا يملك حسابك الصلاحية المطلوبة لهذه الصفحة.</p>
      <Link to="/" className="inline-block px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold">العودة للرئيسية</Link>
    </div>
  </div>
}
