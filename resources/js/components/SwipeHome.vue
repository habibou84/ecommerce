<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'
import LivePrepare from '../views/gestion/marketing/LivePrepare.vue'
import BoostPanel from './marketing/BoostPanel.vue'
import http from '../lib/http'
import { money } from '../lib/format'
import { platformOn } from '../router'
import { boot } from '../lib/boot'
import { useAuthStore } from '../stores/auth'

/**
 * Navigation par glissement (fonction « swipe_nav » de la console) : sur téléphone, l'accueil devient des pages côte
 * à côte, le Live d'abord. Chaque page garde l'essentiel de son domaine ; ⚙ ouvre ses réglages, les pastilles des
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
  const late = (s.value.couriers?.late_returns ?? 0) + (s.value.carriers?.late_returns ?? 0)
  return [
    { key: 'proofs', icon: '💳', count: o.waiting?.proofs ?? 0, text: `${o.waiting?.proofs} paiement(s) à valider`, action: 'Valider', to: '/gestion/commandes?alert=proof', urgent: true },
    { key: 'confirm', icon: '⏰', count: o.to_confirm, text: `${o.to_confirm} commande(s) à confirmer`, action: 'Confirmer', to: canOpen('/gestion/confirmation') ? '/gestion/confirmation' : '/gestion/commandes?status=pending_confirmation', urgent: o.callbacks_due > 0 },
    { key: 'unassigned', icon: '🛵', count: o.waiting?.unassigned ?? 0, text: `${o.waiting?.unassigned} colis sans livreur`, action: 'Choisir', to: '/gestion/commandes?status=to_ship', urgent: true },
    { key: 'not_picked', icon: '📦', count: o.waiting?.not_picked ?? 0, text: `${o.waiting?.not_picked} colis pas encore ramassé(s)`, action: 'Voir', to: '/gestion/commandes?alert=not_picked', urgent: false },
    { key: 'unhandled', icon: '⚠️', count: o.waiting?.unhandled ?? 0, text: `${o.waiting?.unhandled} problème(s) de livraison`, action: 'Traiter', to: '/gestion/commandes?alert=unhandled', urgent: true },
    { key: 'returns', icon: '↩️', count: late, text: `${late} colis à rapporter en retard`, action: 'Voir', to: '/gestion/commandes?alert=to_bring_back', urgent: true },
  ].filter((t) => t.count > 0)
})
const cashTodo = computed(() => [
  ...(s.value.couriers?.late ?? []).map((x) => ({ key: `c${x.courier_id}`, icon: '🛵', text: `${x.name} garde ${money(x.held)}`, action: 'Encaisser', to: '/gestion/caisse/livreurs', urgent: x.level > 1 })),
  ...(s.value.carriers?.overdue ?? []).map((x) => ({ key: `e${x.connection_id}`, icon: '🏢', text: `${x.carrier} vous doit ${money(x.amount)}`, action: 'Réclamer', to: '/gestion/caisse/a-recevoir', urgent: x.level > 1 })),
])
const badge = (items) => (items.length ? { n: items.length, level: items.some((i) => i.urgent) ? 'red' : 'orange' } : null)

// ── Pages ──
const PAGES = [
  { key: 'live', name: 'Live', icon: '🔴', show: () => canOpen('/gestion/live'), badge: () => (s.value.live?.state === 'live' ? { n: '●', level: 'red' } : null),
    settings: [{ to: '/gestion/live/passes', label: 'Lives passés et chiffres' }, { to: '/gestion/whatsapp', label: 'WhatsApp' }, { to: '/gestion/materiel', label: 'Matériel du live' }] },
  // Boost : la pub Facebook et Instagram au même endroit (connexion, pixel, produits à booster, commandes venues des boosts)
  { key: 'boost', name: 'Boost', icon: '🚀', show: () => canOpen('/gestion/boost'), badge: () => null,
    settings: [{ to: '/gestion/marketing/suivi', label: 'Pixels et catalogue (réglages avancés)' }, { to: '/gestion/marketing/reseaux', label: 'Réseaux sociaux' }] },
  { key: 'orders', name: 'Commandes', icon: '🛍️', show: () => canOpen('/gestion/commandes'), badge: () => badge(orderTodo.value),
    settings: [{ to: '/gestion/livraisons', label: 'Livraisons en cours' }, { to: '/gestion/etiquettes', label: 'Étiquettes à scanner' }, { to: '/gestion/livraisons/livreurs', label: 'Mes livreurs' }, { to: '/gestion/livraisons/entreprises', label: 'Entreprises de livraison' }, { to: '/gestion/livraisons/tarifs', label: 'Tarifs de livraison' }, { to: '/gestion/livraisons/qui-livre-quoi', label: 'Qui livre quoi' }, { to: '/gestion/textes-messages', label: 'Textes des messages' }, { to: '/gestion/alertes', label: 'Alertes (délais, heures)' }] },
  { key: 'cash', name: 'Caisse', icon: '💰', show: () => canOpen('/gestion/caisse'), badge: () => badge(cashTodo.value),
    settings: [{ to: '/gestion/caisse/livreurs', label: 'Argent des livreurs' }, { to: '/gestion/caisse/a-recevoir', label: 'À recevoir des entreprises' }, { to: '/gestion/statistiques', label: 'Statistiques' }, { to: '/gestion/paiements', label: 'Paiement en ligne' }, { to: '/gestion/acompte', label: 'Acompte' }, { to: '/gestion/alertes', label: 'Alertes (délais)' }] },
  { key: 'shop', name: 'Boutique', icon: '🏪', show: () => canOpen('/gestion/boutique-en-ligne'), badge: () => (s.value.shop?.abandoned ? { n: s.value.shop.abandoned, level: 'orange' } : null),
    settings: [{ to: '/gestion/boutique-en-ligne', label: 'Apparence' }, { to: '/gestion/boutique-en-ligne/pages', label: 'Pages et menus' }, { to: '/gestion/boutique-en-ligne/domaine', label: 'Nom de domaine' }] },
  { key: 'items', name: 'Articles', icon: '👗', show: () => canOpen('/gestion/catalogue'), badge: () => (s.value.stock && s.value.stock.out + s.value.stock.low ? { n: s.value.stock.out + s.value.stock.low, level: s.value.stock.out ? 'red' : 'orange' } : null),
    settings: [{ to: '/gestion/collections', label: 'Collections' }, { to: '/gestion/packs', label: 'Packs et prix de gros' }, { to: '/gestion/stock', label: 'Stock détaillé et mouvements' }, { to: '/gestion/stock/commandes', label: 'Fournisseurs et bons de commande' }, { to: '/gestion/catalogue/import', label: 'Importer des produits' }] },
  { key: 'more', name: 'Plus', icon: '☰', show: () => true, badge: () => (s.value.unread_messages ? { n: s.value.unread_messages, level: 'orange' } : null), settings: [{ to: '/gestion/parametres', label: 'Informations de l\'espace marchand' }, { to: '/gestion/equipe', label: 'Équipe' }] },
]
const pages = computed(() => PAGES.filter((p) => p.show()))

// Page affichée : gardée au retour d'un écran ouvert depuis une page
const KEY = 'jm.swipe-page'
const track = ref(null)
const tabs = ref(null)
const current = ref(0)
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
  try {
    sessionStorage.setItem(KEY, key)
  } catch {
    // Sans stockage : on revient sur le Live
  }
  // L'onglet de la page affichée vient au milieu de la barre (jamais caché sous la cloche)
  const bar = tabs.value
  const tab = bar?.children[i]
  if (bar && tab) bar.scrollTo({ left: Math.max(0, tab.offsetLeft - (bar.clientWidth - tab.offsetWidth) / 2), behavior: 'smooth' })
  if (key === 'orders') loadOrders()
  if (key === 'items') loadItems()
}, { immediate: true })
onMounted(async () => {
  let saved = null
  try {
    saved = sessionStorage.getItem(KEY)
  } catch {
    saved = null
  }
  const i = Math.max(0, pages.value.findIndex((p) => p.key === saved))
  await nextTick()
  current.value = i
  go(i, false)
  startPage = i
})

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

// ── Commandes : les dernières, chargées une fois à l'arrivée sur la page ──
const orders = ref(null)
async function loadOrders() {
  if (orders.value) return
  orders.value = (await http.get('/orders', { params: { per_page: 6 } }).catch(() => ({ data: { data: [] } }))).data.data
}
const tone = (o) => ({ pending_confirmation: 'bg-amber-100 text-amber-900', cancelled: 'bg-slate-100 text-slate-500', delivered: 'bg-emerald-100 text-emerald-800', completed: 'bg-emerald-100 text-emerald-800' }[o.status] ?? 'bg-sky-100 text-sky-900')

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
  { to: '/gestion/messages', label: 'Messages', color: 'whatsapp' },
  { to: '/gestion/clients', label: 'Clients', color: 'clients' },
  { to: '/gestion/livraisons', label: 'Livraisons', color: 'moto' },
  { to: '/gestion/statistiques', label: 'Statistiques', color: 'stats' },
  { to: '/gestion/marketing', label: 'Promotions', color: 'promo' },
  { to: '/gestion/stock/commandes', label: 'Fournisseurs', color: 'camion' },
  { to: '/gestion/alertes', label: 'Alertes', color: 'cloche' },
  { to: '/gestion/equipe', label: 'Équipe', color: 'profil' },
  { to: '/gestion/mes-boutiques', label: 'Mes boutiques', color: 'boutique' },
  { to: '/gestion/demarrage', label: 'Bien démarrer', color: 'aide' },
  { to: '/gestion/whatsapp', label: 'WhatsApp', color: 'whatsapp' },
  { to: '/gestion/parametres', label: 'Informations', color: 'reglages' },
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
  { to: '/gestion/catalogue/nouveau', label: '📷 Nouvel article' },
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
                class="relative mt-1 shrink-0 rounded-full px-3 py-1.5 text-[13.5px] font-extrabold transition"
                :class="i === current ? (p.badge()?.level === 'red' ? 'bg-rose-800 text-white' : 'bg-slate-900 text-white') : (p.badge()?.level === 'red' ? 'bg-rose-100 text-rose-900' : 'bg-white text-slate-600 shadow-soft')"
                @click="go(i)">
          {{ p.icon }} {{ p.name }}
          <span v-if="p.badge()" class="absolute -right-1.5 -top-2 min-w-[19px] rounded-full border-2 border-slate-50 px-1 text-center text-[11px] font-black leading-[15px] text-white"
                :class="p.badge().level === 'red' ? 'animate-[swipe-pulse_1.6s_ease-out_infinite] bg-rose-600' : 'bg-amber-500'">{{ p.badge().n }}</span>
        </button>
      </div>
    </Teleport>

    <!-- Les pages, côte à côte, aimantées -->
    <div ref="track" :class="{ 'swipe-peek': hint }" class="flex min-h-0 flex-1 snap-x snap-mandatory overflow-x-auto overflow-y-hidden [scrollbar-width:none]" @scroll.passive="onScroll">
      <section v-for="p in pages" :key="p.key" class="h-full w-full shrink-0 snap-start overflow-y-auto px-4 pb-28 pt-1" :aria-label="p.name">
        <!-- LIVE : préparer, en direct (« Revenir au live »), lives passés -->
        <!-- Chargée seulement une fois la page Live affichée, et pas en double quand « Préparer un live » est ouvert par-dessus -->
        <template v-if="p.key === 'live'">
          <LivePrepare v-if="seen.has('live') && route.path !== '/gestion/live'" />
        </template>

        <!-- COMMANDES (+ livraisons) -->
        <template v-else-if="p.key === 'orders'">
          <div class="mb-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="canOpen('/gestion/commandes/nouvelle')" to="/gestion/commandes/nouvelle" class="btn-brand justify-center py-3 text-[15px] font-black">＋ Commande</RouterLink>
            <RouterLink v-if="canOpen('/gestion/caisse/vente')" to="/gestion/caisse/vente" class="btn-secondary justify-center py-3 text-[15px] font-black">🏪 Comptoir</RouterLink>
          </div>
          <div class="rounded-[22px] bg-gradient-to-br from-emerald-800 to-emerald-500 p-4 text-white">
            <p class="text-sm font-semibold opacity-90">Aujourd'hui</p>
            <p class="text-[26px] font-black leading-tight">{{ s.orders?.today_count ?? 0 }} commande(s) · {{ money(s.orders?.today_amount ?? 0) }}</p>
          </div>
          <div v-if="orderTodo.length" class="mt-3 rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
            <p class="text-[11px] font-black uppercase tracking-wide text-orange-800">⚠ À faire</p>
            <RouterLink v-for="t in orderTodo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-orange-100 py-2 text-[14px] font-bold first-of-type:border-0">
              <span aria-hidden="true">{{ t.icon }}</span><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-orange-700">{{ t.action }} ▸</span>
            </RouterLink>
          </div>
          <div class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
            <RouterLink to="/gestion/commandes?status=pending_confirmation" class="shrink-0 rounded-full bg-white px-3 py-1.5 text-[13px] font-extrabold shadow-soft">À confirmer {{ s.orders?.to_confirm ?? '' }}</RouterLink>
            <RouterLink to="/gestion/commandes?status=to_ship" class="shrink-0 rounded-full bg-white px-3 py-1.5 text-[13px] font-extrabold shadow-soft">À livrer {{ s.orders?.to_ship ?? '' }}</RouterLink>
            <RouterLink to="/gestion/livraisons" class="shrink-0 rounded-full bg-white px-3 py-1.5 text-[13px] font-extrabold shadow-soft">🛵 En livraison</RouterLink>
            <RouterLink to="/gestion/commandes" class="shrink-0 rounded-full bg-white px-3 py-1.5 text-[13px] font-extrabold shadow-soft">Toutes</RouterLink>
          </div>
          <div class="mt-3 rounded-2xl bg-white p-1 shadow-soft">
            <p v-if="!orders" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
            <p v-else-if="!orders.length" class="p-4 text-center text-sm text-slate-500">Pas encore de commande.</p>
            <RouterLink v-for="o in orders ?? []" :key="o.id" :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 border-b border-slate-100 px-3 py-2.5 last:border-0">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-slate-100 font-black text-slate-600">{{ (o.customer_name ?? '?').charAt(0).toUpperCase() }}</span>
              <span class="min-w-0 flex-1"><b class="block truncate text-[14.5px]">{{ o.customer_name ?? o.reference }}</b><span class="block truncate text-xs text-slate-500">{{ money(o.total) }} · {{ o.source_label }}<template v-if="o.shipping_place_label"> · {{ o.shipping_place_label }}</template></span></span>
              <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-extrabold" :class="tone(o)">{{ o.status_label }}</span>
            </RouterLink>
          </div>
        </template>

        <!-- CAISSE (+ argent dehors) -->
        <template v-else-if="p.key === 'cash'">
          <div v-if="cashTodo.length" class="mb-3 rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
            <p class="text-[11px] font-black uppercase tracking-wide text-orange-800">⚠ À encaisser</p>
            <RouterLink v-for="t in cashTodo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-orange-100 py-2 text-[14px] font-bold first-of-type:border-0">
              <span aria-hidden="true">{{ t.icon }}</span><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-orange-700">{{ t.action }} ▸</span>
            </RouterLink>
          </div>
          <div class="rounded-[22px] bg-gradient-to-br from-emerald-800 to-emerald-500 p-4 text-white">
            <p class="text-sm font-semibold opacity-90">Ventes du jour</p>
            <p class="text-[28px] font-black leading-tight">{{ money(s.orders?.today_amount ?? 0) }}</p>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="s.couriers" to="/gestion/caisse/livreurs" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-[19px] font-black">{{ money(s.couriers.cash) }}</b><span class="text-xs text-slate-500">chez les livreurs</span></RouterLink>
            <RouterLink v-if="s.carriers" to="/gestion/caisse/a-recevoir" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-[19px] font-black">{{ money(s.carriers.amount) }}</b><span class="text-xs text-slate-500">chez les entreprises</span></RouterLink>
            <div class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-[19px] font-black">{{ s.orders?.today_count ?? 0 }}</b><span class="text-xs text-slate-500">commandes du jour</span></div>
            <div v-if="s.couriers || s.carriers" class="rounded-2xl bg-white p-3 text-center shadow-soft"><b class="block text-[19px] font-black">{{ (s.couriers?.to_bring_back ?? 0) + (s.carriers?.to_bring_back ?? 0) }}</b><span class="text-xs text-slate-500">colis à rapporter</span></div>
          </div>
          <RouterLink to="/gestion/caisse" class="btn-brand mt-3 w-full justify-center py-3 text-[15px] font-black">💰 Ouvrir la caisse et clôturer</RouterLink>
        </template>

        <!-- BOUTIQUE EN LIGNE -->
        <template v-else-if="p.key === 'shop'">
          <div class="rounded-[22px] bg-gradient-to-br from-blue-900 to-blue-500 p-4 text-white">
            <p class="text-sm font-semibold opacity-90">Ta boutique en ligne</p>
            <p class="truncate text-[18px] font-black">{{ shopUrl.replace(/^https?:\/\//, '') }}</p>
            <div class="mt-3 grid grid-cols-2 gap-2">
              <button type="button" class="rounded-xl bg-white py-2.5 text-sm font-black text-blue-900" @click="share">{{ copied ? 'Lien copié ✓' : '🔗 Partager' }}</button>
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
              <span class="min-w-0 flex-1"><b class="block text-[14.5px]">{{ l.label }}</b><span class="text-xs text-slate-500">{{ l.hint }}</span></span>
              <span class="text-xl text-slate-400" aria-hidden="true">›</span>
            </RouterLink>
          </div>
        </template>

        <!-- BOOST : la pub Facebook et Instagram -->
        <!-- Chargée seulement une fois la page Boost affichée -->
        <template v-else-if="p.key === 'boost'">
          <BoostPanel v-if="seen.has('boost')" />
        </template>

        <!-- ARTICLES : catalogue et stock réunis -->
        <template v-else-if="p.key === 'items'">
          <div class="mb-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="canOpen('/gestion/catalogue/nouveau')" to="/gestion/catalogue/nouveau" class="btn-brand justify-center py-3 text-[15px] font-black">📷 Nouvel article</RouterLink>
            <RouterLink v-if="canOpen('/gestion/stock')" to="/gestion/stock" class="btn-secondary justify-center py-3 text-[15px] font-black">＋ Stock</RouterLink>
          </div>
          <input v-model="itemSearch" class="input" placeholder="🔍 Chercher un article" @input="searchItems" />
          <div class="-mx-4 mt-2 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none]">
            <button v-for="[v, label] in [['', 'Tout'], ['low', `⚠ Stock bas ${s.stock?.low ?? ''}`], ['out', `Épuisés ${s.stock?.out ?? ''}`]]" :key="v" type="button"
                    class="shrink-0 rounded-full px-3 py-1.5 text-[13px] font-extrabold" :class="itemFilter === v ? 'bg-slate-900 text-white' : (v ? 'bg-rose-50 text-rose-800' : 'bg-white shadow-soft')" @click="itemFilter = v">{{ label }}</button>
          </div>
          <p v-if="!items" class="p-4 text-center text-sm text-slate-500">Chargement…</p>
          <p v-else-if="!items.length" class="p-4 text-center text-sm text-slate-500">Aucun article.</p>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink v-for="a in items ?? []" :key="a.id" :to="`/gestion/catalogue/${a.id}`" class="overflow-hidden rounded-2xl bg-white shadow-soft">
              <img v-if="a.thumb" :src="a.thumb" alt="" class="aspect-square w-full object-cover" loading="lazy" />
              <span v-else class="grid aspect-square w-full place-items-center bg-slate-100 text-3xl" aria-hidden="true">📷</span>
              <span class="block p-2"><b class="block truncate text-[13.5px]">{{ a.title }}</b><span class="text-xs text-slate-500">{{ money(a.price_min ?? 0) }}</span>
                <span class="mt-1 block w-fit rounded-full px-2 py-0.5 text-[11px] font-extrabold" :class="stockPill(a).cls">{{ stockPill(a).text }}</span></span>
            </RouterLink>
          </div>
        </template>

        <!-- PLUS : grandes icônes, aide, réglages de chaque page, compte -->
        <template v-else>
          <div class="grid grid-cols-3 gap-2.5">
            <a v-if="helpUrl" :href="helpUrl" target="_blank" rel="noopener" class="flex flex-col items-center gap-1.5 rounded-[18px] bg-white px-1 py-3 text-center shadow-soft transition active:scale-95">
              <ColorIcon name="aide" :size="44" /><span class="text-[12.5px] font-semibold leading-tight text-slate-700">Aide</span>
            </a>
            <RouterLink v-for="t in plus" :key="t.to + t.label" :to="t.to" class="flex flex-col items-center gap-1.5 rounded-[18px] bg-white px-1 py-3 text-center shadow-soft transition active:scale-95">
              <ColorIcon :name="t.color" :size="44" />
              <span class="text-[12.5px] font-semibold leading-tight text-slate-700">{{ t.label }}</span>
              <span v-if="t.to === '/gestion/messages' && s.unread_messages" class="-mt-1 rounded-full bg-rose-600 px-1.5 text-[10px] font-black text-white">{{ s.unread_messages }} non lu(s)</span>
            </RouterLink>
          </div>
          <h3 class="mb-2 mt-5 px-1 text-[15px] font-black text-slate-700">⚙ Réglages</h3>
          <details v-for="g in pageSettings" :key="g.key" class="mb-2 rounded-2xl bg-white shadow-soft">
            <summary class="flex cursor-pointer items-center gap-2 px-4 py-3 text-[15px] font-extrabold">{{ g.icon }} {{ g.name }}<span class="ml-auto text-slate-400" aria-hidden="true">›</span></summary>
            <RouterLink v-for="l in g.items" :key="l.to + l.label" :to="l.to" class="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-[14.5px] font-semibold">{{ l.label }}<span class="text-lg text-slate-400" aria-hidden="true">›</span></RouterLink>
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
      <p class="text-xl font-black leading-tight">Glissez pour passer<br>d'une page à l'autre</p>
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
      <p class="mb-2 px-1 text-[18px] font-extrabold">{{ sheet === 'add' ? 'Ajouter' : `⚙ ${page?.name}` }}</p>
      <button v-for="l in sheetLinks" :key="l.to + l.label" type="button" class="flex w-full items-center justify-between border-b border-slate-100 px-1 py-3.5 text-left text-[15.5px] font-bold last:border-0" @click="open(l.to)">
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
