import { Link } from 'react-router-dom'
import {
  FaBook, FaChalkboardTeacher, FaLockOpen, FaUsers, FaUserCog, FaClipboardList,
} from 'react-icons/fa'
import { hasAssignedPermission, PERMISSIONS } from '../../auth/auth'

export default function DeanQuickActions({ canManageTeachers }) {
  const navigation = [
    { to: '/dean/students', label: 'عرض الطلاب', Icon: FaUsers },
    { to: '/dean/teachers', label: 'عرض المدرسين', Icon: FaChalkboardTeacher },
    { to: '/dean/courses', label: 'عرض المواد', Icon: FaBook },
    hasAssignedPermission(PERMISSIONS.semesterOfferingGovernanceView)
      ? { to: '/dean/registration-offerings', label: 'حوكمة طروحات الفصل', Icon: FaLockOpen }
      : null,
  ].filter(Boolean)

  const management = [
    canManageTeachers
      ? { to: '/dean/teachers', label: 'إدارة تكليفات المدرسين', Icon: FaUserCog }
      : null,
    hasAssignedPermission(PERMISSIONS.semesterOfferingGovernanceManage)
      ? { to: '/dean/registration-offerings', label: 'تجهيز وإرسال الطروحات', Icon: FaClipboardList }
      : null,
  ].filter(Boolean)

  return (
    <section
      className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)]"
      dir="rtl"
    >
      <h2 className="text-[15px] font-black text-text-dark mb-4">إجراءات سريعة</h2>
      <div className="grid grid-cols-4 max-[1100px]:grid-cols-2 max-[560px]:grid-cols-1 gap-3">
        {navigation.map(({ to, label, Icon }) => (
          <Link
            key={label}
            to={to}
            className="flex items-center gap-3 rounded-[14px] border border-primary/15 bg-primary/5 px-4 py-3 text-text-dark no-underline transition-all duration-200 hover:-translate-y-[2px] hover:border-primary/35 hover:bg-primary/10"
          >
            <span className="w-10 h-10 rounded-[12px] bg-primary/12 text-primary flex items-center justify-center shrink-0">
              <Icon aria-hidden="true" />
            </span>
            <span className="text-[13.5px] font-bold">{label}</span>
          </Link>
        ))}
      </div>
      {management.length > 0 ? (
        <div className="mt-4 flex flex-wrap gap-2">
          {management.map(({ to, label, Icon }) => (
            <Link
              key={label}
              to={to}
              className="inline-flex items-center gap-2 rounded-[12px] border border-primary/20 bg-white px-3.5 py-2 text-[12.5px] font-bold text-primary-dark no-underline hover:bg-primary/8"
            >
              <Icon className="text-[12px]" aria-hidden="true" />
              {label}
            </Link>
          ))}
        </div>
      ) : null}
    </section>
  )
}
