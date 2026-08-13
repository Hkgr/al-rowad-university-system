import { useEffect, useState } from 'react'
import { FaDatabase, FaWifi } from 'react-icons/fa'

function Status({ Icon, label, value, tone = 'unavailable' }) {
  return (
    <span className={`status-item status-${tone}`}>
      <Icon aria-hidden="true" />
      <span><small>{label}</small><strong>{value}</strong></span>
    </span>
  )
}

export default function DashboardFooter() {
  const [online, setOnline] = useState(() => navigator.onLine)
  const version = import.meta.env.VITE_APP_VERSION
  const showVersion = version && version !== '0.0.0'

  useEffect(() => {
    const updateNetworkStatus = () => setOnline(navigator.onLine)
    window.addEventListener('online', updateNetworkStatus)
    window.addEventListener('offline', updateNetworkStatus)
    return () => {
      window.removeEventListener('online', updateNetworkStatus)
      window.removeEventListener('offline', updateNetworkStatus)
    }
  }, [])

  return (
    <footer className="dashboard-footer" dir="rtl">
      <div className="footer-brand">
        <img src="/logo.png" alt="" />
        <div><strong>نظام جامعة الروّاد</strong><span>جامعة الروّاد · © {new Date().getFullYear()}</span></div>
      </div>
      <div className="footer-statuses">
        <Status Icon={FaWifi} label="اتصال الشبكة" value={online ? 'متصل بالشبكة' : 'غير متصل بالشبكة'} tone={online ? 'online' : 'offline'} />
        <Status Icon={FaDatabase} label="قاعدة البيانات" value="الحالة غير متاحة" />
        {showVersion && <span className="footer-version">الإصدار <strong>{version}</strong></span>}
      </div>
    </footer>
  )
}
