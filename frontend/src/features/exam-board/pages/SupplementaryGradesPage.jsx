import { useCallback, useEffect, useState } from 'react'
import { FaCheck, FaDatabase, FaUndoAlt, FaUpload } from 'react-icons/fa'
import { apiRequest } from '../../../services/apiClient'

const materializationLabels = {
  waiting: 'منشورة - بانتظار الترحيل الرسمي',
  materialized: 'مرحّلة إلى السجل الرسمي',
  conflict: 'تعارض - يتعذر الترحيل',
  no_candidates: 'لا توجد قائمة طلاب',
  not_ready: 'غير جاهزة للترحيل',
}

const workflowLabels = {
  waiting: 'بانتظار العلامات',
  draft: 'مسودة',
  submitted: 'مرسلة للمراجعة',
  returned: 'معادة للتصحيح',
  approved: 'معتمدة',
  published: 'منشورة',
}

export default function SupplementaryGradesPage() {
  const [rows, setRows] = useState([])
  const [message, setMessage] = useState('')
  const [busyOfferingId, setBusyOfferingId] = useState(null)

  const load = useCallback(async () => {
    try {
      const response = await apiRequest('/v1/exams/supplementary-grades')
      setRows(response.data || [])
    } catch (error) {
      setMessage(error.message)
    }
  }, [])

  useEffect(() => {
    load()
  }, [load])

  const review = async (row, action) => {
    let body
    if (action === 'return') {
      const reason = window.prompt('سبب الإرجاع (إلزامي)')
      if (!reason) return
      body = { reason }
    }

    try {
      await apiRequest(
        `/v1/exams/supplementary-grades/${row.submission.supplementary_exam_grade_submission_id}/${action}`,
        { method: 'POST', body: body ? JSON.stringify(body) : undefined },
      )
      setMessage('تم تنفيذ الإجراء مع حفظ سجل التدقيق.')
      await load()
    } catch (error) {
      setMessage(error.message)
    }
  }

  const materialize = async (row) => {
    const confirmed = window.confirm(
      'ستصبح العلامات النظرية التكميلية المنشورة علامات رسمية، مع إبقاء العلامات العملية الأصلية دون تغيير. يؤثر هذا الإجراء في السجل الأكاديمي الرسمي للطلاب. هل تريد المتابعة؟',
    )
    if (!confirmed) return

    const offeringId = row.offering.supplementary_exam_offering_id
    setBusyOfferingId(offeringId)
    try {
      const response = await apiRequest(
        `/v1/exams/supplementary-offerings/${offeringId}/materialize`,
        { method: 'POST' },
      )
      const result = response.data
      setMessage(
        result.status === 'already_materialized'
          ? 'النتائج مرحّلة مسبقاً، ولم يُجرَ أي تعديل مكرر.'
          : `تم ترحيل ${result.materialized_count} نتيجة إلى السجل الرسمي.`,
      )
      await load()
    } catch (error) {
      setMessage(error.message)
    } finally {
      setBusyOfferingId(null)
    }
  }

  return (
    <main className="p-6" dir="rtl">
      <h1 className="text-xl font-black">علامات الامتحانات التكميلية</h1>

      {message && (
        <p className="my-3 border-r-4 border-primary bg-white px-3 py-2 font-bold" role="status">
          {message}
        </p>
      )}

      <div className="space-y-4">
        {rows.map((row) => {
          const offeringId = row.offering.supplementary_exam_offering_id
          const materialization = row.materialization || { state: 'not_ready' }
          const busy = busyOfferingId === offeringId

          return (
            <article className="rounded-md border bg-white p-4" key={offeringId}>
              <header className="flex flex-wrap items-center justify-between gap-2">
                <b>
                  {row.offering.course?.course_code} - {row.offering.course?.course_name}
                </b>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span>{workflowLabels[row.workflow_status] || row.workflow_status}</span>
                  <span>الإصدار {row.submission?.submission_version || '-'}</span>
                  <span className="rounded border px-2 py-1 font-bold">
                    {materializationLabels[materialization.state] || materialization.state}
                  </span>
                </div>
              </header>

              <div className="mt-3 overflow-x-auto">
                <table className="w-full min-w-[620px] text-right">
                  <thead>
                    <tr>
                      <th className="p-2">الطالب</th>
                      <th className="p-2">العملي المحفوظ</th>
                      <th className="p-2">النظري التكميلي</th>
                      <th className="p-2">المجموع المتوقع</th>
                      <th className="p-2">السجل الرسمي</th>
                    </tr>
                  </thead>
                  <tbody>
                    {row.roster.map((candidate) => (
                      <tr className="border-t" key={candidate.supplementary_exam_registration_id}>
                        <td className="p-2">{candidate.student?.student_number}</td>
                        <td className="p-2">{candidate.preserved_practical_mark ?? '-'}</td>
                        <td className="p-2">{candidate.supplementary_theoretical_mark ?? '-'}</td>
                        <td className="p-2">{candidate.preview?.final_mark ?? '-'}</td>
                        <td className="p-2">
                          {candidate.official_record_materialized ? 'مرحّل' : 'غير مرحّل'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="mt-3 flex flex-wrap gap-2">
                {row.workflow_status === 'submitted' && (
                  <>
                    <button
                      className="inline-flex items-center gap-2 rounded border px-3 py-2"
                      onClick={() => review(row, 'return')}
                      type="button"
                    >
                      <FaUndoAlt aria-hidden="true" />
                      إرجاع مع سبب
                    </button>
                    <button
                      className="inline-flex items-center gap-2 rounded bg-primary px-3 py-2 text-white"
                      onClick={() => review(row, 'approve')}
                      type="button"
                    >
                      <FaCheck aria-hidden="true" />
                      اعتماد
                    </button>
                  </>
                )}

                {row.workflow_status === 'approved' && (
                  <button
                    className="inline-flex items-center gap-2 rounded bg-green-700 px-3 py-2 text-white"
                    onClick={() => review(row, 'publish')}
                    type="button"
                  >
                    <FaUpload aria-hidden="true" />
                    نشر
                  </button>
                )}

                {materialization.can_materialize && (
                  <button
                    className="inline-flex items-center gap-2 rounded bg-blue-700 px-3 py-2 text-white disabled:opacity-60"
                    disabled={busy}
                    onClick={() => materialize(row)}
                    type="button"
                  >
                    <FaDatabase aria-hidden="true" />
                    {busy ? 'جارٍ الترحيل...' : 'ترحيل النتائج إلى السجل الرسمي'}
                  </button>
                )}
              </div>
            </article>
          )
        })}
      </div>
    </main>
  )
}
