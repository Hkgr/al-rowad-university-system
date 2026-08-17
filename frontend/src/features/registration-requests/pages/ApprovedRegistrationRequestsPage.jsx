import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { FaSpinner } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import CourseRequirementBadges from '../../../components/academic/CourseRequirementBadges'

function formatDateTime(value) {
  if (!value) return '—'
  return String(value).replace('T', ' ').slice(0, 16)
}

export default function ApprovedRegistrationRequestsPage() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      try {
        const response = await apiRequest('/v1/registration-requests/approved')
        if (!active) return
        setRows(response?.data?.requests ?? [])
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية لعرض طلبات التسجيل المعتمدة.'
          : (requestError.message || 'تعذّر تحميل الطلبات المعتمدة.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [navigate])

  const filtered = useMemo(() => {
    const needle = search.trim()
    if (!needle) return rows
    return rows.filter(row => [
      row.student_registration_request_id,
      row.student?.student_number,
      row.student?.full_name,
      row.student?.program?.program_name,
    ].join(' ').includes(needle))
  }, [rows, search])

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
        <h1 className="text-[22px] font-black text-text-dark">طلبات التسجيل المعتمدة</h1>
        <p className="mt-2 text-[13.5px] text-text-light leading-7">
          عرض فقط. اعتماد المرشد الأكاديمي هو الاعتماد الأكاديمي النهائي، وقد أصبحت التسجيلات ظاهرة في شؤون الطلاب والامتحانات.
        </p>
      </header>

      {error ? (
        <p className="px-4 py-2.5 text-[12.5px] text-red-600 bg-red-50 border border-red-200 rounded-[10px]">⚠ {error}</p>
      ) : null}

      <FilterBar
        search={{ value: search, onChange: setSearch, placeholder: 'بحث بالطالب أو رقم الطلب' }}
      />

      {loading ? (
        <div className="flex justify-center py-16 text-primary">
          <FaSpinner className="animate-spin text-[28px]" />
        </div>
      ) : (
        <DataTable
          columns={[
            { key: 'id', header: 'رقم الطلب', render: row => row.student_registration_request_id },
            { key: 'student', header: 'الطالب', render: row => `${row.student?.full_name || '—'} (${row.student?.student_number || '—'})` },
            { key: 'program', header: 'البرنامج', render: row => row.student?.program?.program_name || '—' },
            { key: 'term', header: 'الفصل', render: row => `${row.academic_year?.year_name || '—'} / ${row.semester?.semester_name || '—'}` },
            {
              key: 'courses',
              header: 'المقررات',
              render: row => (
                <div className="space-y-1.5" dir="rtl">
                  {(row.items ?? []).map(item => (
                    <div key={`${row.student_registration_request_id}-${item.course_offering_id || item.course_code}`} className="min-w-0">
                      <div className="text-[12.5px] font-semibold text-text-dark">
                        {[item.course_code, item.course_name].filter(Boolean).join(' — ') || '—'}
                      </div>
                      <div className="mt-0.5">
                        <CourseRequirementBadges classification={item.requirement_classification} compact />
                      </div>
                    </div>
                  ))}
                </div>
              ),
            },
            { key: 'hours', header: 'إجمالي ساعات الطلب', render: row => row.hours?.approved_snapshot?.request_hours_at_approval ?? row.hours?.request_hours_at_approval ?? 0 },
            { key: 'advisor', header: 'المرشد', render: row => row.advisor?.full_name || '—' },
            { key: 'approved_at', header: 'تاريخ الاعتماد', render: row => formatDateTime(row.approved_at) },
          ]}
          rows={filtered}
          rowKey={row => row.student_registration_request_id}
          emptyTitle="لا توجد طلبات تسجيل معتمدة ضمن نطاقك."
        />
      )}
    </div>
  )
}
