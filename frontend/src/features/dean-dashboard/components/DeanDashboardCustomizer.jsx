import { useEffect } from 'react'
import { FaArrowDown, FaArrowUp, FaTimes } from 'react-icons/fa'
import { KPI_WIDGETS, SECTION_WIDGETS, isWidgetVisible } from '../utils/deanDashboardPreferences'

export default function DeanDashboardCustomizer({
  layout,
  onToggle,
  onMove,
  onReset,
  onClose,
}) {
  useEffect(() => {
    function onKey(event) {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const orderedSections = layout.widgetOrder
    .map(id => SECTION_WIDGETS.find(widget => widget.id === id))
    .filter(Boolean)

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center bg-black/45 p-0 sm:p-4"
      dir="rtl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="dean-dashboard-customizer-title"
      onClick={event => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div className="w-full sm:max-w-[560px] max-h-[96vh] overflow-y-auto bg-white rounded-t-[18px] sm:rounded-[18px] shadow-2xl">
        <div className="flex items-center justify-between border-b border-primary/10 px-5 py-4 sticky top-0 bg-white z-10">
          <h3 id="dean-dashboard-customizer-title" className="text-[16px] font-black text-text-dark">
            تخصيص اللوحة
          </h3>
          <button
            type="button"
            className="p-2 text-text-light hover:text-text-dark"
            onClick={onClose}
            aria-label="إغلاق"
            title="إغلاق"
          >
            <FaTimes aria-hidden="true" />
          </button>
        </div>

        <div className="px-5 py-4 space-y-6">
          <section>
            <h4 className="text-[13px] font-black text-text-dark mb-3">بطاقات المؤشرات</h4>
            <ul className="space-y-2">
              {KPI_WIDGETS.map(widget => (
                <li key={widget.id}>
                  <label className="flex items-center justify-between gap-3 rounded-[12px] border border-primary/10 px-3 py-2.5 cursor-pointer">
                    <span className="text-[13px] font-semibold text-text-dark">{widget.label}</span>
                    <input
                      type="checkbox"
                      checked={isWidgetVisible(layout, widget.id)}
                      onChange={() => onToggle(widget.id)}
                    />
                  </label>
                </li>
              ))}
            </ul>
          </section>

          <section>
            <h4 className="text-[13px] font-black text-text-dark mb-3">ترتيب الأقسام</h4>
            <ul className="space-y-2">
              {orderedSections.map((widget, index) => (
                <li
                  key={widget.id}
                  className="flex items-center justify-between gap-3 rounded-[12px] border border-primary/10 px-3 py-2.5"
                >
                  <label className="flex items-center gap-2 min-w-0 cursor-pointer">
                    <input
                      type="checkbox"
                      checked={isWidgetVisible(layout, widget.id)}
                      onChange={() => onToggle(widget.id)}
                    />
                    <span className="text-[13px] font-semibold text-text-dark truncate">{widget.label}</span>
                  </label>
                  <div className="flex items-center gap-1 shrink-0">
                    <button
                      type="button"
                      className="p-2 rounded-[8px] text-text-light hover:bg-primary/8 hover:text-primary disabled:opacity-30"
                      onClick={() => onMove(widget.id, -1)}
                      disabled={index === 0}
                      aria-label={`تحريك ${widget.label} للأعلى`}
                      title="تحريك للأعلى"
                    >
                      <FaArrowUp className="text-[11px]" aria-hidden="true" />
                    </button>
                    <button
                      type="button"
                      className="p-2 rounded-[8px] text-text-light hover:bg-primary/8 hover:text-primary disabled:opacity-30"
                      onClick={() => onMove(widget.id, 1)}
                      disabled={index === orderedSections.length - 1}
                      aria-label={`تحريك ${widget.label} للأسفل`}
                      title="تحريك للأسفل"
                    >
                      <FaArrowDown className="text-[11px]" aria-hidden="true" />
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        </div>

        <div className="flex items-center justify-between gap-2 px-5 py-4 border-t border-primary/10">
          <button
            type="button"
            className="px-4 py-2 rounded-[10px] text-[13px] font-bold text-red-600 hover:bg-red-50"
            onClick={onReset}
          >
            استعادة الترتيب الافتراضي
          </button>
          <button
            type="button"
            className="px-4 py-2 rounded-[10px] text-[13px] font-bold bg-primary text-white hover:bg-primary-dark"
            onClick={onClose}
          >
            تم
          </button>
        </div>
      </div>
    </div>
  )
}
