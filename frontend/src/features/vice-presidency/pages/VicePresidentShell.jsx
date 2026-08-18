import { Link } from 'react-router-dom'
import { getIdentity } from '../../auth/auth'

const OFFICES = {
  scientific: {
    title: 'نائب رئيس الجامعة للشؤون العلمية',
    scopeNote: 'نطاق العمل: الجامعة كاملة. الصلاحيات تُحدد لكل إجراء على حدة.',
    assignmentsPath: '/vp/scientific/teaching-assignments',
    exceptionalPath: '/vp/scientific/exceptional-openings',
    futureSections: [
      'الموافقة العلمية على إغلاق الشعب',
    ],
  },
  administrative: {
    title: 'نائب رئيس الجامعة للشؤون الإدارية',
    scopeNote: 'نطاق العمل: الجامعة كاملة. الصلاحيات تُحدد لكل إجراء على حدة.',
    assignmentsPath: '/vp/administrative/teaching-assignments',
    exceptionalPath: '/vp/administrative/exceptional-openings',
    futureSections: [],
  },
}

function scopeLabel(scope) {
  if (!scope) return ''
  if (typeof scope === 'string') return scope
  if (scope.type) return scope.type
  return ''
}

export default function VicePresidentShell({ office }) {
  const copy = OFFICES[office] ?? OFFICES.scientific
  const identity = getIdentity()
  const roles = Array.isArray(identity?.roles) ? identity.roles.join('، ') : '—'
  const scopes = Array.isArray(identity?.access_scopes) && identity.access_scopes.length > 0
    ? identity.access_scopes.map(scopeLabel).filter(Boolean).join('، ')
    : '—'
  const unitName = identity?.organizational_unit?.name || identity?.organizational_unit || '—'

  return (
    <div className="flex flex-col gap-5 py-8 px-2" dir="rtl">
      <div>
        <p className="text-[18px] font-black text-text-dark">{copy.title}</p>
        <p className="text-[13px] text-text-light mt-1">{copy.scopeNote}</p>
      </div>

      <div className="bg-white border border-black/5 rounded-[16px] p-5 shadow-sm">
        <p className="text-[12px] font-bold text-text-light mb-3">الهوية الحالية</p>
        <dl className="grid gap-2 text-[13px] text-text-dark">
          <div className="flex justify-between gap-4">
            <dt className="text-text-light">المستخدم</dt>
            <dd>{identity?.username || identity?.email || '—'}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-text-light">الأدوار</dt>
            <dd>{roles || '—'}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-text-light">الوحدة التنظيمية</dt>
            <dd>{typeof unitName === 'string' ? unitName : unitName?.name || '—'}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-text-light">نطاق الوصول</dt>
            <dd>{scopes}</dd>
          </div>
        </dl>
      </div>

      <Link
        to={copy.assignmentsPath}
        className="bg-white border border-primary/15 rounded-[16px] p-5 shadow-sm hover:border-primary/40"
      >
        <p className="text-[15px] font-black text-text-dark">تكليفات المدرسين</p>
        <p className="text-[13px] text-text-light mt-1">مراجعة طلبات تكليف المدرسين ضمن صلاحية هذه النيابة فقط.</p>
      </Link>

      <Link
        to={copy.exceptionalPath}
        className="bg-white border border-primary/15 rounded-[16px] p-5 shadow-sm hover:border-primary/40"
      >
        <p className="text-[15px] font-black text-text-dark">الفتح الاستثنائي للشعب</p>
        <p className="text-[13px] text-text-light mt-1">مراجعة طلبات فتح الشعب استثنائيًا عند نقص تكليف المدرسين ضمن صلاحية هذه النيابة فقط.</p>
      </Link>

      {copy.futureSections.length > 0 && (
      <div className="bg-white border border-dashed border-primary/25 rounded-[16px] p-5">
        <p className="text-[12px] font-bold text-text-light mb-3">أقسام لاحقة — غير مفعّلة في هذه المرحلة</p>
        <ul className="flex flex-col gap-2 text-[13px] text-text-dark">
          {copy.futureSections.map(section => (
            <li key={section} className="text-text-light">• {section}</li>
          ))}
        </ul>
      </div>
      )}
    </div>
  )
}
