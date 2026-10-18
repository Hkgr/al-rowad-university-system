import { useCallback, useEffect, useMemo, useState } from 'react'
import { FaBookOpen, FaSpinner } from 'react-icons/fa'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'
import { apiRequest } from '../../../services/apiClient'
import {
  instructorCoverageSummary,
  instructorRoleTeacherName,
} from '../../dean-dashboard/utils/courseOfferingDisplay'
import { displayValue, offeringStatusLabel } from '../../dean-dashboard/utils/teacherDisplay'
import {
  actualCourseOfferingRows,
  filterActualCourseOfferings,
  loadCourseOfferingsCatalog,
} from '../lib/courseCatalog'

const EMPTY_CATALOG = Object.freeze({
  academicYears: [],
  semesters: [],
  colleges: [],
  departments: [],
  programs: [],
  levels: [],
  programCourses: [],
  offerings: [],
})

function statusClass(status) {
  if (status === 'open') return 'bg-green-500/10 text-green-700 border-green-500/20'
  if (status === 'closed') return 'bg-slate-500/10 text-slate-600 border-slate-500/20'
  if (status === 'cancelled') return 'bg-red-500/10 text-red-700 border-red-500/20'
  return 'bg-primary/8 text-primary-dark border-primary/15'
}

function OfferingCard({ row }) {
  const offering = row.offering
  const advisory = row.advisory
  const coverage = offering.instructor_coverage
  const requiredRoles = coverage?.required_roles ?? []
  const theoreticalName = instructorRoleTeacherName(coverage, 'theoretical')
  const practicalName = instructorRoleTeacherName(coverage, 'practical')

  return (
    <article className="bg-white border border-primary/12 rounded-[15px] p-4 shadow-[0_2px_10px_rgba(26,46,16,0.05)]" dir="rtl">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="font-mono text-[13px] font-black text-primary-dark">{displayValue(offering.course?.course_code)}</p>
          <h3 className="text-[14px] font-extrabold text-text-dark mt-0.5 break-words">{displayValue(offering.course?.course_name)}</h3>
          <p className="text-[11.5px] text-text-light mt-1">{displayValue(offering.academic_program?.program_name)}</p>
        </div>
        <span className={`shrink-0 text-[10.5px] font-bold px-2 py-0.5 rounded-full border ${statusClass(offering.status)}`}>
          {offeringStatusLabel(offering.status)}
        </span>
      </div>

      <div className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-[11.5px]">
        <p><span className="text-text-light">السنة الفعلية: </span><b className="text-text-dark">{displayValue(offering.academic_year?.year_name)}</b></p>
        <p><span className="text-text-light">الفصل الفعلي: </span><b className="text-text-dark">{displayValue(offering.semester?.semester_name)}</b></p>
        <p><span className="text-text-light">السعة: </span><b className="text-text-dark">{displayValue(offering.capacity)}</b></p>
        <p><span className="text-text-light">المقاعد المتاحة: </span><b className="text-text-dark">{displayValue(offering.available_seats)}</b></p>
      </div>

      <div className="mt-3 border-t border-primary/8 pt-3 space-y-1 text-[11.5px] text-text-light">
        <p>المستوى الإرشادي: <b className="text-text-dark">{advisory.academic_level_name || 'غير محدد'}</b></p>
        <p>الفصل الإرشادي: <b className="text-text-dark">{advisory.recommended_semester_name || 'غير محدد'}</b></p>
        {advisory.requirement_classification ? (
          <div className="pt-1"><CourseRequirementBadges classification={advisory.requirement_classification} compact /></div>
        ) : null}
      </div>

      <div className="mt-3 rounded-[10px] bg-primary/[0.04] border border-primary/10 px-3 py-2 text-[11.5px]">
        <p className="font-bold text-text-dark">تغطية المدرسين: {instructorCoverageSummary(coverage)}</p>
        {requiredRoles.includes('theoretical') ? <p className="text-text-light mt-1">النظري: {theoreticalName || 'غير محدد'}</p> : null}
        {requiredRoles.includes('practical') ? <p className="text-text-light mt-0.5">العملي: {practicalName || 'غير محدد'}</p> : null}
      </div>
    </article>
  )
}

