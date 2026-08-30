import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { courseTypeLabel, coverageLabel, semesterOfferingStatusLabel } from '../utils/semesterOfferingLabels'

export default function SemesterOfferingQueue() {
  const navigate = useNavigate()
  const [rows, setRows] = useState([])
  const [status, setStatus] = useState('submitted')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    async function load() {
      setLoading(true)
      setError('')
      try {
        const params = new URLSearchParams({ status, page: String(page), per_page: '20' })
        const response = await apiRequest(`/v1/vice-presidency/scientific/semester-offerings?${params}`)
        if (!active) return
        setRows(response?.data?.data ?? [])
        setLastPage(Math.max(1, Number(response?.data?.last_page) || 1))
      } catch (requestError) {
        if (!active) return
        if (requestError.status === 401) return navigate('/login', { replace: true })
        setRows([])
        setError(requestError.message || 'تعذّر تحميل طروحات الفصل المرسلة للمراجعة.')
      } finally {
        if (active) setLoading(false)
      }
    }
    load()
    return () => { active = false }
  }, [navigate, page, status])

  const columns = useMemo(() => [
    { key: 'course', header: 'المقرر', align: 'right', render: row => <div><b>{row.course_offering?.course?.course_code || '—'}</b><p className="text-[12px] text-text-light">{row.course_offering?.course?.course_name || '—'}</p></div> },
    { key: 'program', header: 'البرنامج والكلية', align: 'right', render: row => <div><b>{row.course_offering?.academic_program?.program_name || '—'}</b><p className="text-[12px] text-text-light">{row.course_offering?.college?.college_name || '—'}</p></div> },
    { key: 'term', header: 'الفصل الفعلي', align: 'center', render: row => `${row.course_offering?.academic_year?.year_name || '—'} / ${row.course_offering?.semester?.semester_name || '—'}` },
    { key: 'type', header: 'النوع', align: 'center', render: row => courseTypeLabel(row.course_type) },
    { key: 'coverage', header: 'التكليف الفعّال', align: 'center', render: row => coverageLabel(row.course_offering?.instructor_coverage) },
    { key: 'minimum', header: 'الحد الأدنى', align: 'center', render: row => row.minimum_enrollment ?? 'غير مطلوب' },
    { key: 'status', header: 'الحالة', align: 'center', render: row => <b>{semesterOfferingStatusLabel(row.status)}</b> },
    { key: 'review', header: '', align: 'center', render: row => <Link className="font-bold text-primary" to={`/vp/scientific/semester-offerings/${row.semester_offering_request_id}`}>مراجعة</Link> },
  ], [])

  return <div className="space-y-5 px-2 py-6" dir="rtl">
    <header className="rounded-[18px] border border-primary/15 bg-white p-6">
      <h1 className="text-[22px] font-black text-text-dark">اعتماد الطروحات الفصلية</h1>
      <p className="mt-2 text-[13px] text-text-light">قرارات علمية مستقلة لكل طرح بعد اكتمال تكليف المدرسين الفعّال.</p>
    </header>
    <FilterBar filters={[{ key: 'status', value: status, onChange: value => { setStatus(value || 'submitted'); setPage(1) }, placeholder: 'الحالة', options: [
      { value: 'submitted', label: 'بانتظار المراجعة' }, { value: 'returned', label: 'معاد للتعديل' }, { value: 'approved', label: 'معتمد' },
    ] }]} />
    {error && <p className="text-[13px] text-red-600">⚠ {error}</p>}
    <DataTable columns={columns} rows={rows} rowKey={row => row.semester_offering_request_id} loading={loading} emptyTitle="لا توجد طروحات مطابقة." page={page} totalPages={lastPage} onPageChange={setPage} />
  </div>
}
