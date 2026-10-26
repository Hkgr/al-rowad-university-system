// Presentation only. Marks, credits, symbols and GPA come from the official API.
export const EXAM_TRANSCRIPT_TEMPLATE = 'exam-board-approved'

const UNITS = ['صفر', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة']
const TEENS = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر']
const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون']

function wholeWords(number) {
  if (number === 100) return 'مائة'
  if (number < 10) return UNITS[number]
  if (number < 20) return TEENS[number - 10]
  return (number % 10 ? UNITS[number % 10] + ' و' : '') + TENS[Math.floor(number / 10)]
}

export function arabicMarkWords(mark) {
  if (mark === null || mark === undefined || mark === '') return ''
  const text = String(mark).trim().replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
  if (!/^\d{1,3}(?:\.\d+)?$/.test(text) || Number(text) > 100) return ''
  const [whole, decimal = ''] = text.split('.')
  const fraction = decimal.replace(/0+$/, '')
  return wholeWords(Number(whole)) + (fraction ? ' فاصلة ' + [...fraction].map(digit => UNITS[Number(digit)]).join(' ') : '')
}

export function displayNumber(value, decimals = null) {
  if (value === null || value === undefined || value === '') return ''
  if (typeof value === 'string' && value.trim() === '') return ''
  const number = Number(value)
  if (!Number.isFinite(number)) return ''
  return decimals === null ? String(number) : number.toFixed(decimals)
}

// Input order is GradeService's chronology. Never infer a historical level or
// year from the student's current level, or from an academic-year label.
export function pairExamTerms(terms) {
  const years = new Map()
  terms.forEach((term, index) => {
    const key = term.academic_year?.academic_year_id ?? 'unknown-' + index
    if (!years.has(key)) years.set(key, [])
    years.get(key).push(term)
  })
  const pairs = []
  for (const year of years.values()) {
    const first = year.find(term => Number(term.semester?.semester_order) === 1)
    const second = year.find(term => Number(term.semester?.semester_order) === 2)
    if (first || second) pairs.push([first ?? null, second ?? null])
    const remaining = year.filter(term => term !== first && term !== second)
    for (let index = 0; index < remaining.length; index += 2) {
      pairs.push([remaining[index], remaining[index + 1] ?? null])
    }
  }
  return pairs
}
