import { useState, useEffect } from 'react'
import { FaSpinner, FaPlus, FaTimes, FaStar, FaRegStar, FaBook } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

export default function CourseDepartmentPage() {
  const [colleges,     setColleges]     = useState([])
  const [departments,  setDepartments]  = useState([])
  const [allCourses,   setAllCourses]   = useState([])
  const [assignments,  setAssignments]  = useState([])
  const [collegeId,    setCollegeId]    = useState('')
  const [deptId,       setDeptId]       = useState('')
  const [loadingInit,  setLoadingInit]  = useState(true)
  const [loadingDepts, setLoadingDepts] = useState(false)
  const [saving,       setSaving]       = useState({})
  const [removing,     setRemoving]     = useState({})
  const [err,          setErr]          = useState('')

  useEffect(() => {
    Promise.all([
      fetch(`${API}/colleges?per_page=50`,           { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/courses?per_page=500`,            { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/course-departments?per_page=500`, { headers: authHeaders() }).then(r => r.json()),
    ]).then(([c, co, cd]) => {
      setColleges(c.success    ? (c.data?.data   ?? c.data  ?? []) : [])
      setAllCourses(co.success ? (co.data?.data  ?? co.data ?? []) : [])
      setAssignments(cd.success? (cd.data?.data  ?? cd.data ?? []) : [])
    }).finally(() => setLoadingInit(false))
  }, [])

  function handleCollegeChange(cId) {
    setCollegeId(cId); setDeptId(''); setDepartments([]); setErr('')
    if (!cId) return
    setLoadingDepts(true)
    fetch(`${API}/colleges/${cId}/departments`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => setDepartments(json.success ? (json.data?.data ?? json.data ?? []) : []))
      .finally(() => setLoadingDepts(false))
  }

  async function reloadAssignments() {
    const json = await fetch(`${API}/course-departments?per_page=500`, { headers: authHeaders() }).then(r => r.json())
    if (json.success) setAssignments(json.data?.data ?? json.data ?? [])
  }

  // Assignments for the selected department
  const assigned        = assignments.filter(a => String(a.department_id) === String(deptId))
  const assignedCourseIds = new Set(assigned.map(a => a.course_id))

  // Courses not yet linked to this department
  const unassigned = allCourses.filter(c => !assignedCourseIds.has(c.course_id))

  async function handleAdd(courseId) {
    setSaving(p => ({ ...p, [courseId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/course-departments`, {
        method:  'POST',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body:    JSON.stringify({ course_id: courseId, department_id: parseInt(deptId), is_primary: true }),
      })
      const json = await res.json()
      if (json.success) await reloadAssignments()
      else setErr(json.message || 'ÙØ´Ù„Øª Ø§Ù„Ø¥Ø¶Ø§ÙØ©')
    } catch { setErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally { setSaving(p => ({ ...p, [courseId]: false })) }
  }

  async function handleRemove(assignmentId) {
    setRemoving(p => ({ ...p, [assignmentId]: true })); setErr('')
    try {
      const res  = await fetch(`${API}/course-departments/${assignmentId}`, {
        method:  'DELETE',
        headers: authHeaders(),
      })
      const json = await res.json()
      if (json.success) setAssignments(p => p.filter(a => a.course_department_id !== assignmentId))
      else setErr(json.message || 'ÙØ´Ù„ Ø§Ù„Ø­Ø°Ù')
    } catch { setErr('ØªØ¹Ø°Ù‘Ø± Ø§Ù„Ø§ØªØµØ§Ù„ Ø¨Ø§Ù„Ø®Ø§Ø¯Ù…') }
    finally { setRemoving(p => ({ ...p, [assignmentId]: false })) }
  }

  if (loadingInit) return (
    <div className="flex justify-center py-16 text-primary">
      <FaSpinner className="animate-spin text-[28px]" />
    </div>
  )

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">Ù…ÙˆØ§Ø¯ Ø§Ù„Ø£Ù‚Ø³Ø§Ù…</h2>
        <p className="text-[12.5px] text-text-light">Course â€” Department Assignments</p>
      </div>

      {/* College + Department selectors */}
      <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-2 max-[600px]:grid-cols-1 gap-4" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">Ø§Ù„ÙƒÙ„ÙŠØ©</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
              value={collegeId}
              onChange={e => handleCollegeChange(e.target.value)}
              dir="rtl"
            >
              <option value="">Ø§Ø®ØªØ± Ø§Ù„ÙƒÙ„ÙŠØ©</option>
              {colleges.map(c => <option key={c.college_id} value={c.college_id}>{c.college_name}</option>)}
            </select>
          </div>

          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">
              Ø§Ù„Ù‚Ø³Ù…
              {loadingDepts && <FaSpinner className="inline mr-2 animate-spin text-[11px] text-primary" />}
            </label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              value={deptId}
              onChange={e => { setDeptId(e.target.value); setErr('') }}
              disabled={!collegeId || loadingDepts}
              dir="rtl"
            >
              <option value="">Ø§Ø®ØªØ± Ø§Ù„Ù‚Ø³Ù…</option>
              {departments.map(d => <option key={d.department_id} value={d.department_id}>{d.department_name}</option>)}
            </select>
          </div>
        </div>
      </div>

      {err && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]" dir="rtl">
          âš  {err}
        </p>
      )}

      {deptId && (
        <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-5">

          {/* Left: assigned courses */}
          <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
              <span className="text-[13px] font-extrabold text-text-dark">Ø§Ù„Ù…ÙˆØ§Ø¯ Ø§Ù„Ù…Ø¶Ø§ÙØ©</span>
              <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{assigned.length}</span>
            </div>

            {assigned.length === 0 ? (
              <div className="flex flex-col items-center py-14 gap-3">
                <FaBook className="text-[36px] text-primary/15" />
                <p className="text-[12px] text-text-light" dir="rtl">Ù„Ø§ ØªÙˆØ¬Ø¯ Ù…ÙˆØ§Ø¯ Ù…Ø¶Ø§ÙØ© Ù„Ù‡Ø°Ø§ Ø§Ù„Ù‚Ø³Ù…</p>
              </div>
            ) : (
              <div className="divide-y divide-primary/6">
                {assigned.map(a => {
                  const course = allCourses.find(c => c.course_id === a.course_id)
                  return (
                    <div key={a.course_department_id} className="flex items-center justify-between gap-3 px-5 py-3.5" dir="rtl">
                      <div className="flex-1 min-w-0">
                        <div className="font-semibold text-[13px] text-text-dark truncate">
                          {course?.course_name || `Ù…Ø§Ø¯Ø© #${a.course_id}`}
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className="text-[11px] text-text-light font-mono">{course?.course_code}</span>
                          {a.is_primary
                            ? <span className="inline-flex items-center gap-1 text-[10px] text-amber-600 font-bold"><FaStar className="text-[9px]" /> Ø±Ø¦ÙŠØ³ÙŠ</span>
                            : <span className="inline-flex items-center gap-1 text-[10px] text-text-light"><FaRegStar className="text-[9px]" /> Ø«Ø§Ù†ÙˆÙŠ</span>
                          }
                        </div>
                      </div>
                      <button
                        onClick={() => handleRemove(a.course_department_id)}
                        disabled={!!removing[a.course_department_id]}
                        className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[7px] text-[11.5px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors flex-shrink-0"
                      >
                        {removing[a.course_department_id]
                          ? <FaSpinner className="animate-spin text-[10px]" />
                          : <FaTimes className="text-[10px]" />}
                        Ø­Ø°Ù
                      </button>
                    </div>
                  )
                })}
              </div>
            )}
          </div>

          {/* Right: available courses to add */}
          <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
              <span className="text-[13px] font-extrabold text-text-dark">Ù…ÙˆØ§Ø¯ Ù…ØªØ§Ø­Ø© Ù„Ù„Ø¥Ø¶Ø§ÙØ©</span>
              <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{unassigned.length}</span>
            </div>

            {unassigned.length === 0 ? (
              <p className="text-center text-[12px] text-text-light py-14" dir="rtl">Ø¬Ù…ÙŠØ¹ Ø§Ù„Ù…ÙˆØ§Ø¯ Ù…Ø¶Ø§ÙØ© Ù„Ù‡Ø°Ø§ Ø§Ù„Ù‚Ø³Ù…</p>
            ) : (
              <div className="divide-y divide-primary/6 max-h-[520px] overflow-y-auto">
                {unassigned.map(c => (
                  <div key={c.course_id} className="flex items-center justify-between gap-3 px-5 py-3.5" dir="rtl">
                    <div className="flex-1 min-w-0">
                      <div className="font-semibold text-[13px] text-text-dark truncate">{c.course_name}</div>
                      <div className="text-[11px] text-text-light font-mono">{c.course_code}</div>
                    </div>
                    <button
                      onClick={() => handleAdd(c.course_id)}
                      disabled={!!saving[c.course_id]}
                      className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[7px] text-[11.5px] font-bold hover:bg-primary-dark disabled:opacity-40 transition-colors flex-shrink-0"
                    >
                      {saving[c.course_id]
                        ? <FaSpinner className="animate-spin text-[10px]" />
                        : <FaPlus className="text-[10px]" />}
                      Ø¥Ø¶Ø§ÙØ©
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

        </div>
      )}
    </>
  )
}

