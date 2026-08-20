import { instructorCoverageComplete, instructorCoverageSummary } from '../utils/courseOfferingDisplay'
import { offeringStatusLabel } from '../utils/teacherDisplay'
import { reviewStatusLabel } from '../../vice-presidency/utils/exceptionalOpeningLabels'

export function canNormalOpenOffering(offering) {
  return offering?.status === 'closed' && instructorCoverageComplete(offering?.instructor_coverage)
}

export function canRequestExceptionalOpening(offering, canRequestPermission) {
  return offering?.status === 'closed'
    && !instructorCoverageComplete(offering?.instructor_coverage)
    && Boolean(canRequestPermission)
}

export function exceptionalRequestStateLabel(status) {
  if (status === 'submitted') return 'قيد المراجعة'
  if (status === 'returned') return 'معاد للتعديل'
  if (status === 'approved') return 'معتمد'
  return status || '—'
}

export default function DeanOfferingStatusPanel({
  offering,
  exceptionRequest,
  canManage,
  canRequestException,
  busy,
  onOpenRegistration,
  onRequestException,
  onResubmitException,
}) {
  const closed = offering?.status === 'closed'
  const open = offering?.status === 'open'
  const coverageComplete = instructorCoverageComplete(offering?.instructor_coverage)
  const showNormalOpen = canManage && canNormalOpenOffering(offering)
  const showExceptionRequest = canRequestExceptionalOpening(offering, canRequestException)
    && (!exceptionRequest || exceptionRequest.status === 'returned' || exceptionRequest.status === 'superseded')
  const showExceptionStatus = Boolean(exceptionRequest)
    && closed
    && exceptionRequest.status !== 'superseded'

  return (
    <section>
      <h3 className="text-[15px] font-extrabold text-text-dark mb-3">إدارة حالة الطرح</h3>
      <div className="space-y-2 text-[13px] text-text-dark">
        <p>
          حالة التسجيل:{' '}
          <span className="font-extrabold">{offeringStatusLabel(offering?.status)}</span>
        </p>
        <p>
          اكتمال تكليف المدرسين:{' '}
          <span className={`font-extrabold ${coverageComplete ? 'text-green-700' : 'text-amber-800'}`}>
            {instructorCoverageSummary(offering?.instructor_coverage)}
          </span>
        </p>
      </div>

      {open && (
        <p className="mt-3 text-[13.5px] font-bold text-green-700">التسجيل مفتوح</p>
      )}

      {closed && !coverageComplete && (
        <p className="mt-3 text-[13.5px] font-bold text-amber-800">
          بانتظار استكمال تكليف المدرسين
        </p>
      )}

      {showExceptionStatus && (
        <div className="mt-3 rounded-[12px] border border-primary/15 bg-primary/[0.04] px-3.5 py-3 text-[12.5px] text-text-dark space-y-1">
          <p className="font-bold">طلب الفتح الاستثنائي: {exceptionalRequestStateLabel(exceptionRequest.status)}</p>
          <p>مراجعة النائب العلمي: {reviewStatusLabel(exceptionRequest.scientific_review?.status)}</p>
          <p>مراجعة النائب الإداري: {reviewStatusLabel(exceptionRequest.administrative_review?.status)}</p>
          {exceptionRequest.status === 'returned' && (exceptionRequest.scientific_review?.notes || exceptionRequest.administrative_review?.notes) && (
            <p className="text-amber-800 whitespace-pre-wrap">
              {exceptionRequest.scientific_review?.notes || exceptionRequest.administrative_review?.notes}
            </p>
          )}
        </div>
      )}

      <div className="mt-4 flex flex-wrap gap-2">
        {showNormalOpen && (
          <button
            type="button"
            className="px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:enabled:bg-primary-dark disabled:opacity-40"
            onClick={onOpenRegistration}
            disabled={busy}
          >
            فتح التسجيل
          </button>
        )}
        {showExceptionRequest && (
          <button
            type="button"
            className="px-4 py-2 border border-primary/30 text-primary-dark rounded-[10px] text-[13px] font-bold hover:bg-primary/8 disabled:opacity-40"
            onClick={exceptionRequest?.status === 'returned' ? onResubmitException : onRequestException}
            disabled={busy}
          >
            {exceptionRequest?.status === 'returned' ? 'إعادة إرسال طلب الفتح الاستثنائي' : 'طلب فتح استثنائي'}
          </button>
        )}
      </div>
    </section>
  )
}
