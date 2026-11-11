import http from './http'

// Étiquette scannée sans réseau : gardée sur le téléphone, envoyée dès que la connexion revient
const KEY = 'jm.parcel-label-queue'

const read = () => {
  try {
    return JSON.parse(localStorage.getItem(KEY) || '[]')
  } catch {
    return []
  }
}
const write = (list) => {
  try {
    localStorage.setItem(KEY, JSON.stringify(list))
  } catch {
    // stockage indisponible
  }
}

export const isNetworkError = (e) => !e?.response

export function queueLabel(orderId, code) {
  write([...read().filter((x) => !(x.orderId === orderId && x.code === code)), { orderId, code }])
}

export const pendingFor = (orderId) => read().filter((x) => x.orderId === orderId).map((x) => x.code)

/** @returns {Promise<string[]>} messages des étiquettes refusées une fois en ligne */
export async function flushLabels() {
  const refused = []
  const left = []
  for (const item of read()) {
    try {
      await http.post(`/orders/${item.orderId}/parcel-labels`, { code: item.code })
    } catch (e) {
      if (isNetworkError(e)) left.push(item)
      else refused.push(e.response?.data?.message ?? `Étiquette ${item.code} refusée.`)
    }
  }
  write(left)
  return refused
}

/** Code lisible tiré d'un lien scanné ou d'une saisie : JM-7K4Q-29XB */
export function displayCode(input) {
  const m = String(input).match(/\/e\/([A-Za-z0-9]{8})/)
  let raw = (m ? m[1] : String(input)).toUpperCase().replace(/[^A-Z0-9]/g, '')
  if (raw.length === 10 && raw.startsWith('JM')) raw = raw.slice(2)
  return raw.length === 8 ? `JM-${raw.slice(0, 4)}-${raw.slice(4)}` : null
}

/** Code brut (8 caractères) */
export const rawCode = (input) => displayCode(input)?.replace(/^JM-|-/g, '') ?? null
