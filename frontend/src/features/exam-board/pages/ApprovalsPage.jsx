import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { FaCheck, FaChevronLeft, FaChevronRight, FaExclamationTriangle, FaRedo, FaSpinner, FaTimes, FaUndo } from 'react-icons/fa'
import {
  approveGradePartApproval,
  getGradePartApprovalDetails,
  getGradePartApprovals,
  returnGradePartApprovalForCorrection,
} from '../lib/gradeApprovalApi'

const PER_PAGE = 15
const EMPTY_FILTERS = { status: 'submitted', component_type: '' }
const PART_LABELS = { practical: 'عملي', theoretical: 'نظري' }
const STATUSES = {
  draft: { label: 'مسودة', style: 'bg-blue-50 text-blue-700' },
  submitted: { label: 'مرسل لهيئة الامتحانات', style: 'bg-amber-50 text-amber-700' },
  returned: { label: 'معاد للتصحيح', style: 'bg-red-50 text-red-700' },
  approved: { label: 'معتمد', style: 'bg-green-50 text-green-700' },
}
const ERROR_MESSAGES = {
  unauthorized_grade_part: 'ليس لديك صلاحية للوصول إلى طلب اعتماد هذا الجزء.',
  grade_part_not_submitted: 'تغيّرت حالة الطلب أو تمت مراجعته مسبقًا. تم تحديث البيانات.',
  missing_review_notes: 'سبب الإعادة مطلوب ولا يمكن أن يكون فارغًا.',
  grade_part_incomplete: 'لا يمكن اعتماد الجزء لأن علاماته غير مكتملة.',
  grade_part_not_required: 'هذا الجزء غير مطلوب للمقرر.',
  result_status_missing: 'حالة النتيجة المطلوبة غير مهيأة في النظام.',
  grade_approval_status_missing: 'حالة الاعتماد النهائية غير مهيأة في النظام.',
}

