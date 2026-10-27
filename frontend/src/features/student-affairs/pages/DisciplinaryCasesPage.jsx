import { useCallback, useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { FaBalanceScale, FaEye, FaPlus } from 'react-icons/fa'
import DataTable from '../../../components/table/DataTable'
import FilterBar from '../../../components/table/FilterBar'
import { canAccess, getIdentity } from '../../auth/auth'
import { Dialog, Notice, headerButton, primaryButton, secondaryButton } from '../../vice-presidency/components/GovernanceUi'
import { OfficeLink } from '../../vice-presidency/utils/administrativeOfficeNavigation'
import DisciplinaryEditor from '../components/DisciplinaryEditor'
import { DISCIPLINE_ACCESS, appealStates, authorities, caseStates, disciplineDate, disciplineError, disciplineRead, disciplineTitle } from '../lib/discipline'

function Badge({ children }) { return <span className="inline-flex rounded-full border border-primary/20 bg-primary/5 px-2.5 py-1 text-[11.5px] font-bold text-primary-dark">{children}</span> }
function Facts({ rows }) { return <dl className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">{rows.map(([key, value]) => <div key={key}><dt className="text-[11.5px] text-text-light">{key}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-[13px] font-semibold text-text-dark">{value || 'غير مسجل'}</dd></div>)}</dl> }

