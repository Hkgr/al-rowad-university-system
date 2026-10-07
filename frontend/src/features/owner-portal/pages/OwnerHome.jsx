import { Link } from 'react-router-dom'
import { FaArrowLeft } from 'react-icons/fa'
import { StatePanel } from '../../ministry-portal/components/MinistryUi'
import { useMinistryResource } from '../../ministry-portal/lib/useMinistryResource'
import { viewState, formatDateTime } from '../../ministry-portal/lib/ministryState'
import { fetchOwnerHome } from '../lib/ownerApi'
import { formatAmount, formatSyp, SYMBOL } from '../lib/payrollMoney'

const FORBIDDEN = 'لا يملك حسابك صلاحية عرض الرئيسية في بوابة مالك الجامعة.'
const count = n => new Intl.NumberFormat('en-US').format(n)
const OPEN = 'inline-flex items-center gap-2 rounded-[10px] bg-primary px-4 py-2.5 text-[13px] font-bold text-white transition-colors hover:bg-primary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary'
const STATUS = { incomplete: ['ناقص أو به خطأ', 'bg-red-50 text-red-700'], warning: ['تحذير حسابي', 'bg-amber-50 text-amber-800'] }

/** Rows that open the payroll sheet already filtered (body / workplace); the figure is the net payable of complete records. */
function Breakdown({ title, rows, param, caption }) {
  return (
    <section aria-labelledby={`grp-${param}`}>
      <h3 id={`grp-${param}`} className="mb-2 text-[13px] font-black text-text-dark">{title}</h3>
      <table className="w-full border-collapse text-[12.5px]" data-testid={`by-${param}`}>
        <caption className="sr-only">{caption}</caption>
        <thead><tr className="text-text-light"><th scope="col" className="pb-1.5 text-start font-bold">{param === 'body_id' ? 'الهيئة' : 'مكان العمل'}</th><th scope="col" className="pb-1.5 text-center font-bold">الموظفون</th><th scope="col" className="pb-1.5 text-end font-bold">الصافي المستحق ({SYMBOL})</th></tr></thead>
        <tbody>
          {rows.map(row => (
            <tr key={row.value} className="border-t border-primary/10">
              <td className="py-1.5"><Link className="font-bold text-primary-dark underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-primary" to={`/owner/payroll?${param}=${encodeURIComponent(row.value)}`}>{row.label}</Link></td>
              <td className="py-1.5 text-center">{count(row.employees)}{row.incomplete > 0 && <span className="mr-1.5 text-[11px] font-bold text-amber-700" title="سجلات غير مكتملة مستثناة من الصافي">({row.incomplete} ناقصة)</span>}</td>
              <td className="py-1.5 text-end font-bold" dir="ltr">{formatAmount(row.net_payable)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}

export default function OwnerHome() {
  const { data, loading, error, reload } = useMinistryResource(fetchOwnerHome, [])
  const d = data?.data
  const state = viewState({ loading, error })

  return (
    <>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3" dir="rtl">
        <div>
          <h2 className="text-[22px] font-black text-text-dark">نظرة عامة</h2>
          {d && <p className="text-[11.5px] text-text-light">ورقة الرواتب الحالية · آخر تحديث: {formatDateTime(d.generated_at)}</p>}
        </div>
        <Link to="/owner/payroll" className={OPEN}>فتح كشف الرواتب <FaArrowLeft aria-hidden="true" className="text-[11px]" /></Link>
      </div>

      {state !== 'ready' ? <StatePanel state={state} error={error} message={state === 'forbidden' ? FORBIDDEN : undefined} onRetry={reload} /> : d.employees === 0 ? (
        <p className="rounded-[16px] border border-primary/12 bg-white px-5 py-8 text-center text-[13px] text-text-gray" dir="rtl">لم يُسجَّل أي موظف في ورقة الرواتب بعد. ابدأ من <Link to="/owner/payroll" className="font-bold text-primary-dark underline">كشف الرواتب</Link>: أنشئ هيئة ثم أضف الموظفين.</p>
      ) : (
        <div className="grid gap-5" dir="rtl">
          <section aria-labelledby="home-net" className="rounded-[18px] border border-primary/12 bg-white px-6 py-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
            <p id="home-net" className="text-[12.5px] font-bold text-text-light">إجمالي الصافي المستحق</p>
            <p className="mt-1 text-[34px] font-black leading-tight text-primary-dark max-[480px]:text-[26px]" dir="ltr" data-testid="home-net">{formatSyp(d.totals.net_payable)}</p>
            <p className="mt-1 text-[12.5px] text-text-gray" data-testid="home-counts">
              {count(d.employees)} موظفًا · <b>{count(d.complete)}</b> مكتملًا · {d.incomplete > 0
                ? <Link className="font-bold text-red-700 underline" to="/owner/payroll?completeness=incomplete">{count(d.incomplete)} يحتاج إلى معالجة</Link>
                : <span>لا سجلات ناقصة</span>}
              {d.warnings > 0 && <> · <Link className="font-bold text-amber-700 underline" to="/owner/payroll?completeness=warning">{count(d.warnings)} بها تحذير حسابي</Link></>}
            </p>
            {d.excluded.net_payable > 0 && <p className="mt-1 text-[12px] font-semibold text-amber-800" data-testid="home-excluded">يستثني هذا المجموع {count(d.excluded.net_payable)} سجلًا غير مكتمل أو به خطأ؛ لا يُحسب أي منها صفرًا.</p>}
            <dl className="mt-4 grid grid-cols-5 gap-4 border-t border-primary/10 pt-3 max-[900px]:grid-cols-3 max-[560px]:grid-cols-2" data-testid="home-breakdown">
              {[
                ['إجمالي المستحقات (قبل الاقتطاع)', d.totals.gross_entitlement], ['التأمينات الاجتماعية', d.totals.insurance], ['ضرائب الدخل', d.totals.income_tax],
                ['حسميات أخرى', d.totals.other_deductions], ['الصافي المستحق', d.totals.net_payable],
              ].map(([label, value]) => (
                <div key={label}><dt className="text-[11px] font-bold text-text-light">{label}</dt><dd className="text-[15px] font-black text-text-dark" dir="ltr">{formatAmount(value)}</dd></div>
              ))}
            </dl>
            <p className="mt-1 text-[11px] text-text-light">كل المبالغ بالليرة السورية ({SYMBOL}) وتخص السجلات المكتملة فقط.</p>
          </section>

          <div className="grid grid-cols-2 gap-8 rounded-[18px] border border-primary/12 bg-white px-6 py-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)] max-[900px]:grid-cols-1">
            <Breakdown title="حسب الهيئة" rows={d.by_body} param="body_id" caption="عدد الموظفين والصافي المستحق لكل هيئة" />
            <Breakdown title="حسب مكان العمل" rows={d.by_workplace} param="workplace" caption="عدد الموظفين والصافي المستحق لكل مكان عمل" />
          </div>

          <section aria-labelledby="home-attention" className="rounded-[18px] border border-primary/12 bg-white px-6 py-5 shadow-[0_2px_16px_rgba(26,46,16,0.06)]">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <h3 id="home-attention" className="text-[13px] font-black text-text-dark">ما يحتاج إلى معالجة {d.attention.total > 0 && <span className="mr-1 rounded-full bg-red-50 px-2 py-0.5 text-[11px] text-red-700">{count(d.attention.total)}</span>}</h3>
              {d.attention.total > 0 && <Link className="text-[12px] font-bold text-primary-dark underline" to="/owner/payroll?completeness=incomplete">عرض الكل في كشف الرواتب</Link>}
            </div>
            {d.attention.total === 0 ? <p className="text-[12.5px] text-text-gray" data-testid="attention-empty">كل السجلات مكتملة ولا يوجد خطأ حسابي.</p> : (
              <ul className="divide-y divide-primary/10" data-testid="attention-list">
                {d.attention.items.map(item => (
                  <li key={item.employee_id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-[12.5px]">
                    <span><b className="text-text-dark">{item.employee_number}</b> — {item.full_name}</span>
                    <span className="flex flex-wrap items-center gap-2">
                      <span className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${STATUS[item.status][1]}`}>{STATUS[item.status][0]}</span>
                      <span className="text-text-gray">{item.issues.map(i => i.message).filter(Boolean).join(' · ')}</span>
                    </span>
                  </li>
                ))}
              </ul>
            )}
            {d.attention.total > d.attention.items.length && <p className="mt-2 text-[11.5px] text-text-light">تُعرض أول {d.attention.items.length} من {count(d.attention.total)}.</p>}
          </section>
        </div>
      )}
    </>
  )
}
