import { useState, useRef, useEffect } from 'react'
import { createPortal } from 'react-dom'
import { FaChalkboardTeacher, FaSpinner } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'
const POPOVER_WIDTH = 256
const POPOVER_HEIGHT_ESTIMATE = 220

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function apiGet(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

function facultyName(facultyMemberId, facultyOptions) {
  const f = facultyOptions.find(fm => fm.faculty_member_id === facultyMemberId)
  if (!f) return null
  return `${f.employee?.first_name ?? ''} ${f.employee?.last_name ?? ''}`.trim() || null
}

function popoverCoords(rect) {
  let left = rect.right - POPOVER_WIDTH
  left = Math.max(8, Math.min(left, window.innerWidth - POPOVER_WIDTH - 8))
  const opensUp = rect.bottom + POPOVER_HEIGHT_ESTIMATE > window.innerHeight
  const top = opensUp ? Math.max(8, rect.top - POPOVER_HEIGHT_ESTIMATE) : rect.bottom + 4
  return { top, left }
}

function slotName(row, facultyOptions) {
  if (!row) return 'بدون أستاذ'
  return facultyName(row.faculty_member_id, facultyOptions)
    || `${row.faculty_member?.employee?.first_name ?? ''} ${row.faculty_member?.employee?.last_name ?? ''}`.trim()
    || 'بدون أستاذ'
}

// Shared نظري/عملي instructor display. Effective rows are read-only here.
// New or replacement assignments must go through the dual VP teaching-assignment workflow.
export default function InstructorAssignment({ offering, facultyOptions, readOnly }) {
  const [open, setOpen] = useState(false)
  const [coords, setCoords] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [instructors, setInstructors] = useState([])
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
    } catch {
      setError('تعذّر تحميل بيانات الأساتذة')
    } finally {
      setLoading(false)
    }
  }

  const primary = instructors.find(r => r.is_primary)
  const practical = instructors.find(r => !r.is_primary && r.instructor_role === 'practical')

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
              <p className="text-[10px] text-text-light mb-1">أستاذ النظري</p>
              <p className="text-[11px] text-text-dark font-semibold mb-2">{slotName(primary, facultyOptions)}</p>
              <p className="text-[10px] text-text-light mb-1">أستاذ العملي</p>
              <p className="text-[11px] text-text-dark font-semibold mb-2">{slotName(practical, facultyOptions)}</p>
              <p className="text-[10px] text-amber-800 leading-5">
                لا يمكن تعديل التكليف النافذ مباشرة. يجب إرسال طلب عبر مسار موافقة النائب العلمي والنائب الإداري.
              </p>
              {error && <p className="text-[10px] text-red-500 mt-2">{error}</p>}
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="mt-3 w-full px-2 py-1 border border-primary/20 text-text-dark rounded-[7px] text-[11px]"
              >
                إغلاق
              </button>
            </>
          )}
        </div>,
        document.body
      )}
    </div>
  )
}
