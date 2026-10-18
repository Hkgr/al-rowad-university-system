import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { motion } from 'framer-motion'
import {
  FaArrowRight, FaCalendarCheck, FaChartBar, FaFolderOpen, FaGraduationCap, FaListAlt, FaSpinner, FaUser,
} from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import TranscriptPdfExportAction from '../../academic-record/components/TranscriptPdfExportAction'
import StudentStatusBadge from '../components/StudentStatusBadge'
import {
  AttendanceTab,
  DocumentsTab,
  GpaTab,
  InfoTab,
  RegistrationsTab,
  TranscriptTab,
} from '../components/DeanStudentRecordPanels'
import { fetchAuthorizedBlob } from '../utils/authorizedDownload'
import {
  academicLevelLabel,
  fullStudentName,
  studentStatusLabel,
} from '../utils/studentDisplay'

const TABS = [
  { id: 'info', ar: 'المعلومات الشخصية', Icon: FaUser },
  { id: 'registrations', ar: 'التسجيلات والمقررات', Icon: FaListAlt },
  { id: 'transcript', ar: 'كشف الدرجات', Icon: FaGraduationCap },
  { id: 'gpa', ar: 'المعدل', Icon: FaChartBar },
  { id: 'attendance', ar: 'الحضور والغياب', Icon: FaCalendarCheck },
  { id: 'documents', ar: 'ملفات الطالب', Icon: FaFolderOpen },
]

const PAGE_SIZE = 15

function emptyTabState() {
  return {
    loading: false,
    loaded: false,
    error: '',
    data: null,
    page: 1,
    totalPages: 1,
  }
}

function paginatedPayload(response) {
  const rows = response?.data?.data ?? []
  const lastPage = response?.data?.meta?.last_page
    ?? response?.data?.last_page
    ?? 1
  return {
    rows: Array.isArray(rows) ? rows : [],
    totalPages: Math.max(1, Number(lastPage) || 1),
  }
}

function tabErrorMessage(tabId, status) {
  if (status === 403) {
    return {
      info: 'لا تملك صلاحية لعرض ملف هذا الطالب.',
      registrations: 'تعذر تحميل بيانات التسجيلات.',
      transcript: 'تعذر تحميل كشف الدرجات.',
      gpa: 'تعذر تحميل بيانات المعدل.',
      attendance: 'تعذر تحميل بيانات الحضور.',
      documents: 'تعذر تحميل ملفات الطالب.',
    }[tabId]
  }

  return {
    registrations: 'تعذر تحميل بيانات التسجيلات.',
    transcript: 'تعذر تحميل كشف الدرجات.',
    gpa: 'تعذر تحميل بيانات المعدل.',
    attendance: 'تعذر تحميل بيانات الحضور.',
    documents: 'تعذر تحميل ملفات الطالب.',
  }[tabId] || 'تعذّر تحميل البيانات.'
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
        العودة إلى طلاب الكلية
      </button>
    </div>
  )
}

