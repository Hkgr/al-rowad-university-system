import { NODE_KINDS, flowExplanation, flowsToNext } from '../guideModel'

// Each kind has its own border style and a text badge, so meaning never relies on color alone.
const KIND_STYLE = {
  start: 'border-[2px] border-solid border-primary bg-primary/6',
  action: 'border border-solid border-primary/35 bg-white',
  review: 'border-[3px] border-double border-sky-600/60 bg-sky-50/60',
  system: 'border border-dashed border-slate-400 bg-slate-50',
  end: 'border-[2px] border-solid border-primary-dark bg-primary/12',
  return: 'border-[2px] border-dotted border-red-500/70 bg-red-50/60',
}

function DownArrow() {
  return (
    <svg width="18" height="22" viewBox="0 0 18 22" className="mx-auto my-1 text-primary/60" aria-hidden="true">
      <path d="M9 1 V17" stroke="currentColor" strokeWidth="2" fill="none" />
      <path d="M3 13 L9 20 L15 13" stroke="currentColor" strokeWidth="2" fill="none" strokeLinejoin="round" />
    </svg>
  )
}

/** Vertical RTL process diagram followed by a numbered plain-language explanation. */
export default function FlowDiagram({ flow }) {
  const explanation = flowExplanation(flow)
  const summary = `${flow.title}: ${explanation.map(item => `${item.number}. ${flow.nodes[item.number - 1].label}`).join('، ')}`

  return (
    <figure className="mt-4 rounded-[14px] border border-primary/12 bg-[#f8fbf5] p-4 max-[560px]:p-3" dir="rtl">
      <figcaption className="text-[13px] font-black text-text-dark mb-3">رسم توضيحي: {flow.title}</figcaption>

      <ol className="list-none m-0 p-0 max-w-[560px] mx-auto" role="img" aria-label={summary}>
        {flow.nodes.map((node, i) => {
          const item = explanation[i]
          return (
            <li key={node.id}>
              <div className={`rounded-[12px] px-3.5 py-2.5 ${KIND_STYLE[node.kind]}`}>
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="w-6 h-6 rounded-full bg-text-dark text-white text-[11.5px] font-black flex items-center justify-center shrink-0">{item.number}</span>
                  <span className="text-[10.5px] font-bold text-text-gray border border-text-light/50 bg-white rounded-full px-2 py-[1px]">{NODE_KINDS[node.kind]}</span>
                </div>
                <p className="mt-1.5 mb-0 text-[13px] font-bold text-text-dark leading-6">{node.label}</p>
                {item.branches.length > 0 && (
                  <ul className="mt-1.5 mb-0 pr-4 text-[12px] text-text-gray leading-6">
                    {item.branches.map(branch => (
                      <li key={branch.label}>{branch.label} ← {branch.target ? `الخطوة ${branch.target}` : 'نهاية المسار'}</li>
                    ))}
                  </ul>
                )}
                {node.terminal && <p className="mt-1 mb-0 text-[11.5px] text-text-light">نهاية هذا الفرع من المسار.</p>}
              </div>
              {i < flow.nodes.length - 1 && flowsToNext(node) && <DownArrow />}
              {i < flow.nodes.length - 1 && !flowsToNext(node) && <div className="h-3" aria-hidden="true" />}
            </li>
          )
        })}
      </ol>

      <div className="mt-4 border-t border-primary/10 pt-3">
        <p className="text-[12.5px] font-black text-text-dark mb-1.5">شرح الرسم</p>
        <ol className="m-0 pr-5 text-[12.5px] text-text-gray leading-7">
          {explanation.map(item => (
            <li key={item.number}><span className="font-bold text-text-dark">({item.kind})</span> {item.text}</li>
          ))}
        </ol>
      </div>
    </figure>
  )
}
