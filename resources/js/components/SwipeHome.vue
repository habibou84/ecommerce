<script setup>
import StateBadge from './StateBadge.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'
import CustomerList from '../views/gestion/customers/CustomerList.vue'
import LivePrepare from '../views/gestion/marketing/LivePrepare.vue'
import BoostPanel from './marketing/BoostPanel.vue'
import WhatsAppHub from './WhatsAppHub.vue'
import http from '../lib/http'
import { money } from '../lib/format'
import { tabCount } from '../lib/orders'
import DayProgress from './DayProgress.vue'
import { platformOn } from '../router'
import { boot } from '../lib/boot'
import { useAuthStore } from '../stores/auth'

/**
 * Navigation par glissement (fonction « swipe_nav » de la console) : sur téléphone, l'accueil devient des pages côte
 * à côte : Commandes, WhatsApp, Live, puis le reste. Chaque page garde l'essentiel de son domaine ; ⚙ ouvre ses réglages, les pastilles des
 * onglets signalent ce qui demande de l'attention (rouge qui pulse : urgent, orange : à surveiller). Les chiffres
 * viennent de l'accueil (GET dashboard, déjà chargé) ; les listes ne se chargent qu'à l'arrivée sur leur page.
 */
const props = defineProps({ stats: { type: Object, default: null } })
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

function canOpen(path) {
  const { meta } = router.resolve(path)
  if (meta.owner && !auth.profile?.is_owner) return false
  if (meta.feature && !auth.profile?.[meta.feature]) return false
  if (meta.platform && !platformOn(auth, meta.platform)) return false
  if (meta.permissionAny) return meta.permissionAny.some((p) => auth.can(p))
  return !meta.permission || auth.can(meta.permission)
}
const links = (list) => list.filter((l) => canOpen(l.to))

// ── Alertes de chaque page (mêmes règles que l'accueil) ──
const s = computed(() => props.stats ?? {})
const orderTodo = computed(() => {
  const o = s.value.orders
  if (!o) return []
  return [
    { key: 'proofs', icon: 'cash', count: o.waiting?.proofs ?? 0, text: pl(o.waiting?.proofs, 'paiement à valider', 'paiements à valider'), action: 'Valider', to: '/gestion/commandes?alert=proof', urgent: true },
    { key: 'confirm', icon: 'clock', count: o.to_confirm, text: pl(o.to_confirm, 'commande à confirmer', 'commandes à confirmer'), action: 'Confirmer', to: canOpen('/gestion/confirmation') ? '/gestion/confirmation' : '/gestion/commandes?status=pending_confirmation', urgent: o.callbacks_due > 0 },
  ].filter((t) => t.count > 0)
})
// Livraisons : ce qui attend un livreur, un ramassage, une décision ou un retour
const deliveryTodo = computed(() => {
  const o = s.value.orders
  if (!o) return []
  const late = (s.value.couriers?.late_returns ?? 0) + (s.value.carriers?.late_returns ?? 0)
  return [
    { key: 'unassigned', icon: 'truck', count: o.waiting?.unassigned ?? 0, text: pl(o.waiting?.unassigned, 'commande à faire livrer', 'commandes à faire livrer'), action: 'Faire livrer', to: '/gestion/commandes?alert=unassigned', urgent: true },
    { key: 'not_picked', icon: 'box', count: o.waiting?.not_picked ?? 0, text: pl(o.waiting?.not_picked, 'colis pas encore ramassé', 'colis pas encore ramassés'), action: 'Voir', to: '/gestion/commandes?alert=not_picked', urgent: false },
    { key: 'unhandled', icon: 'alert', count: o.waiting?.unhandled ?? 0, text: pl(o.waiting?.unhandled, 'problème de livraison', 'problèmes de livraison'), action: 'Traiter', to: '/gestion/commandes?alert=unhandled', urgent: true },
    { key: 'returns', icon: 'box', count: late, text: `${late} colis à rapporter en retard`, action: 'Voir', to: '/gestion/commandes?alert=to_bring_back', urgent: true },
  ].filter((t) => t.count > 0)
})
// Raccourcis de la page Livraisons (les onglets du menu Livraisons)
const DELIVERY_LINKS = [
  { to: '/gestion/livraisons/livreurs', label: 'Mes livreurs' },
  { to: '/gestion/livraisons/entreprises', label: 'Entreprises' },
  { to: '/gestion/livraisons/automatisation', label: 'Automatisation' },
  { to: '/gestion/livraisons/paie', label: 'Paie des livreurs' },
  { to: '/gestion/livraisons/tarifs', label: 'Tarifs et zones' },
  { to: '/gestion/livraisons/reglages', label: 'Réglages' },
]
const cashTodo = computed(() => [
  ...(s.value.couriers?.late ?? []).map((x) => ({ key: `c${x.courier_id}`, icon: 'truck', text: `${x.name} garde ${money(x.held)}`, action: 'Encaisser', to: '/gestion/caisse/livreurs', urgent: x.level > 1 })),
  ...(s.value.carriers?.overdue ?? []).map((x) => ({ key: `e${x.connection_id}`, icon: 'cash', text: `${x.carrier} vous doit ${money(x.amount)}`, action: 'Réclamer', to: '/gestion/caisse/a-recevoir', urgent: x.level > 1 })),
])
const badge = (items) => (items.length ? { n: items.length, level: items.some((i) => i.urgent) ? 'red' : 'orange' } : null)

// Données WhatsApp (GET whatsapp/sales), chargées à l'arrivée sur la page WhatsApp
const whatsapp = ref(null)

