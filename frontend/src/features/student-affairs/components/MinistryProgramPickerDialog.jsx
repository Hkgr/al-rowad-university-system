import { useEffect, useState } from 'react'
import { FaSearch, FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { paginatedRows, paginationMeta, programOptionLabel } from '../lib/ministryPlacement'

export default function MinistryProgramPickerDialog({ open, title, message, suggestions = [], busy = false, onClose, onConfirm }) {
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [programs, setPrograms] = useState([])
  const [meta, setMeta] = useState({})
  const [selected, setSelected] = useState(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!open) return
    setQuery('')
    setPage(1)
    setSelected(null)
  }, [open])

  useEffect(() => {
    if (!open) return
    let active = true
    const params = new URLSearchParams({ page: String(page), per_page: '15' })
    if (query.trim()) params.set('q', query.trim())
    setLoading(true)
    setError('')
    apiRequest(`/v1/ministry-placement-programs?${params}`)
      .then(response => {
        if (!active) return
        setPrograms(paginatedRows(response))
        setMeta(paginationMeta(response))
      })
      .catch(err => active && setError(err.message))
      .finally(() => active && setLoading(false))
    return () => { active = false }
  }, [open, page, query])

  if (!open) return null

  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-xl">
      <h3 className="text-lg font-black text-text-dark">{title}</h3>
      {message && <p className="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{message}</p>}
      {suggestions.length > 0 && <div className="mt-4">
        <p className="mb-2 text-xs font-bold text-text-light">اقتراحات المطابقة — لا يتم اعتماد أي اقتراح تلقائياً</p>
        <div className="flex flex-wrap gap-2">{suggestions.map(program => <button type="button" key={program.academic_program_id} onClick={() => setSelected(program)} className="rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-sm text-primary">{programOptionLabel(program)}</button>)}</div>
      </div>}
      <label className="mt-4 block text-sm font-bold">البحث في البرامج النشطة
        <div className="mt-2 flex gap-2"><FaSearch className="mt-3 text-primary" /><input value={query} onChange={event => { setQuery(event.target.value); setPage(1) }} className="w-full rounded-xl border border-primary/20 px-3 py-2" placeholder="اسم البرنامج أو رمزه أو الكلية أو القسم" /></div>
      </label>
      {error && <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      <div className="mt-3 grid gap-2">{loading ? <FaSpinner className="mx-auto animate-spin text-primary" /> : programs.map(program => <label key={program.academic_program_id} className={`cursor-pointer rounded-xl border p-3 text-sm ${selected?.academic_program_id === program.academic_program_id ? 'border-primary bg-primary/5' : 'border-slate-200'}`}>
        <input type="radio" className="ml-2" checked={selected?.academic_program_id === program.academic_program_id} onChange={() => setSelected(program)} />
        {programOptionLabel(program)}
      </label>)}</div>
      {(meta.last_page ?? 1) > 1 && <div className="mt-3 flex justify-center gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage(value => value - 1)} className="rounded-lg border px-3 py-1 disabled:opacity-40">السابق</button><span>{page} / {meta.last_page}</span><button type="button" disabled={page >= meta.last_page} onClick={() => setPage(value => value + 1)} className="rounded-lg border px-3 py-1 disabled:opacity-40">التالي</button></div>}
      <div className="mt-5 flex justify-end gap-2"><button type="button" disabled={busy} onClick={onClose} className="rounded-xl border px-4 py-2">إلغاء</button><button type="button" disabled={!selected || busy} onClick={() => onConfirm(selected)} className="rounded-xl bg-primary px-4 py-2 font-bold text-white disabled:opacity-40">{busy ? 'جارٍ الحفظ...' : 'تأكيد المطابقة'}</button></div>
    </div>
  </div>
}
