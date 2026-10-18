import { useId, useMemo, useRef, useState } from 'react'

const FUNCTIONS = [
  { name: 'SUM', hint: 'SUM(قيمة1; قيمة2 …) مجموع' },
  { name: 'IF', hint: 'IF(شرط; إن تحقق; وإلا)' },
  { name: 'MAX', hint: 'MAX(قيمة1; قيمة2 …) أكبر قيمة' },
  { name: 'MIN', hint: 'MIN(قيمة1; قيمة2 …) أصغر قيمة' },
  { name: 'ROUND', hint: 'ROUND(قيمة; عدد المنازل 0–6)' },
]

/** The token being typed at the caret: inside an open "[" → a name fragment; otherwise a latin word → function fragment. */
function tokenAt(text, caret) {
  const before = text.slice(0, caret)
  const open = before.lastIndexOf('[')
  if (open !== -1 && before.indexOf(']', open) === -1) return { kind: 'name', start: open, query: before.slice(open + 1) }
  const word = /([A-Za-z]{1,10})$/.exec(before)
  if (word) return { kind: 'function', start: caret - word[1].length, query: word[1] }
  return null
}

/**
 * Formula text box with autocomplete. Columns and settings are referenced by their readable name in square brackets
 * (`[الأجر المقطوع]`); the server resolves them to stable ids, so renaming a column never breaks a formula.
 * `references` = [{ key, label, kind: 'column' | 'setting', type }].
 */
export default function FormulaInput({ value, onChange, references, disabled, invalid, describedBy }) {
  const ref = useRef(null)
  const listId = useId()
  const [caret, setCaret] = useState(0)
  const [focused, setFocused] = useState(false)
  const [active, setActive] = useState(0)

  const token = focused ? tokenAt(value, caret) : null
  const suggestions = useMemo(() => {
    if (!token) return []
    const q = token.query.trim().toLowerCase()
    if (token.kind === 'name') return references.filter(item => item.label.toLowerCase().includes(q)).slice(0, 8).map(item => ({ insert: `[${item.label}]`, label: item.label, hint: item.kind === 'setting' ? 'إعداد عام' : item.type === 'text' ? 'عمود نصي' : 'عمود' }))
    return FUNCTIONS.filter(item => item.name.toLowerCase().startsWith(q)).map(item => ({ insert: `${item.name}(`, label: item.name, hint: item.hint }))
  }, [token, references])

  function apply(suggestion) {
    const end = caret
    const next = value.slice(0, token.start) + suggestion.insert + value.slice(end)
    onChange(next)
    const position = token.start + suggestion.insert.length
    setCaret(position)
    requestAnimationFrame(() => { ref.current?.focus(); ref.current?.setSelectionRange(position, position) })
  }

  const open = suggestions.length > 0
  return (
    <div className="relative">
      <textarea
        ref={ref} dir="rtl" rows={3} value={value} disabled={disabled} spellCheck={false}
        aria-label="المعادلة" aria-invalid={invalid ? 'true' : 'false'} aria-describedby={describedBy}
        aria-autocomplete="list" aria-controls={open ? listId : undefined} aria-expanded={open}
        placeholder="مثال: [الأجر المقطوع] * [نسبة التأمينات]"
        className={`w-full rounded-[9px] border px-3 py-2.5 font-mono text-[13px] leading-7 text-text-dark outline-none transition-colors focus:border-primary disabled:bg-black/[0.03] ${invalid ? 'border-red-400' : 'border-primary/25'}`}
        onChange={event => { onChange(event.target.value); setCaret(event.target.selectionStart); setActive(0) }}
        onSelect={event => setCaret(event.target.selectionStart)}
        onClick={event => setCaret(event.target.selectionStart)}
        onFocus={() => setFocused(true)}
        onBlur={() => setTimeout(() => setFocused(false), 120)}
        onKeyDown={event => {
          if (!open) return
          if (event.key === 'ArrowDown') { event.preventDefault(); setActive(i => (i + 1) % suggestions.length) } else if (event.key === 'ArrowUp') { event.preventDefault(); setActive(i => (i - 1 + suggestions.length) % suggestions.length) } else if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); apply(suggestions[active]) } else if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setFocused(false) }
        }}
      />
      {open && (
        <ul id={listId} role="listbox" aria-label="اقتراحات" className="absolute inset-x-0 top-full z-20 mt-1 max-h-52 overflow-y-auto rounded-[10px] border border-primary/20 bg-white py-1 shadow-[0_8px_24px_rgba(26,46,16,0.14)]">
          {suggestions.map((item, index) => (
            <li key={item.insert} role="option" aria-selected={index === active}>
              <button type="button" onMouseDown={event => event.preventDefault()} onClick={() => apply(item)} className={`flex w-full items-center justify-between gap-3 px-3 py-1.5 text-start text-[12.5px] ${index === active ? 'bg-primary/10' : 'hover:bg-primary/[0.05]'}`}>
                <span className="font-bold text-text-dark">{item.label}</span>
                <span className="text-[11px] text-text-light">{item.hint}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
