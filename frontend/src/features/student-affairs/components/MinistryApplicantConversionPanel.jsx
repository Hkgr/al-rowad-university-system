import { useCallback, useEffect, useRef, useState } from 'react'
import { FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  applicantConversionBlockerLabel,
  applicantConversionStateLabel,
  canBulkConvertMinistryApplicants,
  canConvertMinistryRecord,
} from '../lib/ministryPlacement'
import { createLatestRequestGuard } from '../lib/latestRequestGuard'

function metric(label, value, tone = 'text-primary') {
  return <div className="rounded-xl border border-primary/15 bg-white p-4"><strong className={`block text-2xl ${tone}`}>{value ?? 0}</strong><span className="text-xs font-bold text-text-light">{label}</span></div>
}

function Confirmation({ title, message, busy, onCancel, onConfirm }) {
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
      <h3 className="text-lg font-black">{title}</h3>
      <p className="mt-3 whitespace-pre-line text-sm leading-7 text-text-light">{message}</p>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" disabled={busy} onClick={onCancel} className="rounded-xl border px-4 py-2">إلغاء</button>
        <button type="button" disabled={busy} onClick={onConfirm} className="rounded-xl bg-primary px-4 py-2 font-bold text-white disabled:opacity-50">{busy ? 'جارٍ التحويل...' : 'تأكيد التحويل'}</button>
      </div>
    </div>
  </div>
}

