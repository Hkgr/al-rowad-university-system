import { useMemo, useState } from 'react'

const WIDTH = 800
const HEIGHT = 340
const PAD = { top: 22, right: 16, bottom: 62, left: 46 }
const Y_MAX = 4
const TERM_COLOR = '#569933'
const CGPA_COLOR = '#417327'

function plotWidth() {
  return WIDTH - PAD.left - PAD.right
}

function plotHeight() {
  return HEIGHT - PAD.top - PAD.bottom
}

function xAt(index, count) {
  if (count <= 1) return PAD.left + plotWidth() / 2
  return PAD.left + (index / (count - 1)) * plotWidth()
}

function yAt(value) {
  const safe = Math.min(Y_MAX, Math.max(0, Number(value)))
  return PAD.top + (1 - safe / Y_MAX) * plotHeight()
}

function formatGpa(value) {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return '—'
  return number.toFixed(2)
}

function shortYearName(name) {
  const match = String(name || '').match(/(\d{2})(\d{2})\s*[-–\/]\s*(\d{2})?(\d{2})/)
  if (!match) return name || ''
  return `${match[2]}/${match[4]}`
}

function shortSemesterName(point) {
  const order = Number(point.semester_order)
  const code = String(point.semester_code || '').toLowerCase()
  const name = String(point.semester_name || '')
  if (order === 1 || code === 'first' || name.includes('الأول')) return 'أول'
  if (order === 2 || code === 'second' || name.includes('الثاني')) return 'ثان'
  if (order === 3 || code === 'summer' || name.includes('صيفي') || name.includes('الصيف')) return 'صيفي'
  return name.slice(0, 6)
}

function polylinePoints(points, key) {
  return points
    .map((point, index) => (point[key] === null || point[key] === undefined ? null : `${xAt(index, points.length)},${yAt(point[key])}`))
    .filter(Boolean)
    .join(' ')
}

function yearGroups(points) {
  const groups = []
  points.forEach((point, index) => {
    const key = String(point.academic_year_id ?? point.year_name ?? index)
    const last = groups[groups.length - 1]
    if (last && last.key === key) {
      last.end = index
      return
    }
    groups.push({
      key,
      yearName: point.year_name,
      start: index,
      end: index,
    })
  })
  return groups
}

