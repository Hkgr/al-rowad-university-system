import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaExclamationTriangle, FaShieldAlt, FaSpinner, FaSyncAlt } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  reconciliationGateLabel,
  reconciliationIssueLabel,
  reconciliationSeverityLabel,
  reconciliationStateLabel,
} from '../lib/ministryPlacement'

const emptyFilters = { severity: '', pipeline_state: '', issue_code: '' }

function Gate({ payload, compact = false }) {
  const ready = payload?.production_gate === 'READY'
  return <div className={`rounded-xl border p-4 ${ready ? 'border-green-200 bg-green-50 text-green-800' : 'border-red-200 bg-red-50 text-red-800'}`}>
    <div className="flex items-center gap-2 font-black"><FaShieldAlt />{reconciliationGateLabel(payload?.production_gate)}</div>
    {!compact && <p className="mt-1 text-xs">{ready ? 'لا توجد حواجز مكتشفة في نطاق المصالحة الحالي.' : 'توجد حواجز بيانات يجب التحقيق فيها قبل اعتماد الجاهزية.'}</p>}
  </div>
}

function Metrics({ metrics = {} }) {
  const values = [
    ['إجمالي السجلات', metrics.total_records],
    ['سليمة', metrics.clean_records],
    ['تحذيرات', metrics.warning_records],
    ['محظورة', metrics.blocked_records],
    ['تعارضات الهوية', metrics.identity_conflict_records],
  ]
  return <div className="grid grid-cols-5 gap-2 max-[900px]:grid-cols-2">
    {values.map(([label, value]) => <div key={label} className="rounded-xl border border-slate-200 bg-white p-3"><strong className="block text-xl text-text-dark">{value ?? 0}</strong><span className="text-xs font-bold text-text-light">{label}</span></div>)}
  </div>
}

