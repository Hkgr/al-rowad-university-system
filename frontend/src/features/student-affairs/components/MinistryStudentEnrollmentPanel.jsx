import { useCallback, useEffect, useRef, useState } from 'react'
import { FaSpinner } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'
import {
  canBulkEnrollMinistryStudents,
  canEnrollMinistryStudent,
  enrollmentInputComplete,
  studentEnrollmentBlockerLabel,
  studentEnrollmentStateLabel,
} from '../lib/ministryPlacement'
import { createLatestRequestGuard } from '../lib/latestRequestGuard'

function metric(label, value, tone = 'text-primary') {
  return <div className="rounded-xl border border-primary/15 bg-white p-4"><strong className={`block text-2xl ${tone}`}>{value ?? 0}</strong><span className="text-xs font-bold text-text-light">{label}</span></div>
}

function Confirmation({ count = 1, busy, onCancel, onConfirm }) {
  const bulk = count > 1
  return <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
      <h3 className="text-lg font-black">تأكيد اعتماد وإنشاء طالب</h3>
      <p className="mt-3 whitespace-pre-line text-sm leading-7 text-text-light">{bulk
        ? `سيتم اعتماد ${count} طلب قبول وإنشاء ${count} سجل طالب.\nلن يتم إنشاء حسابات مستخدمين أو كلمات مرور أو تسجيل مقررات.`
        : 'سيتم اعتماد طلب القبول وإنشاء سجل الطالب رسميًا.\nلن يتم إنشاء حساب مستخدم أو كلمة مرور، ولن يتم تسجيل الطالب في أي مقرر.'}</p>
      <div className="mt-5 flex justify-end gap-2">
        <button type="button" disabled={busy} onClick={onCancel} className="rounded-xl border px-4 py-2">إلغاء</button>
        <button type="button" disabled={busy} onClick={onConfirm} className="rounded-xl bg-primary px-4 py-2 font-bold text-white disabled:opacity-50">{busy ? 'جارٍ الاعتماد...' : 'تأكيد الاعتماد والإنشاء'}</button>
      </div>
    </div>
  </div>
}

