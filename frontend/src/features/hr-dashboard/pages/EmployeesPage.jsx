import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import {
  FaUserPlus, FaSearch, FaEye, FaEdit, FaTrash,
  FaChevronLeft, FaChevronRight, FaSpinner, FaUsers, FaTimes, FaSave,
} from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const TYPE_AR   = { academic: 'Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠ', administrative: 'Ø¥Ø¯Ø§Ø±ÙŠ', technical: 'ØªÙ‚Ù†ÙŠ', service: 'Ø®Ø¯Ù…Ø§Øª', board_member: 'Ø¹Ø¶Ùˆ Ù…Ø¬Ù„Ø³' }
const STATUS_AR = { active: 'Ù†Ø´Ø·', inactive: 'ØºÙŠØ± Ù†Ø´Ø·', on_leave: 'ÙÙŠ Ø¥Ø¬Ø§Ø²Ø©', terminated: 'Ù…Ù†ØªÙ‡ÙŠ Ø§Ù„Ø®Ø¯Ù…Ø©' }
const STATUS_COLOR = {
  active:     'bg-green-100 text-green-700',
  inactive:   'bg-slate-100 text-slate-600',
  on_leave:   'bg-amber-100 text-amber-700',
  terminated: 'bg-red-100 text-red-600',
}

const EMPTY_FORM = {
  employee_number: '', first_name: '', last_name: '', father_name: '', mother_name: '',
  phone_number: '', email: '', hire_date: '', employee_type_id: '', employee_status_id: '',
}

function EmployeeModal({ emp, types, statuses, onClose, onSaved }) {
  const [form, setForm]     = useState(emp ? { ...emp, employee_type_id: emp.employee_type_id ?? '', employee_status_id: emp.employee_status_id ?? '' } : { ...EMPTY_FORM })
  const [saving, setSaving] = useState(false)
  const [err, setErr]       = useState('')

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))

  async function handleSave() {
    setSaving(true); setErr('')
    const url    = emp ? `${API}/employees/${emp.employee_id}` : `${API}/employees`
    const method = emp ? 'PUT' : 'POST'
    try {
      const res  = await fetch(url, {
        method,
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...form, employee_type_id: Number(form.employee_type_id), employee_status_id: Number(form.employee_status_id) }),
      })
      const json = await res.json()
      if (json.success) { onSaved() }
      else setErr(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©')
    } catch { setErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally  { setSaving(false) }
  }

  const Field = ({ label, k, type = 'text', half }) => (
    <div className={half ? 'col-span-1' : 'col-span-2'}>
      <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">{label}</label>
      <input
        type={type} value={form[k] ?? ''}
        onChange={e => set(k, e.target.value)}
        className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark outline-none focus:border-primary"
        dir="rtl"
      />
    </div>
  )

  return (
    <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <motion.div
        className="bg-white rounded-[18px] shadow-2xl w-full max-w-[620px] max-h-[90vh] overflow-y-auto"
        initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} transition={{ duration: 0.2 }}
      >
        <div className="flex items-center justify-between px-6 py-4 border-b border-primary/10" dir="rtl">
          <h3 className="text-[16px] font-extrabold text-text-dark">{emp ? 'ØªØ¹Ø¯ÙŠÙ„ Ù…ÙˆØ¸Ù' : 'Ø¥Ø¶Ø§ÙØ© Ù…ÙˆØ¸Ù Ø¬Ø¯ÙŠØ¯'}</h3>
          <button onClick={onClose} className="w-8 h-8 flex items-center justify-center rounded-full hover:bg-gray-100 text-text-light transition-colors"><FaTimes /></button>
        </div>

        <div className="p-6 grid grid-cols-2 gap-4" dir="rtl">
          <Field label="Ø±Ù‚Ù… Ø§Ù„Ù…ÙˆØ¸Ù *"       k="employee_number" half />
          <Field label="ØªØ§Ø±ÙŠØ® Ø§Ù„ØªØ¹ÙŠÙŠÙ†"       k="hire_date" type="date" half />
          <Field label="Ø§Ù„Ø§Ø³Ù… Ø§Ù„Ø£ÙˆÙ„ *"       k="first_name"  half />
          <Field label="Ø§Ø³Ù… Ø§Ù„Ø¹Ø§Ø¦Ù„Ø© *"       k="last_name"   half />
          <Field label="Ø§Ø³Ù… Ø§Ù„Ø£Ø¨"            k="father_name" half />
          <Field label="Ø§Ø³Ù… Ø§Ù„Ø£Ù…"            k="mother_name" half />
          <Field label="Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ"   k="email" type="email" half />
          <Field label="Ø±Ù‚Ù… Ø§Ù„Ù‡Ø§ØªÙ"          k="phone_number" half />

          <div className="col-span-1">
            <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">Ù†ÙˆØ¹ Ø§Ù„Ù…ÙˆØ¸Ù *</label>
            <select value={form.employee_type_id} onChange={e => set('employee_type_id', e.target.value)}
              className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" dir="rtl">
              <option value="">Ø§Ø®ØªØ± Ø§Ù„Ù†ÙˆØ¹</option>
              {types.map(t => <option key={t.employee_type_id} value={t.employee_type_id}>{TYPE_AR[t.type_code] ?? t.type_name}</option>)}
            </select>
          </div>

          <div className="col-span-1">
            <label className="block text-[11.5px] font-bold text-text-dark mb-1" dir="rtl">Ø§Ù„Ø­Ø§Ù„Ø© *</label>
            <select value={form.employee_status_id} onChange={e => set('employee_status_id', e.target.value)}
              className="w-full px-3 py-2 border border-primary/20 rounded-[9px] text-[13px] outline-none focus:border-primary" dir="rtl">
              <option value="">Ø§Ø®ØªØ± Ø§Ù„Ø­Ø§Ù„Ø©</option>
              {statuses.map(s => <option key={s.employee_status_id} value={s.employee_status_id}>{STATUS_AR[s.status_code] ?? s.status_name}</option>)}
            </select>
          </div>
        </div>

        {err && <p className="mx-6 mb-3 text-[12px] text-red-600 bg-red-50 border border-red-200 rounded-[8px] px-3 py-2" dir="rtl">âš  {err}</p>}

        <div className="flex items-center justify-end gap-3 px-6 pb-5 pt-1" dir="rtl">
          <button onClick={onClose} className="px-5 py-2 border border-primary/20 rounded-[9px] text-[13px] text-text-dark hover:bg-gray-50 transition-colors">Ø¥Ù„ØºØ§Ø¡</button>
          <button onClick={handleSave} disabled={saving}
            className="flex items-center gap-2 px-5 py-2 bg-primary text-white rounded-[9px] text-[13px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
            {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaSave className="text-[11px]" />}
            Ø­ÙØ¸
          </button>
        </div>
      </motion.div>
    </div>
  )
}

