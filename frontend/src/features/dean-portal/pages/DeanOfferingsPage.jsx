import { useEffect, useState } from 'react'
import { FaPaperPlane, FaSpinner } from 'react-icons/fa'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json', 'Content-Type': 'application/json' }
}

function rowsOf(json) {
  return json?.data?.data ?? json?.data ?? []
}

export default function DeanOfferingsPage() {
  const [years, setYears] = useState([])
  const [semesters, setSemesters] = useState([])
  const [departments, setDepartments] = useState([])
  const [faculty, setFaculty] = useState([])
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [courses, setCourses] = useState([])
  const [facultyByCourse, setFacultyByCourse] = useState({})
  const [selectedElectives, setSelectedElectives] = useState({})
  const [loading, setLoading] = useState(false)
  const [sending, setSending] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    Promise.all([
      fetch(`${API}/academic-years?per_page=50`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/semesters?per_page=20`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/departments?per_page=100`, { headers: authHeaders() }).then(r => r.json()),
      fetch(`${API}/faculty-members?per_page=200`, { headers: authHeaders() }).then(r => r.json()).catch(() => ({})),
    ]).then(([yearsJson, semestersJson, departmentsJson, facultyJson]) => {
      setYears(rowsOf(yearsJson))
      setSemesters(rowsOf(semestersJson))
      setDepartments(rowsOf(departmentsJson))
      setFaculty(rowsOf(facultyJson))
    })
  }, [])

  useEffect(() => {
    if (!yearId || !semesterId || !departmentId) {
      setCourses([])
      return
    }
    setLoading(true)
    setError('')
    setMessage('')
    const params = new URLSearchParams({ academic_year_id: yearId, semester_id: semesterId, department_id: departmentId })
    fetch(`${API}/offering-proposals/preview?${params}`, { headers: authHeaders() })
      .then(r => r.json())
      .then(json => {
        if (!json.success) {
          setError(json.message || 'تعذّر تحميل المواد')
          setCourses([])
          return
        }
        setCourses(json.data?.courses ?? [])
        setFacultyByCourse({})
        setSelectedElectives({})
      })
      .catch(() => setError('تعذّر الاتصال'))
      .finally(() => setLoading(false))
  }, [yearId, semesterId, departmentId])

  const keyOf = course => `${course.course_id}-${course.academic_program_id}`

  async function sendProposal() {
    setSending(true)
    setError('')
    setMessage('')
    const mandatoryFaculty = courses
      .filter(course => course.included_automatically && facultyByCourse[keyOf(course)])
      .map(course => ({
        course_id: course.course_id,
        academic_program_id: course.academic_program_id,
        faculty_member_id: Number(facultyByCourse[keyOf(course)]),
      }))
    const electives = courses
      .filter(course => !course.included_automatically && selectedElectives[keyOf(course)])
      .map(course => ({
        course_id: course.course_id,
        academic_program_id: course.academic_program_id,
        faculty_member_id: Number(facultyByCourse[keyOf(course)]),
        capacity: 40,
      }))

    if (electives.some(item => !item.faculty_member_id)) {
      setSending(false)
      setError('كل مادة اختيارية تحتاج مدرساً. الاستثناء التلقائي للمواد الإجبارية فقط.')
      return
    }

    try {
      const response = await fetch(`${API}/offering-proposals`, {
        method: 'POST',
        headers: authHeaders(),
        body: JSON.stringify({
          academic_year_id: Number(yearId),
          semester_id: Number(semesterId),
          department_id: Number(departmentId),
          mandatory_faculty: mandatoryFaculty,
          electives,
        }),
      })
      const json = await response.json()
      if (!json.success) setError(json.message || 'فشل الإرسال')
      else setMessage('تم إرسال الطرح إلى النائب العلمي. المواد الإجبارية بلا مدرس أُرسلت كاستثناء تلقائي.')
    } catch {
      setError('تعذّر الاتصال')
    } finally {
      setSending(false)
    }
  }

  const mandatory = courses.filter(course => course.included_automatically)
  const electives = courses.filter(course => !course.included_automatically)

  return (
    <div dir="rtl">
      <h2 className="text-[20px] font-black text-text-dark mb-1">طرح مواد الفصل</h2>
      <p className="text-[12.5px] text-text-light mb-5">المواد الإجبارية تُدرج تلقائياً. إذا لم يُحدد مدرس تُرسل للنائب العلمي كاستثناء حتى يستطيع الطلاب التسجيل.</p>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-3 mb-5">
        <select value={yearId} onChange={e => setYearId(e.target.value)} className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]">
          <option value="">السنة الدراسية</option>
          {years.map(year => <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>)}
        </select>
        <select value={semesterId} onChange={e => setSemesterId(e.target.value)} className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]">
          <option value="">الفصل</option>
          {semesters.map(semester => <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>)}
        </select>
        <select value={departmentId} onChange={e => setDepartmentId(e.target.value)} className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]">
          <option value="">القسم</option>
          {departments.map(department => <option key={department.department_id} value={department.department_id}>{department.department_name}</option>)}
        </select>
      </div>

      {loading && <div className="text-primary py-8 flex justify-center"><FaSpinner className="animate-spin" /></div>}

      {!loading && mandatory.length > 0 && (
        <section className="mb-6">
          <h3 className="font-black text-[15px] mb-2">مواد إجبارية</h3>
          <div className="flex flex-col gap-2">
            {mandatory.map(course => {
              const key = keyOf(course)
              const hasInstructor = Boolean(facultyByCourse[key])
              return (
                <div key={key} className="bg-white border border-primary/12 rounded-[12px] p-3 flex flex-wrap items-center justify-between gap-3">
                  <div>
                    <div className="font-bold text-[13.5px]">{course.course_name} <span className="text-text-light font-mono text-[12px]">{course.course_code}</span></div>
                    <div className="text-[12px] text-text-light">{course.program_name} · {course.credit_hours} ساعات</div>
                    {!hasInstructor && <div className="text-[12px] font-bold text-amber-800 mt-1">استثناء تلقائي: بلا مدرس، بانتظار موافقة النائب</div>}
                  </div>
                  <select value={facultyByCourse[key] ?? ''} onChange={e => setFacultyByCourse(prev => ({ ...prev, [key]: e.target.value }))} className="px-3 py-2 border border-primary/20 rounded-[8px] text-[12.5px] min-w-[180px]">
                    <option value="">بدون مدرس</option>
                    {faculty.map(member => <option key={member.faculty_member_id} value={member.faculty_member_id}>{[member.academic_rank, member.specialization].filter(Boolean).join(' - ') || `مدرس #${member.faculty_member_id}`}</option>)}
                  </select>
                </div>
              )
            })}
          </div>
        </section>
      )}

      {!loading && electives.length > 0 && (
        <section className="mb-6">
          <h3 className="font-black text-[15px] mb-2">مواد اختيارية</h3>
          <div className="flex flex-col gap-2">
            {electives.map(course => {
              const key = keyOf(course)
              return (
                <label key={key} className="bg-white border border-primary/12 rounded-[12px] p-3 flex flex-wrap items-center justify-between gap-3">
                  <span className="flex items-center gap-2">
                    <input type="checkbox" checked={Boolean(selectedElectives[key])} onChange={e => setSelectedElectives(prev => ({ ...prev, [key]: e.target.checked }))} />
                    <span>
                      <span className="font-bold text-[13.5px]">{course.course_name}</span>
                      <span className="block text-[12px] text-text-light">{course.program_name}</span>
                    </span>
                  </span>
                  <select value={facultyByCourse[key] ?? ''} onChange={e => setFacultyByCourse(prev => ({ ...prev, [key]: e.target.value }))} className="px-3 py-2 border border-primary/20 rounded-[8px] text-[12.5px] min-w-[180px]">
                    <option value="">اختر المدرس</option>
                    {faculty.map(member => <option key={member.faculty_member_id} value={member.faculty_member_id}>{[member.academic_rank, member.specialization].filter(Boolean).join(' - ') || `مدرس #${member.faculty_member_id}`}</option>)}
                  </select>
                </label>
              )
            })}
          </div>
        </section>
      )}

      {error && <p className="text-red-600 text-[13px] mb-3">{error}</p>}
      {message && <p className="text-green-700 text-[13px] mb-3">{message}</p>}

      <button type="button" onClick={sendProposal} disabled={sending || !yearId || !semesterId || !departmentId || courses.length === 0} className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] font-bold disabled:opacity-40">
        {sending ? <FaSpinner className="animate-spin" /> : <FaPaperPlane />}
        إرسال للنائب العلمي
      </button>
    </div>
  )
}
