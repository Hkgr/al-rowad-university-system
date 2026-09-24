import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import DashboardBarChart from '../../dean-dashboard/components/DashboardBarChart'

const format = count => Number(count || 0).toLocaleString('ar-SY')

export default function AdministrativeAssignmentOverview() {
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [revision, setRevision] = useState(0)

  useEffect(() => {
    let active = true
    apiRequest('/v1/vice-presidency/teaching-assignments/administrative-summary')
      .then(response => { if (active) setData(response?.data ?? null) })
      .catch(requestError => { if (active) setError(requestError.status === 403 ? 'لا تملك صلاحية عرض التكليفات.' : 'تعذّر تحميل مؤشرات التكليفات.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [revision])

  const totals = data?.totals
  const colleges = data?.colleges ?? []
  return <section className="space-y-4" aria-labelledby="admin-assignment-overview" dir="rtl">
    <h2 id="admin-assignment-overview" className="text-[18px] font-black text-text-dark">طلبات تكليف المدرسين</h2>
    {error && <p className="rounded-[12px] bg-amber-50 border border-amber-200 p-4 text-[13px] text-amber-900">{error} <button type="button" onClick={() => { setLoading(true); setError(''); setRevision(n => n + 1) }} className="underline font-bold">إعادة المحاولة</button></p>}
    <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
      {[
        ['pending', 'بانتظار مراجعتي'], ['returned', 'معادة للتعديل'], ['approved', 'معتمدة'],
      ].map(([key, label]) => <Link key={key} to={`/vp/administrative/teaching-assignments?queue=${key}`}
        className="rounded-[14px] border border-primary/12 bg-white px-4 py-3 shadow-sm hover:border-primary/40">
        <p className="text-[12px] text-text-light font-bold">{label} — الجامعة</p>
        <p className="text-[22px] font-black text-text-dark">{loading && !data ? '…' : error && !data ? 'غير متاح' : format(totals?.[key])}</p>
      </Link>)}
    </div>
    {colleges.length > 0 && <DashboardBarChart title="طلبات تنتظر المراجعة الإدارية حسب الكلية"
      maxBars={colleges.length}
      items={colleges.map(college => ({ key: college.college_id, label: college.college_name, value: Number(college.pending_count) }))} />}
    {colleges.length > 0 && <div className="flex flex-wrap gap-2">
      {colleges.map(college => <Link key={college.college_id}
        to={`/vp/administrative/teaching-assignments?queue=all&college_id=${college.college_id}`}
        className="rounded-full border border-primary/20 bg-white px-3 py-1.5 text-[12px] font-bold text-primary-dark">{college.college_name}: {format(college.pending_count)} بانتظار المراجعة</Link>)}
    </div>}
    {!loading && !error && colleges.length === 0 && <p className="text-[13px] text-text-light">لا توجد طلبات تكليف مسجلة في الفترات المتاحة.</p>}
  </section>
}
