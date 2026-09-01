import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSpinner } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { formatUniversityDateTime, registrationPhaseLabel } from '../../registration-requests/registrationDeadlinePresentation'

const STATUS_LABELS = {
  submitted: 'بانتظار المراجعة',
  returned: 'أعيد للتعديل',
  approved: 'معتمد',
  draft: 'مسودة',
  expired: 'انتهت المهلة دون اعتماد',
}

function formatDateTime(value) {
  if (!value) return '—'
  return String(value).replace('T', ' ').slice(0, 16)
}

export default function DeanRegistrationRequests() {
  const navigate = useNavigate()
  const [payload, setPayload] = useState(null)
  const [kind, setKind] = useState('initial')
  const [status, setStatus] = useState('submitted')
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      try {
        const resource = kind === 'replacement' ? 'registration-replacements' : (kind === 'modification' ? 'registration-modifications' : 'registration-requests')
        const response = await apiRequest(`/v1/academic-advising/${resource}?status=${status}`)
        if (!active) return
        setPayload(response?.data ?? null)
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية لعرض طلبات التسجيل.'
          : (requestError.message || 'تعذّر تحميل الطلبات.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [status, kind, navigate])

  const rows = useMemo(() => {
    const list = payload?.requests ?? []
    const needle = search.trim()
    if (!needle) return list
    return list.filter(row => {
      const haystack = [
        row.student?.student_number,
        row.student?.full_name,
        row.student?.program?.program_name,
      ].join(' ')
      return haystack.includes(needle)
    })
  }, [payload, search])

  const summary = payload?.summary ?? { submitted: 0, returned: 0, approved: 0, expired: 0 }

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
        <h1 className="text-[22px] font-black text-text-dark">طلبات تسجيل الطلاب</h1>
        <p className="mt-2 text-[13.5px] text-text-light leading-7">
          مراجعة طلبات التسجيل ضمن نطاق الكلية. الاعتماد يثبّت التسجيل الأكاديمي نهائياً.
        </p>
      </header>

      <div className="flex flex-wrap gap-2 rounded-[14px] border border-primary/12 bg-white p-2">
        <button type="button" onClick={() => { setKind('initial'); setStatus('submitted') }} className={`rounded-[10px] px-4 py-2 text-[13px] font-bold ${kind === 'initial' ? 'bg-primary text-white' : 'text-primary'}`}>طلبات التسجيل</button>
        <button type="button" onClick={() => { setKind('modification'); setStatus('submitted') }} className={`rounded-[10px] px-4 py-2 text-[13px] font-bold ${kind === 'modification' ? 'bg-primary text-white' : 'text-primary'}`}>طلبات تعديل التسجيل</button>
        <button type="button" onClick={() => { setKind('replacement'); setStatus('submitted') }} className={`rounded-[10px] px-4 py-2 text-[13px] font-bold ${kind === 'replacement' ? 'bg-primary text-white' : 'text-primary'}`}>طلبات استبدال المقررات الملغاة</button>
      </div>

      <div className="grid grid-cols-4 max-[1000px]:grid-cols-2 max-[600px]:grid-cols-1 gap-3">
        {[
          { key: 'submitted', label: 'طلبات بانتظار المراجعة', value: summary.submitted },
          { key: 'returned', label: 'المعادة للتعديل', value: summary.returned },
          { key: 'approved', label: 'المعتمدة', value: summary.approved },
          { key: 'expired', label: 'انتهت دون اعتماد', value: summary.expired },
        ].map(card => (
          <button
            key={card.key}
            type="button"
            onClick={() => setStatus(card.key)}
            className={`text-right rounded-[16px] border px-4 py-4 ${
              status === card.key ? 'border-primary bg-primary/8' : 'border-primary/12 bg-white'
            }`}
          >
            <p className="text-[12px] font-semibold text-text-light">{card.label}</p>
            <p className="mt-1 text-[26px] font-black text-text-dark tabular-nums">{card.value}</p>
          </button>
        ))}
      </div>

      {error ? (
        <p className="px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]">⚠ {error}</p>
      ) : null}

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'بحث برقم أو اسم الطالب' }}
        filters={[]}
      />

      {loading ? (
        <div className="flex justify-center py-16 text-primary">
          <FaSpinner className="animate-spin text-[28px]" />
        </div>
      ) : (
        <DataTable
          columns={[
            { key: 'student_number', header: 'رقم الطالب', render: row => row.student?.student_number || '—' },
            { key: 'full_name', header: 'اسم الطالب', render: row => row.student?.full_name || '—' },
            { key: 'program', header: 'البرنامج', render: row => row.student?.program?.program_name || '—' },
            { key: 'year', header: 'السنة', render: row => row.academic_year?.year_name || '—' },
            { key: 'semester', header: 'الفصل', render: row => row.semester?.semester_name || '—' },
            { key: 'version', header: 'الإصدار', render: row => row.submission_version },
            { key: 'request_hours', header: kind === 'replacement' ? 'عدد البدائل' : (kind === 'modification' ? 'ساعات التغيير' : 'ساعات الطلب'), render: row => kind === 'replacement' ? (row.items?.length ?? 0) : (kind === 'modification' ? (row.hours?.change_hours ?? 0) : (row.hours?.approved_snapshot?.request_hours_at_approval ?? row.hours?.request_hours ?? 0)) },
            { key: 'projected', header: 'الإجمالي المتوقع', render: row => row.hours?.approved_snapshot?.projected_hours_at_approval ?? row.hours?.projected_hours ?? 0 },
            { key: 'max', header: 'الحد الأقصى', render: row => row.hours?.approved_snapshot?.max_allowed_hours_at_approval ?? row.hours?.max_allowed_hours ?? '—' },
            { key: 'submitted_at', header: 'تاريخ الإرسال', render: row => formatDateTime(row.last_submitted_at) },
            { key: 'phase', header: 'مرحلة التسجيل', render: row => registrationPhaseLabel(row.registration_calendar) },
            { key: 'advisor_deadline', header: 'مهلة المرشد', render: row => formatUniversityDateTime(row.registration_calendar?.advisor_approval_ends_at) },
            { key: 'status', header: 'الحالة', render: row => STATUS_LABELS[row.status] || row.status },
            {
              key: 'open',
              header: '',
              render: row => (
                <button
                  type="button"
                  className="text-[12px] font-bold text-primary hover:underline"
                  onClick={() => navigate(kind === 'replacement'
                    ? `/dean/registration-replacements/${row.student_registration_replacement_request_id}`
                    : (kind === 'modification'
                      ? `/dean/registration-modifications/${row.student_registration_modification_request_id}`
                      : `/dean/registration-requests/${row.student_registration_request_id}`))}
                >
                  عرض
                </button>
              ),
            },
          ]}
          rows={rows}
          rowKey={row => kind === 'replacement' ? row.student_registration_replacement_request_id : (kind === 'modification' ? row.student_registration_modification_request_id : row.student_registration_request_id)}
          emptyTitle="لا توجد طلبات في هذه الحالة."
        />
      )}
    </div>
  )
}
