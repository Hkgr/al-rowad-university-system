import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  FaArchive, FaBoxOpen, FaSpinner,
} from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return {
    Authorization: `Bearer ${localStorage.getItem('token')}`,
    Accept: 'application/json',
  }
}

export default function ArchivedStudentsPage() {
  const [students, setStudents] = useState([])
  const [loading, setLoading]   = useState(true)
  const [error, setError]       = useState('')
  const navigate                = useNavigate()

  const fetchArchived = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const res  = await fetch(`${API}/students/deleted`, { headers: authHeaders() })
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()
      if (json.success) {
        setStudents(Array.isArray(json.data) ? json.data : [])
      } else {
        setError(json.message || 'ÙØ´Ù„ ØªØ­Ù…ÙŠÙ„ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª')
      }
    } catch {
      setError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…. ØªØ£ÙƒØ¯ Ø£Ù† php artisan serve ÙŠØ¹Ù…Ù„.')
    } finally {
      setLoading(false)
    }
  }, [navigate])

  useEffect(() => { fetchArchived() }, [fetchArchived])

  const handleRestore = async (id) => {
    if (!window.confirm('Ù‡Ù„ ØªØ±ÙŠØ¯ Ø§Ø³ØªØ¹Ø§Ø¯Ø© Ù‡Ø°Ø§ Ø§Ù„Ø·Ø§Ù„Ø¨ ÙˆØ¥Ø¹Ø§Ø¯ØªÙ‡ Ù„Ù„Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ù†Ø´Ø·Ø©ØŸ')) return
    try {
      const res  = await fetch(`${API}/students/${id}/restore`, { method: 'POST', headers: authHeaders() })
      const json = await res.json()
      if (json.success) {
        setStudents(prev => prev.filter(s => s.student_id !== id))
      } else {
        alert(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø§Ø³ØªØ¹Ø§Ø¯Ø©')
      }
    } catch {
      alert('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…')
    }
  }

  return (
    <>
      {/* Page header */}
      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">Ø§Ù„Ø·Ù„Ø§Ø¨ Ø§Ù„Ù…Ø¤Ø±Ø´ÙÙˆÙ†</h2>
          <p className="text-[12.5px] text-text-light">
            {loading ? 'Ø¬Ø§Ø±ÙŠ Ø§Ù„ØªØ­Ù…ÙŠÙ„â€¦' : `${students.length} Ø·Ø§Ù„Ø¨ Ù…Ø¤Ø±Ø´Ù`}
          </p>
        </div>
      </div>

      {/* Info banner */}
      <div className="flex items-center gap-2.5 bg-slate-50 border border-slate-200 rounded-[12px] px-4 py-3 mb-5 text-[13px] text-slate-600" dir="rtl">
        <FaArchive className="text-slate-400 flex-shrink-0" />
        <span>Ø§Ù„Ø·Ù„Ø§Ø¨ Ø§Ù„Ù…Ø¤Ø±Ø´ÙÙˆÙ† Ù…Ø­ÙÙˆØ¸ÙˆÙ† ÙÙŠ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª ÙˆÙ„Ù… ÙŠÙØ­Ø°ÙÙˆØ§. ÙŠÙ…ÙƒÙ†Ùƒ Ø§Ø³ØªØ¹Ø§Ø¯Ø© Ø£ÙŠ Ø·Ø§Ù„Ø¨ Ù„Ø¥Ø¹Ø§Ø¯ØªÙ‡ Ù„Ù„Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ù†Ø´Ø·Ø©.</span>
      </div>

      {/* Error */}
      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-500/6 border border-red-500/25 rounded-[12px] px-[18px] py-3 mb-4 text-[13.5px] text-red-600" dir="rtl">
          <span>âš  {error}</span>
          <button
            className="px-3.5 py-1 bg-transparent border border-red-500/35 rounded-[8px] text-red-600 text-[12px] cursor-pointer whitespace-nowrap transition-all duration-200 hover:bg-red-500/8"
            onClick={fetchArchived}
          >
            Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ù…Ø­Ø§ÙˆÙ„Ø©
          </button>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-[16px] border border-slate-200 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-slate-400 text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>Ø¬Ø§Ø±ÙŠ Ø§Ù„ØªØ­Ù…ÙŠÙ„â€¦</span>
          </div>
        ) : students.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-[60px]">
            <FaArchive className="text-[48px] text-slate-200 mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ø·Ù„Ø§Ø¨ Ù…Ø¤Ø±Ø´ÙÙˆÙ†</p>
            <p className="text-[12.5px] text-text-light">No archived students</p>
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                <th className="px-4 py-3.5 text-left text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap">#</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">Ø±Ù‚Ù… Ø§Ù„Ù‚ÙŠØ¯</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">Ø§Ù„Ø§Ø³Ù… Ø§Ù„ÙƒØ§Ù…Ù„</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">ØªØ§Ø±ÙŠØ® Ø§Ù„Ù‚Ø¨ÙˆÙ„</th>
                <th className="px-4 py-3.5 text-right text-[12px] font-bold text-white/90 bg-slate-600 whitespace-nowrap" dir="rtl">Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª</th>
              </tr>
            </thead>
            <AnimatePresence mode="wait">
              <motion.tbody
                key="archived"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                exit={{ opacity: 0 }}
                transition={{ duration: 0.18 }}
              >
                {students.map((s, idx) => (
                  <tr key={s.student_id} className="border-b border-slate-100 last:border-b-0 bg-slate-50/40 hover:bg-slate-100/60 transition-colors duration-150">
                    <td className="px-4 py-[13px] text-[12px] text-text-light font-semibold w-10">{idx + 1}</td>
                    <td className="px-4 py-[13px] align-middle">
                      <span className="inline-block px-2.5 py-[3px] bg-slate-100 border border-slate-200 rounded-[8px] text-[12px] font-bold text-slate-500 font-mono">
                        {s.student_number}
                      </span>
                    </td>
                    <td className="px-4 py-[13px] text-[13.5px] font-semibold text-text-gray align-middle" dir="rtl">
                      {s.first_name} {s.last_name}
                    </td>
                    <td className="px-4 py-[13px] text-[12.5px] text-text-gray align-middle">{s.email || 'â€”'}</td>
                    <td className="px-4 py-[13px] text-[13.5px] text-text-gray align-middle">{s.phone_number || 'â€”'}</td>
                    <td className="px-4 py-[13px] text-[13.5px] text-text-gray align-middle">
                      {s.enrollment_date ? new Date(s.enrollment_date).toLocaleDateString('ar-SY') : 'â€”'}
                    </td>
                    <td className="px-4 py-[13px] align-middle">
                      <button
                        className="flex items-center gap-1.5 px-3 py-1.5 rounded-[8px] border text-[12.5px] font-bold cursor-pointer transition-all duration-[180ms] text-green-600 border-green-500/25 bg-green-500/6 hover:bg-green-500/14 hover:border-green-500/40"
                        onClick={() => handleRestore(s.student_id)}
                        dir="rtl"
                      >
                        <FaBoxOpen className="text-[12px]" />
                        Ø§Ø³ØªØ¹Ø§Ø¯Ø©
                      </button>
                    </td>
                  </tr>
                ))}
              </motion.tbody>
            </AnimatePresence>
          </table>
        )}
      </div>
    </>
  )
}

