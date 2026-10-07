import { AMOUNT_ERRORS, parseAmount } from '../lib/payrollMoney'

/**
 * In-cell editor for the three financial columns. It replaces RevoGrid's plain text editor so that an invalid amount is
 * refused where it is typed (the cell keeps editing and is marked invalid) instead of being committed and reverted, and
 * so Enter/Tab can move in the Arabic reading order. `getHooks()` returns the object owned by PayrollGrid:
 *   moveAfterSave  - { row, col } offset to apply once the value has been committed
 *   onInvalid({ title, message }) - report a refused value (the editor stays open on it)
 */
export function createMoneyEditor(getHooks) {
  return class MoneyEditor {
    constructor(column, save, close) {
      this.column = column
      this.save = save
      this.close = close
      this.input = null
      this.element = null
      this.editCell = undefined
    }

    async componentDidRender() {
      if (!this.input) return
      await new Promise(resolve => setTimeout(resolve, 0))
      this.input.focus()
    }

    /** Characters typed between "start editing" and the input being mounted. */
    appendPendingInput(value) {
      if (!this.input) return false
      this.input.value += value
      this.input.focus()
      return true
    }

    getValue() { return this.input?.value }

    beforeDisconnect() { this.input?.blur() }

    mark(message) {
      if (!this.input) return
      this.input.setAttribute('aria-invalid', message ? 'true' : 'false')
      this.input.classList.toggle('pg-input-invalid', Boolean(message))
      this.input.title = message || ''
    }

    onKeyDown(event) {
      if (event.isComposing) return
      const enter = event.key === 'Enter'
      const tab = event.key === 'Tab'
      if (!enter && !tab) return
      // Own the key completely: RevoGrid's document-level handler must not also act on it.
      event.preventDefault()
      event.stopPropagation()
      const parsed = parseAmount(this.input.value)
      if (!parsed.ok) {
        this.mark(AMOUNT_ERRORS[parsed.error])
        getHooks().onInvalid?.({ title: this.column?.column?.name, message: AMOUNT_ERRORS[parsed.error] })
        return
      }
      getHooks().moveAfterSave = { row: enter ? (event.shiftKey ? -1 : 1) : 0, col: tab ? (event.shiftKey ? -1 : 1) : 0 }
      this.beforeDisconnect()
      this.save(this.input.value, true) // true: RevoGrid must not move the selection itself
    }

    render(h) {
      return h('input', {
        type: 'text',
        inputMode: 'decimal',
        enterKeyHint: 'enter',
        autocomplete: 'off',
        dir: 'ltr',
        'aria-label': this.column?.column?.name ? `تعديل ${this.column.column.name}` : 'تعديل المبلغ',
        value: this.editCell?.val ?? '',
        ref: el => { this.input = el },
        onInput: () => this.mark(''),
        onKeyDown: event => this.onKeyDown(event),
      })
    }
  }
}
