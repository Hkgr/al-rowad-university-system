import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaSpinner, FaArrowRight, FaEdit, FaSave, FaTimes,
  FaEnvelope, FaPhone, FaCalendarAlt, FaBriefcase,
  FaChalkboardTeacher, FaPlus, FaTrash,
} from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const TYPE_AR   = { academic: 'Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠ', administrative: 'Ø¥Ø¯Ø§Ø±ÙŠ', technical: 'ØªÙ‚Ù†ÙŠ', service: 'Ø®Ø¯Ù…Ø§Øª', board_member: 'Ø¹Ø¶Ùˆ Ù…Ø¬Ù„Ø³' }
const STATUS_AR = { active: 'Ù†Ø´Ø·', inactive: 'ØºÙŠØ± Ù†Ø´Ø·', on_leave: 'ÙÙŠ Ø¥Ø¬Ø§Ø²Ø©', terminated: 'Ù…Ù†ØªÙ‡ÙŠ Ø§Ù„Ø®Ø¯Ù…Ø©' }
const STATUS_COLOR = { active: 'bg-green-100 text-green-700', inactive: 'bg-slate-100 text-slate-600', on_leave: 'bg-amber-100 text-amber-700', terminated: 'bg-red-100 text-red-600' }
const RANK_AR = {
  'Professor': 'Ø£Ø³ØªØ§Ø°', 'Associate Professor': 'Ø£Ø³ØªØ§Ø° Ù…Ø´Ø§Ø±Ùƒ',
  'Assistant Professor': 'Ø£Ø³ØªØ§Ø° Ù…Ø³Ø§Ø¹Ø¯', 'Lecturer': 'Ù…Ø­Ø§Ø¶Ø±', 'Instructor': 'Ù…Ø¯Ø±Ø³',
}