// ── Pages ──
// « 1 commande », « 3 commandes » (pas de « commande(s) »)
const pl = (n, one, many) => `${n ?? 0} ${n > 1 ? many : one}`
// Icône de couleur de chaque onglet (la même que dans le menu), à la place des émojis
const PAGE_COLORS = { live: 'live', whatsapp: 'whatsapp', boost: 'promo', orders: 'panier', delivery: 'moto', cash: 'caisse', shop: 'boutique', clients: 'clients', items: 'produit', more: 'menu' }
const PAGES = [
  // Ordre sur téléphone : Commandes, WhatsApp, Live, puis le reste
  { key: 'orders', name: 'Commandes', icon: '🛍️', show: () => canOpen('/gestion/commandes'), badge: () => badge(orderTodo.value),
    settings: [{ to: '/gestion/confirmation', label: 'À confirmer' }, { to: '/gestion/etiquettes', label: 'Étiquettes à scanner' }, { to: '/gestion/paniers-abandonnes', label: 'Paniers abandonnés' }, { to: '/gestion/alertes', label: 'Alertes (délais, heures)' }] },
  // WhatsApp : ce qui attend (non lus, récaps, paniers), conversations, assistants des ventes et des réponses
  { key: 'whatsapp', name: 'WhatsApp', icon: '💬', show: () => canOpen('/gestion/whatsapp') || canOpen('/gestion/messages'),
    badge: () => { const n = (s.value.unread_messages ?? 0) + (whatsapp.value?.todo?.recaps ?? 0) + (whatsapp.value?.todo?.carts ?? 0); return n ? { n, level: 'orange' } : null },
    settings: [{ to: '/gestion/whatsapp', label: 'Mon numéro WhatsApp' }, { to: '/gestion/whatsapp/ventes', label: 'Assistant des ventes' }, { to: '/gestion/whatsapp/reponses', label: 'Assistant des réponses' }, { to: '/gestion/textes-messages', label: 'Textes des messages' }, { to: '/gestion/marketing/relances', label: 'Relances' }] },
  { key: 'live', name: 'Live', icon: '🔴', show: () => canOpen('/gestion/live'), badge: () => (s.value.live?.state === 'live' ? { n: '●', level: 'red' } : null),
    settings: [{ to: '/gestion/live/passes', label: 'Lives passés et chiffres' }, { to: '/gestion/materiel', label: 'Matériel du live' }] },
  // Boost : la pub Facebook et Instagram au même endroit (connexion, pixel, produits à booster, commandes venues des boosts)
  { key: 'boost', name: 'Boost', icon: '🚀', show: () => canOpen('/gestion/boost'), badge: () => null,
    settings: [{ to: '/gestion/marketing/suivi', label: 'Pixels et catalogue (réglages avancés)' }, { to: '/gestion/marketing/reseaux', label: 'Réseaux sociaux' }] },
  // Livraisons, puis la caisse
  { key: 'delivery', name: 'Livraisons', icon: '🛵', show: () => DELIVERY_LINKS.some((l) => canOpen(l.to)), badge: () => badge(deliveryTodo.value),
    settings: [{ to: '/gestion/livraisons/automatisation', label: 'Automatisation' }, { to: '/gestion/livraisons/paie', label: 'Paie des livreurs' }, { to: '/gestion/livraisons/tarifs', label: 'Tarifs et zones' }, { to: '/gestion/livraisons/reglages', label: 'Réglages des livraisons' }, { to: '/gestion/alertes', label: 'Alertes (délais, heures)' }] },
  { key: 'cash', name: 'Caisse', icon: '💰', show: () => canOpen('/gestion/caisse'), badge: () => badge(cashTodo.value),
    settings: [{ to: '/gestion/paiements', label: 'Paiement en ligne' }, { to: '/gestion/acompte', label: 'Acompte' }, { to: '/gestion/alertes', label: 'Alertes (délais)' }] },
  { key: 'shop', name: 'Boutique', icon: '🏪', show: () => canOpen('/gestion/boutique-en-ligne'), badge: () => (s.value.shop?.abandoned ? { n: s.value.shop.abandoned, level: 'orange' } : null),
    settings: [{ to: '/gestion/boutique-en-ligne', label: 'Apparence' }, { to: '/gestion/boutique-en-ligne/pages', label: 'Pages et menus' }, { to: '/gestion/boutique-en-ligne/domaine', label: 'Nom de domaine' }] },
  // Clients : chiffres, recherche, relances et liste, dans l'onglet (la fiche s'ouvre par-dessus)
  { key: 'clients', name: 'Clients', icon: '👥', show: () => canOpen('/gestion/clients'), badge: () => (s.value.to_relaunch ? { n: s.value.to_relaunch, level: 'orange' } : null),
    settings: [{ to: '/gestion/marketing/fidelite', label: 'Fidélité et parrainage' }, { to: '/gestion/marketing/avis', label: 'Avis clients' }] },
  { key: 'items', name: 'Articles', icon: '👗', show: () => canOpen('/gestion/catalogue'), badge: () => (s.value.stock && s.value.stock.out + s.value.stock.low ? { n: s.value.stock.out + s.value.stock.low, level: s.value.stock.out ? 'red' : 'orange' } : null),
    settings: [{ to: '/gestion/collections', label: 'Collections' }, { to: '/gestion/packs', label: 'Packs et prix de gros' }, { to: '/gestion/stock', label: 'Stock détaillé et mouvements' }, { to: '/gestion/stock/commandes', label: 'Fournisseurs et bons de commande' }, { to: '/gestion/catalogue/import', label: 'Importer des produits' }] },
  { key: 'more', name: 'Plus', icon: '☰', show: () => true, badge: () => null, settings: [{ to: '/gestion/parametres', label: 'Ma boutique' }, { to: '/gestion/equipe', label: 'Équipe' }] },
]
const pages = computed(() => PAGES.filter((p) => p.show()))

// Page affichée : gardée au retour d'un écran ouvert depuis une page
const KEY = 'jm.swipe-page'
const track = ref(null)
const tabs = ref(null)
// Onglet mémorisé, relu dès le départ : une actualisation affiche tout de suite cet onglet, sans passer par le Live
function savedPage() {
  try {
    return Math.max(0, pages.value.findIndex((p) => p.key === sessionStorage.getItem(KEY)))
  } catch {
    return 0
  }
}
const current = ref(savedPage())
// Pages et barre d'onglets mises en place sans animation avant d'être montrées ; ensuite, glissement doux
let restored = false
const placed = ref(false)
const seen = ref(new Set())
const page = computed(() => pages.value[current.value] ?? pages.value[0])
function go(i, smooth = true) {
  const el = track.value
  if (!el) return
  el.scrollTo({ left: Math.max(0, Math.min(pages.value.length - 1, i)) * el.clientWidth, behavior: smooth ? 'smooth' : 'auto' })
}
function onScroll() {
  const el = track.value
  const i = Math.round(el.scrollLeft / Math.max(1, el.clientWidth))
  if (i === current.value) return
  current.value = i
}
watch(current, (i) => {
  const key = pages.value[i]?.key
  if (!key) return
  seen.value.add(key)
  if (restored) {
    try {
      sessionStorage.setItem(KEY, key)
    } catch {
      // Sans stockage : on revient sur le premier onglet (Commandes)
    }
  }
  // L'onglet de la page affichée vient au milieu de la barre (jamais caché sous la cloche)
  const bar = tabs.value
  const tab = bar?.children[i]
  if (bar && tab) bar.scrollTo({ left: Math.max(0, tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2), behavior: restored ? 'smooth' : 'auto' })
  // Après le démarrage du composant : la page affichée d'emblée (Commandes, première) peut se charger avant que
  // ses données soient déclarées plus bas
  nextTick(() => {
    if (key === 'orders') loadOrders()
    if (key === 'whatsapp') loadWhatsapp()
    if (key === 'items') loadItems()
    if (key === 'cash') loadCash()
  })
}, { immediate: true })
onMounted(async () => {
  const i = current.value
  go(i, false)
  placed.value = true
  // La barre d'onglets arrive après la page (Teleport) : centrée sur l'onglet d'un coup, sans glisser depuis le Live
  await nextTick()
  const bar = tabs.value
  const tab = bar?.children[i]
  if (bar && tab) bar.scrollTo({ left: Math.max(0, tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2), behavior: 'auto' })
  restored = true
  startPage = i
})

