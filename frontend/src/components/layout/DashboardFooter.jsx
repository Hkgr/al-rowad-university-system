import { useEffect, useState } from 'react'
import { FaCircle, FaDatabase, FaServer } from 'react-icons/fa'
const Status = ({ Icon, label, value, tone = 'muted' }) => <span className="status-item"><Icon /><span>{label}</span><FaCircle className={tone} /><strong>{value}</strong></span>
export default function DashboardFooter() {
  const [online, setOnline] = useState(navigator.onLine)
  useEffect(() => { const update = () => setOnline(navigator.onLine); addEventListener('online', update); addEventListener('offline', update); return () => { removeEventListener('online', update); removeEventListener('offline', update) } }, [])
  return <footer className="dashboard-footer" dir="rtl">
    <div className="footer-brand"><img src="/logo.png" alt="" /><div><strong>نظام جامعة الروّاد</strong><span>© {new Date().getFullYear()} · النظام الإداري والأكاديمي</span></div></div>
    <div className="footer-meta"><Status Icon={FaServer} label="النظام" value={online ? 'متصل' : 'غير متصل'} tone={online ? 'online' : 'offline'} /><Status Icon={FaDatabase} label="قاعدة البيانات" value="الحالة غير متاحة" /><span>البيئة: <strong>{import.meta.env.PROD ? 'الإنتاج' : 'التطوير'}</strong></span><span>الإصدار {import.meta.env.VITE_APP_VERSION || '0.0.0'}</span></div>
  </footer>
}
