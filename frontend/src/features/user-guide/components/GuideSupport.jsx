import { useState } from 'react'
import { FaCopy, FaCheck, FaExclamationTriangle, FaLifeRing, FaUserCheck, FaUserShield } from 'react-icons/fa'
import { CONTACT_NOTICE, HELP_CHANNELS, REPORT_PRIVACY_NOTE, buildReportTemplate } from '../guideModel'

const sectionClass = 'bg-white border border-primary/12 rounded-[18px] p-5 max-[560px]:p-4 shadow-[0_2px_12px_rgba(26,46,16,0.05)]'

export function TroubleshootingSection({ items }) {
  return (
    <section className={sectionClass} dir="rtl" aria-labelledby="guide-troubleshooting">
      <h2 id="guide-troubleshooting" className="text-[16px] font-black text-text-dark mb-1 flex items-center gap-2">
        <FaExclamationTriangle className="text-amber-500" aria-hidden="true" /> عند حدوث مشكلة
      </h2>
      <p className="text-[12.5px] text-text-light mb-4">كل حالة موسومة بمن يعالجها: ما يمكنك تصحيحه بنفسك، وما يتطلب التواصل مع المسؤول.</p>
      <div className="grid grid-cols-2 max-[900px]:grid-cols-1 gap-3">
        {items.map(item => (
          <div key={item.id} className="rounded-[14px] border border-primary/12 bg-[#fbfdf9] p-4">
            <div className="flex items-start justify-between gap-2 flex-wrap mb-1.5">
              <h3 className="text-[13.5px] font-black text-text-dark m-0">{item.title}</h3>
              {item.who === 'user'
                ? <span className="inline-flex items-center gap-1 text-[11px] font-bold text-primary-dark border border-primary/30 rounded-full px-2 py-[1px]"><FaUserCheck aria-hidden="true" /> يمكنك تصحيحه</span>
                : <span className="inline-flex items-center gap-1 text-[11px] font-bold text-amber-800 border border-amber-400/60 rounded-full px-2 py-[1px]"><FaUserShield aria-hidden="true" /> يتطلب التواصل مع المسؤول</span>}
            </div>
            <ul className="m-0 pr-4 text-[12.5px] text-text-gray leading-7">
              {item.steps.map(text => <li key={text}>{text}</li>)}
            </ul>
          </div>
        ))}
      </div>
    </section>
  )
}

export function HelpSection({ portalTitle, pagePath }) {
  const [copied, setCopied] = useState(false)
  const template = buildReportTemplate({ portalTitle, pagePath })

  async function copy() {
    try {
      await navigator.clipboard.writeText(template)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }

  return (
    <section className={sectionClass} dir="rtl" aria-labelledby="guide-help">
      <h2 id="guide-help" className="text-[16px] font-black text-text-dark mb-3 flex items-center gap-2">
        <FaLifeRing className="text-primary" aria-hidden="true" /> التواصل وطلب المساعدة
      </h2>
      <div className="grid grid-cols-3 max-[900px]:grid-cols-1 gap-3 mb-4">
        {HELP_CHANNELS.map(channel => (
          <div key={channel.id} className="rounded-[14px] border border-primary/15 bg-primary/5 p-4">
            <h3 className="text-[13.5px] font-black text-text-dark m-0 mb-1">{channel.title}</h3>
            <p className="text-[12.5px] text-text-gray m-0 leading-6">{channel.body}</p>
          </div>
        ))}
      </div>
      <p className="text-[12.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-[10px] px-4 py-2.5 mb-3">{CONTACT_NOTICE}</p>
      <label htmlFor="guide-report" className="block text-[12.5px] font-black text-text-dark mb-1.5">صيغة بلاغ قابلة للنسخ</label>
      <textarea id="guide-report" readOnly value={template} rows={10} dir="rtl"
        className="w-full rounded-[12px] border border-primary/20 bg-[#fbfdf9] p-3 text-[12.5px] text-text-dark leading-6 font-[inherit] resize-y" />
      <p className="text-[12px] text-red-700 mt-2 mb-3">{REPORT_PRIVACY_NOTE}</p>
      <button type="button" onClick={copy}
        className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white rounded-[10px] text-[13px] font-bold hover:bg-primary-dark transition-colors">
        {copied ? <FaCheck aria-hidden="true" /> : <FaCopy aria-hidden="true" />} {copied ? 'تم النسخ إلى الحافظة' : 'نسخ صيغة البلاغ'}
      </button>
      <span className="sr-only" role="status">{copied ? 'تم نسخ صيغة البلاغ' : ''}</span>
    </section>
  )
}