export default function GpaTrendChart({
  points = [],
  selectedYearId = '',
  selectedSemesterId = '',
  onSelectPoint,
}) {
  const [activeIndex, setActiveIndex] = useState(null)
  const groups = useMemo(() => yearGroups(points), [points])
  const count = points.length
  const termLine = polylinePoints(points, 'term_gpa')
  const cgpaLine = polylinePoints(points, 'cumulative_gpa')
  const hovered = activeIndex !== null ? points[activeIndex] : null

  function emphasis(point) {
    if (!selectedYearId) return 1
    if (String(point.academic_year_id) !== String(selectedYearId)) return 0.28
    if (selectedSemesterId && String(point.semester_id) !== String(selectedSemesterId)) return 0.5
    return 1
  }

  function isSelected(point) {
    return selectedYearId
      && String(point.academic_year_id) === String(selectedYearId)
      && (!selectedSemesterId || String(point.semester_id) === String(selectedSemesterId))
  }

  if (count === 0) return null

  return (
    <div className="w-full overflow-hidden" dir="ltr">
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className="w-full h-auto max-h-[360px]"
        role="img"
        aria-label="مخطط تطور المعدل الفصلي والتراكمي"
      >
        {[0, 1, 2, 3, 4].map(tick => {
          const y = yAt(tick)
          return (
            <g key={tick}>
              <line x1={PAD.left} x2={WIDTH - PAD.right} y1={y} y2={y} stroke="#569933" strokeOpacity="0.12" />
              <text x={PAD.left - 8} y={y + 4} textAnchor="end" fill="#5a6b4e" fontSize="11" fontFamily="Cairo, sans-serif">
                {tick.toFixed(1)}
              </text>
            </g>
          )
        })}

        {groups.slice(1).map(group => {
          const x = (xAt(group.start, count) + xAt(group.start - 1, count)) / 2
          return (
            <line
              key={`sep-${group.key}`}
              x1={x}
              x2={x}
              y1={PAD.top}
              y2={PAD.top + plotHeight()}
              stroke="#569933"
              strokeOpacity="0.22"
              strokeDasharray="3 5"
            />
          )
        })}

        {count > 1 && cgpaLine ? (
          <polyline
            points={cgpaLine}
            fill="none"
            stroke={CGPA_COLOR}
            strokeWidth="2.5"
            strokeDasharray="7 5"
            strokeLinejoin="round"
            strokeLinecap="round"
          />
        ) : null}

        {count > 1 && termLine ? (
          <polyline
            points={termLine}
            fill="none"
            stroke={TERM_COLOR}
            strokeWidth="2.75"
            strokeLinejoin="round"
            strokeLinecap="round"
          />
        ) : null}

        {points.map((point, index) => {
          const x = xAt(index, count)
          const opacity = emphasis(point)
          const selected = isSelected(point)
          const termY = point.term_gpa === null || point.term_gpa === undefined ? null : yAt(point.term_gpa)
          const cgpaY = point.cumulative_gpa === null || point.cumulative_gpa === undefined ? null : yAt(point.cumulative_gpa)
          const labelY = PAD.top + plotHeight() + 16
          return (
            <g key={`${point.academic_year_id}-${point.semester_id}-${index}`} opacity={opacity}>
              {cgpaY !== null ? (
                <rect
                  x={x - 4.5}
                  y={cgpaY - 4.5}
                  width="9"
                  height="9"
                  fill="#fff"
                  stroke={CGPA_COLOR}
                  strokeWidth={selected ? 2.4 : 1.8}
                  transform={`rotate(45 ${x} ${cgpaY})`}
                />
              ) : null}
              {termY !== null ? (
                <>
                  {selected ? <circle cx={x} cy={termY} r="9" fill="none" stroke={TERM_COLOR} strokeWidth="2" /> : null}
                  <circle cx={x} cy={termY} r={selected ? 5.5 : 4.5} fill={TERM_COLOR} stroke="#fff" strokeWidth="1.5" />
                </>
              ) : null}
              <text
                x={x}
                y={labelY}
                textAnchor="middle"
                fill="#1a2e10"
                fontSize="11"
                fontFamily="Cairo, sans-serif"
                fontWeight="700"
              >
                {shortSemesterName(point)}
              </text>
              <rect
                x={x - 18}
                y={PAD.top}
                width="36"
                height={plotHeight()}
                fill="transparent"
                className="cursor-pointer"
                onMouseEnter={() => setActiveIndex(index)}
                onMouseLeave={() => setActiveIndex(current => (current === index ? null : current))}
                onFocus={() => setActiveIndex(index)}
                onBlur={() => setActiveIndex(current => (current === index ? null : current))}
                onClick={() => onSelectPoint?.(point)}
                tabIndex="0"
                role="button"
                aria-label={`${point.label}، معدل الفصل ${formatGpa(point.term_gpa)}، التراكمي ${formatGpa(point.cumulative_gpa)}`}
              />
            </g>
          )
        })}

        {groups.map(group => {
          const x = (xAt(group.start, count) + xAt(group.end, count)) / 2
          return (
            <text
              key={`year-${group.key}`}
              x={x}
              y={HEIGHT - 14}
              textAnchor="middle"
              fill="#8a9e7a"
              fontSize="11"
              fontFamily="Cairo, sans-serif"
              fontWeight="700"
            >
              {shortYearName(group.yearName)}
            </text>
          )
        })}
      </svg>

      {hovered ? (
        <div
          className="mt-3 rounded-[12px] border border-primary/15 bg-[#f4fbee] px-4 py-3 text-right"
          dir="rtl"
        >
          <p className="text-[12.5px] font-black text-text-dark">{hovered.year_name} · {hovered.semester_name}</p>
          <div className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-[12px] text-text-gray">
            <p>GPA الفصل: <span className="font-black text-text-dark">{formatGpa(hovered.term_gpa)}</span></p>
            <p>CGPA حتى الفصل: <span className="font-black text-text-dark">{formatGpa(hovered.cumulative_gpa)}</span></p>
            <p className="col-span-2">الساعات المحتسبة: <span className="font-black text-text-dark">{hovered.included_credit_hours ?? 0}</span></p>
          </div>
        </div>
      ) : null}
    </div>
  )
}
