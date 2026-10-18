import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { FaChalkboardTeacher } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import {
  actionLabel,
  facultyName,
  formatDateTime,
  offeringTitle,
  queueApiQuery,
  queueParamsFromSearch,
  requestStatusLabel,
  reviewStatusLabel,
  roleLabel,
} from '../utils/teachingAssignmentLabels'

const PER_PAGE = 20
const QUEUE_OPTIONS = [
  { value: 'pending', label: 'بانتظار مراجعتي' },
  { value: 'returned', label: 'معاد للتعديل' },
  { value: 'approved', label: 'معتمد' },
  { value: 'all', label: 'كل الطلبات الحالية' },
]

const REVIEW_TONE = {
  pending: 'bg-amber-50 text-amber-700 border-amber-200',
  approved: 'bg-green-50 text-green-700 border-green-200',
  returned: 'bg-red-50 text-red-600 border-red-200',
}

const SHORT_REVIEW = { pending: 'بانتظار', approved: 'موافق', returned: 'معاد' }

function ReviewBadge({ label, status }) {
  return (
    <span title={`${label}: ${reviewStatusLabel(status)}`} className={`inline-flex items-center gap-1 px-2 py-[2px] border rounded-[7px] text-[11px] font-bold whitespace-nowrap ${REVIEW_TONE[status] ?? 'bg-gray-50 text-gray-500 border-gray-200'}`}>
      {label}: {SHORT_REVIEW[status] ?? '—'}
    </span>
  )
}

function listPayload(response) {
  return response?.data ?? {}
}