export default function CourseOfferingsPage() {
  const [catalog, setCatalog] = useState(EMPTY_CATALOG)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [yearId, setYearId] = useState('')
  const [semesterId, setSemesterId] = useState('')
  const [collegeId, setCollegeId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [programId, setProgramId] = useState('')
  const [search, setSearch] = useState('')

  const loadCatalog = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const snapshot = await loadCourseOfferingsCatalog({ request: apiRequest })
      setCatalog(snapshot)
      setYearId(current => current || String(
        snapshot.academicYears.find(year => year.is_current === true || year.is_current === 1)?.academic_year_id ?? '',
      ))
    } catch (requestError) {
      setCatalog(EMPTY_CATALOG)
      setError(`تعذر تحميل كتالوج الطروحات كاملاً، لذلك لن تُعرض بيانات جزئية. ${requestError?.message || 'حاول مرة أخرى.'}`)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void loadCatalog() }, [loadCatalog])

  const departments = useMemo(() => (
    collegeId
      ? catalog.departments.filter(department => String(department.college_id) === String(collegeId))
      : catalog.departments
  ), [catalog.departments, collegeId])

  const programs = useMemo(() => catalog.programs.filter(program => {
    if (departmentId && String(program.department_id) !== String(departmentId)) return false
    if (!collegeId) return true
    const department = catalog.departments.find(item => String(item.department_id) === String(program.department_id))
    return String(department?.college_id ?? '') === String(collegeId)
  }), [catalog.departments, catalog.programs, collegeId, departmentId])

  const projectedRows = useMemo(() => actualCourseOfferingRows(
    catalog.offerings,
    catalog.programCourses,
    catalog.levels,
    catalog.semesters,
  ), [catalog.levels, catalog.offerings, catalog.programCourses, catalog.semesters])

  const visibleRows = useMemo(() => {
    if (!yearId || !semesterId) return []
    const filtered = filterActualCourseOfferings(projectedRows, {
      academicYearId: yearId,
      semesterId,
      collegeId,
      departmentId,
      academicProgramId: programId,
    })
    const needle = search.trim().toLowerCase()
    if (!needle) return filtered
    return filtered.filter(({ offering }) => (
      String(offering.course?.course_code ?? '').toLowerCase().includes(needle)
      || String(offering.course?.course_name ?? '').toLowerCase().includes(needle)
      || String(offering.academic_program?.program_name ?? '').toLowerCase().includes(needle)
    ))
  }, [collegeId, departmentId, programId, projectedRows, search, semesterId, yearId])

  if (loading) {
    return <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
  }

  if (error) {
    return (
      <div className="bg-white border border-red-200 rounded-[16px] p-6 text-center" dir="rtl">
        <p className="text-[13.5px] font-semibold text-red-700">{error}</p>
        <button type="button" className="mt-4 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold" onClick={() => { void loadCatalog() }}>
          إعادة المحاولة
        </button>
      </div>
    )
  }

  return (
    <div dir="rtl">
      <div className="mb-5">
        <h2 className="text-[20px] font-black text-text-dark mb-[3px]">الطروحات الأكاديمية</h2>
        <p className="text-[12.5px] text-text-light">
          عرض الطروحات الفعلية للمواد حسب السنة والفصل والبرنامج. تجهيز وفتح الطروحات يتم من بوابة عميد الكلية.
        </p>
      </div>

      <section className="bg-white border border-primary/12 rounded-[16px] p-4 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
          <label className="flex flex-col gap-1.5">
            <span className="text-[12px] font-bold text-text-dark">السنة الأكاديمية الفعلية</span>
            <select className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]" value={yearId} onChange={event => setYearId(event.target.value)}>
              <option value="">اختر السنة</option>
              {catalog.academicYears.map(year => <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1.5">
            <span className="text-[12px] font-bold text-text-dark">الفصل الأكاديمي الفعلي</span>
            <select className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]" value={semesterId} onChange={event => setSemesterId(event.target.value)}>
              <option value="">اختر الفصل</option>
              {catalog.semesters.map(semester => <option key={semester.semester_id} value={semester.semester_id}>{semester.semester_name}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1.5">
            <span className="text-[12px] font-bold text-text-dark">الكلية</span>
            <select className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]" value={collegeId} onChange={event => { setCollegeId(event.target.value); setDepartmentId(''); setProgramId('') }}>
              <option value="">كل الكليات</option>
              {catalog.colleges.map(college => <option key={college.college_id} value={college.college_id}>{college.college_name}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1.5">
            <span className="text-[12px] font-bold text-text-dark">القسم</span>
            <select className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]" value={departmentId} onChange={event => { setDepartmentId(event.target.value); setProgramId('') }}>
              <option value="">كل الأقسام</option>
              {departments.map(department => <option key={department.department_id} value={department.department_id}>{department.department_name}</option>)}
            </select>
          </label>
          <label className="flex flex-col gap-1.5">
            <span className="text-[12px] font-bold text-text-dark">البرنامج</span>
            <select className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]" value={programId} onChange={event => setProgramId(event.target.value)}>
              <option value="">كل البرامج</option>
              {programs.map(program => <option key={program.academic_program_id} value={program.academic_program_id}>{program.program_name}</option>)}
            </select>
          </label>
        </div>
        <input
          type="search"
          className="mt-3 w-full px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px]"
          placeholder="ابحث باسم المادة أو رمزها أو البرنامج"
          value={search}
          onChange={event => setSearch(event.target.value)}
        />
      </section>

      {!yearId || !semesterId ? (
        <p className="text-center text-[13px] text-text-light py-10">اختر السنة والفصل الأكاديميين الفعليين لعرض الطروحات.</p>
      ) : visibleRows.length === 0 ? (
        <div className="bg-white border border-primary/12 rounded-[16px] px-5 py-10 text-center">
          <FaBookOpen className="mx-auto text-[24px] text-primary/45 mb-2" />
          <p className="text-[13.5px] text-text-light">لا توجد طروحات فعلية مطابقة للفلاتر المحددة.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {visibleRows.map(row => <OfferingCard key={row.offering.course_offering_id} row={row} />)}
        </div>
      )}
    </div>
  )
}