export default function MinistryApplicantConversionPanel({ batch, canManage, onChanged }) {
  const batchId = batch?.batch_id ?? null
  const currentBatchId = useRef(batchId)
  const requestGuard = useRef(createLatestRequestGuard())
  currentBatchId.current = batchId
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [selectedRecord, setSelectedRecord] = useState(null)
  const [bulkSelection, setBulkSelection] = useState(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    const capturedBatchId = currentBatchId.current
    if (!capturedBatchId) return false
    const request = requestGuard.current.begin({ batchId: capturedBatchId })
    setLoading(true)
    try {
      const response = await apiRequest(`/v1/ministry-placements/${capturedBatchId}/applicant-conversion`)
      if (!requestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) return false
      setSummary(response.data)
      return true
    } catch (err) {
      if (!requestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) return false
      setError(err.message)
      return false
    } finally {
      if (requestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) setLoading(false)
    }
  }, [])

  useEffect(() => {
    requestGuard.current.invalidate()
    setSummary(null)
    setSelectedRecord(null)
    setBulkSelection(null)
    setError('')
    setSuccess('')
    setSaving(false)
    load()
    return () => requestGuard.current.invalidate()
  }, [batchId, load])

  async function convertRecord() {
    if (!selectedRecord || saving || selectedRecord.batch_id !== currentBatchId.current) {
      setSelectedRecord(null)
      return
    }
    const selection = selectedRecord
    setSaving(true)
    setError('')
    try {
      await apiRequest(`/v1/ministry-placement-records/${selection.record.placement_record_id}/convert-to-applicant`, { method: 'POST' })
      if (currentBatchId.current !== selection.batch_id) return
      setSelectedRecord(null)
      setSuccess('تم إنشاء سجل المتقدم وطلب القبول المعلق دون إنشاء طالب أو حساب مستخدم.')
      await Promise.all([load(), onChanged?.()])
    } catch (err) {
      if (currentBatchId.current === selection.batch_id) setError(err.message)
    } finally {
      if (currentBatchId.current === selection.batch_id) setSaving(false)
    }
  }

  async function convertAll() {
    if (!bulkSelection || saving || bulkSelection.batch_id !== currentBatchId.current) {
      setBulkSelection(null)
      return
    }
    const selection = bulkSelection
    setSaving(true)
    setError('')
    try {
      await apiRequest(`/v1/ministry-placements/${selection.batch_id}/applicant-conversion/convert-all`, {
        method: 'POST',
        body: JSON.stringify({
          expected_eligible_count: selection.eligible_count,
          expected_snapshot: selection.eligible_snapshot,
        }),
      })
      if (currentBatchId.current !== selection.batch_id) return
      setBulkSelection(null)
      setSuccess(`تم إنشاء ${selection.eligible_count} متقدماً وطلب قبول معلقاً دون إنشاء طلاب أو حسابات مستخدمين.`)
      await Promise.all([load(), onChanged?.()])
    } catch (err) {
      if (currentBatchId.current !== selection.batch_id) return
      setError(err.message)
      setBulkSelection(null)
      if (err.errorCode === 'ministry_placement_conversion_batch_stale') await Promise.all([load(), onChanged?.()])
    } finally {
      if (currentBatchId.current === selection.batch_id) setSaving(false)
    }
  }

  if (loading && !summary) return <div className="flex justify-center p-8"><FaSpinner className="animate-spin text-2xl text-primary" /></div>

  const metrics = summary?.metrics ?? {}
  return <div className="mt-5 space-y-4">
    {error && <p className="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{error}</p>}
    {success && <p className="rounded-xl bg-green-50 p-3 text-sm font-bold text-green-700">{success}</p>}
    <div className="grid grid-cols-5 gap-3 max-[900px]:grid-cols-2">
      {metric('جاهز للتحويل', metrics.convertible_records)}
      {metric('تم إنشاء المتقدم', metrics.converted_records)}
      {metric('غير جاهز', metrics.not_ready_records, 'text-slate-600')}
      {metric('يحتاج مراجعة', metrics.inconsistent_records, 'text-amber-700')}
      {metric('مرحلة لاحقة', metrics.later_stage_records, 'text-blue-700')}
    </div>
    {canBulkConvertMinistryApplicants(canManage, summary) && <div className="flex justify-end">
      <button type="button" onClick={() => setBulkSelection({ batch_id: batchId, eligible_count: summary.eligible_count, eligible_snapshot: summary.eligible_snapshot })} className="rounded-xl bg-primary px-5 py-3 font-bold text-white">تحويل جميع السجلات الجاهزة</button>
    </div>}
    <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="min-w-full text-sm"><thead className="bg-primary/8"><tr>{['الصف','الاسم','البرنامج','السنة الأكاديمية','حالة التحويل','المتقدم وطلب القبول','الإجراء'].map(label => <th key={label} className="whitespace-nowrap p-3 text-right">{label}</th>)}</tr></thead><tbody>
      {(summary?.records ?? []).map(record => <tr key={record.placement_record_id} className="border-t align-top">
        <td className="p-3">{record.row_number ?? '—'}</td>
        <td className="p-3">{record.full_name || '—'}</td>
        <td className="p-3">{record.academic_program?.program_name || '—'}</td>
        <td className="p-3">{record.academic_year?.year_name || '—'}</td>
        <td className="min-w-56 p-3"><strong>{applicantConversionStateLabel(record.conversion_state)}</strong>{record.blocker_code && <span className="mt-1 block text-xs text-amber-700">{applicantConversionBlockerLabel(record.blocker_code)}</span>}{record.identity_conflict && <span className="mt-1 block text-xs text-amber-700">توجد هوية وزارة مكررة؛ المراجع الآمنة: {(record.identity_conflicts ?? []).map(item => `دفعة ${item.batch_id}/سجل ${item.placement_record_id}`).join('، ')}</span>}</td>
        <td className="min-w-56 p-3">{record.applicant ? <><div>{record.applicant.applicant_number}</div><div className="text-xs text-text-light">حالة الطلب: {record.admission_application?.decision_status || '—'}</div></> : '—'}</td>
        <td className="p-3">{canConvertMinistryRecord(canManage, record) ? <button type="button" onClick={() => setSelectedRecord({ batch_id: batchId, record })} className="rounded-lg bg-primary px-3 py-2 text-xs font-bold text-white">إنشاء متقدم وطلب قبول</button> : <span className="text-xs text-text-light">للقراءة فقط</span>}</td>
      </tr>)}
    </tbody></table></div>
    {(summary?.records ?? []).length === 0 && <p className="p-8 text-center text-text-light">لا توجد سجلات في هذه الدفعة.</p>}

    {selectedRecord && <Confirmation title="تأكيد إنشاء المتقدم" message={'سيتم إنشاء سجل متقدم وطلب قبول بحالة معلقة فقط.\nلن يتم إنشاء طالب أو حساب مستخدم.'} busy={saving} onCancel={() => setSelectedRecord(null)} onConfirm={convertRecord} />}
    {bulkSelection && <Confirmation title="تأكيد التحويل الجماعي" message={`سيتم إنشاء ${bulkSelection.eligible_count} متقدماً و${bulkSelection.eligible_count} طلب قبول بحالة معلقة.\nلن يتم إنشاء طلاب أو حسابات مستخدمين.`} busy={saving} onCancel={() => setBulkSelection(null)} onConfirm={convertAll} />}
  </div>
}
