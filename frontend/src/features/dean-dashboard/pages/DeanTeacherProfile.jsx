import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaArrowRight, FaBookOpen, FaCalendarAlt, FaHistory, FaSpinner, FaUser,
} from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { InfoField, SectionTitle } from '../components/DeanStudentRecordPanels'
import TeacherActivityTimeline from '../components/TeacherActivityTimeline'
import TeacherAssignmentsTab from '../components/TeacherAssignmentsTab'
import TeacherSessionsTab from '../components/TeacherSessionsTab'
import {
  academicRankLabel,
  assignmentStatusLabel,
  buildTeacherTimeline,
  displayValue,
  fullTeacherName,
  groupAssignmentsByOffering,
  teacherInitials,
} from '../utils/teacherDisplay'

const TABS = [
  { id: 'info', ar: 'المعلومات', Icon: FaUser },
  { id: 'assignments', ar: 'المواد والتكليفات', Icon: FaBookOpen },
  { id: 'sessions', ar: 'الجلسات', Icon: FaCalendarAlt },
  { id: 'activity', ar: 'السجل والنشاط', Icon: FaHistory },
]

const PAGE_SIZE = 15
const API_PAGE_SIZE = 100

function emptyListState() {
  return {
    loading: false,
    loaded: false,
    error: '',
    rows: [],
  }
}

function paginatedRows(response) {
  return response?.data?.data ?? []
}

async function fetchAllPages(path) {
  const firstResponse = await apiRequest(`${path}${path.includes('?') ? '&' : '?'}per_page=${API_PAGE_SIZE}&page=1`)
  const rows = [...paginatedRows(firstResponse)]
  const lastPage = firstResponse?.data?.meta?.last_page ?? 1

  if (lastPage > 1) {
    const remaining = await Promise.all(
      Array.from(
        { length: lastPage - 1 },
        (_, index) => apiRequest(`${path}${path.includes('?') ? '&' : '?'}per_page=${API_PAGE_SIZE}&page=${index + 2}`),
      ),
    )
    remaining.forEach(response => rows.push(...paginatedRows(response)))
  }

  return rows
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
        رجوع إلى المدرسين
      </button>
    </div>
  )
}

function SummaryChip({ label, value }) {
  return (
    <div className="bg-primary/[0.05] border border-primary/12 rounded-[12px] px-3 py-2 min-w-[108px]">
      <p className="text-[11px] text-text-light font-semibold">{label}</p>
      <p className="text-[16px] font-black text-text-dark tabular-nums">{value}</p>
    </div>
  )
}

