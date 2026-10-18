function toItems(items = []) {
  return items
    .map(item => ({
      key: String(item.key ?? item.id ?? item.label),
      label: item.label,
      value: Number(item.value) || 0,
      detail: item.detail,
    }))
    .filter(item => item.label)
}

export default function DashboardBarChart({
  title,
  items = [],
  emptyText,
  loading = false,
  valueSuffix = '',
  maxBars = 8,
}) {
  const rows = toItems(items)
  const visible = rows.slice(0, maxBars)
  const overflow = rows.length - visible.length
  const maxValue = Math.max(...visible.map(item => item.value), 0)

  return (
    <section
      className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)] h-full"
      dir="rtl"
    >
      <h3 className="text-[15px] font-black text-text-dark mb-4">{title}</h3>
      {loading ? (
        <div className="space-y-3" aria-hidden="true">
          {[0, 1, 2, 3].map(index => (
            <div key={index} className="h-8 rounded-lg bg-primary/8 animate-pulse" />
          ))}
        </div>
      ) : visible.length === 0 ? (
        <p className="text-[13px] text-text-light leading-7">{emptyText}</p>
      ) : (
        <ul className="space-y-3 max-h-[320px] overflow-y-auto pr-1" aria-label={title}>
          {visible.map(item => {
            const width = maxValue > 0 ? Math.max((item.value / maxValue) * 100, 6) : 0
            return (
              <li key={item.key}>
                <div className="flex items-center justify-between gap-3 mb-1">
                  <span className="text-[12.5px] font-semibold text-text-dark truncate" title={item.label}>
                    {item.label}
                  </span>
                  <span className="text-[12.5px] font-black text-text-dark tabular-nums shrink-0">
                    {item.value.toLocaleString('ar-SY')}{valueSuffix}
                  </span>
                </div>
                <div className="h-2.5 rounded-full bg-primary/10 overflow-hidden" aria-hidden="true">
                  <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${width}%` }}
                  />
                </div>
                <span className="sr-only">
                  {item.label}: {item.value.toLocaleString('ar-SY')}{valueSuffix}
                </span>
              </li>
            )
          })}
        </ul>
      )}
      {!loading && overflow > 0 ? (
        <p className="mt-3 text-[11.5px] text-text-light">
          يُعرض أعلى {maxBars} برامج. العدد الكامل: {rows.length.toLocaleString('ar-SY')}
        </p>
      ) : null}
    </section>
  )
}
