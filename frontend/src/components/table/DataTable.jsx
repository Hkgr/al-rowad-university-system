import { motion, AnimatePresence } from 'framer-motion'
import { FaSpinner, FaChevronLeft, FaChevronRight } from 'react-icons/fa'

const ALIGN_CLASS = { right: 'text-right', center: 'text-center', left: 'text-left' }

// Generic list table: pass `columns` (what to show) and `rows` (the current page's data).
// columns: [{ key, header, align: 'right'|'center'|'left', dir, cellClassName, render(row, index) }]
// Theming: containerBorderClass/headerBgClass/rowClassName default to the standard green look;
// override them (e.g. for an "archived" table) without touching every other page using DataTable.
export default function DataTable({
  columns,
  rows,
  rowKey,
  loading,
  animationKey,
  emptyIcon: EmptyIcon,
  emptyTitle = 'لا توجد بيانات',
  emptySubtitle,
  hasFilters,
  onClearFilters,
  page,
  totalPages,
  onPageChange,
  containerBorderClass = 'border-primary/12',
  headerBgClass = 'bg-text-dark',
  rowClassName = 'border-b border-primary/7 last:border-b-0 transition-colors duration-150 hover:bg-primary/[0.035]',
  emptyIconClass = 'text-[#d1eab8]',
}) {
  return (
    <>
      <div className={`bg-white rounded-[16px] border ${containerBorderClass} overflow-hidden shadow-[0_2px_16px_rgba(26,46,16,0.06)] min-h-[240px]`}>
        {loading ? (
          <div className="flex flex-col items-center justify-center gap-3.5 py-[60px] text-primary-light text-[14px] font-medium">
            <FaSpinner className="text-[28px] animate-[spin_0.7s_linear_infinite]" />
            <span>جاري التحميل…</span>
          </div>
        ) : rows.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-[60px]">
            {EmptyIcon && <EmptyIcon className={`text-[48px] ${emptyIconClass} mb-2`} />}
            <p className="text-[16px] font-bold text-text-gray" dir="rtl">{emptyTitle}</p>
            {emptySubtitle && <p className="text-[12.5px] text-text-light">{emptySubtitle}</p>}
            {hasFilters && onClearFilters && (
              <button
                className="mt-2.5 px-5 py-2 bg-primary/8 border border-primary/20 rounded-[10px] text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 hover:bg-primary/15"
                onClick={onClearFilters}
                dir="rtl"
              >
                مسح الفلاتر
              </button>
            )}
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full border-collapse">
              <thead>
                <tr>
                  {columns.map(col => (
                    <th
                      key={col.key}
                      className={`px-4 py-3.5 ${ALIGN_CLASS[col.align || 'right']} text-[12px] font-bold text-white/90 ${headerBgClass} whitespace-nowrap`}
                      dir={col.dir}
                    >
                      {col.header}
                    </th>
                  ))}
                </tr>
              </thead>
              <AnimatePresence mode="wait">
                <motion.tbody
                  key={animationKey}
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  exit={{ opacity: 0 }}
                  transition={{ duration: 0.18 }}
                >
                  {rows.map((row, idx) => (
                    <tr key={rowKey(row)} className={rowClassName}>
                      {columns.map(col => (
                        <td
                          key={col.key}
                          className={`px-4 py-[13px] align-middle ${ALIGN_CLASS[col.align || 'right']} ${col.cellClassName || ''}`}
                          dir={col.dir}
                        >
                          {col.render(row, idx)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </motion.tbody>
              </AnimatePresence>
            </table>
          </div>
        )}
      </div>

      {!loading && totalPages > 1 && (
        <div className="flex items-center justify-center gap-4 mt-5 py-1">
          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
            dir="rtl"
          >
            <FaChevronRight />
            <span>السابق</span>
          </button>

          <div className="flex items-center gap-1.5 text-[13px] text-text-gray" dir="rtl">
            <span className="text-[17px] font-extrabold text-primary">{page}</span>
            <span className="text-text-light">من</span>
            <span className="font-semibold text-text-dark">{totalPages}</span>
          </div>

          <button
            className="flex items-center gap-1.5 px-4 py-2 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-primary-dark text-[13px] font-semibold cursor-pointer transition-all duration-200 disabled:opacity-[0.38] disabled:cursor-not-allowed hover:not-disabled:bg-primary/8 hover:not-disabled:border-primary/40"
            disabled={page >= totalPages}
            onClick={() => onPageChange(page + 1)}
            dir="rtl"
          >
            <span>التالي</span>
            <FaChevronLeft />
          </button>
        </div>
      )}
    </>
  )
}
