// Exact USD arithmetic on the client: integer cents only, never binary floating point for stored values.
// Mirrors App\Support\PayrollMoney on the server (which stays authoritative).

export const MAX_CENTS = 99_999_999_999 // 999,999,999.99

const ARABIC_DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' }

export const AMOUNT_ERRORS = {
  negative: 'لا يُقبل مبلغ سالب.',
  invalid: 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى منزلتين عشريتين.',
  range: 'القيمة أكبر من الحد المسموح (999,999,999.99).',
}

/**
 * Parse what a user typed or pasted. Returns { ok: true, cents } (cents === null means a blank cell) or
 * { ok: false, error }. Accepts Arabic-Indic digits, the Arabic decimal separator, a leading "$" and
 * Excel-style thousands commas; everything else strict (no exponent, no sign, two decimals at most).
 */
export function parseAmount(input) {
  if (input === null || input === undefined) return { ok: true, cents: null }
  let text = String(input).replace(/[\u00A0\u200B-\u200F\uFEFF]/g, ' ').trim()
  if (text === '') return { ok: true, cents: null }
  text = text.replace(/[٠-٩۰-۹]/g, digit => ARABIC_DIGITS[digit]).replace(/٫/g, '.').replace(/٬/g, ',')
  if (/^-\s*\$?\s*\d/.test(text) || /^\$\s*-/.test(text) || /^\(.*\)$/.test(text)) return { ok: false, error: 'negative' }
  text = text.replace(/^\$\s*/, '')
  if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(text)) text = text.replace(/,/g, '')
  if (!/^\d{1,9}(\.\d{1,2})?$/.test(text)) return { ok: false, error: /^\d+(\.\d+)?$/.test(text) && text.split('.')[0].length > 9 ? 'range' : 'invalid' }
  const [whole, fraction = ''] = text.split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return cents > MAX_CENTS ? { ok: false, error: 'range' } : { ok: true, cents }
}

/** Server string ("1234.50" | null) → cents. */
export function centsFromString(value) {
  if (value === null || value === undefined || value === '') return null
  const [whole, fraction = '0'] = String(value).replace('-', '').split('.')
  const cents = Number(whole) * 100 + Number(fraction.padEnd(2, '0').slice(0, 2))
  return String(value).startsWith('-') ? -cents : cents
}

/** Cents → plain two-decimal string for the API and the editor ("1234.50", "-150.50"); null stays null. */
export function centsToString(cents) {
  if (cents === null || cents === undefined) return null
  const abs = Math.abs(cents)
  return `${cents < 0 ? '-' : ''}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

/** Display: $1,234.50 — negatives are signed and unmistakable: -$150.50. Blank stays ''. */
export function formatMoney(cents) {
  if (cents === null || cents === undefined) return ''
  const abs = Math.abs(cents)
  const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${cents < 0 ? '-' : ''}$${whole}.${String(abs % 100).padStart(2, '0')}`
}

/** Net payable = fixed salary - deduction + compensation. Blank salary → blank; blank deduction/compensation → 0. */
export function payableCents(salary, deduction, compensation) {
  return salary === null || salary === undefined ? null : salary - (deduction ?? 0) + (compensation ?? 0)
}

/** Sum of displayed rows. Payable counts only rows that have a fixed salary. */
export function sumTotals(rows) {
  const total = { employees: rows.length, fixed_salary: 0, deduction: 0, compensation: 0, payable: 0 }
  for (const row of rows) {
    total.fixed_salary += row.fixed_salary ?? 0
    total.deduction += row.deduction ?? 0
    total.compensation += row.compensation ?? 0
    total.payable += payableCents(row.fixed_salary, row.deduction, row.compensation) ?? 0
  }
  return total
}
