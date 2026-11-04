<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money, phoneDisplay } from '../lib/format'
import Modal from './Modal.vue'
import { useAuthStore } from '../stores/auth'

/**
 * Écran de la vendeuse pendant un live avec pancartes : la pancarte montrée toujours en haut (photo, ce qui reste,
 * par taille, phrase à dire, « Suivante »), puis trois onglets : Articles, Messages, Commandes. Noir et blanc ; les
 * seules couleurs sont les photos des articles et un rouge réservé à ce qui demande une action.
 */
const props = defineProps({
  live: { type: Object, required: true },
  now: { type: Number, required: true },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['feature', 'reload', 'add', 'quick', 'end', 'share', 'flash', 'stop-flash', 'big', 'cancel', 'mode'])
// Ce que montrent les écrans du live (télé, OBS, LIVE Studio) : un article, défilement, comment commander
const MODES = [['product', '📦 Un article'], ['scroll', '🔁 Défilement'], ['how', '🛒 Commander']]
const auth = useAuthStore()
// Cartes des articles (photo + code) à afficher à l'écran, sauf si la console les masque
const cardsOn = computed(() => auth.profile?.features?.live_screens !== false)

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

function say(p) {
  if (!p) return ''
  if (p.remaining === 0) return 'Tout est parti ! Passez à la pancarte suivante.'
  const left = p.sizes.filter((s) => s.remaining > 0).map((s) => `${s.remaining > 1 ? `${s.remaining} en` : 'une'} ${s.name}`)
  const sizes = p.sizes.length && left.length <= 3 ? ` ${left.join(', ').replace(/^./, (c) => c.toUpperCase())}.` : ''
  if (p.remaining === 1) return `Dernière pièce !${sizes} Envoie ${p.code}${p.sizes.length ? ' + ta taille' : ''}.`
  if (p.remaining <= 3) return `Plus que ${p.remaining} !${sizes} Envoie ${p.code}${p.sizes.length ? ' + ta taille' : ''}.`
  return `Envoie ${p.code}${p.sizes.length ? ' + ta taille' : ''} pour le réserver.`
}
function chrono(item) {
  const s = Math.max(0, Math.round((new Date(item.flash.ends_at).getTime() - props.now) / 1000))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

// ── Onglets ──
const tab = ref('articles')
const articleFilter = ref('all')
const shownProducts = computed(() => products.value.filter((p) => articleFilter.value === 'all' || p.remaining > 0))
const soldPieces = computed(() => products.value.reduce((sum, p) => sum + p.sold, 0))
const allPieces = computed(() => products.value.reduce((sum, p) => sum + p.quantity, 0))

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
    <!-- Pancarte montrée et onglets : toujours visibles -->
    <div class="sticky top-2 z-20 space-y-2">
      <section class="rounded-2xl border-2 border-slate-900 bg-white p-2.5 shadow-sm">
        <div class="flex items-center gap-2 text-[11px] font-extrabold tracking-wider text-slate-900">
          <span>À L'ÉCRAN MAINTENANT</span>
          <span v-if="current" class="ml-auto font-bold text-slate-500">{{ currentIndex + 1 }} / {{ products.length }}</span>
        </div>
        <!-- Messages et Commandes : l'article à l'écran sur une ligne, avec « suivant » -->
        <div v-if="current && tab !== 'articles'" class="mt-1.5 flex items-center gap-2">
          <img v-if="current.image" :src="current.image" alt="" class="h-11 w-11 shrink-0 rounded-lg object-cover" />
          <span class="shrink-0 rounded-md border-[3px] border-slate-900 px-1.5 text-lg leading-tight font-black">{{ current.code }}</span>
          <span class="min-w-0 flex-1 truncate text-xs text-slate-600">{{ current.title }} · {{ money(current.price) }} · reste {{ current.remaining }}</span>
          <button v-if="next" type="button" class="shrink-0 rounded-lg bg-slate-900 px-3 py-2 text-sm font-black text-white" :disabled="busy" :aria-label="`Suivante : ${next.code}`" @click="show(next)">▶ {{ next.code }}</button>
        </div>
        <template v-else-if="current">
          <div class="mt-1.5 flex gap-2.5">
            <button type="button" class="relative h-28 w-28 shrink-0 overflow-hidden rounded-xl bg-slate-100" :aria-label="`Afficher ${current.code} en grand`" @click="emit('big', current.items[0])">
              <img v-if="current.image" :src="current.image" alt="" class="h-full w-full object-cover" />
              <span class="absolute top-1.5 left-1.5 rounded-md border-[3px] border-slate-900 bg-white px-1.5 text-lg leading-tight font-black">{{ current.code }}</span>
            </button>
            <div class="min-w-0 flex-1">
              <p class="truncate font-extrabold leading-tight">{{ current.title }}</p>
              <p v-if="current.flash" class="text-sm font-bold">⚡ {{ money(current.price) }} <s class="font-normal text-slate-400">{{ money(current.flash.flash.was) }}</s> · {{ chrono(current.flash) }}</p>
              <p v-else class="text-sm font-semibold text-slate-600">{{ money(current.price) }}</p>
              <p class="mt-0.5 text-[28px] leading-none font-black">{{ current.remaining }} <span class="text-xs font-semibold text-slate-500">{{ current.remaining > 1 ? 'restantes' : 'restante' }} sur {{ current.quantity }}</span></p>
              <div v-if="current.sizes.length" class="mt-1.5 flex flex-wrap gap-1">
                <span v-for="s in current.sizes" :key="s.name" class="rounded-md border-[1.5px] px-1.5 py-0.5 text-[11px] font-extrabold"
                      :class="s.remaining ? 'border-slate-900 text-slate-900' : 'border-slate-200 text-slate-400 line-through'">{{ s.name }}<template v-if="s.remaining"> · {{ s.remaining }}</template></span>
              </div>
            </div>
          </div>
          <p class="mt-2 rounded-xl bg-slate-100 px-2.5 py-1.5 text-[13px] font-bold">🗣 « {{ say(current) }} »</p>
          <div v-if="cardsOn" class="mt-2 grid grid-cols-3 gap-1" role="radiogroup" aria-label="Que montrer sur l'écran du live ?">
            <button v-for="[value, label] in MODES" :key="value" type="button" role="radio" :aria-checked="(live.display_mode || 'product') === value" :disabled="busy"
                    class="rounded-lg border-[1.5px] py-1.5 text-[11px] font-extrabold" :class="(live.display_mode || 'product') === value ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 text-slate-600'" @click="emit('mode', value)">{{ label }}</button>
          </div>
        </template>
        <p v-else class="mt-1 text-sm text-slate-600">Prenez votre première pancarte et touchez « Commencer » : l'application saura de quel article vous parlez.</p>
        <div v-if="!current || tab === 'articles'" class="mt-2 flex gap-1.5">
          <button v-if="previous" type="button" class="flex shrink-0 items-center gap-1.5 rounded-xl border-[1.5px] border-slate-300 py-1 pr-2 pl-1 text-xs font-extrabold" :disabled="busy" @click="show(previous)">
            <img v-if="previous.image" :src="previous.image" alt="" class="h-8 w-8 rounded-lg object-cover" />◀ {{ previous.code }}
          </button>
          <button v-if="current ? next : products[0]" type="button" class="flex min-w-0 flex-1 items-center gap-2 rounded-xl bg-slate-900 py-1 pr-2.5 pl-1 text-left text-white disabled:opacity-60" :disabled="busy" @click="show(current ? next : products[0])">
            <img v-if="(current ? next : products[0]).image" :src="(current ? next : products[0]).image" alt="" class="h-8 w-8 shrink-0 rounded-lg object-cover" />
            <span class="min-w-0 flex-1 leading-tight"><b class="block text-[13px]">{{ current ? 'Suivante' : 'Commencer' }} · {{ (current ? next : products[0]).code }}</b><span class="block truncate text-[11px] opacity-75">{{ (current ? next : products[0]).title }}</span></span>
            <span class="shrink-0 text-lg">▶</span>
          </button>
          <span v-else-if="current" class="flex flex-1 items-center justify-center rounded-xl border-[1.5px] border-dashed border-slate-300 text-xs font-bold text-slate-500">Dernière pancarte</span>
        </div>
      </section>

      <nav class="grid grid-cols-3 overflow-hidden rounded-xl border border-slate-200 bg-white text-[13px] font-extrabold" role="tablist">
        <button v-for="[key, label, count] in [['articles', 'Articles', 0], ['messages', 'Messages', questions.length], ['commandes', 'Commandes', newOrders]]" :key="key" type="button" role="tab" :aria-selected="tab === key"
                class="relative py-2.5" :class="tab === key ? 'bg-slate-900 text-white' : 'text-slate-500'" @click="tab = key">
          {{ label }}<span v-if="count" class="ml-1 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ count }}</span>
        </button>
      </nav>
    </div>

    <!-- ═══ Articles ═══ -->
    <section v-if="tab === 'articles'" class="space-y-2">
      <div class="flex items-center gap-1.5 text-xs font-extrabold">
        <button v-for="[key, label] in [['all', `Toutes · ${products.length}`], ['left', `Restantes · ${products.filter((p) => p.remaining > 0).length}`]]" :key="key" type="button" :aria-pressed="articleFilter === key"
                class="rounded-full border-[1.5px] px-3 py-1" :class="articleFilter === key ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white'" @click="articleFilter = key">{{ label }}</button>
        <span class="ml-auto text-slate-500">{{ soldPieces }} / {{ allPieces }} pièces vendues</span>
      </div>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
        <button v-for="p in shownProducts" :key="p.code" type="button" class="overflow-hidden rounded-xl border-2 bg-white text-left" :class="current?.code === p.code ? 'border-slate-900' : 'border-white'" :disabled="busy" @click="show(p)">
          <span class="relative block h-24 bg-slate-100" :class="{ 'opacity-45 grayscale': !p.remaining }">
            <img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" />
            <span class="absolute top-1 left-1 rounded border-[2.5px] border-slate-900 bg-white px-1 text-sm font-black">{{ p.code }}</span>
            <span v-if="current?.code === p.code" class="absolute top-1 right-1 rounded-full bg-slate-900 px-1.5 text-[9px] font-black text-white">MONTRÉE</span>
            <span v-else-if="next?.code === p.code" class="absolute top-1 right-1 rounded-full border-[1.5px] border-slate-900 bg-white px-1.5 text-[9px] font-black">SUIVANTE</span>
            <span v-else-if="!p.remaining" class="absolute top-1 right-1 rounded-full bg-slate-500 px-1.5 text-[9px] font-black text-white">VENDU</span>
          </span>
          <span class="block px-2 pt-1 pb-1.5" :class="{ 'opacity-50': !p.remaining }">
            <b class="block truncate text-xs">{{ p.title }}</b>
            <span class="text-[11px] text-slate-500"><b class="text-base text-slate-900">{{ p.remaining }}</b> {{ p.remaining > 1 ? 'restent' : 'reste' }} sur {{ p.quantity }}<template v-if="p.waiting"> · {{ p.waiting }} en attente</template></span>
            <span class="mt-1 block h-1 overflow-hidden rounded bg-slate-200"><span class="block h-full bg-slate-900" :style="{ width: `${p.quantity ? (p.sold / p.quantity) * 100 : 0}%` }" /></span>
          </span>
        </button>
        <button type="button" class="grid min-h-32 place-items-center rounded-xl border-2 border-dashed border-slate-300 text-sm font-extrabold text-slate-600" @click="emit('add')">+ Ajouter un article</button>
      </div>
      <div v-if="current" class="flex gap-2">
        <button v-if="current.flash" type="button" class="btn-secondary flex-1 justify-center" :disabled="busy" @click="emit('stop-flash', current.flash)">Arrêter le prix live {{ current.code }}</button>
        <button v-else-if="current.remaining" type="button" class="btn-secondary flex-1 justify-center" :disabled="busy" @click="emit('flash', current.items[0])">⚡ Prix live pour {{ current.code }}</button>
      </div>
      <div class="flex gap-2 pt-2">
        <button type="button" class="btn-ghost flex-1 justify-center text-sm" @click="emit('share')">Partager le lien</button>
        <RouterLink v-if="cardsOn" :to="`/gestion/live/${live.id}/cartes`" class="btn-ghost flex-1 justify-center text-sm">🖼 Cartes à l'écran</RouterLink>
        <button type="button" class="btn-ghost flex-1 justify-center text-sm text-slate-500" :disabled="busy" @click="emit('end')">Terminer le live</button>
      </div>
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
        <p class="text-xs text-slate-500">Ses pièces gardées deviennent une commande, livrée à ce quartier. Ce qu'elle a écrit est gardé comme repère pour le livreur.</p>
        <p v-if="placing.error" class="text-red-600">{{ placing.error }}</p>
      </div>
    </Modal>
  </div>
</template>