export default function DisciplinaryCasesPage({ studentId }) {
  const [params, setParams] = useSearchParams()
  const identity = JSON.stringify(getIdentity())
  const authorized = canAccess(DISCIPLINE_ACCESS)
  const page = Math.max(1, Number(params.get('discipline_page')) || 1)
  const q = params.get('discipline_q') || ''
  const status = params.get('discipline_status') || ''
  const rawCaseId = params.get('disciplinary_case') || ''
  const caseId = /^[1-9]\d*$/.test(rawCaseId) ? rawCaseId : ''
  const [listing, setListing] = useState(null)
  const [detail, setDetail] = useState(null)
  const [capabilities, setCapabilities] = useState(null)
  const [error, setError] = useState(null)
  const [detailError, setDetailError] = useState(null)
  const [notice, setNotice] = useState('')
  const [denied, setDenied] = useState(false)
  const [reload, setReload] = useState(0)
  const [editor, setEditor] = useState(null)
  const editorClose = useRef(null)
  const requestKey = JSON.stringify([identity, studentId, page, q, status, reload])
  const detailKey = JSON.stringify([identity, studentId, caseId, reload])
  const row = detail?.key === detailKey ? detail.data : null
  const current = listing?.key === requestKey ? listing.data : null
  const currentError = error?.key === requestKey ? error.message : ''
  const currentDetailError = detailError?.key === detailKey ? detailError.message : ''
  const authorityLost = useCallback(() => { setDenied(true); setListing(null); setDetail(null); setEditor(null); setCapabilities(null) }, [setDenied, setListing, setDetail, setEditor, setCapabilities])
  useEffect(() => {
    if (!authorized) return
    let active = true
    const abort = new AbortController()
    const query = new URLSearchParams({ page, ...(studentId ? { student_id: studentId } : {}), ...(q ? { q } : {}), ...(status ? { case_status: status } : {}) })
    disciplineRead('disciplinary-cases?' + query, abort.signal).then(data => {
      if (!Array.isArray(data.data) || !Number.isInteger(data.meta?.last_page)) throw new Error('تعذرت قراءة قائمة العقوبات كاملة. أعد المحاولة.')
      if (active) setListing({ key: requestKey, data })
    })
      .catch(e => { if (active && e.name !== 'AbortError') { setError({ key: requestKey, message: disciplineError(e) }); if ([401, 403].includes(e.status)) authorityLost() } })
    return () => { active = false; abort.abort() }
  }, [requestKey, authorized, studentId, page, q, status, authorityLost])
  useEffect(() => {
    if (!authorized) return
    let active = true
    const abort = new AbortController()
    disciplineRead('disciplinary-cases/options' + (studentId ? `?student_id=${studentId}` : ''), abort.signal)
      .then(data => { if (active) setCapabilities({ identity, studentId, ...data.capabilities }) })
      .catch(e => { if (active && [401, 403].includes(e.status)) authorityLost() })
    return () => { active = false; abort.abort() }
  }, [identity, studentId, authorized, authorityLost, reload])
  useEffect(() => {
    if (!caseId || !authorized) return
    let active = true
    const abort = new AbortController()
    disciplineRead(`disciplinary-cases/${caseId}`, abort.signal).then(data => {
      if (active) {
        if (Number(data.case_id) !== Number(caseId) || !Array.isArray(data.appeals) || !Array.isArray(data.affected_courses)) { setDetailError({ key: detailKey, message: 'تعذرت مطابقة سجل العقوبة المطلوب كاملًا.' }); return }
        if (studentId && Number(data.student_id) !== Number(studentId)) { setDetailError({ key: detailKey, message: 'العقوبة لا تخص الطالب المعروض.' }); return }
        setDetail({ key: detailKey, data })
      }
    }).catch(e => { if (active && e.name !== 'AbortError') { setDetailError({ key: detailKey, message: disciplineError(e) }); if ([401, 403].includes(e.status)) authorityLost() } })
    return () => { active = false; abort.abort() }
  }, [caseId, detailKey, studentId, authorized, authorityLost])
  const change = (key, value) => setParams(currentParams => { const next = new URLSearchParams(currentParams); if (['discipline_q', 'discipline_status'].includes(key)) next.delete('discipline_page'); value ? next.set(key, value) : next.delete(key); return next }, { replace: true })
  const clear = () => setParams(currentParams => { const next = new URLSearchParams(currentParams); ['discipline_q', 'discipline_status', 'discipline_page'].forEach(key => next.delete(key)); return next }, { replace: true })
  const saved = () => { setEditor(null); setReload(value => value + 1); setNotice('تم حفظ التسجيل. جرى طلب تحديث السجل الحالي.') }
  const openCase = id => { setDetail(null); setDetailError(''); change('disciplinary_case', String(id)) }
  const closeDialog = () => { if (editor) editorClose.current?.(); else change('disciplinary_case', '') }
  const canCreate = capabilities?.identity === identity && capabilities?.studentId === studentId && capabilities.create
  if (!authorized || denied) return <Notice tone="error">غير مصرح بالوصول إلى سجل العقوبات. البيانات غير معروضة.</Notice>
  const columns = [
    ...(!studentId ? [{ key: 'student', header: 'الطالب', render: r => r.student?.is_archived ? <span className="text-[13px] font-bold text-text-gray">{r.student.full_name}<span className="block text-[11px] font-normal">{r.student.student_number} — مؤرشف</span></span> : <OfficeLink className="font-bold text-primary hover:underline" to={`/student-affairs/students/${r.student_id}?tab=discipline`}>{r.student?.full_name || 'اسم غير متاح'}<span className="block text-[11px] font-normal text-text-light">{r.student?.student_number}</span></OfficeLink> }] : []),
    { key: 'violation', header: 'المخالفة', render: r => <span className="text-[13px]">{r.violation_type?.violation_name_ar || 'نوع غير متاح'}</span> },
    { key: 'penalty', header: 'العقوبة', render: r => <span className="text-[13px]">{r.penalty_type?.penalty_name_ar || 'نوع غير متاح'}</span> },
    { key: 'date', header: 'تاريخ القرار', render: r => <span className="text-[12px]">{disciplineDate(r.decision_date)}</span> },
    { key: 'status', header: 'الحالة', render: r => <Badge>{caseStates[r.case_status] || 'حالة غير معروفة'}</Badge> },
    { key: 'actions', header: 'التفاصيل', render: r => <button type="button" className="inline-flex items-center gap-2 text-[12.5px] font-bold text-primary" onClick={() => openCase(r.case_id)}><FaEye />عرض السجل</button> },
  ]
  return <section className="space-y-5" dir="rtl">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div><h2 className="text-[22px] font-black text-text-dark">{disciplineTitle}</h2><p className="mt-1 text-[12.5px] text-text-light">سجل المخالفات والعقوبات والطعون المسجلة؛ لا يتضمن إنذارات انخفاض المعدل.</p></div>
      {canCreate && <button type="button" className={headerButton} onClick={() => setEditor({ mode: 'create' })}><FaPlus />تسجيل عقوبة</button>}
    </div>
    {notice && <Notice tone="success" onDismiss={() => setNotice('')}>{notice}</Notice>}
    {rawCaseId && !caseId && <Notice tone="error">معرف سجل العقوبة غير صحيح.</Notice>}
    <FilterBar search={!studentId ? { value: q, onChange: value => change('discipline_q', value), placeholder: 'ابحث بالاسم أو الرقم الجامعي…' } : null}
      filters={[{ key: 'status', value: status, onChange: value => change('discipline_status', value), placeholder: 'كل الحالات', options: Object.entries(caseStates).map(([value, label]) => ({ value, label })) }]}
      hasActiveFilters={!!q || !!status} onClear={clear} disabled={!!editor} />
    {currentError ? <Notice tone="error" action={<button className={secondaryButton} onClick={() => setReload(v => v + 1)}>إعادة المحاولة</button>}>{currentError}</Notice>
      : <DataTable columns={columns} rows={current?.data || []} rowKey={r => r.case_id} loading={!current} animationKey={requestKey}
        emptyIcon={FaBalanceScale} emptyTitle="لا توجد عقوبات مسجلة ضمن هذا النطاق" hasFilters={!!q || !!status} onClearFilters={clear}
        page={page} totalPages={current?.meta?.last_page || 1} onPageChange={value => change('discipline_page', String(value))} />}
    {(caseId || editor) && <Dialog title={editor ? editor.mode === 'create' ? 'تسجيل عقوبة صادرة' : editor.mode === 'appeal' ? 'تسجيل طعن' : 'تسجيل نتيجة الطعن' : 'تفاصيل العقوبة'} onClose={closeDialog}>
      {editor ? <DisciplinaryEditor key={`${identity}:${studentId || ''}:${caseId}:${editor.mode}:${editor.appealId || ''}`} {...editor} studentId={studentId || row?.student_id} caseId={row?.case_id} closeRef={editorClose} onClose={() => setEditor(null)} onSaved={saved} onDenied={authorityLost} />
        : <div className="space-y-5 p-5">
          {currentDetailError ? <Notice tone="error" action={<button className={secondaryButton} onClick={() => setReload(v => v + 1)}>إعادة المحاولة</button>}>{currentDetailError}</Notice> : !row ? <p className="text-[13px]">تحميل السجل…</p> : <>
            <Facts rows={[
              ['الطالب', `${row.student?.full_name || ''} — ${row.student?.student_number || ''}`], ['الحالة', caseStates[row.case_status]],
              ['المخالفة', row.violation_type?.violation_name_ar], ['العقوبة', row.penalty_type?.penalty_name_ar],
              ['تاريخ المخالفة', disciplineDate(row.violation_date)], ['وصف المخالفة', row.violation_description],
              ['رقم القرار', row.decision_number], ['تاريخ القرار', disciplineDate(row.decision_date)],
              ['جهة القرار', authorities[row.decided_by_authority] || 'غير مسجلة'], ['نوع القرار', row.is_in_absentia ? 'غيابي' : 'غير غيابي'],
              ['بداية العقوبة', disciplineDate(row.penalty_start_date)], ['نهاية العقوبة', disciplineDate(row.penalty_end_date)],
              ['التحقيق', row.investigation_status === 'pending' ? 'بانتظار التحقيق' : row.investigation_status === 'completed' ? 'مكتمل' : 'غير مسجل'],
            ]} />
            {row.affected_courses?.length > 0 && <div><h3 className="mb-2 text-[14px] font-bold">المقررات المتأثرة المسجلة</h3>{row.affected_courses.map(a => <p className="py-1 text-[12.5px]" key={a.affected_course_id}>{a.course_offering?.course?.course_name || 'طرح مقرر'} — {a.course_offering?.course?.course_code || `#${a.course_offering_id}`} — {a.reverted_at ? 'أُلغي الأثر' : 'طُبّق الأثر'}: {disciplineDate(a.reverted_at || a.applied_at)}</p>)}</div>}
            <div className="border-t border-primary/10 pt-4"><div className="mb-3 flex items-center justify-between gap-3"><h3 className="text-[15px] font-bold">الطعون ونتائجها</h3>{row.capabilities?.record_appeal && <button className={primaryButton} onClick={() => setEditor({ mode: 'appeal' })}>تسجيل طعن</button>}</div>
              {!row.appeals?.length ? <p className="text-[12.5px] text-text-light">لا يوجد طعن مسجل.</p> : row.appeals.map(a => <div key={a.appeal_id} className="mb-3 space-y-3 rounded-[12px] border border-primary/15 p-4">
                <Badge>{appealStates[a.appeal_status?.status_code] || 'حالة غير متاحة'}</Badge><Facts rows={[
                  ['تاريخ التقديم', disciplineDate(a.submitted_at)], ['سبب الطعن', a.appeal_reason], ['تاريخ النتيجة', disciplineDate(a.decision_date)], ['ملاحظات النتيجة', a.decision_notes],
                ]} />{a.can_record_decision && <button type="button" className={secondaryButton} onClick={() => setEditor({ mode: 'decision', appealId: a.appeal_id })}>تسجيل نتيجة الطعن</button>}
              </div>)}
            </div>
          </>}
        </div>}
    </Dialog>}
  </section>
}
