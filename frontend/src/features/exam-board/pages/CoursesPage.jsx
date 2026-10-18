import { useCallback, useState, useEffect, useMemo } from 'react'
import { FaSpinner, FaPlus, FaEdit, FaTrash, FaCheck, FaTimes, FaBook, FaStar, FaRegStar, FaLayerGroup } from 'react-icons/fa'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import CourseRequirementBadges, { ProgramRequirementClassifications } from '../../../components/academic/CourseRequirementBadges'
import { apiRequest } from '../../../services/apiClient'
import {
  buildCourseCollegeMap,
  fetchAllPaginated,
  filterCoursesByAffiliation,
  planDepartmentLinkSync,
} from '../lib/courseCatalog'

const EMPTY_FORM = {
  course_code: '', course_name: '', credit_hours: '', theoretical_hours: '', practical_hours: '', description: '', is_active: true,
}

function requestErrorMessage(error, fallback) {
  const details = error?.details
  if (details && typeof details === 'object') {
    const messages = Object.values(details).flat().filter(Boolean)
    if (messages.length > 0) return messages.join(' | ')
  }

  return error?.message || fallback
}

function CourseForm({ initial, onSave, onCancel, saving, colleges, departments, initialDepartmentIds }) {
  const [form, setForm] = useState(initial ?? EMPTY_FORM)
  const set = (k, v) => setForm(p => ({ ...p, [k]: v }))
  const isEdit = !!initial

  const [scope, setScope] = useState((initialDepartmentIds?.length ?? 0) > 0 ? 'specific' : 'unlinked')
  const [selectedDeptIds, setSelectedDeptIds] = useState(new Set((initialDepartmentIds ?? []).map(String)))
  function toggleDept(id) {
    setSelectedDeptIds(prev => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id); else next.add(id)
      return next
    })
  }

  const departmentsByCollege = useMemo(() => {
    const map = {}
    departments.forEach(d => {
      const key = String(d.college_id)
      if (!map[key]) map[key] = []
      map[key].push(d)
    })
    return map
  }, [departments])

  return (
    <div className="bg-white border border-primary/20 rounded-[16px] p-5 mb-5 shadow-[0_2px_12px_rgba(26,46,16,0.08)]">
      <h3 className="text-[14px] font-extrabold text-text-dark mb-4" dir="rtl">
        {isEdit ? 'تعديل المادة' : 'إضافة مادة جديدة'}
      </h3>
      <div className="grid grid-cols-2 max-[640px]:grid-cols-1 gap-4 mb-4" dir="rtl">
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">رمز المادة <span className="text-red-500">*</span></label>
          <input
            type="text" placeholder="e.g. STAT101"
            value={form.course_code}
            onChange={e => set('course_code', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">اسم المادة <span className="text-red-500">*</span></label>
          <input
            type="text" placeholder="اسم المادة"
            value={form.course_name}
            onChange={e => set('course_name', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="rtl"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">الساعات المعتمدة <span className="text-red-500">*</span></label>
          <input
            type="number" min="1" max="12" placeholder="3"
            value={form.credit_hours}
            onChange={e => set('credit_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">الساعات النظرية</label>
          <input
            type="number" min="0" max="12" placeholder="2"
            value={form.theoretical_hours}
            onChange={e => set('theoretical_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5">
          <label className="text-[11.5px] font-bold text-text-dark">الساعات العملية</label>
          <input
            type="number" min="0" max="12" placeholder="1"
            value={form.practical_hours}
            onChange={e => set('practical_hours', e.target.value)}
            className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary"
            dir="ltr"
          />
        </div>
        <div className="flex flex-col gap-1.5 justify-end">
          <label className="text-[11.5px] font-bold text-text-dark">الحالة</label>
          <div className="flex gap-4" dir="rtl">
            <label className="flex items-center gap-2 cursor-pointer text-[13px]">
              <input type="radio" name="is_active" checked={form.is_active === true} onChange={() => set('is_active', true)} />
              <span className="text-green-700 font-semibold">فعّال</span>
            </label>
            <label className="flex items-center gap-2 cursor-pointer text-[13px]">
              <input type="radio" name="is_active" checked={form.is_active === false} onChange={() => set('is_active', false)} />
              <span className="text-text-light font-semibold">غير فعّال</span>
            </label>
          </div>
        </div>
      </div>
      <div className="flex flex-col gap-1.5 mb-5" dir="rtl">
        <label className="text-[11.5px] font-bold text-text-dark">الوصف</label>
        <textarea
          rows={2} placeholder="وصف اختياري للمادة"
          value={form.description || ''}
          onChange={e => set('description', e.target.value)}
          className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] outline-none focus:border-primary resize-none"
          dir="rtl"
        />
      </div>
      <div className="flex flex-col gap-1.5 mb-5" dir="rtl">
        <label className="text-[11.5px] font-bold text-text-dark">نطاق المادة</label>
        <div className="flex gap-4 mb-2">
          <label className="flex items-center gap-2 cursor-pointer text-[13px]">
            <input type="radio" name="scope" checked={scope === 'unlinked'} onChange={() => setScope('unlinked')} />
            <span className="font-semibold text-text-dark">غير مرتبطة بقسم</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer text-[13px]">
            <input type="radio" name="scope" checked={scope === 'specific'} onChange={() => setScope('specific')} />
            <span className="font-semibold text-text-dark">خاصة بأقسام محددة</span>
          </label>
        </div>
        {scope === 'specific' && (
          <div className="border border-primary/15 rounded-[10px] p-3 max-h-[220px] overflow-y-auto bg-[#fafaf8]">
            {colleges.length === 0 ? (
              <p className="text-[12px] text-text-light">لا توجد كليات</p>
            ) : (
              colleges.map(col => {
                const depts = departmentsByCollege[String(col.college_id)] || []
                if (depts.length === 0) return null
                return (
                  <div key={col.college_id} className="mb-2.5 last:mb-0">
                    <div className="text-[11.5px] font-bold text-primary-dark mb-1">{col.college_name}</div>
                    <div className="flex flex-wrap gap-x-4 gap-y-1.5 pr-2">
                      {depts.map(d => (
                        <label key={d.department_id} className="flex items-center gap-1.5 cursor-pointer text-[12.5px]">
                          <input
                            type="checkbox"
                            checked={selectedDeptIds.has(String(d.department_id))}
                            onChange={() => toggleDept(String(d.department_id))}
                          />
                          <span className="text-text-dark">{d.department_name}</span>
                        </label>
                      ))}
                    </div>
                  </div>
                )
              })
            )}
            {selectedDeptIds.size === 0 && (
              <p className="text-[11.5px] text-amber-600 mt-1">اختر قسمًا واحدًا على الأقل</p>
            )}
          </div>
        )}
      </div>
      <div className="flex gap-3" dir="rtl">
        <button
          onClick={() => onSave(form, { scope, departmentIds: [...selectedDeptIds] })}
          disabled={saving || !form.course_code.trim() || !form.course_name.trim() || !form.credit_hours || (scope === 'specific' && selectedDeptIds.size === 0)}
          className="flex items-center gap-2 px-5 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold disabled:opacity-40 hover:enabled:bg-primary-dark transition-colors"
        >
          {saving ? <FaSpinner className="animate-spin text-[11px]" /> : <FaCheck className="text-[11px]" />}
          {isEdit ? 'حفظ التعديلات' : 'إضافة المادة'}
        </button>
        <button
          onClick={onCancel}
          className="flex items-center gap-2 px-5 py-2.5 border border-primary/20 text-text-gray rounded-[10px] text-[13px] font-bold hover:bg-gray-50 transition-colors"
        >
          <FaTimes className="text-[11px]" /> إلغاء
        </button>
      </div>
    </div>
  )
}

export default function CoursesPage() {
  const canManage = hasPermission(PERMISSIONS.coursesManage)
  const [courses,     setCourses]     = useState([])
  const [colleges,    setColleges]    = useState([])
  const [departments, setDepartments] = useState([])
  const [assignments, setAssignments] = useState([])   // course_departments rows
  const [loading,     setLoading]     = useState(true)
  const [catalogReady, setCatalogReady] = useState(false)
  const [catalogError, setCatalogError] = useState('')
  const [activeTab,   setActiveTab]   = useState('all') // 'all' | college_id | 'unlinked'
  const [mode,        setMode]        = useState(null)  // null | 'add' | course object
  const [saving,      setSaving]      = useState(false)
  const [deleting,    setDeleting]    = useState({})
  const [err,         setErr]         = useState('')
  const [success,     setSuccess]     = useState('')

  const [viewTab,     setViewTab]     = useState('courses') // 'courses' | 'byDepartment'
  const [dvCollegeId, setDvCollegeId] = useState('')
  const [dvDeptId,    setDvDeptId]    = useState('')
  const [dvSaving,    setDvSaving]    = useState({})
  const [dvRemoving,  setDvRemoving]  = useState({})
  const [dvErr,       setDvErr]       = useState('')

  const loadAll = useCallback(async () => {
    setLoading(true)
    setCatalogReady(false)
    setCatalogError('')
    try {
      const [nextCourses, nextColleges, nextDepartments, nextAssignments] = await Promise.all([
        fetchAllPaginated('/v1/courses', { request: apiRequest, primaryKey: 'course_id' }),
        fetchAllPaginated('/v1/colleges', { request: apiRequest, primaryKey: 'college_id' }),
        fetchAllPaginated('/v1/departments', { request: apiRequest, primaryKey: 'department_id' }),
        fetchAllPaginated('/v1/course-departments', { request: apiRequest, primaryKey: 'course_department_id' }),
      ])

      buildCourseCollegeMap(nextCourses, nextDepartments, nextAssignments)
      setCourses(nextCourses)
      setColleges(nextColleges)
      setDepartments(nextDepartments)
      setAssignments(nextAssignments)
      setCatalogReady(true)

      return true
    } catch (error) {
      setCourses([])
      setColleges([])
      setDepartments([])
      setAssignments([])
      setCatalogError(`تعذر تحميل كتالوج المواد كاملاً. ${error?.message || 'حاول مرة أخرى.'}`)

      return false
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void loadAll() }, [loadAll])

  // Map: course_id → Set of college_ids
  const courseCollegeMap = useMemo(() => {
    if (!catalogReady) return new Map()
    return buildCourseCollegeMap(courses, departments, assignments)
  }, [assignments, catalogReady, courses, departments])

  const filteredCourses = useMemo(
    () => filterCoursesByAffiliation(courses, courseCollegeMap, activeTab),
    [courses, courseCollegeMap, activeTab]
  )

  // Count per tab
  const unlinkedCount = filterCoursesByAffiliation(courses, courseCollegeMap, 'unlinked').length

  function flash(msg) {
    setSuccess(msg)
    setTimeout(() => setSuccess(''), 3000)
  }

  async function syncDepartmentLinks(courseId, { scope, departmentIds }) {
    if (!canManage) return
    const current    = assignments.filter(a => a.course_id === courseId)
    const desiredIds = scope === 'specific' ? departmentIds.map(String) : []
    const plan = planDepartmentLinkSync(current, desiredIds)
    const assignmentsByDepartment = new Map(current.map(assignment => [
      String(assignment.department_id),
      assignment,
    ]))

    for (const departmentId of plan.toAdd) {
      const response = await apiRequest('/v1/course-departments', {
        method: 'POST',
        body: JSON.stringify({
          course_id: courseId,
          department_id: Number(departmentId),
          is_primary: false,
        }),
      })
      assignmentsByDepartment.set(departmentId, response.data)
    }
    for (const assignment of plan.toRemove) {
      await apiRequest(`/v1/course-departments/${assignment.course_department_id}`, { method: 'DELETE' })
    }
    if (plan.promoteDepartmentId !== null) {
      const primary = assignmentsByDepartment.get(plan.promoteDepartmentId)
      if (!primary?.course_department_id) throw new Error('تعذر تحديد رابط القسم الرئيسي بعد الحفظ.')
      await apiRequest(`/v1/course-departments/${primary.course_department_id}`, {
        method: 'PUT',
        body: JSON.stringify({ is_primary: true }),
      })
    }
  }

  // ── Department-centric bulk assignment view ──────────────────────────────
  const dvDepartments = useMemo(
    () => departments.filter(d => String(d.college_id) === String(dvCollegeId)),
    [departments, dvCollegeId]
  )
  const dvAssigned          = assignments.filter(a => String(a.department_id) === String(dvDeptId))
  const dvAssignedCourseIds = new Set(dvAssigned.map(a => a.course_id))
  const dvUnassigned        = courses.filter(c => !dvAssignedCourseIds.has(c.course_id))

  function handleDvCollegeChange(cId) {
    setDvCollegeId(cId); setDvDeptId(''); setDvErr('')
  }

  async function handleDvAdd(courseId) {
    if (!canManage) return
    setDvSaving(p => ({ ...p, [courseId]: true })); setDvErr('')
    try {
      const departmentIds = assignments
        .filter(assignment => assignment.course_id === courseId)
        .map(assignment => assignment.department_id)
      await syncDepartmentLinks(courseId, {
        scope: 'specific',
        departmentIds: [...departmentIds, dvDeptId],
      })
      if (!await loadAll()) setDvErr('تم تنفيذ الإضافة، لكن تعذر التحقق من الكتالوج بعد العملية.')
    } catch (error) {
      await loadAll()
      setDvErr(requestErrorMessage(error, 'فشلت إضافة المادة إلى القسم.'))
    }
    finally { setDvSaving(p => ({ ...p, [courseId]: false })) }
  }

  async function handleDvRemove(assignmentId) {
    if (!canManage) return
    setDvRemoving(p => ({ ...p, [assignmentId]: true })); setDvErr('')
    try {
      const target = assignments.find(assignment => assignment.course_department_id === assignmentId)
      if (!target) throw new Error('تعذر العثور على رابط القسم المطلوب حذفه.')
      const departmentIds = assignments
        .filter(assignment => (
          assignment.course_id === target.course_id
          && assignment.course_department_id !== assignmentId
        ))
        .map(assignment => assignment.department_id)
      await syncDepartmentLinks(target.course_id, {
        scope: departmentIds.length > 0 ? 'specific' : 'unlinked',
        departmentIds,
      })
      if (!await loadAll()) setDvErr('تم تنفيذ الحذف، لكن تعذر التحقق من الكتالوج بعد العملية.')
    } catch (error) {
      await loadAll()
      setDvErr(requestErrorMessage(error, 'فشل حذف رابط القسم.'))
    }
    finally { setDvRemoving(p => ({ ...p, [assignmentId]: false })) }
  }

  async function handleSave(form, deptSelection) {
    if (!canManage) { setErr('ليس لديك صلاحية إدارة المواد'); return }
    setSaving(true); setErr('')
    const isEdit = mode !== 'add'
    const url    = isEdit ? `/v1/courses/${mode.course_id}` : '/v1/courses'
    const method = isEdit ? 'PUT' : 'POST'
    let coursePersisted = false
    try {
      const response = await apiRequest(url, {
        method,
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
      coursePersisted = true
      const courseId = isEdit ? mode.course_id : response.data.course_id
      await syncDepartmentLinks(courseId, deptSelection)
      if (!await loadAll()) {
        setMode(null)
        setErr('تم حفظ المادة، لكن تعذر التحقق من الكتالوج بعد العملية.')
        return
      }
      setMode(null)
      flash(isEdit ? 'تم تعديل المادة بنجاح' : 'تمت إضافة المادة بنجاح')
    } catch (error) {
      await loadAll()
      if (coursePersisted) setMode(null)
      setErr(requestErrorMessage(error, 'فشلت عملية حفظ المادة.'))
    }
    finally { setSaving(false) }
  }

  async function handleDelete(course) {
    if (!canManage) { setErr('ليس لديك صلاحية إدارة المواد'); return }
    if (!window.confirm(`حذف المادة "${course.course_name}"؟`)) return
    setDeleting(p => ({ ...p, [course.course_id]: true })); setErr('')
    try {
      await apiRequest(`/v1/courses/${course.course_id}`, { method: 'DELETE' })
      if (!await loadAll()) {
        setErr('تم حذف المادة، لكن تعذر التحقق من الكتالوج بعد العملية.')
        return
      }
      flash('تم حذف المادة')
    } catch (error) {
      await loadAll()
      setErr(requestErrorMessage(error, 'فشل حذف المادة.'))
    }
    finally { setDeleting(p => ({ ...p, [course.course_id]: false })) }
  }

  return (
    <>
      <div className="flex items-start justify-between mb-5" dir="rtl">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">إدارة المواد الدراسية</h2>
          <p className="text-[12.5px] text-text-light">Courses Management</p>
        </div>
        {canManage && viewTab === 'courses' && mode === null && (
          <button
            onClick={() => { setMode('add'); setErr(''); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
            className="flex items-center gap-2 px-4 py-2.5 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
          >
            <FaPlus className="text-[11px]" /> إضافة مادة
          </button>
        )}
      </div>

      {/* Page-level view switch: course-centric list/edit vs. department-centric bulk assignment */}
      <div className="flex gap-1.5 mb-5 p-1.5 bg-gray-100 rounded-[12px] w-fit" dir="rtl">
        <button
          onClick={() => setViewTab('courses')}
          className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-[9px] text-[12.5px] font-bold transition-all whitespace-nowrap ${
            viewTab === 'courses' ? 'bg-white text-primary shadow-sm' : 'text-text-gray hover:text-text-dark'
          }`}
        >
          <FaBook className="text-[11px]" /> المواد الدراسية
        </button>
        <button
          onClick={() => setViewTab('byDepartment')}
          className={`flex items-center gap-1.5 px-3.5 py-1.5 rounded-[9px] text-[12.5px] font-bold transition-all whitespace-nowrap ${
            viewTab === 'byDepartment' ? 'bg-white text-primary shadow-sm' : 'text-text-gray hover:text-text-dark'
          }`}
        >
          <FaLayerGroup className="text-[11px]" /> عرض حسب القسم
        </button>
      </div>

      {err && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]" dir="rtl">⚠ {err}</p>
      )}
      {success && (
        <p className="mb-4 px-4 py-2.5 text-[12.5px] text-green-700 bg-green-50 border border-green-200 rounded-[10px]" dir="rtl">✓ {success}</p>
      )}

      {/* Add / Edit form */}
      {canManage && catalogReady && viewTab === 'courses' && mode === 'add' && (
        <CourseForm
          onSave={handleSave}
          onCancel={() => { setMode(null); setErr('') }}
          saving={saving}
          colleges={colleges}
          departments={departments}
        />
      )}
      {canManage && catalogReady && viewTab === 'courses' && mode !== null && mode !== 'add' && (
        <CourseForm
          initial={mode}
          onSave={handleSave}
          onCancel={() => { setMode(null); setErr('') }}
          saving={saving}
          colleges={colleges}
          departments={departments}
          initialDepartmentIds={assignments.filter(a => a.course_id === mode.course_id).map(a => a.department_id)}
        />
      )}

      {loading ? (
        <div className="flex justify-center py-16 text-primary"><FaSpinner className="animate-spin text-[28px]" /></div>
      ) : !catalogReady ? (
        <div className="flex flex-col items-center gap-3 rounded-[14px] border border-red-200 bg-red-50 px-5 py-12 text-center" dir="rtl">
          <p className="text-[13px] font-bold text-red-700">{catalogError || 'تعذر تحميل كتالوج المواد كاملاً.'}</p>
          <p className="text-[12px] text-red-600">لن تُعرض تصنيفات أو أعداد جزئية على أنها بيانات معتمدة.</p>
          <button
            type="button"
            onClick={() => void loadAll()}
            className="rounded-[9px] border border-red-300 bg-white px-4 py-2 text-[12px] font-bold text-red-700 hover:bg-red-100"
          >
            إعادة المحاولة
          </button>
        </div>
      ) : viewTab === 'courses' ? (
        <>
          {/* College filter tabs */}
          <div className="flex gap-1.5 flex-wrap mb-4 p-1.5 bg-gray-100 rounded-[12px] w-fit" dir="rtl">
            <TabBtn label="الكل" count={courses.length}   active={activeTab === 'all'}    onClick={() => setActiveTab('all')} />
            {colleges.map(col => {
              const cnt = courses.filter(c => courseCollegeMap.get(String(c.course_id))?.has(String(col.college_id))).length
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
            <TabBtn label="غير مرتبطة بقسم" count={unlinkedCount} active={activeTab === 'unlinked'} onClick={() => setActiveTab('unlinked')} />
          </div>

          {/* Unlinked courses note */}
          {activeTab === 'unlinked' && unlinkedCount > 0 && (
            <div className="mb-4 px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-[10px] text-[12px] text-amber-800" dir="rtl">
              هذه المواد لا تملك رابطاً بقسم في قاعدة البيانات، ولا يعني ذلك أنها مشتركة بين جميع الكليات.
              يمكنك ربطها بالأقسام من تبويب <strong>عرض حسب القسم</strong> أعلاه.
            </div>
          )}

          {filteredCourses.length === 0 ? (
            <div className="flex flex-col items-center py-20 gap-3">
              <FaBook className="text-[48px] text-primary/15" />
              <p className="text-[13px] text-text-light" dir="rtl">
                {activeTab === 'all'
                  ? 'لا توجد مواد دراسية.'
                  : activeTab === 'unlinked'
                    ? 'لا توجد مواد غير مرتبطة بقسم.'
                    : 'لا توجد مواد مرتبطة بهذه الكلية في قاعدة البيانات.'}
              </p>
            </div>
          ) : (
            <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
              <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10" dir="rtl">
                <span className="text-[13px] font-extrabold text-text-dark">{filteredCourses.length} مادة</span>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[13px]">
                  <thead>
                    <tr className="bg-[#fafaf8]">
                      <th className="px-4 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">المادة</th>
                      <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">التصنيف الأكاديمي</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">معتمدة</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">نظري</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">عملي</th>
                      <th className="px-3 py-2.5 text-right text-[11px] font-bold text-text-light" dir="rtl">الكليات</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">الحالة</th>
                      <th className="px-3 py-2.5 text-center text-[11px] font-bold text-text-light">إجراءات</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredCourses.map(c => {
                      const collegeIds   = courseCollegeMap.get(String(c.course_id))
                      const courseColleges = collegeIds
                        ? colleges.filter(col => collegeIds.has(String(col.college_id)))
                        : []
                      return (
                        <tr key={c.course_id} className="border-t border-primary/6 hover:bg-primary/[0.02] transition-colors">
                          <td className="px-4 py-3" dir="rtl">
                            <div className="font-semibold text-[13px] text-text-dark">{c.course_name}</div>
                            <div className="text-[11px] text-text-light font-mono mt-0.5">{c.course_code}</div>
                          </td>
                          <td className="px-3 py-3" dir="rtl">
                            <ProgramRequirementClassifications items={c.program_requirement_classifications} />
                          </td>
                          <td className="px-3 py-3 text-center font-bold text-text-dark">{c.credit_hours}</td>
                          <td className="px-3 py-3 text-center text-text-dark">{c.theoretical_hours ?? '—'}</td>
                          <td className="px-3 py-3 text-center text-text-dark">{c.practical_hours ?? '—'}</td>
                          <td className="px-3 py-3" dir="rtl">
                            {courseColleges.length === 0 ? (
                              <span className="inline-block px-2 py-0.5 bg-amber-100 text-amber-700 text-[10px] font-bold rounded-full">غير مرتبطة بقسم</span>
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
                              ? <span className="inline-block px-2 py-0.5 bg-green-100 text-green-700 text-[10.5px] font-bold rounded-full">فعّال</span>
                              : <span className="inline-block px-2 py-0.5 bg-gray-100 text-text-light text-[10.5px] font-bold rounded-full">غير فعّال</span>
                            }
                          </td>
                          <td className="px-3 py-3 text-center">
                            {canManage && <div className="flex items-center justify-center gap-2">
                              <button
                                onClick={() => { setMode(c); setErr(''); window.scrollTo({ top: 0, behavior: 'smooth' }) }}
                                className="flex items-center gap-1 px-2.5 py-1.5 border border-primary/25 text-primary rounded-[7px] text-[11px] font-bold hover:bg-primary/[0.05] transition-colors"
                              >
                                <FaEdit className="text-[10px]" /> تعديل
                              </button>
                              <button
                                onClick={() => handleDelete(c)}
                                disabled={!!deleting[c.course_id]}
                                className="flex items-center gap-1 px-2.5 py-1.5 border border-red-300 text-red-600 rounded-[7px] text-[11px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors"
                              >
                                {deleting[c.course_id] ? <FaSpinner className="animate-spin text-[10px]" /> : <FaTrash className="text-[10px]" />}
                                حذف
                              </button>
                            </div>}
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
      ) : (
        <>
          {/* Department-centric bulk assignment view */}
          <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
            <div className="grid grid-cols-2 max-[600px]:grid-cols-1 gap-4" dir="rtl">
              <div className="flex flex-col gap-1.5">
                <label className="text-[12px] font-bold text-text-dark">الكلية</label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary"
                  value={dvCollegeId}
                  onChange={e => handleDvCollegeChange(e.target.value)}
                  dir="rtl"
                >
                  <option value="">اختر الكلية</option>
                  {colleges.map(c => <option key={c.college_id} value={c.college_id}>{c.college_name}</option>)}
                </select>
              </div>
              <div className="flex flex-col gap-1.5">
                <label className="text-[12px] font-bold text-text-dark">القسم</label>
                <select
                  className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13.5px] text-text-dark outline-none focus:border-primary disabled:opacity-50"
                  value={dvDeptId}
                  onChange={e => { setDvDeptId(e.target.value); setDvErr('') }}
                  disabled={!dvCollegeId}
                  dir="rtl"
                >
                  <option value="">اختر القسم</option>
                  {dvDepartments.map(d => <option key={d.department_id} value={d.department_id}>{d.department_name}</option>)}
                </select>
              </div>
            </div>
          </div>

          {dvErr && (
            <p className="mb-4 px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]" dir="rtl">⚠ {dvErr}</p>
          )}

          {dvDeptId && (
            <div className="grid grid-cols-2 max-[800px]:grid-cols-1 gap-5">

              {/* Left: assigned courses */}
              <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
                  <span className="text-[13px] font-extrabold text-text-dark">المواد المضافة</span>
                  <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{dvAssigned.length}</span>
                </div>

                {dvAssigned.length === 0 ? (
                  <div className="flex flex-col items-center py-14 gap-3">
                    <FaBook className="text-[36px] text-primary/15" />
                    <p className="text-[12px] text-text-light" dir="rtl">لا توجد مواد مضافة لهذا القسم</p>
                  </div>
                ) : (
                  <div className="divide-y divide-primary/6">
                    {dvAssigned.map(a => {
                      const course = courses.find(c => c.course_id === a.course_id)
                      return (
                        <div key={a.course_department_id} className="flex items-center justify-between gap-3 px-5 py-3.5" dir="rtl">
                          <div className="flex-1 min-w-0">
                            <div className="font-semibold text-[13px] text-text-dark truncate">
                              {course?.course_name || `مادة #${a.course_id}`}
                            </div>
                            <div className="flex items-center gap-2 mt-0.5">
                              <span className="text-[11px] text-text-light font-mono">{course?.course_code}</span>
                              {a.is_primary
                                ? <span className="inline-flex items-center gap-1 text-[10px] text-amber-600 font-bold"><FaStar className="text-[9px]" /> رئيسي</span>
                                : <span className="inline-flex items-center gap-1 text-[10px] text-text-light"><FaRegStar className="text-[9px]" /> ثانوي</span>
                              }
                            </div>
                            <div className="mt-1">
                              <ProgramRequirementClassifications items={course?.program_requirement_classifications} />
                            </div>
                          </div>
                          {canManage && <button
                            onClick={() => handleDvRemove(a.course_department_id)}
                            disabled={!!dvRemoving[a.course_department_id]}
                            className="flex items-center gap-1.5 px-3 py-1.5 border border-red-300 text-red-600 rounded-[7px] text-[11.5px] font-bold hover:bg-red-50 disabled:opacity-40 transition-colors flex-shrink-0"
                          >
                            {dvRemoving[a.course_department_id]
                              ? <FaSpinner className="animate-spin text-[10px]" />
                              : <FaTimes className="text-[10px]" />}
                            حذف
                          </button>}
                        </div>
                      )
                    })}
                  </div>
                )}
              </div>

              {/* Right: available courses to add */}
              <div className="bg-white border border-primary/12 rounded-[16px] overflow-hidden shadow-[0_2px_10px_rgba(26,46,16,0.05)]">
                <div className="px-5 py-3 bg-primary/[0.05] border-b border-primary/10 flex items-center gap-2" dir="rtl">
                  <span className="text-[13px] font-extrabold text-text-dark">مواد متاحة للإضافة</span>
                  <span className="text-[11px] text-text-light bg-primary/10 px-2 py-0.5 rounded-full font-bold">{dvUnassigned.length}</span>
                </div>

                {dvUnassigned.length === 0 ? (
                  <p className="text-center text-[12px] text-text-light py-14" dir="rtl">جميع المواد مضافة لهذا القسم</p>
                ) : (
                  <div className="divide-y divide-primary/6 max-h-[520px] overflow-y-auto">
                    {dvUnassigned.map(c => (
                      <div key={c.course_id} className="flex items-center justify-between gap-3 px-5 py-3.5" dir="rtl">
                        <div className="flex-1 min-w-0">
                          <div className="font-semibold text-[13px] text-text-dark truncate">{c.course_name}</div>
                          <div className="text-[11px] text-text-light font-mono">{c.course_code}</div>
                          <div className="mt-1">
                            <ProgramRequirementClassifications items={c.program_requirement_classifications} />
                          </div>
                        </div>
                        {canManage && <button
                          onClick={() => handleDvAdd(c.course_id)}
                          disabled={!!dvSaving[c.course_id]}
                          className="flex items-center gap-1.5 px-3 py-1.5 bg-primary text-white rounded-[7px] text-[11.5px] font-bold hover:bg-primary-dark disabled:opacity-40 transition-colors flex-shrink-0"
                        >
                          {dvSaving[c.course_id]
                            ? <FaSpinner className="animate-spin text-[10px]" />
                            : <FaPlus className="text-[10px]" />}
                          إضافة
                        </button>}
                      </div>
                    ))}
                  </div>
                )}
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
