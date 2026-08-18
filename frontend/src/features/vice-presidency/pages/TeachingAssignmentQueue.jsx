import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import {
  facultyName,
  formatDateTime,
  offeringTitle,
  requestStatusLabel,
  reviewStatusLabel,
  roleLabel,
} from '../utils/teachingAssignmentLabels'

function listRows(response) {
  return response?.data?.data ?? []
}

export default function TeachingAssignmentQueue({ office }) {
  const navigate = useNavigate()
  const authority = office === 'administrative' ? 'administrative' : 'scientific'
  const basePath = office === 'administrative' ? '/vp/administrative' : '/vp/scientific'
  const ownReviewKey = authority === 'administrative' ? 'administrative_review' : 'scientific_review'
  const [rows, setRows] = useState([])
  const [queue, setQueue] = useState('pending')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true

    async function load() {
      setLoading(true)
      setError('')
      const params = new URLSearchParams({
        authority,
        queue,
        per_page: '20',
        page: String(page),
      })
      try {
        const response = await apiRequest(`/v1/vice-presidency/teaching-assignments?${params.toString()}`)
        if (!active) return
        setRows(listRows(response))
        setLastPage(Math.max(1, Number(response?.data?.meta?.last_page) || 1))
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) {
          navigate('/login', { replace: true })
          return
        }
        setRows([])
        setError(requestError.status === 403
          ? 'ليس لديك صلاحية لعرض تكليفات المدرسين.'
          : (requestError.message || 'تعذّر تحميل قائمة التكليفات.'))
      } finally {
        if (active) setLoading(false)
      }
    }

    load()
    return () => { active = false }
  }, [authority, navigate, page, queue])

  const columns = useMemo(() => ([
    {
      key: 'offering',
      header: 'المادة',
      align: 'right',
      dir: 'rtl',
      render: row => (
        <div className="min-w-0 max-w-[240px]">
          <span className="block truncate text-[13px] font-semibold text-text-dark" title={offeringTitle(row.course_offering)}>
            {offeringTitle(row.course_offering)}
          </span>
          <span className="block text-[11.5px] text-text-gray mt-0.5">
            {row.course_offering?.college?.college_name || '—'}
          </span>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'الشق',
      align: 'center',
      render: row => <span className="text-[12.5px] font-bold">{roleLabel(row.instructor_role)}</span>,
    },
    {
      key: 'proposed',
      header: 'المدرس المقترح',
      align: 'right',
      dir: 'rtl',
      render: row => (
        <div className="min-w-0 max-w-[180px]">
          <span className="block truncate text-[13px] text-text-dark">{facultyName(row.proposed_faculty_member)}</span>
          <span className="block text-[11px] text-text-light">{row.proposed_faculty_member?.academic_rank || ''}</span>
        </div>
      ),
    },
    {
      key: 'effective',
      header: 'المدرس المعتمد',
      align: 'right',
      dir: 'rtl',
      render: row => <span className="text-[12.5px] text-text-gray">{facultyName(row.effective_faculty_member)}</span>,
    },
    {
      key: 'own',
      header: authority === 'administrative' ? 'المراجعة الإدارية' : 'المراجعة العلمية',
      align: 'center',
      render: row => (
        <span className="text-[12px] font-bold">{reviewStatusLabel(row[ownReviewKey]?.status)}</span>
      ),
    },
    {
      key: 'status',
      header: 'الحالة',
      align: 'center',
      render: row => <span className="text-[12px] font-bold">{requestStatusLabel(row.status)}</span>,
    },
    {
      key: 'submitted',
      header: 'تاريخ الإرسال',
      align: 'center',
      render: row => <span className="text-[12px] text-text-gray whitespace-nowrap">{formatDateTime(row.submitted_at)}</span>,
    },
    {
      key: 'open',
      header: '',
      align: 'center',
      render: row => (
        <Link
          to={`${basePath}/teaching-assignments/${row.teaching_assignment_request_id}`}
          className="text-[12.5px] font-bold text-primary"
        >
          مراجعة
        </Link>
      ),
    },
  ]), [authority, basePath, ownReviewKey])

  return (
    <div className="space-y-5 py-6 px-2" dir="rtl">
      <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5">
        <h1 className="text-[22px] font-black text-text-dark">تكليفات المدرسين</h1>
        <p className="mt-2 text-[13.5px] text-text-light leading-7">
          {authority === 'administrative'
            ? 'مراجعة إدارية لطلبات تكليف المدرسين. الموافقة العلمية مستقلة ولا تظهر أزرارها هنا.'
            : 'مراجعة علمية لطلبات تكليف المدرسين. الموافقة الإدارية مستقلة ولا تظهر أزرارها هنا.'}
        </p>
      </header>

      <FilterBar
        filters={[{
          key: 'queue',
          value: queue,
          onChange: value => {
            setQueue(value || 'pending')
            setPage(1)
          },
          placeholder: 'الحالة',
          options: [
            { value: 'pending', label: 'بانتظار مراجعتي' },
            { value: 'returned', label: 'معاد للتعديل' },
            { value: 'approved', label: 'معتمد' },
            { value: 'all', label: 'الكل' },
          ],
        }]}
      />

      {error && <p className="text-[13px] text-red-600">⚠ {error}</p>}

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={row => row.teaching_assignment_request_id}
        loading={loading}
        emptyTitle="لا توجد طلبات مطابقة."
        page={page}
        totalPages={lastPage}
        onPageChange={setPage}
      />
    </div>
  )
}
