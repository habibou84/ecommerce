<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money, phoneDisplay } from '../lib/format'
import LiveDisplay from './LiveDisplay.vue'
import LiveOverlay from './LiveOverlay.vue'
import LivePin from './LivePin.vue'
import LiveScreenTabs from './LiveScreenTabs.vue'
import Modal from './Modal.vue'
import { pinChanges, pinCodes, pinSignature } from '../lib/livePin'
import { useAuthStore } from '../stores/auth'

/**
 * Écran de la vendeuse pendant le live : en-tête « en direct » (durée, montant, menu ⋯), puis trois onglets.
 * - Live : sous-menus Télé, TikTok Studio et Étiquette. Télé et Studio montrent en grand ce que voient les clientes
 *   (avec « connecté » ou un bandeau dépliable pour connecter), le mode d'affichage, les articles à toucher et
 *   Précédent / Suivant ; l'Étiquette dit ce qui a changé depuis la dernière épinglée et propose la nouvelle.
 * - Commandes, Messages.
 */
const props = defineProps({
  live: { type: Object, required: true },
  now: { type: Number, required: true },
  busy: { type: Boolean, default: false },
  duration: { type: String, default: '' },
  // Dernière étiquette partagée : { sig (codes), at }
  pinShared: { type: Object, default: null },
})
const emit = defineEmits(['feature', 'reload', 'add', 'quick', 'end', 'share', 'flash', 'stop-flash', 'big', 'cancel', 'mode', 'pin-shared'])
// Ce que montrent les écrans du live (télé, OBS, LIVE Studio) : un article, défilement, comment commander
const MODES = [['product', '📦 Un article'], ['scroll', '🔁 Défilement'], ['grid', '🧩 Vitrine'], ['how', '🛒 Commander']]
const screenMode = computed(() => props.live.display_mode || 'product')

const auth = useAuthStore()
// Cartes des articles (photo + code) à afficher à l'écran, sauf si la console les masque
const cardsOn = computed(() => auth.profile?.features?.live_screens !== false)

// ── Sous-menus du Live : Télé, TikTok Studio, Étiquette (gardé sur cet appareil) ──
const viewKey = `jm.live-view.${props.live.id}`
const VIEWS = computed(() => (cardsOn.value ? [['tv', '📺 Télé'], ['studio', '📱 TikTok Studio'], ['pin', '📌 Étiquette']] : [['pin', '📌 Étiquette']]))
const view = ref((() => {
  try {
    return localStorage.getItem(viewKey) || 'tv'
  } catch {
    return 'tv'
  }
})())
watch(view, (v) => {
  try {
    localStorage.setItem(viewKey, v)
  } catch {
    // sans stockage : le choix vaut pour cette visite
  }
})
const shownView = computed(() => (VIEWS.value.some(([k]) => k === view.value) ? view.value : VIEWS.value[0][0]))

// Ce que montrent la télé et LIVE Studio, en direct
const display = ref(null)
async function loadDisplay() {
  if (!cardsOn.value) return
  try {
    display.value = (await http.get(`/lives/${props.live.id}/display`)).data.data
  } catch {
    // lecture suivante
  }
}
// Télé et LIVE Studio : connectés quand leur page est ouverte (elle se signale toute seule)
const screens = ref(null)
async function loadScreens() {
  if (!cardsOn.value) return
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch {
    // lecture suivante
  }
}
const tvOnline = computed(() => !!screens.value?.some((s) => !s.is_link && s.online))
const studioOnline = computed(() => !!screens.value?.some((s) => s.is_link && s.online))
// Bandeau « Connecter » déplié
const connecting = ref(false)
watch(shownView, () => (connecting.value = false))
watch([tvOnline, studioOnline], () => (connecting.value = false))
let liveTimer
onMounted(() => {
  loadDisplay()
  loadScreens()
  liveTimer = setInterval(() => {
    if (document.hidden) return
    if (tab.value === 'live' && shownView.value !== 'pin') loadDisplay()
    loadScreens()
  }, 3000)
})
onUnmounted(() => clearInterval(liveTimer))
watch(() => [props.live.display_mode, props.live.featured_item_id], loadDisplay)

