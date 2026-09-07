import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../../../services/apiClient'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'
import { Pager, card, field } from '../components/RegistrationGridRow'
import { manualError, searchPath } from '../lib/manualGradeEntry'
import { studentGridPath } from '../lib/manualGradeGrid'
import { studentSearchLifecycle } from '../lib/studentSearchLifecycle'

export default function ManualGradeEntryPage() {
  const [identity] = useState(() => JSON.stringify(getIdentity()))
  const [allowed, setAllowed] = useState(() => canAccess(ACCESS.manualGradeEntry, getIdentity()))
  const [q, setQ] = useState('')
  const lifecycle = useRef(studentSearchLifecycle())
  const [intent, setIntent] = useState(null)
  const [applied, setApplied] = useState(null)
  const [students, setStudents] = useState(null)
  const [lookupError, setLookupError] = useState('')
  const [loading, setLoading] = useState(false)
  useEffect(() => {
    const check = () => { if (JSON.stringify(getIdentity()) !== identity || !canAccess(ACCESS.manualGradeEntry, getIdentity())) { lifecycle.current.invalidate(); setAllowed(false); setStudents(null); setQ(''); setIntent(null); setApplied(null); setLookupError(''); setLoading(false) } }
    const timer = setInterval(check, 1000)
    window.addEventListener('storage', check); window.addEventListener('focus', check)
    return () => { clearInterval(timer); window.removeEventListener('storage', check); window.removeEventListener('focus', check) }
  }, [identity])
  useEffect(() => {
    if (!intent?.query) return
    const timer = setTimeout(() => { if (lifecycle.current.valid(intent)) setApplied(intent) }, 350)
    return () => clearTimeout(timer)
  }, [intent])
  useEffect(() => {
    if (!allowed || !applied?.query || !lifecycle.current.valid(applied)) return
    const abort = new AbortController()
    lifecycle.current.bind(applied, abort)
    let active = true
    const current = () => active && lifecycle.current.valid(applied) && JSON.stringify(getIdentity()) === identity
    setLoading(true); setLookupError(''); setStudents(null)
    apiRequest(searchPath(applied.query, applied.page), { signal: abort.signal }).then(json => {
      if (current()) setStudents(json.data)
    }).catch(e => { if (current() && e.name !== 'AbortError') { setLookupError(manualError(e)); if ([401, 403].includes(e.status)) { lifecycle.current.invalidate(); setAllowed(false); setStudents(null); setLoading(false) } } })
      .finally(() => { if (current()) setLoading(false) })
    return () => { active = false; abort.abort() }
  }, [allowed, identity, applied])
  const changeQuery = value => {
    setQ(value)
    const next = lifecycle.current.change(value)
    if (!next) return // Whitespace-only edits keep the applied page/results/request.
    setIntent(next); setStudents(null); setLookupError(''); setLoading(false)
    if (!next.query) setApplied(null)
  }
  const changePage = page => {
    const next = lifecycle.current.paginate(applied, page)
    if (next) { setStudents(null); setLookupError(''); setLoading(true); setApplied(next) }
  }
  if (!allowed) return <p role="alert" dir="rtl">الوصول غير متاح.</p>
  return <main dir="rtl" className="min-w-0 space-y-5 text-[13px] text-text-gray">
    <header><h1 className="text-[20px] font-black text-text-dark">إدخال العلامات اليدوي</h1><p className="text-[12.5px] text-text-light">ابحث عن الطالب لفتح جدول مقررات كليته وسياق علاماته.</p></header>
    <section className={`${card} space-y-3 p-5`}>
      <label className="block space-y-2">البحث باسم الطالب أو رقمه<input className={field} value={q} onChange={e => changeQuery(e.target.value)} /></label>
      {loading && <p role="status">جاري البحث…</p>}
      {lookupError && <p role="alert" className="text-red-700">{lookupError}</p>}
      {students && <><div className="divide-y divide-primary/10">{students.students.map(s => <Link className="block rounded-[10px] px-3 py-3 hover:bg-primary/[0.04] focus-visible:outline-primary" key={s.student_id} to={studentGridPath(s.student_id)}>
        <strong className="text-text-dark">{s.name}</strong> — <bdi>{s.student_number}</bdi><p className="mt-1 text-[12px] text-text-light">{s.college} — {s.program}</p>
      </Link>)}</div>{!students.students.length && <p>لا توجد نتائج ضمن نطاقك.</p>}<Pager meta={students.meta} onPage={changePage} disabled={loading} /></>}
    </section>
  </main>
}
