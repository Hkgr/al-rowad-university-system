import { useState, useEffect, useMemo } from 'react'
import { FaSpinner, FaPlus, FaEdit, FaTrash, FaCheck, FaTimes, FaBook } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

const EMPTY_FORM = {
  course_code: '', course_name: '', credit_hours: '', theoretical_hours: '', practical_hours: '', description: '', is_active: true,
}

function CourseForm({ initial, onSave, onCancel, saving }) {
  const [form, setForm] = useState(initial ?? EMPTY_FORM)
  const set = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const isEdit = !!initial

  return (
    <div className="bg-white border border-primary/20 rounded-[16px] p-5 mb-5 shadow-[0_2px_12px_rgba(26,46,16,0.08)]">
      <h3 className="text-[14px] font-extrabold text-text-dark mb-4" dir="rtl">
        {isEdit ? 'ØªØ¹Ø¯ÙŠÙ„ Ø§Ù„Ù…Ø§Ø¯Ø©' : 'Ø¥Ø¶Ø§ÙØ© Ù…Ø§Ø¯Ø© Ø¬Ø¯ÙŠØ¯Ø©'}
      </h3>
      <div className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-4 mb-4" dir="rtl">
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">Ø±Ù…Ø² Ø§Ù„Ù…Ø§Ø¯Ø© <span className="text-red-500">*</span></label>
          <input
            type="text" placeholder="e.g. STAT101"
            value={form.course_code}
            onChange={e => set('course_code', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø© <span className="text-red-500">*</span></label>
          <input
            type="text" placeholder="Ø§Ø³Ù… Ø§Ù„Ù…Ø§Ø¯Ø©"
            value={form.course_name}
            onChange={e => set('course_name', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="rtl"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">Ø§Ù„Ø³Ø§Ø¹Ø§Øª Ø§Ù„Ù…Ø¹ØªÙ…Ø¯Ø© <span className="text-red-500">*</span></label>
          <input
            type="number" min="1" max="12" placeholder="3"
            value={form.credit_hours}
            onChange={e => set('credit_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">Ø§Ù„Ø³Ø§Ø¹Ø§Øª Ø§Ù„Ù†Ø¸Ø±ÙŠØ©</label>
          <input
            type="number" min="0" max="12" placeholder="2"
            value={form.theoretical_hours}
            onChange={e => set('theoretical_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">Ø§Ù„Ø³Ø§Ø¹Ø§Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©</label>
          <input
            type="number" min="0" max="12" placeholder="1"
            value={form.practical_hours}
            onChange={e => set('practical_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5 justify-end">
          <label className="text-[11.5px] font-bold text-text-dark">Ø§Ù„Ø­Ø§Ù„Ø©</label>
          <div className="flex gap-4" dir="rtl">
            <label className="flex items-center gap-2 cursor-pointer text-[13px]">
              <input type="radio" name="is_active" checked={form.is_active === true} onChange={() => set('is_active', true)} />
              <span className="text-green-700 font-semibold">ÙØ¹Ù‘Ø§Ù„</span>
            </label>
            <label className="flex items-center gap-2 cursor-pointer text-[13px]">
              <input type="radio" name="is_active" checked={form.is_active === false} onChange={() => set('is_active', false)} />
              <span className="text-text-light font-semibold">ØºÙŠØ± ÙØ¹Ù‘Ø§Ù„</span>
            </label>
          </div>
        </div>
      </div>
      <div className="flex flex-col gap-1.5 mb-5" dir="rtl">
        <label className="text-[11.5px] font-bold text-text-dark">Ø§Ù„ÙˆØµÙ</label>
        <textarea
          rows={2} placeholder="ÙˆØµÙ Ø§Ø®ØªÙŠØ§Ø±ÙŠ Ù„Ù„Ù…Ø§Ø¯Ø©"
          value={form.description || ''}
          onChange={e => set('description', e.target.value)}
          className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary resize-none"
          dir="rtl"
        />
      </div>
      <div className="flex gap-3" dir="rtl">
        <button
          onClick={() => onSave(form)}
          disabled={saving || !form.course_code.trim() || !form.course_name.trim() || !form.credit_hours}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40 hover:enabled:bg-primary-dark transition-colors"
        >
          {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaCheck className="text-[11px]" />}
          {isEdit ? 'Ø­ÙØ¸ Ø§Ù„ØªØ¹Ø¯ÙŠÙ„Ø§Øª' : 'Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ù…Ø§Ø¯Ø©'}
        </button>
        <button
          onClick={onCancel}
          className="flex items-center gap-2 px-5 py-2.5 border border-primary/20 text-text-gray rounded-[10px] text-[13px] font-bold hover:bg-gray-50 transition-colors"
        >
          <FaTimes className="text-[11px]" /> Ø¥Ù„ØºØ§Ø¡
        </button>
      </div>
    </div>
  )
}

export default function CoursesPage() {
  const [courses,     setCourses]     = useState([])
  const [colleges,    setColleges]    = useState([])
  const [departments, setDepartments] = useState([])
  const [assignments, setAssignments] = useState([])   // course_departments rows
  const [loading,     setLoading]     = useState(true)
  const [activeTab,   setActiveTab]   = useState('all') // 'all' | college_id | 'shared'
  const [mode,        setMode]        = useState(null)  // null | 'add' | course object
  const [saving,      setSaving]      = useState(false)
  const [deleting,    setDeleting]    = useState({})
  const [err,         setErr]         = useState('')
  const [success,     setSuccess]     = useState('')

  function loadAll() {
    setLoading(true)
    Promise.all([
      fetch(`${API}/courses?per_page=500`,           { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/colleges?per_page=50`,           { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/departments?per_page=200`,       { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/course-departments?per_page=500`,{ headers: authHeaders() }).then(r => r.json()),
    ]).then(([co, cl, dp, cd]) => {
      setCourses(co.success     ? (co.data?.data ?? co.data ?? []) : [])
      setColleges(cl.success    ? (cl.data?.data ?? cl.data ?? []) : [])
      setDepartments(dp.success ? (dp.data?.data ?? dp.data ?? []) : [])
      setAssignments(cd.success ? (cd.data?.data ?? cd.data ?? []) : [])
    }).finally(() => setLoading(false))
  }

  useEffect(() => { loadAll() }, [])

  // Map: course_id â†’ Set of college_ids
  const courseCollegeMap = useMemo(() => {
    const deptToCollege = {}
    departments.forEach(d => { deptToCollege[d.department_id] = d.college_id })
    const map = {}
    assignments.forEach(a => {
      const colId = deptToCollege[a.department_id]
      if (!map[a.course_id]) map[a.course_id] = new Set()
      if (colId) map[a.course_id].add(String(colId))
    })
    return map
  }, [assignments, departments])

  const filteredCourses = useMemo(() => {
    if (activeTab === 'all')    return courses
    if (activeTab === 'shared') return courses.filter(c => !courseCollegeMap[c.course_id] || courseCollegeMap[c.course_id].size === 0)
    return courses.filter(c => courseCollegeMap[c.course_id]?.has(String(activeTab)))
  }, [courses, courseCollegeMap, activeTab])

  // Count per tab
  const sharedCount = courses.filter(c => !courseCollegeMap[c.course_id] || courseCollegeMap[c.course_id].size === 0).length

  function flash(msg) {
    setSuccess(msg)
    setTimeout(() => setSuccess(''), 3000)
  }

  async function handleSave(form) {
    setSaving(true); setErr('')
    const isEdit = mode !== 'add'
    const url    = isEdit ? `${API}/courses/${mode.course_id}` : `${API}/courses`
    const method = isEdit ? 'PUT' : 'POST'
    try {
      const res  = await fetch(url, {
        method,
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({
          course_code:       form.course_code.trim(),
          course_name:       form.course_name.trim(),
          credit_hours:      parseInt(form.credit_hours),
          theoretical_hours: form.theoretical_hours !== '' ? parseInt(form.theoretical_hours) : 0,
          practical_hours:   form.practical_hours   !== '' ? parseInt(form.practical_hours)   : 0,
          description:       form.description || null,
          is_active:         form.is_active,
        }),
      })
      const json = await res.json()
      if (json.success) {
        setMode(null)
        loadAll()
        flash(isEdit ? 'ØªÙ… ØªØ¹Ø¯ÙŠÙ„ Ø§Ù„Ù…Ø§Ø¯Ø© Ø¨Ù†Ø¬Ø§Ø­' : 'ØªÙ…Øª Ø¥Ø¶Ø§ÙØ© Ø§Ù„Ù…Ø§Ø¯Ø© Ø¨Ù†Ø¬Ø§Ø­')
      } else {
        setErr(json.message || (json.errors ? Object.values(json.errors).flat().join(' | ') : 'ÙØ´Ù„Øª Ø§Ù„Ø¹Ù…Ù„ÙŠØ©'))
      }
    } catch { setErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally { setSaving(false) }
  }

  async function handleDelete(course) {
    if (!window.confirm(`Ø­Ø°Ù Ø§Ù„Ù…Ø§Ø¯Ø© "${course.course_name}"ØŸ`)) return
    setDeleting(p => ({ ...p, [course.course_id]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/courses/${course.course_id}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) { setCourses(p => p.filter(c => c.course_id !== course.course_id)); flash('ØªÙ… Ø­Ø°Ù Ø§Ù„Ù…Ø§Ø¯Ø©') }
      else setErr(json.message || 'ÙØ´Ù„ Ø§Ù„Ø­Ø°Ù')
    } catch { setErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally { setDeleting(p => ({ ...p, [course.course_id]: false })) }
  }

  return (
    <>
      <div className="flex items-start justify-between mb-5" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">Ø¥Ø¯Ø§Ø±Ø© Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ø¯Ø±Ø§Ø³ÙŠØ©</h2>
          <p className="text-[12.5px] text-text-light">Courses Management</p>
        </div>
        {mode === null && (
          <button
            onClick={() => { setMode('add'); setErr(''); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
            className="flex items-center gap-2 px-4 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
          >
            <FaPlus className="text-[11px]" /> Ø¥Ø¶Ø§ÙØ© Ù…Ø§Ø¯Ø©
          </button>
        )}
      </div>

      {err && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]" dir="rtl">âš  {err}</p>
      )}
      {success && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px]" dir="rtl">âœ“ {success}</p>
      )}

      {/* Add / Edit form */}
      {mode === 'add' && (
        <CourseForm onSave={handleSave} onCancel={() => { setMode(null); setErr('') }} saving={saving} />
      )}
      {mode !== null && mode !== 'add' && (
        <CourseForm
          initial={mode}
          onSave={handleSave}
          onCancel={() => { setMode(null); setErr('') }}
          saving={saving}
        />
      )}

      {loading ? (
        <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
      ) : (
        <>
          {/* College filter tabs */}
          <div className="flex gap-1.5 flex-wrap mb-4 p-1.5 bg-gray-100 rounded-[12px] w-fit" dir="rtl">
            <TabBtn label="Ø§Ù„ÙƒÙ„" count={courses.length}   active={activeTab === 'all'}    onClick={() => setActiveTab('all')} />
            {colleges.map(col => {
              const cnt = courses.filter(c => courseCollegeMap[c.course_id]?.has(String(col.college_id))).length
              return (
                <TabBtn
                  key={col.college_id}
                  label={col.college_name}
                  count={cnt}
                  active={activeTab === String(col.college_id)}
                  onClick={() => setActiveTab(String(col.college_id))}
                />
              )
            })}
            <TabBtn label="Ù…Ø´ØªØ±ÙƒØ©" count={sharedCount} active={activeTab === 'shared'} onClick={() => setActiveTab('shared')} />
          </div>

          {/* Shared courses note */}
          {activeTab === 'shared' && sharedCount > 0 && (
            <div className="mb-4 px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-[10px] text-[12px] text-amber-800" dir="rtl">
              Ù‡Ø°Ù‡ Ø§Ù„Ù…ÙˆØ§Ø¯ ØºÙŠØ± Ù…Ø±ØªØ¨Ø·Ø© Ø¨Ø£ÙŠ Ù‚Ø³Ù… â€” ÙŠÙ…ÙƒÙ† Ø§Ø¹ØªØ¨Ø§Ø±Ù‡Ø§ Ù…ÙˆØ§Ø¯ Ù…Ø´ØªØ±ÙƒØ© Ù„Ø¬Ù…ÙŠØ¹ Ø§Ù„ÙƒÙ„ÙŠØ§Øª.
              ÙŠÙ…ÙƒÙ†Ùƒ Ø±Ø¨Ø·Ù‡Ø§ Ø¨Ø§Ù„Ø£Ù‚Ø³Ø§Ù… Ù…Ù† ØµÙØ­Ø© <strong>Ù…ÙˆØ§Ø¯ Ø§Ù„Ø£Ù‚Ø³Ø§Ù…</strong>.
            </div>
          )}

          {filteredCourses.length === 0 ? (
            <div className="flex flex-col items-center py-20 gap-3">
              <FaBook className="text-[48px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">
                {activeTab === 'all' ? 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ Ø¯Ø±Ø§Ø³ÙŠØ©.' : 'Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ ÙÙŠ Ù‡Ø°Ø§ Ø§Ù„ØªØµÙ†ÙŠÙ.'}
              </p>
            </div>
          ) : (
            <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
              <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                <span className="text-[13px] font-extrabold text-text-dark">{filteredCourses.length} Ù…Ø§Ø¯Ø©</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                  <thead>
                    <tr className="bg-[#fafaf8]">
                      <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">Ø§Ù„Ù…Ø§Ø¯Ø©</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">Ù…Ø¹ØªÙ…Ø¯Ø©</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">Ù†Ø¸Ø±ÙŠ</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">Ø¹Ù…Ù„ÙŠ</th>
                      <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">Ø§Ù„ÙƒÙ„ÙŠØ§Øª</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">Ø§Ù„Ø­Ø§Ù„Ø©</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">Ø¥Ø¬Ø±Ø§Ø¡Ø§Øª</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCourses.map(c => {
                      const collegeIds   = courseCollegeMap[c.course_id]
                      const courseColleges = collegeIds
                        ? colleges.filter(col => collegeIds.has(String(col.college_id)))
                        : []
                      return (
                        <tr key={c.course_id} className="border-t border-primary/6 hover:bg-primary/[0.02] transition-colors">
                          <td className="px-4 py-3" dir="rtl">
                            <div className="font-semibold text-[13px] text-text-dark">{c.course_name}</div>
                            <div className="text-[11px] text-text-light font-mono mt-0.5">{c.course_code}</div>
                          </td>
                          <td className="px-3 py-3 text-center font-bold text-text-dark">{c.credit_hours}</td>
                          <td className="px-3 py-3 text-center text-text-dark">{c.theoretical_hours ?? 'â€”'}</td>
                          <td className="px-3 py-3 text-center text-text-dark">{c.practical_hours ?? 'â€”'}</td>
                          <td className="px-3 py-3" dir="rtl">
                            {courseColleges.length === 0 ? (
                              <span className="inline-block px-2 py-0.5 bg-amber-100 text-amber-700 text-[10px] font-bold rounded-full">Ù…Ø´ØªØ±ÙƒØ©</span>
                            ) : (
                              <div className="flex flex-wrap gap-1">
                                {courseColleges.map(col => (
                                  <span key={col.college_id} className="inline-block px-2 py-0.5 bg-primary/10 text-primary-dark text-[10px] font-bold rounded-full whitespace-nowrap">
                                    {col.college_name}
                                  </span>
                                ))}
                              </div>
                            )}
                          </td>
                          <td className="px-3 py-3 text-center">
                            {c.is_active
                              ? <span className="inline-block px-2 py-0.5 bg-green-100 text-green-700 text-[10.5px] font-bold rounded-full">ÙØ¹Ù‘Ø§Ù„</span>
                              : <span className="inline-block px-2 py-0.5 bg-gray-100 text-text-light text-[10.5px] font-bold rounded-full">ØºÙŠØ± ÙØ¹Ù‘Ø§Ù„</span>
                            }
                          </td>
                          <td className="px-3 py-3 text-center">
                            <div className="flex items-center justify-center gap-2">
                              <button
                                onClick={() => { setMode(c); setErr(''); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
                                className="flex items-center gap-1 px-2.5 py-1.5 border border-primary/25 text-primary rounded-[7px] text-[11px] font-bold hover:bg-primary/[0.05] transition-colors"
                              >
                                <FaEdit className="text-[10px]" /> ØªØ¹Ø¯ÙŠÙ„
                              </button>
                              <button
                                onClick={() => handleDelete(c)}
                                disabled={!!deleting[c.course_id]}
                                className="flex items-center gap-1 px-2.5 py-1.5 border border-red-300 text-red-600 rounded-[7px] text-[11px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors"
                              >
                                {deleting[c.course_id] ? <FaSpinner className="animate-spin text-[10px]" /> : <FaTrash className="text-[10px]" />}
                                Ø­Ø°Ù
                              </button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </>
      )}
    </>
  )
}

function TabBtn({ label, count, active, onClick }) {
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-[9px] text-[12.5px] font-bold transition-all whitespace-nowrap ${
        active ? 'bg-white text-primary shadow-sm' : 'text-text-gray hover:text-text-dark'
      }`}
    >
      {label}
      <span className={`text-[10.5px] px-1.5 py-0.5 rounded-full font-bold ${active ? 'bg-primary/10 text-primary' : 'bg-gray-200 text-text-light'}`}>
        {count}
      </span>
    </button>
  )
}