// Étiquette : à refaire quand un article est fini ou ajouté depuis la dernière partagée
const pinStale = computed(() => props.pinShared?.sig !== pinSignature(props.live.items))
const changes = computed(() => pinChanges(props.live.items, props.pinShared))
const pinCount = computed(() => pinCodes(props.live.items).length)
const pinTime = computed(() => (props.pinShared?.at ? new Date(props.pinShared.at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : null))
const viewState = (key) => (key === 'pin' ? !pinStale.value : key === 'tv' ? tvOnline.value : studioOnline.value)

// Menu ⋯ : partager, ajouter, réservation vue, terminer
const menu = ref(false)

// ── Un produit = une pancarte (ses tailles et couleurs la partagent), dans l'ordre de la liste ──
const products = computed(() => {
  const groups = new Map()
  for (const i of props.live.items) {
    const g = groups.get(i.code) ?? { code: i.code, title: i.product ?? i.title, image: i.image, items: [], quantity: 0, remaining: 0, waiting: 0 }
    g.items.push(i)
    g.quantity += i.quantity
    g.remaining += i.remaining
    g.waiting += i.waiting
    groups.set(i.code, g)
  }
  return [...groups.values()].map((g) => ({
    ...g,
    id: g.items[0].id,
    price: Math.min(...g.items.map((i) => i.price)),
    flash: g.items.find((i) => i.flash) ?? null,
    sizes: g.items.filter((i) => i.variant).map((i) => ({ name: i.variant, remaining: i.remaining })),
    sold: g.quantity - g.remaining,
  }))
})
const byCode = computed(() => Object.fromEntries(products.value.map((p) => [p.code, p])))
const currentIndex = computed(() => products.value.findIndex((p) => p.items.some((i) => i.id === props.live.featured_item_id)))
const current = computed(() => products.value[currentIndex.value] ?? null)
// Suivante : la prochaine pancarte dans l'ordre qui a encore des pièces (sinon la suivante tout court)
const next = computed(() => {
  const after = products.value.slice(currentIndex.value + 1)
  return after.find((p) => p.remaining > 0) ?? after[0] ?? null
})
const previous = computed(() => (currentIndex.value > 0 ? products.value[currentIndex.value - 1] : null))
const show = (p) => p && current.value?.code !== p.code && emit('feature', p.id)

function chrono(item) {
  const s = Math.max(0, Math.round((new Date(item.flash.ends_at).getTime() - props.now) / 1000))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

// ── Onglets ──
const tab = ref('live')
const soldPieces = computed(() => products.value.reduce((sum, p) => sum + p.sold, 0))
// Bientôt épuisé : badge rouge sur l'article
const almostGone = (p) => p.remaining > 0 && (p.remaining <= 2 || p.remaining / p.quantity <= 0.25)

// Messages : code lu (ou capture lue), question sans réponse
const messageFilter = ref('all')
// « LIVE » : la liste des articles est partie toute seule, ce n'est pas une question
const isQuestion = (m) => m.type === 'text' && !m.list && !m.codes.length && !m.answered
// À traiter : questions sans réponse et quartiers pas reconnus (la vendeuse choisit le quartier)
const needsAction = (m) => isQuestion(m) || (m.place_check && m.reservation_id && props.live.reservations.some((r) => r.id === m.reservation_id && r.status === 'reserved'))
const questions = computed(() => (props.live.messages ?? []).filter(needsAction))
const messages = computed(() => (messageFilter.value === 'todo' ? questions.value : props.live.messages ?? []))
function messageState(m) {
  if (m.list) return 'LISTE ENVOYÉE'
  if (m.place_check) return needsAction(m) ? 'QUARTIER À VÉRIFIER' : null
  if (!m.codes.length) return m.type === 'image' ? 'CAPTURE' : null
  const r = props.live.reservations.find((x) => x.customer_phone === m.phone && m.codes.includes(x.code) && x.status !== 'cancelled')
  return { ordered: 'COMMANDÉ', reserved: 'QUARTIER ?', waiting: 'EN ATTENTE', expired: 'LIBÉRÉ' }[r?.status] ?? 'LU'
}

// Commandes : réservations vivantes, les plus récentes d'abord
// Commandes : réservations vivantes, et les commandes annulées par la cliente (effacées)
const orders = computed(() => props.live.reservations.filter((r) => !['cancelled', 'expired'].includes(r.status) || r.order?.status === 'cancelled'))
const ORDER_STATE = { pending_confirmation: 'à confirmer', confirmed: 'confirmée ✓', cancelled: 'annulée par la cliente' }
const keptCount = computed(() => orders.value.filter((r) => r.status === 'reserved').length)
const itemOf = (r) => props.live.items.find((i) => i.id === r.item_id)
function minutesLeft(r) {
  return r.expires_at ? Math.max(0, Math.round((new Date(r.expires_at).getTime() - props.now) / 60000)) : null
}
const seenOrder = ref(0)
const newOrders = computed(() => orders.value.filter((r) => r.id > seenOrder.value).length)
watch(tab, (t) => t === 'commandes' && (seenOrder.value = Math.max(0, ...orders.value.map((r) => r.id))))

// Une vibration à chaque nouvelle réservation (pas au premier chargement)
// ── Nouvelle commande : bannière en grand, petit son et vibration ──
const toasts = ref([])
let toastTimer
function firstName(r) {
  const name = String(r.customer_name || '').replace('@', '').trim()
  return name ? name.split(/\s+/)[0] : ''
}
function chime() {
  try {
    const audio = new (window.AudioContext || window.webkitAudioContext)()
    ;[880, 1320].forEach((freq, i) => {
      const osc = audio.createOscillator()
      const gain = audio.createGain()
      osc.frequency.value = freq
      gain.gain.setValueAtTime(0.15, audio.currentTime + i * 0.15)
      gain.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + i * 0.15 + 0.3)
      osc.connect(gain).connect(audio.destination)
      osc.start(audio.currentTime + i * 0.15)
      osc.stop(audio.currentTime + i * 0.15 + 0.3)
    })
  } catch {
    // pas de son possible : la vibration et la bannière suffisent
  }
}
function announce(r) {
  const item = props.live.items.find((i) => i.id === r.item_id)
  toasts.value.push({ id: r.id, name: r.customer_name || phoneDisplay(r.customer_phone) || 'Une cliente', first: firstName(r), code: r.code, variant: item?.variant, image: byCode.value[r.code]?.image })
  chime()
  showNext()
}
function showNext() {
  clearTimeout(toastTimer)
  if (toasts.value.length) toastTimer = setTimeout(() => { toasts.value.shift(); showNext() }, 7000)
}
function dismiss() {
  toasts.value.shift()
  showNext()
}
onUnmounted(() => clearTimeout(toastTimer))

let lastId = null
watch(() => Math.max(0, ...props.live.reservations.map((r) => r.id)), (id) => {
  if (lastId !== null && id > lastId) {
    navigator.vibrate?.([120, 60, 120])
    // Une bannière par nouvelle commande : la vendeuse la félicite à voix haute, par son prénom
    props.live.reservations.filter((r) => r.id > lastId && ['reserved', 'ordered'].includes(r.status)).sort((a, b) => a.id - b.id).forEach(announce)
  }
  // Au premier chargement, rien n'est « nouveau » : seules les réservations arrivées ensuite comptent
  if (lastId === null || tab.value === 'commandes') seenOrder.value = id
  lastId = id
}, { immediate: true })

// Écran allumé pendant tout le live
let lock = null
async function keepAwake() {
  try {
    if (!document.hidden && navigator.wakeLock) lock = await navigator.wakeLock.request('screen')
  } catch {
    lock = null
  }
}
onMounted(() => {
  keepAwake()
  document.addEventListener('visibilitychange', keepAwake)
})
onUnmounted(() => {
  document.removeEventListener('visibilitychange', keepAwake)
  lock?.release?.()
})

// Réponse rapide à une question, sans quitter le live
const QUICK = ['Oui, c\'est disponible.', 'Non, désolée, pas pour ce modèle.', 'Écrivez le code de la pancarte et votre taille, je vous le garde.']
const replying = reactive({ id: null, body: '', busy: false, error: '' })
function openReply(m) {
  Object.assign(replying, { id: m.id, body: '', busy: false, error: '' })
}
async function sendReply(m, body = replying.body) {
  if (!body.trim()) return
  replying.busy = true
  replying.error = ''
  try {
    await http.post(`/conversations/${m.conversation_id}/messages`, { body: body.trim() })
    Object.assign(replying, { id: null, body: '' })
    emit('reload')
  } catch (e) {
    replying.error = errorMessage(e)
  } finally {
    replying.busy = false
  }
}
// Quartier choisi par la vendeuse quand la réponse de la cliente n'a pas été reconnue
const placing = reactive({ reservation: null, name: '', search: '', results: [], address: '', busy: false, error: '' })
let placeTimer
function openPlace(reservationId, name, text = '') {
  Object.assign(placing, { reservation: reservationId, name, search: '', results: [], address: text, busy: false, error: '' })
}
function findPlaces() {
  clearTimeout(placeTimer)
  placeTimer = setTimeout(async () => {
    placing.results = placing.search.trim().length < 2 ? [] : (await http.get('/places', { params: { search: placing.search.trim() } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}
async function choosePlace(place) {
  placing.busy = true
  placing.error = ''
  try {
    await http.post(`/lives/${props.live.id}/reservations/${placing.reservation}/place`, { place_id: place.id, address: placing.address.trim() ? `${place.label}, ${placing.address.trim()}`.slice(0, 255) : null })
    placing.reservation = null
    emit('reload')
  } catch (e) {
    placing.error = errorMessage(e)
  } finally {
    placing.busy = false
  }
}
const ago = (iso) => {
  const min = Math.max(0, Math.round((props.now - new Date(iso).getTime()) / 60000))
  return min < 1 ? 'maint.' : min < 60 ? `${min} min` : `${Math.floor(min / 60)} h`
}
</script>

<template>
  <div class="space-y-2">
    <!-- Nouvelle commande : à annoncer en direct, avec le prénom -->
    <Teleport to="body">
      <button v-if="toasts.length" type="button" class="fixed inset-x-3 top-3 z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-slate-900 p-3 text-left text-white shadow-2xl" aria-live="polite" @click="dismiss">
        <span class="relative h-14 w-14 shrink-0">
          <img v-if="toasts[0].image" :src="toasts[0].image" alt="" class="h-14 w-14 rounded-xl object-cover" />
          <span class="absolute -bottom-1 -left-1 rounded border-2 border-slate-900 bg-white px-1 text-xs font-black text-slate-900">{{ toasts[0].code }}</span>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-[11px] font-extrabold tracking-wider text-red-400">🎉 NOUVELLE COMMANDE<template v-if="toasts.length > 1"> · +{{ toasts.length - 1 }}</template></span>
          <span class="block truncate text-lg font-black leading-tight">{{ toasts[0].name }}</span>
          <span class="block truncate text-xs text-white/80">{{ toasts[0].code }}<template v-if="toasts[0].variant"> · {{ toasts[0].variant }}</template></span>
          <span class="mt-1 block text-[13px] font-bold">🗣 « Merci {{ toasts[0].first || 'à toi' }} ! Bien reçu pour {{ toasts[0].code }} ! »</span>
        </span>
      </button>
    </Teleport>
    <!-- En direct : durée, montant, menu ⋯ -->
    <div class="flex items-center gap-2 px-0.5">
      <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-red-600" aria-hidden="true" />
      <b class="text-[15px] font-black">EN DIRECT</b>
      <span class="text-xs font-bold text-slate-500">{{ duration }}</span>
      <b class="ml-auto text-lg font-black">{{ money(live.summary?.amount ?? 0) }}</b>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-900 text-lg font-black text-white" aria-label="Plus" :aria-expanded="menu" @click="menu = !menu">⋯</button>
    </div>
    <div v-if="menu" class="grid grid-cols-2 gap-1.5 rounded-2xl bg-white p-2 text-sm font-extrabold">
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('share')">📤 Lien du live</button>
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('add')">＋ Ajouter un article</button>
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('quick')">✍️ Réservation vue</button>
      <RouterLink v-if="live.whatsapp" to="/gestion/whatsapp" class="rounded-xl bg-emerald-50 py-2.5 text-center text-emerald-800">✓ {{ live.whatsapp_phone || 'WhatsApp' }}</RouterLink>
      <RouterLink v-else to="/gestion/whatsapp?onglet=messages" class="rounded-xl bg-amber-50 py-2.5 text-center text-amber-800">WhatsApp à connecter</RouterLink>
      <button type="button" class="col-span-2 rounded-xl bg-slate-900 py-2.5 text-white" :disabled="busy" @click="menu = false; emit('end')">⏹ Terminer le live</button>
    </div>

    <!-- Live, Commandes, Messages -->
    <nav class="sticky top-2 z-20 grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-white text-[14px] font-extrabold" role="tablist">
      <button v-for="[key, label, count] in [['live', '🔴 Live', 0], ['commandes', 'Commandes', newOrders], ['messages', 'Messages', questions.length]]" :key="key" type="button" role="tab" :aria-selected="tab === key"
              class="relative py-2.5" :class="tab === key ? 'bg-slate-900 text-white' : 'text-slate-500'" @click="tab = key">
        {{ label }}<span v-if="count" class="ml-1 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ count }}</span>
      </button>
    </nav>

    <!-- ═══ Live : Télé, TikTok Studio, Étiquette ═══ -->
    <section v-if="tab === 'live'" class="space-y-2.5 rounded-[22px] bg-[#0b1120] p-3 text-white">
      <div class="grid gap-1 rounded-xl bg-white/10 p-1" :style="{ gridTemplateColumns: `repeat(${VIEWS.length}, minmax(0, 1fr))` }" role="tablist" aria-label="Où regarder ?">
        <button v-for="[key, label] in VIEWS" :key="key" type="button" role="tab" :aria-selected="shownView === key"
                class="relative rounded-lg py-2 text-[12.5px] font-extrabold" :class="shownView === key ? 'bg-white text-slate-900' : 'text-slate-300'" @click="view = key">
          {{ label }}<span class="absolute top-1 right-1 h-2 w-2 rounded-full" :class="viewState(key) ? 'bg-emerald-400' : key === 'pin' ? 'bg-amber-400' : 'bg-red-500'" />
        </button>
      </div>

      <!-- Télé ou TikTok Studio : connecté, ou bandeau à déplier pour connecter -->
      <template v-if="shownView !== 'pin'">
        <p v-if="shownView === 'tv' ? tvOnline : studioOnline" class="flex items-center gap-2 rounded-xl bg-emerald-500/15 px-3 py-2 text-[13px] font-extrabold text-emerald-300">
          ✓ {{ shownView === 'tv' ? 'Télé connectée : les clientes voient vos articles en temps réel' : 'TikTok Studio connecté : la carte de l\'article est sur votre live' }}
        </p>
        <div v-else class="overflow-hidden rounded-xl bg-red-500/15 ring-1 ring-red-400/50">
          <button type="button" class="flex w-full items-center gap-2 px-3 py-2.5 text-left" :aria-expanded="connecting" @click="connecting = !connecting">
            <span class="min-w-0 flex-1">
              <b class="block text-[14px]">{{ shownView === 'tv' ? '📺 Connecter la télé' : '📱 Connecter TikTok Studio' }}</b>
              <span class="block text-[12.5px] text-red-100">{{ shownView === 'tv' ? 'Pour afficher vos articles en grand derrière vous, en temps réel.' : 'Pour mettre la carte de l\'article en haut de votre live TikTok.' }}</span>
            </span>
            <span class="shrink-0 text-lg">{{ connecting ? '▴' : '▾' }}</span>
          </button>
          <div v-if="connecting" class="bg-white p-3 text-slate-900">
            <LiveScreenTabs :id="live.id" screen-only :part="shownView" :no-phone="shownView === 'studio'" @add="connecting = false" />
          </div>
        </div>

        <!-- Ce que voient les clientes -->
        <div v-if="shownView === 'tv'" class="aspect-video overflow-hidden rounded-xl bg-black"><LiveDisplay v-if="display" :data="display" /></div>
        <div v-else class="flex justify-center">
          <div class="relative h-[min(46vh,340px)] overflow-hidden rounded-[18px] border-[3px] border-slate-600 bg-gradient-to-b from-amber-800 to-stone-900" style="aspect-ratio: 9 / 16">
            <span class="absolute bottom-4 left-1/2 -translate-x-1/2 text-[72px] leading-none" aria-hidden="true">🧕🏾</span>
            <div class="absolute top-[3%] left-[3%] w-[55%]" style="aspect-ratio: 480 / 780"><LiveOverlay v-if="display" :data="display" format="carte" /></div>
          </div>
        </div>

        <div class="grid grid-cols-4 gap-1.5" role="radiogroup" aria-label="Que montrer à l'écran ?">
          <button v-for="[value, label] in MODES" :key="value" type="button" role="radio" :aria-checked="screenMode === value" :disabled="busy"
                  class="rounded-lg px-1 py-2 text-[11.5px] font-extrabold" :class="screenMode === value ? 'bg-white text-slate-900' : 'bg-white/10 text-slate-300'" @click="emit('mode', value)">{{ label }}</button>
        </div>
      </template>

      <!-- Étiquette à épingler : à jour, ou ce qui a changé et la nouvelle -->
      <template v-else>
        <div v-if="pinStale" class="rounded-xl bg-amber-500/15 p-3 ring-1 ring-amber-400/60">
          <b class="block text-[14px] text-amber-200">⚠ {{ pinShared ? 'Étiquette à refaire' : 'Étiquette pas encore épinglée' }}</b>
          <template v-if="changes.length">
            <span class="block text-[12.5px] text-amber-100">Depuis la dernière<template v-if="pinTime"> ({{ pinTime }})</template> :</span>
            <span v-for="c in changes" :key="c" class="block text-[13px] font-bold">{{ c }}</span>
          </template>
          <span class="mt-1 block text-[12.5px] text-amber-100">Voici la nouvelle : partagez-la, puis dans TikTok ouvrez <b>Fond vert</b> et épinglez-la sur le live.</span>
        </div>
        <p v-else class="rounded-xl bg-emerald-500/15 px-3 py-2 text-[13px] font-extrabold text-emerald-300">✓ Étiquette à jour · {{ pinCount }} article{{ pinCount > 1 ? 's' : '' }}<template v-if="pinTime"> · épinglée à {{ pinTime }}</template></p>
        <div class="rounded-xl bg-white p-3 text-slate-900"><LivePin :live="live" bare @shared="(sig) => emit('pin-shared', sig)" /></div>
      </template>

      <!-- Les articles à toucher pour les montrer (Télé et Studio, ou sans écrans) -->
      <template v-if="shownView !== 'pin' || !cardsOn">
        <p class="pt-1 text-[11px] font-extrabold tracking-wider text-slate-400 uppercase">Touchez un article pour le montrer · {{ soldPieces }} pièce{{ soldPieces > 1 ? 's' : '' }} vendue{{ soldPieces > 1 ? 's' : '' }}</p>
        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
          <button v-for="p in products" :key="p.code" type="button" :disabled="busy"
                  class="relative overflow-hidden rounded-xl border-[3px] bg-white text-left text-slate-900" :class="current?.code === p.code ? 'border-red-600' : 'border-transparent'" @click="current?.code === p.code ? (screenMode !== 'product' && emit('mode', 'product')) : show(p)">
            <span class="block h-16 bg-slate-100" :class="{ 'opacity-40 grayscale': !p.remaining }"><img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy" /></span>
            <span class="absolute top-1 left-1 rounded-md bg-slate-900 px-1.5 text-xs font-black text-white">{{ p.code }}</span>
            <span v-if="!p.remaining" class="absolute inset-x-0 top-0 grid h-16 place-items-center text-sm font-black text-red-600">VENDU</span>
            <span v-else-if="almostGone(p)" class="absolute top-1 right-1 rounded-full bg-red-600 px-1.5 text-[9px] font-black text-white">BIENTÔT</span>
            <span class="block truncate px-1.5 pt-0.5 text-[11px] font-bold">{{ p.title }}</span>
            <span class="block px-1.5 pb-1 text-[10.5px] text-slate-500"><b class="text-slate-900">{{ p.remaining }}</b> / {{ p.quantity }}<template v-if="p.waiting"> · {{ p.waiting }} att.</template></span>
          </button>
          <button type="button" class="grid min-h-24 place-items-center rounded-xl border-2 border-dashed border-white/30 text-xs font-extrabold text-slate-300" @click="emit('add')">＋ Ajouter</button>
        </div>
        <div v-if="current && current.remaining">
          <button v-if="current.flash" type="button" class="w-full rounded-xl bg-red-600 py-2.5 text-sm font-extrabold" :disabled="busy" @click="emit('stop-flash', current.flash)">⚡ {{ money(current.price) }} · {{ chrono(current.flash) }} · Arrêter</button>
          <button v-else type="button" class="w-full rounded-xl bg-amber-400 py-2.5 text-sm font-extrabold text-slate-900" @click="emit('flash', current.items[0])">⚡ Prix live sur {{ current.code }}</button>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="rounded-xl border border-white/25 bg-white/10 py-3.5 text-base font-extrabold" :disabled="busy || !previous" @click="show(previous)">◀ Précédent</button>
          <button type="button" class="rounded-xl bg-red-600 py-3.5 text-base font-extrabold disabled:opacity-50" :disabled="busy || !(current ? next : products[0])" @click="show(current ? next : products[0])">{{ current ? 'Suivant' : 'Commencer' }} ▶</button>
        </div>
      </template>
    </section>

    <!-- ═══ Messages ═══ -->
    <section v-else-if="tab === 'messages'" class="space-y-1.5">
      <div class="flex items-center gap-1.5 text-xs font-extrabold">
        <button v-for="[key, label] in [['all', 'Tous'], ['todo', `À répondre · ${questions.length}`]]" :key="key" type="button" :aria-pressed="messageFilter === key"
                class="rounded-full border-[1.5px] px-3 py-1" :class="messageFilter === key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white'" @click="messageFilter = key">{{ label }}</button>
      </div>
      <p v-if="live.whatsapp === null" class="rounded-xl bg-white p-3 text-sm text-slate-600">Votre WhatsApp n'est pas connecté : les messages des clientes n'arrivent pas ici. <RouterLink to="/gestion/whatsapp" class="font-bold underline">Connecter WhatsApp</RouterLink></p>
      <p v-else-if="!messages.length" class="rounded-xl bg-white p-4 text-center text-sm text-slate-500">{{ messageFilter === 'todo' ? 'Aucune question en attente ✓' : 'Les messages des clientes s\'afficheront ici.' }}</p>
      <article v-for="m in messages" :key="m.id" class="rounded-xl bg-white p-2" :class="needsAction(m) ? 'shadow-[inset_4px_0_0_#dc2626]' : ''">
        <div class="flex items-center gap-2.5">
          <span v-if="m.codes.length && byCode[m.codes[0]]" class="relative h-10 w-10 shrink-0">
            <img v-if="byCode[m.codes[0]].image" :src="byCode[m.codes[0]].image" alt="" class="h-10 w-10 rounded-lg object-cover" />
            <span class="absolute -bottom-1 -left-1 rounded border-2 border-slate-900 bg-white px-0.5 text-[10px] leading-tight font-black">{{ m.codes[0] }}</span>
          </span>
          <span v-else class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-lg">{{ m.type === 'image' ? '📷' : '💬' }}</span>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] font-extrabold"><span v-if="needsAction(m)" class="text-red-600">● </span>{{ m.name }}</p>
            <p class="text-xs break-words text-slate-600">
              {{ m.type === 'image' ? '📷 capture' + (m.codes.length ? ` · lue : ${m.codes.join(', ')}` : '') : `« ${m.body} »` }}
              <span v-if="messageState(m)" class="ml-1 inline-block rounded-full border-[1.5px] border-slate-900 px-1.5 text-[10px] font-black" :class="messageState(m) === 'COMMANDÉ' ? 'bg-slate-900 text-white' : ''">{{ messageState(m) }}</span>
            </p>
          </div>
          <span class="shrink-0 self-start text-[10px] text-slate-400">{{ ago(m.created_at) }}</span>
        </div>
        <div v-if="m.place_check && needsAction(m)" class="mt-1.5 pl-12">
          <button type="button" class="rounded-lg bg-slate-900 px-3 py-1 text-xs font-extrabold text-white" @click="openPlace(m.reservation_id, m.name, m.body)">Choisir le quartier</button>
        </div>
        <div v-if="isQuestion(m)" class="mt-1.5 pl-12">
          <button v-if="replying.id !== m.id" type="button" class="rounded-lg bg-slate-900 px-3 py-1 text-xs font-extrabold text-white" @click="openReply(m)">Répondre</button>
          <form v-else class="space-y-1.5" @submit.prevent="sendReply(m)">
            <div class="flex flex-wrap gap-1">
              <button v-for="q in QUICK" :key="q" type="button" class="rounded-lg border-[1.5px] border-slate-300 bg-white px-2 py-1 text-left text-[11px] font-bold" :disabled="replying.busy" @click="sendReply(m, q)">{{ q }}</button>
            </div>
            <div class="flex gap-1.5">
              <input v-model="replying.body" class="input min-w-0 flex-1 py-1.5 text-sm" placeholder="Votre réponse…" maxlength="1000" />
              <button class="rounded-lg bg-slate-900 px-3 text-xs font-extrabold text-white disabled:opacity-50" :disabled="replying.busy || !replying.body.trim()">Envoyer</button>
            </div>
            <p v-if="replying.error" class="text-xs text-red-600">{{ replying.error }}</p>
          </form>
        </div>
      </article>
    </section>

    <!-- ═══ Commandes ═══ -->
    <section v-else class="space-y-1.5">
      <div class="grid grid-cols-3 gap-1 rounded-xl bg-white p-2 text-center">
        <span><b class="block text-lg font-black">{{ live.summary.orders }}</b><span class="text-[10px] font-bold text-slate-500">commande{{ live.summary.orders > 1 ? 's' : '' }}</span></span>
        <span><b class="block text-lg font-black">{{ money(live.summary.amount) }}</b><span class="text-[10px] font-bold text-slate-500">montant</span></span>
        <span><b class="block text-lg font-black">{{ keptCount }}</b><span class="text-[10px] font-bold text-slate-500">quartier{{ keptCount > 1 ? 's' : '' }} attendu{{ keptCount > 1 ? 's' : '' }}</span></span>
      </div>
      <button type="button" class="w-full rounded-xl border-[1.5px] border-slate-900 bg-white py-2 text-sm font-extrabold" @click="emit('quick')">+ Réservation vue dans les commentaires</button>
      <p v-if="!orders.length" class="rounded-xl bg-white p-4 text-center text-sm text-slate-500">Les commandes s'afficheront ici dès les premières.</p>
      <article v-for="r in orders" :key="r.id" class="flex items-center gap-2.5 rounded-xl bg-white p-2" :class="{ 'opacity-55': r.status === 'waiting' || r.order?.status === 'cancelled' }">
        <span class="relative h-10 w-10 shrink-0">
          <img v-if="byCode[r.code]?.image" :src="byCode[r.code].image" alt="" class="h-10 w-10 rounded-lg object-cover" :class="{ grayscale: r.status === 'waiting' }" />
          <span v-else class="block h-10 w-10 rounded-lg bg-slate-100" />
          <span class="absolute -bottom-1 -left-1 rounded border-2 border-slate-900 bg-white px-0.5 text-[10px] leading-tight font-black">{{ r.code }}</span>
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-[13px] font-extrabold">
            {{ r.customer_name || phoneDisplay(r.customer_phone) || 'Cliente' }}
            <template v-if="r.order"> · {{ money(r.unit_price) }} · {{ ORDER_STATE[r.order.status] ?? 'commande créée' }}</template>
            <template v-else-if="r.status === 'reserved'"> · gardée{{ minutesLeft(r) ? ` ${minutesLeft(r)} min` : '' }} ⏳</template>
            <template v-else> · liste d'attente</template>
          </p>
          <p class="truncate text-xs text-slate-600">
            <template v-if="itemOf(r)?.variant">Taille {{ itemOf(r).variant }} · </template>
            <template v-if="r.order">{{ r.shipping_address || 'commande créée' }}</template>
            <template v-else-if="r.status === 'reserved'">quartier attendu</template>
            <template v-else>{{ r.code }} épuisé · servie si une pièce se libère</template>
          </p>
        </div>
        <span class="flex shrink-0 flex-col items-end gap-0.5 self-start">
          <span class="text-[10px] text-slate-400">{{ ago(r.created_at) }}</span>
          <button v-if="r.status === 'reserved'" type="button" class="text-[11px] font-bold underline" @click="openPlace(r.id, r.customer_name || phoneDisplay(r.customer_phone) || 'Cliente')">Choisir le quartier</button>
          <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="text-[11px] font-bold underline">{{ r.order.reference }}</RouterLink>
          <button v-else type="button" class="text-[11px] font-bold text-slate-500 underline" :disabled="busy" @click="emit('cancel', r)">Annuler</button>
        </span>
      </article>
    </section>

    <!-- Choisir le quartier d'une cliente -->
    <Modal v-if="placing.reservation" :title="`Quartier de ${placing.name}`" @close="placing.reservation = null">
      <div class="space-y-3 text-sm">
        <p v-if="placing.address" class="rounded-xl bg-slate-100 p-2.5">Elle a écrit : « {{ placing.address }} »</p>
        <input v-model="placing.search" class="input" placeholder="Commune ou quartier (ex. Yopougon)" autofocus @input="findPlaces" />
        <ul v-if="placing.results.length" class="max-h-64 divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200">
          <li v-for="p in placing.results" :key="p.id">
            <button type="button" class="w-full p-2.5 text-left font-semibold hover:bg-slate-50 disabled:opacity-50" :disabled="placing.busy" @click="choosePlace(p)">{{ p.label }}</button>
          </li>
        </ul>
        <p v-else-if="placing.search.trim().length >= 2" class="text-slate-500">Aucun lieu trouvé.</p>
        <p class="text-sm text-slate-600">Ses pièces deviennent une commande, livrée à ce quartier.</p>
        <p v-if="placing.error" class="text-red-600">{{ placing.error }}</p>
      </div>
    </Modal>
  </div>
</template>
