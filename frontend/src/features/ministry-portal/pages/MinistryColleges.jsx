import { Link, useSearchParams } from 'react-router-dom'
import { FaUniversity } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import { fetchMinistryFilters, fetchMinistryList } from '../lib/ministryApi'
import { buildQuery, formatNumber, readFilters } from '../lib/ministryState'
import { Badge, FilterSelect, Notice, PageHeader } from '../components/MinistryUi'
import { useMinistryResource } from '../lib/useMinistryResource'

const count = (value, to) => <Link to={to} className="font-bold text-primary-dark hover:underline" aria-label="فتح القائمة المطابقة">{formatNumber(value)}</Link>

const columns = [
  { key: 'name', header: 'الكلية', render: row => <Link to={`/ministry/colleges/${row.college_id}`} className="font-bold text-primary-dark hover:underline">{row.college_name}</Link> },
  { key: 'departments', header: 'الأقسام', align: 'center', render: row => formatNumber(row.departments) },
  { key: 'programs', header: 'البرامج', align: 'center', render: row => formatNumber(row.programs) },
  { key: 'students', header: 'الطلاب', align: 'center', render: row => count(row.students, `/ministry/students?college_id=${row.college_id}`) },
  { key: 'active', header: 'النشطون', align: 'center', render: row => count(row.active_students, `/ministry/students?college_id=${row.college_id}&status=active`) },
  { key: 'faculty', header: 'المدرسون', align: 'center', render: row => count(row.faculty, `/ministry/faculty?college_id=${row.college_id}&active=1`) },
  { key: 'courses', header: 'المقررات', align: 'center', render: row => count(row.courses, `/ministry/courses?college_id=${row.college_id}&active=1`) },
  { key: 'dean', header: 'العميد الحالي', render: row => row.deans.length ? row.deans.map((d, i) => <span key={d.person}>{i > 0 && '، '}<Link className="hover:underline" to={`/ministry/deans/${d.person}`}>{d.full_name}</Link></span>) : <Badge tone="warning">غير مسجل</Badge> },
  { key: 'status', header: 'الحالة', render: row => <Badge tone={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'مفعّلة' : 'غير مفعّلة'}</Badge> },
]

export default function MinistryColleges() {
  const [params, setParams] = useSearchParams()
  const filters = readFilters('colleges', params)
  const query = buildQuery('colleges', filters)
  const { data, loading, error, reload } = useMinistryResource(() => fetchMinistryList('colleges', query), [query])
  const options = useMinistryResource(fetchMinistryFilters, [])
  const collegeOptions = (options.data?.data?.colleges ?? []).map(c => ({ value: c.id, label: c.name }))
  if (filters.college_id && !collegeOptions.some(c => String(c.value) === filters.college_id)) collegeOptions.push({ value: filters.college_id, label: `الكلية المحددة (${filters.college_id})` })
  const change = key => value => setParams(new URLSearchParams(buildQuery('colleges', { ...filters, [key]: value })))
  const rows = data?.data ?? []
  return (
    <>
      <PageHeader title="الكليات" en="Colleges" subtitle="الكليات المسجلة وأعداد أقسامها وبرامجها وطلابها ومدرسيها ومقرراتها. الأعداد المرتبطة تفتح القوائم المطابقة لها.">
        {!loading && !error && <span className="text-[12.5px] font-bold text-primary-dark">{formatNumber(data?.meta?.total)} كلية</span>}
      </PageHeader>
      <div className="flex items-end gap-3 flex-wrap mb-4" dir="rtl">
        <FilterSelect label="الكلية" value={filters.college_id} onChange={change('college_id')} options={collegeOptions} />
        <FilterSelect label="حالة الكلية" value={filters.active} onChange={change('active')} options={[{ value: '1', label: 'مفعّلة' }, { value: '0', label: 'غير مفعّلة' }]} />
      </div>
      <div className="mb-4"><Notice>الطلاب حسب كلية برنامجهم الحالي؛ المدرسون حسب الانتماء (قد يُحسب المدرس في أكثر من كلية)؛ المقررات حسب الأقسام المالكة (مقرر مشترك يُحسب لكل كلية مالكة).</Notice></div>
      {error ? (
        <div className="flex items-center justify-between gap-3 border rounded-[12px] px-4 py-3 text-[13px] bg-red-50 border-red-200 text-red-700" role="alert" dir="rtl">
          <span>{error.status === 403 ? 'لا يملك حسابك صلاحية الاطلاع على الكليات.' : (error.message || 'تعذّر تحميل الكليات.')}</span>
          {error.status !== 403 && <button type="button" onClick={reload} className="px-3 py-1.5 rounded-[9px] border border-red-300 bg-white font-bold">إعادة المحاولة</button>}
        </div>
      ) : (
        <DataTable columns={columns} rows={rows} rowKey={row => row.college_id} loading={loading} animationKey={query} emptyIcon={FaUniversity} emptyTitle="لا توجد كليات مطابقة للمرشحات" page={1} totalPages={1} onPageChange={() => {}} />
      )}
    </>
  )
}