export default function TeachingAssignmentQueue({ office }) {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const authority = office === 'administrative' ? 'administrative' : 'scientific'
  const basePath = office === 'administrative' ? '/vp/administrative' : '/vp/scientific'
  const state = useMemo(() => queueParamsFromSearch(searchParams), [searchParams])
  const [searchText, setSearchText] = useState(state.search)
  const [rows, setRows] = useState([])
  const [meta, setMeta] = useState({ total: 0, last_page: 1 })
  const [options, setOptions] = useState({ colleges: [], academic_years: [], semesters: [] })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  const debounceRef = useRef(null)

  // Every filter lives in the URL so dashboard links and the back button restore it.
  const update = (changes) => {
    const next = { ...state, ...changes }
    if (!('page' in changes)) next.page = '1'
    if (next.semester_id && !next.academic_year_id) next.semester_id = ''
    const params = new URLSearchParams()
    Object.entries(next).forEach(([key, value]) => {
      if (value && !(key === 'page' && value === '1') && !(key === 'queue' && value === 'pending')) params.set(key, value)
    })
    setSearchParams(params, { replace: 'search' in changes })
  }

  // URL → input sync (back button, cleared filters) without an effect.
  const [syncedSearch, setSyncedSearch] = useState(state.search)
  if (syncedSearch !== state.search) {
    setSyncedSearch(state.search)
    setSearchText(state.search)
  }

  useEffect(() => {
    clearTimeout(debounceRef.current)
    if (searchText.trim() === state.search.trim()) return undefined
    debounceRef.current = setTimeout(() => update({ search: searchText.trim() }), 380)
    return () => clearTimeout(debounceRef.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchText])

  useEffect(() => {
    let active = true
    const timer = setTimeout(() => {
    setLoading(true)
    setError('')
    apiRequest(`/v1/vice-presidency/teaching-assignments?${queueApiQuery(authority, state, PER_PAGE)}`)
      .then((response) => {
        if (!active) return
        const payload = listPayload(response)
        setRows(payload.data ?? [])
        setMeta({ total: Number(payload.meta?.total) || 0, last_page: Math.max(1, Number(payload.meta?.last_page) || 1) })
        if (payload.filter_options) setOptions(payload.filter_options)
      })
      .catch((requestError) => {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setRows([])
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية لعرض طلبات هذه النيابة، أو سُحبت منك مؤخرًا.'
          : requestError.status === 422
            ? 'قيمة تصفية غير صالحة؛ امسح الفلاتر وأعد المحاولة.'
            : (requestError.message || 'تعذّر تحميل قائمة التكليفات.'))
      })
      .finally(() => { if (active) setLoading(false) })
    }, 0)
    return () => { active = false; clearTimeout(timer) }
  }, [authority, navigate, state, revision])

  const hasFilters = Boolean(state.search || state.college_id || state.academic_year_id || state.semester_id || state.instructor_role || state.action_type)
  const clearFilters = () => { setSearchText(''); setSearchParams(state.queue === 'pending' ? {} : { queue: state.queue }) }
  const page = Number(state.page) || 1

  const columns = useMemo(() => ([
    {
      key: 'type',
      header: 'نوع الطلب',
      dir: 'rtl',
      render: row => (
        <div className="min-w-[110px]">
          <span className={`block text-[12.5px] font-bold ${row.action_type === 'remove' ? 'text-red-600' : 'text-text-dark'}`}>{actionLabel(row.action_type)}</span>
          <span className="block text-[11px] text-text-light">النسخة {row.submission_version ?? '—'} • {roleLabel(row.instructor_role)}</span>
        </div>
      ),
    },
    {
      key: 'college',
      header: 'الكلية / القسم / البرنامج',
      dir: 'rtl',
      render: row => (
        <div className="min-w-[140px] max-w-[190px] text-[12px] leading-5">
          <span className="block font-semibold text-text-dark truncate">{row.course_offering?.college?.college_name || '—'}</span>
          <span className="block text-text-gray truncate">{row.course_offering?.department?.department_name || '—'}</span>
          <span className="block text-text-light truncate">{row.course_offering?.academic_program?.program_name || '—'}</span>
        </div>
      ),
    },
    {
      key: 'offering',
      header: 'المادة والطرح',
      dir: 'rtl',
      render: row => (
        <div className="min-w-[150px] max-w-[210px]">
          <span className="block truncate text-[13px] font-semibold text-text-dark" title={offeringTitle(row.course_offering)}>{offeringTitle(row.course_offering)}</span>
          <span className="block text-[11.5px] text-text-gray mt-0.5">
            {[`طرح #${row.course_offering?.course_offering_id ?? '—'}`, row.course_offering?.academic_year?.year_name, row.course_offering?.semester?.semester_name].filter(Boolean).join(' • ')}
          </span>
        </div>
      ),
    },
    {
      key: 'teachers',
      header: 'المدرس المقترح / النافذ',
      dir: 'rtl',
      render: row => (
        <div className="min-w-[130px] max-w-[180px] text-[12px] leading-5">
          {row.action_type === 'remove' ? (
            <span className="block text-red-600 font-semibold truncate">إنهاء: {facultyName(row.removal_target || row.proposed_faculty_member)}</span>
          ) : (
            <span className="block text-text-dark font-semibold truncate">مقترح: {facultyName(row.proposed_faculty_member)}</span>
          )}
          <span className="block text-text-gray truncate">نافذ: {facultyName(row.effective_faculty_member)}</span>
        </div>
      ),
    },
    {
      key: 'submitted',
      header: 'العميد والتاريخ',
      dir: 'rtl',
      render: row => (
        <div className="text-[12px] leading-5 whitespace-nowrap">
          <span className="block text-text-dark" dir="ltr">{row.requester?.username || '—'}</span>
          <span className="block text-text-gray">{formatDateTime(row.submitted_at)}</span>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'الحالة والمراجعات',
      dir: 'rtl',
      render: row => (
        <div className="flex flex-col items-start gap-1 min-w-[110px]">
          <span className="text-[12px] font-bold text-text-dark">{requestStatusLabel(row.status)}</span>
          <ReviewBadge label="العلمية" status={row.scientific_review?.status} />
          <ReviewBadge label="الإدارية" status={row.administrative_review?.status} />
        </div>
      ),
    },
    {
      key: 'open',
      header: '',
      align: 'center',
      render: row => (
        <Link
          to={`${basePath}/teaching-assignments/${row.teaching_assignment_request_id}`}
          state={{ fromQueue: `?${searchParams.toString()}` }}
          className={`inline-block px-3 py-1.5 rounded-[9px] text-[12.5px] font-bold whitespace-nowrap ${row.viewer_context?.can_approve || row.viewer_context?.can_return ? 'bg-primary text-white' : 'border border-primary/20 text-primary-dark'}`}
        >
          {row.viewer_context?.can_approve || row.viewer_context?.can_return ? 'مراجعة' : 'عرض'}
        </Link>
      ),
    },
  ]), [basePath, searchParams])

  const semesterOptions = state.academic_year_id ? options.semesters : []

  return (
    <div className="space-y-5 py-6 px-2" dir="rtl">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div>
          <h2 className="text-[20px] font-black text-text-dark mb-[3px]">تكليفات المدرسين</h2>
          <p className="text-[12.5px] text-text-light">
            {authority === 'administrative'
              ? 'مراجعة إدارية لطلبات تكليف المدرسين. الموافقة العلمية مستقلة؛ لا يصبح التكليف نافذًا إلا بموافقة المكتبين.'
              : 'مراجعة علمية لطلبات تكليف المدرسين. الموافقة الإدارية مستقلة؛ لا يصبح التكليف نافذًا إلا بموافقة المكتبين.'}
          </p>
        </div>
        <span className="text-[12.5px] text-text-light">{loading ? '' : `${meta.total} طلب`}</span>
      </div>

      <FilterBar
        search={{ value: searchText, onChange: setSearchText, placeholder: 'ابحث برمز المادة أو اسمها أو اسم المدرس أو اسم مستخدم العميد…' }}
        filters={[
          { key: 'queue', value: state.queue, onChange: value => update({ queue: value || 'pending' }), placeholder: 'بانتظار مراجعتي', options: QUEUE_OPTIONS.slice(1), minWidth: 150 },
          { key: 'college', value: state.college_id, onChange: value => update({ college_id: value }), placeholder: 'كل الكليات', minWidth: 190, options: options.colleges.map(item => ({ value: String(item.id), label: item.label })) },
          { key: 'year', value: state.academic_year_id, onChange: value => update({ academic_year_id: value }), placeholder: 'كل السنوات', options: options.academic_years.map(item => ({ value: String(item.id), label: item.label })) },
          { key: 'semester', value: state.semester_id, onChange: value => update({ semester_id: value }), placeholder: state.academic_year_id ? 'كل الفصول' : 'اختر السنة أولًا', options: semesterOptions.map(item => ({ value: String(item.id), label: item.label })) },
          { key: 'role', value: state.instructor_role, onChange: value => update({ instructor_role: value }), placeholder: 'كل الشقوق', options: [{ value: 'theoretical', label: 'نظري' }, { value: 'practical', label: 'عملي' }] },
          { key: 'action', value: state.action_type, onChange: value => update({ action_type: value }), placeholder: 'كل الأنواع', minWidth: 160, options: [{ value: 'assign', label: 'تكليف أو تغيير' }, { value: 'remove', label: 'إنهاء تكليف' }] },
        ]}
        hasActiveFilters={hasFilters}
        onClear={clearFilters}
      />

      {error && (
        <div className="flex items-center justify-between gap-3 bg-red-50 border border-red-200 rounded-[12px] px-5 py-3 text-[13px] text-red-600" role="alert">
          <span>⚠ {error}</span>
          <button type="button" onClick={() => setRevision(value => value + 1)} className="px-3 py-1 border border-red-300 rounded-[7px] text-[12px]">إعادة المحاولة</button>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={row => row.teaching_assignment_request_id}
        loading={loading}
        animationKey={searchParams.toString()}
        emptyIcon={FaChalkboardTeacher}
        emptyTitle={error ? 'تعذّر عرض الطلبات' : hasFilters ? 'لا توجد طلبات مطابقة' : 'لا توجد طلبات في هذه القائمة'}
        emptySubtitle={hasFilters ? 'جرّب تعديل البحث أو الفلاتر.' : undefined}
        hasFilters={hasFilters}
        onClearFilters={clearFilters}
        page={page}
        totalPages={meta.last_page}
        onPageChange={value => update({ page: String(value) })}
      />
    </div>
  )
}