export default function EmployeesPage() {
  const [employees, setEmployees] = useState([])
  const [loading,   setLoading]   = useState(true)
  const [error,     setError]     = useState('')
  const [search,    setSearch]    = useState('')
  const [page,      setPage]      = useState(1)
  const [meta,      setMeta]      = useState({ total: 0, last_page: 1, per_page: 15 })
  const [types,     setTypes]     = useState([])
  const [statuses,  setStatuses]  = useState([])
  const [modal,     setModal]     = useState(null) // null | 'add' | employee obj
  const debounceRef = useRef(null)
  const navigate    = useNavigate()

  useEffect(() => {
    Promise.all([
      fetch(`${API}/employee-types?per_page=50`,    { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/employee-statuses?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([t, s]) => {
      setTypes(t.success   ? (t.data?.data ?? t.data ?? []) : [])
      setStatuses(s.success ? (s.data?.data ?? s.data ?? []) : [])
    })
  }, [])

  const fetchEmployees = useCallback(async (q, p) => {
    setLoading(true); setError('')
    try {
      const url = `${API}/employees?page=${p}&per_page=15${q ? `&search=${encodeURIComponent(q)}` : ''}`
      const res  = await fetch(url, { headers: authHeaders() })
      if (res.status === 401) { navigate('/login'); return }
      const json = await res.json()
      if (json.success) {
        const d = json.data
        setEmployees(d.data ?? [])
        setMeta({ total: d.meta?.total ?? d.total ?? 0, last_page: d.meta?.last_page ?? d.last_page ?? 1, per_page: d.meta?.per_page ?? 15 })
      } else setError(json.message || 'ÙØ´Ù„ ØªØ­Ù…ÙŠÙ„ Ø§Ù„Ø¨ÙŠØ§Ù†Ø§Øª')
    } catch { setError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally   { setLoading(false) }
  }, [navigate])

  useEffect(() => {
    clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(() => { setPage(1); fetchEmployees(search, 1) }, 380)
    return () => clearTimeout(debounceRef.current)
  }, [search, fetchEmployees])

  useEffect(() => { fetchEmployees(search, page) }, [page]) // eslint-disable-line

  async function handleDelete(id, name) {
    if (!window.confirm(`Ù‡Ù„ ØªØ±ÙŠØ¯ Ø­Ø°Ù Ø§Ù„Ù…ÙˆØ¸Ù "${name}"ØŸ\nÙ‡Ø°Ø§ Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡ Ù„Ø§ ÙŠÙ…ÙƒÙ† Ø§Ù„ØªØ±Ø§Ø¬Ø¹ Ø¹Ù†Ù‡.`)) return
    try {
      const res  = await fetch(`${API}/employees/${id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) { fetchEmployees(search, page) }
      else alert(json.message || 'ÙØ´Ù„ Ø§Ù„Ø­Ø°Ù')
    } catch { alert('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
  }

  const getStatusCode = (emp) => emp.employeeStatus?.status_code ?? emp.employee_status?.status_code ?? ''
  const getTypeCode   = (emp) => emp.employeeType?.type_code     ?? emp.employee_type?.type_code     ?? ''

  return (
    <>
      {modal && (
        <EmployeeModal
          emp={modal === 'add' ? null : modal}
          types={types} statuses={statuses}
          onClose={() => setModal(null)}
          onSaved={() => { setModal(null); fetchEmployees(search, page) }}
        />
      )}

      <div className="flex items-center justify-between mb-5 gap-4 flex-wrap">
        <div dir="rtl">
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">Ù‚Ø§Ø¦Ù…Ø© Ø§Ù„Ù…ÙˆØ¸ÙÙŠÙ†</h2>
          <p className="text-[12.5px] text-text-light">{meta.total > 0 ? `${meta.total} Ù…ÙˆØ¸Ù` : 'Ø¹Ø±Ø¶ Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ¸ÙÙŠÙ†'}</p>
        </div>
        <button
          onClick={() => setModal('add')}
          className="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 hover:shadow-[0_8px_24px_rgba(86,153,51,0.45)] transition-all duration-[220ms]"
          dir="rtl"
        >
          <FaUserPlus /> Ø¥Ø¶Ø§ÙØ© Ù…ÙˆØ¸Ù
        </button>
      </div>

      <div className="relative mb-5">
        <FaSearch className="absolute left-[15px] top-1/2 -translate-y-1/2 text-primary-light text-[14px] pointer-events-none" />
        <input
          className="w-full py-[13px] pr-4 pl-[42px] border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] text-text-dark outline-none transition-all focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)] placeholder:text-text-light"
          placeholder="Ø§Ø¨Ø­Ø« Ø¨Ø§Ù„Ø§Ø³Ù… Ø£Ùˆ Ø±Ù‚Ù… Ø§Ù„Ù…ÙˆØ¸Ù Ø£Ùˆ Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠâ€¦"
          value={search} onChange={e => setSearch(e.target.value)} dir="rtl"
        />
        {search && (
          <button onClick={() => setSearch('')}
            className="absolute right-3.5 top-1/2 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded-full text-[18px] text-text-light hover:bg-red-50 hover:text-red-500 transition-colors">Ã—</button>
        )}
      </div>

      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 mb-4 text-[13px] text-red-600" dir="rtl">
          <span>âš  {error}</span>
          <button onClick={() => fetchEmployees(search, page)} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px] hover:bg-red-50 transition-colors">Ø¥Ø¹Ø§Ø¯Ø© Ø§Ù„Ù…Ø­Ø§ÙˆÙ„Ø©</button>
        </div>
      )}

      <div className="bg-white rounded-[16px] border border-primary/12 overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]">
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-primary-light">
            <FaSpinner className="text-[28px] animate-spin" /><span className="text-[14px]">Ø¬Ø§Ø±ÙŠ Ø§Ù„ØªØ­Ù…ÙŠÙ„â€¦</span>
          </div>
        ) : employees.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-16">
            <FaUsers className="text-[48px] text-[#d1eab8] mb-2" />
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">Ù„Ø§ ÙŠÙˆØ¬Ø¯ Ù…ÙˆØ¸ÙÙˆÙ†</p>
            {search && <button onClick={() => setSearch('')} className="mt-2 px-5 py-2 bg-primary/8 border border-primary/20 rounded-[10px] text-primary-dark text-[13px] font-semibold hover:bg-primary/15 transition-colors">Ù…Ø³Ø­ Ø§Ù„Ø¨Ø­Ø«</button>}
          </div>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr>
                {['#', 'Ø±Ù‚Ù… Ø§Ù„Ù…ÙˆØ¸Ù', 'Ø§Ù„Ø§Ø³Ù… Ø§Ù„ÙƒØ§Ù…Ù„', 'Ø§Ù„Ø¨Ø±ÙŠØ¯ / Ø§Ù„Ù‡Ø§ØªÙ', 'Ø§Ù„Ù†ÙˆØ¹', 'ØªØ§Ø±ÙŠØ® Ø§Ù„ØªØ¹ÙŠÙŠÙ†', 'Ø§Ù„Ø­Ø§Ù„Ø©', 'Ø§Ù„Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª'].map(h => (
                  <th key={h} className="px-4 py-3.5 text-right text-[11.5px] font-bold text-white/90 bg-text-dark whitespace-nowrap" dir="rtl">{h}</th>
                ))}
              </tr>
            </thead>
            <AnimatePresence mode="wait">
              <motion.tbody key={`${search}-${page}`} initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.18 }}>
                {employees.map((e, idx) => {
                  const statusCode = getStatusCode(e)
                  const typeCode   = getTypeCode(e)
                  return (
                    <tr key={e.employee_id} className="border-b border-primary/7 last:border-b-0 hover:bg-primary/[0.025] transition-colors">
                      <td className="px-4 py-[13px] text-[12px] text-text-light font-semibold w-10">{(page - 1) * meta.per_page + idx + 1}</td>
                      <td className="px-4 py-[13px]">
                        <span className="inline-block px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono">{e.employee_number}</span>
                      </td>
                      <td className="px-4 py-[13px] font-semibold text-[13.5px] text-text-dark" dir="rtl">{e.first_name} {e.last_name}</td>
                      <td className="px-4 py-[13px] text-[12px] text-text-gray" dir="rtl">
                        <div>{e.email || 'â€”'}</div>
                        <div className="text-text-light">{e.phone_number || ''}</div>
                      </td>
                      <td className="px-4 py-[13px] text-[12px] text-text-gray" dir="rtl">{TYPE_AR[typeCode] ?? typeCode ?? 'â€”'}</td>
                      <td className="px-4 py-[13px] text-[12.5px] text-text-dark">{e.hire_date ? new Date(e.hire_date).toLocaleDateString('ar-SY') : 'â€”'}</td>
                      <td className="px-4 py-[13px]">
                        <span className={`text-[11px] font-bold px-2 py-0.5 rounded-full ${STATUS_COLOR[statusCode] ?? 'bg-gray-100 text-gray-600'}`} dir="rtl">
                          {STATUS_AR[statusCode] ?? statusCode ?? 'â€”'}
                        </span>
                      </td>
                      <td className="px-4 py-[13px]">
                        <div className="flex items-center gap-1.5">
                          <button onClick={() => navigate(`/hr/employees/${e.employee_id}`)}
                            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-blue-500 border-blue-500/20 bg-blue-50 hover:bg-blue-100 transition-colors" title="Ø¹Ø±Ø¶ Ø§Ù„Ù…Ù„Ù">
                            <FaEye />
                          </button>
                          <button onClick={() => setModal(e)}
                            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-amber-500 border-amber-500/20 bg-amber-50 hover:bg-amber-100 transition-colors" title="ØªØ¹Ø¯ÙŠÙ„">
                            <FaEdit />
                          </button>
                          <button onClick={() => handleDelete(e.employee_id, `${e.first_name} ${e.last_name}`)}
                            className="w-8 h-8 rounded-[8px] border flex items-center justify-center text-[13px] text-red-500 border-red-200 bg-red-50 hover:bg-red-100 transition-colors" title="Ø­Ø°Ù">
                            <FaTrash />
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </motion.tbody>
            </AnimatePresence>
          </table>
        )}
      </div>

      {!loading && meta.last_page > 1 && (
        <div className="flex items-center justify-center gap-4 mt-5">
          <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold disabled:opacity-40 disabled:cursor-not-allowed hover:enabled:bg-primary/8 transition-colors" dir="rtl">
            <FaChevronRight /><span>Ø§Ù„Ø³Ø§Ø¨Ù‚</span>
          </button>
          <span className="text-[13px] text-text-gray" dir="rtl">
            <span className="text-[17px] font-extrabold text-primary">{page}</span> Ù…Ù† <span className="font-semibold">{meta.last_page}</span>
          </span>
          <button disabled={page >= meta.last_page} onClick={() => setPage(p => p + 1)}
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold disabled:opacity-40 disabled:cursor-not-allowed hover:enabled:bg-primary/8 transition-colors" dir="rtl">
            <span>Ø§Ù„ØªØ§Ù„ÙŠ</span><FaChevronLeft />
          </button>
        </div>
      )}
    </>
  )
}

