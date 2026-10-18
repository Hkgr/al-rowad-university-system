import { FaSearch, FaFilter, FaTimes } from 'react-icons/fa'

// Generic search box + row of dropdown filters, used above a DataTable.
// search: { value, onChange, placeholder }
// filters: [{ key, value, onChange, placeholder, options: [{ value, label }], minWidth }]
export default function FilterBar({ search, filters = [], hasActiveFilters, onClear, disabled }) {
  return (
    <div className="flex flex-col gap-3 mb-5">
      {search && (
        <div className="relative">
          <FaSearch className="absolute left-[15px] top-1/2 -translate-y-1/2 text-primary-light text-[14px] pointer-events-none" />
          <input
            className="w-full py-[13px] pr-4 pl-[42px] border-[1.5px] border-primary/20 rounded-[13px] bg-white text-[14px] font-medium text-text-dark outline-none transition-all duration-[220ms] placeholder:text-text-light focus:border-primary focus:shadow-[0_0_0_4px_rgba(86,153,51,0.1)]"
            type="text"
            placeholder={search.placeholder}
            value={search.value}
            onChange={e => search.onChange(e.target.value)}
            dir="rtl"
          />
          {search.value && (
            <button
              type="button"
              className="absolute right-3.5 top-1/2 -translate-y-1/2 bg-transparent border-none text-[18px] text-text-light cursor-pointer leading-none w-6 h-6 flex items-center justify-center rounded-full transition-all duration-200 hover:bg-red-500/8 hover:text-red-500"
              onClick={() => search.onChange('')}
              aria-label="مسح البحث"
              title="مسح البحث"
            >×</button>
          )}
        </div>
      )}

      {(filters.length > 0 || hasActiveFilters) && (
        <div className="flex items-center gap-3 flex-wrap">
          <div className="flex items-center gap-1.5 text-[12.5px] text-text-light font-semibold" dir="rtl">
            <FaFilter className="text-primary-light text-[11px]" />
            <span>تصفية:</span>
          </div>

          {filters.map(f => (
            <select
              key={f.key}
              className="py-2 px-3 border-[1.5px] border-primary/20 rounded-[10px] bg-white text-[13px] text-text-dark outline-none cursor-pointer transition-all duration-200 focus:border-primary"
              style={{ minWidth: f.minWidth ?? 140 }}
              value={f.value}
              onChange={e => f.onChange(e.target.value)}
              dir="rtl"
              disabled={disabled}
            >
              <option value="">{f.placeholder}</option>
              {f.options.map(o => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          ))}

          {hasActiveFilters && (
            <button
              type="button"
              className="flex items-center gap-1.5 py-2 px-3 border-[1.5px] border-red-400/30 rounded-[10px] bg-red-50 text-red-500 text-[12.5px] font-semibold cursor-pointer transition-all duration-200 hover:bg-red-100"
              onClick={onClear}
              dir="rtl"
              aria-label="مسح الفلاتر"
              title="مسح الفلاتر"
            >
              <FaTimes className="text-[10px]" />
              <span>مسح الفلاتر</span>
            </button>
          )}
        </div>
      )}
    </div>
  )
}
