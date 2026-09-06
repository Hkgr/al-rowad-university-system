import { formatMetric, metricValue, stableGroupKey } from '../presentation'

function labelFor(row, dimension, labels) {
  const dimensions = Array.isArray(dimension) ? dimension : [dimension]
  return dimensions.map(current => {
    const raw = row?.[current]
    return labels?.[current]?.[String(raw)] ?? (raw === null || raw === undefined || raw === '' ? 'غير محدد' : String(raw))
  }).join(' — ')
}

function dimensionList(dimension) { return Array.isArray(dimension) ? dimension : [dimension] }

function consecutiveTimeValue(previous, current, dimension) {
  if (previous === undefined) return true
  if (dimension === 'day') return (Date.parse(`${current}T00:00:00Z`) - Date.parse(`${previous}T00:00:00Z`)) === 86400000
  if (dimension === 'month') {
    const [previousYear, previousMonth] = String(previous).split('-').map(Number)
    const [currentYear, currentMonth] = String(current).split('-').map(Number)
    return currentYear * 12 + currentMonth === previousYear * 12 + previousMonth + 1
  }
  if (dimension === 'week') {
    const before = String(previous).match(/^(\d{4})-W?(\d{1,2})$/); const after = String(current).match(/^(\d{4})-W?(\d{1,2})$/)
    if (!before || !after) return false
    return (Number(after[1]) === Number(before[1]) && Number(after[2]) === Number(before[2]) + 1)
      || (Number(after[1]) === Number(before[1]) + 1 && Number(after[2]) === 1 && Number(before[2]) >= 52)
  }
  return false
}

export function HorizontalBarChart({ title, rows, metric, dimension, labels = {} }) {
  const values = rows.map(row => metricValue(metric, row[metric.code])).filter(value => value !== null)
  const maximum = Math.max(0, ...values)
  return (
    <section className="rounded-2xl border border-black/5 bg-white p-5 shadow-sm" aria-labelledby={`chart-${metric.code}`}>
      <h3 id={`chart-${metric.code}`} className="font-black text-text-dark">{title}</h3>
      <div className="mt-4 space-y-3">
        {rows.map((row, index) => {
          const formatted = formatMetric(metric, row[metric.code])
          const width = formatted.value === null || maximum === 0 ? 0 : Math.max(0, Math.min(100, formatted.value / maximum * 100))
          return <div key={stableGroupKey(row, dimensionList(dimension)) || index} tabIndex="0" className="rounded-xl p-2 focus:outline-none focus:ring-2 focus:ring-primary/40">
            <div className="mb-1 flex justify-between gap-3 text-xs"><span className="font-bold">{labelFor(row, dimension, labels)}</span><span>{formatted.text}</span></div>
            <div className="h-3 overflow-hidden rounded-full bg-slate-100" role="img" aria-label={`${labelFor(row, dimension, labels)}: ${formatted.text}`}><div className="h-full rounded-full bg-primary" style={{ width: `${width}%` }} /></div>
            {formatted.note && <p className="mt-1 text-[10px] text-text-light">{formatted.note}</p>}
          </div>
        })}
      </div>
      {rows.length === 0 && <p className="mt-4 text-sm text-text-light">لا توجد بيانات للرسم.</p>}
      <ChartTable rows={rows} metric={metric} dimension={dimension} labels={labels} />
    </section>
  )
}

