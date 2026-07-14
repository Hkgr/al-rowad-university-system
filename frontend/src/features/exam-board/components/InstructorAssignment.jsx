import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { FaChalkboardTeacher, FaSpinner } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
const POPOVER_WIDTH = 256
const POPOVER_HEIGHT_ESTIMATE = 260

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function apiGet(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

async function apiPost(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  return r.json()
}

async function apiDelete(url) {
  const r = await fetch(url, { method: 'DELETE', headers: authHeaders() })
  return r.json()
}

function facultyName(facultyMemberId, facultyOptions) {
  const f = facultyOptions.find(fm => fm.faculty_member_id === facultyMemberId)
  if (!f) return null
  return `${f.employee?.first_name ?? ''} ${f.employee?.last_name ?? ''}`.trim() || null
}

// Computes fixed viewport coordinates for the popover from the trigger button's rect,
// clamped so it never runs off-screen — used instead of CSS position:absolute because
// this control sits inside card grids with overflow-hidden ancestors that would clip it.
function popoverCoords(rect) {
  let left = rect.right - POPOVER_WIDTH
  left = Math.max(8, Math.min(left, window.innerWidth - POPOVER_WIDTH - 8))
  const opensUp = rect.bottom + POPOVER_HEIGHT_ESTIMATE > window.innerHeight
  const top = opensUp ? Math.max(8, rect.top - POPOVER_HEIGHT_ESTIMATE) : rect.bottom + 4
  return { top, left }
}

// Shared نظري/عملي instructor-assignment control. Reads/writes course_offering_instructors
// rows (نظري = the is_primary row, عملي = the non-primary row with instructor_role 'practical').
// Picking the same person for both slots collapses to a single row, since one instructor
// already covers everything when no separate عملي professor is chosen.
export default function InstructorAssignment({ offering, facultyOptions, onUpdated, readOnly }) {
  const [open, setOpen] = useState(false)
  const [coords, setCoords] = useState(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [instructors, setInstructors] = useState([])
  const [theoreticalId, setTheoreticalId] = useState('')
  const [practicalId, setPracticalId] = useState('')
  const triggerRef = useRef(null)
  const popoverRef = useRef(null)

  useEffect(() => {
    if (!open) return
    function onClickOutside(e) {
      if (triggerRef.current?.contains(e.target)) return
      if (popoverRef.current?.contains(e.target)) return
      setOpen(false)
    }
    function onViewportChange() {
      setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    window.addEventListener('resize', onViewportChange)
    window.addEventListener('scroll', onViewportChange, true)
    return () => {
      document.removeEventListener('mousedown', onClickOutside)
      window.removeEventListener('resize', onViewportChange)
      window.removeEventListener('scroll', onViewportChange, true)
    }
  }, [open])

  const currentName = facultyName(offering.faculty_member_id, facultyOptions)

  async function handleOpen() {
    if (readOnly) return
    setCoords(popoverCoords(triggerRef.current.getBoundingClientRect()))
    setOpen(true)
    setError('')
    setLoading(true)
    try {
      const json = await apiGet(`${API}/course-offerings/${offering.course_offering_id}/instructors`)
      const rows = json.success ? json.data : []
      setInstructors(rows)
      const primary = rows.find(r => r.is_primary)
      const practical = rows.find(r => !r.is_primary && r.instructor_role === 'practical')
      setTheoreticalId(primary ? primary.faculty_member_id : (offering.faculty_member_id ?? ''))
      setPracticalId(practical ? practical.faculty_member_id : '')
    } catch {
      setError('تعذّر تحميل بيانات الأساتذة')
    } finally {
      setLoading(false)
    }
  }

  async function handleSave() {
    setSaving(true)
    setError('')
    try {
      const originalPrimary = instructors.find(r => r.is_primary)
      const originalPractical = instructors.find(r => !r.is_primary && r.instructor_role === 'practical')
      const originalTheoreticalId = originalPrimary ? originalPrimary.faculty_member_id : (offering.faculty_member_id ?? '')
      const originalPracticalId = originalPractical ? originalPractical.faculty_member_id : ''

      const effectivePracticalId = practicalId && practicalId !== theoreticalId ? practicalId : ''

      const base = `${API}/course-offerings/${offering.course_offering_id}/instructors`
      const rowBase = `${API}/course-offering-instructors`

      if (theoreticalId !== originalTheoreticalId) {
        if (theoreticalId) {
          const res = await apiPost(base, { faculty_member_id: theoreticalId, instructor_role: 'theoretical', is_primary: true })
          if (!res.success) throw new Error(res.message || 'فشل تعيين أستاذ النظري')
        } else if (originalPrimary) {
          const res = await apiDelete(`${rowBase}/${originalPrimary.course_offering_instructor_id}`)
          if (!res.success) throw new Error(res.message || 'فشل إلغاء تعيين أستاذ النظري')
        }
      }

      if (effectivePracticalId !== originalPracticalId) {
        if (effectivePracticalId) {
          const res = await apiPost(base, { faculty_member_id: effectivePracticalId, instructor_role: 'practical', is_primary: false })
          if (!res.success) throw new Error(res.message || 'فشل تعيين أستاذ العملي')
        } else if (originalPractical) {
          const res = await apiDelete(`${rowBase}/${originalPractical.course_offering_instructor_id}`)
          if (!res.success) throw new Error(res.message || 'فشل إلغاء تعيين أستاذ العملي')
        }
      }

      const refreshed = await apiGet(base)
      const rows = refreshed.success ? refreshed.data : []
      const newPrimary = rows.find(r => r.is_primary)
      onUpdated(offering, {
        faculty_member_id: newPrimary ? newPrimary.faculty_member_id : null,
        faculty_member: newPrimary ? newPrimary.faculty_member : null,
      })
      setOpen(false)
    } catch (err) {
      setError(err.message || 'تعذّر حفظ التعيين')
    } finally {
      setSaving(false)
    }
  }

  if (readOnly) {
    return (
      <p className="text-[11px] text-text-light mt-2 flex items-center gap-1.5" dir="rtl">
        <FaChalkboardTeacher className="text-[10px] flex-shrink-0" />
        {currentName || 'بدون أستاذ'}
      </p>
    )
  }

  return (
    <div className="mt-2" dir="rtl" ref={triggerRef}>
      <button
        type="button"
        onClick={handleOpen}
        className="flex items-center gap-1.5 text-[11px] text-text-dark hover:text-primary transition-colors w-full text-right"
      >
        <FaChalkboardTeacher className="text-[10px] text-text-light flex-shrink-0" />
        <span className="truncate">{currentName || 'بدون أستاذ'}</span>
      </button>

      {open && coords && createPortal(
        <div
          ref={popoverRef}
          className="fixed z-50 bg-white border border-primary/20 rounded-[10px] shadow-lg p-3"
          style={{ top: coords.top, left: coords.left, width: POPOVER_WIDTH }}
          dir="rtl"
        >
          {loading ? (
            <div className="flex items-center justify-center py-4">
              <FaSpinner className="animate-spin text-primary text-sm" />
            </div>
          ) : (
            <>
              <label className="block text-[10px] text-text-light mb-1">أستاذ النظري</label>
              <select
                value={theoreticalId}
                onChange={e => setTheoreticalId(e.target.value ? Number(e.target.value) : '')}
                disabled={saving}
                className="w-full px-2 py-1 border border-primary/20 rounded-[7px] text-[11px] text-text-dark outline-none focus:border-primary disabled:opacity-50 mb-2"
              >
                <option value="">بدون أستاذ</option>
                {facultyOptions.map(f => (
                  <option key={f.faculty_member_id} value={f.faculty_member_id}>
                    {f.employee?.first_name} {f.employee?.last_name}
                  </option>
                ))}
              </select>

              <label className="block text-[10px] text-text-light mb-1">أستاذ العملي (اختياري)</label>
              <select
                value={practicalId}
                onChange={e => setPracticalId(e.target.value ? Number(e.target.value) : '')}
                disabled={saving}
                className="w-full px-2 py-1 border border-primary/20 rounded-[7px] text-[11px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              >
                <option value="">نفس أستاذ النظري</option>
                {facultyOptions.map(f => (
                  <option key={f.faculty_member_id} value={f.faculty_member_id}>
                    {f.employee?.first_name} {f.employee?.last_name}
                  </option>
                ))}
              </select>

              {error && <p className="text-[10px] text-red-500 mt-2">{error}</p>}

              <div className="flex items-center gap-2 mt-3">
                <button
                  type="button"
                  onClick={handleSave}
                  disabled={saving}
                  className="flex-1 px-2 py-1 bg-primary text-white rounded-[7px] text-[11px] disabled:opacity-50 flex items-center justify-center gap-1"
                >
                  {saving && <FaSpinner className="animate-spin text-[10px]" />}
                  حفظ
                </button>
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  disabled={saving}
                  className="flex-1 px-2 py-1 border border-primary/20 text-text-dark rounded-[7px] text-[11px] disabled:opacity-50"
                >
                  إلغاء
                </button>
              </div>
            </>
          )}
        </div>,
        document.body
      )}
    </div>
  )
}