// ── Clients : filtre demandé par un lien (ex. « à relancer »), lu une fois à l'arrivée puis à chaque demande ──
const clientsFilter = ref('')
try {
  clientsFilter.value = sessionStorage.getItem('jm.clients-filter') ?? ''
  sessionStorage.removeItem('jm.clients-filter')
} catch {
  clientsFilter.value = ''
}
function onSwipeGo(e) {
  const i = pages.value.findIndex((p) => p.key === e.detail?.page)
  if (e.detail?.page === 'clients') clientsFilter.value = e.detail.filter ?? ''
  if (i >= 0) go(i)
}
onMounted(() => window.addEventListener('jm:swipe-go', onSwipeGo))
onBeforeUnmount(() => window.removeEventListener('jm:swipe-go', onSwipeGo))

// ── Indicateur de glissement : points des pages toujours visibles, et une aide animée tant qu'on n'a jamais glissé ──
const HINT_KEY = 'jm.swipe-hint-done'
function hintDone() {
  try {
    return localStorage.getItem(HINT_KEY) === '1'
  } catch {
    return false
  }
}
const hint = ref(!hintDone())
let startPage = null
function closeHint() {
  hint.value = false
  try {
    localStorage.setItem(HINT_KEY, '1')
  } catch {
    // aide de nouveau à la prochaine visite
  }
}
// Voile d'aide : glisser dessus change de page (et ferme l'aide), le toucher ferme l'aide
let touchX = null
function hintStart(e) {
  touchX = e.touches?.[0]?.clientX ?? null
}
function hintEnd(e) {
  const x = e.changedTouches?.[0]?.clientX
  const dx = touchX === null || x === undefined ? 0 : x - touchX
  touchX = null
  closeHint()
  if (Math.abs(dx) > 40) go(current.value + (dx < 0 ? 1 : -1))
}
const nextName = computed(() => pages.value[current.value + 1]?.name ?? pages.value[current.value - 1]?.name ?? '')
// Changer de page une fois suffit : l'aide ne revient plus
watch(current, (i) => {
  if (startPage !== null && i !== startPage && hint.value) closeHint()
})

