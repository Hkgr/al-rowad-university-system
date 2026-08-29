import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaCheckCircle, FaExclamationTriangle, FaFileExcel, FaSearch, FaSpinner, FaUpload } from 'react-icons/fa'
import { hasAssignedPermission, PERMISSIONS } from '../../auth/auth'
import { apiRequest } from '../../../services/apiClient'
import MinistryProgramMatchingPanel from '../components/MinistryProgramMatchingPanel'
import MinistryApplicantConversionPanel from '../components/MinistryApplicantConversionPanel'
import MinistryProgramPickerDialog from '../components/MinistryProgramPickerDialog'
import { createLatestRequestGuard } from '../lib/latestRequestGuard'
import {
  buildMinistryPlacementFormData,
  canImportPreview,
  paginatedRows,
  paginationMeta,
  previewStatusLabel,
  canMutateProgramMatch,
  programMatchStateLabel,
  rowErrorLabels,
  workbookIssueLabel,
} from '../lib/ministryPlacement'

const emptyForm = { file: null, academic_year_id: '', batch_name: '', notes: '' }

function metric(label, value, tone = 'green') {
  const colors = {
    green: 'border-primary/20 bg-primary/5 text-primary',
    red: 'border-red-200 bg-red-50 text-red-700',
    amber: 'border-amber-200 bg-amber-50 text-amber-700',
    slate: 'border-slate-200 bg-slate-50 text-slate-700',
  }
  return <div className={`rounded-xl border p-4 ${colors[tone]}`}><strong className="block text-2xl">{value ?? 0}</strong><span className="text-xs font-bold">{label}</span></div>
}

