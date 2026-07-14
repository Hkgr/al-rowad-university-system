import { useState, useEffect, useRef, useMemo } from 'react'
import {
  FaSpinner, FaPlus, FaSearch, FaLockOpen, FaLock, FaCheckCircle, FaTimes, FaLayerGroup, FaEye, FaPen,
} from 'react-icons/fa'
import InstructorAssignment from '../components/InstructorAssignment'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function get(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

async function post(url, body) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { ...authHeaders(), 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  return r.json()
}

// Same "shared vs. department-specific" logic as CoursesPage — a course counts as shared
// when it has zero rows in course_departments. Department granularity matches the
// business rule that a course can be restricted to specific departments within a college
// (e.g. مادة خاصة بهندسة المعلوماتية والكهرباء فقط، وليس العمارة).
function courseScopeInfo(courseId, courseDepartmentIdMap, departments) {
  const ids = courseDepartmentIdMap[courseId]
  if (!ids || ids.size === 0) return { label: 'مشتركة', className: 'bg-amber-100 text-amber-700' }
  const names = departments.filter(d => ids.has(String(d.department_id))).map(d => d.department_name)
  const label = names.length <= 1 ? (names[0] || 'خاصة') : `${names.length} أقسام`
  return { label, className: 'bg-primary/10 text-primary-dark' }
}

// ── Searchable course picker ──────────────────────────────────────────────────
function CourseCombobox({ courses, excludeIds, value, onChange, placeholder, courseDepartmentIdMap, departments }) {
  const [query, setQuery] = useState('')
  const [open, setOpen]   = useState(false)
  const boxRef = useRef(null)

  useEffect(() => {
    function onClickOutside(e) {
      if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [])

  const available = useMemo(
    () => courses.filter(c => !excludeIds?.has(c.course_id)),
    [courses, excludeIds]
  )
  const selected = available.find(c => c.course_id === value)
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return available.slice(0, 30)
    return available.filter(c =>
      c.course_code?.toLowerCase().includes(q) || c.course_name?.toLowerCase().includes(q)
    ).slice(0, 30)
  }, [available, query])

  return (
    <div className="relative" ref={boxRef}>
      <div className="relative">
        <FaSearch className="absolute right-3 top-1/2 -translate-y-1/2 text-text-light text-[11px] pointer-events-none" />
        <input
          type="text"
          placeholder={placeholder || 'ابحث برمز أو اسم المادة…'}
          className="w-full pr-7 pl-2 py-2 border border-primary/20 rounded-[8px] text-[12.5px] text-text-dark outline-none focus:border-primary"
          value={open ? query : (selected ? `${selected.course_code} — ${selected.course_name}` : '')}
          onFocus={() => { setOpen(true); setQuery('') }}
          onChange={e => setQuery(e.target.value)}
          dir="rtl"
        />
      </div>
      {open && (
        <div className="absolute z-20 mt-1 w-full max-h-[220px] overflow-y-auto bg-white border border-primary/15 rounded-[10px] shadow-lg divide-y divide-primary/6">
          {filtered.length === 0 ? (
            <p className="text-center text-[12px] text-text-light py-3" dir="rtl">لا توجد نتائج</p>
          ) : filtered.map(c => {
            const scope = courseDepartmentIdMap && departments ? courseScopeInfo(c.course_id, courseDepartmentIdMap, departments) : null
            return (
              <button
                key={c.course_id}
                type="button"
                className="w-full flex items-center justify-between gap-2 text-right px-3 py-1.5 text-[12.5px] hover:bg-primary/[0.05] transition-colors"
                onClick={() => { onChange(c.course_id); setOpen(false); setQuery('') }}
                dir="rtl"
              >
                <span className="min-w-0 truncate">
                  <span className="font-mono text-primary-dark font-bold">{c.course_code}</span>
                  <span className="text-text-dark"> — {c.course_name}</span>
                </span>
                {scope && (
                  <span className={`flex-shrink-0 text-[9.5px] font-bold px-1.5 py-0.5 rounded-full whitespace-nowrap ${scope.className}`}>
                    {scope.label}
                  </span>
                )}
              </button>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ── One card: a curriculum course + its details + its term status ───────────
function CurriculumCourseRow({ course, programCourse, offering, onRemove, onOpen, onToggle, onInstructorUpdated, busy, readOnly, courseDepartmentIdMap, departments, facultyMembers }) {
  const [capacity, setCapacity] = useState('40')
  const scope = course ? courseScopeInfo(course.course_id, courseDepartmentIdMap, departments) : null

  return (
    <div className="border border-primary/12 rounded-[12px] px-4 py-3.5 bg-white hover:shadow-[0_2px_10px_rgba(26,46,16,0.06)] transition-shadow" dir="rtl">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-[14px] font-extrabold text-text-dark">
            <span className="font-mono text-primary-dark">{course?.course_code ?? '—'}</span>
          </div>
          <div className="text-[13px] text-text-dark mt-0.5">{course?.course_name ?? '—'}</div>
        </div>
        {!readOnly && (
          <button
            type="button"
            onClick={() => onRemove(programCourse.program_course_id)}
            disabled={busy}
            className="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-full text-red-400 hover:bg-red-50 hover:text-red-600 transition-colors disabled:opacity-40"
            title="إزالة من المنهج"
          >
            <FaTimes className="text-[11px]" />
          </button>
        )}
      </div>

      <div className="flex items-center flex-wrap gap-1.5 mt-2.5">
        <span className={`inline-block text-[10px] font-bold px-1.5 py-0.5 rounded-full ${programCourse.course_type === 'mandatory' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'}`}>
          {programCourse.course_type === 'mandatory' ? 'إجباري' : 'اختياري'}
        </span>
        {scope && (
          <span className={`inline-block text-[10px] font-bold px-1.5 py-0.5 rounded-full ${scope.className}`}>
            {scope.label}
          </span>
        )}
      </div>

      {course && (
        <div className="flex items-center gap-3 mt-2.5 py-2 border-y border-primary/8 text-[11px] text-text-light">
          <span><b className="text-text-dark">{course.credit_hours ?? '—'}</b> ساعة معتمدة</span>
          <span className="text-primary/20">|</span>
          <span>نظري <b className="text-text-dark">{course.theoretical_hours ?? '—'}</b></span>
          <span className="text-primary/20">|</span>
          <span>عملي <b className="text-text-dark">{course.practical_hours ?? '—'}</b></span>
        </div>
      )}

      {course?.description && (
        <p className="text-[11px] text-text-light mt-2 line-clamp-2" title={course.description}>{course.description}</p>
      )}

      <div className="mt-3">
        {offering ? (
          <div className="flex items-center justify-between gap-2">
            <span className={`text-[10.5px] font-bold px-2 py-0.5 rounded-full ${offering.status === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'}`}>
              {offering.status === 'open' ? `مفتوحة (${offering.available_seats}/${offering.capacity})` : 'مغلقة'}
            </span>
            {!readOnly && (
              <button
                type="button"
                onClick={() => onToggle(offering)}
                disabled={busy}
                className="flex items-center gap-1 px-2 py-1 border border-primary/25 rounded-[7px] text-[10.5px] font-bold text-primary-dark hover:bg-primary/7 disabled:opacity-40 transition-colors"
              >
                {offering.status === 'open' ? <FaLock className="text-[9px]" /> : <FaLockOpen className="text-[9px]" />}
                {offering.status === 'open' ? 'إغلاق' : 'فتح'}
              </button>
            )}
          </div>
        ) : readOnly ? (
          <span className="inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full bg-gray-100 text-text-light">لم تُفتح لهذا الفصل بعد</span>
        ) : null}
        {offering && (
          <InstructorAssignment
            offering={offering}
            facultyOptions={facultyMembers}
            onUpdated={onInstructorUpdated}
            readOnly={readOnly}
          />
        )}
        {!offering && !readOnly && (
          <div className="flex items-center gap-1.5">
            <input
              type="number"
              min="1"
              value={capacity}
              onChange={e => setCapacity(e.target.value)}
              className="w-16 px-2 py-1 border border-primary/20 rounded-[7px] text-[11.5px] text-center outline-none focus:border-primary"
            />
            <button
              type="button"
              onClick={() => onOpen(course.course_id, capacity)}
              disabled={busy || !capacity || Number(capacity) < 1}
              className="flex-1 flex items-center justify-center gap-1 px-2 py-1 bg-primary text-white rounded-[7px] text-[10.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40 transition-colors"
            >
              {busy ? <FaSpinner className="animate-spin text-[9px]" /> : <FaLockOpen className="text-[9px]" />}
              فتح لهذا الفصل
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

// ── One section = one academic level (year 1..5), full-width with a rich grid of course cards ──
function LevelColumn({ level, rows, courses, assignedCourseIds, onAdd, onRemove, onOpen, onToggle, onInstructorUpdated, busyIds, readOnly, courseDepartmentIdMap, departments, facultyMembers }) {
  const [adding, setAdding]   = useState(false)
  const [courseId, setCourseId] = useState('')
  const [courseType, setCourseType] = useState('mandatory')
  const [capacity, setCapacity] = useState('40')
  const [err, setErr] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function submit() {
    setErr('')
    if (!courseId) { setErr('اختر المادة'); return }
    if (!capacity || Number(capacity) < 1) { setErr('أدخل عدد المقاعد'); return }
    setSubmitting(true)
    try {
      await onAdd(level.academic_level_id, Number(courseId), courseType, Number(capacity))
      setAdding(false); setCourseId(''); setCourseType('mandatory'); setCapacity('40')
    } catch (e) {
      setErr(e.message || 'فشل الإضافة')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="bg-white border border-primary/12 rounded-[14px] overflow-hidden shadow-[0_2px_8px_rgba(26,46,16,0.05)] mb-4">
      <div className="px-4 py-3 bg-primary/[0.06] border-b border-primary/10 flex items-center justify-between" dir="rtl">
        <span className="text-[14px] font-extrabold text-text-dark">{level.level_name}</span>
        <span className="text-[11px] text-text-light bg-white px-2 py-0.5 rounded-full font-bold">{rows.length} مادة</span>
      </div>
      <div className="p-4">
        {rows.length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            {rows.map(({ programCourse, course, offering }) => (
              <CurriculumCourseRow
                key={programCourse.program_course_id}
                course={course}
                programCourse={programCourse}
                offering={offering}
                onRemove={onRemove}
                onOpen={onOpen}
                onToggle={onToggle}
                onInstructorUpdated={onInstructorUpdated}
                busy={!!busyIds[programCourse.program_course_id] || (offering && !!busyIds[offering.course_offering_id])}
                readOnly={readOnly}
                courseDepartmentIdMap={courseDepartmentIdMap}
                departments={departments}
                facultyMembers={facultyMembers}
              />
            ))}
          </div>
        )}
        {rows.length === 0 && !adding && (
          <p className="text-center text-[11.5px] text-text-light py-3" dir="rtl">لا توجد مواد بعد</p>
        )}
        {!readOnly && (
        <div className={rows.length > 0 ? 'mt-3.5 pt-3.5 border-t border-primary/8' : ''}>
          {adding ? (
            <div className="max-w-md space-y-2" dir="rtl">
              <CourseCombobox
                courses={courses}
                excludeIds={assignedCourseIds}
                value={courseId}
                onChange={setCourseId}
                courseDepartmentIdMap={courseDepartmentIdMap}
                departments={departments}
              />
              <div className="flex items-center gap-2">
                <select
                  value={courseType}
                  onChange={e => setCourseType(e.target.value)}
                  className="flex-1 px-2 py-1.5 border border-primary/20 rounded-[7px] text-[11.5px] outline-none focus:border-primary"
                >
                  <option value="mandatory">إجباري</option>
                  <option value="elective">اختياري</option>
                </select>
                <input
                  type="number"
                  min="1"
                  value={capacity}
                  onChange={e => setCapacity(e.target.value)}
                  className="w-16 px-2 py-1.5 border border-primary/20 rounded-[7px] text-[11.5px] text-center outline-none focus:border-primary"
                  placeholder="مقاعد"
                />
              </div>
              {err && <p className="text-[11px] text-red-600">⚠ {err}</p>}
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={submit}
                  disabled={submitting}
                  className="flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 bg-primary text-white rounded-[7px] text-[11.5px] font-bold hover:enabled:bg-primary-dark disabled:opacity-50 transition-colors"
                >
                  {submitting ? <FaSpinner className="animate-spin text-[10px]" /> : <FaPlus className="text-[10px]" />}
                  إضافة
                </button>
                <button
                  type="button"
                  onClick={() => { setAdding(false); setErr('') }}
                  className="px-2 py-1.5 border border-primary/20 rounded-[7px] text-[11.5px] text-text-light hover:bg-primary/5 transition-colors"
                >
                  إلغاء
                </button>
              </div>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => setAdding(true)}
              className="flex items-center justify-center gap-1.5 px-3 py-1.5 border border-dashed border-primary/30 rounded-[7px] text-[11.5px] font-bold text-primary-dark hover:bg-primary/5 transition-colors"
            >
              <FaPlus className="text-[10px]" /> إضافة مادة
            </button>
          )}
        </div>
        )}
      </div>
    </div>
  )
}

// ── Shared / common courses (open to every college) ──────────────────────────
function SharedCoursesSection({ courses, offerings, yearId, semId, onAdd, onToggle, onInstructorUpdated, busyIds, facultyMembers }) {
  const [adding, setAdding] = useState(false)
  const [courseId, setCourseId] = useState('')
  const [capacity, setCapacity] = useState('40')
  const [err, setErr] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const sharedOfferings = offerings.filter(o =>
    !o.academic_program_id && String(o.academic_year_id) === String(yearId) && String(o.semester_id) === String(semId)
  )
  const excludeIds = useMemo(() => new Set(sharedOfferings.map(o => o.course_id)), [sharedOfferings])
  const courseMap = useMemo(() => Object.fromEntries(courses.map(c => [c.course_id, c])), [courses])

  async function submit() {
    setErr('')
    if (!courseId) { setErr('اختر المادة'); return }
    if (!capacity || Number(capacity) < 1) { setErr('أدخل عدد المقاعد'); return }
    setSubmitting(true)
    try {
      await onAdd(Number(courseId), Number(capacity))
      setAdding(false); setCourseId(''); setCapacity('40')
    } catch (e) {
      setErr(e.message || 'فشل الإضافة')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)] mb-5">
      <div className="flex items-center gap-2 px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
        <FaLayerGroup className="text-primary text-[13px]" />
        <span className="text-[13px] font-extrabold text-text-dark">مواد مشتركة بين كل الكليات</span>
        <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{sharedOfferings.length}</span>
      </div>
      <div className="p-4 space-y-2">
        {sharedOfferings.map(o => {
          const course = courseMap[o.course_id]
          const isOpen = o.status === 'open'
          return (
            <div key={o.course_offering_id} className="border border-primary/10 rounded-[10px] px-3.5 py-2" dir="rtl">
              <div className="flex items-center justify-between gap-3">
                <div className="text-[12.5px] font-bold text-text-dark">
                  <span className="font-mono text-primary-dark">{course?.course_code ?? '—'}</span> — {course?.course_name ?? '—'}
                  <span className="text-[11px] text-text-light font-normal"> ({o.available_seats}/{o.capacity})</span>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <span className={`px-2 py-0.5 rounded-full text-[10.5px] font-bold ${isOpen ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-600'}`}>
                    {isOpen ? 'مفتوحة' : 'مغلقة'}
                  </span>
                  <button
                    type="button"
                    onClick={() => onToggle(o)}
                    disabled={!!busyIds[o.course_offering_id]}
                    className="flex items-center gap-1 px-2 py-1 border border-primary/25 rounded-[7px] text-[10.5px] font-bold text-primary-dark hover:bg-primary/7 disabled:opacity-40 transition-colors"
                  >
                    {isOpen ? <FaLock className="text-[9px]" /> : <FaLockOpen className="text-[9px]" />}
                    {isOpen ? 'إغلاق' : 'فتح'}
                  </button>
                </div>
              </div>
              <InstructorAssignment
                offering={o}
                facultyOptions={facultyMembers}
                onUpdated={onInstructorUpdated}
                readOnly={false}
              />
            </div>
          )
        })}
        {sharedOfferings.length === 0 && !adding && (
          <p className="text-center text-[12px] text-text-light py-2" dir="rtl">لا توجد مواد مشتركة مفتوحة لهذا الفصل</p>
        )}

        {adding ? (
          <div className="border border-primary/15 rounded-[10px] p-3 space-y-2" dir="rtl">
            <CourseCombobox courses={courses} excludeIds={excludeIds} value={courseId} onChange={setCourseId} />
            <div className="flex items-center gap-2">
              <input
                type="number"
                min="1"
                value={capacity}
                onChange={e => setCapacity(e.target.value)}
                className="w-20 px-2 py-1.5 border border-primary/20 rounded-[7px] text-[12px] text-center outline-none focus:border-primary"
                placeholder="مقاعد"
              />
              <button
                type="button"
                onClick={submit}
                disabled={submitting}
                className="flex-1 flex items-center justify-center gap-1.5 px-2 py-1.5 bg-primary text-white rounded-[7px] text-[12px] font-bold hover:enabled:bg-primary-dark disabled:opacity-50 transition-colors"
              >
                {submitting ? <FaSpinner className="animate-spin text-[10px]" /> : <FaPlus className="text-[10px]" />}
                إضافة
              </button>
              <button
                type="button"
                onClick={() => { setAdding(false); setErr('') }}
                className="px-3 py-1.5 border border-primary/20 rounded-[7px] text-[12px] text-text-light hover:bg-primary/5 transition-colors"
              >
                إلغاء
              </button>
            </div>
            {err && <p className="text-[11px] text-red-600">⚠ {err}</p>}
          </div>
        ) : (
          <button
            type="button"
            onClick={() => setAdding(true)}
            className="w-full flex items-center justify-center gap-1.5 px-2 py-1.5 border border-dashed border-primary/30 rounded-[7px] text-[11.5px] font-bold text-primary-dark hover:bg-primary/5 transition-colors"
          >
            <FaPlus className="text-[10px]" /> إضافة مادة مشتركة
          </button>
        )}
      </div>
    </div>
  )
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function CourseOfferingsPage() {
  const [years, setYears]           = useState([])
  const [semesters, setSemesters]   = useState([])
  const [colleges, setColleges]     = useState([])
  const [departments, setDepartments] = useState([])
  const [programs, setPrograms]     = useState([])
  const [courses, setCourses]       = useState([])
  const [levels, setLevels]         = useState([])
  const [programCourses, setProgramCourses] = useState([])
  const [offerings, setOfferings]   = useState([])
  const [courseDepartments, setCourseDepartments] = useState([])
  const [facultyMembers, setFacultyMembers] = useState([])
  const [employees, setEmployees]   = useState([])
  const [loading, setLoading]       = useState(true)

  const [yearId, setYearId]       = useState('')
  const [semId, setSemId]         = useState('')
  const [collegeId, setCollegeId] = useState('')
  const [programId, setProgramId] = useState('')

  const [toast, setToast] = useState('')
  const [busyIds, setBusyIds] = useState({})
  const [previewMode, setPreviewMode] = useState(false)

  useEffect(() => {
    Promise.all([
      get(`${API}/academic-years?per_page=50`),
      get(`${API}/semesters?per_page=20`),
      get(`${API}/colleges?per_page=20`),
      get(`${API}/departments?per_page=50`),
      get(`${API}/academic-programs?per_page=50`),
      get(`${API}/courses?per_page=100`),
      get(`${API}/academic-levels?per_page=20`),
      get(`${API}/program-courses?per_page=200`),
      get(`${API}/course-offerings?per_page=200`),
      get(`${API}/course-departments?per_page=500`),
      get(`${API}/faculty-members?per_page=100`),
      get(`${API}/employees?per_page=500`),
    ]).then(([y, s, col, dep, prog, crs, lvl, pc, off, cd, fac, emp]) => {
      setYears(y.success ? (y.data?.data ?? []) : [])
      setSemesters(s.success ? (s.data?.data ?? []) : [])
      setColleges(col.success ? (col.data?.data ?? []) : [])
      setDepartments(dep.success ? (dep.data?.data ?? []) : [])
      setPrograms(prog.success ? (prog.data?.data ?? []) : [])
      setCourses(crs.success ? (crs.data?.data ?? []) : [])
      setLevels(lvl.success ? (lvl.data?.data ?? []) : [])
      setProgramCourses(pc.success ? (pc.data?.data ?? []) : [])
      setOfferings(off.success ? (off.data?.data ?? []) : [])
      setCourseDepartments(cd.success ? (cd.data?.data ?? []) : [])
      setFacultyMembers(fac.success ? (fac.data?.data ?? fac.data ?? []) : [])
      setEmployees(emp.success ? (emp.data?.data ?? emp.data ?? []) : [])
    }).finally(() => setLoading(false))
  }, [])

  const courseMap = useMemo(() => Object.fromEntries(courses.map(c => [c.course_id, c])), [courses])

  // FacultyMemberResource doesn't embed the employee relation, so names are joined
  // client-side against /employees by employee_id.
  const facultyOptions = useMemo(() => {
    const employeeMap = Object.fromEntries(employees.map(e => [e.employee_id, e]))
    return facultyMembers
      .filter(f => f.is_active)
      .map(f => ({ ...f, employee: employeeMap[f.employee_id] }))
  }, [facultyMembers, employees])

  // course_id → Set<department_id>, mirrors the same "shared vs. department-specific" logic
  // used in CoursesPage — department granularity (not college) so that e.g. a course linked
  // only to هندسة المعلوماتية + هندسة الكهرباء correctly excludes العمارة, even though all
  // three departments belong to the same college.
  const courseDepartmentIdMap = useMemo(() => {
    const map = {}
    courseDepartments.forEach(a => {
      if (!map[a.course_id]) map[a.course_id] = new Set()
      map[a.course_id].add(String(a.department_id))
    })
    return map
  }, [courseDepartments])

  const departmentsInCollege = useMemo(
    () => departments.filter(d => String(d.college_id) === String(collegeId)),
    [departments, collegeId]
  )
  const programsInCollege = useMemo(() => {
    const deptIds = new Set(departmentsInCollege.map(d => d.department_id))
    return programs.filter(p => deptIds.has(p.department_id))
  }, [programs, departmentsInCollege])

  const curriculumLevels = useMemo(
    () => [...levels].filter(l => l.is_active).sort((a, b) => a.level_order - b.level_order),
    [levels]
  )

  const programCoursesForProgram = useMemo(
    () => programCourses.filter(pc => String(pc.academic_program_id) === String(programId)),
    [programCourses, programId]
  )
  const assignedCourseIds = useMemo(
    () => new Set(programCoursesForProgram.map(pc => pc.course_id)),
    [programCoursesForProgram]
  )

  // Department that the selected program belongs to — used to filter the course picker
  // down to shared courses + courses specifically linked to this department.
  const selectedProgramDepartmentId = useMemo(() => {
    const program = programs.find(p => String(p.academic_program_id) === String(programId))
    return program ? String(program.department_id) : null
  }, [programs, programId])

  // Only courses that are shared (no course_departments rows) or specifically linked to the
  // selected program's department may be added to its curriculum — mirrors the البرمجة 1
  // example: available to هندسة المعلوماتية/الكهرباء but not العمارة, even within the same كلية.
  const eligibleCourses = useMemo(() => {
    if (!selectedProgramDepartmentId) return courses
    return courses.filter(c => {
      const ids = courseDepartmentIdMap[c.course_id]
      return !ids || ids.size === 0 || ids.has(selectedProgramDepartmentId)
    })
  }, [courses, courseDepartmentIdMap, selectedProgramDepartmentId])

  function offeringFor(courseId) {
    return offerings.find(o =>
      String(o.course_id) === String(courseId) &&
      String(o.academic_year_id) === String(yearId) &&
      String(o.semester_id) === String(semId) &&
      String(o.academic_program_id) === String(programId)
    )
  }

  function showToast(msg) {
    setToast(msg)
    setTimeout(() => setToast(''), 3000)
  }

  function handleCollegeChange(v) {
    setCollegeId(v)
    setProgramId('')
  }

  async function handleAddCurriculumCourse(levelId, courseId, courseType, capacity) {
    const pcJson = await post(`${API}/program-courses`, {
      academic_program_id: Number(programId),
      course_id: courseId,
      academic_level_id: levelId,
      recommended_semester_id: Number(semId),
      course_type: courseType,
      is_active: true,
    })
    if (!pcJson.success) {
      throw new Error(pcJson.message || (pcJson.errors && Object.values(pcJson.errors)[0]?.[0]) || 'فشل ربط المادة بالمنهج')
    }
    setProgramCourses(prev => [...prev, pcJson.data])

    const ofJson = await post(`${API}/course-offerings`, {
      course_id: courseId,
      academic_year_id: Number(yearId),
      semester_id: Number(semId),
      department_id: null,
      academic_program_id: Number(programId),
      capacity,
      available_seats: capacity,
      status: 'open',
    })
    if (ofJson.success) setOfferings(prev => [...prev, ofJson.data])
    showToast('تمت إضافة المادة وفتحها لهذا الفصل')
  }

  async function handleRemoveCurriculumCourse(programCourseId) {
    if (!window.confirm('هل تريد إزالة هذه المادة من منهج هذا المستوى؟')) return
    setBusyIds(p => ({ ...p, [programCourseId]: true }))
    try {
      const res = await fetch(`${API}/program-courses/${programCourseId}`, { method: 'DELETE', headers: authHeaders() })
      const json = await res.json()
      if (json.success) setProgramCourses(prev => prev.filter(pc => pc.program_course_id !== programCourseId))
    } finally {
      setBusyIds(p => ({ ...p, [programCourseId]: false }))
    }
  }

  async function handleOpenOffering(courseId, capacity) {
    setBusyIds(p => ({ ...p, [`open-${courseId}`]: true }))
    try {
      const json = await post(`${API}/course-offerings`, {
        course_id: courseId,
        academic_year_id: Number(yearId),
        semester_id: Number(semId),
        department_id: null,
        academic_program_id: Number(programId),
        capacity: Number(capacity),
        available_seats: Number(capacity),
        status: 'open',
      })
      if (json.success) {
        setOfferings(prev => [...prev, json.data])
        showToast('تم فتح المادة لهذا الفصل')
      }
    } finally {
      setBusyIds(p => ({ ...p, [`open-${courseId}`]: false }))
    }
  }

  async function handleToggleStatus(offering) {
    const nextStatus = offering.status === 'open' ? 'closed' : 'open'
    setBusyIds(p => ({ ...p, [offering.course_offering_id]: true }))
    try {
      const res = await fetch(`${API}/course-offerings/${offering.course_offering_id}`, {
        method: 'PUT',
        headers: { ...authHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ status: nextStatus }),
      })
      const json = await res.json()
      if (json.success) {
        setOfferings(prev => prev.map(o => o.course_offering_id === offering.course_offering_id ? { ...o, status: nextStatus } : o))
        showToast(nextStatus === 'open' ? 'تم فتح المادة' : 'تم إغلاق المادة')
      }
    } finally {
      setBusyIds(p => ({ ...p, [offering.course_offering_id]: false }))
    }
  }

  function handleInstructorUpdated(offering, { faculty_member_id, faculty_member }) {
    setOfferings(prev => prev.map(o => o.course_offering_id === offering.course_offering_id ? { ...o, faculty_member_id, faculty_member } : o))
    showToast('تم تحديث تعيين الأساتذة')
  }

  async function handleAddSharedCourse(courseId, capacity) {
    const json = await post(`${API}/course-offerings`, {
      course_id: courseId,
      academic_year_id: Number(yearId),
      semester_id: Number(semId),
      department_id: null,
      academic_program_id: null,
      capacity,
      available_seats: capacity,
      status: 'open',
    })
    if (!json.success) throw new Error(json.message || 'فشل فتح المادة')
    setOfferings(prev => [...prev, json.data])
    showToast('تمت إضافة المادة المشتركة وفتحها لهذا الفصل')
  }

  if (loading) {
    return <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
  }

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">فتح المواد الدراسية</h2>
        <p className="text-[12.5px] text-text-light">Course Offerings — Open Subjects for a Semester</p>
      </div>

      {/* Year + Semester selector */}
      <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-4" dir="rtl">
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">السنة الدراسية</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
              value={yearId}
              onChange={e => { setYearId(e.target.value); setSemId('') }}
              dir="rtl"
            >
              <option value="">اختر السنة</option>
              {years.map(y => <option key={y.academic_year_id} value={y.academic_year_id}>{y.year_name}</option>)}
            </select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-[12px] font-bold text-text-dark">الفصل الدراسي</label>
            <select
              className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
              value={semId}
              onChange={e => setSemId(e.target.value)}
              disabled={!yearId}
              dir="rtl"
            >
              <option value="">اختر الفصل</option>
              {semesters.map(s => <option key={s.semester_id} value={s.semester_id}>{s.semester_name}</option>)}
            </select>
          </div>
        </div>
      </div>

      {!semId ? (
        <p className="text-center text-[13px] text-text-light py-8" dir="rtl">اختر السنة والفصل الدراسي أولاً</p>
      ) : (
        <>
          {toast && (
            <div className="mb-4 px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px] flex items-center gap-2" dir="rtl">
              <FaCheckCircle className="text-green-500 flex-shrink-0" /> {toast}
            </div>
          )}

          <SharedCoursesSection
            courses={courses}
            offerings={offerings}
            yearId={yearId}
            semId={semId}
            onAdd={handleAddSharedCourse}
            onToggle={handleToggleStatus}
            onInstructorUpdated={handleInstructorUpdated}
            busyIds={busyIds}
            facultyMembers={facultyOptions}
          />

          {/* College + Program selector */}
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="grid grid-cols-2 max-[580px]:grid-cols-1 gap-4" dir="rtl">
              <div className="flex flex-col gap-1.5">
                <label className="text-[12px] font-bold text-text-dark">الكلية</label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
                  value={collegeId}
                  onChange={e => handleCollegeChange(e.target.value)}
                  dir="rtl"
                >
                  <option value="">اختر الكلية</option>
                  {colleges.map(c => <option key={c.college_id} value={c.college_id}>{c.college_name}</option>)}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <label className="text-[12px] font-bold text-text-dark">البرنامج / التخصص</label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
                  value={programId}
                  onChange={e => setProgramId(e.target.value)}
                  disabled={!collegeId}
                  dir="rtl"
                >
                  <option value="">اختر البرنامج</option>
                  {programsInCollege.map(p => <option key={p.academic_program_id} value={p.academic_program_id}>{p.program_name}</option>)}
                </select>
              </div>
            </div>
          </div>

          {collegeId && programsInCollege.length === 0 && (
            <p className="text-center text-[13px] text-text-light py-6" dir="rtl">لا توجد برامج ضمن هذه الكلية</p>
          )}

          {programId && (
            <>
              <div className="flex justify-end mb-3">
                <button
                  type="button"
                  onClick={() => setPreviewMode(p => !p)}
                  className={`flex items-center gap-2 px-3.5 py-2 rounded-[9px] text-[12.5px] font-bold transition-colors ${previewMode ? 'bg-primary text-white' : 'border border-primary/25 text-primary-dark hover:bg-primary/7'}`}
                  dir="rtl"
                >
                  {previewMode ? <FaPen className="text-[11px]" /> : <FaEye className="text-[11px]" />}
                  {previewMode ? 'رجوع للتحرير' : 'معاينة ما تم إدخاله'}
                </button>
              </div>
              <div>
                {curriculumLevels.map(level => {
                  const rows = programCoursesForProgram
                    .filter(pc => pc.academic_level_id === level.academic_level_id && String(pc.recommended_semester_id) === String(semId))
                    .map(pc => ({ programCourse: pc, course: courseMap[pc.course_id], offering: offeringFor(pc.course_id) }))
                  return (
                    <LevelColumn
                      key={level.academic_level_id}
                      level={level}
                      rows={rows}
                      courses={eligibleCourses}
                      assignedCourseIds={assignedCourseIds}
                      onAdd={handleAddCurriculumCourse}
                      onRemove={handleRemoveCurriculumCourse}
                      onOpen={handleOpenOffering}
                      onToggle={handleToggleStatus}
                      onInstructorUpdated={handleInstructorUpdated}
                      busyIds={busyIds}
                      readOnly={previewMode}
                      courseDepartmentIdMap={courseDepartmentIdMap}
                      departments={departments}
                      facultyMembers={facultyOptions}
                    />
                  )
                })}
              </div>
            </>
          )}
        </>
      )}
    </>
  )
}