const rowsOf = payload => payload?.data?.data ?? payload?.data ?? []
const detailsOf = payload => payload?.data ?? payload ?? {}
const valueText = value => value === null || value === undefined || value === '' ? '—' : value
const dateText = value => value ? new Intl.DateTimeFormat('ar-SY', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const studentName = student => [student?.first_name, student?.last_name].filter(Boolean).join(' ') || '—'
const errorText = error => ERROR_MESSAGES[error?.errorCode]
  ?? (error?.status === 401 ? 'انتهت الجلسة، يرجى تسجيل الدخول مجددًا.'
    : error?.status === 403 ? 'ليس لديك صلاحية تنفيذ هذا الإجراء.'
      : error?.status === 404 ? 'لم يعد طلب الاعتماد موجودًا.'
        : error?.status === 409 ? 'تعذّر تنفيذ الإجراء بسبب تغيّر حالة الطلب.'
          : error?.status === 422 ? 'البيانات المرسلة غير صالحة. تحقق من الملاحظات وحاول مجددًا.'
            : 'تعذّر الاتصال بالخادم. تحقق من الاتصال وحاول مرة أخرى.')

function StatusBadge({ status }) {
  const item = STATUSES[status]
  return <span className={`inline-flex rounded-full px-2.5 py-1 font-bold ${item?.style ?? 'bg-slate-100 text-slate-700'}`}>{item?.label ?? 'حالة غير معروفة'}</span>
}

function Modal({ title, children, busy, onClose }) {
  return <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="w-full max-w-xl rounded-[16px] bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-primary/10 p-5"><h3 className="font-black text-text-dark">{title}</h3><button disabled={busy} onClick={onClose} className="p-2 text-text-light disabled:opacity-40" aria-label="إغلاق"><FaTimes /></button></div>
      {children}
    </div>
  </div>
}

export default function ApprovalsPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [items, setItems] = useState([])
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [listState, setListState] = useState({ loading: true, error: '' })
  const [selectedId, setSelectedId] = useState(null)
  const [detail, setDetail] = useState(null)
  const [detailState, setDetailState] = useState({ loading: false, error: '' })
  const [action, setAction] = useState(null)
  const [notes, setNotes] = useState('')
  const [notesError, setNotesError] = useState('')
  const [actionBusy, setActionBusy] = useState(false)
  const [actionsLocked, setActionsLocked] = useState(false)
  const [alert, setAlert] = useState(null)
  const listSequence = useRef(0)
  const detailSequence = useRef(0)
  const selectedIdRef = useRef(null)

  const loadList = useCallback(async () => {
    const sequence = ++listSequence.current
    setListState({ loading: true, error: '' })
    try {
      const payload = await getGradePartApprovals({ ...filters, page, per_page: PER_PAGE })
      if (sequence !== listSequence.current) return false
      const rows = rowsOf(payload)
      const meta = payload?.data?.meta ?? payload?.meta ?? {}
      setItems(rows)
      setPagination({ current_page: meta.current_page ?? page, last_page: meta.last_page ?? 1, total: meta.total ?? rows.length })
      setListState({ loading: false, error: '' })
      return true
    } catch (error) {
      if (sequence === listSequence.current) setListState({ loading: false, error: errorText(error) })
      return false
    }
  }, [filters, page])

  const loadDetails = useCallback(async id => {
    const sequence = ++detailSequence.current
    setDetail(null); setDetailState({ loading: true, error: '' })
    try {
      const payload = await getGradePartApprovalDetails(id)
      if (sequence !== detailSequence.current || Number(selectedIdRef.current) !== Number(id)) return false
      setDetail(detailsOf(payload)); setDetailState({ loading: false, error: '' }); setActionsLocked(false)
      return true
    } catch (error) {
      if (sequence === detailSequence.current && Number(selectedIdRef.current) === Number(id)) setDetailState({ loading: false, error: errorText(error) })
      return false
    }
  }, [])

  useEffect(() => { loadList() }, [loadList])

  function changeFilter(name, value) { setFilters(current => ({ ...current, [name]: value })); setPage(1) }
  function openDetails(id) { if (actionBusy) return; selectedIdRef.current = id; setSelectedId(id); setAlert(null); setActionsLocked(false); loadDetails(id) }
  function closeDetails() { if (actionBusy) return; detailSequence.current += 1; selectedIdRef.current = null; setSelectedId(null); setDetail(null); setAction(null) }
  function openAction(type) { setNotes(''); setNotesError(''); setAction(type); setAlert(null) }

  async function refreshAfterAction(id) {
    const [detailsUpdated, listUpdated] = await Promise.all([loadDetails(id), loadList()])
    if (!detailsUpdated || !listUpdated) {
      setActionsLocked(true)
      setAlert({ type: 'error', text: 'تم تنفيذ الإجراء، لكن تعذّر تحديث كل البيانات. أزرار الإجراءات مقفلة حتى تنجح إعادة المحاولة.' })
    }
  }

  async function retryRefresh() {
    if (!selectedId || actionBusy) return
    setActionBusy(true)
    await refreshAfterAction(selectedId)
    setActionBusy(false)
  }

  async function submitAction() {
    if (actionBusy || actionsLocked || !selectedId) return
    const trimmedNotes = notes.trim()
    if (action === 'return' && !trimmedNotes) { setNotesError('سبب الإعادة مطلوب ولا يمكن أن يكون فارغًا.'); return }
    if (notes.length > 2000) { setNotesError('يجب ألا تتجاوز الملاحظة 2000 حرف.'); return }
    const operationId = selectedId
    const operation = action
    setActionBusy(true); setActionsLocked(true); setNotesError(''); setAlert(null)
    try {
      if (operation === 'approve') await approveGradePartApproval(operationId, trimmedNotes)
      else await returnGradePartApprovalForCorrection(operationId, trimmedNotes)
      if (Number(selectedIdRef.current) !== Number(operationId)) return
      setAction(null); setNotes('')
      setAlert({ type: 'success', text: operation === 'approve' ? `تم اعتماد الجزء ${PART_LABELS[detail?.approval?.component_type]} فقط.` : `تمت إعادة الجزء ${PART_LABELS[detail?.approval?.component_type]} فقط للتصحيح.` })
      await refreshAfterAction(operationId)
    } catch (error) {
      if (Number(selectedIdRef.current) !== Number(operationId)) return
      setAlert({ type: 'error', text: errorText(error) })
      setActionsLocked(false)
      if (error.errorCode === 'grade_part_not_submitted') {
        setAction(null)
        setActionsLocked(true)
        await refreshAfterAction(operationId)
      }
    } finally {
      if (Number(selectedIdRef.current) === Number(operationId)) setActionBusy(false)
    }
  }

  const approval = detail?.approval
  const workflow = detail?.workflow
  const selectedPart = approval?.component_type
  const components = useMemo(() => workflow?.components?.[selectedPart] ?? [], [selectedPart, workflow])
  const students = workflow?.students ?? []
  const partMaximum = components.reduce((sum, component) => sum + Number(component.max_mark ?? 0), 0)
  const otherPart = selectedPart === 'theoretical' ? 'practical' : 'theoretical'
  const practicalComponents = workflow?.components?.practical ?? []
  const practicalRequired = workflow?.parts?.practical?.required === true
  const canReview = approval?.status === 'submitted' && !actionsLocked && !detailState.loading

  return <div dir="rtl" className="pb-8">
    <div className="mb-5"><h2 className="text-[20px] font-black text-text-dark">اعتماد أجزاء العلامات</h2><p className="text-[12.5px] text-text-light">مراجعة العملي والنظري كطلبات مستقلة</p></div>
    <div className="mb-5 rounded-[16px] border border-primary/12 bg-white p-5 shadow-sm">
      <div className="grid max-w-2xl grid-cols-2 gap-4 max-[560px]:grid-cols-1">
        <label className="flex flex-col gap-1.5 text-[12px] font-bold text-text-dark">حالة الجزء<select value={filters.status} onChange={e => changeFilter('status', e.target.value)} className="rounded-[10px] border border-primary/20 bg-white px-3 py-2.5 text-[13px] outline-none focus:border-primary"><option value="">الكل</option>{Object.entries(STATUSES).map(([value, item]) => <option key={value} value={value}>{item.label}</option>)}</select></label>
        <label className="flex flex-col gap-1.5 text-[12px] font-bold text-text-dark">نوع الجزء<select value={filters.component_type} onChange={e => changeFilter('component_type', e.target.value)} className="rounded-[10px] border border-primary/20 bg-white px-3 py-2.5 text-[13px] outline-none focus:border-primary"><option value="">الكل</option><option value="practical">عملي</option><option value="theoretical">نظري</option></select></label>
      </div>
      <button onClick={() => { setFilters(EMPTY_FILTERS); setPage(1) }} className="mt-4 inline-flex items-center gap-2 text-[12px] font-bold text-primary"><FaRedo /> إعادة ضبط الفلاتر</button>
    </div>

    <div className="min-h-[280px] overflow-hidden rounded-[16px] border border-primary/12 bg-white shadow-sm">
      {listState.loading ? <div className="flex justify-center py-24 text-primary"><FaSpinner className="animate-spin text-2xl" /></div> : listState.error ? <div className="py-20 text-center"><FaExclamationTriangle className="mx-auto mb-3 text-red-500"/><p className="text-sm text-red-600">{listState.error}</p><button onClick={loadList} className="mt-4 text-sm font-bold text-primary">إعادة المحاولة</button></div> : items.length === 0 ? <div className="py-20 text-center text-sm text-text-light">لا توجد طلبات مطابقة للفلاتر المحددة.</div> : <>
        <div className="overflow-x-auto"><table className="w-full min-w-[1050px] text-[12.5px]"><thead className="bg-primary/[0.05] text-text-light"><tr>{['المقرر','الشعبة','الجزء','النسخة','الأستاذ المرسل','تاريخ الإرسال','المراجع','تاريخ المراجعة','الحالة',''].map(x => <th key={x} className="p-3 text-right font-bold">{x}</th>)}</tr></thead><tbody>{items.map(item => <tr key={item.id} className="border-t border-primary/8 hover:bg-primary/[0.02]"><td className="p-3"><b>{valueText(item.course_name ?? item.course_offering?.course?.course_name)}</b><div className="font-mono text-text-light">{valueText(item.course_code ?? item.course_offering?.course?.course_code)}</div></td><td className="p-3 font-mono">{valueText(item.course_offering_id)}</td><td className="p-3 font-bold">{PART_LABELS[item.component_type] ?? 'جزء غير معروف'}</td><td className="p-3">{valueText(item.submission_version)}</td><td className="p-3">{valueText(item.submitted_by_name)}</td><td className="p-3 whitespace-nowrap">{dateText(item.submitted_at)}</td><td className="p-3">{valueText(item.reviewed_by_name)}</td><td className="p-3 whitespace-nowrap">{dateText(item.reviewed_at)}</td><td className="p-3"><StatusBadge status={item.status} /></td><td className="p-3"><button disabled={actionBusy} onClick={() => openDetails(item.id)} className="rounded-[8px] bg-primary px-3 py-2 font-bold text-white disabled:opacity-50">مراجعة الجزء</button></td></tr>)}</tbody></table></div>
        <div className="flex items-center justify-between border-t border-primary/10 p-4 text-xs"><span>{pagination.total} طلب</span><div className="flex items-center gap-3"><button disabled={page <= 1} onClick={() => setPage(x => x - 1)} className="p-2 disabled:opacity-30"><FaChevronRight /></button><span>صفحة {pagination.current_page} من {pagination.last_page}</span><button disabled={page >= pagination.last_page} onClick={() => setPage(x => x + 1)} className="p-2 disabled:opacity-30"><FaChevronLeft /></button></div></div>
      </>}
    </div>

    {selectedId && <div className="fixed inset-0 z-50 flex justify-end bg-black/45" onMouseDown={e => { if (e.target === e.currentTarget) closeDetails() }}><section className="h-full w-full max-w-[1150px] overflow-y-auto bg-[#fafaf8] shadow-2xl" dir="rtl"><header className="sticky top-0 z-10 flex items-center justify-between border-b border-primary/10 bg-white p-4"><div><h3 className="font-black text-text-dark">مراجعة جزء العلامة</h3><p className="text-xs text-text-light">طلب رقم {selectedId}</p></div><button disabled={actionBusy} onClick={closeDetails} className="p-2 disabled:opacity-40"><FaTimes /></button></header>
      {detailState.loading ? <div className="flex justify-center py-32 text-primary"><FaSpinner className="animate-spin text-2xl"/></div> : detailState.error ? <div className="py-24 text-center text-sm text-red-600"><p>{detailState.error}</p><button onClick={() => loadDetails(selectedId)} className="mt-4 font-bold text-primary">إعادة المحاولة</button></div> : detail && <div className="space-y-5 p-5">
        {alert && <div className={`rounded-[10px] border p-3 text-sm ${alert.type === 'success' ? 'border-green-200 bg-green-50 text-green-700' : 'border-red-200 bg-red-50 text-red-700'}`}>{alert.text}{actionsLocked && <button disabled={actionBusy} onClick={retryRefresh} className="mr-3 font-black underline disabled:opacity-50">إعادة تحميل البيانات</button>}</div>}
        <div className="rounded-[14px] border border-primary/10 bg-white p-5"><div className="mb-4 flex flex-wrap justify-between gap-3"><div><h4 className="text-lg font-black">{valueText(approval?.course_name ?? workflow?.course?.course_name)} <span className="font-mono text-sm text-text-light">{valueText(approval?.course_code ?? workflow?.course?.course_code)}</span></h4><p className="text-sm text-text-light">رقم الشعبة: {valueText(approval?.course_offering_id)}</p><p className="mt-1 text-xs text-text-light">العام الدراسي: {valueText(approval?.academic_year_name)} · الفصل: {valueText(approval?.semester_name)}</p></div><div className="flex h-fit items-center gap-2"><span className="rounded-full bg-primary/10 px-3 py-1 text-sm font-bold text-primary">{PART_LABELS[selectedPart]}</span><StatusBadge status={approval?.status} /></div></div><dl className="grid grid-cols-4 gap-4 text-sm max-[750px]:grid-cols-2 max-[450px]:grid-cols-1">{[['نسخة الإرسال',approval?.submission_version],['الأستاذ المرسل',approval?.submitted_by_name],['تاريخ الإرسال',dateText(approval?.submitted_at)],['المراجع',approval?.reviewed_by_name],['تاريخ المراجعة',dateText(approval?.reviewed_at)],['حالة الجزء الآخر',`${PART_LABELS[otherPart]}: ${STATUSES[workflow?.parts?.[otherPart]?.status]?.label ?? 'غير مطلوب'}`]].map(([key, value]) => <div key={key}><dt className="mb-1 text-xs text-text-light">{key}</dt><dd className="font-bold text-text-dark">{valueText(value)}</dd></div>)}</dl>{approval?.review_notes && <div className="mt-4 rounded-lg border border-amber-100 bg-amber-50 p-3"><b className="text-xs">ملاحظات المراجعة</b><p className="mt-1 whitespace-pre-wrap text-sm">{approval.review_notes}</p></div>}</div>
        <div className="overflow-hidden rounded-[14px] border border-primary/10 bg-white"><div className="border-b border-primary/10 p-4 font-black">علامات الجزء {PART_LABELS[selectedPart]} ({students.length} طالب)</div><div className="overflow-x-auto"><table className="w-full min-w-[850px] text-[12.5px]"><thead className="bg-primary/[0.04]"><tr><th className="p-3 text-right">الرقم الجامعي</th><th className="p-3 text-right">اسم الطالب</th>{components.map((component, index) => <th key={component.grade_component_id} className="p-3 text-center">المكوّن {index + 1} / {component.max_mark}</th>)}<th className="p-3 text-center">مجموع الجزء</th><th className="p-3 text-center">الحد الأعلى</th><th className="p-3 text-center">الحرمان</th>{selectedPart === 'theoretical' && <th className="p-3 text-center">العملي (مرجع فقط)</th>}</tr></thead><tbody>{students.map(row => { const marks = row.marks?.[selectedPart] ?? []; const markById = new Map(marks.map(mark => [Number(mark.grade_component_id), mark.mark])); const missing = components.some(component => markById.get(Number(component.grade_component_id)) === null || markById.get(Number(component.grade_component_id)) === undefined); const total = missing ? null : components.reduce((sum, component) => sum + Number(markById.get(Number(component.grade_component_id))), 0); const practicalMarks = row.marks?.practical ?? []; const practicalMarkById = new Map(practicalMarks.map(mark => [Number(mark.grade_component_id), mark.mark])); const practicalMissing = practicalRequired && practicalComponents.some(component => { const mark = practicalMarkById.get(Number(component.grade_component_id)); return mark === null || mark === undefined }); const practicalTotal = !practicalRequired || practicalMissing ? null : practicalComponents.reduce((sum, component) => sum + Number(practicalMarkById.get(Number(component.grade_component_id))), 0); const practicalText = !practicalRequired ? 'غير مطلوب' : practicalMissing ? 'غير مكتمل' : practicalTotal; return <tr key={row.registration_id} className={`border-t border-primary/8 ${row.is_deprived ? 'bg-amber-50' : missing ? 'bg-red-50' : ''}`}><td className="p-3 font-mono">{valueText(row.student?.student_number)}</td><td className="p-3 font-bold">{studentName(row.student)}</td>{components.map(component => { const mark = markById.get(Number(component.grade_component_id)); return <td key={component.grade_component_id} className={`p-3 text-center ${mark === null || mark === undefined ? 'font-black text-red-700' : ''}`}>{mark === null || mark === undefined ? 'علامة ناقصة' : mark}</td> })}<td className={`p-3 text-center font-black ${missing ? 'text-red-700' : ''}`}>{missing ? 'غير مكتمل' : total}</td><td className="p-3 text-center">{partMaximum}</td><td className="p-3 text-center">{row.is_deprived ? <span className="font-bold text-red-700">محروم</span> : '—'}</td>{selectedPart === 'theoretical' && <td className="p-3 text-center"><span className="font-bold">{practicalText}</span><div className="text-[10px] text-text-light">{STATUSES[workflow?.parts?.practical?.status]?.label ?? 'غير مطلوب'} · للقراءة فقط</div></td>}</tr>})}</tbody></table></div></div>
        {canReview && <div className="sticky bottom-0 flex justify-end gap-3 rounded-[14px] border border-primary/10 bg-white p-4"><button disabled={actionBusy} onClick={() => openAction('return')} className="flex items-center gap-2 rounded-[9px] border border-red-300 px-4 py-2.5 font-bold text-red-700 disabled:opacity-50"><FaUndo/> إعادة الجزء للتصحيح</button><button disabled={actionBusy} onClick={() => openAction('approve')} className="flex items-center gap-2 rounded-[9px] bg-primary px-4 py-2.5 font-bold text-white disabled:opacity-50"><FaCheck/> اعتماد الجزء</button></div>}
      </div>}
    </section></div>}

    {action && <Modal title={action === 'approve' ? `تأكيد اعتماد الجزء ${PART_LABELS[selectedPart]}` : `إعادة الجزء ${PART_LABELS[selectedPart]} للتصحيح`} busy={actionBusy} onClose={() => setAction(null)}><div className="p-5"><p className="mb-4 text-sm text-text-dark">{action === 'approve' ? selectedPart === 'practical' ? 'سيتم اعتماد الجزء العملي فقط. لن يؤثر ذلك في حالة الجزء النظري. بعد الاعتماد لن يستطيع الأستاذ تعديل هذا الجزء. هل أنت متأكد؟' : 'سيتم اعتماد الجزء النظري فقط. لن تتغير علامة العملي. إذا كانت جميع الأجزاء المطلوبة معتمدة فسيقوم النظام بإتمام النتيجة النهائية تلقائياً. هل أنت متأكد؟' : `سيتمكن الأستاذ من تعديل الجزء ${PART_LABELS[selectedPart]} المعاد فقط، ولن تتغير حالة الجزء ${PART_LABELS[otherPart]}. يرجى توضيح سبب الإعادة.`}</p><label className="text-xs font-bold text-text-dark">{action === 'approve' ? 'ملاحظات اختيارية' : 'سبب الإعادة (إلزامي)'}<textarea autoFocus value={notes} maxLength={2000} onChange={e => { setNotes(e.target.value); setNotesError('') }} rows={5} className={`mt-2 w-full resize-none rounded-[10px] border p-3 outline-none ${notesError ? 'border-red-400' : 'border-primary/20 focus:border-primary'}`}/></label><div className="mt-1 flex justify-between text-xs"><span className="text-red-600">{notesError}</span><span className="text-text-light">{notes.length} / 2000</span></div><div className="mt-5 flex justify-end gap-3"><button disabled={actionBusy} onClick={() => setAction(null)} className="rounded-[8px] border border-primary/20 px-4 py-2 font-bold disabled:opacity-50">إلغاء</button><button disabled={actionBusy || (action === 'return' && !notes.trim())} onClick={submitAction} className={`rounded-[8px] px-4 py-2 font-bold text-white disabled:opacity-50 ${action === 'return' ? 'bg-red-600' : 'bg-primary'}`}>{actionBusy ? <FaSpinner className="animate-spin"/> : action === 'approve' ? 'تأكيد الاعتماد' : 'تأكيد الإعادة'}</button></div></div></Modal>}
  </div>
}
