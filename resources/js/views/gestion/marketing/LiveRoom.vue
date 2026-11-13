<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import LiveLink from '../../../components/LiveLink.vue'
import LiveCockpit from '../../../components/LiveCockpit.vue'
import LiveScreenTabs from '../../../components/LiveScreenTabs.vue'
import LivePin from '../../../components/LivePin.vue'
import LiveWhatsApp from '../../../components/LiveWhatsApp.vue'
import LiveBilan from '../../../components/live/LiveBilan.vue'
import LiveMysteryEditor from '../../../components/live/LiveMysteryEditor.vue'
import EndLiveSheet from '../../../components/EndLiveSheet.vue'
import Modal from '../../../components/Modal.vue'
import { BEFORE_LIVE, loadChecks, saveChecks } from '../../../lib/liveTraining'
import { pinSignature, readPin } from '../../../lib/livePin'
import { fullscreenOn, livesChanged } from '../../../lib/fullscreen'
import { openRecharge } from '../../../lib/credits'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money, phoneDisplay } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'


/**
 * Écran du live, pensé pour le téléphone tenu pendant le direct : en-tête « en direct » fixe (durée, montant,
 * commandes, à traiter), articles (pièces restantes, article montré aux clients), clientes groupées, bouton
 * « + Réservation » toujours à portée de pouce. Avant : préparation ; après : bilan et suite.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })
const router = useRouter()
const route = useRoute()
// Deux onglets avant et pendant le live : « Live » (articles, messages, commandes, ce qui passe à l'écran) et
// « Support » (photo à épingler, écran télé, liens OBS / TikTok LIVE Studio)
const pane = ref(route.query.onglet === 'support' ? 'support' : 'live')
watch(pane, (p) => router.replace({ query: { ...route.query, onglet: p === 'support' ? 'support' : undefined } }))
const screensOn = computed(() => useAuthStore().profile?.features?.live_screens !== false)
// Support : sur quoi la vendeuse est en live (un ou deux supports), gardé sur cet appareil pour ce live. L'onglet Live
// montre en haut ce que voient les clientes sur ces supports.
const SUPPORTS = [['tv', '📺', 'Télé ou tablette'], ['studio', '📱', 'TikTok LIVE Studio'], ['pin', '📌', 'Étiquette à épingler']]
const supportKey = `jm.live-supports.${props.id}`
function readStore(key) {
  try {
    return JSON.parse(localStorage.getItem(key) ?? 'null')
  } catch {
    return null
  }
}
function writeStore(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value))
  } catch {
    // stockage indisponible : le choix vaut pour cette visite
  }
}
const asked = ['pin', 'tv', 'studio'].includes(route.query.support) ? [route.query.support] : null
const supports = ref(asked ?? readStore(supportKey) ?? (screensOn.value ? ['tv'] : ['pin']))
const supportList = computed(() => SUPPORTS.filter(([key]) => screensOn.value || key === 'pin'))
// Sans écrans (console), seule l'étiquette reste
const activeSupports = computed(() => {
  const list = supports.value.filter((s) => screensOn.value || s === 'pin')
  return list.length ? list : ['pin']
})
watch(supports, (v) => writeStore(supportKey, v), { deep: true })
// Deux supports au plus ; toujours au moins un
function chooseSupport(key) {
  const list = activeSupports.value.filter((s) => s !== key)
  supports.value = [...list, key].slice(-2)
  sheet.value = null
}
function dropSupport(key) {
  if (activeSupports.value.length > 1) supports.value = activeSupports.value.filter((s) => s !== key)
  sheet.value = null
}
// Fenêtre d'explication d'un support : à quoi il sert, comment le mettre en place ; coché quand il est prêt
const sheet = ref(null)
const SUPPORT_HELP = {
  tv: { why: 'Vos articles s\'affichent en grand derrière vous. Vous les faites défiler depuis ce téléphone, les clientes les voient en direct.', video: '/videos/supports/tv.mp4?v=1', poster: '/videos/supports/tv.jpg?v=1' },
  studio: { why: 'La carte de l\'article montré apparaît sur votre live TikTok, en haut à gauche.', video: '/videos/supports/studio.mp4?v=1', poster: '/videos/supports/studio.jpg?v=1' },
  pin: { why: 'Une image avec vos articles et leurs codes, en fond vert sur votre live : les clientes la voient pendant tout le live.', video: '/videos/supports/pin.mp4?v=1', poster: '/videos/supports/pin.jpg?v=1' },
}
// Écrans reliés (télé, LIVE Studio) : état de chaque support, et télé cochée toute seule dès qu'elle est reliée
const screens = ref(null)
async function loadScreens() {
  if (!screensOn.value || document.hidden) return
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch {
    // lecture suivante
  }
}
const tvOnline = computed(() => !!screens.value?.some((s) => !s.is_link && s.online))
const studioOnline = computed(() => !!screens.value?.some((s) => s.is_link && s.online))
watch(tvOnline, (on, was) => on && !was && sheet.value === 'tv' && chooseSupport('tv'))
let screensTimer
watch([pane, sheet], ([p, s]) => {
  clearInterval(screensTimer)
  if (p !== 'support') return
  loadScreens()
  screensTimer = setInterval(loadScreens, s ? 3000 : 10000)
}, { immediate: true })
onUnmounted(() => clearInterval(screensTimer))
const pinStale = computed(() => pinShared.value?.sig !== pinSignature(live.value?.items ?? []))
function supportState(key) {
  if (!activeSupports.value.includes(key)) return ['Choisir', 'bg-slate-100 text-slate-700']
  if (key === 'pin') return pinStale.value ? ['⚠ À ré-épingler', 'bg-amber-100 text-amber-900'] : ['✓ À jour', 'bg-emerald-100 text-emerald-800']
  if (screens.value === null) return ['✓ Choisi', 'bg-emerald-100 text-emerald-800']
  const on = key === 'tv' ? tvOnline.value : studioOnline.value
  return on ? ['✓ Prête', 'bg-emerald-100 text-emerald-800'] : [key === 'tv' ? 'Pas connectée' : 'Pas connecté', 'bg-red-100 text-red-800']
}
function onPinSheetShared(signature) {
  onPinShared(signature)
  chooseSupport('pin')
}
// Étiquette : la dernière téléchargée ou partagée (ses codes), pour prévenir quand elle n'est plus à jour
const pinKey = `jm.live-pin.${props.id}`
const pinShared = ref(readPin(readStore(pinKey)))
function onPinShared(signature) {
  pinShared.value = { sig: signature, at: new Date().toISOString() }
  writeStore(pinKey, pinShared.value)
}
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
    // Commandes WhatsApp sans live : leur propre écran (pas de TikTok ni d'écran du live)
    if (live.value?.channel === 'whatsapp') {
      router.replace(`/gestion/live/commandes-whatsapp/${props.id}`)
      return
    }
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
// Pendant le live : plein écran, la page derrière ne défile plus
const fullscreen = computed(() => live.value?.status === 'live')
watch(fullscreen, (on) => {
  document.body.style.overflow = on ? 'hidden' : ''
  fullscreenOn.value = on
})
onUnmounted(() => {
  document.body.style.overflow = ''
  fullscreenOn.value = false
})
// ✕ : retour exactement là d'où l'on vient (accueil, onglet Live, « Préparer un live »), sans ouvrir d'autre fenêtre
function closeLive() {
  if (window.history.state?.back) router.back()
  else router.replace('/gestion')
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
    if (path === 'start' || path === 'end') livesChanged()
    return true
  } catch (e) {
    // Solde trop bas pour ouvrir les commandes : la recharge s'ouvre (pack ou illimité)
    if (path === 'start' && e.response?.data?.errors?.payment) {
      openRecharge(e.response.data.errors.payment[0])
      return false
    }
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
// « Terminer le live » : commandes gardées actives 24 h / 48 h, ou arrêtées maintenant
const ending = ref(false)
function end() {
  ending.value = true
}
async function confirmEnd(hours) {
  if (await act('end', hours ? { keep_hours: hours } : {})) ending.value = false
}
async function stopOrders() {
  if (window.confirm('Arrêter les commandes maintenant ? Les codes ne marcheront plus et le live sera compté.')) await act('stop-orders')
}
const ordersUntil = computed(() => live.value?.orders_until ? new Date(live.value.orders_until).toLocaleString('fr-FR', { weekday: 'long', hour: '2-digit', minute: '2-digit' }).replace(':', ' h ') : null)

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
  <!-- En direct : tout l'écran du téléphone, fond sombre, ✕ pour sortir (le live continue) -->
  <Teleport to="body" :disabled="!fullscreen">
  <div :class="fullscreen ? 'fixed inset-0 z-[45] overflow-y-auto overscroll-contain bg-[#0b1120]' : ''">
  <div class="space-y-4" :class="fullscreen ? 'mx-auto max-w-3xl px-3 pt-[max(0.75rem,env(safe-area-inset-top))] pb-28' : ''">
    <RouterLink v-if="!fullscreen" to="/gestion/live" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Tous les lives</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <p v-if="!live" class="text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <!-- ═══ Onglets Live / Support (avant et pendant le live) ═══ -->
      <nav v-if="live.status === 'draft'" class="grid grid-cols-2 border-b border-slate-200" role="tablist" aria-label="Live ou support">
        <button v-for="[value, label] in [['live', 'Live'], ['support', 'Support']]" :key="value" type="button" role="tab" :aria-selected="pane === value"
                class="-mb-px border-b-[3px] py-2.5 text-base font-extrabold" :class="pane === value ? 'border-slate-900 text-slate-900' : 'border-transparent text-slate-500'" @click="pane = value">
          {{ label }}<span v-if="value === 'live' && live.status === 'live'" class="ml-1.5 rounded bg-red-600 px-1.5 py-0.5 align-middle text-[10px] font-black text-white">● OUVERT</span>
        </button>
      </nav>

      <!-- ═══ Support : un ou deux supports cochés, le réglage de chacun sous sa case, puis les infos du live ═══ -->
      <template v-if="live.status === 'draft' && pane === 'support'">
        <p class="text-base font-extrabold">Je suis en live avec… <span v-if="supportList.length > 1" class="text-sm font-semibold text-slate-500">(1 ou 2)</span></p>
        <div class="space-y-2">
          <button v-for="[key, icon, label] in supportList" :key="key" type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 bg-white p-3 text-left"
                  :class="activeSupports.includes(key) ? 'border-slate-900' : 'border-slate-200'" @click="sheet = key">
            <span class="text-2xl" aria-hidden="true">{{ icon }}</span>
            <b class="min-w-0 flex-1 text-base">{{ label }}</b>
            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-extrabold" :class="supportState(key)[1]">{{ supportState(key)[0] }}</span>
          </button>
        </div>

        <!-- Fenêtre d'un support : vidéo, à quoi il sert, comment le mettre en place, puis « Choisir » -->
        <Modal v-if="sheet" :title="SUPPORTS.find(([k]) => k === sheet).slice(1).join(' ')" @close="sheet = null">
          <div class="space-y-3">
            <video v-if="SUPPORT_HELP[sheet].video" :key="sheet" :src="SUPPORT_HELP[sheet].video" :poster="SUPPORT_HELP[sheet].poster" class="aspect-video w-full rounded-2xl bg-slate-900" controls playsinline preload="metadata" />
            <p class="rounded-xl bg-emerald-50 p-3 text-[15px] font-semibold text-emerald-900">{{ SUPPORT_HELP[sheet].why }}</p>
            <template v-if="sheet === 'pin'">
              <ol class="space-y-2 text-[15px]">
                <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-black text-white">1</span><span>Choisissez le format, puis <b>téléchargez</b> l'étiquette.</span></li>
                <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-slate-900 text-sm font-black text-white">2</span><span>Dans votre live TikTok, ouvrez le menu <b>Fond vert</b> et choisissez l'étiquette dans la galerie.</span></li>
              </ol>
              <LivePin :live="live" bare @shared="onPinSheetShared" />
            </template>
            <LiveScreenTabs v-else :id="id" screen-only :part="sheet" :no-phone="sheet === 'studio' && activeSupports.includes('tv')" @add="sheet = null; pane = 'live'" />
            <button v-if="sheet !== 'pin' && !activeSupports.includes(sheet)" type="button" class="btn-brand w-full justify-center py-3.5 text-base" @click="chooseSupport(sheet)">✓ Choisir {{ sheet === 'tv' ? 'la télé' : 'LIVE Studio' }}</button>
            <button v-if="activeSupports.includes(sheet) && activeSupports.length > 1" type="button" class="btn-ghost w-full justify-center text-red-600" @click="dropSupport(sheet)">Ne plus utiliser</button>
          </div>
        </Modal>

        <p class="pt-2 text-base font-extrabold">Infos du live</p>
        <div class="divide-y divide-slate-100 rounded-2xl bg-white px-4 text-sm">
          <p class="flex justify-between py-2.5"><span class="text-slate-600">Live</span><b class="truncate pl-3">{{ live.title }}</b></p>
          <p v-if="live.status === 'live'" class="flex justify-between py-2.5"><span class="text-slate-600">En direct depuis</span><b>{{ duration }}</b></p>
          <p class="flex justify-between py-2.5"><span class="text-slate-600">Articles</span><b>{{ live.items.length }}</b></p>
          <p class="flex justify-between py-2.5"><span class="text-slate-600">WhatsApp</span>
            <b v-if="live.whatsapp" class="text-emerald-700">✓ {{ live.whatsapp_phone || 'Connecté' }}</b>
            <RouterLink v-else to="/gestion/whatsapp?onglet=messages" class="font-bold text-amber-700 underline">Connecter</RouterLink>
          </p>
          <p class="flex items-center justify-between py-2.5"><span class="text-slate-600">Lien du live</span><button type="button" class="font-bold text-emerald-700" @click="share = true">📤 Partager</button></p>
        </div>
        <button v-if="live.status === 'live'" type="button" class="w-full rounded-2xl bg-slate-900 py-3 text-base font-extrabold text-white" :disabled="busy" @click="end">⏹ Terminer le live</button>
      </template>

      <!-- ═══ En-tête selon l'état ═══ -->
      <!-- Pendant le live : la pancarte montrée et les onglets Articles, Messages, Commandes -->
      <LiveCockpit v-if="live.status === 'live'" :live="live" :now="now" :busy="busy" :duration="duration" :pin-shared="pinShared" @pin-shared="onPinShared" @close="closeLive"
                   @feature="(itemId) => act('feature', { item_id: itemId })" @reload="load" @add="openAdding" @quick="quickOpen = true" @end="end" @share="share = true"
                   @flash="openFlash" @stop-flash="stopFlash" @big="big = $event" @cancel="(r) => act(`reservations/${r.id}/cancel`)" @mode="(m) => act('display', { mode: m })" @updated="live = $event" />

      <section v-else-if="live.status === 'draft' && pane === 'live'" class="overflow-hidden rounded-[24px] bg-live-grad p-5 text-white">
        <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />LIVE PRÊT</span>
        <h2 class="mt-2 text-xl font-extrabold">{{ live.title }}</h2>
        <p class="text-sm text-white/90">{{ live.items.length }} article{{ live.items.length > 1 ? 's' : '' }} · lancez le live au moment où vous passez à l'antenne.</p>
        <button type="button" class="mt-4 block w-full rounded-2xl bg-white py-3 text-center text-base font-extrabold text-[#c02a4e] disabled:opacity-60" :disabled="busy" @click="start">Lancer le live</button>
        <button type="button" class="mt-2 block w-full text-center text-sm text-white/90 underline" @click="share = true">Partager le lien avant de commencer</button>
      </section>

      <!-- Bilan du live terminé -->
      <template v-else-if="live.status === 'ended'">
        <!-- Commandes gardées actives après le live : jusqu'à quand, combien depuis, arrêter avant l'heure -->
        <section v-if="live.orders_until" class="rounded-[20px] border-2 border-amber-300 bg-amber-50 p-4">
          <p class="text-[13px] font-extrabold uppercase tracking-wide text-amber-800">● Live terminé · commandes actives</p>
          <h3 class="mt-1 text-[17px] font-black">⏳ Commandes actives jusqu'à {{ ordersUntil }}</h3>
          <p class="mt-1 text-[14px] text-slate-700">Les clients peuvent encore écrire A1, A2… sur WhatsApp. Ces commandes comptent dans ce live<template v-if="live.billing"> : retirées du solde quand les commandes s'arrêtent</template>.</p>
          <p class="mt-2 text-[14px]"><b>{{ live.orders_after ?? 0 }}</b> commande{{ (live.orders_after ?? 0) > 1 ? 's' : '' }} depuis la fin du live<template v-if="live.billing"> · <b>{{ live.billing.orders }}</b> en tout dans ce live</template></p>
          <button type="button" class="mt-3 block w-full rounded-2xl border-2 border-orange-200 bg-orange-50 py-2.5 font-extrabold text-orange-800 disabled:opacity-60" :disabled="busy" @click="stopOrders">Arrêter les commandes maintenant</button>
        </section>
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
        <!-- Solde : commandes de ce live retirées à la fermeture -->
        <section v-if="live.billing?.billed" class="rounded-[20px] border-2 border-amber-400 bg-amber-50 p-4">
          <h3 class="font-display text-base font-extrabold">🧾 {{ live.billing.used }} commande{{ live.billing.used > 1 ? 's' : '' }} retirée{{ live.billing.used > 1 ? 's' : '' }} de votre solde</h3>
          <p class="mt-1 text-xs text-slate-600">Une commande annulée ensuite reste comptée.</p>
          <p class="mt-2 flex justify-between border-t border-amber-200 pt-2 text-sm"><span>Solde après ce live</span><b :class="live.billing.balance < 0 ? 'text-red-700' : ''">{{ live.billing.balance }} commandes</b></p>
          <template v-if="live.billing.recharge">
            <p class="mt-1 text-sm font-bold text-orange-900">Il faut au moins {{ live.billing.min_orders }} commandes pour ouvrir votre prochain live.</p>
            <button type="button" class="mt-2 block w-full rounded-xl bg-slate-900 py-2.5 text-[15px] font-black text-white" @click="openRecharge()">Recharger</button>
          </template>
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
        <LiveBilan :id="live.id" />
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

      <!-- ═══ Articles (avant le live) ═══ -->
      <section v-if="live.status === 'draft' && pane === 'live'" class="space-y-2">
        <div class="flex items-center justify-between px-0.5">
          <h3 class="text-[17px] font-extrabold">Articles</h3>
          <button type="button" class="text-sm font-semibold text-emerald-700" @click="openAdding">+ Ajouter</button>
        </div>
        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <div v-for="i in live.items" :key="i.id" class="card flex min-w-0 items-center gap-3 p-3" :class="live.featured_item_id === i.id ? 'border-2 border-ocre-600 ring-4 ring-ocre-100' : ''">
            <button type="button" class="grid h-14 w-14 shrink-0 place-items-center rounded-2xl text-xl font-black text-white" :class="i.remaining ? 'bg-red-600' : 'bg-slate-300'" :aria-label="`Afficher ${i.code} en grand`" @click="big = i">{{ i.code }}</button>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold" :title="i.title"><span v-if="i.mystery && !i.revealed" class="mr-1 rounded bg-violet-100 px-1 text-xs font-bold text-violet-700">🎁 Mystère</span>{{ i.title }}</p>
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
        <p v-if="live.status === 'live'" class="px-0.5 text-sm text-slate-500">Touchez un code pour l'afficher en grand.</p>
        <!-- Article mystère : caché des clientes jusqu'à sa révélation pendant le live -->
        <details class="card p-4" :open="live.items.some((i) => i.mystery)">
          <summary class="cursor-pointer font-extrabold">🎁 Article mystère <span class="font-normal text-slate-500">· optionnel</span></summary>
          <div class="mt-2"><LiveMysteryEditor :live="live" @saved="live = $event" /></div>
        </details>
      </section>


      <!-- Avant de lancer -->
      <section v-if="live.status === 'draft' && pane === 'live'" class="card space-y-2 p-4 text-sm">
        <p class="font-semibold">Avant de lancer le live <span class="font-normal text-slate-500">· {{ ready.length }} / {{ BEFORE_LIVE.length }}</span></p>
        <label v-for="c in BEFORE_LIVE" :key="c" class="flex items-start gap-2">
          <input v-model="ready" type="checkbox" :value="c" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span :class="{ 'text-slate-400 line-through': ready.includes(c) }">{{ c }}</span>
        </label>
      </section>
      <LiveWhatsApp v-if="live.status === 'draft' && pane === 'live' && live.whatsapp === null" />


      <!-- ═══ Clientes ═══ -->
      <section v-if="live.status === 'ended' ? showAll : live.status === 'draft' && pane === 'live' && clients.length" id="live-clientes" class="card scroll-mt-48 p-4">
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

      <!-- Réservation vue dans les commentaires (onglet Commandes) -->
      <template v-if="live.status === 'live'">
        <form v-if="quickOpen" class="fixed inset-x-0 bottom-[76px] z-30 rounded-t-3xl border-t border-slate-200 bg-white p-4 shadow-2xl lg:right-6 lg:bottom-24 lg:left-auto lg:w-96 lg:rounded-3xl lg:border" @submit.prevent="addQuick">
          <div class="flex items-center gap-2"><p class="flex-1 font-extrabold">Réservation vue dans les commentaires</p><button type="button" class="btn-ghost px-2 py-1" aria-label="Fermer" @click="quickOpen = false"><Icon name="close" :size="18" /></button></div>
          <div class="mt-3 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Code de l'article">
            <button v-for="i in live.items" :key="i.id" type="button" role="radio" :aria-checked="quick.code === i.code" class="rounded-xl border-2 px-3 py-2 text-sm font-black"
                    :class="quick.code === i.code ? 'border-slate-900 bg-slate-900 text-white' : i.remaining ? 'border-slate-200' : 'border-slate-200 text-slate-400'" @click="quick.code = i.code">{{ i.code }}</button>
          </div>
          <input v-model="quick.name" class="input mt-3" placeholder="Pseudo ou nom" maxlength="120" />
          <input v-model="quick.phone" class="input mt-2" type="tel" placeholder="Numéro WhatsApp (facultatif)" />
          <p class="mt-1 text-xs text-slate-500">Avec un numéro, la cliente reçoit un WhatsApp qui lui demande son quartier.</p>
          <button class="btn-brand mt-3 w-full" :disabled="busy || !quick.code || (!quick.name && !quick.phone)">{{ quick.code ? `Réserver ${quick.code}` : 'Choisissez un code' }}</button>
        </form>
      </template>
    </template>

    <!-- Ajouter des articles -->
    <EndLiveSheet v-if="ending" :busy="busy" @close="ending = false" @confirm="confirmEnd" />
    <Modal v-if="adding" :title="live?.status === 'live' ? 'Ajouter des articles pendant le live' : 'Ajouter des articles'" @close="closeAdding">
      <div class="space-y-3 text-sm">
        <p class="text-sm text-slate-600">Un nouvel article prend le code suivant. Les codes annoncés ne changent pas.</p>
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
        <p class="text-sm text-slate-600">{{ flashing.title }} · prix du live <strong>{{ money(flashing.price) }}</strong>. Pendant le chrono, {{ flashing.code }} est à ce prix.</p>
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
        <p v-if="live.whatsapp === 'official'" class="rounded-xl bg-emerald-50 p-3 text-emerald-900"><b class="block">✓ WhatsApp connecté</b>Les codes des clientes sont lus tout seuls.</p>
        <p v-else-if="live.whatsapp === 'link'" class="rounded-xl bg-amber-50 p-3 text-amber-900">Passez au WhatsApp officiel, sans risque de blocage. <RouterLink to="/gestion/whatsapp?onglet=messages" class="font-medium underline">Connecter le WhatsApp officiel</RouterLink></p>
        <p v-else class="rounded-xl bg-amber-50 p-3 text-amber-900">WhatsApp pas connecté : réservation par le lien seulement. <RouterLink to="/gestion/whatsapp?onglet=messages" class="font-medium underline">Connecter le WhatsApp officiel</RouterLink></p>
        <p class="text-slate-600">📷 La capture envoyée sur WhatsApp réserve l'article. Plusieurs pièces : « A2 x3 ».</p>
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
  </div>
  </Teleport>
</template>
