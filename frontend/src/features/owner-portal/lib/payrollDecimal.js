// Exact decimal arithmetic for the payroll sheet (BigInt, no binary floating point anywhere).
// Mirrors brick/math on the server: add/subtract/multiply are exact, division keeps DIVISION_SCALE digits (half up), and rounding is
// HALF_UP = half away from zero. A decimal is { n: BigInt, s: number } meaning n / 10^s.

export const DIVISION_SCALE = 10
export const OVERFLOW_LIMIT = 10n ** 15n

const POW = new Map()
const pow10 = exponent => {
  if (!POW.has(exponent)) POW.set(exponent, 10n ** BigInt(exponent))
  return POW.get(exponent)
}

export const makeDec = (n, s = 0) => ({ n: BigInt(n), s })
export const ZERO = makeDec(0)

/** Parse a plain ASCII decimal ("-12.50", "7", "0.07"); throws on anything else. */
export function parseDec(text) {
  const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(text).trim())
  if (!match) throw new Error(`invalid decimal: ${text}`)
  const fraction = match[3] ?? ''
  const n = BigInt(match[2] + fraction) * (match[1] === '-' ? -1n : 1n)
  return { n, s: fraction.length }
}

const align = (a, b) => {
  const s = Math.max(a.s, b.s)
  return [a.n * pow10(s - a.s), b.n * pow10(s - b.s), s]
}

export const plus = (a, b) => { const [x, y, s] = align(a, b); return { n: x + y, s } }
export const minus = (a, b) => { const [x, y, s] = align(a, b); return { n: x - y, s } }
export const times = (a, b) => ({ n: a.n * b.n, s: a.s + b.s })
export const negate = a => ({ n: -a.n, s: a.s })
export const isZero = a => a.n === 0n
export const isNegative = a => a.n < 0n
export const compare = (a, b) => { const [x, y] = align(a, b); return x < y ? -1 : x > y ? 1 : 0 }
export const abs = a => (a.n < 0n ? negate(a) : a)

/** Move the decimal point left by `places` (exact: 15 → 0.15 for places = 2). */
export const movePointLeft = (a, places) => ({ n: a.n, s: a.s + places })

/** Round to `scale` decimals, half away from zero. */
export function round(a, scale) {
  if (a.s <= scale) return { n: a.n * pow10(scale - a.s), s: scale }
  const divisor = pow10(a.s - scale)
  const sign = a.n < 0n ? -1n : 1n
  const magnitude = a.n < 0n ? -a.n : a.n
  let quotient = magnitude / divisor
  if ((magnitude % divisor) * 2n >= divisor) quotient += 1n
  return { n: sign * quotient, s: scale }
}

/** a / b with `scale` decimals (half up). b must not be zero. */
export function divide(a, b, scale = DIVISION_SCALE) {
  // (a.n / 10^a.s) / (b.n / 10^b.s) = (a.n * 10^b.s) / (b.n * 10^a.s); scale the numerator so the quotient has `scale` digits.
  const numerator = a.n * pow10(b.s) * pow10(scale)
  const denominator = b.n * pow10(a.s)
  const sign = (numerator < 0n) !== (denominator < 0n) ? -1n : 1n
  const n = numerator < 0n ? -numerator : numerator
  const d = denominator < 0n ? -denominator : denominator
  let quotient = n / d
  if ((n % d) * 2n >= d) quotient += 1n
  return { n: sign * quotient, s: scale }
}

export const exceedsLimit = a => abs(a).n >= OVERFLOW_LIMIT * pow10(a.s)

/** Fixed decimals ("1234.50"): the value is rounded half up first. */
export function toFixed(a, scale) {
  const r = round(a, scale)
  const negative = r.n < 0n
  const digits = (negative ? -r.n : r.n).toString().padStart(scale + 1, '0')
  const whole = digits.slice(0, digits.length - scale)
  const fraction = digits.slice(digits.length - scale)
  return `${negative ? '-' : ''}${whole}${scale ? `.${fraction}` : ''}`
}

/** Plain text without trailing zeros ("0.07", "1200", "-3.5"). */
export function toPlain(a) {
  const text = toFixed(a, a.s)
  const trimmed = text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text
  return trimmed === '-0' || trimmed === '' ? '0' : trimmed
}

export const toNumber = a => Number(toFixed(a, Math.min(a.s, 8))) // display-only (sorting hints); never used for money maths