export default function MinistryPlacementsPage() {
  const canManage = hasAssignedPermission(PERMISSIONS.admissionsManage)
  const [form, setForm] = useState(emptyForm)
  const [preview, setPreview] = useState(null)
  const [batches, setBatches] = useState([])
  const [batchMeta, setBatchMeta] = useState({})
  const [batchPage, setBatchPage] = useState(1)
  const [years, setYears] = useState([])
  const [selectedBatch, setSelectedBatch] = useState(null)
  const [records, setRecords] = useState([])
  const [recordMeta, setRecordMeta] = useState({})
  const [recordPage, setRecordPage] = useState(1)
  const [search, setSearch] = useState('')
  const [batchView, setBatchView] = useState('records')
  const [recordMatch, setRecordMatch] = useState(null)
  const [recordUnmatch, setRecordUnmatch] = useState(null)
  const [matchingRecord, setMatchingRecord] = useState(false)
  const [loading, setLoading] = useState(true)
  const [checking, setChecking] = useState(false)
  const [importing, setImporting] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const recordsRequestGuard = useRef(createLatestRequestGuard())
  const recordsContext = useRef({ batchId: null, page: 1, search: '' })
  recordsContext.current = {
    batchId: selectedBatch?.batch_id ?? null,
    page: recordPage,
    search: search.trim(),
  }

  const loadBatches = useCallback(async (page = 1) => {
    const response = await apiRequest(`/v1/ministry-placements?page=${page}&per_page=15`)
    setBatches(paginatedRows(response))
    setBatchMeta(paginationMeta(response))
  }, [])

  useEffect(() => {
    let active = true
    Promise.all([
      apiRequest('/v1/ministry-placements?per_page=15'),
      canManage ? apiRequest('/v1/academic-years?per_page=100') : Promise.resolve(null),
    ]).then(([batchResponse, yearResponse]) => {
      if (!active) return
      setBatches(paginatedRows(batchResponse))
      setBatchMeta(paginationMeta(batchResponse))
      setYears(yearResponse ? paginatedRows(yearResponse) : [])
    }).catch(err => active && setError(err.message)).finally(() => active && setLoading(false))
    return () => { active = false }
  }, [canManage])

  const loadRecords = useCallback(async () => {
    if (!selectedBatch) return
    const context = {
      batchId: selectedBatch.batch_id,
      page: recordPage,
      search: search.trim(),
    }
    const request = recordsRequestGuard.current.begin(context)
    const params = new URLSearchParams({ page: String(context.page), per_page: '25' })
    if (context.search) params.set('q', context.search)
    try {
      const response = await apiRequest(`/v1/ministry-placements/${context.batchId}/records?${params}`)
      if (!recordsRequestGuard.current.isCurrent(request, recordsContext.current)) return false
      setRecords(paginatedRows(response))
      setRecordMeta(paginationMeta(response))
      return true
    } catch (err) {
      if (!recordsRequestGuard.current.isCurrent(request, recordsContext.current)) return false
      setError(err.message)
      return false
    }
  }, [selectedBatch, recordPage, search])

  useEffect(() => {
    loadRecords()
    return () => recordsRequestGuard.current.invalidate()
  }, [loadRecords])

  const selectBatch = useCallback((batch) => {
    recordsContext.current = { batchId: batch?.batch_id ?? null, page: 1, search: '' }
    recordsRequestGuard.current.invalidate()
    setRecords([])
    setRecordMeta({})
    setRecordMatch(null)
    setRecordUnmatch(null)
    setSelectedBatch(batch)
    setRecordPage(1)
    setSearch('')
    setBatchView('records')
  }, [])

  function changeRecordSearch(value) {
    recordsContext.current = { ...recordsContext.current, page: 1, search: value.trim() }
    recordsRequestGuard.current.invalidate()
    setSearch(value)
    setRecordPage(1)
  }

  function changeRecordPage(page) {
    recordsContext.current = { ...recordsContext.current, page }
    recordsRequestGuard.current.invalidate()
    setRecordPage(page)
  }

  const importReady = useMemo(() => canImportPreview(preview), [preview])

  function update(key, value) {
    setForm(current => ({ ...current, [key]: value }))
    setPreview(null)
    setSuccess('')
  }

  async function inspectFile(event) {
    event.preventDefault()
    if (!form.file) return
    setChecking(true)
    setError('')
    try {
      const response = await apiRequest('/v1/ministry-placements/preview', {
        method: 'POST',
        body: buildMinistryPlacementFormData(form.file),
      })
      setPreview(response.data)
    } catch (err) {
      setPreview(null)
      setError(err.message)
    } finally {
      setChecking(false)
    }
  }

  async function importBatch() {
    if (!importReady || importing) return
    setImporting(true)
    setError('')
    try {
      const response = await apiRequest('/v1/ministry-placements/import', {
        method: 'POST',
        body: buildMinistryPlacementFormData(form.file, {
          academic_year_id: form.academic_year_id,
          batch_name: form.batch_name,
          notes: form.notes,
        }),
      })
      setSuccess('تم اعتماد واستيراد الدفعة بنجاح.')
      setPreview(null)
      setForm(emptyForm)
      selectBatch(response.data)
      await loadBatches(1)
      setBatchPage(1)
    } catch (err) {
      setError(err.message)
    } finally {
      setImporting(false)
    }
  }

  async function changeBatchPage(page) {
    setLoading(true)
    setError('')
    try {
      await loadBatches(page)
      setBatchPage(page)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  async function saveRecordMatch(program) {
    if (!recordMatch || matchingRecord) return
    setMatchingRecord(true)
    setError('')
    try {
      await apiRequest(`/v1/ministry-placement-records/${recordMatch.placement_record_id}/program-match`, {
        method: 'PUT',
        body: JSON.stringify({ academic_program_id: program.academic_program_id }),
      })
      setSuccess('تم تحديث المطابقة الفردية بنجاح.')
      setRecordMatch(null)
      await loadRecords()
    } catch (err) {
      setError(err.message)
    } finally {
      setMatchingRecord(false)
    }
  }

  async function removeRecordMatch() {
    if (!recordUnmatch || matchingRecord) return
    setMatchingRecord(true)
    setError('')
    try {
      await apiRequest(`/v1/ministry-placement-records/${recordUnmatch.placement_record_id}/program-match`, { method: 'DELETE' })
      setSuccess('تمت إزالة المطابقة الفردية بنجاح.')
      setRecordUnmatch(null)
      await loadRecords()
    } catch (err) {
      setError(err.message)
    } finally {
      setMatchingRecord(false)
    }
  }

  return (
    <div className="space-y-6" dir="rtl">
      <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h1 className="text-xl font-black text-text-dark">دفعات مفاضلة الوزارة</h1>
            <p className="mt-1 text-sm text-text-light">فحص ملف الوزارة، مراجعة مطابقة البرامج، ثم التحويل الآمن إلى متقدم وطلب قبول معلق دون إنشاء طالب أو حساب مستخدم.</p>
          </div>
          <div className="rounded-xl bg-primary/10 p-3 text-2xl text-primary"><FaFileExcel /></div>
        </div>
      </section>

      {error && <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-700">{error}</div>}
      {success && <div className="flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-bold text-green-700"><FaCheckCircle />{success}</div>}

      {canManage && <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm">
        <h2 className="mb-4 text-base font-black text-text-dark">رفع ملف مفاضلة</h2>
        <form onSubmit={inspectFile} className="grid grid-cols-2 gap-4 max-[760px]:grid-cols-1">
          <label className="text-sm font-bold text-text-dark">ملف Excel
            <input type="file" accept=".xlsx,.xls" onChange={event => update('file', event.target.files?.[0] ?? null)} className="mt-2 block w-full rounded-xl border border-primary/20 p-3 text-sm" required />
          </label>
          <label className="text-sm font-bold text-text-dark">السنة الأكاديمية
            <select value={form.academic_year_id} onChange={event => update('academic_year_id', event.target.value)} className="mt-2 block w-full rounded-xl border border-primary/20 p-3" required>
              <option value="">اختر السنة</option>
              {years.map(year => <option key={year.academic_year_id} value={year.academic_year_id}>{year.year_name}</option>)}
            </select>
          </label>
          <label className="text-sm font-bold text-text-dark">اسم الدفعة
            <input value={form.batch_name} onChange={event => update('batch_name', event.target.value)} maxLength={255} className="mt-2 block w-full rounded-xl border border-primary/20 p-3" required />
          </label>
          <label className="text-sm font-bold text-text-dark">ملاحظات
            <input value={form.notes} onChange={event => update('notes', event.target.value)} className="mt-2 block w-full rounded-xl border border-primary/20 p-3" />
          </label>
          <div className="col-span-2 flex justify-end max-[760px]:col-span-1">
            <button type="submit" disabled={!form.file || checking} className="flex items-center gap-2 rounded-xl bg-primary px-5 py-3 font-bold text-white disabled:opacity-50">
              {checking ? <FaSpinner className="animate-spin" /> : <FaSearch />} فحص الملف
            </button>
          </div>
        </form>
      </section>}

      {preview && <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm">
        <div className="grid grid-cols-4 gap-3 max-[760px]:grid-cols-2">
          {metric('إجمالي الصفوف', preview.rows_count, 'slate')}
          {metric('السجلات الصحيحة', preview.valid_rows)}
          {metric('السجلات الخاطئة', preview.invalid_rows, 'red')}
          {metric('السجلات المكررة', preview.duplicate_rows, 'amber')}
        </div>
        {(preview.warnings?.length > 0 || preview.structural_errors?.length > 0) && <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          {[...(preview.warnings ?? []), ...(preview.structural_errors ?? [])].map(item => <div key={item}>• {workbookIssueLabel(item)} <span className="font-mono text-xs" dir="ltr">({item})</span></div>)}
        </div>}
        <div className="mt-5 overflow-x-auto rounded-xl border border-slate-200">
          <table className="min-w-full text-sm"><thead className="bg-primary/8 text-text-dark"><tr>{['رقم الصف','الرقم الوطني','رقم الاكتتاب','الاسم','اسم الأب','تاريخ الميلاد','الرغبة المقبولة','المجموع','الحالة','الأخطاء'].map(label => <th key={label} className="whitespace-nowrap p-3 text-right">{label}</th>)}</tr></thead>
            <tbody>{preview.normalized_preview_rows?.map(row => <tr key={row.source_row} className="border-t border-slate-100">
              <td className="p-3">{row.source_row}</td><td className="p-3 font-mono" dir="ltr">{row.national_civil_id || '—'}</td><td className="p-3 font-mono" dir="ltr">{row.subscription_number || '—'}</td><td className="p-3">{row.full_name || '—'}</td><td className="p-3">{row.father_name || '—'}</td><td className="p-3">{row.date_of_birth || '—'}</td><td className="p-3">{row.accepted_preference_text || '—'}</td><td className="p-3">{row.total_score ?? '—'}</td><td className={`p-3 font-bold ${row.status === 'valid' ? 'text-green-700' : row.status === 'duplicate' ? 'text-amber-700' : 'text-red-700'}`}>{previewStatusLabel(row.status)}</td><td className="min-w-56 p-3 text-xs text-red-700">{rowErrorLabels(row.errors).length > 0 ? rowErrorLabels(row.errors).map(reason => <div key={reason}>• {reason}</div>) : '—'}</td>
            </tr>)}</tbody>
          </table>
        </div>
        {!importReady && <div className="mt-4 flex items-center gap-2 rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700"><FaExclamationTriangle />يجب تصحيح أخطاء الملف قبل الاستيراد.</div>}
        <div className="mt-4 flex justify-end"><button type="button" onClick={importBatch} disabled={!importReady || importing || !form.academic_year_id || !form.batch_name} className="flex items-center gap-2 rounded-xl bg-primary px-5 py-3 font-bold text-white disabled:opacity-50">{importing ? <FaSpinner className="animate-spin" /> : <FaUpload />}اعتماد واستيراد الدفعة</button></div>
      </section>}

      <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm">
        <h2 className="mb-4 text-base font-black text-text-dark">الدفعات المستوردة</h2>
        {loading ? <div className="flex justify-center p-8"><FaSpinner className="animate-spin text-2xl text-primary" /></div> : batches.length === 0 ? <p className="p-8 text-center text-text-light">لا توجد دفعات مستوردة بعد.</p> : <div className="grid gap-3">
          {batches.map(batch => <button key={batch.batch_id} onClick={() => selectBatch(batch)} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/15 p-4 text-right hover:bg-primary/5">
            <div><strong className="block text-text-dark">{batch.batch_name}</strong><span className="text-xs text-text-light">#{batch.batch_id} · {batch.academic_year?.year_name ?? batch.academic_year_id}</span></div>
            <div className="text-sm text-text-gray">{batch.records_count ?? 0} سجل · {batch.import_date}</div>
          </button>)}
        </div>}
        {(batchMeta.last_page ?? 1) > 1 && <div className="mt-4 flex justify-center gap-2"><button disabled={batchPage <= 1} onClick={() => changeBatchPage(batchPage - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">السابق</button><span className="p-2">{batchPage} / {batchMeta.last_page}</span><button disabled={batchPage >= batchMeta.last_page} onClick={() => changeBatchPage(batchPage + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">التالي</button></div>}
      </section>

      {selectedBatch && <section className="rounded-2xl border border-primary/15 bg-white p-6 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-base font-black">{selectedBatch.batch_name}</h2><p className="text-xs text-text-light">المصدر: {selectedBatch.source_file_name || '—'} · المستورد: {selectedBatch.imported_by?.username || selectedBatch.imported_by_user_id || '—'}</p></div></div>
        <div className="mt-4 flex flex-wrap gap-2 border-b border-slate-200 pb-3">
          <button type="button" onClick={() => setBatchView('records')} className={`rounded-lg px-4 py-2 font-bold ${batchView === 'records' ? 'bg-primary text-white' : 'bg-slate-100 text-text-dark'}`}>السجلات</button>
          <button type="button" onClick={() => setBatchView('program_matching')} className={`rounded-lg px-4 py-2 font-bold ${batchView === 'program_matching' ? 'bg-primary text-white' : 'bg-slate-100 text-text-dark'}`}>مطابقة البرامج</button>
          <button type="button" onClick={() => setBatchView('applicant_conversion')} className={`rounded-lg px-4 py-2 font-bold ${batchView === 'applicant_conversion' ? 'bg-primary text-white' : 'bg-slate-100 text-text-dark'}`}>تحويل إلى متقدم</button>
        </div>

        {batchView === 'program_matching' && <MinistryProgramMatchingPanel key={selectedBatch.batch_id} batch={selectedBatch} canManage={canManage} onChanged={loadRecords} />}
        {batchView === 'applicant_conversion' && <MinistryApplicantConversionPanel key={selectedBatch.batch_id} batch={selectedBatch} canManage={canManage} onChanged={loadRecords} />}

        {batchView === 'records' && <>
          <div className="mt-4 flex justify-end"><input value={search} onChange={event => changeRecordSearch(event.target.value)} placeholder="بحث في السجلات" className="rounded-xl border border-primary/20 px-4 py-2" /></div>
          <div className="mt-4 overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-primary/8"><tr>{['الصف','الرقم الوطني','الاسم','رغبة الوزارة','البرنامج المطابق','حالة المطابقة','الإجراء'].map(label => <th key={label} className="p-3 text-right">{label}</th>)}</tr></thead><tbody>{records.map(row => {
            const mutable = canMutateProgramMatch(canManage, row)
            return <tr key={row.placement_record_id} className="border-t align-top"><td className="p-3">{row.row_number}</td><td className="p-3 font-mono" dir="ltr">{row.national_civil_id}</td><td className="p-3">{row.full_name}</td><td className="max-w-64 p-3">{row.accepted_preference_text || '—'}</td><td className="p-3">{row.matched_academic_program?.program_name || '—'}{row.program_match_state === 'stale_match' && row.matched_academic_program_id && <span className="mt-1 block text-xs font-bold text-amber-700">البرنامج المطابق غير نشط أو أن حالة السجل تحتاج مراجعة</span>}</td><td className="p-3 font-bold">{programMatchStateLabel(row.program_match_state)}{row.program_match_state === 'locked' && <span className="mt-1 block max-w-56 text-xs font-normal text-text-light">تم الانتقال إلى مرحلة لاحقة — المطابقة للقراءة فقط</span>}</td><td className="p-3"><div className="flex flex-wrap gap-2">{mutable && <button type="button" onClick={() => setRecordMatch(row)} className="rounded-lg bg-primary px-3 py-2 text-xs font-bold text-white">{row.matched_academic_program_id ? 'تعديل المطابقة' : 'مطابقة البرنامج'}</button>}{mutable && row.matched_academic_program_id && <button type="button" onClick={() => setRecordUnmatch(row)} className="rounded-lg border border-red-200 px-3 py-2 text-xs font-bold text-red-700">إزالة المطابقة</button>}{!mutable && <span className="text-xs text-text-light">للقراءة فقط</span>}</div></td></tr>
          })}</tbody></table></div>
          {records.length === 0 && <p className="p-8 text-center text-text-light">لا توجد سجلات مطابقة.</p>}
          {(recordMeta.last_page ?? 1) > 1 && <div className="mt-4 flex justify-center gap-2"><button disabled={recordPage <= 1} onClick={() => changeRecordPage(recordPage - 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">السابق</button><span className="p-2">{recordPage} / {recordMeta.last_page}</span><button disabled={recordPage >= recordMeta.last_page} onClick={() => changeRecordPage(recordPage + 1)} className="rounded-lg border px-3 py-2 disabled:opacity-40">التالي</button></div>}
        </>}
      </section>}

      <MinistryProgramPickerDialog open={Boolean(recordMatch)} title="تأكيد المطابقة الفردية" message={recordMatch?.matched_academic_program_id ? 'سيتم استبدال المطابقة الحالية بالبرنامج الذي تختاره. هذا الإجراء هو الاستثناء الفردي الصريح.' : 'اختر البرنامج النشط ثم أكّد المطابقة الفردية.'} busy={matchingRecord} onClose={() => setRecordMatch(null)} onConfirm={saveRecordMatch} />
      {recordUnmatch && <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" dir="rtl" role="dialog" aria-modal="true"><div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl"><h3 className="text-lg font-black">إزالة المطابقة الفردية</h3><p className="mt-3 text-sm text-text-light">سيعود السجل إلى حالة غير مطابق. هل تريد المتابعة؟</p><div className="mt-5 flex justify-end gap-2"><button type="button" disabled={matchingRecord} onClick={() => setRecordUnmatch(null)} className="rounded-xl border px-4 py-2">إلغاء</button><button type="button" disabled={matchingRecord} onClick={removeRecordMatch} className="rounded-xl bg-red-700 px-4 py-2 font-bold text-white">{matchingRecord ? 'جارٍ الحفظ...' : 'تأكيد الإزالة'}</button></div></div></div>}
    </div>
  )
}
