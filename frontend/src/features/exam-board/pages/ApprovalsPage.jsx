import { useCallback, useEffect, useRef, useState } from 'react'
import { FaCheck, FaChevronLeft, FaChevronRight, FaExclamationTriangle, FaRedo, FaSpinner, FaTimes, FaUndo } from 'react-icons/fa'
import {
  approveGradeApproval,
  getApprovalFilterOptions,
  getGradeApprovalDetails,
  getGradeApprovals,
  returnGradeApprovalForCorrection,
} from '../lib/gradeApprovalApi'

const PER_PAGE = 15
const EMPTY_FILTERS = { status: 'pending', academic_year_id: '', semester_id: '', department_id: '' }
const STATUS_LABELS = {
  pending: 'معلق', approved: 'معتمد', returned_for_correction: 'معاد للتصحيح', rejected: 'مرفوض',
}
const ERROR_MESSAGES = {
  grade_approval_not_pending: 'تمت مراجعة هذا الطلب مسبقًا ولم يعد معلقًا.',
  grade_sheet_incomplete: 'لا يمكن اعتماد الكشف لوجود علامات ناقصة أو غير صالحة.',
  no_eligible_students: 'لا يوجد طلاب مؤهلون ضمن هذه المادة.',
  grade_approval_status_missing: 'حالة الاعتماد المطلوبة غير مهيأة في النظام.',
  grade_approval_out_of_scope: 'طلب الاعتماد خارج نطاق صلاحياتك.',
}

