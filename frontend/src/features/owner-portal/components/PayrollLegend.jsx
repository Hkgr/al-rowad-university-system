// Compact key of the grid's column styles. Colour is never the only cue: each item repeats the header marker used in the grid.
const SWATCH = 'inline-block h-3.5 w-3.5 flex-none rounded-[4px] border'

export default function PayrollLegend({ canEdit }) {
  return (
    <ul className="m-0 flex list-none flex-wrap items-center gap-x-4 gap-y-1 p-0 text-[11.5px] text-text-gray" dir="rtl" aria-label="دليل ألوان الأعمدة" data-testid="payroll-legend">
      {canEdit
        ? <li className="flex items-center gap-1.5" data-legend="input"><span className={`${SWATCH} border-amber-400 bg-[#fffaf0] shadow-[inset_0_-2px_0_rgba(217,119,6,0.55)]`} aria-hidden="true" /><b className="font-extrabold text-amber-700" aria-hidden="true">✎</b>قابل للتعديل</li>
        : <li className="flex items-center gap-1.5" data-legend="source"><span className={`${SWATCH} border-stone-300 bg-white`} aria-hidden="true" />قيم مدخلة (للعرض فقط)</li>}
      <li className="flex items-center gap-1.5" data-legend="computed"><span className={`${SWATCH} border-stone-300 bg-[#f4f5f1]`} aria-hidden="true" /><i className="font-serif font-extrabold text-[#4b5d3f]" aria-hidden="true">ƒ</i>محسوب تلقائيًا</li>
      <li className="flex items-center gap-1.5" data-legend="net"><span className={`${SWATCH} border-[#3b7a22] bg-[#e3f0d8] shadow-[inset_0_3px_0_#3b7a22]`} aria-hidden="true" /><b className="font-extrabold text-[#2f6a1c]">الصافي النهائي</b></li>
    </ul>
  )
}
