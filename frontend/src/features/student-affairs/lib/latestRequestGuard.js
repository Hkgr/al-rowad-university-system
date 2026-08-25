function sameContext(left, right) {
  if (!left || !right) return false

  const leftKeys = Object.keys(left)
  const rightKeys = Object.keys(right)
  return leftKeys.length === rightKeys.length
    && leftKeys.every(key => left[key] === right[key])
}

export function createLatestRequestGuard() {
  let generation = 0

  return {
    begin(context) {
      generation += 1
      return { generation, context: { ...context } }
    },
    invalidate() {
      generation += 1
    },
    isCurrent(token, currentContext) {
      return token?.generation === generation
        && sameContext(token.context, currentContext)
    },
  }
}

export function bindSelectionToBatch(batchId, group) {
  return { batch_id: batchId, group }
}

export function selectionForBatch(selection, batchId) {
  return selection?.batch_id === batchId ? selection.group : null
}
