import { useEffect, useMemo, useState } from 'react'
import { FaSearch, FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'

const REASONS = {
  already_registered: 'مسجّلة أصلًا',
  already_in_request: 'ضمن طلب قائم',
  course_already_passed: 'ناجحة سابقًا',
  missing_prerequisites: 'متطلب سابق ناقص',
  credit_limit_exceeded: 'تتجاوز حد الساعات',
  timetable_conflict: 'تعارض في الوقت',
  offering_schedule_incomplete: 'الجدول غير مكتمل',
  timetable_reference_incomplete: 'جدول مرجعي غير مكتمل',
}

function studentName(student) {
  return [student.first_name, student.father_name, student.last_name].filter(Boolean).join(' ')
}

function hoursOf(course) {
  const hours = Number(course.credit_hours ?? course.course?.credit_hours ?? 0)
  return Number.isFinite(hours) ? hours : 0
}

export default function StudentAffairsCourseRegistrationPage() {
  const [query, setQuery] = useState('')
  const [matches, setMatches] = useState([])
  const [student, setStudent] = useState(null)
  const [year, setYear] = useState(null)
  const [semesters, setSemesters] = useState([])
  const [semesterId, setSemesterId] = useState('')
  const [courses, setCourses] = useState([])
  const [hours, setHours] = useState(null)
  const [selected, setSelected] = useState([])
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  useEffect(() => {
    let cancelled = false
    async function loadTerm() {
      try {
        const [yearJson, semesterJson] = await Promise.all([
          apiRequest('/v1/academic-years/current'),
          apiRequest('/v1/semesters/active?per_page=20'),
        ])
        if (cancelled) return
        const yearRow = yearJson.data
        const semesterRows = semesterJson.data?.data ?? semesterJson.data ?? []
        setYear(yearRow)
        setSemesters(Array.isArray(semesterRows) ? semesterRows : [])
        setSemesterId(semesterRows[0] ? String(semesterRows[0].semester_id) : '')
      } catch (err) {
        if (!cancelled) setError(err.message || 'تعذر تحميل السنة والفصل الحاليين.')
      }
    }
    loadTerm()
    return () => { cancelled = true }
  }, [])

  async function search(event) {
    event.preventDefault()
    const q = query.trim()
    if (!q) return
    setBusy('search')
    setError('')
    setNotice('')
    try {
      const json = await apiRequest(`/v1/students/search?q=${encodeURIComponent(q)}&per_page=8`)
      setMatches(json.data?.data ?? [])
    } catch (err) {
      setMatches([])
      setError(err.message || 'تعذر البحث عن الطالب.')
    } finally {
      setBusy('')
    }
  }

  async function loadStudent(nextStudent, nextSemesterId = semesterId) {
    if (!year?.academic_year_id || !nextSemesterId) {
      setError('لا توجد سنة أو فصل حالي لتسجيل المواد.')
      return
    }
    setStudent(nextStudent)
    setSelected([])
    setBusy('courses')
    setError('')
    const params = `academic_year_id=${year.academic_year_id}&semester_id=${nextSemesterId}`
    try {
      const [coursesJson, hoursJson] = await Promise.all([
        apiRequest(`/v1/students/${nextStudent.student_id}/available-courses?${params}`),
        apiRequest(`/v1/students/${nextStudent.student_id}/registered-hours?${params}`),
      ])
      setCourses(Array.isArray(coursesJson.data) ? coursesJson.data : [])
      setHours(hoursJson.data ?? null)
    } catch (err) {
      setCourses([])
      setHours(null)
      setError(err.message || 'تعذر تحميل مواد الطالب.')
    } finally {
      setBusy('')
    }
  }

  const selectedHours = useMemo(
    () => courses.filter(course => selected.includes(course.course_offering_id)).reduce((sum, course) => sum + hoursOf(course), 0),
    [courses, selected],
  )
  const remaining = Number(hours?.remaining_hours ?? 0)
  const overLimit = hours != null && selectedHours > remaining

  function toggle(course) {
    const id = course.course_offering_id
    setSelected(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id])
  }

  async function submit(event) {
    event.preventDefault()
    if (!student || selected.length === 0 || overLimit) return
    setBusy('save')
    setError('')
    setNotice('')
    try {
      const json = await apiRequest('/v1/student-affairs/course-registrations', {
        method: 'POST',
        body: JSON.stringify({ student_id: student.student_id, course_offering_ids: selected }),
      })
      await loadStudent(student)
      setNotice(json.message || 'تم تسجيل المواد.')
    } catch (err) {
      setError(err.message || 'تعذر تسجيل المواد.')
      setBusy('')
    }
  }

  return (
    <div className="space-y-5 px-2 py-6" dir="rtl">
      <header className="rounded-[18px] border border-primary/15 bg-white p-6">
        <h1 className="text-[22px] font-black text-text-dark">تسجيل مواد الطلاب</h1>
        <p className="mt-2 text-[13px] leading-6 text-text-light">
          تُدخل هنا المواد التي اعتمدها المرشد الأكاديمي على الورق. الصفحة لا تظهر لحساب شؤون الطلاب إلا إذا منح الأدمن دور «تسجيل مواد الطلاب»، ويسحبها في أي وقت من شاشة الحسابات.
        </p>
      </header>

      {error && <p className="rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700" role="alert">{error}</p>}
      {notice && <p className="rounded-[12px] border border-green-200 bg-green-50 px-4 py-3 text-[13px] text-green-800" role="status">{notice}</p>}

      <form className="flex flex-wrap gap-2 rounded-[16px] border border-primary/12 bg-white p-4" onSubmit={search}>
        <input
          className="min-w-[220px] flex-1 rounded-[10px] border border-primary/20 px-3 py-2 text-[13px] outline-none focus:border-primary"
          value={query}
          onChange={event => setQuery(event.target.value)}
          placeholder="رقم القيد أو اسم الطالب"
        />
        <button type="submit" className="flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-white disabled:opacity-50" disabled={busy === 'search'}>
          {busy === 'search' ? <FaSpinner className="animate-spin" /> : <FaSearch />} بحث
        </button>
      </form>

      {matches.length > 0 && (
        <ul className="overflow-hidden rounded-[16px] border border-primary/12 bg-white">
          {matches.map(match => (
            <li key={match.student_id} className="flex items-center justify-between gap-3 border-b border-primary/10 px-4 py-3 last:border-b-0">
              <span className="text-[13px] text-text-dark"><b>{match.student_number}</b> — {studentName(match)}</span>
              <button type="button" className="rounded-[9px] border border-primary/20 px-3 py-1.5 text-[12px] font-bold text-primary-dark" onClick={() => { setMatches([]); loadStudent(match) }}>اختيار</button>
            </li>
          ))}
        </ul>
      )}

      {student && (
        <section className="space-y-4 rounded-[16px] border border-primary/12 bg-white p-4">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 className="text-[16px] font-extrabold text-text-dark">{studentName(student)}</h2>
              <p className="text-[12px] text-text-light">رقم القيد {student.student_number}{year?.year_name ? ` — ${year.year_name}` : ''}</p>
            </div>
            <label className="text-[12px] font-bold text-text-dark">
              الفصل
              <select className="mt-1 block rounded-[9px] border border-primary/20 px-3 py-2 text-[13px]" value={semesterId} onChange={event => { setSemesterId(event.target.value); loadStudent(student, event.target.value) }}>
                {semesters.map(semester => <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>)}
              </select>
            </label>
          </div>

          {hours && (
            <div className="grid grid-cols-3 gap-2 text-center max-[640px]:grid-cols-1">
              <p className="rounded-[12px] bg-primary/5 px-3 py-2 text-[12px]">المسجّل: <b>{hours.registered_hours}</b></p>
              <p className="rounded-[12px] bg-primary/5 px-3 py-2 text-[12px]">الحد الأعلى: <b>{hours.max_allowed_hours}</b></p>
              <p className="rounded-[12px] bg-primary/5 px-3 py-2 text-[12px]">المتبقي: <b>{hours.remaining_hours}</b></p>
            </div>
          )}

          {busy === 'courses' ? <p className="text-[13px] text-text-light">جارٍ تحميل المواد...</p> : (
            <form onSubmit={submit}>
              <ul className="divide-y divide-primary/10">
                {courses.map(course => {
                  const eligible = course.eligibility_status === 'eligible'
                  const checked = selected.includes(course.course_offering_id)
                  const wouldExceed = !checked && hours != null && selectedHours + hoursOf(course) > remaining
                  return (
                    <li key={course.course_offering_id} className="flex items-start gap-3 py-3">
                      <input type="checkbox" className="mt-1" checked={checked} disabled={!eligible || wouldExceed} onChange={() => toggle(course)} />
                      <div>
                        <p className="text-[13px] font-bold text-text-dark">{course.course_code} — {course.course_name}</p>
                        <p className="text-[12px] text-text-light">{hoursOf(course)} ساعة{course.requirement_classification ? ` — ${course.requirement_classification}` : ''}</p>
                        {!eligible && <p className="text-[12px] text-amber-800">{(course.eligibility_reasons ?? []).map(reason => REASONS[reason] ?? reason).join('، ')}</p>}
                        {wouldExceed && <p className="text-[12px] text-amber-800">إضافتها تتجاوز الحد الأعلى للساعات.</p>}
                      </div>
                    </li>
                  )
                })}
              </ul>
              {courses.length === 0 && <p className="py-4 text-[13px] text-text-light">لا توجد مواد مفتوحة لهذا الفصل.</p>}
              <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p className="text-[12px] text-text-light">المختار: {selected.length} مواد، {selectedHours} ساعة</p>
                <button type="submit" className="rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-white disabled:opacity-50" disabled={busy === 'save' || selected.length === 0 || overLimit}>
                  {busy === 'save' ? 'جارٍ الحفظ...' : 'تسجيل المواد المختارة'}
                </button>
              </div>
            </form>
          )}
        </section>
      )}
    </div>
  )
}