const rowsOf = payload => payload?.data?.data ?? payload?.data ?? []
const detailsOf = payload => payload?.data ?? payload ?? {}
const dateText = value => value ? new Intl.DateTimeFormat('ar-SY', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const valueText = value => value === null || value === undefined || value === '' ? '—' : value
const errorText = error => ERROR_MESSAGES[error?.errorCode] ?? (error?.status === 401 ? 'انتهت الجلسة، يرجى تسجيل الدخول مجددًا.' : error?.status === 403 ? 'ليس لديك صلاحية للوصول إلى طلبات الاعتماد.' : 'تعذّر الاتصال بالخادم. حاول مرة أخرى.')
const statusLabel = approval => approval?.status_name || STATUS_LABELS[approval?.status_code] || approval?.status_code || '—'

function Modal({ title, children, busy, onClose }) {
  return <div className="fixed inset-0 z-[70] bg-black/45 flex items-center justify-center p-4" dir="rtl" role="dialog" aria-modal="true">
    <div className="w-full max-w-xl rounded-[16px] bg-white shadow-2xl">
      <div className="flex items-center justify-between border-b border-primary/10 p-5">
        <h3 className="font-black text-text-dark">{title}</h3>
        <button disabled={busy} onClick={onClose} className="p-2 text-text-light disabled:opacity-40" aria-label="إغلاق"><FaTimes /></button>
      </div>
      {children}
    </div>
  </div>
}

export default function ApprovalsPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [options, setOptions] = useState({ years: [], semesters: [], departments: [] })
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
  const [alert, setAlert] = useState(null)
  const [invalidRegistrations, setInvalidRegistrations] = useState(new Set())
  const listSequence = useRef(0)
  const detailSequence = useRef(0)
  const selectedIdRef = useRef(null)

  const loadList = useCallback(async () => {
    const sequence = ++listSequence.current
    const controller = new AbortController()
    setListState({ loading: true, error: '' })
    try {
      const payload = await getGradeApprovals({ ...filters, page, per_page: PER_PAGE }, { signal: controller.signal })
      if (sequence !== listSequence.current) return
      setItems(rowsOf(payload))
      const meta = payload?.data?.meta ?? payload?.meta ?? payload?.data?.pagination ?? {}
      setPagination({ current_page: meta.current_page ?? page, last_page: meta.last_page ?? 1, total: meta.total ?? rowsOf(payload).length })
      setListState({ loading: false, error: '' })
    } catch (error) {
      if (sequence === listSequence.current && error.name !== 'AbortError') setListState({ loading: false, error: errorText(error) })
    }
    return () => controller.abort()
  }, [filters, page])

  const loadDetails = useCallback(async id => {
    const sequence = ++detailSequence.current
    setDetail(null); setInvalidRegistrations(new Set()); setDetailState({ loading: true, error: '' })
    try {
      const payload = await getGradeApprovalDetails(id)
      if (sequence !== detailSequence.current || selectedIdRef.current !== id) return
      setDetail(detailsOf(payload)); setDetailState({ loading: false, error: '' })
    } catch (error) {
      if (sequence === detailSequence.current && selectedIdRef.current === id) setDetailState({ loading: false, error: errorText(error) })
    }
  }, [])

  useEffect(() => { loadList() }, [loadList])
  useEffect(() => {
    const controller = new AbortController()
    getApprovalFilterOptions({ signal: controller.signal }).then(([years, semesters, departments]) => {
      setOptions({ years: rowsOf(years), semesters: rowsOf(semesters), departments: rowsOf(departments) })
    }).catch(() => {})
    return () => controller.abort()
  }, [])

  function changeFilter(name, value) { setFilters(current => ({ ...current, [name]: value })); setPage(1) }
  function openDetails(id) {
    if (actionBusy) return
    selectedIdRef.current = id; setSelectedId(id); setAlert(null); loadDetails(id)
  }
  function closeDetails() {
    if (actionBusy) return
    detailSequence.current += 1; selectedIdRef.current = null; setSelectedId(null); setDetail(null); setAction(null)
  }
  function openAction(type) { setNotes(''); setNotesError(''); setAction(type); setAlert(null); setInvalidRegistrations(new Set()) }

  async function submitAction() {
    if (actionBusy || !selectedId) return
    if (action === 'return' && !notes.trim()) { setNotesError('سبب الإعادة مطلوب ولا يمكن أن يكون فارغًا.'); return }
    if (notes.length > 2000) { setNotesError('يجب ألا تتجاوز الملاحظة 2000 حرف.'); return }
    const operationId = selectedId
    setActionBusy(true); setNotesError(''); setAlert(null)
    try {
      if (action === 'approve') await approveGradeApproval(operationId, notes)
      else await returnGradeApprovalForCorrection(operationId, notes)
      if (selectedIdRef.current !== operationId) return
      setAction(null); setNotes('')
      setAlert({ type: 'success', text: action === 'approve' ? 'تم اعتماد العلامات بنجاح.' : 'تمت إعادة العلامات للأستاذ للتصحيح.' })
      await Promise.all([loadDetails(operationId), loadList()])
    } catch (error) {
      if (selectedIdRef.current !== operationId) return
      setAlert({ type: 'error', text: errorText(error) })
      if (error.errorCode === 'grade_sheet_incomplete') {
        setInvalidRegistrations(new Set((error.details?.registration_ids ?? []).map(String)))
        setAction(null)
      }
      if (error.errorCode === 'grade_approval_not_pending') {
        setAction(null); await Promise.all([loadDetails(operationId), loadList()])
      }
    } finally { if (selectedIdRef.current === operationId) setActionBusy(false) }
  }

  const approval = detail?.approval
  const students = detail?.grade_sheet?.students ?? []
  const pending = approval?.status_code === 'pending'

  return <div dir="rtl" className="pb-8">
    <div className="mb-5"><h2 className="text-[20px] font-black text-text-dark">اعتماد العلامات</h2><p className="text-[12.5px] text-text-light">مراجعة كشوف العلامات المرسلة من الأساتذة</p></div>
    <div className="bg-white border border-primary/12 rounded-[16px] p-5 mb-5 shadow-sm">
      <div className="grid grid-cols-4 max-[900px]:grid-cols-2 max-[560px]:grid-cols-1 gap-4">
        {[['status','حالة الطلب', [['pending','معلق'],['approved','معتمد'],['returned_for_correction','معاد للتصحيح'],['rejected','مرفوض']]], ['academic_year_id','السنة الدراسية', options.years.map(x => [x.academic_year_id, x.year_name])], ['semester_id','الفصل الدراسي', options.semesters.map(x => [x.semester_id, x.semester_name])], ['department_id','القسم', options.departments.map(x => [x.department_id, x.department_name])]].map(([key, label, choices]) => <label key={key} className="flex flex-col gap-1.5 text-[12px] font-bold text-text-dark">{label}<select value={filters[key]} onChange={e => changeFilter(key, e.target.value)} className="px-3 py-2.5 border border-primary/20 rounded-[10px] text-[13px] bg-white outline-none focus:border-primary"><option value="">الكل</option>{choices.map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></label>)}
      </div>
      <button onClick={() => { setFilters(EMPTY_FILTERS); setPage(1) }} className="mt-4 inline-flex items-center gap-2 text-[12px] font-bold text-primary"><FaRedo /> إعادة ضبط الفلاتر</button>
    </div>

    <div className="bg-white border border-primary/12 rounded-[16px] min-h-[280px] overflow-hidden shadow-sm">
      {listState.loading ? <div className="flex justify-center py-24 text-primary"><FaSpinner className="animate-spin text-2xl" /></div> : listState.error ? <div className="text-center py-20"><FaExclamationTriangle className="mx-auto text-red-500 mb-3"/><p className="text-red-600 text-sm">{listState.error}</p><button onClick={loadList} className="mt-4 text-primary font-bold text-sm">إعادة المحاولة</button></div> : items.length === 0 ? <div className="text-center py-20 text-text-light text-sm">لا توجد طلبات مطابقة للفلاتر المحددة.</div> : <>
        <div className="overflow-x-auto"><table className="w-full min-w-[950px] text-[12.5px]"><thead className="bg-primary/[0.05] text-text-light"><tr>{['المادة','القسم','السنة والفصل','الأستاذ المرسل','تاريخ الإرسال','اكتمال الطلاب','الحالة',''].map(x => <th key={x} className="p-3 text-right font-bold">{x}</th>)}</tr></thead><tbody>{items.map(item => <tr key={item.grade_approval_id} className="border-t border-primary/8 hover:bg-primary/[0.02]"><td className="p-3"><b>{item.course_name}</b><div className="text-text-light font-mono">{item.course_code}</div></td><td className="p-3">{valueText(item.department_name)}</td><td className="p-3">{valueText(item.academic_year_name)}<div>{valueText(item.semester_name)}</div></td><td className="p-3">{valueText(item.submitted_by_name)}</td><td className="p-3 whitespace-nowrap">{dateText(item.submitted_at)}</td><td className="p-3">{item.completed_students_count ?? 0} / {item.eligible_students_count ?? 0}</td><td className="p-3"><span className="rounded-full bg-primary/10 text-primary px-2.5 py-1 font-bold">{statusLabel(item)}</span></td><td className="p-3"><button disabled={actionBusy} onClick={() => openDetails(item.grade_approval_id)} className="rounded-[8px] bg-primary text-white px-3 py-2 font-bold disabled:opacity-50">مراجعة الكشف</button></td></tr>)}</tbody></table></div>
        <div className="flex items-center justify-between p-4 border-t border-primary/10 text-xs"><span>{pagination.total} طلب</span><div className="flex items-center gap-3"><button disabled={page <= 1} onClick={() => setPage(x => x - 1)} className="p-2 disabled:opacity-30"><FaChevronRight /></button><span>صفحة {pagination.current_page} من {pagination.last_page}</span><button disabled={page >= pagination.last_page} onClick={() => setPage(x => x + 1)} className="p-2 disabled:opacity-30"><FaChevronLeft /></button></div></div>
      </>}
    </div>

    {selectedId && <div className="fixed inset-0 z-50 bg-black/45 flex justify-end" onMouseDown={e => { if (e.target === e.currentTarget) closeDetails() }}><section className="h-full w-full max-w-[1100px] bg-[#fafaf8] overflow-y-auto shadow-2xl" dir="rtl"><header className="sticky top-0 z-10 bg-white border-b border-primary/10 p-4 flex justify-between items-center"><div><h3 className="font-black text-text-dark">مراجعة كشف العلامات</h3><p className="text-xs text-text-light">طلب رقم {selectedId}</p></div><button disabled={actionBusy} onClick={closeDetails} className="p-2 disabled:opacity-40"><FaTimes /></button></header>
      {detailState.loading ? <div className="flex justify-center py-32 text-primary"><FaSpinner className="animate-spin text-2xl"/></div> : detailState.error ? <div className="text-center py-24 text-red-600 text-sm">{detailState.error}</div> : detail && <div className="p-5 space-y-5">
        {alert && <div className={`rounded-[10px] border p-3 text-sm ${alert.type === 'success' ? 'bg-green-50 border-green-200 text-green-700' : 'bg-red-50 border-red-200 text-red-700'}`}>{alert.text}</div>}
        <div className="bg-white rounded-[14px] border border-primary/10 p-5"><div className="flex flex-wrap justify-between gap-3 mb-4"><div><h4 className="font-black text-lg">{approval?.course_name} <span className="font-mono text-sm text-text-light">{approval?.course_code}</span></h4><p className="text-sm text-text-light">{valueText(approval?.department_name)}</p></div><span className="h-fit rounded-full bg-primary/10 text-primary px-3 py-1 text-sm font-bold">{statusLabel(approval)}</span></div><dl className="grid grid-cols-4 max-[750px]:grid-cols-2 max-[450px]:grid-cols-1 gap-4 text-sm">{[['السنة الدراسية',approval?.academic_year_name],['الفصل',approval?.semester_name],['الأستاذ المرسل',approval?.submitted_by_name],['تاريخ الإرسال',dateText(approval?.submitted_at)],['حالة سير العمل',STATUS_LABELS[detail.workflow?.status] ?? detail.workflow?.status ?? '—'],['الطلاب المؤهلون',approval?.eligible_students_count],['العلامات المكتملة',approval?.completed_students_count],['العلامات غير المكتملة',approval?.incomplete_students_count],['المراجع',approval?.reviewed_by_name],['تاريخ المراجعة',dateText(approval?.review_date)]].map(([k,v]) => <div key={k}><dt className="text-xs text-text-light mb-1">{k}</dt><dd className="font-bold text-text-dark">{valueText(v)}</dd></div>)}</dl>{approval?.approval_notes && <div className="mt-4 rounded-lg bg-amber-50 border border-amber-100 p-3"><b className="text-xs">ملاحظات المراجعة</b><p className="text-sm whitespace-pre-wrap mt-1">{approval.approval_notes}</p></div>}</div>
        <div className="bg-white rounded-[14px] border border-primary/10 overflow-hidden"><div className="p-4 border-b border-primary/10 font-black">كشف الطلاب ({students.length})</div><div className="overflow-x-auto"><table className="w-full min-w-[900px] text-[12.5px]"><thead className="bg-primary/[0.04]"><tr>{['الرقم الجامعي','اسم الطالب','النظري / 60','العملي / 40','العلامة النهائية','التقدير','حالة النتيجة','الحرمان'].map(x => <th key={x} className="p-3 text-right">{x}</th>)}</tr></thead><tbody>{students.map(student => { const registrationId = student.student_course_registration_id ?? student.registration_id; const invalid = invalidRegistrations.has(String(registrationId)); const deprived = student.is_deprived || student.deprivation_status; return <tr key={registrationId ?? student.student_number} className={`border-t border-primary/8 ${invalid ? 'bg-red-100 ring-1 ring-inset ring-red-300' : deprived ? 'bg-amber-50' : ''}`}><td className="p-3 font-mono">{valueText(student.student_number)}</td><td className="p-3 font-bold">{valueText(student.full_name ?? student.student_name)}</td><td className="p-3">{valueText(student.theoretical_mark)}</td><td className="p-3">{valueText(student.practical_mark)}</td><td className="p-3 font-bold">{valueText(student.final_mark)}</td><td className="p-3">{valueText(student.letter_grade)}</td><td className="p-3">{student.result_status?.status_name ?? student.result_status?.status_code ?? '—'}</td><td className="p-3">{deprived ? <span className="text-red-700 font-bold">محروم</span> : '—'}</td></tr>})}</tbody></table></div></div>
        {pending && <div className="sticky bottom-0 bg-white border border-primary/10 rounded-[14px] p-4 flex justify-end gap-3"><button disabled={actionBusy} onClick={() => openAction('return')} className="flex items-center gap-2 rounded-[9px] border border-red-300 text-red-700 px-4 py-2.5 font-bold disabled:opacity-50"><FaUndo/> إعادة للتصحيح</button><button disabled={actionBusy} onClick={() => openAction('approve')} className="flex items-center gap-2 rounded-[9px] bg-primary text-white px-4 py-2.5 font-bold disabled:opacity-50"><FaCheck/> اعتماد العلامات</button></div>}
      </div>}
    </section></div>}

    {action && <Modal title={action === 'approve' ? 'تأكيد اعتماد العلامات' : 'تأكيد إعادة العلامات'} busy={actionBusy} onClose={() => setAction(null)}><div className="p-5"><p className="text-sm text-text-dark mb-4">{action === 'approve' ? 'سيتم اعتماد علامات هذه المادة نهائيًا، ولن يتمكن الأستاذ من تعديلها بعد الاعتماد. هل أنت متأكد؟' : 'ستعود العلامات للأستاذ وستصبح قابلة للتعديل لديه. يرجى توضيح سبب الإعادة.'}</p><label className="text-xs font-bold text-text-dark">{action === 'approve' ? 'ملاحظات اختيارية' : 'سبب الإعادة (إلزامي)'}<textarea autoFocus value={notes} maxLength={2000} onChange={e => { setNotes(e.target.value); setNotesError('') }} rows={5} className={`mt-2 w-full resize-none rounded-[10px] border p-3 outline-none ${notesError ? 'border-red-400' : 'border-primary/20 focus:border-primary'}`}/></label><div className="flex justify-between text-xs mt-1"><span className="text-red-600">{notesError}</span><span className="text-text-light">{notes.length} / 2000</span></div><div className="flex justify-end gap-3 mt-5"><button disabled={actionBusy} onClick={() => setAction(null)} className="px-4 py-2 rounded-[8px] border border-primary/20 font-bold disabled:opacity-50">إلغاء</button><button disabled={actionBusy || (action === 'return' && !notes.trim())} onClick={submitAction} className={`px-4 py-2 rounded-[8px] text-white font-bold disabled:opacity-50 ${action === 'return' ? 'bg-red-600' : 'bg-primary'}`}>{actionBusy ? <FaSpinner className="animate-spin"/> : action === 'approve' ? 'تأكيد الاعتماد' : 'تأكيد الإعادة'}</button></div></div></Modal>}
  </div>
}
