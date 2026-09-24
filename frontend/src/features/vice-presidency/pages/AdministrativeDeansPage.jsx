import { useEffect, useState } from 'react'
import { apiRequest } from '../../../services/apiClient'
import { canAccess, PERMISSIONS, ROLES } from '../../auth/auth'

const BASE = '/v1/vice-presidency/administrative/personnel'
const MANAGE = { allRoles: [ROLES.vicePresidentAdministrative], assignedPermissions: [PERMISSIONS.administrativeDeansManage], actualUniversityScope: true }
const empty = { college_id: '', employee_id: '', user_id: '', employee_number: '', first_name: '', last_name: '', username: '', email: '', password: '' }

export default function AdministrativeDeansPage() {
  const [colleges, setColleges] = useState([])
  const [candidates, setCandidates] = useState([])
  const [candidateSearch, setCandidateSearch] = useState('')
  const [form, setForm] = useState(empty)
  const [existing, setExisting] = useState(false)
  const [creating, setCreating] = useState(false)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [revision, setRevision] = useState(0)
  const canManage = canAccess(MANAGE)

  useEffect(() => {
    let active = true
    apiRequest(`${BASE}/deans`).then(response => { if (active) setColleges(response.data ?? []) })
      .catch(err => { if (active) setError(err.status === 403 ? 'لا تملك صلاحية عرض العمداء.' : (err.message || 'تعذّر تحميل العمداء.')) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [revision])
  useEffect(() => {
    if (!canManage || !creating || !existing) return
    let active = true
    const timer = setTimeout(() => {
      const params = new URLSearchParams()
      if (candidateSearch.trim()) params.set('search', candidateSearch.trim())
      apiRequest(`${BASE}/dean-candidates?${params}`).then(res => { if (active) setCandidates(res.data ?? []) })
        .catch(() => { if (active) setCandidates([]) })
    }, 300)
    return () => { active = false; clearTimeout(timer) }
  }, [canManage, creating, existing, candidateSearch])

  const change = (key, value) => setForm(old => ({ ...old, [key]: value }))
  const begin = college => { setForm({ ...empty, college_id: String(college.college_id) }); setCreating(true); setExisting(false); setNotice(''); setError('') }

  async function save(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError(''); setNotice('')
    const data = { college_id: Number(form.college_id) }
    if (existing) {
      const candidate = candidates.find(c => c.employee_id === Number(form.employee_id))
      data.employee_id = Number(form.employee_id)
      if (candidate?.user_id) data.user_id = candidate.user_id
    } else Object.assign(data, { employee_number: form.employee_number, first_name: form.first_name, last_name: form.last_name })
    if (!data.user_id) Object.assign(data, { username: form.username, email: form.email, password: form.password })
    try {
      await apiRequest(`${BASE}/deans`, { method: 'POST', body: JSON.stringify(data) })
      setNotice('أُنشئ أو رُبط حساب العميد بنطاق الكلية. سلّم بيانات الدخول عبر قناة معتمدة.')
      setCreating(false); setForm(empty); setLoading(true); setRevision(n => n + 1)
    } catch (err) { setError(err.status === 422 ? Object.values(err.details ?? {}).flat().join(' ') || err.message : err.message) }
    finally { setBusy(false) }
  }

  async function retire(dean, college) {
    if (busy || !window.confirm(`إنهاء تكليف ${dean.full_name || dean.username} في ${college.college_name}؟ ستُسحب صلاحية دخول بوابة العميد.`)) return
    setBusy(true); setError(''); setNotice('')
    try {
      await apiRequest(`${BASE}/deans/${dean.user_id}/colleges/${college.college_id}/retire`, { method: 'POST' })
      setNotice('انتهى تكليف العميد وسُحب دور العميد ونطاق الكلية دون حذف حسابه أو سجلاته.')
      setLoading(true); setRevision(n => n + 1)
    } catch (err) { setError(err.message || 'تعذّر إنهاء التكليف.') }
    finally { setBusy(false) }
  }

  const candidate = candidates.find(c => c.employee_id === Number(form.employee_id))
  return <div className="space-y-5 py-6 px-2" dir="rtl">
    <header className="rounded-[18px] border border-primary/12 bg-white p-5">
      <h1 className="text-[22px] font-black text-text-dark">عمداء الكليات</h1>
      <p className="mt-2 text-[13px] text-text-light">حساب العميد يُربط بكلية واحدة بصلاحية دخول محددة، مع حفظ تاريخ التغييرات.</p>
    </header>
    {notice && <p role="status" className="rounded-[12px] bg-green-50 border border-green-200 p-3 text-[13px]">{notice}</p>}
    {error && <p role="alert" className="rounded-[12px] bg-red-50 border border-red-200 p-3 text-[13px] text-red-700">{error}</p>}
    {loading && <p className="text-[13px] text-text-light">جاري التحميل…</p>}
    <div className="grid gap-3 md:grid-cols-2">
      {colleges.map(college => <section key={college.college_id} className="rounded-[16px] border border-primary/12 bg-white p-4">
        <h2 className="text-[15px] font-black text-text-dark">{college.college_name}</h2>
        {college.deans.length === 0 && <p className="mt-2 text-[12px] text-text-light">لا يوجد عميد فعّال مرتبط بهذه الكلية.</p>}
        {college.deans.map(dean => <div key={dean.user_id} className="flex flex-wrap items-center justify-between gap-2 border-t border-primary/10 mt-2 pt-2 text-[13px]">
          <span>{dean.full_name || dean.username} <span dir="ltr" className="text-text-light">({dean.username})</span></span>
          {canManage && <button type="button" disabled={busy} onClick={() => retire(dean, college)} className="text-red-700 font-bold disabled:opacity-50">إنهاء التكليف</button>}
        </div>)}
        {canManage && college.deans.length === 0 && <button type="button" onClick={() => begin(college)}
          className="mt-3 rounded-[10px] bg-primary px-4 py-2 text-white text-[13px] font-bold">تعيين عميد</button>}
      </section>)}
    </div>
    {creating && canManage && <form onSubmit={save} className="rounded-[18px] border border-primary/12 bg-white p-5 space-y-3" aria-label="تعيين عميد">
      <h2 className="text-[17px] font-black">تعيين عميد — {colleges.find(c => String(c.college_id) === form.college_id)?.college_name}</h2>
      <label className="flex gap-2 text-[13px]"><input type="checkbox" checked={existing} onChange={e => { setExisting(e.target.checked); setForm(old => ({ ...empty, college_id: old.college_id })) }} /> ربط موظف موجود</label>
      {existing ? <div className="space-y-2">
        <label className="block text-[13px]">البحث عن موظف<input value={candidateSearch} onChange={e => setCandidateSearch(e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>
        <label className="block text-[13px]">الموظف
          <select required value={form.employee_id} onChange={e => change('employee_id', e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]">
            <option value="">اختر موظفًا بلا صلاحيات أخرى</option>
            {candidates.map(c => <option key={c.employee_id} value={c.employee_id}>{c.full_name} — {c.employee_number}{c.username ? ` (${c.username})` : ''}</option>)}
          </select>
        </label>
      </div> : <div className="grid gap-3 sm:grid-cols-3">
        {[['employee_number', 'رقم الموظف'], ['first_name', 'الاسم الأول'], ['last_name', 'اسم العائلة']].map(([key, label]) => <label key={key} className="text-[13px]">{label}<input required value={form[key]} onChange={e => change(key, e.target.value)} className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>)}
      </div>}
      {(!existing || !candidate?.user_id) && <div className="grid gap-3 sm:grid-cols-3">
        {[['username', 'اسم المستخدم', 'text'], ['email', 'البريد الإلكتروني', 'email'], ['password', 'كلمة مرور أولية', 'password']].map(([key, label, type]) => <label key={key} className="text-[13px]">{label}<input required type={type} minLength={type === 'password' ? 12 : undefined}
          value={form[key]} onChange={e => change(key, e.target.value)} autoComplete="off" className="block w-full p-2.5 border border-primary/20 rounded-[10px]" /></label>)}
      </div>}
      <p className="text-[12px] text-text-light">تُشفّر كلمة المرور على الخادم ولا تظهر في سجل الحساب. لا تُرسلها ضمن بلاغات الدعم.</p>
      <div className="flex gap-2"><button type="submit" disabled={busy} className="rounded-[10px] bg-primary px-4 py-2 text-white text-[13px] font-bold disabled:opacity-50">{busy ? 'جاري الحفظ…' : 'حفظ التعيين'}</button>
        <button type="button" onClick={() => setCreating(false)} className="rounded-[10px] border border-primary/20 px-4 py-2 text-[13px]">إلغاء</button></div>
    </form>}
  </div>
}
