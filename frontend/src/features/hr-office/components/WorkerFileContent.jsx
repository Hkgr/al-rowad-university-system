import { Link } from 'react-router-dom'
import { HrStatus, PayrollLinkStatus } from './HrOfficeUi'
import { hrDate, label } from '../lib/hrOffice'
import { placementName, positionName } from './HrOfficeTables'
import { secondaryButton } from '../../vice-presidency/components/GovernanceUi'

export function WorkerFacts({ entries }) {
  return <dl className="grid grid-cols-2 gap-4 max-[560px]:grid-cols-1">{entries.map(([title, value]) => <div key={title} className="rounded-[10px] bg-primary/5 p-3"><dt className="text-[11.5px] text-text-light">{title}</dt><dd className="mt-1 break-words text-[13px] font-bold">{value || 'غير محدد'}</dd></div>)}</dl>
}

export default function WorkerFileContent({ worker, options, payrollRead, canLink, onLink, onCorrect, onCancel }) {
  return <div className="space-y-5">
    <WorkerFacts entries={[
      ['الهيئة الحالية', worker.employee.current_body ? label(worker.employee.current_body) : 'غير محددة / لا علاقة نافذة'],
      ['نوع الموظف', options.employee_types.find(t => String(t.employee_type_id) === String(worker.employee.employee_type_id))?.type_name],
      ['الوحدة الأساسية المسجلة', placementName(worker.employee, options)],
      ['الملف التعليمي', worker.faculty ? worker.faculty.academic_rank || 'ملف تعليمي قائم' : 'لا يوجد ملف تعليمي'],
    ]} />
    <PayrollLinkStatus detail={worker} canView={payrollRead} canLink={canLink} onLink={onLink} />
    <section className="space-y-3"><h2 className="text-[16px] font-bold">العلاقات والعقود المسجلة</h2>{worker.relationships.length === 0 ? <p className="text-[12.5px] text-text-light">لم تُوثق علاقة وظيفية بعد.</p> : worker.relationships.map(row => <article key={row.id} className="rounded-[12px] border border-primary/15 bg-white p-4 space-y-3">
      <div className="flex flex-wrap justify-between gap-3"><h3 className="font-bold text-[13px]">{positionName(row, options)}</h3><HrStatus value={row.cancelled_from ? 'cancelled' : row.superseded_from ? 'ended' : 'approved'}>{row.cancelled_from ? `إلغاء النفاذ من ${hrDate(row.cancelled_from)}` : row.superseded_from ? `استبدال مسجل من ${hrDate(row.superseded_from)}` : 'علاقة مسجلة'}</HrStatus></div>
      <WorkerFacts entries={[
        ['الهيئة / الجهة', `${label(row.body)} — ${placementName(row, options)}`], ['العلاقة / النمط', `${label(row.relationship_type)} — ${label(row.work_mode)}`],
        ['البداية الأصلية', hrDate(row.starts_on)], ['النهاية الأصلية', row.ends_on ? hrDate(row.ends_on) : 'مستمر'],
        ['المصدر', row.source === 'audited_correction' ? 'تصحيح موثق — الأصل محفوظ' : label(row.source)], ['الملاحظات', row.notes],
      ]} />
      {row.action_lock_reason && <p className="text-[11.5px] text-text-light">{row.action_lock_reason}</p>}
      <div className="flex flex-wrap gap-2">{row.can_correct && onCorrect && <button type="button" className={secondaryButton} onClick={() => onCorrect(row)}>تصحيح العلاقة</button>}{row.can_cancel && onCancel && <button type="button" className={`${secondaryButton} text-red-700`} onClick={() => onCancel(row)}>إلغاء نفاذ العلاقة</button>}</div>
    </article>)}</section>
    <details className="rounded-[12px] border border-primary/15 p-4"><summary className="cursor-pointer font-bold text-[13px]">المناصب والانتماءات المسجلة</summary><div className="mt-3 space-y-3">{worker.positions.map((row, index) => <p className="text-[12.5px] leading-7" key={`position-${index}`}>{positionName(row, options)} — {hrDate(row.start_date)} إلى {row.end_date ? hrDate(row.end_date) : 'قيد مفتوح'}</p>)}{worker.affiliations.map((row, index) => <p className="text-[12.5px] leading-7" key={`unit-${index}`}>{placementName(row, options)} — {hrDate(row.start_date)} إلى {row.end_date ? hrDate(row.end_date) : 'قيد مفتوح'}</p>)}</div></details>
    <details className="rounded-[12px] border border-primary/15 p-4"><summary className="cursor-pointer font-bold text-[13px]">سجل التغييرات والتدقيق</summary><ol className="mt-3 space-y-3">{worker.events.map(event => {
      const details = typeof event.details === 'string' ? JSON.parse(event.details) : event.details
      return <li key={event.id} className="border-b border-primary/10 pb-3 text-[12px] leading-7"><p className="font-bold">{({ relationship_cancelled: 'إلغاء نفاذ العلاقة', relationship_corrected: 'تصحيح العلاقة مع حفظ الأصل', payroll_linked: 'ربط الملف المالي', classification_completed: 'استكمال البيانات القائمة' })[event.action] || 'تغيير موثق'} — {hrDate(event.created_at)}</p>{details?.reason && <p>{details.reason}</p>}{details?.effective_on && <p>نافذ من: {hrDate(details.effective_on)}</p>}<p className="text-text-light">مرجع المنفذ: {event.actor_user_id}</p></li>
    })}</ol></details>
    <Link to="/vp/administrative/hr" className="text-[12.5px] font-bold text-primary hover:underline">العودة إلى مكتب الموارد البشرية</Link>
  </div>
}
