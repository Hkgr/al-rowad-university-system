import { Link } from 'react-router-dom'
import { FaArrowLeft } from 'react-icons/fa'
import FlowDiagram from './FlowDiagram'

const linkClass = 'inline-flex items-center gap-2 rounded-[12px] border border-primary/20 bg-white px-3.5 py-2 text-[12.5px] font-bold text-primary-dark no-underline hover:bg-primary/8'

export default function GuideTaskCard({ task }) {
  return (
    <article className="rounded-[16px] border border-primary/15 bg-white p-5 max-[560px]:p-4 shadow-sm" dir="rtl" aria-labelledby={`task-${task.id}`}>
      <div className="flex items-start justify-between gap-3 flex-wrap">
        <h3 id={`task-${task.id}`} className="text-[15px] font-black text-text-dark m-0">{task.title}</h3>
        {task.link && (
          <Link to={task.link.to} className={linkClass}>
            اذهب إلى الصفحة <FaArrowLeft className="text-[10px]" aria-hidden="true" />
          </Link>
        )}
      </div>
      <p className="text-[13px] text-text-light mt-1.5 mb-0 leading-7">{task.summary}</p>

      {task.steps.length > 0 && (
        <ol className="mt-3 mb-0 pr-5 text-[13px] text-text-dark leading-7">
          {task.steps.map(step => (
            <li key={step.text}>
              {step.text}
              {step.link && step.link.to !== task.link?.to && (
                <Link to={step.link.to} className="mr-2 text-[12px] font-bold text-primary-dark underline underline-offset-4">اذهب إلى الصفحة</Link>
              )}
            </li>
          ))}
        </ol>
      )}

      {task.flows.map(flow => <FlowDiagram key={flow.title} flow={flow} />)}
    </article>
  )
}
