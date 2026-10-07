// Minimal in-memory host for ReactDOM lifecycle/event tests. It has NO layout,
// rendering, accessibility tree, network, or browser. Never use it for visual QA.
// No test dependency is added to the application's lockfile.
export function installNoLayoutDom() {
  class HostEvent {
    constructor(type, init = {}) { Object.assign(this, { type, bubbles: true, cancelable: true, defaultPrevented: false, button: 0 }, init) }
    preventDefault() { this.defaultPrevented = true }
    stopPropagation() { this.stopped = true }
  }
  class HostNode {
    constructor(type, ownerDocument) { this.nodeType = type; this.ownerDocument = ownerDocument; this.parentNode = null; this.childNodes = []; this.listeners = new Map() }
    get firstChild() { return this.childNodes[0] || null }
    get lastChild() { return this.childNodes.at(-1) || null }
    get nextSibling() { return this.parentNode?.childNodes[this.parentNode.childNodes.indexOf(this) + 1] || null }
    get isConnected() { return this.nodeType === 9 || !!this.parentNode?.isConnected }
    get textContent() { return this.nodeType === 3 ? this.nodeValue : this.childNodes.map(n => n.textContent).join('') }
    set textContent(value) { for (const n of this.childNodes) n.parentNode = null; this.childNodes = []; if (value !== '') this.appendChild(this.ownerDocument.createTextNode(String(value))) }
    appendChild(node) { return this.insertBefore(node, null) }
    insertBefore(node, reference) { node.parentNode?.removeChild(node); const index = reference ? this.childNodes.indexOf(reference) : this.childNodes.length; if (index < 0) throw Error('Invalid reference'); this.childNodes.splice(index, 0, node); node.parentNode = this; return node }
    removeChild(node) { const index = this.childNodes.indexOf(node); if (index < 0) throw Error('Invalid child'); this.childNodes.splice(index, 1); node.parentNode = null; return node }
    contains(node) { return this === node || this.childNodes.some(n => n.contains(node)) }
    getRootNode() { return this.parentNode ? this.parentNode.getRootNode() : this }
    addEventListener(type, listener, capture = false) { const list = this.listeners.get(type) || []; list.push({ listener, capture: capture === true || capture?.capture === true }); this.listeners.set(type, list) }
    removeEventListener(type, listener) { this.listeners.set(type, (this.listeners.get(type) || []).filter(n => n.listener !== listener)) }
    dispatchEvent(event) {
      event.target = this; const path = []; for (let n = this; n; n = n.parentNode) path.push(n)
      const invoke = (nodes, capture) => { for (const n of nodes) { event.currentTarget = n; for (const e of n.listeners.get(event.type) || []) if (e.capture === capture) e.listener.call(n, event); if (event.stopped) break } }
      invoke([...path].reverse(), true); if (!event.stopped) invoke(event.bubbles ? path : [this], false)
      return !event.defaultPrevented
    }
  }
  class HostElement extends HostNode {
    constructor(tag, document, namespace = 'http://www.w3.org/1999/xhtml') {
      super(1, document); this.localName = tag.toLowerCase(); this.tagName = tag.toUpperCase(); this.nodeName = this.tagName; this.namespaceURI = namespace; this.attributes = new Map()
      this.style = { setProperty(name, value) { this[name] = value }, removeProperty(name) { delete this[name] } }; this._value = ''; this.checked = false
    }
    get value() { return this._value }
    set value(value) { this._value = String(value) }
    get options() { return allNodes(this).filter(n => n.localName === 'option') }
    get children() { return this.childNodes.filter(n => n.nodeType === 1) }
    setAttribute(name, value) { this.attributes.set(name, String(value)); if (['disabled', 'open', 'multiple'].includes(name)) this[name] = true; if (name === 'type') this.type = value; if (name === 'value') this.defaultValue = String(value) }
    setAttributeNS(_namespace, name, value) { this.setAttribute(name, value) }
    getAttribute(name) { return this.attributes.get(name) ?? null }
    hasAttribute(name) { return this.attributes.has(name) }
    removeAttribute(name) { this.attributes.delete(name); if (['disabled', 'open', 'multiple'].includes(name)) this[name] = false }
    removeAttributeNS(_namespace, name) { this.removeAttribute(name) }
    focus() { this.ownerDocument.activeElement = this }
    showModal() { this.setAttribute('open', '') }
    close() { this.removeAttribute('open') }
  }
  class HostDocument extends HostNode {
    constructor() {
      super(9, null); this.ownerDocument = this; this.nodeName = '#document'; this.oninput = null
      this.documentElement = this.createElement('html'); this.appendChild(this.documentElement); this.head = this.createElement('head'); this.body = this.createElement('body'); this.documentElement.appendChild(this.head); this.documentElement.appendChild(this.body); this.activeElement = this.body
    }
    createElement(tag) { return new HostElement(tag, this) }
    createElementNS(namespace, tag) { return new HostElement(tag, this, namespace) }
    createTextNode(text) { const n = new HostNode(3, this); n.nodeValue = String(text); n.nodeName = '#text'; return n }
    getElementById(id) { return allNodes(this).find(n => n.getAttribute?.('id') === id) || null }
  }
  const document = new HostDocument(), storage = new Map(), window = new HostNode(0, document)
  Object.assign(window, { document, HTMLElement: HostElement, HTMLIFrameElement: class {}, Event: HostEvent, navigator: { userAgent: 'Node no-layout React host' }, location: new URL('http://127.0.0.1/'), setTimeout, clearTimeout, requestAnimationFrame: fn => setTimeout(() => fn(performance.now()), 0), cancelAnimationFrame: clearTimeout })
  document.defaultView = window
  const localStorage = { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, String(value)), removeItem: key => storage.delete(key), clear: () => storage.clear() }
  for (const [key, value] of Object.entries({ window, document, localStorage, HTMLElement: HostElement, Node: HostNode, Event: HostEvent, IS_REACT_ACT_ENVIRONMENT: true })) globalThis[key] = value
  return { document, window, Event: HostEvent }
}

export function allNodes(root) { return [root, ...root.childNodes.flatMap(allNodes)] }
export function named(root, tag, text) { return allNodes(root).filter(n => n.localName === tag && (n.getAttribute('aria-label') === text || n.textContent.trim() === text)) }
