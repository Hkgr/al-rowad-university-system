import { HrStatus, HrText } from './HrOfficeUi'
import { Link } from 'react-router-dom'
import { hrDate, label, workerFilePath } from '../lib/hrOffice'

export const personName = row => `${row?.first_name || ''} ${row?.last_name || ''}`.trim()
const sameId = (left, right) => left != null && right != null && String(left) === String(right)
export const placementName = (row, options) => options?.colleges.find(c => sameId(c.college_id, row?.college_id))?.college_name
  || options?.units.find(u => sameId(u.organizational_unit_id, row?.organizational_unit_id))?.unit_name || 'غير محدد'
export const positionName = (row, options) => options?.positions.find(p => sameId(p.position_id, row?.position_id))?.position_title || row?.job_title || 'غير محدد'
const number = value => value == null ? '—' : new Intl.NumberFormat('ar-SY').format(value)
const nameCell = (primary, secondary) => <HrText secondary={secondary}>{primary}</HrText>
const countColumn = (key, header) => ({ key, header, align: 'center', render: row => <span className="text-[14px] font-bold tabular-nums text-text-dark">{number(row[key])}</span> })

export function needItemColumns(options) {
  return [
    { key: 'position', header: 'المنصب', render: row => nameCell(positionName(row, options), row.position_id ? row.job_title : null) },
    countColumn('quantity', 'المطلوب'), countColumn('filled', 'المشغول'), countColumn('remaining', 'المتبقي'),
    { key: 'active', header: 'الحالة', render: row => <HrStatus value={row.is_active ? 'open' : 'closed'}>{row.is_active ? 'بند فعال' : 'بند غير فعال'}</HrStatus> },
  ]
}

export function officeColumns(section, options) {
  let columns
  if (section === 'needs') columns = [
    { key: 'title', header: 'الاحتياج', render: row => nameCell(row.title, label(row.body)) },
    { key: 'placement', header: 'الكلية / الوحدة', render: row => nameCell(placementName(row, options)) },
    { key: 'positions', header: 'المناصب المطلوبة', render: row => <div className="space-y-1">{row.items.slice(0, 2).map(item => <HrText key={item.id}>{positionName(item, options)}</HrText>)}{row.items.length > 2 && <span className="text-[11px] text-text-light">+ {number(row.items.length - 2)} أخرى</span>}</div> },
    countColumn('requested_quantity', 'المطلوب'), countColumn('filled_quantity', 'المشغول'), countColumn('remaining_quantity', 'المتبقي'),
    { key: 'status', header: 'الحالة', render: row => <HrStatus value={row.status} /> },
  ]
  else if (section === 'candidates') columns = [
    { key: 'name', header: 'المرشح', render: row => nameCell(personName(row)) },
    { key: 'need', header: 'الاحتياج / المنصب', render: row => nameCell(positionName({ position_id: row.need_position_id, job_title: row.need_job_title }, options), row.need_title) },
    { key: 'placement', header: 'الكلية / الوحدة', render: row => nameCell(placementName(row, options)) },
    { key: 'status', header: 'الحالة', render: row => <HrStatus value={row.status} /> },
    { key: 'date', header: 'آخر تحديث', render: row => <span className="whitespace-nowrap text-[12px] text-text-gray">{hrDate(row.updated_at)}</span> },
  ]
  else if (['workers', 'classification'].includes(section)) columns = [
    { key: 'name', header: 'العامل / الرقم', render: row => nameCell(<Link className="text-primary hover:underline" to={workerFilePath(row.id)}>{personName(row)}</Link>, row.employee_number) },
    { key: 'body', header: 'الهيئة الحالية', render: row => <HrStatus value={row.current_body}>{row.current_body ? label(row.current_body) : row.relationships?.length ? 'لا علاقة نافذة' : 'غير محدد'}</HrStatus> },
    { key: 'position', header: 'المنصب', render: row => row.current_relationship ? nameCell(positionName(row.current_relationship, options)) : nameCell((row.recorded_positions || []).map(p => positionName(p, options)).join('، ')) },
    { key: 'placement', header: 'الكلية / الوحدة', render: row => nameCell(placementName(row.current_relationship || row, options)) },
    { key: 'relation', header: 'العلاقة / نمط العمل', render: row => row.current_relationship ? nameCell(label(row.current_relationship.relationship_type), label(row.current_relationship.work_mode)) : nameCell(row.relationships?.length ? 'سابقة / مستقبلية' : 'غير موثقة') },
  ]
  else if (section === 'requests') columns = [
    { key: 'target', header: 'العامل / المرشح', render: row => nameCell(`${row.target_first_name || ''} ${row.target_last_name || ''}`.trim(), `طلب ${number(row.id)}`) },
    { key: 'position', header: 'المنصب / الإجراء', render: row => nameCell(positionName(row.display_proposal, options), label(row.kind)) },
    { key: 'placement', header: 'الكلية / الوحدة', render: row => nameCell(placementName(row.display_proposal, options)) },
    { key: 'status', header: 'الحالة', render: row => <HrStatus value={row.status} /> },
    { key: 'submitted', header: 'تاريخ الإرسال', render: row => <span className="whitespace-nowrap text-[12px] text-text-gray">{row.submitted_at ? hrDate(row.submitted_at) : 'لم يُرسل'}</span> },
  ]
  else columns = [
    { key: 'employee', header: 'العامل / الرقم', render: row => nameCell(<Link className="text-primary hover:underline" to={workerFilePath(row.employee_id)}>{`${row.employee_first_name || ''} ${row.employee_last_name || ''}`.trim() || 'غير محدد'}</Link>, row.employee_number) },
    { key: 'position', header: 'المنصب', render: row => nameCell(positionName(row, options)) },
    { key: 'placement', header: 'الكلية / الوحدة', render: row => nameCell(placementName(row, options)) },
    { key: 'type', header: 'العلاقة / نمط العمل', render: row => nameCell(label(row.relationship_type), label(row.work_mode)) },
    { key: 'dates', header: 'التواريخ المسجلة', render: row => nameCell(hrDate(row.starts_on), row.ends_on ? hrDate(row.ends_on) : 'مستمر') },
    { key: 'status', header: 'النفاذ', render: row => <HrStatus value={row.temporal_status}>{({ effective: 'نافذة حاليًا', future: 'تبدأ لاحقًا', ended: 'سابقة', cancelled: 'أُلغي النفاذ' })[row.temporal_status] || 'غير محدد'}</HrStatus> },
  ]
  return columns
}
