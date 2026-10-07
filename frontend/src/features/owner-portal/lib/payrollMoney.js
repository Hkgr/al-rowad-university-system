// Syrian-pound amounts on the client: exact decimals, "ل.س" display, strict parsing of typed/pasted text.
// All amounts in the payroll sheet are Syrian pounds (SYP). There is no exchange rate and no other currency.
import { makeDec, parseDec, toFixed, toPlain, round, compare, isNegative } from './payrollDecimal.js'

export const SYMBOL = 'ل.س'
export const CURRENCY_CODE = 'SYP'
export const MAX_AMOUNT = parseDec('999999999.99')

const ARABIC_DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' }

export const INPUT_ERRORS = {
  negative: 'لا يُقبل مبلغ سالب في هذا العمود.',
  invalid: 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى منزلتين عشريتين.',
  invalidNumber: 'قيمة غير صالحة؛ أدخل رقمًا بحد أقصى 6 منازل عشرية.',
  invalidPercent: 'نسبة غير صالحة؛ أدخل رقمًا بين 0 و 100 (مثل 7 أو 7%).',
  range: 'القيمة أكبر من الحد المسموح (999,999,999.99).',
  text: 'نص طويل جدًا أو يحتوي على محارف غير مسموحة (الحد 255 حرفًا).',
}

/** Digits, separators and spacing the way spreadsheets and Arabic keyboards produce them. */
export const normalizeNumeric = input => String(input ?? '')
  .replace(/[\u00A0\u200B-\u200F\uFEFF\u202A-\u202E]/g, ' ').trim()
  .replace(/[٠-٩۰-۹]/g, digit => ARABIC_DIGITS[digit]).replace(/٫/g, '.').replace(/٬/g, ',').replace(/،/g, ',')

/**
 * Parse one typed/pasted value for a column. Returns { ok: true, value } where value is a plain decimal string ("1234.50"; a
 * percentage as a FRACTION: "0.07"), a text string, or null for a blank cell — or { ok: false, error }.
 * `column` = { value_type, allow_negative }.
 */
export function parseInput(column, input) {
  if (input === null || input === undefined) return { ok: true, value: null }
  if (column.value_type === 'text') {
    const text = String(input).replace(/\s+/g, ' ').trim()
    if (text === '') return { ok: true, value: null }
    // eslint-disable-next-line no-control-regex
    if (text.length > 255 || /[\u0000-\u001F\u007F]/.test(text)) return { ok: false, error: 'text' }
    return { ok: true, value: text }
  }
  let text = normalizeNumeric(input)
  if (text === '') return { ok: true, value: null }
  const type = column.value_type
  if (type === 'percent') text = text.replace(/\s*%$/, '')
  if (type === 'amount') text = text.replace(new RegExp(`\\s*${SYMBOL.replace('.', '\\.')}$`), '').replace(/\s*SYP$/i, '')
  const parenthesisNegative = /^\(.*\)$/.test(text)
  if (parenthesisNegative) text = `-${text.slice(1, -1).trim()}`
  text = text.replace(/^-\s+/, '-')
  if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(text)) text = text.replace(/,/g, '')
  const decimals = type === 'amount' ? 2 : type === 'percent' ? 4 : 6
  const integerDigits = type === 'percent' ? 3 : 9
  const match = new RegExp(`^(-?)(\\d{1,${integerDigits}})(?:\\.(\\d{1,${decimals}}))?$`).exec(text)
  if (!match) {
    if (/^-/.test(text) && !column.allow_negative && /^-\d/.test(text)) return { ok: false, error: 'negative' }
    if (/^\d+(\.\d+)?$/.test(text) && text.split('.')[0].length > integerDigits) return { ok: false, error: 'range' }
    return { ok: false, error: type === 'percent' ? 'invalidPercent' : type === 'amount' ? 'invalid' : 'invalidNumber' }
  }
  let value = parseDec(text)
  if (isNegative(value) && !column.allow_negative) return { ok: false, error: 'negative' }
  if (type === 'percent') {
    if (compare(value, makeDec(100)) > 0) return { ok: false, error: 'invalidPercent' }
    value = { n: value.n, s: value.s + 2 } // points -> fraction
  }
  return { ok: true, value: type === 'amount' ? toFixed(value, 2) : toPlain(value) }
}

/** Thousands separators on an unsigned digit string. */
const groupDigits = digits => digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',')

/** "1,234.50" (amounts always two decimals; negative signed). Blank/null → ''. */
export function formatAmount(value) {
  if (value === null || value === undefined || value === '') return ''
  const text = toFixed(parseDec(value), 2)
  const negative = text.startsWith('-')
  const [whole, fraction] = text.replace('-', '').split('.')
  return `${negative ? '-' : ''}${groupDigits(whole)}.${fraction}`
}

/** "1,234.50 ل.س" — the symbol follows the number; negatives are signed and unmistakable. */
export const formatSyp = value => (value === null || value === undefined || value === '' ? '' : `${formatAmount(value)} ${SYMBOL}`)

/** Display text of a stored/wire value by column type. */
export function formatValue(type, value) {
  if (value === null || value === undefined || value === '') return ''
  if (type === 'text') return String(value)
  if (type === 'amount') return formatAmount(value)
  const dec = parseDec(value)
  if (type === 'percent') return `${pctText(dec)}%`
  const plain = toPlain(round(dec, 6))
  const negative = plain.startsWith('-')
  const [whole, fraction] = plain.replace('-', '').split('.')
  return `${negative ? '-' : ''}${groupDigits(whole)}${fraction ? `.${fraction}` : ''}`
}

/** Fraction -> percentage points text ("0.07" -> "7", "0.0725" -> "7.25"). */
export function pctText(dec) {
  const points = round({ n: dec.n * 100n, s: dec.s }, 4)
  return toPlain(points)
}

/** Editor text for an existing value (what the user would type): plain digits, percentages as points. */
export function editText(type, value) {
  if (value === null || value === undefined) return ''
  if (type === 'percent') return pctText(parseDec(value))
  return String(value)
}
