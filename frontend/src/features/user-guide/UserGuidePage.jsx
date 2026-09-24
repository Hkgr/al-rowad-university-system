import { FaBookOpen, FaBan } from 'react-icons/fa'
import { getIdentity } from '../auth/auth'
import { GUIDES } from './content/index.js'
import { guideTitle, reportPageSuggestions, visibleSections, visibleTroubleshooting, visibleUnavailable } from './guideModel'
import GuideTaskCard from './components/GuideTaskCard'
import { HelpSection, TroubleshootingSection } from './components/GuideSupport'

/** One shared page renders every portal guide from its structured definition. */
export default function UserGuidePage({ guideId }) {
  const user = getIdentity()
  const guide = GUIDES[guideId]
  const title = guideTitle(guide, user)
  const sections = visibleSections(guide, user)
  const unavailable = visibleUnavailable(guide, user)
  const troubleshooting = visibleTroubleshooting(guide, user)

  return (
    <div className="space-y-5" dir="rtl">
      <header className="bg-white border border-primary/12 rounded-[18px] px-6 py-5 max-[560px]:px-4">
        <div className="flex items-center gap-3">
          <span className="w-11 h-11 rounded-[13px] bg-primary/10 border border-primary/20 flex items-center justify-center text-[19px] text-primary shrink-0"><FaBookOpen aria-hidden="true" /></span>
          <div>
            <h1 className="text-[22px] max-[560px]:text-[19px] font-black text-text-dark m-0">دليل الاستخدام — {title}</h1>
            <p className="text-[12px] text-text-light m-0">User guide</p>
          </div>
        </div>
        <p className="mt-3 mb-0 text-[13.5px] text-text-gray leading-7">{guide.intro}</p>
        <p className="mt-2 mb-0 text-[12px] text-text-light">يعرض هذا الدليل المهام التي تسمح بها أدوار حسابك وصلاحياته فقط.</p>
      </header>

      {sections.length === 0 ? (
        <p className="bg-white border border-primary/12 rounded-[16px] p-5 text-[13px] text-text-gray">لا توجد مهام في هذه البوابة ضمن صلاحياتك الحالية.</p>
      ) : sections.map(section => (
        <section key={section.id} aria-labelledby={`section-${section.id}`}>
          <h2 id={`section-${section.id}`} className="text-[16px] font-black text-text-dark mb-3">{section.title}</h2>
          <div className="grid gap-4">
            {section.tasks.map(task => <GuideTaskCard key={task.id} task={task} />)}
          </div>
        </section>
      ))}

      {unavailable.length > 0 && (
        <section className="bg-white border border-dashed border-primary/25 rounded-[18px] p-5" aria-labelledby="guide-unavailable">
          <h2 id="guide-unavailable" className="text-[16px] font-black text-text-dark mb-1 flex items-center gap-2"><FaBan className="text-text-light" aria-hidden="true" /> غير متاح حاليًا</h2>
          <p className="text-[12.5px] text-text-light mb-3">هذه الأجزاء ظاهرة في النظام لكنها غير مكتملة؛ ليست تعليمات تشغيل.</p>
          <ul className="m-0 pr-5 text-[13px] text-text-gray leading-7">
            {unavailable.map(item => <li key={item.id}><span className="font-bold text-text-dark">{item.title}:</span> {item.text}</li>)}
          </ul>
        </section>
      )}

      <TroubleshootingSection items={troubleshooting} />
      <HelpSection portalTitle={title} pageSuggestions={reportPageSuggestions(guideId, user)} />
    </div>
  )
}
