import { useEffect, useState } from 'react'
import { FaWifi } from 'react-icons/fa'

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
    <footer className="dashboard-footer print-hidden" dir="rtl">
      <div className="footer-brand">
        <strong>نظام جامعة الروّاد</strong>
        <span>© {new Date().getFullYear()}</span>
      </div>
      <div className="footer-statuses">
        <span className={`network-status status-${online ? 'online' : 'offline'}`}>
          <FaWifi aria-hidden="true" />
          {online ? 'متصل بالشبكة' : 'غير متصل بالشبكة'}
        </span>
        {showVersion && <span className="footer-version">الإصدار <strong>{version}</strong></span>}
      </div>
    </footer>
  )
}