// ── Commandes : celles à livrer aujourd'hui, avec l'avancement ; les onglets filtrent sur place ──
const DAY_TABS = [
  { value: '', label: 'Toutes' },
  { value: 'in_progress', label: 'En cours' },
  { value: 'completed', label: 'Livrées' },
  { value: 'failed', label: 'Échec', alert: true },
]
const orders = ref(null)
const orderCounts = ref(null)
const dayProgress = ref(null)
const dayTab = ref('')
// Jour affiché : aujourd'hui par défaut ; « Autre date » ouvre le calendrier
const DAY_PERIODS = [
  { value: 'yesterday', label: 'Hier' },
  { value: 'today', label: 'Aujourd\'hui' },
  { value: 'tomorrow', label: 'Demain' },
  { value: '7d', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'all', label: 'Toutes' },
]
const dayPeriod = ref('today')
const dayDate = ref('')
const DAY_TITLES = { today: 'Commandes du jour', yesterday: 'Commandes d\'hier', tomorrow: 'Commandes de demain', '7d': 'Commandes des 7 jours', month: 'Commandes du mois', all: 'Toutes les commandes' }
const dayTitle = computed(() => (dayPeriod.value === 'custom' && dayDate.value
  ? `Commandes du ${new Date(`${dayDate.value}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}`
  : DAY_TITLES[dayPeriod.value] ?? 'Commandes'))
function pickDate(e) {
  dayDate.value = e.target.value
  if (dayDate.value) dayPeriod.value = 'custom'
}
const ordersMore = ref(0)
async function loadOrders(force = false) {
  if (orders.value && !force) return
  const data = (await http.get('/orders', { params: { period: dayPeriod.value, from: dayPeriod.value === 'custom' ? dayDate.value : undefined, status: dayTab.value || undefined, per_page: 30 } }).catch(() => ({ data: { data: [], counts: null } }))).data
  orders.value = data.data
  orderCounts.value = data.counts ?? orderCounts.value
  dayProgress.value = data.progress ?? dayProgress.value
  ordersMore.value = Math.max(0, (data.meta?.total ?? 0) - data.data.length)
}
watch([dayTab, dayPeriod, dayDate], () => loadOrders(true))
// Retour à l'accueil après une action dans la liste : chiffres à jour
watch(() => route.path, (path) => { if (path === '/gestion' && orders.value) loadOrders(true) })

// ── WhatsApp : ce qui attend et les entrées du menu (réglages WhatsApp : seulement qui gère la boutique) ──
// Rechargées à chaque arrivée sur la page et au retour d'une fenêtre (une commande confirmée entre-temps n'est plus
// « à confirmer »)
async function loadWhatsapp() {
  if (!canOpen('/gestion/whatsapp')) return
  whatsapp.value = (await http.get('/whatsapp/sales').catch(() => ({ data: { data: null } }))).data.data
}

// ── Caisse : état du tiroir et argent dehors, chargés à l'arrivée sur la page (et au retour d'une fenêtre) ──
const drawer = ref(null)
const position = ref(null)
async function loadCash() {
  const [reg, overview] = await Promise.all([
    canOpen('/gestion/caisse/tiroir') ? http.get('/cash-register').catch(() => null) : null,
    auth.can('finance.view') ? http.get('/cash-overview', { params: { period: 'today' } }).catch(() => null) : null,
  ])
  drawer.value = reg?.data.data ?? null
  position.value = overview?.data.data.position ?? null
}
// Fermer une fenêtre de la caisse (tiroir ouvert ou clôturé, livreur encaissé…) : les chiffres de la page se mettent à jour
watch(() => route.path, (to, from) => {
  if (to === '/gestion' && from?.startsWith('/gestion/') && page.value?.key === 'cash') loadCash()
  if (to === '/gestion' && from?.startsWith('/gestion/') && page.value?.key === 'whatsapp') loadWhatsapp()
})
// Où est mon argent : chaque tuile ouvre une seule fenêtre, une seule information
const moneyOut = computed(() => [
  { to: '/gestion/caisse/livreurs', label: 'Chez les livreurs', amount: position.value?.couriers_cash ?? s.value.couriers?.cash, hint: 'espèces à faire verser', show: !!(position.value || s.value.couriers) },
  { to: '/gestion/caisse/a-recevoir', label: 'Chez les entreprises', amount: position.value?.carriers_due ?? s.value.carriers?.amount, hint: position.value?.carriers_due_overdue ? `dont ${money(position.value.carriers_due_overdue)} en retard` : 'livré, pas reversé', late: !!position.value?.carriers_due_overdue, show: !!(position.value || s.value.carriers) },
  { to: '/gestion/caisse/clients', label: 'Chez les clients', amount: position.value?.customers_due, hint: `${pl(position.value?.customers_due_count, 'commande', 'commandes')} pas soldée${position.value?.customers_due_count > 1 ? 's' : ''}`, show: !!position.value },
  { to: '/gestion/livraisons/paie', label: 'À payer aux livreurs', amount: position.value?.couriers_to_pay, hint: 'gains pas encore payés', show: !!position.value },
].filter((t) => t.show && canOpen(t.to)))
const CASH_LINKS = [
  { to: '/gestion/caisse/entrees-sorties', label: 'Entrées et sorties', hint: 'Ce qui est entré et sorti, canal par canal', color: 'billets' },
  { to: '/gestion/caisse/depenses-mensuelles', label: 'Dépenses mensuelles', hint: 'Loyer, salaires… notés chaque mois', color: 'calendrier' },
  { to: '/gestion/statistiques', label: 'Statistiques', hint: 'Bénéfice, ventes, coût par commande', color: 'stats' },
]

// ── Articles : catalogue et stock réunis ──
const items = ref(null)
const itemFilter = ref('')
const itemSearch = ref('')
let timer
async function loadItems(force = false) {
  if (items.value && !force) return
  const params = { per_page: 24, search: itemSearch.value || undefined, stock: itemFilter.value || undefined }
  items.value = (await http.get('/products', { params }).catch(() => ({ data: { data: [] } }))).data.data
}
watch(itemFilter, () => loadItems(true))
function searchItems() {
  clearTimeout(timer)
  timer = setTimeout(() => loadItems(true), 300)
}
// Stock de l'article (liste du catalogue : disponible et variantes en stock bas)
function stockPill(p) {
  if (!p.track_inventory) return { text: 'Sans suivi', cls: 'bg-slate-100 text-slate-500' }
  if (p.available <= 0) return { text: 'Épuisé', cls: 'bg-red-100 text-red-800' }
  return { text: `${p.available} en stock`, cls: p.low_variants ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800' }
}

// ── Boutique ──
const shopUrl = computed(() => auth.store?.url ?? '')
const copied = ref(false)
async function share() {
  if (navigator.share) return navigator.share({ title: auth.store?.name, url: shopUrl.value }).catch(() => {})
  await navigator.clipboard?.writeText(shopUrl.value).catch(() => {})
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

// ── Plus : grandes icônes ──
const PLUS = [
  { to: '/gestion/statistiques', label: 'Statistiques', color: 'stats' },
  { to: '/gestion/marketing', label: 'Promotions', color: 'promo' },
  { to: '/gestion/stock/commandes', label: 'Commandes fournisseurs', color: 'camion' },
  { to: '/gestion/alertes', label: 'Alertes', color: 'cloche' },
  { to: '/gestion/equipe', label: 'Équipe', color: 'profil' },
  { to: '/gestion/mes-boutiques', label: 'Toutes mes boutiques', color: 'boutique' },
  { to: '/gestion/demarrage', label: 'Bien démarrer', color: 'aide' },
  { to: '/gestion/parametres', label: 'Ma boutique', color: 'reglages' },
]
const plus = computed(() => links(PLUS))
// Réglages de chaque page, regroupés dans Plus
const pageSettings = computed(() => pages.value.filter((p) => p.key !== 'more').map((p) => ({ ...p, items: links(p.settings) })).filter((p) => p.items.length))
const helpUrl = computed(() => (boot.supportWhatsapp ? `https://wa.me/${String(boot.supportWhatsapp).replace(/\D/g, '')}?text=${encodeURIComponent(`Bonjour, j'ai besoin d'aide pour mon espace marchand ${auth.store?.name ?? ''}.`)}` : null))
const openMenu = () => window.dispatchEvent(new Event('jm:open-menu'))

// ── Feuilles du bas : ⚙ de la page, et ＋ ──
const sheet = ref(null)
const ADD = [
  { to: '/gestion/commandes/nouvelle', label: '🛍️ Nouvelle commande' },
  { to: '/gestion/caisse/vente', label: '🏪 Vente au comptoir' },
  { to: '/gestion/catalogue/nouveau', label: 'Nouveau produit' },
  { to: '/gestion/live', label: '🔴 Préparer un live' },
]
const sheetLinks = computed(() => (sheet.value === 'add' ? links(ADD) : links(page.value?.settings ?? [])))
function open(to) {
  sheet.value = null
  if (to === '/gestion/live') return go(0)
  router.push(to)
}
</script>

<template>
  <div class="-mx-4 -mt-4 flex h-[calc(100dvh-4rem)] flex-col pt-2 sm:-mx-6 sm:-mt-6">
    <!-- Onglets des pages, tout en haut dans l'en-tête figé (ils passent sous la cloche) -->
    <Teleport defer to="#swipe-tabs">
      <div ref="tabs" class="flex h-full w-full items-center gap-2 overflow-x-auto pl-4 pr-20 [scrollbar-width:none]" role="tablist">
        <button v-for="(p, i) in pages" :key="p.key" type="button" role="tab" :aria-selected="i === current"
                class="relative mt-1 shrink-0 rounded-full px-3 py-1.5 text-sm font-extrabold transition"
                :class="i === current ? (p.badge()?.level === 'red' ? 'bg-red-700 text-white' : 'bg-slate-900 text-white') : (p.badge()?.level === 'red' ? 'bg-red-100 text-red-900' : 'bg-white text-slate-600 shadow-soft')"
                @click="go(i)">
          <span class="inline-flex items-center gap-1.5"><ColorIcon :name="PAGE_COLORS[p.key] ?? 'menu'" :size="18" :tile="false" />{{ p.name }}</span>
          <span v-if="p.badge()" class="absolute -right-1.5 -top-2 min-w-[19px] rounded-full border-2 border-slate-50 px-1 text-center text-pastille font-black leading-[15px] text-white"
                :class="p.badge().level === 'red' ? 'animate-[swipe-pulse_1.6s_ease-out_infinite] bg-red-600' : 'bg-amber-700'">{{ p.badge().n }}</span>
        </button>
      </div>
    </Teleport>

    <!-- Les pages, côte à côte, aimantées -->
    <div ref="track" :class="{ 'swipe-peek': hint, invisible: !placed }" class="flex min-h-0 flex-1 snap-x snap-mandatory overflow-x-auto overflow-y-hidden [scrollbar-width:none]" @scroll.passive="onScroll">
      <section v-for="p in pages" :key="p.key" class="h-full w-full shrink-0 snap-start snap-always overflow-y-auto px-4 pb-28 pt-1" :aria-label="p.name">
        <!-- LIVE : préparer, en direct (« Revenir au live »), lives passés -->
        <!-- Chargée seulement une fois la page Live affichée, et pas en double quand « Préparer un live » est ouvert par-dessus -->
        <template v-if="p.key === 'live'">
          <LivePrepare v-if="seen.has('live') && route.path !== '/gestion/live'" />
        </template>

        <!-- WHATSAPP : ce qui attend, conversations, assistants -->
        <template v-else-if="p.key === 'whatsapp'">
          <WhatsAppHub :data="whatsapp" :unread="s.unread_messages ?? 0" :can-manage="canOpen('/gestion/whatsapp')" :can-messages="canOpen('/gestion/messages')" />
          <RouterLink v-if="canOpen('/gestion/whatsapp')" to="/gestion/whatsapp" class="card mt-3 flex items-center gap-3 px-3.5 py-3">
            <ColorIcon name="whatsapp" :size="36" />
            <span class="min-w-0 flex-1"><b class="block text-base">Mon numéro</b><span class="text-sm text-slate-500">{{ whatsapp?.account ? whatsapp.account.display_phone : 'Connecter mon numéro WhatsApp' }}</span></span>
            <Icon name="chevron" :size="16" class="text-slate-400" />
          </RouterLink>
        </template>

        <!-- COMMANDES (+ livraisons) -->
        <template v-else-if="p.key === 'orders'">
          <div class="mb-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="canOpen('/gestion/commandes/nouvelle')" to="/gestion/commandes/nouvelle" class="btn-brand justify-center whitespace-nowrap px-2 py-3 text-sm font-black">＋ Nouvelle commande</RouterLink>
            <RouterLink v-if="canOpen('/gestion/caisse/vente')" to="/gestion/caisse/vente" class="btn-secondary justify-center whitespace-nowrap px-2 py-3 text-sm font-black">Vente au comptoir</RouterLink>
          </div>
          <!-- Jour : aujourd'hui par défaut ; la carte, les onglets et la liste suivent -->
          <div class="-mx-4 mb-3 overflow-x-auto px-4 [scrollbar-width:none]">
            <div class="inline-flex gap-0.5 rounded-xl bg-slate-200/70 p-1" role="group" aria-label="Jour">
              <button v-for="d in DAY_PERIODS" :key="d.value" type="button" class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-bold"
                      :class="dayPeriod === d.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'" :aria-pressed="dayPeriod === d.value" @click="dayPeriod = d.value">{{ d.label }}</button>
              <label class="relative shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-bold" :class="dayPeriod === 'custom' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600'">
                {{ dayPeriod === 'custom' && dayDate ? new Date(`${dayDate}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : 'Autre date' }}
                <input type="date" class="absolute inset-0 cursor-pointer opacity-0" :value="dayDate" aria-label="Choisir une date" @change="pickDate" />
              </label>
            </div>
          </div>
          <DayProgress :progress="dayProgress" :title="dayTitle" @changed="loadOrders(true)" />
          <div v-if="orderTodo.length" class="mt-3 rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
            <p class="text-xs font-black uppercase tracking-wide text-orange-800">À faire</p>
            <RouterLink v-for="t in orderTodo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-orange-100 py-2 text-sm font-bold first-of-type:border-0">
              <Icon :name="t.icon" :size="16" class="shrink-0 text-orange-700" /><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-orange-700">{{ t.action }} ▸</span>
            </RouterLink>
          </div>
          <!-- Onglets : ils filtrent les commandes du jour juste en dessous -->
          <div class="mt-3 flex border-b border-slate-200" role="tablist" aria-label="Commandes du jour">
            <button v-for="tab in DAY_TABS" :key="tab.value" type="button" role="tab" :aria-selected="dayTab === tab.value"
                    class="-mb-px inline-flex min-h-11 flex-1 items-center justify-center gap-1 whitespace-nowrap border-b-[2.5px] text-sm font-extrabold"
                    :class="dayTab === tab.value ? (tab.alert && orderCounts?.failed ? 'border-red-700 text-red-800' : 'border-emerald-700 text-emerald-800') : tab.alert && orderCounts?.failed ? 'border-transparent text-red-700' : 'border-transparent text-slate-600'"
                    @click="dayTab = tab.value">
              {{ tab.label }}<span v-if="orderCounts" class="rounded-md px-1.5 text-xs font-bold" :class="tab.alert && orderCounts.failed ? 'bg-red-100 text-red-800' : 'bg-slate-100 text-slate-700'">{{ tabCount(orderCounts, tab.value) }}</span>
            </button>
          </div>
          <div class="mt-3 rounded-2xl bg-white p-1 shadow-soft">
            <p v-if="!orders" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
            <p v-else-if="!orders.length" class="p-4 text-center text-sm text-slate-500">{{ dayPeriod === 'today' ? (dayTab ? 'Aucune commande ici aujourd\'hui.' : 'Aucune commande à livrer aujourd\'hui.') : 'Aucune commande pour ce jour.' }}</p>
            <RouterLink v-for="o in orders ?? []" :key="o.id" :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl font-black" :class="o.delivery_failed ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'">{{ (o.customer_name ?? '?').charAt(0).toUpperCase() }}</span>
              <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ o.customer_name ?? o.reference }}</b><span class="block truncate text-xs text-slate-500">{{ money(o.total) }}<template v-if="o.shipping_place_label"> · {{ o.shipping_place_label }}</template><template v-if="o.deliverer"> · {{ o.deliverer }}</template></span></span>
              <StateBadge v-if="o.shipment_status_label && !['completed', 'cancelled', 'returned'].includes(o.status)" kind="shipment" :status="o.shipment_status" :label="o.shipment_status_label" class="shrink-0" />
              <StateBadge v-else :status="o.status" :label="o.status_label" class="shrink-0" />
            </RouterLink>
          </div>
          <RouterLink v-if="canOpen('/gestion/commandes')" :to="{ path: '/gestion/commandes', query: { period: dayPeriod, from: dayPeriod === 'custom' ? dayDate : undefined, status: dayTab || undefined } }" class="mt-2 block py-2 text-center text-sm font-bold text-emerald-700">{{ ordersMore ? `Voir les ${ordersMore} autres dans la liste complète ›` : 'Ouvrir la liste complète ›' }}</RouterLink>
        </template>

        <!-- LIVRAISONS : ce qui attend, colis dehors, livreurs, automatisation, paie -->
        <template v-else-if="p.key === 'delivery'">
          <div v-if="deliveryTodo.length" class="mb-3 rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
            <p class="text-xs font-black uppercase tracking-wide text-orange-800">À faire</p>
            <RouterLink v-for="t in deliveryTodo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-orange-100 py-2 text-sm font-bold first-of-type:border-0">
              <Icon :name="t.icon" :size="16" class="shrink-0 text-orange-700" /><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-orange-700">{{ t.action }} ▸</span>
            </RouterLink>
          </div>
          <div class="grid grid-cols-3 gap-2">
            <RouterLink to="/gestion/commandes?status=to_ship" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-lg font-black">{{ s.orders?.to_ship ?? 0 }}</b><span class="text-xs text-slate-500">à livrer</span></RouterLink>
            <RouterLink to="/gestion/commandes?status=shipped" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-lg font-black">{{ (s.couriers?.on_the_way ?? 0) + (s.carriers?.on_the_way ?? 0) }}</b><span class="text-xs text-slate-500">en route</span></RouterLink>
            <RouterLink to="/gestion/commandes?alert=to_bring_back" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-lg font-black">{{ (s.couriers?.to_bring_back ?? 0) + (s.carriers?.to_bring_back ?? 0) }}</b><span class="text-xs text-slate-500">à rapporter</span></RouterLink>
          </div>
          <RouterLink v-if="canOpen('/gestion/commandes')" to="/gestion/commandes?status=shipped" class="btn-brand mt-3 w-full justify-center py-3 text-base font-black">Voir les commandes en route</RouterLink>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink v-for="l in links(DELIVERY_LINKS)" :key="l.to" :to="l.to" class="flex items-center gap-2 rounded-2xl bg-white px-3 py-3 text-sm font-extrabold shadow-soft">
              <span class="min-w-0 flex-1">{{ l.label }}</span><Icon name="chevron" :size="14" class="shrink-0 text-slate-400" />
            </RouterLink>
          </div>
        </template>

        <!-- CAISSE : le tiroir d'abord, puis où est l'argent ; chaque bouton ouvre une seule fenêtre -->
        <template v-else-if="p.key === 'cash'">
          <div class="mb-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="canOpen('/gestion/caisse/vente')" to="/gestion/caisse/vente" class="btn-brand justify-center py-3 text-base font-black">Vente comptoir</RouterLink>
            <RouterLink v-if="auth.can('cash.manage') && drawer" :to="drawer.session ? '/gestion/caisse/tiroir?faire=depense' : '/gestion/caisse/tiroir'" class="btn-secondary justify-center py-3 text-base font-black">{{ drawer.session ? 'Noter une dépense' : 'Ouvrir la caisse' }}</RouterLink>
          </div>

          <!-- Le tiroir : ouvert ou fermé, espèces attendues -->
          <RouterLink v-if="canOpen('/gestion/caisse/tiroir')" to="/gestion/caisse/tiroir" class="block rounded-[22px] bg-brand-grad p-4 text-white">
            <span class="flex items-center justify-between text-sm font-semibold opacity-90">
              <span>{{ !drawer ? 'Tiroir-caisse' : drawer.session ? 'Caisse ouverte' : 'Caisse fermée' }}</span>
              <Icon name="chevron" :size="16" />
            </span>
            <template v-if="drawer?.session">
              <b class="block text-3xl font-black leading-tight">{{ money(drawer.session.expected_cash) }}</b>
              <span class="text-sm opacity-90">en espèces dans le tiroir · {{ pl(drawer.movements.length, 'mouvement', 'mouvements') }} · clôturer ici</span>
            </template>
            <template v-else>
              <b class="block text-2xl font-black leading-tight">{{ money(s.orders?.today_amount ?? 0) }} vendus aujourd'hui</b>
              <span class="text-sm opacity-90">{{ drawer ? 'Ouvrez-la avec le fond de caisse avant d\'encaisser des espèces' : pl(s.orders?.today_count ?? 0, 'commande', 'commandes') }}</span>
            </template>
          </RouterLink>
          <div v-else class="rounded-[22px] bg-brand-grad p-4 text-white">
            <p class="text-sm font-semibold opacity-90">Ventes du jour</p>
            <p class="text-3xl font-black leading-tight">{{ money(s.orders?.today_amount ?? 0) }}</p>
          </div>

          <div v-if="cashTodo.length" class="mt-3 rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
            <p class="text-xs font-black uppercase tracking-wide text-orange-800">À encaisser</p>
            <RouterLink v-for="t in cashTodo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-orange-100 py-2 text-sm font-bold first-of-type:border-0">
              <Icon :name="t.icon" :size="16" class="shrink-0 text-orange-700" /><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-orange-700">{{ t.action }} ▸</span>
            </RouterLink>
          </div>

          <!-- Où est mon argent -->
          <template v-if="moneyOut.length">
            <h3 class="mb-2 mt-4 px-1 text-base font-black text-slate-700">Où est mon argent</h3>
            <div class="grid grid-cols-2 gap-2">
              <RouterLink v-for="t in moneyOut" :key="t.to" :to="t.to" class="rounded-2xl bg-white p-3 shadow-soft">
                <span class="block text-xs font-semibold text-slate-600">{{ t.label }}</span>
                <b class="block whitespace-nowrap text-lg font-black" :class="t.amount < 0 ? 'text-red-700' : ''">{{ money(t.amount ?? 0) }}</b>
                <span class="block truncate text-xs" :class="t.late ? 'font-bold text-red-700' : 'text-slate-500'">{{ t.hint }}</span>
              </RouterLink>
            </div>
          </template>

          <!-- Suivre son argent -->
          <div class="mt-3 rounded-2xl bg-white p-1 shadow-soft">
            <RouterLink v-for="l in links(CASH_LINKS)" :key="l.to" :to="l.to" class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0">
              <ColorIcon :name="l.color" :size="36" />
              <span class="min-w-0 flex-1"><b class="block text-sm">{{ l.label }}</b><span class="text-xs text-slate-500">{{ l.hint }}</span></span>
              <span class="text-xl text-slate-400" aria-hidden="true">›</span>
            </RouterLink>
          </div>
        </template>

        <!-- BOUTIQUE EN LIGNE -->
        <template v-else-if="p.key === 'shop'">
          <div class="rounded-[22px] bg-brand-grad p-4 text-white">
            <p class="text-sm font-semibold opacity-90">Votre boutique en ligne</p>
            <p class="truncate text-lg font-black">{{ shopUrl.replace(/^https?:\/\//, '') }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
              <button type="button" class="rounded-xl bg-white py-2.5 text-sm font-black text-emerald-800" @click="share">{{ copied ? 'Lien copié ✓' : 'Partager' }}</button>
              <a :href="shopUrl" target="_blank" rel="noopener" class="rounded-xl bg-white/20 py-2.5 text-center text-sm font-black">Voir ▸</a>
            </div>
          </div>
          <div class="mt-3 rounded-2xl bg-white p-1 shadow-soft">
            <RouterLink v-for="l in links([
              { to: '/gestion/paniers-abandonnes', label: 'Paniers abandonnés', hint: s.shop?.abandoned ? `${s.shop.abandoned} à relancer` : 'Aucun', color: 'panier' },
              { to: '/gestion/marketing', label: 'Promotions', hint: 'Codes promo, ventes flash', color: 'promo' },
              { to: '/gestion/boutique-en-ligne', label: 'Apparence', hint: 'Couleurs, logo, bannière', color: 'boutique' },
              { to: '/gestion/marketing/reseaux', label: 'Réseaux', hint: 'Publier un produit', color: 'partage' },
            ])" :key="l.to" :to="l.to" class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0">
              <ColorIcon :name="l.color" :size="36" />
              <span class="min-w-0 flex-1"><b class="block text-sm">{{ l.label }}</b><span class="text-xs text-slate-500">{{ l.hint }}</span></span>
              <span class="text-xl text-slate-400" aria-hidden="true">›</span>
            </RouterLink>
          </div>
        </template>

        <!-- BOOST : la pub Facebook et Instagram -->
        <!-- Chargée seulement une fois la page Boost affichée -->
        <template v-else-if="p.key === 'boost'">
          <BoostPanel v-if="seen.has('boost')" />
        </template>

        <!-- CLIENTS : chargés seulement une fois l'onglet affiché, et pas en double quand la page Clients est ouverte par-dessus -->
        <template v-else-if="p.key === 'clients'">
          <CustomerList v-if="seen.has('clients') && route.path !== '/gestion/clients'" :filter="clientsFilter" />
        </template>

        <!-- ARTICLES : catalogue et stock réunis -->
        <template v-else-if="p.key === 'items'">
          <div class="mb-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="canOpen('/gestion/catalogue/nouveau')" to="/gestion/catalogue/nouveau" class="btn-brand justify-center py-3 text-base font-black">＋ Nouveau produit</RouterLink>
            <RouterLink v-if="canOpen('/gestion/stock')" to="/gestion/stock" class="btn-secondary justify-center py-3 text-base font-black">＋ Stock</RouterLink>
          </div>
          <input v-model="itemSearch" class="input" placeholder="🔍 Chercher un article" @input="searchItems" />
          <div class="-mx-4 mt-2 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
            <button v-for="[v, label] in [['', 'Tout'], ['low', `⚠ Stock bas ${s.stock?.low ?? ''}`], ['out', `Épuisés ${s.stock?.out ?? ''}`]]" :key="v" type="button"
                    class="shrink-0 rounded-full px-3 py-1.5 text-sm font-extrabold" :class="itemFilter === v ? 'bg-slate-900 text-white' : (v ? 'bg-red-50 text-red-800' : 'bg-white shadow-soft')" @click="itemFilter = v">{{ label }}</button>
          </div>
          <p v-if="!items" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
          <p v-else-if="!items.length" class="p-4 text-center text-sm text-slate-500">Aucun article.</p>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink v-for="a in items ?? []" :key="a.id" :to="`/gestion/catalogue/${a.id}`" class="overflow-hidden rounded-2xl bg-white shadow-soft">
              <img v-if="a.thumb" :src="a.thumb" alt="" class="aspect-square w-full object-cover" loading="lazy" />
              <span v-else class="grid aspect-square w-full place-items-center bg-slate-100 text-3xl" aria-hidden="true">📷</span>
              <span class="block p-2"><b class="block truncate text-sm">{{ a.title }}</b><span class="text-xs text-slate-500">{{ money(a.price_min ?? 0) }}</span>
                <span class="mt-1 block w-fit rounded-full px-2 py-0.5 text-xs font-extrabold" :class="stockPill(a).cls">{{ stockPill(a).text }}</span></span>
            </RouterLink>
          </div>
        </template>

        <!-- PLUS : grandes icônes, aide, réglages de chaque page, compte -->
        <template v-else>
          <div class="grid grid-cols-3 gap-2.5">
            <a v-if="helpUrl" :href="helpUrl" target="_blank" rel="noopener" class="flex flex-col items-center gap-1.5 rounded-[18px] bg-white px-1 py-3 text-center shadow-soft transition active:scale-95">
              <ColorIcon name="aide" :size="44" /><span class="text-xs font-semibold leading-tight text-slate-700">Aide</span>
            </a>
            <RouterLink v-for="t in plus" :key="t.to + t.label" :to="t.to" class="flex flex-col items-center gap-1.5 rounded-[18px] bg-white px-1 py-3 text-center shadow-soft transition active:scale-95">
              <ColorIcon :name="t.color" :size="44" />
              <span class="text-xs font-semibold leading-tight text-slate-700">{{ t.label }}</span>
              <span v-if="t.to === '/gestion/messages' && s.unread_messages" class="-mt-1 rounded-full bg-red-600 px-1.5 text-xs font-black text-white">{{ s.unread_messages }} non lu{{ s.unread_messages > 1 ? 's' : '' }}</span>
            </RouterLink>
          </div>
          <h3 class="mb-2 mt-5 px-1 text-base font-black text-slate-700">Réglages</h3>
          <details v-for="g in pageSettings" :key="g.key" class="mb-2 rounded-2xl bg-white shadow-soft">
            <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 text-base font-extrabold"><ColorIcon :name="PAGE_COLORS[g.key] ?? 'menu'" :size="22" :tile="false" />{{ g.name }}<span class="ml-auto text-slate-400" aria-hidden="true">›</span></summary>
            <RouterLink v-for="l in g.items" :key="l.to + l.label" :to="l.to" class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm font-semibold">{{ l.label }}<span class="text-lg text-slate-400" aria-hidden="true">›</span></RouterLink>
          </details>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink to="/gestion/compte" class="rounded-2xl bg-white py-3 text-center text-sm font-bold shadow-soft">👤 Mon compte</RouterLink>
            <button type="button" class="rounded-2xl bg-white py-3 text-center text-sm font-bold shadow-soft" @click="openMenu">☰ Tout le menu</button>
          </div>
        </template>
      </section>
    </div>

    <!-- Points des pages : où l'on est, et qu'il y en a d'autres de chaque côté -->
    <div class="pointer-events-none fixed inset-x-0 bottom-[calc(env(safe-area-inset-bottom)+1.9rem)] z-20 flex justify-center">
      <div class="pointer-events-auto flex items-center gap-1.5 rounded-full bg-slate-900/80 px-2.5 py-2 shadow-lg backdrop-blur" role="tablist" aria-label="Pages">
        <button v-for="(p, i) in pages" :key="p.key" type="button" role="tab" :aria-selected="i === current" :aria-label="p.name"
                class="h-2 rounded-full transition-all" :class="i === current ? 'w-5 bg-white' : 'w-2 bg-white/45'" @click="go(i)" />
      </div>
    </div>

    <!-- Aide de la première fois : voile, main qui glisse et page qui suit ; disparaît au premier glissement -->
    <div v-if="hint" class="fixed inset-0 z-40 flex flex-col items-center justify-center gap-4 bg-slate-900/55 px-6 text-center text-white" role="dialog" aria-label="Glissez pour changer de page"
         @touchstart.passive="hintStart" @touchend="hintEnd" @click="closeHint">
      <div class="relative h-24 w-60" aria-hidden="true">
        <span class="swipe-trail absolute right-10 top-9 h-2.5 rounded-full bg-gradient-to-r from-white/0 to-white/80" />
        <span class="swipe-hand absolute right-2 top-2 text-6xl drop-shadow-lg">👆</span>
      </div>
      <p class="text-2xl font-black leading-tight">Glissez pour passer<br>d'une page à l'autre</p>
      <p v-if="nextName" class="text-base font-extrabold opacity-90">‹ {{ page?.name }} · {{ nextName }} ›</p>
      <p class="text-sm opacity-80">Touchez pour fermer</p>
    </div>

    <!-- ＋ partout -->
    <button type="button" class="fixed bottom-[calc(env(safe-area-inset-bottom)+1rem)] right-4 z-20 grid h-14 w-14 place-items-center rounded-[20px] bg-emerald-700 text-white shadow-lg shadow-emerald-900/30" aria-label="Ajouter" @click="sheet = 'add'">
      <Icon name="plus" :size="28" />
    </button>

    <!-- Feuille du bas : réglages de la page ou ＋ -->
    <div v-if="sheet" class="fixed inset-0 z-30 bg-slate-900/40" @click="sheet = null" />
    <div v-if="sheet" class="fixed inset-x-0 bottom-0 z-40 rounded-t-[28px] bg-white p-4 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]" role="dialog" :aria-label="sheet === 'add' ? 'Ajouter' : `Réglages : ${page?.name}`">
      <div class="mx-auto mb-3 h-1.5 w-10 rounded-full bg-slate-200" />
      <p class="mb-2 px-1 text-lg font-extrabold">{{ sheet === 'add' ? 'Ajouter' : `⚙ ${page?.name}` }}</p>
      <button v-for="l in sheetLinks" :key="l.to + l.label" type="button" class="flex w-full items-center justify-between border-b border-slate-100 px-1 py-3.5 text-left text-base font-bold last:border-0" @click="open(l.to)">
        {{ l.label }}<span class="text-xl text-slate-400" aria-hidden="true">›</span>
      </button>
    </div>
  </div>
</template>

<style>
@keyframes swipe-hand {
  0%, 10% { transform: translateX(0) scale(1); opacity: 1; }
  15% { transform: translateX(0) scale(0.88); }
  60% { transform: translateX(-150px) scale(0.88); opacity: 1; }
  72%, 100% { transform: translateX(-150px) scale(1); opacity: 0; }
}
@keyframes swipe-trail {
  0%, 15% { width: 0; opacity: 1; }
  60% { width: 150px; opacity: 1; }
  75%, 100% { width: 150px; opacity: 0; }
}
@keyframes swipe-peek {
  0%, 15% { transform: translateX(0); }
  60% { transform: translateX(-18%); }
  82%, 100% { transform: translateX(0); }
}
.swipe-hand { animation: swipe-hand 2.2s ease-in-out infinite; }
.swipe-trail { animation: swipe-trail 2.2s ease-in-out infinite; }
.swipe-peek > section { animation: swipe-peek 2.2s ease-in-out infinite; }
@keyframes swipe-pulse {
  0% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.6); }
  70% { box-shadow: 0 0 0 9px rgba(225, 29, 72, 0); }
  100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); }
}
</style>
