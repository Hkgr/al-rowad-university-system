import { Button, Notice } from '../scientific-courses/CatalogControls'
import CatalogConflict from '../scientific-courses/CatalogConflict'
import useCatalogMutation from '../scientific-courses/useCatalogMutation'
import { entityRead, entityWrite } from './entities'

export default function StructureDeletion({ kind, id, baseline, guard, onUnauthorized, onDeleted, onRestart }) {
  const mutation = useCatalogMutation({ currentPath: `/${kind}/${id}`, readCurrent: entityRead, send: entityWrite, onSaved: onDeleted,
    onUnauthorized, onBusy: guard.onBusy, onBlocked: guard.onBlocked })
  return <div className="space-y-3"><p>هل تريد حذف {baseline.entity[kind === 'colleges' ? 'college_name' : 'department_name']}؟ لا تُحذف العناصر المرتبطة بتسجيلات أو عناصر أخرى.</p><Notice>{baseline.capabilities.delete_lock_reason}</Notice><CatalogConflict mutation={mutation} onRestart={onRestart} /><Button danger disabled={guard.busy || mutation.blocked || !baseline.capabilities.delete} onClick={() => mutation.write(`/${kind}/${id}`, 'DELETE', { revision: baseline.revision, confirmed: true })}>تأكيد الحذف</Button></div>
}