export default function EmployeeProfilePage() {
  const { id }   = useParams()
  const navigate = useNavigate()

  const [emp,      setEmp]      = useState(null)
  const [faculty,  setFaculty]  = useState(null)
  const [positions,setPositions]= useState([])
  const [loading,  setLoading]  = useState(true)
  const [error,    setError]    = useState('')

  // Faculty edit state
  const [editFac,    setEditFac]    = useState(false)
  const [facForm,    setFacForm]    = useState({})
  const [savingFac,  setSavingFac]  = useState(false)
  const [facErr,     setFacErr]     = useState('')

  // Add position state
  const [showAddPos, setShowAddPos] = useState(false)
  const [allPositions, setAllPositions] = useState([])
  const [posForm, setPosForm] = useState({ position_id: '', start_date: '', end_date: '', is_primary: false })
  const [savingPos, setSavingPos] = useState(false)
  const [posErr, setPosErr] = useState('')

  useEffect(() => {
    loadEmployee()
    fetch(`${API}/positions?per_page=50`, { headers: authHeaders() })
      .then(r => r.json()).then(j => setAllPositions(j.data?.data ?? j.data ?? []))
  }, [id]) // eslint-disable-line

  async function loadEmployee() {
    setLoading(true); setError('')
    try {
      const [empRes, posRes] = await Promise.all([
        fetch(`${API}/employees/${id}`, { headers: authHeaders() }).then(r => r.json()),
        fetch(`${API}/employee-positions?per_page=100`, { headers: authHeaders() }).then(r => r.json()),
      ])
      if (!empRes.success) { setError(empRes.message || 'Ù„Ù… ÙŠØªÙ… Ø§Ù„Ø¹Ø«ÙˆØ± Ø¹Ù„Ù‰ Ø§Ù„Ù…ÙˆØ¸Ù'); setLoading(false); return }

      const empData = empRes.data
      setEmp(empData)

      // Load faculty record if academic
      const typeCode = empData.employeeType?.type_code ?? empData.employee_type?.type_code ?? ''
      if (typeCode === 'academic') {
        const facRes = await fetch(`${API}/faculty-members?per_page=100`, { headers: authHeaders() }).then(r => r.json())
        const facList = facRes.data?.data ?? facRes.data ?? []
        const found = facList.find(f => f.employee_id === empData.employee_id)
        setFaculty(found ?? null)
        if (found) setFacForm({ academic_rank: found.academic_rank ?? '', specialization: found.specialization ?? '', office_location: found.office_location ?? '', is_active: found.is_active ? 1 : 0 })
      }

      // Filter positions for this employee
      const allPos = posRes.data?.data ?? posRes.data ?? []
      setPositions(allPos.filter(p => p.employee_id === Number(id)))
    } catch { setError('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally   { setLoading(false) }
  }

  async function saveFaculty() {
    setSavingFac(true); setFacErr('')
    try {
      const url    = faculty ? `${API}/faculty-members/${faculty.faculty_member_id}` : `${API}/faculty-members`
      const method = faculty ? 'PUT' : 'POST'
      const body   = faculty ? facForm : { ...facForm, employee_id: Number(id) }
      const res    = await fetch(url, { method, headers: { ...authHeaders(), 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      const json   = await res.json()
      if (json.success) { setEditFac(false); loadEmployee() }
      else setFacErr(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©')
    } catch { setFacErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally   { setSavingFac(false) }
  }

  async function addPosition() {
    setSavingPos(true); setPosErr('')
    try {
      const res  = await fetch(`${API}/employee-positions`, {
        method: 'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...posForm, employee_id: Number(id), position_id: Number(posForm.position_id), is_primary: posForm.is_primary ? 1 : 0, is_active: 1 }),
      })
      const json = await res.json()
      if (json.success) { setShowAddPos(false); setPosForm({ position_id: '', start_date: '', end_date: '', is_primary: false }); loadEmployee() }
      else setPosErr(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©')
    } catch { setPosErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally   { setSavingPos(false) }
  }

  async function deletePosition(posId) {
    if (!window.confirm('Ù‡Ù„ ØªØ±ÙŠØ¯ Ø­Ø°Ù Ù‡Ø°Ø§ Ø§Ù„Ù…Ù†ØµØ¨ØŸ')) return
    await fetch(`${API}/employee-positions/${posId}`, { method: 'DELETE', headers: authHeaders() })
    loadEmployee()
  }

  if (loading) return <div className="flex justify-center py-20 text-primary"><FaSpinner className="animate-spin text-[32px]" /></div>
  if (error)   return <div className="text-center py-20 text-red-500" dir="rtl">âš  {error}</div>
  if (!emp)    return null

  const statusCode = emp.employeeStatus?.status_code ?? emp.employee_status?.status_code ?? ''
  const typeCode   = emp.employeeType?.type_code     ?? emp.employee_type?.type_code     ?? ''

  return (
    <>
      <div className="flex items-center gap-3 mb-6" dir="rtl">
        <button onClick={() => navigate('/hr/employees')}
          className="w-9 h-9 flex items-center justify-center rounded-[10px] border border-primary/20 text-primary hover:bg-primary/8 transition-colors">
          <FaArrowRight />
        </button>
        <div>
          <h2 className="text-[20px] font-black text-text-dark">{emp.first_name} {emp.last_name}</h2>
          <p className="text-[12px] text-text-light font-mono">{emp.employee_number}</p>
        </div>
        <span className={`mr-auto text-[11px] font-bold px-2.5 py-1 rounded-full ${STATUS_COLOR[statusCode] ?? 'bg-gray-100 text-gray-600'}`} dir="rtl">
          {STATUS_AR[statusCode] ?? statusCode}
        </span>
      </div>

      <div className="grid grid-cols-3 max-[900px]:grid-cols-1 gap-5">
        {/* Left column: personal info */}
        <motion.div className="col-span-2 space-y-5" initial={{ opacity: 0, y: 14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.4 }}>

          {/* Personal info card */}
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <h3 className="text-[13.5px] font-extrabold text-text-dark mb-4 pb-3 border-b border-primary/10" dir="rtl">Ø§Ù„Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø§Ù„Ø´Ø®ØµÙŠØ©</h3>
            <div className="grid grid-cols-2 gap-4" dir="rtl">
              {[
                ['Ø§Ù„Ø§Ø³Ù… Ø§Ù„ÙƒØ§Ù…Ù„', `${emp.first_name} ${emp.last_name}`],
                ['Ø§Ø³Ù… Ø§Ù„Ø£Ø¨', emp.father_name || 'â€”'],
                ['Ø§Ø³Ù… Ø§Ù„Ø£Ù…', emp.mother_name || 'â€”'],
                ['Ù†ÙˆØ¹ Ø§Ù„Ù…ÙˆØ¸Ù', TYPE_AR[typeCode] ?? typeCode ?? 'â€”'],
              ].map(([label, val]) => (
                <div key={label}>
                  <p className="text-[10.5px] text-text-light font-bold mb-0.5">{label}</p>
                  <p className="text-[13.5px] font-semibold text-text-dark">{val}</p>
                </div>
              ))}
            </div>
            <div className="flex flex-col gap-2.5 mt-4 pt-4 border-t border-primary/8" dir="rtl">
              {emp.email && (
                <div className="flex items-center gap-2.5 text-[13px] text-text-gray">
                  <FaEnvelope className="text-primary text-[13px] flex-shrink-0" />{emp.email}
                </div>
              )}
              {emp.phone_number && (
                <div className="flex items-center gap-2.5 text-[13px] text-text-gray">
                  <FaPhone className="text-primary text-[13px] flex-shrink-0" />{emp.phone_number}
                </div>
              )}
              {emp.hire_date && (
                <div className="flex items-center gap-2.5 text-[13px] text-text-gray">
                  <FaCalendarAlt className="text-primary text-[13px] flex-shrink-0" />
                  ØªØ§Ø±ÙŠØ® Ø§Ù„ØªØ¹ÙŠÙŠÙ†: {new Date(emp.hire_date).toLocaleDateString('ar-SY')}
                </div>
              )}
            </div>
          </div>

          {/* Positions card */}
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="flex items-center justify-between mb-4 pb-3 border-b border-primary/10" dir="rtl">
              <h3 className="text-[13.5px] font-extrabold text-text-dark flex items-center gap-2"><FaBriefcase className="text-primary" /> Ø§Ù„Ù…Ù†Ø§ØµØ¨ Ø§Ù„ÙˆØ¸ÙŠÙÙŠØ©</h3>
              <button onClick={() => setShowAddPos(v => !v)}
                className="flex items-center gap-1.5 px-3 py-1.5 bg-primary/8 border border-primary/20 rounded-[8px] text-[12px] font-bold text-primary hover:bg-primary/15 transition-colors">
                <FaPlus className="text-[10px]" /> Ø¥Ø¶Ø§ÙØ© Ù…Ù†ØµØ¨
              </button>
            </div>

            {showAddPos && (
              <div className="mb-4 p-4 bg-primary/[0.03] border border-primary/15 rounded-[12px]" dir="rtl">
                <div className="grid grid-cols-2 gap-3 mb-3">
                  <div>
                    <label className="block text-[11px] font-bold text-text-dark mb-1">Ø§Ù„Ù…Ù†ØµØ¨ *</label>
                    <select value={posForm.position_id} onChange={e => setPosForm(f => ({ ...f, position_id: e.target.value }))}
                      className="w-full px-3 py-2 border border-primary/20 rounded-[8px] text-[12.5px] outline-none focus:border-primary" dir="rtl">
                      <option value="">Ø§Ø®ØªØ± Ø§Ù„Ù…Ù†ØµØ¨</option>
                      {allPositions.map(p => <option key={p.position_id} value={p.position_id}>{p.position_title}</option>)}
                    </select>
                  </div>
                  <div className="flex items-center gap-2 mt-5">
                    <input type="checkbox" id="isPrimary" checked={posForm.is_primary} onChange={e => setPosForm(f => ({ ...f, is_primary: e.target.checked }))} />
                    <label htmlFor="isPrimary" className="text-[12px] font-semibold text-text-dark">Ù…Ù†ØµØ¨ Ø±Ø¦ÙŠØ³ÙŠ</label>
                  </div>
                  <div>
                    <label className="block text-[11px] font-bold text-text-dark mb-1">ØªØ§Ø±ÙŠØ® Ø§Ù„Ø¨Ø¯Ø¡</label>
                    <input type="date" value={posForm.start_date} onChange={e => setPosForm(f => ({ ...f, start_date: e.target.value }))}
                      className="w-full px-3 py-2 border border-primary/20 rounded-[8px] text-[12.5px] outline-none focus:border-primary" />
                  </div>
                  <div>
                    <label className="block text-[11px] font-bold text-text-dark mb-1">ØªØ§Ø±ÙŠØ® Ø§Ù„Ø§Ù†ØªÙ‡Ø§Ø¡</label>
                    <input type="date" value={posForm.end_date} onChange={e => setPosForm(f => ({ ...f, end_date: e.target.value }))}
                      className="w-full px-3 py-2 border border-primary/20 rounded-[8px] text-[12.5px] outline-none focus:border-primary" />
                  </div>
                </div>
                {posErr && <p className="text-[11px] text-red-500 mb-2">{posErr}</p>}
                <div className="flex gap-2">
                  <button onClick={addPosition} disabled={savingPos}
                    className="flex items-center gap-1.5 px-4 py-1.5 bg-primary text-white rounded-[7px] text-[12px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
                    {savingPos ? <FaSpinner className="animate-spin text-[10px]" /> : <FaSave className="text-[10px]" />} Ø­ÙØ¸
                  </button>
                  <button onClick={() => setShowAddPos(false)} className="px-4 py-1.5 border border-primary/20 rounded-[7px] text-[12px] text-text-dark hover:bg-gray-50 transition-colors">Ø¥Ù„ØºØ§Ø¡</button>
                </div>
              </div>
            )}

            {positions.length === 0 ? (
              <p className="text-[12.5px] text-text-light text-center py-6" dir="rtl">Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…Ù†Ø§ØµØ¨ Ù…Ø³Ø¬Ù„Ø©</p>
            ) : (
              <div className="divide-y divide-primary/8">
                {positions.map(p => (
                  <div key={p.employee_position_id} className="flex items-center justify-between gap-3 py-3" dir="rtl">
                    <div>
                      <p className="text-[13px] font-semibold text-text-dark">{p.position?.position_title ?? 'â€”'}</p>
                      <p className="text-[11px] text-text-light">
                        {p.start_date ? new Date(p.start_date).toLocaleDateString('ar-SY') : ''}
                        {p.end_date ? ` â€” ${new Date(p.end_date).toLocaleDateString('ar-SY')}` : ''}
                        {p.is_primary && <span className="mr-2 text-primary font-bold">â— Ø±Ø¦ÙŠØ³ÙŠ</span>}
                      </p>
                    </div>
                    <button onClick={() => deletePosition(p.employee_position_id)}
                      className="w-7 h-7 flex items-center justify-center rounded-[7px] text-red-400 border border-red-200 bg-red-50 hover:bg-red-100 transition-colors text-[11px]">
                      <FaTrash />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Faculty card (academic employees only) */}
          {typeCode === 'academic' && (
            <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
              <div className="flex items-center justify-between mb-4 pb-3 border-b border-primary/10" dir="rtl">
                <h3 className="text-[13.5px] font-extrabold text-text-dark flex items-center gap-2"><FaChalkboardTeacher className="text-primary" /> Ø¨ÙŠØ§Ù†Ø§Øª Ù‡ÙŠØ¦Ø© Ø§Ù„ØªØ¯Ø±ÙŠØ³</h3>
                <button onClick={() => { setEditFac(v => !v); setFacErr('') }}
                  className="flex items-center gap-1.5 px-3 py-1.5 bg-primary/8 border border-primary/20 rounded-[8px] text-[12px] font-bold text-primary hover:bg-primary/15 transition-colors">
                  {editFac ? <><FaTimes className="text-[10px]" /> Ø¥Ù„ØºØ§Ø¡</> : <><FaEdit className="text-[10px]" /> ØªØ¹Ø¯ÙŠÙ„</>}
                </button>
              </div>

              {editFac ? (
                <div className="grid grid-cols-2 gap-4" dir="rtl">
                  {[
                    ['Ø§Ù„Ø±ØªØ¨Ø© Ø§Ù„Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠØ©', 'academic_rank'],
                    ['Ø§Ù„ØªØ®ØµØµ', 'specialization'],
                    ['Ù…ÙˆÙ‚Ø¹ Ø§Ù„Ù…ÙƒØªØ¨', 'office_location'],
                  ].map(([label, k]) => (
                    <div key={k} className={k === 'specialization' ? 'col-span-2' : ''}>
                      <label className="block text-[11px] font-bold text-text-dark mb-1">{label}</label>
                      <input value={facForm[k] ?? ''} onChange={e => setFacForm(f => ({ ...f, [k]: e.target.value }))}
                        className="w-full px-3 py-2 border border-primary/20 rounded-[8px] text-[13px] outline-none focus:border-primary" dir="rtl" />
                    </div>
                  ))}
                  <div className="flex items-center gap-2">
                    <input type="checkbox" id="facActive" checked={!!facForm.is_active} onChange={e => setFacForm(f => ({ ...f, is_active: e.target.checked ? 1 : 0 }))} />
                    <label htmlFor="facActive" className="text-[12px] font-semibold text-text-dark">Ù†Ø´Ø·</label>
                  </div>
                  {facErr && <p className="col-span-2 text-[11px] text-red-500">{facErr}</p>}
                  <div className="col-span-2 flex gap-2">
                    <button onClick={saveFaculty} disabled={savingFac}
                      className="flex items-center gap-1.5 px-4 py-1.5 bg-primary text-white rounded-[7px] text-[12px] font-bold hover:bg-primary-dark disabled:opacity-50 transition-colors">
                      {savingFac ? <FaSpinner className="animate-spin text-[10px]" /> : <FaSave className="text-[10px]" />}
                      {faculty ? 'Ø­ÙØ¸ Ø§Ù„ØªØ¹Ø¯ÙŠÙ„Ø§Øª' : 'Ø¥Ø¶Ø§ÙØ© ÙƒØ¹Ø¶Ùˆ ØªØ¯Ø±ÙŠØ³'}
                    </button>
                  </div>
                </div>
              ) : faculty ? (
                <div className="grid grid-cols-2 gap-4" dir="rtl">
                  {[
                    ['Ø§Ù„Ø±ØªØ¨Ø© Ø§Ù„Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠØ©', RANK_AR[faculty.academic_rank] ?? faculty.academic_rank ?? 'â€”'],
                    ['Ø§Ù„ØªØ®ØµØµ', faculty.specialization || 'â€”'],
                    ['Ù…ÙˆÙ‚Ø¹ Ø§Ù„Ù…ÙƒØªØ¨', faculty.office_location || 'â€”'],
                    ['Ø§Ù„Ø­Ø§Ù„Ø©', faculty.is_active ? 'Ù†Ø´Ø·' : 'ØºÙŠØ± Ù†Ø´Ø·'],
                  ].map(([label, val]) => (
                    <div key={label}>
                      <p className="text-[10.5px] text-text-light font-bold mb-0.5">{label}</p>
                      <p className="text-[13px] font-semibold text-text-dark">{val}</p>
                    </div>
                  ))}
                </div>
              ) : (
                <div className="text-center py-6" dir="rtl">
                  <FaChalkboardTeacher className="text-[36px] text-primary/15 mx-auto mb-2" />
                  <p className="text-[12.5px] text-text-light">Ù„Ù… ÙŠØªÙ… ØªØ³Ø¬ÙŠÙ„ Ù‡Ø°Ø§ Ø§Ù„Ù…ÙˆØ¸Ù ÙƒØ¹Ø¶Ùˆ ØªØ¯Ø±ÙŠØ³ Ø¨Ø¹Ø¯</p>
                  <button onClick={() => setEditFac(true)}
                    className="mt-3 px-4 py-1.5 bg-primary/10 border border-primary/20 rounded-[8px] text-[12px] font-bold text-primary hover:bg-primary/20 transition-colors">
                    Ø¥Ø¶Ø§ÙØ© ÙƒØ¹Ø¶Ùˆ ØªØ¯Ø±ÙŠØ³
                  </button>
                </div>
              )}
            </div>
          )}
        </motion.div>

        {/* Right column: quick info */}
        <motion.div className="space-y-4" initial={{ opacity: 0, x: 14 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.4, delay: 0.1 }}>
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center text-[28px] font-black text-primary mx-auto mb-3">
              {emp.first_name?.[0]?.toUpperCase()}
            </div>
            <div className="text-center" dir="rtl">
              <p className="font-extrabold text-[15px] text-text-dark">{emp.first_name} {emp.last_name}</p>
              <p className="text-[11px] font-mono text-text-light mt-0.5">{emp.employee_number}</p>
              <span className={`inline-block mt-2 text-[11px] font-bold px-2.5 py-0.5 rounded-full ${STATUS_COLOR[statusCode] ?? 'bg-gray-100 text-gray-600'}`}>
                {STATUS_AR[statusCode] ?? statusCode}
              </span>
            </div>
          </div>

          <div className="bg-white border border-primary/12 rounded-[16px] p-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
            <p className="text-[11.5px] font-extrabold text-text-dark mb-3">Ù…Ø¹Ù„ÙˆÙ…Ø§Øª Ø³Ø±ÙŠØ¹Ø©</p>
            <div className="space-y-2.5">
              {[
                ['Ø§Ù„Ù†ÙˆØ¹', TYPE_AR[typeCode] ?? typeCode ?? 'â€”'],
                ['Ø§Ù„Ø­Ø§Ù„Ø©', STATUS_AR[statusCode] ?? statusCode ?? 'â€”'],
                ['Ø§Ù„Ù…Ù†Ø§ØµØ¨', `${positions.length} Ù…Ù†ØµØ¨`],
                ['Ø¹Ø¶Ùˆ ØªØ¯Ø±ÙŠØ³', typeCode === 'academic' ? (faculty ? 'Ù†Ø¹Ù…' : 'Ù„Ø§') : 'ØºÙŠØ± Ø£ÙƒØ§Ø¯ÙŠÙ…ÙŠ'],
              ].map(([label, val]) => (
                <div key={label} className="flex items-center justify-between gap-2">
                  <span className="text-[11.5px] text-text-light">{label}</span>
                  <span className="text-[12px] font-semibold text-text-dark">{val}</span>
                </div>
              ))}
            </div>
          </div>
        </motion.div>
      </div>
    </>
  )
}

