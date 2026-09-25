import { Badge } from './MinistryUi'

// Rendering only: evidence and overall currentness come from the same server projection.
export function DeanOverallState({ row }) {
  return <Badge tone={row.has_conflict ? 'warning' : row.state === 'current' ? 'success' : 'neutral'}>{row.state_label}</Badge>
}

export function DeanAccountState({ row }) {
  return <div className="text-[11.5px] leading-6">
    <div>{row.account_active ? 'الحساب فعّال' : 'لا يوجد حساب فعّال'}</div>
    <div>{row.role_active ? 'دور العميد فعّال' : 'لا يوجد دور عميد فعّال'}</div>
    <div>{row.college_scope_active ? 'نطاق الكلية فعّال' : 'لا يوجد نطاق كلية فعّال'}</div>
    <div>{row.account_current ? 'حساب مخوّل حاليًا للكلية' : 'لا يوجد حساب عميد مخوّل حاليًا للكلية'}</div>
  </div>
}

export function DeanPositionState({ row }) {
  return <Badge tone={row.position_state === 'active' ? 'success' : 'neutral'}>{row.position_state_label}</Badge>
}