export default function MinistryStudentEnrollmentPanel({ batch, canManage, onChanged }) {
  const batchId = batch?.batch_id ?? null
  const currentBatchId = useRef(batchId)
  const requestGuard = useRef(createLatestRequestGuard())
  currentBatchId.current = batchId
  const [summary, setSummary] = useState(null)
  const [levels, setLevels] = useState([])
  const [inputs, setInputs] = useState({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [confirmation, setConfirmation] = useState(null)

  const load = useCallback(async () => {
    const capturedBatchId = currentBatchId.current
    if (!capturedBatchId) return false
    const request = requestGuard.current.begin({ batchId: capturedBatchId })
    setLoading(true)
    try {
      const [summaryResponse, levelsResponse] = await Promise.all([
        apiRequest(`/v1/ministry-placements/${capturedBatchId}/student-enrollment`),
        apiRequest('/v1/ministry-placement-academic-levels'),
      ])
      if (!requestGuard.current.isCurrent(request, { batchId: currentBatchId.current })) return false
      setSummary(summaryResponse.data)
      setLevels(levelsResponse.data ?? [])
      setInputs(current => Object.fromEntries((summaryResponse.data?.records ?? [])
        .filter(record => record.enrollment_state === 'ready')
        .map(record => [record.placement_record_id, current[record.placement_record_id] ?? { student_number: '', current_academic_level_id: '', enrollment_date: '' }])))
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
    setLevels([])
    setInputs({})
    setConfirmation(null)
    setError('')
    setSuccess('')
    setSaving(false)
    load()
    return () => requestGuard.current.invalidate()
  }, [batchId, load])

  function updateInput(recordId, field, value) {
    setInputs(current => ({ ...current, [recordId]: { ...current[recordId], [field]: value } }))
    setConfirmation(null)
  }

  function openIndividual(record) {
    const input = inputs[record.placement_record_id]
    if (!canEnrollMinistryStudent(canManage, record) || !enrollmentInputComplete(input)) return
    setConfirmation({ type: 'individual', batch_id: batchId, record, input })
  }

  function openBulk() {
    if (!canBulkEnrollMinistryStudents(canManage, summary, inputs)) return
    const items = summary.records.filter(record => record.enrollment_state === 'ready').map(record => ({
      placement_record_id: record.placement_record_id,
      ...inputs[record.placement_record_id],
      current_academic_level_id: Number(inputs[record.placement_record_id].current_academic_level_id),
    }))
    setConfirmation({ type: 'bulk', batch_id: batchId, count: items.length, items, eligible_count: summary.eligible_count, eligible_snapshot: summary.eligible_snapshot })
  }

  async function confirmEnrollment() {
    if (!confirmation || saving || confirmation.batch_id !== currentBatchId.current) {
      setConfirmation(null)
      return
    }
    const selection = confirmation
    setSaving(true)
    setError('')
    try {
      if (selection.type === 'individual') {
        await apiRequest(`/v1/ministry-placement-records/${selection.record.placement_record_id}/enroll-student`, {
          method: 'POST',
          body: JSON.stringify({
            student_number: selection.input.student_number,
            current_academic_level_id: Number(selection.input.current_academic_level_id),
            enrollment_date: selection.input.enrollment_date,
          }),
        })
        setSuccess('تم اعتماد طلب القبول وإنشاء سجل الطالب دون إنشاء حساب أو تسجيل مقررات.')
      } else {
        await apiRequest(`/v1/ministry-placements/${selection.batch_id}/student-enrollment/enroll-all`, {
          method: 'POST',
          body: JSON.stringify({ expected_eligible_count: selection.eligible_count, expected_snapshot: selection.eligible_snapshot, items: selection.items }),
        })
        setSuccess(`تم اعتماد ${selection.count} طلب قبول وإنشاء ${selection.count} سجل طالب دون إنشاء حسابات أو تسجيل مقررات.`)
      }
      if (currentBatchId.current !== selection.batch_id) return
      setConfirmation(null)
      await Promise.all([load(), onChanged?.()])
    } catch (err) {
      if (currentBatchId.current !== selection.batch_id) return
      setConfirmation(null)
      setError(err.message)
      if (err.errorCode === 'ministry_placement_enrollment_batch_stale') await Promise.all([load(), onChanged?.()])
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
      {metric('جاهز للاعتماد', metrics.ready_records)}
      {metric('تم إنشاء الطالب', metrics.enrolled_records, 'text-blue-700')}
      {metric('غير جاهز', metrics.not_ready_records, 'text-slate-600')}
      {metric('مرفوض', metrics.rejected_records, 'text-red-700')}
      {metric('يحتاج مراجعة', metrics.inconsistent_records, 'text-amber-700')}
    </div>
    {canBulkEnrollMinistryStudents(canManage, summary, inputs) && <div className="flex justify-end"><button type="button" onClick={openBulk} className="rounded-xl bg-primary px-5 py-3 font-bold text-white">اعتماد وإنشاء جميع الطلاب الجاهزين</button></div>}
    <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="min-w-full text-sm"><thead className="bg-primary/8"><tr>{['المتقدم','البرنامج والسنة','حالة الاعتماد','بيانات إنشاء الطالب','النتيجة','الإجراء'].map(label => <th key={label} className="whitespace-nowrap p-3 text-right">{label}</th>)}</tr></thead><tbody>
      {(summary?.records ?? []).map(record => {
        const ready = canEnrollMinistryStudent(canManage, record)
        const input = inputs[record.placement_record_id] ?? {}
        return <tr key={record.placement_record_id} className="border-t align-top">
          <td className="p-3"><strong>{record.applicant?.full_name || '—'}</strong><span className="block text-xs text-text-light">{record.applicant?.applicant_number || '—'}</span></td>
          <td className="p-3">{record.academic_program?.program_name || '—'}<span className="block text-xs text-text-light">{record.academic_year?.year_name || '—'}</span></td>
          <td className="min-w-52 p-3"><strong>{studentEnrollmentStateLabel(record.enrollment_state)}</strong>{record.blocker_code && <span className="mt-1 block text-xs text-amber-700">{studentEnrollmentBlockerLabel(record.blocker_code)}</span>}</td>
          <td className="min-w-[22rem] p-3">{ready ? <div className="grid gap-2">
            <input value={input.student_number ?? ''} onChange={event => updateInput(record.placement_record_id, 'student_number', event.target.value)} maxLength={50} placeholder="رقم الطالب" className="rounded-lg border p-2" dir="ltr" />
            <select value={input.current_academic_level_id ?? ''} onChange={event => updateInput(record.placement_record_id, 'current_academic_level_id', event.target.value)} className="rounded-lg border p-2"><option value="">اختر المستوى الأكاديمي الحالي</option>{levels.map(level => <option key={level.academic_level_id} value={level.academic_level_id}>{level.level_name}</option>)}</select>
            <input type="date" value={input.enrollment_date ?? ''} onChange={event => updateInput(record.placement_record_id, 'enrollment_date', event.target.value)} className="rounded-lg border p-2" />
          </div> : <span className="text-xs text-text-light">لا توجد مدخلات متاحة لهذه الحالة</span>}</td>
          <td className="min-w-56 p-3">{record.student ? <><strong>{record.student.student_number}</strong><span className="block text-xs text-text-light">#{record.student.student_id} · {record.student.current_academic_level?.level_name || '—'} · {record.student.student_status?.status_name || '—'} · {record.student.enrollment_date}</span><span className="mt-1 block text-xs">قرار الطلب: {record.admission_application?.decision_status}</span></> : '—'}</td>
          <td className="p-3">{ready ? <button type="button" disabled={!enrollmentInputComplete(input)} onClick={() => openIndividual(record)} className="rounded-lg bg-primary px-3 py-2 text-xs font-bold text-white disabled:opacity-40">اعتماد وإنشاء طالب</button> : <span className="text-xs text-text-light">للقراءة فقط</span>}</td>
        </tr>
      })}
    </tbody></table></div>
    {(summary?.records ?? []).length === 0 && <p className="p-8 text-center text-text-light">لا توجد سجلات في هذه الدفعة.</p>}
    {confirmation && <Confirmation count={confirmation.type === 'bulk' ? confirmation.count : 1} busy={saving} onCancel={() => setConfirmation(null)} onConfirm={confirmEnrollment} />}
  </div>
}
