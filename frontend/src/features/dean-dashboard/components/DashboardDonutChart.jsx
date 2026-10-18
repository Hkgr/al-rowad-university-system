const DEFAULT_COLORS = ['#569933', '#3b82f6', '#f59e0b', '#64748b', '#ef4444', '#8b5cf6']

function toSlices(items = []) {
  return items
    .map((item, index) => ({
      key: String(item.key ?? item.code ?? item.status ?? item.label ?? index),
      label: item.label,
      value: Number(item.value) || 0,
      color: item.color || DEFAULT_COLORS[index % DEFAULT_COLORS.length],
    }))
    .filter(item => item.label)
}

export default function DashboardDonutChart({
  title,
  items = [],
  emptyText,
  loading = false,
}) {
  const slices = toSlices(items)
  const total = slices.reduce((sum, item) => sum + item.value, 0)
  const radius = 42
  const circumference = 2 * Math.PI * radius
  const drawnSlices = total > 0
    ? slices
      .filter(slice => slice.value > 0)
      .map((slice, index, list) => {
        const length = (slice.value / total) * circumference
        const dashOffset = list
          .slice(0, index)
          .reduce((sum, previous) => sum + ((previous.value / total) * circumference), 0)
        return { ...slice, length, dashOffset }
      })
    : []

  return (
    <section
      className="bg-white border border-primary/12 rounded-[18px] p-5 shadow-[0_2px_12px_rgba(26,46,16,0.05)] h-full"
      dir="rtl"
    >
      <h3 className="text-[15px] font-black text-text-dark mb-4">{title}</h3>
      {loading ? (
        <div className="flex items-center gap-5" aria-hidden="true">
          <div className="w-[120px] h-[120px] rounded-full bg-primary/8 animate-pulse" />
          <div className="flex-1 space-y-3">
            <div className="h-4 rounded bg-primary/8 animate-pulse" />
            <div className="h-4 rounded bg-primary/8 animate-pulse" />
            <div className="h-4 rounded bg-primary/8 animate-pulse" />
          </div>
        </div>
      ) : total === 0 ? (
        <p className="text-[13px] text-text-light leading-7">{emptyText}</p>
      ) : (
        <div className="flex flex-col sm:flex-row items-center gap-5">
          <svg
            viewBox="0 0 120 120"
            className="w-[128px] h-[128px] shrink-0"
            role="img"
            aria-label={title}
          >
            <circle cx="60" cy="60" r={radius} fill="none" stroke="#e8f3de" strokeWidth="16" />
            {drawnSlices.map(slice => (
                <circle
                  key={slice.key}
                  cx="60"
                  cy="60"
                  r={radius}
                  fill="none"
                  stroke={slice.color}
                  strokeWidth="16"
                  strokeDasharray={`${slice.length} ${circumference - slice.length}`}
                  strokeDashoffset={-slice.dashOffset}
                  strokeLinecap="butt"
                  transform="rotate(-90 60 60)"
                >
                  <title>{`${slice.label}: ${slice.value}`}</title>
                </circle>
            ))}
            <text
              x="60"
              y="56"
              textAnchor="middle"
              fill="#1a2e10"
              fontSize="18"
              fontWeight="800"
            >
              {total.toLocaleString('ar-SY')}
            </text>
            <text
              x="60"
              y="74"
              textAnchor="middle"
              fill="#64748b"
              fontSize="9"
            >
              الإجمالي
            </text>
          </svg>
          <ul className="flex-1 w-full space-y-2" aria-label={`تفاصيل ${title}`}>
            {slices.map(slice => (
              <li key={slice.key} className="flex items-center justify-between gap-3 text-[12.5px]">
                <span className="flex items-center gap-2 min-w-0">
                  <span
                    className="w-2.5 h-2.5 rounded-full shrink-0"
                    style={{ background: slice.color }}
                    aria-hidden="true"
                  />
                  <span className="font-semibold text-text-dark truncate">{slice.label}</span>
                </span>
                <span className="tabular-nums font-black text-text-dark shrink-0">
                  {slice.value.toLocaleString('ar-SY')}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
