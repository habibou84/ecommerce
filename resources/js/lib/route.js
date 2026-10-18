// Tournée du jour du livreur : ordre logique des arrêts et itinéraire.

const today = () => new Date().toLocaleDateString('sv-SE') // AAAA-MM-JJ, heure du téléphone

// Reportée à un autre jour : hors de la tournée d'aujourd'hui
export const isLater = (m) => m.status === 'rescheduled' && m.rescheduled_to && m.rescheduled_to > today()

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
