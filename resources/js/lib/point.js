import http from './http'

/**
 * « Faire le point » : l'argent que les livreurs ont en main et ce que les entreprises de livraison doivent reverser.
 * Mêmes données que les écrans de la Caisse (« Argent chez les livreurs », « Argent chez les entreprises »).
 * Chaque partie n'est chargée que si l'utilisateur y a droit.
 */
export function canPoint(auth) {
  return auth.can('couriers.manage') || auth.can('finance.view') || auth.can('carriers.manage')
}

export async function loadPoint(auth) {
  const [cash, carriers] = await Promise.all([
    auth.can('couriers.manage') || auth.can('finance.view') ? http.get('/cash').then((r) => r.data).catch(() => null) : null,
    auth.can('carriers.manage') ? http.get('/carrier-balances').then((r) => r.data).catch(() => null) : null,
  ])
  const couriers = (cash?.data ?? []).filter((c) => c.held > 0)
    .sort((a, b) => Number(b.over_limit) - Number(a.over_limit) || String(a.oldest_at ?? '9').localeCompare(String(b.oldest_at ?? '9')))
  const settled = (cash?.data ?? []).filter((c) => !c.held)
  const companies = (carriers?.data ?? []).filter((c) => c.count > 0)
  return {
    couriers,
    settled,
    companies,
    declarations: cash?.declarations ?? [],
    total: couriers.reduce((s, c) => s + c.held, 0) + companies.reduce((s, c) => s + Math.max(c.amount, 0), 0),
  }
}