export function MinistryGlobalReconciliationCard({ revision = 0 }) {
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const generation = useRef(0)

  const load = useCallback(async () => {
    const current = ++generation.current
    setLoading(true)
    setError('')
    try {
      const response = await apiRequest('/v1/ministry-placement-reconciliation?per_page=1')
      if (generation.current === current) setPayload(response.data)
    } catch {
      if (generation.current === current) setError('تعذر تحميل بوابة الجاهزية النهائية. أعد المحاولة.')
    } finally {
      if (generation.current === current) setLoading(false)
    }
  }, [])

  useEffect(() => { load(); return () => { generation.current += 1 } }, [load, revision])

  return <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm" data-testid="ministry-global-production-gate">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-black text-text-dark">بوابة الجاهزية الإنتاجية</h2><p className="text-xs text-text-light">قراءة شاملة غير قابلة للتعديل لجميع دفعات المفاضلة.</p></div><button type="button" onClick={load} disabled={loading} className="rounded-lg border border-primary/20 p-2 text-primary disabled:opacity-50" aria-label="تحديث بوابة الجاهزية"><FaSyncAlt className={loading ? 'animate-spin' : ''} /></button></div>
    {loading && !payload ? <div className="flex justify-center p-5"><FaSpinner className="animate-spin text-primary" /></div> : error ? <div className="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{error}</div> : <div className="grid gap-4 md:grid-cols-[240px_1fr]"><Gate payload={payload} /><div><Metrics metrics={payload?.metrics} /><p className="mt-3 break-all font-mono text-[10px] text-text-light" dir="ltr">SHA-256: {payload?.reconciliation_checksum}</p></div></div>}
  </section>
}

export default function MinistryReconciliationPanel({ batch, revision = 0 }) {
  const [filters, setFilters] = useState(emptyFilters)
  const [page, setPage] = useState(1)
  const [payload, setPayload] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const generation = useRef(0)
  const context = useMemo(() => JSON.stringify({ batch: batch.batch_id, filters, page, revision }), [batch.batch_id, filters, page, revision])

  useEffect(() => {
    const current = ++generation.current
    const params = new URLSearchParams({ page: String(page), per_page: '25' })
    Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value) })
    setLoading(true)
    setError('')
    apiRequest(`/v1/ministry-placements/${batch.batch_id}/reconciliation?${params}`)
      .then(response => { if (generation.current === current) setPayload(response.data) })
      .catch(() => { if (generation.current === current) setError('تعذر تحميل التدقيق النهائي لهذه الدفعة.') })
      .finally(() => { if (generation.current === current) setLoading(false) })
    return () => { generation.current += 1 }
  }, [context, batch.batch_id, filters, page])

  function changeFilter(key, value) {
    setFilters(current => ({ ...current, [key]: value }))
    setPage(1)
  }

  return <div className="mt-5 space-y-4" data-testid="ministry-reconciliation-panel">
    {error && <div className="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700"><FaExclamationTriangle />{error}</div>}
    {payload && <><div className="grid gap-4 md:grid-cols-[240px_1fr]"><Gate payload={payload} /><div><Metrics metrics={payload.metrics} /><p className="mt-3 break-all font-mono text-[10px] text-text-light" dir="ltr">SHA-256: {payload.checksum}</p></div></div>
      <div className="grid grid-cols-3 gap-3 max-[760px]:grid-cols-1">
        <select aria-label="تصفية حسب الشدة" value={filters.severity} onChange={event => changeFilter('severity', event.target.value)} className="rounded-xl border border-primary/20 p-2.5"><option value="">كل درجات الشدة</option><option value="clean">سليم</option><option value="warning">تحذير</option><option value="blocked">محظور</option></select>
        <select aria-label="تصفية حسب حالة المسار" value={filters.pipeline_state} onChange={event => changeFilter('pipeline_state', event.target.value)} className="rounded-xl border border-primary/20 p-2.5"><option value="">كل حالات المسار</option>{['imported','matched','applicant_pending','documents_pending','enrolled','rejected','inconsistent'].map(state => <option key={state} value={state}>{reconciliationStateLabel(state)}</option>)}</select>
        <input aria-label="تصفية حسب رمز المشكلة" value={filters.issue_code} onChange={event => changeFilter('issue_code', event.target.value.trim())} placeholder="رمز المشكلة الدقيق" className="rounded-xl border border-primary/20 p-2.5 font-mono text-xs" dir="ltr" />
      </div>
      <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="min-w-full text-sm"><thead className="bg-primary/8"><tr>{['السجل','الصف','حالة المصدر','حالة المسار','الشدة','المتقدم / الطالب','المشكلات'].map(label => <th key={label} className="p-3 text-right">{label}</th>)}</tr></thead><tbody>{payload.records?.map(record => <tr key={record.placement_record_id} className="border-t align-top"><td className="p-3">#{record.placement_record_id}</td><td className="p-3">{record.row_number ?? '—'}</td><td className="p-3 font-mono text-xs">{record.processing_status}</td><td className="p-3 font-bold">{reconciliationStateLabel(record.pipeline_state)}</td><td className="p-3">{reconciliationSeverityLabel(record.reconciliation_severity)}</td><td className="p-3"><div>{record.applicant?.applicant_number || '—'}</div><div>{record.student?.student_number || '—'}</div></td><td className="min-w-80 p-3">{record.issues?.length ? record.issues.map((issue, index) => <div key={`${issue.code}-${index}`} className={issue.code === 'identity_conflict_multiple_terminal_records' ? 'mb-1 rounded-lg bg-red-100 p-2 font-bold text-red-800' : issue.severity === 'warning' ? 'mb-1 text-amber-700' : 'mb-1 text-red-700'}><span className="font-mono text-[10px]" dir="ltr">{issue.code}</span><span className="mr-2">{reconciliationIssueLabel(issue)}</span></div>) : '—'}</td></tr>)}</tbody></table></div>
      {!loading && (payload.records?.length ?? 0) === 0 && <p className="p-6 text-center text-text-light">لا توجد سجلات تطابق عوامل التصفية.</p>}
      {(payload.meta?.last_page ?? 1) > 1 && <div className="flex justify-center gap-2"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage(value => value - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">السابق</button><span className="p-2">{page} / {payload.meta.last_page}</span><button type="button" disabled={page >= payload.meta.last_page || loading} onClick={() => setPage(value => value + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">التالي</button></div>}
    </>}
    {loading && <div className="flex justify-center p-3"><FaSpinner className="animate-spin text-primary" /></div>}
    <p className="rounded-xl bg-slate-50 p-3 text-xs text-text-light">هذا العرض للقراءة والتدقيق فقط. لا يوفر إصلاحاً أو دمجاً أو تجاوزاً أو إنشاء حسابات أو تسجيل مقررات.</p>
  </div>
}
