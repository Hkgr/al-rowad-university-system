import { useState } from 'react'
import { selectedContext } from '../lib/manualGradeGrid'
import { RegistrationGridRow, field } from './RegistrationGridRow'
import PreparationGradeRow from './PreparationGradeRow'

export default function CatalogGradeRow({ course, change, ...props }) {
  const [offeringId, setOfferingId] = useState('')
  const [registrationId, setRegistrationId] = useState('')
  const { offering, registration, attempts } = selectedContext(course, offeringId, registrationId)
  const context = <div className="space-y-2">
    {course.offerings.length > 0 && <label>الطرح الفعلي<select className={field} disabled={props.readOnly} value={offering?.course_offering_id ?? ''}
      onChange={e => { const value = e.target.value; change(() => { setOfferingId(value); setRegistrationId('') }) }}>
      <option value="">اختر الطرح / الشعبة</option>{course.offerings.map(o => <option key={o.course_offering_id} value={o.course_offering_id}>{o.academic_year} / {o.semester} — {o.program} — الشعبة {o.course_offering_id} ({o.status})</option>)}
    </select></label>}
    {attempts.length > 1 && <label>التسجيل / المحاولة<select className={field} disabled={props.readOnly} value={registration?.registration_id ?? ''}
      onChange={e => { const value = e.target.value; change(() => setRegistrationId(value)) }}><option value="">اختر المحاولة صراحةً</option>
      {attempts.map(r => <option key={r.registration_id} value={r.registration_id}>{r.registration_id} — {r.registration_status}</option>)}
    </select></label>}
  </div>
  return <RowMode key={`${props.draftEpoch}:${props.term?.academic_year_id}:${props.term?.semester_id}:${offeringId}:${registrationId}`}
    {...props} course={course} context={context} offering={offering} registration={registration}
    ambiguous={(!offering && course.offerings.length > 1) || (!registration && attempts.length > 1)} />
}

function RowMode({ registration, offering, ...props }) {
  // Refreshing another row never replaces an in-progress preparation draft with a newly discovered registration.
  const [integrated, setIntegrated] = useState(() => !registration || !registration.components.length || offering?.configuration_missing)
  const [saved, setSaved] = useState(null)
  if (integrated) return <PreparationGradeRow {...props} offering={offering} registration={registration}
    onSaved={row => { setSaved({ row, priorRevision: registration?.revision }); setIntegrated(false) }} />
  const row = saved && (!registration || registration.revision === saved.priorRevision) ? saved.row : registration
  return row && <RegistrationGridRow {...props} row={row} requiredParts={offering?.required_parts} />
}
