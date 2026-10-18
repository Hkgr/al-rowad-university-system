// Small constructors keeping the guide content files compact and uniform.
export const node = (id, kind, label, explain, branches) => ({ id, kind, label, explain: explain ?? label, ...(branches ? { branches } : {}) })
export const branch = (label, to) => ({ label, to })
export const source = (file, ...mustContain) => ({ file, mustContain })
export const step = (text, extra = {}) => ({ text, ...extra })
