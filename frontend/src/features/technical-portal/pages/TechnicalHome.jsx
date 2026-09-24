import { Link } from 'react-router-dom'
import { motion } from 'framer-motion'
import { FaUserShield, FaInfoCircle, FaArrowLeft } from 'react-icons/fa'
import { ACCESS, canAccess, getIdentity } from '../../auth/auth'

// Brief landing page for the Technical Office portal. No statistics by design.
export default function TechnicalHome() {
  const user = getIdentity() ?? {}
  const roles = user.roles ?? []
  const permissions = user.permissions ?? []
  const canOpenAccounts = canAccess(ACCESS.technicalAccounts, user)
  const dateStr = new Date().toLocaleDateString('ar-SY', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })

  return (
    <>
      <motion.div
        className="flex items-center justify-between gap-4 flex-wrap bg-white border border-primary/15 rounded-[18px] px-7 py-[22px] mb-6 relative overflow-hidden shadow-[0_4px_24px_rgba(86,153,51,0.08)] max-[820px]:px-5"
        initial={{ opacity: 0, y: -14 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.45 }}
      >
        <div className="absolute top-0 left-0 right-0 h-1 animate-[barFlow_4s_linear_infinite]"
          style={{ background: 'linear-gradient(90deg,#569933,#7ab356,#a8d68a,#7ab356,#417327,#569933)', backgroundSize: '250% 100%' }} />
        <div dir="rtl">
          <h2 className="text-[21px] font-black text-text-dark mb-1">
            المكتب التقني
            <span className="text-[14px] font-medium text-text-light mr-2">Technical Office</span>
          </h2>
          <p className="text-[12.5px] text-text-light">{dateStr}</p>
        </div>
        <div className="flex items-center gap-2 py-2 px-4 rounded-[12px] bg-primary/7 border border-primary/16 flex-shrink-0" dir="rtl">
          <div className="w-8 h-8 rounded-full flex items-center justify-center text-[13px] font-black text-white" style={{ background: 'linear-gradient(135deg,#569933,#2d5c18)' }}>
            {(user.username?.[0] ?? 'U').toUpperCase()}
          </div>
          <div className="flex flex-col">
            <span className="text-[12px] font-bold text-primary-dark">{user.username}</span>
            <span className="text-[9px] text-text-light">الفريق التقني</span>
          </div>
        </div>
      </motion.div>

      <div className="grid grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] gap-5 max-[900px]:grid-cols-1" dir="rtl">
        <section className="bg-white border border-primary/12 rounded-[16px] p-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
          <h3 className="text-[16px] font-extrabold text-text-dark mb-2">مهمة البوابة</h3>
          <p className="text-[13px] text-text-gray leading-7 mb-4">
            إنشاء حسابات المستخدمين وإسناد الأدوار المناسبة لها وسحبها، وتفعيل الحسابات أو تعطيلها،
            ومراجعة الصلاحيات التي يكتسبها كل حساب من أدواره.
          </p>
          <div className="flex items-start gap-2 bg-primary/5 border border-primary/15 rounded-[12px] px-4 py-3 text-[12.5px] text-text-gray mb-5">
            <FaInfoCircle className="text-primary mt-1 flex-shrink-0" />
            <span>التبعية التنظيمية للمكتب التقني لمديرية الشؤون الإدارية لا تمنح أي صلاحية داخل النظام؛ الصلاحيات تُكتسب من الأدوار المسندة فقط.</span>
          </div>
          {canOpenAccounts && (
            <Link
              to="/technical/accounts"
              className="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-br from-primary to-primary-dark text-white rounded-[12px] text-[14px] font-bold shadow-[0_4px_16px_rgba(86,153,51,0.35)] hover:-translate-y-0.5 transition-all duration-[220ms] no-underline"
            >
              <FaUserShield /> الحسابات والصلاحيات <FaArrowLeft className="text-[11px]" />
            </Link>
          )}
        </section>

        <section className="bg-white border border-primary/12 rounded-[16px] p-6 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
          <h3 className="text-[16px] font-extrabold text-text-dark mb-3">صلاحيات حسابك</h3>
          <p className="text-[11.5px] font-bold text-text-light mb-1.5">الأدوار</p>
          <div className="flex flex-wrap gap-1.5 mb-4">
            {roles.length === 0 ? <span className="text-[12px] text-text-light">لا توجد أدوار</span> : roles.map(role => (
              <span key={role} className="px-2.5 py-[3px] bg-primary/8 border border-primary/15 rounded-[8px] text-[12px] font-bold text-primary-dark font-mono" dir="ltr">{role}</span>
            ))}
          </div>
          <p className="text-[11.5px] font-bold text-text-light mb-1.5">الصلاحيات الناتجة عن الأدوار</p>
          <div className="flex flex-wrap gap-1.5">
            {permissions.length === 0 ? <span className="text-[12px] text-text-light">لا توجد صلاحيات</span> : permissions.map(permission => (
              <span key={permission} className="px-2 py-[2px] bg-slate-50 border border-slate-200 rounded-[7px] text-[11.5px] text-text-gray font-mono" dir="ltr">{permission}</span>
            ))}
          </div>
        </section>
      </div>
    </>
  )
}
