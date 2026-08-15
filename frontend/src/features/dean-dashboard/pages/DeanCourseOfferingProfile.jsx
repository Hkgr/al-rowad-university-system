import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaArrowRight, FaBookOpen, FaCalendarAlt, FaSpinner, FaUsers } from 'react-icons/fa'
import { hasPermission, PERMISSIONS } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import { InfoField, SectionTitle } from '../components/DeanStudentRecordPanels'
import DeanCourseSessionsTab from '../components/DeanCourseSessionsTab'
import DeanCourseStudentsTab from '../components/DeanCourseStudentsTab'
import DeanCourseTeachersPanel from '../components/DeanCourseTeachersPanel'
import TeacherAssignmentManagerModal from '../components/TeacherAssignmentManagerModal'
import {
  formatAverageMark,
  offeringCodeName,
  offeringStatusText,
  statusBadgeClass,
} from '../utils/courseOfferingDisplay'
import { displayValue } from '../utils/teacherDisplay'

const TABS = [
  { id: 'overview', ar: 'نظرة عامة', Icon: FaBookOpen },
  { id: 'students', ar: 'الطلاب المسجلون', Icon: FaUsers },
  { id: 'sessions', ar: 'الجلسات', Icon: FaCalendarAlt },
]

const PAGE_SIZE = 15
const SEARCH_DEBOUNCE_MS = 400

function emptyTabState() {
  return {
    loading: false,
    loaded: false,
    error: '',
    rows: [],
    page: 1,
    totalPages: 1,
    includesGrades: false,
  }
}

function paginatedRows(response) {
  return response?.data?.data ?? []
}

function SummaryCard({ label, value }) {
  return (
    <div className="bg-primary/[0.04] border border-primary/12 rounded-[12px] px-3 py-2.5 min-w-0">
      <p className="text-[11px] text-text-light font-semibold">{label}</p>
      <p className="text-[18px] font-black text-text-dark tabular-nums break-words">{value}</p>
    </div>
  )
}

function ErrorState({ message, onBack }) {
  return (
    <div className="flex flex-col items-center justify-center gap-4 py-24" dir="rtl">
      <p className="text-[15px] text-red-600 font-bold text-center px-4">⚠ {message}</p>
      <button
        type="button"
        className="px-5 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors"
        onClick={onBack}
      >
        رجوع إلى المواد
      </button>
    </div>
  )
}

