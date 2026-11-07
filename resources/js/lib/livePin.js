/**
 * Étiquette à épingler : les codes qu'elle montre (articles qui ont encore des pièces ; tous si tout est fini), et ce
 * qui a changé depuis la dernière étiquette partagée.
 */
export function pinCodes(items = []) {
  const all = [...new Set(items.map((i) => i.code))]
  const left = all.filter((code) => items.some((i) => i.code === code && i.remaining > 0))
  return left.length ? left : all
}

export const pinSignature = (items) => pinCodes(items).join(',')

// Dernière étiquette partagée : { sig, at } (ou l'ancienne forme, la liste des codes seule)
export function readPin(stored) {
  if (!stored) return null
  return typeof stored === 'string' ? { sig: stored, at: null } : stored
}

// Changements depuis l'étiquette partagée : articles finis, articles ajoutés
export function pinChanges(items, shared) {
  if (!shared?.sig) return []
  const before = shared.sig.split(',').filter(Boolean)
  const now = pinCodes(items)
  const title = (code) => items.find((i) => i.code === code)?.product ?? items.find((i) => i.code === code)?.title ?? ''
  return [
    ...before.filter((c) => !now.includes(c)).map((c) => `❌ ${c} ${title(c)} est fini`.replace(/\s+/g, ' ')),
    ...now.filter((c) => !before.includes(c)).map((c) => `➕ ${c} ${title(c)} ajouté`.replace(/\s+/g, ' ')),
  ]
}
