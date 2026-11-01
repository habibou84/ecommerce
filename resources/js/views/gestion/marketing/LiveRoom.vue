<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import LiveLink from '../../../components/LiveLink.vue'
import LiveScreenTabs from '../../../components/LiveScreenTabs.vue'
import LiveWhatsApp from '../../../components/LiveWhatsApp.vue'
import Modal from '../../../components/Modal.vue'
import { BEFORE_LIVE, loadChecks, saveChecks } from '../../../lib/liveTraining'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money, phoneDisplay } from '../../../lib/format'
import { platformOn } from '../../../router'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()

/**
 * Écran du live, pensé pour le téléphone tenu pendant le direct : en-tête « en direct » fixe (durée, montant,
 * commandes, à traiter), articles (pièces restantes, article montré aux clients), clientes groupées, bouton
 * « + Réservation » toujours à portée de pouce. Avant : préparation ; après : bilan et suite.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })
const router = useRouter()
const live = ref(null)
const error = ref('')
const busy = ref(false)
const notice = ref('')
// Code affiché en plein écran : le téléphone posé à côté de l'article pendant le live
const big = ref(null)
const share = ref(false)
let timer
// « Avant de lancer le live » : cases gardées sur cet appareil, par live
const ready = ref(loadChecks(`live${props.id}`))
watch(ready, (v) => saveChecks(`live${props.id}`, v), { deep: true })
const pageUrl = computed(() => `${window.location.origin}/live`)

async function load() {
  try {
    live.value = (await http.get(`/lives/${props.id}`)).data.data
  } catch (e) {
    // Application en arrière-plan : pas de message, la prochaine lecture rattrape
    if (!document.hidden) error.value = errorMessage(e)
  }
}
// Durée du live, mise à jour chaque minute
const now = ref(Date.now())
let clock
onMounted(() => {
  load()
  timer = setInterval(() => !document.hidden && live.value?.status === 'live' && load(), 3000)
  // Chaque seconde pendant une vente flash (chrono), sinon chaque demi-minute
  clock = setInterval(() => (live.value?.items?.some((i) => i.flash) || Date.now() - now.value >= 30000) && (now.value = Date.now()), 1000)
})
onUnmounted(() => {
  clearInterval(timer)
  clearInterval(clock)
})
watch(() => props.id, load)

function minutesBetween(from, to) {
  return from ? Math.max(0, Math.round(((to ? new Date(to).getTime() : now.value) - new Date(from).getTime()) / 60000)) : 0
}
const duration = computed(() => {
  const m = minutesBetween(live.value?.started_at, live.value?.ended_at)
  return m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`
})

async function act(path, body) {
  busy.value = true
  error.value = ''
  try {
    live.value = (await http.post(`/lives/${props.id}/${path}`, body)).data.data
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

// Dernier rappel sans WhatsApp : les commandes envoyées sur WhatsApp ne seraient pas lues
async function start() {
  if (live.value.whatsapp === null && !window.confirm('Votre WhatsApp n\'est pas connecté : les codes et captures envoyés sur WhatsApp ne seront pas lus, les clients réserveront seulement par le lien du live.\n\nLancer le live quand même ?')) return
  await act('start')
}
async function end() {
  if (window.confirm('Terminer le live ? Les clients ne pourront plus réserver ; ceux qui ont une pièce gardée peuvent encore donner leur quartier.')) await act('end')
}

// Article montré aux clients (mis en avant sur la page du live) ; toucher à nouveau l'enlève
const feature = (item) => act('feature', { item_id: live.value.featured_item_id === item.id ? null : item.id })

// Vente flash : prix live pendant quelques minutes, chrono sur les écrans
const flashing = ref(null)
const flashForm = reactive({ price: null, minutes: 5 })
function openFlash(item) {
  flashing.value = item
  // Proposition : environ 10 % de moins, arrondi à 500 F
  flashForm.price = Math.max(0, Math.floor((item.price * 0.9) / 500) * 500)
  flashForm.minutes = 5
}
async function startFlash() {
  if (await act(`items/${flashing.value.id}/flash`, { price: flashForm.price, minutes: flashForm.minutes })) flashing.value = null
}
const stopFlash = (item) => act(`items/${item.id}/flash`, { price: null })
function chrono(item) {
  const s = Math.max(0, Math.round((new Date(item.flash.ends_at).getTime() - now.value) / 1000))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

function stock(item) {
  if (item.remaining === 0) return [`Épuisé${item.waiting ? ` · ${item.waiting} en attente` : ''}`, 'text-red-600']
  if (item.remaining === 1) return ['Dernière pièce', 'text-ocre-700']
  return [`${item.remaining} restantes sur ${item.quantity}`, 'text-emerald-700']
}
const sold = (item) => Math.max(0, item.quantity - item.remaining)

// ── Clientes : réservations groupées par personne ──
const SOURCE = { page: 'Page du live', whatsapp: 'WhatsApp', manual: 'Équipe' }
const STATUS = {
  reserved: ['Quartier attendu', 'bg-amber-100 text-amber-900'],
  waiting: ['Liste d\'attente', 'bg-sky-100 text-sky-800'],
  ordered: ['Commande créée', 'bg-emerald-100 text-emerald-800'],
  expired: ['Libérée', 'bg-slate-100 text-slate-600'],
  cancelled: ['Annulée', 'bg-slate-100 text-slate-600'],
}
const PRIORITY = ['reserved', 'waiting', 'ordered', 'expired', 'cancelled']
const filter = ref('todo')
const openClient = ref('')
const clients = computed(() => {
  if (!live.value) return []
  const price = Object.fromEntries(live.value.items.map((i) => [i.code, i.price]))
  const groups = new Map()
  for (const r of live.value.reservations) {
    const key = r.customer_phone || r.customer_name || `r${r.id}`
    if (!groups.has(key)) groups.set(key, { key, name: r.customer_name || phoneDisplay(r.customer_phone) || 'Client', phone: r.customer_phone, list: [] })
    groups.get(key).list.push(r)
  }
  return [...groups.values()].map((g) => {
    const active = g.list.filter((r) => !['cancelled', 'expired'].includes(r.status))
    const status = PRIORITY.find((s) => g.list.some((r) => r.status === s))
    return {
      ...g, status,
      codes: active.map((r) => r.code),
      amount: active.reduce((sum, r) => sum + (price[r.code] ?? 0), 0),
      sources: [...new Set(g.list.map((r) => SOURCE[r.source] ?? r.source))].join(', '),
      orders: [...new Map(g.list.filter((r) => r.order).map((r) => [r.order.id, r.order])).values()],
      todo: g.list.some((r) => ['reserved', 'waiting'].includes(r.status)),
    }
  })
})
const todoCount = computed(() => clients.value.filter((c) => c.todo).length)
// Carte du live : la dernière réservation (mise à jour toute seule) et l'accès à la liste des clientes
const last = computed(() => (live.value?.reservations ?? []).filter((r) => r.status !== 'cancelled')
  .reduce((a, r) => (!a || r.created_at > a.created_at ? r : a), null))
function ago(iso) {
  const min = Math.max(0, Math.round((now.value - new Date(iso).getTime()) / 60000))
  return min < 1 ? 'à l\'instant' : min < 60 ? `il y a ${min} min` : `il y a ${Math.floor(min / 60)} h`
}
function goToClients(which = 'all') {
  filter.value = which
  document.getElementById('live-clientes')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
const shown = computed(() => clients.value.filter((c) => filter.value === 'all' || c.todo))

// ── Réservation vue dans les commentaires (bouton flottant) ──
const quickOpen = ref(false)
const quick = reactive({ code: '', name: '', phone: '' })
async function addQuick() {
  if (await act('reservations', { code: quick.code, name: quick.name || null, phone: quick.phone || null })) {
    notice.value = `${quick.code} réservé pour ${quick.name || phoneDisplay(quick.phone)}.`
    Object.assign(quick, { code: '', name: '', phone: '' })
    quickOpen.value = false
  }
}

// ── Articles ajoutés (préparation ou pendant le live) ──
const adding = ref(false)
const extra = ref([])
const search = ref('')
const results = ref([])
let searchTimer
function find() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    results.value = (await http.get('/lives/variants', { params: { search: search.value || undefined } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}
const inLive = (variantId) => live.value?.items.find((i) => i.variant_id === variantId)
function pick(v) {
  if (extra.value.some((i) => i.variant_id === v.id)) return
  extra.value.push({ variant_id: v.id, title: v.title, price: inLive(v.id)?.price ?? v.price, quantity: Math.max(1, Math.min(v.available || 1, 10)), available: v.available })
}
// Résultats regroupés par produit : « Tout le produit » ajoute toutes ses tailles et couleurs en stock
const productsFound = computed(() => {
  const groups = []
  results.value.forEach((v) => {
    const group = groups.find((g) => g.id === v.product_id)
    if (group) group.variants.push(v)
    else groups.push({ id: v.product_id, title: v.product ?? v.title, variants: [v] })
  })
  return groups
})
function pickProduct(group) {
  const left = group.variants.filter((v) => v.available > 0)
  ;(left.length ? left : group.variants).forEach(pick)
}
function openAdding() {
  adding.value = true
  notice.value = ''
  find()
}
function closeAdding() {
  adding.value = false
  extra.value = []
  search.value = ''
  results.value = []
}
async function addItems() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/lives/${props.id}/items`, {
      items: extra.value.map((i) => ({ variant_id: i.variant_id, price: Number(i.price), quantity: Number(i.quantity) })),
    })
    live.value = data.data
    notice.value = `Ajouté : ${data.meta.codes.join(', ')}. Montrez le code à l'écran, les clients peuvent réserver tout de suite.`
    closeAdding()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// ── Bilan ──
const best = computed(() => [...(live.value?.items ?? [])].filter((i) => sold(i) > 0).sort((a, b) => sold(b) * b.price - sold(a) * a.price))
const pieces = computed(() => (live.value?.items ?? []).reduce((sum, i) => sum + sold(i), 0))
const waitingClients = computed(() => clients.value.filter((c) => c.list.some((r) => r.status === 'waiting') && c.phone))
const trend = computed(() => {
  const prev = live.value?.previous_amount
  if (!prev) return null
  const pct = Math.round(((live.value.summary.amount - prev) / prev) * 100)
  return `${pct >= 0 ? '+' : ''}${pct} % par rapport au live précédent`
})
const showWaiting = ref(false)
const showAll = ref(false)
function waLink(c) {
  const codes = c.list.filter((r) => r.status === 'waiting').map((r) => r.code).join(', ')
  const text = `Bonjour ${c.name}, merci d'avoir participé à notre live ! L'article ${codes} est parti, mais nous avons d'autres articles qui pourraient vous plaire : ${window.location.origin}`
  return `https://wa.me/${String(c.phone).replace(/\D/g, '')}?text=${encodeURIComponent(text)}`
}
async function redo() {
  busy.value = true
  try {
    const { data } = await http.post(`/lives/${props.id}/duplicate`)
    router.push(`/gestion/live/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4" :class="live?.status === 'live' ? 'pb-28' : ''">
    <RouterLink to="/gestion/live" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Tous les lives</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <p v-if="!live" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <!-- ═══ En-tête selon l'état ═══ -->
      <section v-if="live.status === 'live'" class="sticky top-2 z-20 overflow-hidden rounded-[22px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 px-3 py-3 text-white shadow-lg sm:rounded-[24px] sm:p-5">
        <div class="flex items-center gap-2">
          <span class="inline-flex shrink-0 items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><span class="h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT · {{ duration }}</span>
          <span class="hidden min-w-0 flex-1 truncate text-sm font-semibold sm:block">{{ live.title }}</span>
          <span class="flex-1 sm:hidden" />
          <button type="button" class="shrink-0 rounded-full bg-white/20 px-3 py-1 text-xs font-bold" @click="share = true">Partager</button>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2">
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg font-extrabold leading-tight sm:text-xl">{{ money(live.summary.amount) }}</b><span class="text-xs">montant</span></span>
          <button type="button" class="rounded-xl bg-white/15 p-2 text-left" @click="goToClients('all')"><b class="block text-lg font-extrabold leading-tight sm:text-xl">{{ live.summary.orders }}</b><span class="text-xs">commande{{ live.summary.orders > 1 ? 's' : '' }} ›</span></button>
          <button type="button" class="rounded-xl bg-white/15 p-2 text-left" @click="goToClients('todo')"><b class="block text-lg font-extrabold leading-tight sm:text-xl">{{ todoCount }}</b><span class="text-xs">à traiter ›</span></button>
        </div>
        <!-- Dernière commande, en direct : la toucher mène à la liste des clientes -->
        <button v-if="last" type="button" class="mt-2 flex w-full items-center gap-2 rounded-xl bg-white/20 px-3 py-2 text-left text-sm" @click="goToClients('all')">
          <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-white" />
          <span class="min-w-0 flex-1 truncate"><b>Dernière :</b> {{ last.customer_name || phoneDisplay(last.customer_phone) || 'Client' }} · <b>{{ last.code }}</b> · {{ ago(last.created_at) }}</span>
          <span class="shrink-0 font-bold">Voir ›</span>
        </button>
      </section>

      <section v-else-if="live.status === 'draft'" class="overflow-hidden rounded-[24px] bg-live-grad p-5 text-white">
        <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />LIVE PRÊT</span>
        <h2 class="mt-2 text-xl font-extrabold">{{ live.title }}</h2>
        <p class="text-sm text-white/90">{{ live.items.length }} article{{ live.items.length > 1 ? 's' : '' }} · lancez le live au moment où vous passez à l'antenne.</p>
        <button type="button" class="mt-4 block w-full rounded-2xl bg-white py-3 text-center text-base font-extrabold text-[#c02a4e] disabled:opacity-60" :disabled="busy" @click="start">Lancer le live</button>
        <button type="button" class="mt-2 block w-full text-center text-sm text-white/90 underline" @click="share = true">Partager le lien avant de commencer</button>
      </section>

      <!-- Bilan du live terminé -->
      <template v-else>
        <section class="overflow-hidden rounded-[24px] bg-live-grad p-5 text-white">
          <span class="inline-flex rounded-full bg-white/20 px-3 py-1 text-xs font-bold">TERMINÉ · {{ formatDateTime(live.ended_at) }} · {{ duration }}</span>
          <p class="mt-1 text-sm font-semibold text-white/90">{{ live.title }}</p>
          <p class="mt-2 text-4xl font-extrabold">{{ money(live.summary.amount) }}</p>
          <p v-if="trend" class="text-sm text-white/90">{{ trend }}</p>
          <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
            <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.summary.orders }}</b>commande{{ live.summary.orders > 1 ? 's' : '' }}</span>
            <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ pieces }}</b>pièce{{ pieces > 1 ? 's' : '' }} vendue{{ pieces > 1 ? 's' : '' }}</span>
            <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.summary.sold_out }} / {{ live.items.length }}</b>épuisé{{ live.summary.sold_out > 1 ? 's' : '' }}</span>
          </div>
        </section>
        <section v-if="waitingClients.length" class="card flex flex-wrap items-center gap-3 p-4">
          <ColorIcon name="clients" :size="44" />
          <div class="min-w-0 flex-1"><p class="font-extrabold">{{ waitingClients.length }} cliente{{ waitingClients.length > 1 ? 's' : '' }} en liste d'attente</p><p class="text-sm text-slate-600">Proposez-leur un autre article par WhatsApp avant qu'elles achètent ailleurs.</p></div>
          <button type="button" class="btn-brand shrink-0" @click="showWaiting = true">Leur écrire</button>
        </section>
        <section class="card p-4">
          <h3 class="font-extrabold">Et maintenant ?</h3>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <RouterLink to="/gestion/commandes?status=to_ship" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 hover:border-emerald-600">
              <ColorIcon name="colis" :size="38" />
              <span class="min-w-0 flex-1 text-sm"><b class="block">Faire livrer les commandes</b><span class="text-slate-500">{{ live.summary.orders }} commande{{ live.summary.orders > 1 ? 's' : '' }} du live<template v-if="live.summary.pending"> · {{ live.summary.pending }} quartier(s) attendu(s)</template></span></span>
              <Icon name="chevron" :size="16" class="text-slate-400" />
            </RouterLink>
            <button type="button" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-left hover:border-emerald-600" :disabled="busy" @click="redo">
              <ColorIcon name="live" :size="38" />
              <span class="min-w-0 flex-1 text-sm"><b class="block">Refaire un live avec ces articles</b><span class="text-slate-500">même ordre, mêmes prix, à vérifier avant de lancer</span></span>
              <Icon name="chevron" :size="16" class="text-slate-400" />
            </button>
          </div>
        </section>
        <section class="card p-4">
          <h3 class="font-extrabold">Ce qui s'est vendu</h3>
          <p v-if="!best.length" class="mt-2 text-sm text-slate-500">Aucune pièce vendue pendant ce live.</p>
          <ul class="mt-2 divide-y divide-slate-100 text-sm">
            <li v-for="i in best" :key="i.id" class="flex items-center gap-3 py-2.5">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-red-600 text-xs font-black text-white">{{ i.code }}</span>
              <span class="min-w-0 flex-1 truncate">{{ i.title }}</span>
              <span class="shrink-0 text-slate-500">{{ sold(i) }} / {{ i.quantity }}</span>
              <b class="shrink-0">{{ money(sold(i) * i.price) }}</b>
            </li>
          </ul>
          <button v-if="clients.length" type="button" class="mt-2 text-sm font-semibold text-emerald-700" @click="showAll = !showAll">{{ showAll ? 'Masquer' : 'Voir' }} les {{ live.reservations.length }} réservations</button>
        </section>
      </template>

      <!-- ═══ Produits et écran, juste sous la carte du live ═══ -->
      <LiveScreenTabs v-if="live.status !== 'ended' && platformOn(auth, 'live_screens')" :id="live.id" @add="openAdding" />

      <!-- ═══ Articles ═══ -->
      <section v-else-if="live.status !== 'ended'" class="space-y-2">
        <div class="flex items-center justify-between px-0.5">
          <h3 class="text-[17px] font-extrabold">Articles</h3>
          <button type="button" class="text-sm font-semibold text-emerald-700" @click="openAdding">+ Ajouter</button>
        </div>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="i in live.items" :key="i.id" class="card flex min-w-0 items-center gap-3 p-3" :class="live.featured_item_id === i.id ? 'border-2 border-ocre-600 ring-4 ring-ocre-100' : ''">
            <button type="button" class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl text-xl font-black text-white" :class="i.remaining ? 'bg-red-600' : 'bg-slate-300'" :aria-label="`Afficher ${i.code} en grand`" @click="big = i">{{ i.code }}</button>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold" :title="i.title">{{ i.title }}</p>
              <p v-if="i.flash" class="text-xs font-bold text-red-600">⚡ {{ money(i.price) }} <s class="font-normal text-slate-400">{{ money(i.flash.was) }}</s> · {{ chrono(i) }}</p>
              <p v-else class="text-xs text-slate-500">{{ money(i.price) }}</p>
              <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="i.remaining ? 'bg-emerald-500' : 'bg-red-400'" :style="{ width: `${(sold(i) / i.quantity) * 100}%` }" /></div>
              <p class="mt-1 text-xs font-semibold" :class="stock(i)[1]">{{ stock(i)[0] }}</p>
            </div>
            <div v-if="live.status === 'live'" class="flex shrink-0 flex-col gap-1">
              <button type="button" class="rounded-xl px-2 py-2 text-xs font-bold" :class="live.featured_item_id === i.id ? 'bg-ocre-600 text-white' : 'bg-slate-100 text-slate-700'" :disabled="busy" @click="feature(i)">
                {{ live.featured_item_id === i.id ? 'Montré ✓' : 'Montrer' }}
              </button>
              <button v-if="i.flash" type="button" class="rounded-xl bg-red-50 px-2 py-1.5 text-xs font-bold text-red-700" :disabled="busy" @click="stopFlash(i)">Arrêter ⚡</button>
              <button v-else-if="i.remaining > 0" type="button" class="rounded-xl bg-amber-100 px-2 py-1.5 text-xs font-bold text-amber-800" :disabled="busy" @click="openFlash(i)">⚡ Prix live</button>
            </div>
          </div>
        </div>
        <p v-if="live.status === 'live'" class="px-0.5 text-xs text-slate-500">Touchez un code pour l'afficher en grand. « Montrer » met l'article en avant sur la page de vos clients. « Prix live » baisse le prix quelques minutes, avec un chrono sur l'écran.</p>
      </section>

      <!-- Avant de lancer -->
      <section v-if="live.status === 'draft'" class="card space-y-2 p-4 text-sm">
        <p class="font-semibold">Avant de lancer le live <span class="font-normal text-slate-500">· {{ ready.length }} / {{ BEFORE_LIVE.length }}</span></p>
        <label v-for="c in BEFORE_LIVE" :key="c" class="flex items-start gap-2">
          <input v-model="ready" type="checkbox" :value="c" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span :class="{ 'text-slate-400 line-through': ready.includes(c) }">{{ c }}</span>
        </label>
      </section>
      <LiveWhatsApp v-if="live.status === 'draft' && live.whatsapp === null" />


      <!-- ═══ Clientes ═══ -->
      <section v-if="live.status !== 'ended' || showAll" id="live-clientes" class="card scroll-mt-48 p-4">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="flex-1 text-[17px] font-extrabold">Clientes</h3>
          <div class="inline-flex rounded-lg bg-slate-100 p-1 text-xs" role="radiogroup" aria-label="Filtrer les clientes">
            <button type="button" role="radio" :aria-checked="filter === 'todo'" class="rounded-md px-2.5 py-1" :class="filter === 'todo' ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'" @click="filter = 'todo'">À traiter · {{ todoCount }}</button>
            <button type="button" role="radio" :aria-checked="filter === 'all'" class="rounded-md px-2.5 py-1" :class="filter === 'all' ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'" @click="filter = 'all'">Toutes · {{ clients.length }}</button>
          </div>
        </div>
        <p v-if="!shown.length" class="py-4 text-sm text-slate-500">{{ clients.length ? 'Rien à traiter pour l\'instant ✓' : 'Les réservations s\'afficheront ici dès les premières.' }}</p>
        <ul class="mt-1 divide-y divide-slate-100">
          <li v-for="c in shown" :key="c.key" class="py-2">
            <button type="button" class="flex w-full items-center gap-3 text-left" :aria-expanded="openClient === c.key" @click="openClient = openClient === c.key ? '' : c.key">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-slate-100 text-sm font-bold text-slate-600">{{ c.name.replace('@', '').charAt(0).toUpperCase() }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold">{{ c.name }}</span>
                <span class="flex flex-wrap items-center gap-1 text-xs text-slate-500">
                  <span v-for="(code, k) in c.codes" :key="k" class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ code }}</span>
                  {{ money(c.amount) }} · {{ c.sources }}
                </span>
              </span>
              <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="STATUS[c.status][1]">{{ c.status === 'ordered' && c.orders.length ? c.orders.map((o) => o.reference).join(', ') : STATUS[c.status][0] }}</span>
            </button>
            <!-- Détail : chaque réservation, commande, annulation -->
            <ul v-if="openClient === c.key" class="mt-2 space-y-1 rounded-xl bg-slate-50 p-2 text-sm">
              <li v-for="r in c.list" :key="r.id" class="flex flex-wrap items-center gap-2">
                <span class="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ r.code }}</span>
                <span class="min-w-0 flex-1 text-slate-600">{{ SOURCE[r.source] }}<template v-if="r.shipping_address"> · {{ r.shipping_address }}</template></span>
                <span class="rounded-full px-2 py-0.5 text-[11px]" :class="STATUS[r.status][1]">{{ STATUS[r.status][0] }}</span>
                <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="text-xs font-medium text-emerald-700 underline">{{ r.order.reference }}</RouterLink>
                <button v-if="['reserved', 'waiting'].includes(r.status)" type="button" class="text-xs font-medium text-red-600" :disabled="busy" @click="act(`reservations/${r.id}/cancel`)">Annuler</button>
              </li>
              <li v-if="c.phone" class="pt-1 text-xs text-slate-500">{{ phoneDisplay(c.phone) }} · <a :href="`https://wa.me/${c.phone.replace(/\D/g, '')}`" target="_blank" rel="noopener" class="font-medium text-emerald-700 underline">WhatsApp</a></li>
            </ul>
          </li>
        </ul>
      </section>

      <button v-if="live.status === 'live'" type="button" class="btn-ghost w-full text-sm text-slate-500" :disabled="busy" @click="end">Terminer le live</button>

      <!-- Bouton flottant : réservation vue dans les commentaires -->
      <template v-if="live.status === 'live'">
        <button type="button" class="fixed right-4 bottom-[92px] z-30 inline-flex items-center gap-2 rounded-full bg-emerald-700 px-5 py-3.5 text-sm font-extrabold text-white shadow-xl lg:bottom-8" :aria-expanded="quickOpen" @click="quickOpen = !quickOpen">
          <Icon :name="quickOpen ? 'close' : 'plus'" :size="18" />Réservation
        </button>
        <form v-if="quickOpen" class="fixed inset-x-0 bottom-[76px] z-30 rounded-t-3xl border-t border-slate-200 bg-white p-4 shadow-2xl lg:right-6 lg:bottom-24 lg:left-auto lg:w-96 lg:rounded-3xl lg:border" @submit.prevent="addQuick">
          <p class="font-extrabold">Réservation vue dans les commentaires</p>
          <div class="mt-3 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Code de l'article">
            <button v-for="i in live.items" :key="i.id" type="button" role="radio" :aria-checked="quick.code === i.code" class="rounded-xl border-2 px-3 py-2 text-sm font-black"
                    :class="quick.code === i.code ? 'border-red-600 bg-red-600 text-white' : i.remaining ? 'border-slate-200' : 'border-slate-200 text-slate-400'" @click="quick.code = i.code">{{ i.code }}</button>
          </div>
          <input v-model="quick.name" class="input mt-3" placeholder="Pseudo ou nom" maxlength="120" />
          <input v-model="quick.phone" class="input mt-2" type="tel" placeholder="Numéro WhatsApp (facultatif)" />
          <p class="mt-1 text-xs text-slate-500">Avec un numéro, la cliente reçoit un WhatsApp qui lui demande son quartier.</p>
          <button class="btn-brand mt-3 w-full" :disabled="busy || !quick.code || (!quick.name && !quick.phone)">{{ quick.code ? `Réserver ${quick.code}` : 'Choisissez un code' }}</button>
        </form>
      </template>
    </template>

    <!-- Ajouter des articles -->
    <Modal v-if="adding" :title="live?.status === 'live' ? 'Ajouter des articles pendant le live' : 'Ajouter des articles'" @close="closeAdding">
      <div class="space-y-3 text-sm">
        <p class="text-xs text-slate-500">Les codes déjà annoncés ne changent pas : un nouvel article prend le code suivant. Un article déjà dans le live reçoit les pièces en plus.</p>
        <div v-for="(i, index) in extra" :key="i.variant_id" class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 p-2">
          <span class="min-w-0 flex-1 basis-[calc(100%-3rem)] font-medium [overflow-wrap:anywhere] sm:basis-0">
            {{ i.title }}
            <span class="block text-xs font-normal text-slate-500">{{ inLive(i.variant_id) ? `Déjà dans le live (${inLive(i.variant_id).code}) : pièces en plus` : `${i.available} en stock` }}</span>
          </span>
          <button type="button" class="btn-ghost order-last px-2 text-red-600 max-sm:order-none" aria-label="Retirer" @click="extra.splice(index, 1)">✕</button>
          <label v-if="!inLive(i.variant_id)" class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28" /></label>
          <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20" /></label>
        </div>
        <input v-model="search" class="input" placeholder="Chercher un article (nom ou référence)" @input="find" />
        <div v-if="results.length" class="max-h-72 divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200">
          <template v-for="g in productsFound" :key="g.id">
            <div v-if="g.variants.length > 1" class="flex items-center gap-3 bg-slate-50 p-2">
              <img v-if="g.variants[0].image" :src="g.variants[0].image" alt="" class="h-9 w-9 rounded object-cover" />
              <b class="min-w-0 flex-1 truncate">{{ g.title }}</b>
              <button type="button" class="shrink-0 rounded-lg bg-emerald-700 px-2.5 py-1.5 text-xs font-bold text-white" @click="pickProduct(g)">Tout le produit ({{ g.variants.length }})</button>
            </div>
            <button v-for="v in g.variants" :key="v.id" type="button" class="flex w-full items-center gap-3 p-2 text-left hover:bg-slate-50" :class="g.variants.length > 1 ? 'pl-6' : ''" @click="pick(v)">
              <img v-if="v.image && g.variants.length === 1" :src="v.image" alt="" class="h-9 w-9 rounded object-cover" />
              <span class="min-w-0 flex-1">{{ g.variants.length > 1 ? v.variant : v.title }}<span v-if="inLive(v.id)" class="ml-1 text-xs text-red-600">{{ inLive(v.id).code }}</span></span>
              <span class="text-slate-500">{{ money(v.price) }} · {{ v.available }} en stock</span>
            </button>
          </template>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn-ghost" @click="closeAdding">Annuler</button>
          <button type="button" class="btn-brand" :disabled="busy || !extra.length" @click="addItems">Ajouter au live</button>
        </div>
      </div>
    </Modal>

    <!-- Partager : lien, message à épingler, WhatsApp -->
    <Modal v-if="flashing" :title="`⚡ Prix live · ${flashing.code}`" @close="flashing = null">
      <form class="space-y-4" @submit.prevent="startFlash">
        <p class="text-sm text-slate-600">{{ flashing.title }} · prix du live <strong>{{ money(flashing.price) }}</strong>. Pendant le chrono, les clients qui envoient {{ flashing.code }} ont le prix live ; l'article est montré sur l'écran avec le chrono.</p>
        <label class="block">
          <span class="text-sm font-semibold">Prix live</span>
          <input v-model.number="flashForm.price" type="number" min="0" :max="flashing.price - 1" step="100" class="input mt-1" required />
        </label>
        <div>
          <span class="text-sm font-semibold">Pendant</span>
          <div class="mt-1 flex gap-2">
            <button v-for="m in [2, 5, 10]" :key="m" type="button" class="flex-1 rounded-xl border px-3 py-2 text-sm font-semibold" :class="flashForm.minutes === m ? 'border-amber-500 bg-amber-50 text-amber-800' : 'border-slate-200'" @click="flashForm.minutes = m">{{ m }} min</button>
          </div>
        </div>
        <Alert v-if="error">{{ error }}</Alert>
        <button class="btn-brand w-full" :disabled="busy">⚡ Lancer la vente flash</button>
      </form>
    </Modal>

    <Modal v-if="share && live" title="Partager le live" @close="share = false">
      <div class="space-y-3 text-sm">
        <LiveLink :url="pageUrl" compact />
        <p v-if="live.whatsapp === 'official'" class="rounded-xl bg-emerald-50 p-3 text-emerald-900">✓ WhatsApp officiel connecté : les codes et captures envoyés par vos clients sont lus tout seuls.</p>
        <p v-else-if="live.whatsapp === 'link'" class="rounded-xl bg-amber-50 p-3 text-amber-900">Pour des lives sans risque de blocage, passez au WhatsApp officiel. <RouterLink to="/gestion/whatsapp?onglet=messages" class="font-medium underline">Connecter le WhatsApp officiel</RouterLink></p>
        <p v-else class="rounded-xl bg-amber-50 p-3 text-amber-900">WhatsApp pas connecté : les clients réservent seulement par le lien. <RouterLink to="/gestion/whatsapp?onglet=messages" class="font-medium underline">Connecter le WhatsApp officiel</RouterLink></p>
        <p class="text-slate-600">📷 Montrez le code de l'article à l'écran : quand une cliente envoie sa capture sur WhatsApp, le code est lu et l'article réservé. Pour plusieurs pièces : « A2 x3 ».</p>
      </div>
    </Modal>

    <!-- Clientes en liste d'attente : message WhatsApp prêt -->
    <Modal v-if="showWaiting" title="Écrire aux clientes en attente" @close="showWaiting = false">
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="c in waitingClients" :key="c.key" class="flex items-center gap-3 py-2.5">
          <span class="min-w-0 flex-1"><b class="block">{{ c.name }}</b><span class="text-xs text-slate-500">attendait {{ c.list.filter((r) => r.status === 'waiting').map((r) => r.code).join(', ') }}</span></span>
          <a :href="waLink(c)" target="_blank" rel="noopener" class="btn-secondary px-3 py-1.5">WhatsApp</a>
        </li>
      </ul>
    </Modal>

    <button v-if="big" type="button" class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-red-600 text-white" @click="big = null">
      <span class="text-[38vmin] font-black leading-none">{{ big.code }}</span>
      <span class="text-[7vmin] font-bold">{{ money(big.price) }}</span>
      <span class="text-sm opacity-80">Touchez pour fermer</span>
    </button>
  </div>
</template>
