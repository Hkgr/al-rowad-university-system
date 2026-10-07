import { Link } from 'react-router-dom'
import { FaUsers, FaMoneyBillWave, FaMinusCircle, FaPlusCircle, FaCoins, FaArrowLeft } from 'react-icons/fa'
import { Notice, PageHeader, Section, StatCard, StatePanel } from '../../ministry-portal/components/MinistryUi'
import { useMinistryResource } from '../../ministry-portal/lib/useMinistryResource'
import { viewState, formatDateTime } from '../../ministry-portal/lib/ministryState'
import { fetchOwnerHome } from '../lib/ownerApi'
import { centsFromString, formatMoney } from '../lib/payrollMoney'

const FORBIDDEN = 'لا يملك حسابك صلاحية عرض الرئيسية في بوابة مالك الجامعة.'

export default function OwnerHome() {
  const { data, loading, error, reload } = useMinistryResource(fetchOwnerHome, [])
  const d = data?.data
  const state = viewState({ loading, error })
  const money = key => centsFromString(d?.[key])

  return (
    <>
      <PageHeader title="مالك الجامعة — الرئيسية" en="Home" subtitle="ملخص ورقة الرواتب الحالية (ورقة عمل واحدة، ليست سجلًا شهريًا).">
        <Link to="/owner/payroll" className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-white transition-colors hover:bg-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
          الانتقال إلى الرواتب <FaArrowLeft aria-hidden="true" className="text-[11px]" />
        </Link>
      </PageHeader>

      {state !== 'ready' ? <StatePanel state={state} error={error} message={state === 'forbidden' ? FORBIDDEN : undefined} onRetry={reload} /> : (
        <div className="grid gap-5">
          <Section title="ملخص الرواتب" subtitle={`جميع الموظفين المسجلين في ورقة الرواتب. آخر تحديث: ${formatDateTime(d.generated_at)}`} id="payroll-summary">
            <div className="grid grid-cols-5 gap-3 max-[1200px]:grid-cols-3 max-[800px]:grid-cols-2 max-[480px]:grid-cols-1">
              <StatCard label="عدد الموظفين" value={d.employees} format={count => new Intl.NumberFormat('en-US').format(count)} Icon={FaUsers} />
              <StatCard label="إجمالي الرواتب المقطوعة $" value={money('fixed_salary')} format={formatMoney} Icon={FaMoneyBillWave} />
              <StatCard label="إجمالي الاقتطاعات $" value={money('deduction')} format={formatMoney} Icon={FaMinusCircle} />
              <StatCard label="إجمالي التعويضات $" value={money('compensation')} format={formatMoney} Icon={FaPlusCircle} />
              <StatCard label="إجمالي المستحق $" value={money('payable')} format={formatMoney} Icon={FaCoins} note="يجمع الموظفين الذين لهم راتب مقطوع فقط" />
            </div>
          </Section>
          {d.employees === 0 && (
            <Notice>لم يُسجَّل أي موظف في ورقة الرواتب بعد. ابدأ من صفحة <Link to="/owner/payroll" className="font-bold text-primary-dark underline">الرواتب</Link>: أنشئ هيئة أولًا من «إدارة الهيئات» ثم أضف الموظفين.</Notice>
          )}
          <Notice>المبالغ كلها بالدولار الأمريكي. الاقتطاع والتعويض الفارغان يُحتسبان صفرًا في المستحق، والمستحق يبقى فارغًا إذا لم يُدخل الراتب المقطوع.</Notice>
        </div>
      )}
    </>
  )
}
