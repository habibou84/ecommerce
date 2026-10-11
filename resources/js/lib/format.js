export function slugify(text) {
  return (text || '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40)
    .replace(/-+$/g, '')
}

export function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

export function daysUntil(iso) {
  if (!iso) return null
  return Math.max(0, Math.ceil((new Date(iso) - Date.now()) / 86400000))
}

export function money(amount) {
  if (amount === null || amount === undefined || amount === '') return ''
  return `${new Intl.NumberFormat('fr-FR').format(amount).replace(/ | /g, ' ')} F`
}

export function priceRange(min, max) {
  if (min === null || min === undefined) return ''
  return min === max ? money(min) : `${money(min)} – ${money(max)}`
}

export function formatDateTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/**
 * Toutes les combinaisons des valeurs d'options : [['Rouge','Bleu'],['M','L']] → [['Rouge','M'],['Rouge','L'],…]
 */
export function combinations(lists) {
  return lists.reduce((acc, values) => acc.flatMap((combo) => values.map((v) => [...combo, v])), [[]])
}