export default function DeanCourseOfferingProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  const canManageTeachers = hasPermission(PERMISSIONS.teachingStaffManage)

  const [offering, setOffering] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [managerOpen, setManagerOpen] = useState(false)
  const [activeTab, setActiveTab] = useState('overview')

  const [students, setStudents] = useState(emptyTabState)
  const [studentSearch, setStudentSearch] = useState('')
  const [appliedStudentSearch, setAppliedStudentSearch] = useState('')
  const [registrationStatus, setRegistrationStatus] = useState('registered')

  const [sessions, setSessions] = useState(emptyTabState)
  const [sessionType, setSessionType] = useState('')
  const studentsQueryRef = useRef('')
  const sessionsQueryRef = useRef('')

  const goBack = useCallback(() => navigate('/dean/courses'), [navigate])
  const goToLogin = useCallback(() => navigate('/login', { replace: true }), [navigate])

  const loadOffering = useCallback(async () => {
    const response = await apiRequest(`/v1/dean/course-offerings/${id}`)
    return response?.data ?? null
  }, [id])

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      setOffering(null)
      setStudents(emptyTabState())
      setSessions(emptyTabState())
      setActiveTab('overview')
      setStudentSearch('')
      setAppliedStudentSearch('')
      setRegistrationStatus('registered')
      setSessionType('')
      setManagerOpen(false)
      studentsQueryRef.current = ''
      sessionsQueryRef.current = ''

      try {
        const payload = await loadOffering()
        if (!active) return
        setOffering(payload)
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          goToLogin()
          return
        }
        if (requestError.status === 403) {
          setError('ليس لديك صلاحية لعرض مواد الكلية.')
          return
        }
        setError('تعذّر الوصول إلى هذه المادة.')
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [goToLogin, id, loadOffering])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setAppliedStudentSearch(studentSearch.trim())
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timeout)
  }, [studentSearch])

  const loadStudents = useCallback(async (page = 1) => {
    setStudents(current => ({ ...current, loading: true, error: '', page }))
    try {
      const params = new URLSearchParams({
        per_page: String(PAGE_SIZE),
        page: String(page),
        registration_status: registrationStatus || 'registered',
      })
      if (appliedStudentSearch) params.set('search', appliedStudentSearch)
      const response = await apiRequest(`/v1/dean/course-offerings/${id}/students?${params.toString()}`)
      setStudents({
        loading: false,
        loaded: true,
        error: '',
        rows: paginatedRows(response),
        page,
        totalPages: Math.max(1, Number(response?.data?.meta?.last_page) || 1),
        includesGrades: Boolean(response?.data?.includes_grades),
      })
      studentsQueryRef.current = `${id}|${appliedStudentSearch}|${registrationStatus || 'registered'}`
    } catch (requestError) {
      if (requestError.status === 401) {
        goToLogin()
        return
      }
      setStudents(current => ({
        ...current,
        loading: false,
        loaded: true,
        error: 'تعذّر تحميل الطلاب المسجلين.',
        rows: [],
      }))
    }
  }, [appliedStudentSearch, goToLogin, id, registrationStatus])

  const loadSessions = useCallback(async (page = 1) => {
    setSessions(current => ({ ...current, loading: true, error: '', page }))
    try {
      const params = new URLSearchParams({
        per_page: String(PAGE_SIZE),
        page: String(page),
      })
      if (sessionType) params.set('session_type', sessionType)
      const response = await apiRequest(`/v1/dean/course-offerings/${id}/sessions?${params.toString()}`)
      setSessions({
        loading: false,
        loaded: true,
        error: '',
        rows: paginatedRows(response),
        page,
        totalPages: Math.max(1, Number(response?.data?.meta?.last_page) || 1),
        includesGrades: false,
      })
      sessionsQueryRef.current = `${id}|${sessionType}`
    } catch (requestError) {
      if (requestError.status === 401) {
        goToLogin()
        return
      }
      setSessions(current => ({
        ...current,
        loading: false,
        loaded: true,
        error: 'تعذّر تحميل الجلسات.',
        rows: [],
      }))
    }
  }, [goToLogin, id, sessionType])

  useEffect(() => {
    if (activeTab !== 'students' || !offering) return
    const queryKey = `${id}|${appliedStudentSearch}|${registrationStatus || 'registered'}`
    if (studentsQueryRef.current === queryKey) return
    loadStudents(1)
  }, [activeTab, appliedStudentSearch, id, loadStudents, offering, registrationStatus])

  useEffect(() => {
    if (activeTab !== 'sessions' || !offering) return
    const queryKey = `${id}|${sessionType}`
    if (sessionsQueryRef.current === queryKey) return
    loadSessions(1)
  }, [activeTab, id, loadSessions, offering, sessionType])

  async function refreshAfterAssignmentSave() {
    try {
      const payload = await loadOffering()
      setOffering(payload)
      setNotice('تم تحديث التكليف التدريسي بنجاح.')
      setManagerOpen(false)
    } catch (requestError) {
      if (requestError.status === 401) {
        goToLogin()
        return
      }
      setNotice('')
    }
  }

  const metrics = offering?.metrics ?? {}
  const resultsSummary = offering?.results_summary
  const averageMark = formatAverageMark(metrics.average_final_mark)
  const gradedCount = metrics.graded_students_count
  const passRate = resultsSummary && Number(resultsSummary.total_students_with_results) > 0
    ? `${resultsSummary.pass_rate}%`
    : null

  const sessionSummary = useMemo(() => ({
    total: Number(metrics.attendance_sessions_count) || 0,
    theoretical: Number(metrics.theoretical_sessions_count) || 0,
    practical: Number(metrics.practical_sessions_count) || 0,
  }), [metrics.attendance_sessions_count, metrics.practical_sessions_count, metrics.theoretical_sessions_count])

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light" dir="rtl">
        <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[14px] font-medium">جاري التحميل…</span>
      </div>
    )
  }

  if (error || !offering) {
    return <ErrorState message={error || 'تعذّر الوصول إلى هذه المادة.'} onBack={goBack} />
  }

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          type="button"
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={goBack}
          aria-label="رجوع إلى المواد"
          title="رجوع إلى المواد"
        >
          <FaArrowRight aria-hidden="true" />
          <span>رجوع إلى المواد</span>
        </button>
      </div>

      {notice && (
        <div className="mb-4 bg-green-500/8 border border-green-500/25 rounded-[12px] px-[18px] py-3 text-[13.5px] text-green-700 font-semibold">
          {notice}
        </div>
      )}

      <motion.div
        className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 mb-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="flex items-start justify-between gap-4 flex-wrap mb-4">
          <div className="min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark break-words">
                {offeringCodeName(offering)}
              </h2>
              <span className={`inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-bold ${statusBadgeClass(offering.status)}`}>
                {offeringStatusText(offering.status)}
              </span>
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span>{displayValue(offering.academic_year?.year_name)}</span>
              <span className="text-primary/30">•</span>
              <span>{displayValue(offering.semester?.semester_name)}</span>
              <span className="text-primary/30">•</span>
              <span className="break-words">{displayValue(offering.academic_program?.program_name)}</span>
              <span className="text-primary/30">•</span>
              <span className="break-words">{displayValue(offering.department?.department_name)}</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <SummaryCard label="الطلاب المسجلون" value={Number(metrics.registered_students_count) || 0} />
          <SummaryCard label="إجمالي الجلسات" value={sessionSummary.total} />
          <SummaryCard label="متوسط العلامة" value={averageMark} />
          <SummaryCard
            label="الطلاب ذوو النتائج"
            value={gradedCount == null ? '—' : gradedCount}
          />
        </div>
        {passRate && (
          <p className="text-[12px] text-text-gray mt-3">
            نسبة النجاح: <span className="font-bold text-text-dark">{passRate}</span>
          </p>
        )}
      </motion.div>

      <div className="bg-white border border-primary/12 rounded-[18px] shadow-[0_2px_16px_rgba(26,46,16,0.06)] overflow-hidden">
        <div className="flex border-b border-primary/10 overflow-x-auto" role="tablist" aria-label="أقسام المادة المطروحة">
          {TABS.map(tab => (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={activeTab === tab.id}
              className={`flex items-center gap-2 px-5 py-3.5 text-[13px] font-bold whitespace-nowrap border-b-2 transition-all duration-[180ms] ${
                activeTab === tab.id
                  ? 'text-primary border-primary bg-primary/[0.04]'
                  : 'text-text-gray border-transparent hover:text-text-dark hover:bg-primary/[0.02]'
              }`}
              onClick={() => setActiveTab(tab.id)}
            >
              <tab.Icon aria-hidden="true" className="text-[12px]" />
              <span>{tab.ar}</span>
            </button>
          ))}
        </div>

        <div className="p-5">
          {activeTab === 'overview' && (
            <div className="space-y-8">
              <DeanCourseTeachersPanel
                offering={offering}
                canManage={canManageTeachers}
                onManage={() => setManagerOpen(true)}
              />

              <div>
                <SectionTitle
                  title="ملخص النتائج"
                  subtitle="عرض إحصائي للقراءة فقط دون تعديل أو اعتماد أو نشر"
                />
                {resultsSummary && Number(resultsSummary.total_students_with_results) > 0 ? (
                  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                    <InfoField label="متوسط العلامة" value={averageMark} />
                    <InfoField label="الطلاب ذوو النتائج" value={resultsSummary.total_students_with_results} />
                    <InfoField label="ناجحون" value={resultsSummary.passed_count} />
                    <InfoField label="راسبون" value={resultsSummary.failed_count} />
                    <InfoField label="محرومون" value={resultsSummary.deprived_count} />
                    <InfoField label="نسبة النجاح" value={`${resultsSummary.pass_rate}%`} />
                  </div>
                ) : (
                  <p className="text-[13.5px] text-text-light">
                    {hasPermission(PERMISSIONS.gradesView)
                      ? 'لا توجد نتائج نهائية متاحة لهذه المادة'
                      : 'تعذّر تحميل ملخص النتائج.'}
                  </p>
                )}
              </div>
            </div>
          )}

          {activeTab === 'students' && (
            <DeanCourseStudentsTab
              loading={students.loading}
              error={students.error}
              rows={students.rows}
              page={students.page}
              totalPages={students.totalPages}
              onPageChange={nextPage => loadStudents(nextPage)}
              search={studentSearch}
              onSearchChange={setStudentSearch}
              registrationStatus={registrationStatus}
              onRegistrationStatusChange={value => setRegistrationStatus(value || 'registered')}
              onClearFilters={() => {
                setStudentSearch('')
                setAppliedStudentSearch('')
                setRegistrationStatus('registered')
              }}
              includesGrades={students.includesGrades}
              onOpenStudent={studentId => navigate(`/dean/students/${studentId}`)}
            />
          )}

          {activeTab === 'sessions' && (
            <DeanCourseSessionsTab
              loading={sessions.loading}
              error={sessions.error}
              rows={sessions.rows}
              page={sessions.page}
              totalPages={sessions.totalPages}
              onPageChange={nextPage => loadSessions(nextPage)}
              sessionType={sessionType}
              onSessionTypeChange={setSessionType}
              onClearFilters={() => setSessionType('')}
              summary={sessionSummary}
            />
          )}
        </div>
      </div>

      {managerOpen && (
        <TeacherAssignmentManagerModal
          mode="manage"
          profileTeacher={null}
          offeringId={offering.course_offering_id}
          onClose={() => setManagerOpen(false)}
          onSaved={refreshAfterAssignmentSave}
          onUnauthorized={goToLogin}
        />
      )}
    </div>
  )
}
