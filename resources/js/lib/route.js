// Tournée du jour du livreur : ordre logique des arrêts et itinéraire.

export const today = () => new Date().toLocaleDateString('sv-SE') // AAAA-MM-JJ, heure du téléphone
// Jour décalé de n jours (AAAA-MM-JJ)
export const addDays = (iso, n) => {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + n)
  return d.toLocaleDateString('sv-SE')
}
export const tomorrow = () => addDays(today(), 1)

// Jour de la course, donné par le serveur (date de livraison ou de report ; sans date : aujourd'hui)
export const dueDay = (m) => m.due_day || today()
// « 14:15 »
export const clock = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
// « jeu. 2 nov. »
export const shortDay = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
// « Aujourd'hui », « Demain », « Hier » ou « jeu. 2 nov. »
export const dayName = (d) => (d === today() ? 'Aujourd\'hui' : d === tomorrow() ? 'Demain' : d === addDays(today(), -1) ? 'Hier' : shortDay(d))

// Onglet de la course pour un jour donné : pickup (à ramasser), deliver (à livrer), return (à retourner), ou aucun.
// Date passée sans livraison : aucun onglet (le marchand choisit une nouvelle date).
export function tabOf(m, day) {
  if (m.status === 'returning') return day === today() ? 'return' : null
  if (m.overdue || dueDay(m) !== day) return null
  if (m.status === 'awaiting_pickup') return 'pickup'
  return ['in_transit', 'out_for_delivery', 'rescheduled'].includes(m.status) ? 'deliver' : null
}

const hasPoint = (m) => m.latitude != null && m.longitude != null

// Distance à vol d'oiseau en km
function km(a, b) {
  const rad = (d) => (d * Math.PI) / 180
  const dLat = rad(b.latitude - a.latitude)
  const dLng = rad(b.longitude - a.longitude)
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2
  return 12742 * Math.asin(Math.sqrt(h))
}

/**
 * Ordre automatique : par commune puis par quartier (les arrêts proches se suivent). Les adresses avec un point GPS
 * sont rangées de proche en proche à partir de la position du livreur, quand on la connaît.
 */
export function autoOrder(missions, here = null) {
  const byArea = [...missions].sort((a, b) => (a.commune ?? '~').localeCompare(b.commune ?? '~', 'fr') || (a.place ?? '').localeCompare(b.place ?? '', 'fr'))
  if (!here) return byArea

  const located = byArea.filter(hasPoint)
  const ordered = []
  let from = here
  while (located.length) {
    let best = 0
    located.forEach((m, i) => { if (km(from, m) < km(from, located[best])) best = i })
    from = located.splice(best, 1)[0]
    ordered.push(from)
  }
  // Sans point GPS : rattachées après le dernier arrêt de leur commune, sinon à la fin
  for (const m of byArea.filter((x) => !hasPoint(x))) {
    const last = ordered.findLastIndex((x) => x.commune && x.commune === m.commune)
    ordered.splice(last === -1 ? ordered.length : last + 1, 0, m)
  }
  return ordered
}

/**
 * Ordre de la tournée : celui choisi par le livreur (sur ce téléphone, sinon enregistré à la boutique), puis
 * l'ordre automatique pour les nouvelles missions.
 */
export function routeOrder(missions, savedIds = null) {
  const auto = autoOrder(missions)
  const rank = (m) => {
    const local = savedIds?.indexOf(m.id) ?? -1
    if (local !== -1) return local
    return savedIds ? Infinity : m.route_position ?? Infinity
  }
  return auto.map((m, i) => [m, i]).sort(([a, i], [b, j]) => rank(a) - rank(b) || i - j).map(([m]) => m)
}

const stopQuery = (m) => (hasPoint(m) ? `${m.latitude},${m.longitude}` : [m.address, m.landmark, m.place, 'Côte d\'Ivoire'].filter(Boolean).join(', '))

/**
 * Itinéraire Google Maps depuis la position du téléphone, arrêts dans l'ordre (dix au plus : limite de Google Maps).
 */
export function itineraryUrl(stops) {
  const list = stops.slice(0, 10).map(stopQuery)
  const params = new URLSearchParams({ api: '1', travelmode: 'driving', destination: list.at(-1) })
  if (list.length > 1) params.set('waypoints', list.slice(0, -1).join('|'))
  return `https://www.google.com/maps/dir/?${params}`
}

/**
 * Position actuelle du téléphone (null si refusée ou indisponible).
 */
export function currentPosition(timeout = 8000) {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null)
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => resolve({ latitude: coords.latitude, longitude: coords.longitude }),
      () => resolve(null),
      { enableHighAccuracy: false, timeout, maximumAge: 120000 },
    )
  })
}
