import { useCallback, useEffect, useRef, useState } from 'react'
import { FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import { programOptionLabel, programSuggestionStatusLabel } from '../lib/ministryPlacement'
import { bindSelectionToBatch, createLatestRequestGuard, selectionForBatch } from '../lib/latestRequestGuard'
import MinistryProgramPickerDialog from './MinistryProgramPickerDialog'

function countCard(label, value, tone = 'text-primary') {
  return <div className="rounded-xl border border-primary/15 bg-white p-4"><strong className={`block text-2xl ${tone}`}>{value ?? 0}</strong><span className="text-xs font-bold text-text-light">{label}</span></div>
}

export default function MinistryProgramMatchingPanel({ batch, canManage, onChanged }) {
  const [summary, setSummary] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [selectedGroup, setSelectedGroup] = useState(null)
  const [saving, setSaving] = useState(false)
  const batchId = batch?.batch_id ?? null
  const currentBatchId = useRef(batchId)
  const summaryRequestGuard = useRef(createLatestRequestGuard())
  currentBatchId.current = batchId

  const load = useCallback(async () => {
    const capturedBatchId = currentBatchId.current
    if (!capturedBatchId) return false
    const request = summaryRequestGuard.current.begin({ batchId: capturedBatchId })
    setLoading(true)
    try {
      const response = await apiRequest(`/v1/ministry-placements/${capturedBatchId}/program-matching`)
      if (!summaryRequestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) return false
      setSummary(response.data)
      return true
    } catch (err) {
      if (!summaryRequestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) return false
      setError(err.message)
      return false
    } finally {
      if (summaryRequestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) setLoading(false)
    }
  }, [])

  useEffect(() => {
    summaryRequestGuard.current.invalidate()
    setSummary(null)
    setSelectedGroup(null)
    setSuccess('')
    setError('')
    setLoading(true)
    setSaving(false)
    load()
    return () => summaryRequestGuard.current.invalidate()
  }, [batchId, load])

  async function applyGroup(program) {
    if (!selectedGroup || saving) return
    const selected = selectionForBatch(selectedGroup, currentBatchId.current)
    if (!selected) {
      setSelectedGroup(null)
      await load()
      return
    }
    const selectedBatchId = selectedGroup.batch_id
    setSaving(true)
    setError('')
    try {
      await apiRequest(`/v1/ministry-placements/${selectedBatchId}/program-matching/apply-group`, {
        method: 'POST',
        body: JSON.stringify({
          preference_key: selected.preference_key,
          academic_program_id: program.academic_program_id,
          expected_eligible_count: selected.bulk_eligible_unmatched_count,
        }),
      })
      if (currentBatchId.current !== selectedBatchId) return
      setSuccess('تمت مطابقة السجلات غير المطابقة فقط، مع الحفاظ على المطابقات الفردية.')
      setSelectedGroup(null)
      await Promise.all([load(), onChanged?.()])
    } catch (err) {
      if (currentBatchId.current !== selectedBatchId) return
      setError(err.message)
      if (err.errorCode === 'ministry_placement_group_stale') await Promise.all([load(), onChanged?.()])
    } finally {
      if (currentBatchId.current === selectedBatchId) setSaving(false)
    }
  }

  if (loading && !summary) return <div className="flex justify-center p-8"><FaSpinner className="animate-spin text-2xl text-primary" /></div>

  const metrics = summary?.metrics ?? {}
  return <div className="mt-5 space-y-4">
    {error && <p className="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{error}</p>}
    {success && <p className="rounded-xl bg-green-50 p-3 text-sm font-bold text-green-700">{success}</p>}
    <div className="grid grid-cols-5 gap-3 max-[900px]:grid-cols-2">
      {countCard('إجمالي السجلات', metrics.total_records)}
      {countCard('غير مطابق', metrics.unmatched_records)}
      {countCard('تمت المطابقة', metrics.matched_records)}
      {countCard('بحاجة للمراجعة', metrics.stale_match_records, 'text-amber-700')}
      {countCard('مقفل', metrics.locked_records, 'text-slate-600')}
    </div>
    <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="min-w-full text-sm"><thead className="bg-primary/8"><tr>{['رغبة الوزارة','عدد السجلات','متاح للمطابقة الجماعية','مطابق فردياً','بحاجة للمراجعة','مقفل','الاقتراح','البرنامج الحالي','الإجراء'].map(label => <th key={label} className="whitespace-nowrap p-3 text-right">{label}</th>)}</tr></thead><tbody>
      {(summary?.groups ?? []).map(group => <tr key={group.preference_key} className="border-t align-top">
        <td className="max-w-72 p-3">{group.display_preference || 'غير محددة'}</td><td className="p-3">{group.record_count}</td><td className="p-3 font-bold text-primary">{group.bulk_eligible_unmatched_count}</td><td className="p-3">{group.already_matched_count}</td><td className="p-3 text-amber-700">{group.stale_match_count}</td><td className="p-3">{group.locked_count}</td>
        <td className="min-w-64 p-3"><span className="text-xs text-text-light">{programSuggestionStatusLabel(group.suggestion_status)}</span>{group.suggestions?.map(program => <div key={program.academic_program_id} className="mt-1 text-xs">{programOptionLabel(program)}</div>)}</td>
        <td className="min-w-52 p-3">{group.current_programs?.length ? group.current_programs.map(program => <div key={program.academic_program_id}>{program.program_name || `#${program.academic_program_id}`} ({program.record_count})</div>) : '—'}</td>
        <td className="p-3">{canManage && group.bulk_eligible_unmatched_count > 0 ? <button type="button" onClick={() => setSelectedGroup(bindSelectionToBatch(batchId, group))} className="rounded-lg bg-primary px-3 py-2 font-bold text-white">اختيار وتطبيق</button> : <span className="text-xs text-text-light">للقراءة فقط</span>}</td>
      </tr>)}
    </tbody></table></div>
    {(summary?.groups ?? []).length === 0 && <p className="p-8 text-center text-text-light">لا توجد سجلات في هذه الدفعة.</p>}
    <MinistryProgramPickerDialog open={Boolean(selectedGroup)} title="تأكيد مطابقة مجموعة" message={selectedGroup ? `سيتم ربط ${selectedGroup.group.bulk_eligible_unmatched_count} سجلاً غير مطابق فقط. لن تتغير المطابقات الفردية أو السجلات المقفلة أو التي تحتاج مراجعة.` : ''} suggestions={selectedGroup?.group.suggestions ?? []} busy={saving} onClose={() => setSelectedGroup(null)} onConfirm={applyGroup} />
  </div>
}