export default function DeanTeacherProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [profile, setProfile] = useState(null)
  const [activeTab, setActiveTab] = useState('info')
  const [assignments, setAssignments] = useState(emptyListState())
  const [sessions, setSessions] = useState(emptyListState())
  const [assignmentStatus, setAssignmentStatus] = useState('active')
  const [assignmentRole, setAssignmentRole] = useState('')
  const [assignmentPage, setAssignmentPage] = useState(1)
  const [sessionType, setSessionType] = useState('')
  const [sessionPage, setSessionPage] = useState(1)
  const assignmentsRef = useRef(emptyListState())
  const sessionsRef = useRef(emptyListState())
  const requestSeqRef = useRef(0)

  useEffect(() => {
    let active = true
    const requestSeq = requestSeqRef.current + 1
    requestSeqRef.current = requestSeq
    assignmentsRef.current = emptyListState()
    sessionsRef.current = emptyListState()

    async function loadProfile() {
      setLoading(true)
      setError('')
      setProfile(null)
      setActiveTab('info')
      setAssignments(emptyListState())
      setSessions(emptyListState())
      setAssignmentStatus('active')
      setAssignmentRole('')
      setAssignmentPage(1)
      setSessionType('')
      setSessionPage(1)

      try {
        const response = await apiRequest(`/v1/teaching-staff/${id}`)
        if (!active || requestSeq !== requestSeqRef.current) return
        setProfile(response?.data ?? null)
      } catch (requestError) {
        if (!active || requestSeq !== requestSeqRef.current) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError('تعذّر الوصول إلى ملف هذا المدرس.')
      } finally {
        if (active && requestSeq === requestSeqRef.current) setLoading(false)
      }
    }

    loadProfile()
    return () => { active = false }
  }, [id, navigate])

  async function loadAssignments() {
    if (assignmentsRef.current.loaded || assignmentsRef.current.loading) return
    const requestSeq = requestSeqRef.current
    assignmentsRef.current = { ...assignmentsRef.current, loading: true, error: '' }
    setAssignments(assignmentsRef.current)
    try {
      const rows = await fetchAllPages(`/v1/teaching-staff/${id}/assignments?status=all`)
      if (requestSeq !== requestSeqRef.current) return
      assignmentsRef.current = { loading: false, loaded: true, error: '', rows }
      setAssignments(assignmentsRef.current)
    } catch (requestError) {
      if (requestSeq !== requestSeqRef.current) return
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      assignmentsRef.current = {
        loading: false,
        loaded: true,
        error: 'تعذّر تحميل تكليفات المدرس.',
        rows: [],
      }
      setAssignments(assignmentsRef.current)
    }
  }

  async function loadSessions() {
    if (sessionsRef.current.loaded || sessionsRef.current.loading) return
    const requestSeq = requestSeqRef.current
    sessionsRef.current = { ...sessionsRef.current, loading: true, error: '' }
    setSessions(sessionsRef.current)
    try {
      const rows = await fetchAllPages(`/v1/teaching-staff/${id}/sessions`)
      if (requestSeq !== requestSeqRef.current) return
      sessionsRef.current = { loading: false, loaded: true, error: '', rows }
      setSessions(sessionsRef.current)
    } catch (requestError) {
      if (requestSeq !== requestSeqRef.current) return
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }
      sessionsRef.current = {
        loading: false,
        loaded: true,
        error: 'تعذّر تحميل جلسات المدرس.',
        rows: [],
      }
      setSessions(sessionsRef.current)
    }
  }

  function openTab(tabId) {
    setActiveTab(tabId)
    if (tabId === 'assignments' || tabId === 'activity') loadAssignments()
    if (tabId === 'sessions' || tabId === 'activity') loadSessions()
  }

  const groupedAssignments = useMemo(() => {
    return groupAssignmentsByOffering(assignments.rows).filter(group => {
      const hasActiveSlot = group.slots.some(slot => slot.is_active)
      if (assignmentStatus === 'active' && !hasActiveSlot) return false
      if (assignmentStatus === 'inactive' && hasActiveSlot) return false
      if (assignmentRole === 'theoretical' && !group.theoretical) return false
      if (assignmentRole === 'practical' && !group.practical) return false
      return true
    })
  }, [assignmentRole, assignmentStatus, assignments.rows])
  const assignmentTotalPages = Math.max(1, Math.ceil(groupedAssignments.length / PAGE_SIZE))
  const safeAssignmentPage = Math.min(assignmentPage, assignmentTotalPages)
  const pagedAssignments = groupedAssignments.slice(
    (safeAssignmentPage - 1) * PAGE_SIZE,
    safeAssignmentPage * PAGE_SIZE,
  )

  const filteredSessions = useMemo(() => {
    return sessions.rows.filter(row => {
      if (sessionType && String(row.session_type ?? '').toLowerCase() !== sessionType) return false
      return true
    })
  }, [sessionType, sessions.rows])

  const sessionTotalPages = Math.max(1, Math.ceil(filteredSessions.length / PAGE_SIZE))
  const safeSessionPage = Math.min(sessionPage, sessionTotalPages)
  const pagedSessions = filteredSessions.slice(
    (safeSessionPage - 1) * PAGE_SIZE,
    safeSessionPage * PAGE_SIZE,
  )

  const sessionSummary = useMemo(() => {
    const source = sessions.rows
    return {
      total: source.length,
      theoretical: source.filter(row => {
        const type = String(row.session_type ?? '').toLowerCase()
        return type === 'theoretical' || type === 'lecture'
      }).length,
      practical: source.filter(row => String(row.session_type ?? '').toLowerCase() === 'practical').length,
    }
  }, [sessions.rows])

  const timelineEvents = useMemo(
    () => buildTeacherTimeline(assignments.rows, sessions.rows),
    [assignments.rows, sessions.rows],
  )

  const goBack = () => navigate('/dean/teachers')

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light" dir="rtl">
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[14px] font-medium">جاري تحميل ملف المدرس…</span>
      </div>
    )
  }

  if (error || !profile) {
    return <ErrorState message={error || 'تعذّر الوصول إلى ملف هذا المدرس.'} onBack={goBack} />
  }

  const name = fullTeacherName(profile)
  const collegeLabel = (profile.colleges ?? [])
    .map(college => [college.college_name, college.college_code].filter(Boolean).join(' — '))
    .filter(Boolean)
    .join('، ') || '—'
  const employeeStatus = profile.employee?.employee_status?.status_name
    || (profile.is_active ? 'نشط' : 'غير نشط')

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          type="button"
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={goBack}
          aria-label="رجوع إلى المدرسين"
          title="رجوع إلى المدرسين"
        >
          <FaArrowRight aria-hidden="true" />
          <span>رجوع إلى المدرسين</span>
        </button>
      </div>

      <motion.div
        className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 mb-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="flex items-center gap-5 flex-wrap">
          <div className="w-[68px] h-[68px] flex-shrink-0 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-[20px] font-black text-primary">
            {teacherInitials(name)}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark break-words">{name}</h2>
              <span className={`inline-block px-2.5 py-[3px] rounded-full text-[11.5px] font-bold ${
                profile.is_active ? 'bg-green-500/10 text-green-700' : 'bg-slate-500/10 text-slate-600'
              }`}
              >
                {assignmentStatusLabel(profile.is_active)}
              </span>
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span className="font-mono bg-primary/7 border border-primary/15 px-2 py-0.5 rounded-[6px] text-primary-dark font-bold text-[12px]">
                {displayValue(profile.employee?.employee_number)}
              </span>
              <span className="text-primary/30">•</span>
              <span>{academicRankLabel(profile.academic_rank)}</span>
              {profile.specialization && (
                <>
                  <span className="text-primary/30">•</span>
                  <span className="break-words">{profile.specialization}</span>
                </>
              )}
              <span className="text-primary/30">•</span>
              <span className="break-words">{collegeLabel}</span>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
          <SummaryChip label="المواد الحالية" value={profile.active_course_count ?? 0} />
          <SummaryChip label="التكليفات النظرية" value={profile.theoretical_assignment_count ?? 0} />
          <SummaryChip label="التكليفات العملية" value={profile.practical_assignment_count ?? 0} />
          <SummaryChip label="إجمالي التكليفات" value={profile.active_assignment_count ?? 0} />
        </div>
      </motion.div>

      <div className="bg-white border border-primary/12 rounded-[18px] shadow-[0_2px_16px_rgba(26,46,16,0.06)] overflow-hidden">
        <div className="flex border-b border-primary/10 overflow-x-auto" role="tablist" aria-label="أقسام ملف المدرس">
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
              onClick={() => openTab(tab.id)}
            >
              <tab.Icon aria-hidden="true" className="text-[12px]" />
              <span>{tab.ar}</span>
            </button>
          ))}
        </div>

        <div className="p-5">
          {activeTab === 'info' && (
            <div>
              <SectionTitle title="معلومات المدرس" subtitle="بيانات الهوية والعضوية الأكاديمية المتاحة للكلية" />
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                <InfoField label="الاسم" value={name} />
                <InfoField label="الرقم الوظيفي" value={profile.employee?.employee_number} />
                <InfoField label="الرتبة الأكاديمية" value={academicRankLabel(profile.academic_rank)} />
                <InfoField label="الاختصاص" value={profile.specialization} />
                <InfoField label="المكتب" value={profile.office_location} />
                <InfoField label="حالة المدرس" value={profile.is_active ? 'نشط' : 'غير نشط'} />
                <InfoField label="حالة الموظف" value={employeeStatus} />
                <InfoField label="البريد الإلكتروني" value={profile.employee?.email} />
                <InfoField label="الهاتف" value={profile.employee?.phone_number} />
                <InfoField label="الكلية" value={collegeLabel} />
              </div>
            </div>
          )}

          {activeTab === 'assignments' && (
            <TeacherAssignmentsTab
              loading={assignments.loading}
              error={assignments.error}
              rows={pagedAssignments}
              page={safeAssignmentPage}
              totalPages={assignmentTotalPages}
              onPageChange={setAssignmentPage}
              status={assignmentStatus}
              role={assignmentRole}
              onStatusChange={value => {
                setAssignmentStatus(value)
                setAssignmentPage(1)
              }}
              onRoleChange={value => {
                setAssignmentRole(value)
                setAssignmentPage(1)
              }}
              onClearFilters={() => {
                setAssignmentStatus('active')
                setAssignmentRole('')
                setAssignmentPage(1)
              }}
            />
          )}

          {activeTab === 'sessions' && (
            <TeacherSessionsTab
              loading={sessions.loading}
              error={sessions.error}
              rows={pagedSessions}
              page={safeSessionPage}
              totalPages={sessionTotalPages}
              onPageChange={setSessionPage}
              sessionType={sessionType}
              onSessionTypeChange={value => {
                setSessionType(value)
                setSessionPage(1)
              }}
              onClearFilters={() => {
                setSessionType('')
                setSessionPage(1)
              }}
              summary={sessionSummary}
            />
          )}

          {activeTab === 'activity' && (
            <>
              {(assignments.error || sessions.error) && timelineEvents.length > 0 && (
                <p className="text-[13px] text-amber-700 mb-3">
                  ⚠ {assignments.error || sessions.error}
                </p>
              )}
              <TeacherActivityTimeline
                loading={assignments.loading || sessions.loading}
                error={timelineEvents.length === 0 ? (assignments.error || sessions.error) : ''}
                events={timelineEvents}
              />
            </>
          )}
        </div>
      </div>
    </div>
  )
}
