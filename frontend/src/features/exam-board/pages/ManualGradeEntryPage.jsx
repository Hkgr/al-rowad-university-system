import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import { Pager, card, field } from '../components/RegistrationGridRow'
import { manualError, searchPath } from '../lib/manualGradeEntry'
import { studentGridPath } from '../lib/manualGradeGrid'

export default function ManualGradeEntryPage() {
  const [identity] = useState(() => JSON.stringify(getIdentity()))
  const [allowed, setAllowed] = useState(() => canAccess(ACCESS.manualGradeEntry, getIdentity()))
  const [q, setQ] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [students, setStudents] = useState(null)
  const [lookupError, setLookupError] = useState('')
  const [loading, setLoading] = useState(false)
  useEffect(() => {
    const check = () => { if (JSON.stringify(getIdentity()) !== identity || !canAccess(ACCESS.manualGradeEntry, getIdentity())) { setAllowed(false); setStudents(null); setQ(''); setSearch('') } }
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check) }
  }, [identity])
  useEffect(() => { const timer = setTimeout(() => { setSearch(q.trim()); setPage(1) }, 350); return () => clearTimeout(timer) }, [q])
  useEffect(() => {
    if (!allowed || !search) { setStudents(null); return }
    const abort = new AbortController()
    let active = true
    setLoading(true); setLookupError(''); setStudents(null)
    apiRequest(searchPath(search, page), { signal: abort.signal }).then(json => {
      if (active && JSON.stringify(getIdentity()) === identity) setStudents(json.data)
    }).catch(e => { if (active && e.name !== 'AbortError') { setLookupError(manualError(e)); if ([401, 403].includes(e.status)) { setAllowed(false); setStudents(null) } } })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false; abort.abort() }
  }, [allowed, identity, search, page])
  if (!allowed) return <p role="alert" dir="rtl">الوصول غير متاح.</p>
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <header><h1 className="text-[20px] font-black text-text-dark">إدخال العلامات اليدوي</h1><p className="text-[12.5px] text-text-light">ابحث عن الطالب لفتح جدول مقررات كليته وسياق علاماته.</p></header>
    <section className={`${card} space-y-3 p-5`}>
      <label className="block space-y-2">البحث باسم الطالب أو رقمه<input className={field} value={q} onChange={e => { setStudents(null); setQ(e.target.value) }} /></label>
      {loading && <p role="status">جاري البحث…</p>}
      {lookupError && <p role="alert" className="text-red-700">{lookupError}</p>}
      {students && <><div className="divide-y divide-primary/10">{students.students.map(s => <Link className="block rounded-[10px] px-3 py-3 hover:bg-primary/[0.04] focus-visible:outline-primary" key={s.student_id} to={studentGridPath(s.student_id)}>
        <strong className="text-text-dark">{s.name}</strong> — <bdi>{s.student_number}</bdi><p className="mt-1 text-[12px] text-text-light">{s.college} — {s.program}</p>
      </Link>)}</div>{!students.students.length && <p>لا توجد نتائج ضمن نطاقك.</p>}<Pager meta={students.meta} onPage={setPage} disabled={loading} /></>}
    </section>
  </main>
}
