import { useCallback, useEffect, useState } from 'react'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { apiRequest } from '../../../services/apiClient'
import { canAccess, PERMISSIONS, ROLES } from '../../auth/auth'

const BASE = '/v1/vice-presidency/administrative/personnel'
const MANAGE = { allRoles: [ROLES.vicePresidentAdministrative], assignedPermissions: [PERMISSIONS.administrativeStaffManage], actualUniversityScope: true }
const empty = { college_id: '', employee_id: '', employee_number: '', first_name: '', last_name: '', email: '', academic_rank: '', specialization: '', office_location: '' }

export default function AdministrativeFacultyPage() {
  const [rows, setRows] = useState([])
  const [colleges, setColleges] = useState([])
  const [employees, setEmployees] = useState([])
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [search, setSearch] = useState('')
  const [college, setCollege] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [selected, setSelected] = useState(null)
  const [existing, setExisting] = useState(false)
  const [form, setForm] = useState(empty)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [revision, setRevision] = useState(0)
  const canManage = canAccess(MANAGE)

  const reload = useCallback(() => setRevision(n => n + 1), [])
  useEffect(() => {
    let active = true
    apiRequest(`${BASE}/colleges`).then(res => { if (active) setColleges(res.data ?? []) }).catch(() => {})
    return () => { active = false }
  }, [])
  useEffect(() => {
    if (!canManage || selected !== 'new' || !existing) return
    let active = true
    const timer = setTimeout(() => {
      const params = new URLSearchParams()
      if (employeeSearch.trim()) params.set('search', employeeSearch.trim())
      apiRequest(`${BASE}/employees?${params}`).then(res => { if (active) setEmployees(res.data ?? []) }).catch(() => { if (active) setEmployees([]) })
    }, 300)
    return () => { active = false; clearTimeout(timer) }
  }, [canManage, selected, existing, employeeSearch, revision])
  useEffect(() => {
    let active = true
    const timer = setTimeout(async () => {
      setLoading(true)
      setError('')
      const params = new URLSearchParams({ page: String(page), per_page: '20' })
      if (search.trim()) params.set('search', search.trim())
      if (college) params.set('college_id', college)
      try {
        const response = await apiRequest(`${BASE}/faculty?${params}`)
        if (active) {
          setRows(response.data?.data ?? [])
          setLastPage(response.data?.meta?.last_page ?? 1)
        }
      } catch (err) {
        if (active) { setRows([]); setError(err.status === 403 ? 'لا تملك صلاحية عرض المدرسين.' : (err.message || 'تعذّر التحميل.')) }
      } finally { if (active) setLoading(false) }
    }, search ? 300 : 0)
    return () => { active = false; clearTimeout(timer) }
  }, [search, college, page, revision])

  const change = (key, value) => setForm(old => ({ ...old, [key]: value }))
  const begin = row => {
    setSelected(row || 'new'); setExisting(false); setError(''); setNotice('')
    setEmployeeSearch('')
    setForm(row ? { ...empty, ...row, college_id: String(row.college_ids?.[0] ?? ''), employee_id: '' } : empty)
  }
  async function save(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError(''); setNotice('')
    const editing = selected && selected !== 'new'
    const payload = { college_id: Number(form.college_id), academic_rank: form.academic_rank,
      specialization: form.specialization, office_location: form.office_location }
    if (editing) Object.assign(payload, { first_name: form.first_name, last_name: form.last_name, email: form.email || null })
    else if (existing) payload.employee_id = Number(form.employee_id)
    else Object.assign(payload, { employee_number: form.employee_number, first_name: form.first_name,
      last_name: form.last_name, email: form.email || null })
    try {
      await apiRequest(editing ? `${BASE}/faculty/${selected.faculty_member_id}` : `${BASE}/faculty`,
        { method: editing ? 'PUT' : 'POST', body: JSON.stringify(payload) })
      setNotice('حُفظ ملف المدرس وانتماؤه إلى الكلية. التكليف بمادة يحتاج طلب العميد واعتماد النائبين.')
      setSelected(null); setForm(empty); reload()
    } catch (err) { setError(err.status === 422 ? Object.values(err.details ?? {}).flat().join(' ') || err.message : err.message) }
    finally { setBusy(false) }
  }

  const cols = [
    { key: 'name', header: 'المدرس', render: row => <span className="font-bold text-[13px]">{row.full_name} <span className="block text-text-light font-normal">{row.employee_number}</span></span> },
    { key: 'rank', header: 'الرتبة والتخصص', render: row => <span className="text-[12px]">{row.academic_rank || '—'} · {row.specialization || '—'}</span> },
    { key: 'college', header: 'الكلية', render: row => <span className="text-[12px]">{row.college_ids?.map(id => colleges.find(c => c.college_id === id)?.college_name || `#${id}`).join('، ') || '—'}</span> },
    { key: 'edit', header: '', render: row => canManage && <button type="button" className="text-primary text-[12px] font-bold" onClick={() => begin(row)}>تعديل</button> },
  ]

  return <div className="space-y-5 py-6 px-2" dir="rtl">
    <header className="rounded-[18px] border border-primary/12 bg-white p-5">
      <h1 className="text-[22px] font-black text-text-dark">إدارة المدرسين</h1>
      <p className="mt-2 text-[13px] text-text-light">إضافة ملف تدريسي أو تعديله وربط المدرس بكلية. الانتماء لا يُنشئ تكليف مادة.</p>
      {canManage && <button type="button" className="mt-3 rounded-[10px] bg-primary px-4 py-2 text-white text-[13px] font-bold" onClick={() => begin(null)}>إضافة مدرس</button>}
    </header>
    {notice && <p role="status" className="rounded-[12px] bg-green-50 border border-green-200 p-3 text-[13px]">{notice}</p>}
    {error && <p role="alert" className="rounded-[12px] bg-red-50 border border-red-200 p-3 text-[13px] text-red-700">{error}</p>}
    <FilterBar search={{ value: search, onChange: value => { setSearch(value); setPage(1) }, placeholder: 'اسم المدرس أو رقم الموظف' }}
      filters={[{ key: 'college', value: college, onChange: value => { setCollege(value); setPage(1) },
        placeholder: 'كل الكليات', options: colleges.map(c => ({ value: c.college_id, label: c.college_name })) }]} />
    <DataTable columns={cols} rows={rows} rowKey={row => row.faculty_member_id} loading={loading}
      emptyTitle="لا توجد ملفات تدريسية مطابقة" page={page} totalPages={lastPage} onPageChange={setPage} />
    {selected && canManage && <form onSubmit={save} className="rounded-[18px] border border-primary/12 bg-white p-5 space-y-3" aria-label="بيانات المدرس">
      <h2 className="text-[17px] font-black">{selected === 'new' ? 'إضافة مدرس' : `تعديل ${selected.full_name}`}</h2>
      {selected === 'new' && <label className="flex items-center gap-2 text-[13px]"> <input type="checkbox" checked={existing} onChange={e => { setExisting(e.target.checked); setForm(empty) }} /> ربط موظف موجود بملف تدريسي</label>}
      {selected === 'new' && existing && <div className="space-y-2">
        <label className="block text-[13px]">ابحث بالاسم أو رقم الموظف<input value={employeeSearch} onChange={e => setEmployeeSearch(e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>
        <label className="block text-[13px]">الموظف
        <select required value={form.employee_id} onChange={e => change('employee_id', e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]">
          <option value="">اختر موظفًا نشطًا بلا ملف تدريسي</option>
          {employees.map(e => <option key={e.employee_id} value={e.employee_id}>{e.first_name} {e.last_name} — {e.employee_number}</option>)}
        </select>
        </label>
      </div>}
      {(!existing || selected !== 'new') && <div className="grid gap-3 sm:grid-cols-3">
        {selected === 'new' && <label className="text-[13px]">رقم الموظف<input required value={form.employee_number} onChange={e => change('employee_number', e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>}
        {[['first_name', 'الاسم الأول'], ['last_name', 'اسم العائلة']].map(([key, label]) => <label key={key} className="text-[13px]">{label}<input required value={form[key]} onChange={e => change(key, e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>)}
      </div>}
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-[13px]">الكلية
          <select required value={form.college_id} onChange={e => change('college_id', e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]">
            <option value="">اختر الكلية</option>{colleges.map(c => <option key={c.college_id} value={c.college_id}>{c.college_name}</option>)}
          </select>
        </label>
        {!existing && <label className="text-[13px]">البريد الإلكتروني<input type="email" value={form.email || ''} onChange={e => change('email', e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>}
        {[['academic_rank', 'الرتبة العلمية'], ['specialization', 'الاختصاص'], ['office_location', 'المكتب']].map(([key, label]) => <label key={key} className="text-[13px]">{label}<input value={form[key] || ''} onChange={e => change(key, e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>)}
      </div>
      <div className="flex gap-2"><button type="submit" disabled={busy} className="rounded-[10px] bg-primary px-4 py-2 text-white text-[13px] font-bold disabled:opacity-50">{busy ? 'جاري الحفظ…' : 'حفظ المدرس'}</button>
        <button type="button" onClick={() => setSelected(null)} className="rounded-[10px] border border-primary/20 px-4 py-2 text-[13px]">إلغاء</button></div>
    </form>}
  </div>
}