export default function DeanStudentProfile() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [profile, setProfile] = useState(null)
  const [activeTab, setActiveTab] = useState('info')
  const [photoUrl, setPhotoUrl] = useState(null)
  const [tabs, setTabs] = useState({
    registrations: emptyTabState(),
    transcript: emptyTabState(),
    gpa: emptyTabState(),
    attendance: emptyTabState(),
    documents: emptyTabState(),
  })
  const photoObjectUrlRef = useRef(null)

  function clearPhoto() {
    if (photoObjectUrlRef.current) {
      URL.revokeObjectURL(photoObjectUrlRef.current)
      photoObjectUrlRef.current = null
    }
    setPhotoUrl(null)
  }

  useEffect(() => {
    let active = true

    async function loadProfile() {
      setLoading(true)
      setError('')
      setProfile(null)
      setActiveTab('info')
      setTabs({
        registrations: emptyTabState(),
        transcript: emptyTabState(),
        gpa: emptyTabState(),
        attendance: emptyTabState(),
        documents: emptyTabState(),
      })
      clearPhoto()

      try {
        const response = await apiRequest(`/v1/students/${id}/profile`)
        if (!active) return
        setProfile(response?.data ?? null)
      } catch (requestError) {
        if (!active) return

        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }

        if (requestError.status === 403) {
          setError('لا تملك صلاحية لعرض ملف هذا الطالب.')
          return
        }

        if (requestError.status === 404) {
          setError('لم يتم العثور على الطالب.')
          return
        }

        setError('تعذّر تحميل ملف الطالب. يرجى المحاولة مرة أخرى.')
      } finally {
        if (active) setLoading(false)
      }
    }

    loadProfile()
    return () => {
      active = false
      clearPhoto()
    }
  }, [id, navigate])

  async function loadDocumentsPage(page = 1, { forPhoto = false } = {}) {
    setTabs(current => ({
      ...current,
      documents: {
        ...current.documents,
        loading: true,
        error: '',
        page,
      },
    }))

    try {
      const response = await apiRequest(`/v1/students/${id}/documents?per_page=${PAGE_SIZE}&page=${page}`)
      const { rows, totalPages } = paginatedPayload(response)

      setTabs(current => ({
        ...current,
        documents: {
          loading: false,
          loaded: true,
          error: '',
          data: rows,
          page,
          totalPages,
        },
      }))

      if (forPhoto || page === 1) {
        const photoDoc = rows.find(doc => doc.document_type?.type_code === 'personal_photo')
        if (photoDoc?.download_url) {
          try {
            const blob = await fetchAuthorizedBlob(photoDoc.download_url)
            if (blob.type.startsWith('image/')) {
              if (photoObjectUrlRef.current) URL.revokeObjectURL(photoObjectUrlRef.current)
              const objectUrl = URL.createObjectURL(blob)
              photoObjectUrlRef.current = objectUrl
              setPhotoUrl(objectUrl)
            }
          } catch {
            // Keep generic avatar if authorized photo download is unavailable.
          }
        }
      }
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }

      setTabs(current => ({
        ...current,
        documents: {
          ...current.documents,
          loading: false,
          loaded: true,
          error: tabErrorMessage('documents', requestError.status),
          data: [],
          page,
          totalPages: 1,
        },
      }))
    }
  }

  async function loadTab(tabId, page = 1) {
    if (tabId === 'info') return

    if (tabId === 'documents') {
      const current = tabs.documents
      if (current.loaded && current.page === page && !current.error) return
      await loadDocumentsPage(page)
      return
    }

    const current = tabs[tabId]
    if (!current) return
    if (current.loaded && (tabId !== 'registrations' || current.page === page) && !current.error) {
      return
    }

    setTabs(state => ({
      ...state,
      [tabId]: {
        ...state[tabId],
        loading: true,
        error: '',
        page: tabId === 'registrations' ? page : state[tabId].page,
      },
    }))

    try {
      let nextState = emptyTabState()

      if (tabId === 'registrations') {
        const response = await apiRequest(`/v1/students/${id}/registrations?per_page=${PAGE_SIZE}&page=${page}`)
        const { rows, totalPages } = paginatedPayload(response)
        nextState = {
          loading: false,
          loaded: true,
          error: '',
          data: rows,
          page,
          totalPages,
        }
      } else if (tabId === 'transcript') {
        const response = await apiRequest(`/v1/students/${id}/transcript`)
        nextState = {
          loading: false,
          loaded: true,
          error: '',
          data: response?.data ?? null,
          page: 1,
          totalPages: 1,
        }
      } else if (tabId === 'gpa') {
        const response = await apiRequest(`/v1/students/${id}/cgpa`)
        nextState = {
          loading: false,
          loaded: true,
          error: '',
          data: response?.data ?? null,
          page: 1,
          totalPages: 1,
        }
      } else if (tabId === 'attendance') {
        const response = await apiRequest(`/v1/students/${id}/attendance`)
        nextState = {
          loading: false,
          loaded: true,
          error: '',
          data: response?.data ?? null,
          page: 1,
          totalPages: 1,
        }
      }

      setTabs(state => ({ ...state, [tabId]: nextState }))
    } catch (requestError) {
      if (requestError.status === 401) {
        navigate('/login', { replace: true })
        return
      }

      setTabs(state => ({
        ...state,
        [tabId]: {
          ...state[tabId],
          loading: false,
          loaded: true,
          error: tabErrorMessage(tabId, requestError.status),
          data: tabId === 'registrations' ? [] : null,
          page: tabId === 'registrations' ? page : 1,
          totalPages: 1,
        },
      }))
    }
  }

  useEffect(() => {
    if (!profile) return undefined
    // Soft-load documents once for optional avatar viewing; also primes the documents tab cache.
    loadDocumentsPage(1, { forPhoto: true })
    return undefined
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [profile, id])

  useEffect(() => {
    if (!profile || activeTab === 'info') return
    loadTab(activeTab)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab, profile, id])

  const goBack = () => navigate('/dean/students')

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-24 text-primary-light" dir="rtl">
        <FaSpinner className="text-[30px] animate-[spin_0.7s_linear_infinite]" aria-hidden="true" />
        <span className="text-[14px] font-medium">جاري تحميل ملف الطالب…</span>
      </div>
    )
  }

  if (error || !profile) {
    return <ErrorState message={error || 'لم يتم العثور على الطالب.'} onBack={goBack} />
  }

  const levelLabel = academicLevelLabel(profile.academic_level)
  const programName = profile.program?.program_name
  const name = fullStudentName(profile)
  const statusLabel = studentStatusLabel(profile)
  const enrichedProfile = {
    ...profile,
    _levelLabel: levelLabel,
    _statusLabel: statusLabel,
  }

  return (
    <div dir="rtl">
      <div className="flex items-center justify-between mb-5 gap-3 flex-wrap">
        <button
          type="button"
          className="flex items-center gap-2 text-[13.5px] font-semibold text-text-gray hover:text-primary transition-colors"
          onClick={goBack}
        >
          <FaArrowRight aria-hidden="true" />
          <span>العودة إلى طلاب الكلية</span>
        </button>
      </div>

      <motion.div
        className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 mb-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]"
        initial={{ opacity: 0, y: -12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.35 }}
      >
        <div className="flex items-center gap-5 flex-wrap">
          <div className="w-[68px] h-[68px] flex-shrink-0 rounded-full bg-primary/10 border-2 border-primary/20 flex items-center justify-center text-[28px] text-primary overflow-hidden">
            {photoUrl
              ? <img src={photoUrl} alt={name} className="w-full h-full object-cover" />
              : <FaUser aria-hidden="true" />}
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-3 flex-wrap mb-1">
              <h2 className="text-[20px] font-black text-text-dark break-words">{name}</h2>
              <StudentStatusBadge status={profile.student_status} />
            </div>
            <div className="flex items-center gap-2.5 flex-wrap text-[12.5px] text-text-gray">
              <span className="font-mono bg-primary/7 border border-primary/15 px-2 py-0.5 rounded-[6px] text-primary-dark font-bold text-[12px]">
                {profile.student_number || '—'}
              </span>
              {programName && (
                <>
                  <span className="text-primary/30">•</span>
                  <span className="break-words">{programName}</span>
                </>
              )}
              {levelLabel && (
                <>
                  <span className="text-primary/30">•</span>
                  <span>{levelLabel}</span>
                </>
              )}
            </div>
          </div>
        </div>
      </motion.div>

      <div className="bg-white border border-primary/12 rounded-[18px] shadow-[0_2px_16px_rgba(26,46,16,0.06)] overflow-hidden">
        <div className="flex border-b border-primary/10 overflow-x-auto" role="tablist" aria-label="أقسام ملف الطالب">
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
              <tab.Icon aria-hidden="true" />
              <span>{tab.ar}</span>
            </button>
          ))}
        </div>

        <motion.div
          key={activeTab}
          className="p-6"
          role="tabpanel"
          initial={{ opacity: 0, y: 6 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.2 }}
        >
          {activeTab === 'info' && <InfoTab profile={enrichedProfile} />}

          {activeTab === 'registrations' && (
            <RegistrationsTab
              loading={tabs.registrations.loading}
              error={tabs.registrations.error}
              rows={tabs.registrations.data || []}
              page={tabs.registrations.page}
              totalPages={tabs.registrations.totalPages}
              onPageChange={nextPage => loadTab('registrations', nextPage)}
            />
          )}

          {activeTab === 'transcript' && (
            <div className="space-y-4">
              {tabs.transcript.loaded && !tabs.transcript.error && tabs.transcript.data ? (
                <div className="flex justify-end">
                  <TranscriptPdfExportAction endpoint={`/v1/students/${id}/academic-record`} />
                </div>
              ) : null}
              <TranscriptTab
                loading={tabs.transcript.loading}
                error={tabs.transcript.error}
                transcript={tabs.transcript.data}
              />
            </div>
          )}

          {activeTab === 'gpa' && (
            <GpaTab
              loading={tabs.gpa.loading}
              error={tabs.gpa.error}
              cgpa={tabs.gpa.data}
            />
          )}

          {activeTab === 'attendance' && (
            <AttendanceTab
              loading={tabs.attendance.loading}
              error={tabs.attendance.error}
              attendance={tabs.attendance.data}
            />
          )}

          {activeTab === 'documents' && (
            <DocumentsTab
              loading={tabs.documents.loading}
              error={tabs.documents.error}
              documents={tabs.documents.data || []}
              page={tabs.documents.page}
              totalPages={tabs.documents.totalPages}
              onPageChange={nextPage => loadDocumentsPage(nextPage)}
            />
          )}
        </motion.div>
      </div>
    </div>
  )
}
