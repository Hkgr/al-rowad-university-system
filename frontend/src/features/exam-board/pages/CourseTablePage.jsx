import { useState, useEffect, useMemo } from 'react'
import { FaSpinner, FaDownload, FaTable } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { exportRowsToPdf } from '../../../utils/pdfExport'
import InstructorAssignment from '../components/InstructorAssignment'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import CourseRequirementBadges, { classificationPlainText } from '../../../components/academic/CourseRequirementBadges'

const API = 'https://rust.alrowaduni.edu.sy/api/v1'

function authHeaders() {
  return { Authorization: `Bearer ${localStorage.getItem('token')}`, Accept: 'application/json' }
}

async function get(url) {
  const r = await fetch(url, { headers: authHeaders() })
  return r.json()
}

const TYPE_LABEL = { mandatory: 'إجباري', elective: 'اختياري' }

export default function CourseTablePage() {
  const canManage = hasPermission(PERMISSIONS.coursesManage)
  const canViewHr = hasPermission(PERMISSIONS.hrView)
  const [years, setYears]             = useState([])
  const [semesters, setSemesters]     = useState([])
  const [colleges, setColleges]       = useState([])
  const [departments, setDepartments] = useState([])
  const [programs, setPrograms]       = useState([])
  const [courses, setCourses]         = useState([])
  const [levels, setLevels]           = useState([])
  const [programCourses, setProgramCourses] = useState([])
  const [offerings, setOfferings]     = useState([])
  const [facultyMembers, setFacultyMembers] = useState([])
  const [employees, setEmployees]     = useState([])
  const [loading, setLoading]         = useState(true)
  const [pdfLoading, setPdfLoading]   = useState(false)

  const [yearId, setYearId]           = useState('')
  const [semId, setSemId]             = useState('')
  const [collegeId, setCollegeId]     = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [programId, setProgramId]     = useState('')

  const [search, setSearch]           = useState('')
  const [levelFilter, setLevelFilter] = useState('')
  const [typeFilter, setTypeFilter]   = useState('')


  useEffect(() => {
    Promise.all([
      get(`${API}/academic-years?per_page=50`),
      get(`${API}/semesters?per_page=20`),
      get(`${API}/colleges?per_page=20`),
      get(`${API}/departments?per_page=50`),
      get(`${API}/academic-programs?per_page=50`),
      get(`${API}/courses?per_page=200`),
      get(`${API}/academic-levels?per_page=20`),
      get(`${API}/program-courses?per_page=500`),
      get(`${API}/course-offerings?per_page=500`),
      canViewHr ? get(`${API}/faculty-members?per_page=100`) : Promise.resolve({ success: true, data: [] }),
      canViewHr ? get(`${API}/employees?per_page=500`) : Promise.resolve({ success: true, data: [] }),
    ]).then(([y, s, col, dep, prog, crs, lvl, pc, off, fac, emp]) => {
      setYears(y.success ? (y.data?.data ?? []) : [])
      setSemesters(s.success ? (s.data?.data ?? []) : [])
      setColleges(col.success ? (col.data?.data ?? []) : [])
      setDepartments(dep.success ? (dep.data?.data ?? []) : [])
      setPrograms(prog.success ? (prog.data?.data ?? []) : [])
      setCourses(crs.success ? (crs.data?.data ?? []) : [])
      setLevels(lvl.success ? (lvl.data?.data ?? []) : [])
      setProgramCourses(pc.success ? (pc.data?.data ?? []) : [])
      setOfferings(off.success ? (off.data?.data ?? []) : [])
      setFacultyMembers(fac.success ? (fac.data?.data ?? fac.data ?? []) : [])
      setEmployees(emp.success ? (emp.data?.data ?? emp.data ?? []) : [])
    }).finally(() => setLoading(false))
  }, [canViewHr])

  const courseMap = useMemo(() => Object.fromEntries(courses.map(c => [c.course_id, c])), [courses])
  const programMap = useMemo(() => Object.fromEntries(programs.map(p => [p.academic_program_id, p])), [programs])

  // FacultyMemberResource doesn't embed the employee relation, so names are joined
  // client-side against /employees by employee_id — same join used in Course Offerings.
  const facultyOptions = useMemo(() => {
    const employeeMap = Object.fromEntries(employees.map(e => [e.employee_id, e]))
    return facultyMembers
      .filter(f => f.is_active)
      .map(f => ({ ...f, employee: employeeMap[f.employee_id] }))
  }, [facultyMembers, employees])

  function handleInstructorUpdated(offering, { faculty_member_id, faculty_member }) {
    setOfferings(prev => prev.map(o => o.course_offering_id === offering.course_offering_id ? { ...o, faculty_member_id, faculty_member } : o))
  }

  const activeLevels = useMemo(
    () => [...levels].filter(l => l.is_active).sort((a, b) => a.level_order - b.level_order),
    [levels]
  )

  const departmentsInCollege = useMemo(
    () => departments.filter(d => String(d.college_id) === String(collegeId)),
    [departments, collegeId]
  )
  const programOptions = useMemo(() => {
    if (departmentId) return programs.filter(p => String(p.department_id) === String(departmentId))
    if (collegeId) {
      const deptIds = new Set(departmentsInCollege.map(d => d.department_id))
      return programs.filter(p => deptIds.has(p.department_id))
    }
    return []
  }, [programs, departmentId, collegeId, departmentsInCollege])

  // Which programs fall inside the employee's current filter selection —
  // narrows from "whole college" down to "one department" down to "one specific اختصاص".
  const scopedProgramIds = useMemo(() => {
    if (programId) return [Number(programId)]
    if (departmentId) return programs.filter(p => String(p.department_id) === String(departmentId)).map(p => p.academic_program_id)
    if (collegeId) {
      const deptIds = new Set(departmentsInCollege.map(d => d.department_id))
      return programs.filter(p => deptIds.has(p.department_id)).map(p => p.academic_program_id)
    }
    return []
  }, [programId, departmentId, collegeId, programs, departmentsInCollege])

  function offeringFor(courseId, progId) {
    return offerings.find(o =>
      String(o.course_id) === String(courseId) &&
      String(o.academic_program_id) === String(progId) &&
      String(o.academic_year_id) === String(yearId) &&
      String(o.semester_id) === String(semId)
    )
  }

  const rows = useMemo(() => {
    if (scopedProgramIds.length === 0 || !semId) return []
    const scopedSet = new Set(scopedProgramIds.map(String))
    return programCourses
      .filter(pc => scopedSet.has(String(pc.academic_program_id)) && String(pc.recommended_semester_id) === String(semId))
      .map(pc => {
        const course = courseMap[pc.course_id]
        const program = programMap[pc.academic_program_id]
        const level = activeLevels.find(l => l.academic_level_id === pc.academic_level_id)
        const offering = offeringFor(pc.course_id, pc.academic_program_id)
        return {
          key: `${pc.program_course_id}`,
          levelId: pc.academic_level_id,
          levelName: level?.level_name ?? '—',
          levelOrder: level?.level_order ?? 0,
          programName: program?.program_name ?? '—',
          courseCode: course?.course_code ?? '—',
          courseName: course?.course_name ?? '—',
          creditHours: course?.credit_hours,
          courseType: pc.course_type,
          requirement_classification: pc.requirement_classification,
          offering,
        }
      })
      .sort((a, b) => a.levelOrder - b.levelOrder || a.programName.localeCompare(b.programName) || a.courseCode.localeCompare(b.courseCode))
      // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [scopedProgramIds, semId, programCourses, courseMap, programMap, activeLevels, offerings, yearId])

  const filteredRows = useMemo(() => {
    const q = search.trim().toLowerCase()
    return rows.filter(r => {
      if (levelFilter && String(r.levelId) !== String(levelFilter)) return false
      if (typeFilter && r.courseType !== typeFilter) return false
      if (q && !(r.courseCode.toLowerCase().includes(q) || r.courseName.toLowerCase().includes(q) || r.programName.toLowerCase().includes(q))) return false
      return true
    })
  }, [rows, search, levelFilter, typeFilter])

  const hasFilters = !!(search || levelFilter || typeFilter)
  function clearFilters() { setSearch(''); setLevelFilter(''); setTypeFilter('') }

  function handleCollegeChange(v) { setCollegeId(v); setDepartmentId(''); setProgramId('') }
  function handleDepartmentChange(v) { setDepartmentId(v); setProgramId('') }

  function offeringStatusLabel(offering) {
    if (!offering) return { label: 'لم تُفتح', className: 'bg-gray-100 text-text-light' }
    if (offering.status === 'open') return { label: `مفتوحة (${offering.available_seats}/${offering.capacity})`, className: 'bg-green-100 text-green-700' }
    return { label: 'مغلقة', className: 'bg-gray-200 text-gray-600' }
  }

  function instructorNameFor(offering) {
    if (!offering?.faculty_member_id) return '—'
    const f = facultyOptions.find(fm => fm.faculty_member_id === offering.faculty_member_id)
    if (!f) return '—'
    return `${f.employee?.first_name ?? ''} ${f.employee?.last_name ?? ''}`.trim() || '—'
  }

  async function handleDownloadPdf() {
    setPdfLoading(true)
    try {
      const scopeName = programId
        ? programMap[programId]?.program_name
        : departmentId
          ? departments.find(d => String(d.department_id) === String(departmentId))?.department_name
          : colleges.find(c => String(c.college_id) === String(collegeId))?.college_name
      await exportRowsToPdf({
        title: 'جدول المواد الدراسية',
        subtitle: `${scopeName || ''} — ${filteredRows.length} مادة`,
        columns: [
          { header: 'المستوى', value: r => r.levelName },
          { header: 'البرنامج / التخصص', value: r => r.programName },
          { header: 'رمز المادة', value: r => r.courseCode },
          { header: 'اسم المادة', value: r => r.courseName },
          { header: 'الساعات المعتمدة', value: r => r.creditHours },
          { header: 'التصنيف الأكاديمي', value: r => classificationPlainText(r.requirement_classification) || (TYPE_LABEL[r.courseType] ?? r.courseType) },
          { header: 'حالة الفتح', value: r => offeringStatusLabel(r.offering).label },
          { header: 'الأستاذ', value: r => instructorNameFor(r.offering) },
        ],
        rows: filteredRows,
        filename: 'جدول_المواد.pdf',
      })
    } finally {
      setPdfLoading(false)
    }
  }

  if (loading) {
    return <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
  }

  return (
    <>
      <div className="mb-5" dir="rtl">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">جدول المواد الدراسية</h2>
        <p className="text-[12.5px] text-text-light">Course Table — Curriculum by College, Department &amp; Program</p>
      </div>

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
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="grid grid-cols-3 max-[820px]:grid-cols-1 gap-4" dir="rtl">
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
                <label className="text-[12px] font-bold text-text-dark">القسم <span className="font-normal text-text-light">(اختياري)</span></label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
                  value={departmentId}
                  onChange={e => handleDepartmentChange(e.target.value)}
                  disabled={!collegeId}
                  dir="rtl"
                >
                  <option value="">كل الأقسام</option>
                  {departmentsInCollege.map(d => <option key={d.department_id} value={d.department_id}>{d.department_name}</option>)}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <label className="text-[12px] font-bold text-text-dark">البرنامج / الاختصاص <span className="font-normal text-text-light">(اختياري)</span></label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
                  value={programId}
                  onChange={e => setProgramId(e.target.value)}
                  disabled={!collegeId}
                  dir="rtl"
                >
                  <option value="">كل البرامج</option>
                  {programOptions.map(p => <option key={p.academic_program_id} value={p.academic_program_id}>{p.program_name}</option>)}
                </select>
              </div>
            </div>
          </div>

          {!collegeId ? (
            <p className="text-center text-[13px] text-text-light py-8" dir="rtl">اختر الكلية على الأقل لعرض جدول المواد</p>
          ) : (
            <>
              <div className="flex items-center justify-between gap-3 flex-wrap mb-3" dir="rtl">
                <div className="flex-1 min-w-[240px]">
                  <FilterBar
                    search={{ value: search, onChange: setSearch, placeholder: 'ابحث برمز المادة أو اسمها أو البرنامج…' }}
                    filters={[
                      {
                        key: 'level', value: levelFilter, onChange: setLevelFilter, placeholder: 'كل المستويات', minWidth: 140,
                        options: activeLevels.map(l => ({ value: l.academic_level_id, label: l.level_name })),
                      },
                      {
                        key: 'type', value: typeFilter, onChange: setTypeFilter, placeholder: 'كل الأنواع', minWidth: 120,
                        options: [{ value: 'mandatory', label: 'إجباري' }, { value: 'elective', label: 'اختياري' }],
                      },
                    ]}
                    hasActiveFilters={hasFilters}
                    onClear={clearFilters}
                  />
                </div>
                <button
                  type="button"
                  onClick={handleDownloadPdf}
                  disabled={pdfLoading || filteredRows.length === 0}
                  className="flex items-center gap-2 px-4 py-2.5 bg-white border border-primary/25 text-primary-dark rounded-[12px] text-[13.5px] font-bold whitespace-nowrap transition-all duration-[220ms] hover:bg-primary/6 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {pdfLoading ? <FaSpinner className="animate-spin text-[12px]" /> : <FaDownload className="text-[12px]" />}
                  <span>{pdfLoading ? 'جارٍ التجهيز…' : 'تنزيل'}</span>
                </button>
              </div>

              <DataTable
                columns={[
                  { key: 'level', header: 'المستوى', align: 'center', render: r => r.levelName },
                  { key: 'program', header: 'البرنامج / التخصص', render: r => r.programName },
                  { key: 'code', header: 'رمز المادة', render: r => <span className="font-mono text-primary-dark font-bold">{r.courseCode}</span> },
                  { key: 'name', header: 'اسم المادة', render: r => r.courseName },
                  { key: 'hours', header: 'الساعات المعتمدة', align: 'center', render: r => r.creditHours ?? '—' },
                  {
                    key: 'classification',
                    header: 'التصنيف الأكاديمي',
                    render: r => <CourseRequirementBadges classification={r.requirement_classification} compact />,
                  },
                  {
                    key: 'status', header: 'حالة الفتح', align: 'center', render: r => {
                      const s = offeringStatusLabel(r.offering)
                      return <span className={`inline-block text-[10.5px] font-bold px-2 py-0.5 rounded-full ${s.className}`}>{s.label}</span>
                    },
                  },
                  {
                    key: 'instructor', header: 'الأستاذ', align: 'center', render: r => (
                      r.offering ? (
                        <InstructorAssignment
                          offering={r.offering}
                          facultyOptions={facultyOptions}
                          onUpdated={handleInstructorUpdated}
                          readOnly={!canManage || !canViewHr}
                        />
                      ) : <span className="text-text-light">—</span>
                    ),
                  },
                ]}
                rows={filteredRows}
                rowKey={r => r.key}
                loading={false}
                emptyIcon={FaTable}
                emptyTitle="لا توجد مواد ضمن هذا الاختيار"
                emptySubtitle="جرّب تغيير الكلية أو القسم أو الفصل الدراسي"
                hasFilters={hasFilters}
                onClearFilters={clearFilters}
              />
            </>
          )}
        </>
      )}
    </>
  )
}