export function LineChart({ title, rows, metric, dimension, labels = {} }) {
  const width = 720; const height = 260; const padding = 36
  const values = rows.map(row => metricValue(metric, row[metric.code]))
  const numeric = values.filter(value => value !== null)
  const max = Math.max(0, ...numeric); const min = Math.min(0, ...numeric)
  const x = index => rows.length <= 1 ? width / 2 : padding + index * (width - padding * 2) / (rows.length - 1)
  const y = value => max === min ? height / 2 : height - padding - (value - min) * (height - padding * 2) / (max - min)
  const segments = []; let current = []; const timeDimension = dimensionList(dimension)[0]
  values.forEach((value, index) => {
    const timeValue = rows[index]?.[timeDimension]
    if (value === null || (current.length && !consecutiveTimeValue(rows[index - 1]?.[timeDimension], timeValue, timeDimension))) { if (current.length) segments.push(current); current = [] }
    if (value !== null) current.push(`${x(index)},${y(value)}`)
  }); if (current.length) segments.push(current)
  return (
    <section className="rounded-2xl border border-black/5 bg-white p-5 shadow-sm">
      <h3 className="font-black text-text-dark">{title}</h3>
      <div className="mt-4 overflow-x-auto">
        <svg viewBox={`0 0 ${width} ${height}`} className="min-w-[620px]" role="img" aria-label={title}>
          <line x1={padding} y1={height - padding} x2={width - padding} y2={height - padding} stroke="#cbd5e1" />
          {segments.map((points, index) => <polyline key={index} points={points.join(' ')} fill="none" stroke="#417327" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />)}
          {values.map((value, index) => value === null ? null : <circle key={index} cx={x(index)} cy={y(value)} r="5" fill="#569933"><title>{labelFor(rows[index], dimension, labels)}: {formatMetric(metric, rows[index][metric.code]).text}</title></circle>)}
        </svg>
      </div>
      <p className="text-xs text-text-light">تظهر القيم غير المتاحة كفجوات ولا تُحوّل إلى صفر.</p>
      <ChartTable rows={rows} metric={metric} dimension={dimension} labels={labels} />
    </section>
  )
}

export function GroupedComparisonChart({ title, pairs, metric, dimension, labels = {} }) {
  const allValues = pairs.flatMap(pair => [metricValue(metric, pair.primary?.[metric.code]), metricValue(metric, pair.baseline?.[metric.code])]).filter(value => value !== null)
  const maximum = Math.max(0, ...allValues)
  return <section className="rounded-2xl border border-black/5 bg-white p-5 shadow-sm"><h3 className="font-black">{title}</h3><div className="mt-4 space-y-4">{pairs.map(pair => {
    const row = pair.primary ?? pair.baseline; const primary = metricValue(metric, pair.primary?.[metric.code]); const baseline = metricValue(metric, pair.baseline?.[metric.code])
    return <div key={pair.key}><p className="mb-1 text-xs font-bold">{labelFor(row, dimension, labels)}</p><div className="space-y-1"><div className="flex items-center gap-2"><span className="w-16 text-[11px]">الحالي</span><div className="h-3 flex-1 rounded bg-slate-100"><div className="h-full rounded bg-primary" style={{ width: `${primary === null || maximum === 0 ? 0 : primary / maximum * 100}%` }} /></div><span className="w-20 text-left text-xs">{formatMetric(metric, pair.primary?.[metric.code]).text}</span></div><div className="flex items-center gap-2"><span className="w-16 text-[11px]">الأساس</span><div className="h-3 flex-1 rounded bg-slate-100"><div className="h-full rounded bg-amber-500" style={{ width: `${baseline === null || maximum === 0 ? 0 : baseline / maximum * 100}%` }} /></div><span className="w-20 text-left text-xs">{formatMetric(metric, pair.baseline?.[metric.code]).text}</span></div></div></div>
  })}</div><details className="mt-4"><summary className="cursor-pointer text-xs font-bold text-primary">عرض المقارنة كجدول</summary><div className="mt-2 overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-right">الفئة</th><th className="p-2 text-right">الحالي</th><th className="p-2 text-right">خط الأساس</th></tr></thead><tbody>{pairs.map(pair => { const row = pair.primary ?? pair.baseline; return <tr key={pair.key} className="border-b border-black/5"><td className="p-2">{labelFor(row, dimension, labels)}</td><td className="p-2">{formatMetric(metric, pair.primary?.[metric.code]).text}</td><td className="p-2">{formatMetric(metric, pair.baseline?.[metric.code]).text}</td></tr> })}</tbody></table></div></details></section>
}

function ChartTable({ rows, metric, dimension, labels }) {
  return <details className="mt-4"><summary className="cursor-pointer text-xs font-bold text-primary">عرض البيانات كجدول</summary><div className="mt-2 overflow-x-auto"><table className="w-full text-sm"><thead><tr className="border-b"><th className="p-2 text-right">الفئة</th><th className="p-2 text-right">{metric.label}</th></tr></thead><tbody>{rows.map((row, index) => <tr key={stableGroupKey(row, dimensionList(dimension)) || index} className="border-b border-black/5"><td className="p-2">{labelFor(row, dimension, labels)}</td><td className="p-2">{formatMetric(metric, row[metric.code]).text}</td></tr>)}</tbody></table></div></details>
}
