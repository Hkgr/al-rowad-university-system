// Compact key of the column styles shown in the grid. Colour is never the only cue: each item repeats the header marker used in the grid.
import { legendEntries } from '../lib/payrollView'

const SWATCH = 'inline-block h-3.5 w-3.5 flex-none rounded-[4px] border'

const ITEMS = {
  input: <li key="input" className="flex items-center gap-1.5" data-legend="input"><span className={`${SWATCH} border-amber-400 bg-[#fffaf0] shadow-[inset_0_-2px_0_rgba(217,119,6,0.55)]`} aria-hidden="true" /><b className="font-extrabold text-amber-700" aria-hidden="true">✎</b>قابل للتعديل</li>,
  source: <li key="source" className="flex items-center gap-1.5" data-legend="source"><span className={`${SWATCH} border-stone-300 bg-white`} aria-hidden="true" />قيم مدخلة (للعرض فقط)</li>,
  computed: <li key="computed" className="flex items-center gap-1.5" data-legend="computed"><span className={`${SWATCH} border-stone-300 bg-[#f4f5f1]`} aria-hidden="true" /><i className="font-serif font-extrabold text-[#4b5d3f]" aria-hidden="true">ƒ</i>محسوب تلقائيًا</li>,
  net: <li key="net" className="flex items-center gap-1.5" data-legend="net"><span className={`${SWATCH} border-[#3b7a22] bg-[#e3f0d8] shadow-[inset_0_3px_0_#3b7a22]`} aria-hidden="true" /><b className="font-extrabold text-[#2f6a1c]">الصافي النهائي</b></li>,
}

/** `columns` is the list the grid displays; only the styles present in it are listed. */
export default function PayrollLegend({ columns, canEdit }) {
  const entries = legendEntries(columns, canEdit)
  if (entries.length === 0) return null
  return (
    <ul className="m-0 flex list-none flex-wrap items-center gap-x-4 gap-y-1 p-0 text-[11.5px] text-text-gray" dir="rtl" aria-label="دليل ألوان الأعمدة" data-testid="payroll-legend">
      {entries.map(entry => ITEMS[entry])}
    </ul>
  )
}
