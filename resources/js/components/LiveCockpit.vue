<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money, phoneDisplay } from '../lib/format'
import LiveOverlay from './LiveOverlay.vue'
import LiveShowStage from './LiveShowStage.vue'
import LivePin from './LivePin.vue'
import LiveScreenTabs from './LiveScreenTabs.vue'
import LiveShowRemote from './LiveShowRemote.vue'
import Modal from './Modal.vue'
import LiveChatSheet from './live/LiveChatSheet.vue'
import LiveOrderSheet from './live/LiveOrderSheet.vue'
import LiveCopilot from './live/LiveCopilot.vue'
import LiveMysteryEditor from './live/LiveMysteryEditor.vue'
import { launch, useLivePlan, useMomentLog } from '../lib/liveShow'
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
const emit = defineEmits(['feature', 'reload', 'add', 'quick', 'end', 'share', 'flash', 'stop-flash', 'big', 'cancel', 'mode', 'pin-shared', 'close', 'updated'])
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
// Aperçu de l'écran agrandi (un toucher, ✕ ou Échap pour refermer)
const big = ref(false)
function closeOnEscape(e) {
  if (e.key === 'Escape') big.value = false
}
watch(big, (on) => {
  if (on) window.addEventListener('keydown', closeOnEscape)
  else window.removeEventListener('keydown', closeOnEscape)
})
onUnmounted(() => window.removeEventListener('keydown', closeOnEscape))
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
// Forme de la télé connectée (largeur / hauteur de sa fenêtre) : l'aperçu a exactement la même, donc la même mise en page
// (large, carré ou vertical). Sans télé connue : 16/9.
const tvRatio = computed(() => {
  const tv = (screens.value ?? []).filter((s) => !s.is_link && s.online && s.width && s.height).sort((a, b) => (a.last_seen_at < b.last_seen_at ? 1 : -1))[0]
  return tv ? tv.width / tv.height : 16 / 9
})
// Agrandi : posé sur la page, centré par des positions explicites ; l'aperçu réel prend tout l'écran possible,
// à la forme exacte de la télé (dvh : la hauteur visible, sans la barre d'adresse du téléphone)
const tvBigStyle = computed(() => ({
  position: 'fixed', left: '50%', top: '50%', transform: 'translate(-50%, -50%)', zIndex: 81,
  width: `min(100vw, calc(100dvh * ${tvRatio.value}))`, height: `min(100dvh, calc(100vw / ${tvRatio.value}))`,
}))
const studioBigStyle = {
  position: 'fixed', left: '50%', top: '66px', transform: 'translateX(-50%)', zIndex: 81,
  aspectRatio: '9 / 16', height: 'min(calc(100vh - 78px), calc((100vw - 1.5rem) * 16 / 9))',
}
// Fenêtre « Connecter la télé / TikTok Studio » qui monte du bas : elle attend l'écran, affiche ✓ puis se ferme
const connecting = ref(null) // 'tv' | 'studio'
const connected = ref(false)
const managing = ref(false) // ouvert depuis « Gérer » : écran déjà relié, à déconnecter ou à remplacer (pas de fermeture automatique)
function openConnect(key, manage = false) {
  managing.value = manage
  connecting.value = key
}
watch([tvOnline, studioOnline], ([tv, studio]) => {
  if (!connecting.value || managing.value || connected.value || !(connecting.value === 'tv' ? tv : studio)) return
  connected.value = true
  setTimeout(() => {
    connecting.value = null
    connected.value = false
  }, 2000)
})
const CONNECT = {
  tv: { title: '📺 Connecter la télé', why: 'Vos articles s\'affichent en grand derrière vous, en temps réel.', video: '/videos/supports/tv.mp4?v=1', poster: '/videos/supports/tv.jpg?v=1', done: 'Télé connectée', manage: '📺 Écrans reliés', manageWhy: 'Déconnectez un écran, ou reliez-en un autre : il remplace ou complète le premier.' },
  studio: { title: '📱 Connecter TikTok Studio', why: 'La carte de l\'article montré apparaît en haut de votre live.', video: '/videos/supports/studio.mp4?v=1', poster: '/videos/supports/studio.jpg?v=1', done: 'TikTok Studio connecté', manage: '📱 TikTok Studio', manageWhy: 'Le lien de LIVE Studio : vous pouvez le changer si besoin.' },
}
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
// Une chaîne, pas une liste : une nouvelle liste à chaque rechargement du live relançait l'aperçu toutes les 3 s
watch(() => `${props.live.display_mode}|${props.live.featured_item_id}`, loadDisplay)

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
    const g = groups.get(i.code) ?? { code: i.code, title: i.product ?? i.title, image: i.image, items: [], quantity: 0, remaining: 0, waiting: 0, mystery: !!i.mystery, revealed: !!i.revealed }
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
// Articles qu'on peut montrer : l'article mystère seulement une fois révélé
const showable = computed(() => products.value.filter((p) => !p.mystery || p.revealed))
// Suivante : la prochaine pancarte dans l'ordre qui a encore des pièces (sinon la suivante tout court)
const next = computed(() => {
  const list = showable.value
  const at = list.findIndex((p) => p.code === current.value?.code)
  const after = [...list.slice(at + 1), ...list.slice(0, Math.max(0, at))]
  return after.find((p) => p.remaining > 0) ?? after[0] ?? null
})
const previous = computed(() => {
  const list = showable.value
  const at = list.findIndex((p) => p.code === current.value?.code)
  return at > 0 ? list[at - 1] : null
})
const show = (p) => p && current.value?.code !== p.code && emit('feature', p.id)

// ── Show interactif : écran en haut, Copilote / Moments / Articles / Plan, barre du bas ──
const liveRef = computed(() => props.live)
const plan = useLivePlan(liveRef, products)
const moments = useMomentLog(liveRef)
const showState = computed(() => display.value?.live?.show?.state ?? null)
const screenOrders = computed(() => display.value?.live?.orders ?? props.live.summary?.orders ?? 0)
const hasMystery = computed(() => products.value.some((p) => p.mystery && !p.revealed))
const subKey = `jm.live-sub.${props.live.id}`
const sub = ref((() => {
  try {
    return sessionStorage.getItem(subKey) || 'copilot'
  } catch {
    return 'copilot'
  }
})())
watch(sub, (v) => {
  try {
    sessionStorage.setItem(subKey, v)
  } catch {
    // sans stockage : onglet Copilote à la prochaine visite
  }
})
// Copilote (plan du live puis conseils), Articles, Plus (tous les moments, musique, thème, article mystère, durée)
const SUBS = [['copilot', '🧭 Copilote'], ['articles', '📦 Articles'], ['more', '⚙️ Plus']]
if (!SUBS.some(([k]) => k === sub.value)) sub.value = 'copilot'
// Sons et musique de l'écran : à portée de pouce, en bas
const showSettings = computed(() => display.value?.live?.show?.settings ?? {})
async function toggleShow(key) {
  const value = !(showSettings.value[key] ?? (key === 'sound'))
  if (display.value?.live?.show?.settings) display.value.live.show.settings[key] = value
  try {
    await http.put(`/lives/${props.live.id}/show`, { [key]: value })
  } catch (e) {
    showError.value = errorMessage(e)
  }
  loadDisplay()
}
const planLeft = computed(() => {
  const end = plan.plannedEnd.value
  if (!end) return ''
  const min = Math.round((end - props.now) / 60000)
  return min > 0 ? `fin prévue dans ${min} min` : 'durée prévue dépassée'
})
// Article montré : depuis quand, combien vendus à ce moment-là, articles déjà montrés (gardé sur ce téléphone)
const trackKey = `jm.live-track.${props.live.id}`
const track = reactive((() => {
  try {
    return JSON.parse(sessionStorage.getItem(trackKey)) || { code: null, since: Date.now(), sold: 0, shown: [] }
  } catch {
    return { code: null, since: Date.now(), sold: 0, shown: [] }
  }
})())
// Article montré pendant le live (à la main ou par le plan) : le plan se recale ; pas au chargement, pour que
// l'ouverture (vitrine, comment commander) reste proposée
watch(() => current.value?.code, (code) => code && plan.reached(code))
watch(() => current.value?.code, (code) => {
  if (!code || code === track.code) return
  Object.assign(track, { code, since: Date.now(), sold: current.value.sold, shown: [...new Set([...track.shown, code])] })
  try {
    sessionStorage.setItem(trackKey, JSON.stringify(track))
  } catch {
    // sans stockage
  }
}, { immediate: true })
const showError = ref('')
async function moment(type, body = {}) {
  showError.value = ''
  try {
    await launch(props.live.id, type, body)
    moments.add(type)
    loadDisplay()
    if (type === 'reveal') emit('reload')
  } catch (e) {
    showError.value = errorMessage(e)
  }
}
// Ce que propose le copilote ou le plan
async function act(a) {
  if (a.kind === 'moment') return moment(a.type, a.body)
  if (a.kind === 'feature') return show(a.product)
  if (a.kind === 'flash') return emit('flash', a.item)
  if (a.kind === 'tab') return a.tab === 'moments' ? (sub.value = 'moments') : (tab.value = a.tab)
  if (a.kind === 'question') return moment('question', { message_id: a.message.id })
  if (a.kind === 'plan') {
    const step = plan.next.value
    if (!step) return
    applyStep(step)
    plan.start(step)
  }
}
// Étape du plan à l'écran : la vitrine, comment commander, le défilement, ou l'article en grand
function applyStep(step) {
  if (step.kind === 'article') {
    const p = products.value.find((x) => x.code === step.code)
    if (p && current.value?.code !== p.code) show(p)
    else if (screenMode.value !== 'product') emit('mode', 'product')
  } else if (screenMode.value !== step.mode) {
    emit('mode', step.mode)
  }
}
// ── Plan : « Maintenant », « Ensuite », sorti du plan, temps passé sur l'étape ──
const tick = ref(Date.now())
const ticker = setInterval(() => (tick.value = Date.now()), 1000)
onUnmounted(() => clearInterval(ticker))
const stepSecs = computed(() => (plan.now.value ? Math.max(0, Math.round((tick.value - plan.now.value.at) / 1000)) : 0))
const stepClock = computed(() => `${Math.floor(stepSecs.value / 60)}:${String(stepSecs.value % 60).padStart(2, '0')}`)
const overtime = computed(() => !!plan.current.value && stepSecs.value > (plan.current.value.secs ?? 180))
// L'écran ne montre plus l'étape en cours (article montré à la main, autre affichage) : depuis plus de 5 secondes
const planOff = computed(() => {
  const step = plan.current.value
  if (!step) return false
  return step.kind === 'article' ? current.value?.code !== step.code || screenMode.value !== 'product' : screenMode.value !== step.mode
})
const offSince = ref(0)
watch(planOff, (off) => (offSince.value = off ? Date.now() : 0), { immediate: true })
const showOff = computed(() => planOff.value && offSince.value > 0 && tick.value - offSince.value > 5000)
// « Continuer d'ici » : seulement si l'article à l'écran fait partie du plan
const adoptable = computed(() => screenMode.value === 'product' && current.value && plan.steps.value.some((x) => x.key === `art-${current.value.code}`))
function backStep() {
  const prev = plan.back()
  if (prev) applyStep(prev)
}
function restartPlan() {
  if (!window.confirm('Recommencer le plan depuis le début (vitrine, puis comment commander) ?')) return
  plan.restart()
}
const sinceFeature = computed(() => {
  const s = Math.max(0, Math.round((props.now - track.since) / 1000))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})

function chrono(item) {
  const s = Math.max(0, Math.round((new Date(item.flash.ends_at).getTime() - props.now) / 1000))
  return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`
}

// ── Onglets ──
// Onglet gardé le temps de la session : retour d'une commande ouverte depuis « Commandes » sur le même onglet
const tabKey = `jm.live-tab.${props.live.id}`
const tab = ref((() => {
  try {
    return ['live', 'commandes', 'messages'].includes(sessionStorage.getItem(tabKey)) ? sessionStorage.getItem(tabKey) : 'live'
  } catch {
    return 'live'
  }
})())
watch(tab, (t) => {
  try {
    sessionStorage.setItem(tabKey, t)
  } catch {
    // sans stockage : retour sur l'onglet Live
  }
})
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
const orders = computed(() => props.live.reservations.filter((r) => !['cancelled', 'expired'].includes(r.status) || r.order?.status === 'cancelled')
  // Reçu à vérifier : en haut de la liste
  .sort((a, b) => (b.order?.proof ? 1 : 0) - (a.order?.proof ? 1 : 0)))
// Une commande par reçu (plusieurs articles = une seule commande)
const proofs = computed(() => new Set(orders.value.filter((r) => r.order?.proof).map((r) => r.order.id)).size)
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
// Ligne touchée : la commande ou la conversation dans une fenêtre qui monte du bas, le live reste derrière
const openOrder = ref(null)
const openChat = ref(null)
const openedReservation = computed(() => props.live.reservations.find((x) => x.id === openOrder.value) ?? null)
const chatOrderId = (m) => props.live.reservations.find((x) => x.customer_phone === m.phone && x.order)?.order.id ?? null
function placeFromSheet(r) {
  openOrder.value = null
  openPlace(r.id, r.customer_name || phoneDisplay(r.customer_phone) || 'Cliente')
}
function cancelFromSheet(r) {
  openOrder.value = null
  emit('cancel', r)
}
const QUICK = ['Oui, c\'est disponible.', 'Non, désolée, pas pour ce modèle.', 'Écrivez le code de la pancarte, je vous le garde.']
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
// Quartier reçu mais commande pas créée (stock, quota…) : on réessaie avec le même quartier, et on dit pourquoi si ça échoue encore
const retrying = ref(false)
const retryError = ref('')
async function retryOrder(r) {
  retrying.value = true
  retryError.value = ''
  try {
    await http.post(`/lives/${props.live.id}/reservations/${r.id}/place`, { place_id: r.shipping_place_id, address: r.shipping_address })
    openOrder.value = null
    emit('reload')
  } catch (e) {
    retryError.value = errorMessage(e)
  } finally {
    retrying.value = false
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
    <!-- Commandes ouvertes : durée, montant, menu ⋯, ✕ -->
    <div class="flex items-center gap-2 px-0.5 text-white">
      <span class="h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-red-600" aria-hidden="true" />
      <b class="whitespace-nowrap text-[13px] font-black">COMMANDES OUVERTES</b>
      <span class="whitespace-nowrap text-xs font-bold text-slate-400">{{ duration }}</span>
      <b class="ml-auto whitespace-nowrap text-[17px] font-black">{{ money(live.summary?.amount ?? 0) }}</b>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-white/15 text-lg font-black text-white" aria-label="Plus" :aria-expanded="menu" @click="menu = !menu">⋯</button>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-white text-lg font-black text-slate-900" aria-label="Fermer (le live continue)" @click="emit('close')">✕</button>
    </div>
    <!-- Solde : commandes prises pendant le live et solde quand elles seront retirées -->
    <div v-if="live.billing" class="rounded-xl bg-[#1e2638] px-3 py-2 text-white">
      <div class="flex items-center justify-between text-sm font-extrabold">
        <span>{{ live.billing.orders }} commande{{ live.billing.orders > 1 ? 's' : '' }}</span>
        <span :class="live.billing.after < 0 ? 'text-amber-300' : 'text-emerald-300'">Solde après le live : {{ live.billing.after }}</span>
      </div>
      <p v-if="live.billing.after < 0 && !live.billing.billed" class="mt-1.5 rounded-lg bg-amber-400/15 px-2 py-1 text-xs font-bold text-amber-200">⚠️ Ton solde ne couvre pas toutes les commandes : le live continue, recharge avant le prochain.</p>
    </div>
    <div v-if="menu" class="grid grid-cols-2 gap-1.5 rounded-2xl bg-white p-2 text-sm font-extrabold text-slate-900">
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('share')">📤 Lien du live</button>
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('add')">＋ Ajouter un article</button>
      <button type="button" class="rounded-xl bg-slate-100 py-2.5" @click="menu = false; emit('quick')">✍️ Réservation vue</button>
      <RouterLink v-if="live.whatsapp" to="/gestion/whatsapp" class="rounded-xl bg-emerald-50 py-2.5 text-center text-emerald-800">✓ {{ live.whatsapp_phone || 'WhatsApp' }}</RouterLink>
      <RouterLink v-else to="/gestion/whatsapp?onglet=messages" class="rounded-xl bg-amber-50 py-2.5 text-center text-amber-800">WhatsApp à connecter</RouterLink>
      <button type="button" class="col-span-2 rounded-xl bg-slate-900 py-2.5 text-white" :disabled="busy" @click="menu = false; emit('end')">⏹ Terminer le live</button>
    </div>

    <!-- Live, Commandes, Messages -->
    <nav class="sticky top-0 z-20 grid grid-cols-3 gap-1 rounded-xl bg-[#1e2638] p-1 text-[14px] font-extrabold" role="tablist">
      <button v-for="[key, label, count] in [['live', '🔴 Live', 0], ['commandes', 'Commandes', newOrders + proofs], ['messages', 'Messages', questions.length]]" :key="key" type="button" role="tab" :aria-selected="tab === key"
              class="relative rounded-lg py-2" :class="tab === key ? 'bg-white text-slate-900' : 'text-slate-400'" @click="tab = key">
        {{ label }}<span v-if="count" class="ml-1 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ count }}</span>
      </button>
    </nav>

    <!-- ═══ Live : Télé, TikTok Studio, Étiquette ═══ -->
    <section v-if="tab === 'live'" class="space-y-2.5 text-white">
      <div class="grid gap-1 rounded-xl bg-white/10 p-1" :style="{ gridTemplateColumns: `repeat(${VIEWS.length}, minmax(0, 1fr))` }" role="tablist" aria-label="Où regarder ?">
        <button v-for="[key, label] in VIEWS" :key="key" type="button" role="tab" :aria-selected="shownView === key"
                class="relative rounded-lg py-2 text-[12.5px] font-extrabold" :class="shownView === key ? 'bg-white text-slate-900' : 'text-slate-300'" @click="view = key">
          {{ label }}<span class="absolute top-1 right-1 h-2 w-2 rounded-full" :class="viewState(key) ? 'bg-emerald-400' : key === 'pin' ? 'bg-amber-400' : 'bg-red-500'" />
        </button>
      </div>

      <!-- Télé ou TikTok Studio : connecté, ou bandeau à déplier pour connecter -->
      <template v-if="shownView !== 'pin'">
        <p v-if="shownView === 'tv' ? tvOnline : studioOnline" class="flex items-center gap-2 rounded-xl bg-emerald-500/15 px-3 py-1.5 text-[12.5px] font-extrabold text-emerald-300">
          <span class="min-w-0 flex-1">✓ {{ shownView === 'tv' ? 'Télé connectée' : 'TikTok Studio connecté' }}</span>
          <button type="button" class="shrink-0 rounded-lg bg-white/15 px-2.5 py-1 text-[12px] font-black text-white" @click="openConnect(shownView, true)">Gérer</button>
        </p>
        <div v-else class="flex items-center gap-2 rounded-xl bg-red-500/15 px-3 py-2 ring-1 ring-red-400/50">
          <b class="min-w-0 flex-1 text-[13px]">{{ shownView === 'tv' ? '📺 Télé pas connectée' : '📱 TikTok Studio pas connecté' }}</b>
          <button type="button" class="shrink-0 rounded-lg bg-white px-3 py-1.5 text-[13px] font-black text-slate-900" @click="openConnect(shownView)">Connecter</button>
        </div>

        <!-- Ce que voient les clientes : toujours visible en haut pendant qu'on utilise la console -->
        <!-- Un toucher agrandit l'aperçu ; « Fermer », le fond ou Échap le referme. Le petit aperçu ne change pas.
             Agrandi, il est posé sur la page (Teleport) : dans la console, un parent le décalait. C'est le même aperçu,
             déplacé et non recréé : pas de son en double.
             Télé : l'aperçu réel, à la forme exacte de l'écran connecté, le plus grand possible sur fond noir.
             TikTok Studio : la carte posée sur le live d'une cliente (vidéo et commentaires d'exemple). -->
        <div class="sticky top-[50px] z-10 -mx-1 bg-[#0b1120] px-1 pb-2">
          <Teleport to="body">
            <template v-if="big">
              <div class="fixed inset-0 z-[80] bg-black" aria-hidden="true" @click="big = false" />
              <button type="button" class="fixed right-3 top-3 z-[82] min-h-11 rounded-full bg-white px-4 text-sm font-black text-slate-900 shadow-lg" @click="big = false">Fermer</button>
              <!-- Télé large sur un téléphone tenu droit : tourner le téléphone l'agrandit -->
              <p v-if="shownView === 'tv' && tvRatio >= 1.2" class="pointer-events-none fixed inset-x-0 bottom-[12vh] z-[82] hidden text-center text-sm font-bold text-slate-300 portrait:block">Tournez le téléphone pour voir en plus grand</p>
            </template>
          </Teleport>
          <div v-if="shownView === 'tv'">
            <div v-if="big" class="aspect-video rounded-xl bg-black ring-2 ring-red-600/70" aria-hidden="true" />
            <Teleport to="body" :disabled="!big">
              <div
                :class="big ? 'overflow-hidden bg-black cursor-zoom-out' : 'relative aspect-video overflow-hidden rounded-xl bg-black ring-2 ring-red-600/70 cursor-zoom-in'"
                :style="big ? tvBigStyle : null"
                role="button" tabindex="0" :aria-label="big ? 'Fermer l\'aperçu agrandi' : 'Agrandir l\'aperçu de l\'écran'"
                @click="big = !big" @keydown.enter.prevent="big = !big" @keydown.space.prevent="big = !big"
              >
                <LiveShowStage v-if="display" :data="display" />
                <span v-if="!big" class="absolute top-1.5 right-1.5 rounded-md bg-black/60 px-1.5 text-[10px] font-black">📺 À L'ÉCRAN</span>
              </div>
            </Teleport>
          </div>
          <div v-else class="flex justify-center">
            <div v-if="big" class="h-[min(34vh,260px)] rounded-[18px] border-[3px] border-slate-600 bg-gradient-to-b from-amber-800 to-stone-900" style="aspect-ratio: 9 / 16" aria-hidden="true" />
            <Teleport to="body" :disabled="!big">
              <div
                class="overflow-hidden bg-gradient-to-b from-amber-800 to-stone-900"
                :class="big ? 'rounded-[18px] cursor-zoom-out' : 'relative h-[min(34vh,260px)] rounded-[18px] border-[3px] border-slate-600 cursor-zoom-in'"
                :style="big ? studioBigStyle : { aspectRatio: '9 / 16' }"
                role="button" tabindex="0" :aria-label="big ? 'Fermer l\'aperçu agrandi' : 'Agrandir l\'aperçu de l\'écran'"
                @click="big = !big" @keydown.enter.prevent="big = !big" @keydown.space.prevent="big = !big"
              >
                <span v-if="!big" class="absolute bottom-4 left-1/2 -translate-x-1/2 text-[56px] leading-none" aria-hidden="true">🧕🏾</span>
                <!-- Agrandi : le live d'une cliente (la vendeuse, la barre du haut, des commentaires d'exemple) -->
                <template v-if="big">
                  <span class="absolute bottom-0 left-1/2 h-[30%] w-[60%] -translate-x-1/2 rounded-t-full bg-[#2a1a10]" aria-hidden="true" />
                  <span class="absolute bottom-[27%] left-1/2 h-[16%] w-[27%] -translate-x-1/2 rounded-full bg-[#3b2415]" aria-hidden="true" />
                  <div class="absolute inset-x-2.5 top-2.5 flex items-center gap-1.5 text-white">
                    <span class="h-7 w-7 rounded-full bg-slate-300" aria-hidden="true" />
                    <b class="truncate text-[11px]">{{ display?.store?.name }}</b>
                    <span class="ml-auto rounded bg-[#fe2c55] px-1.5 py-0.5 text-[10px] font-black">LIVE</span>
                  </div>
                  <div class="absolute bottom-14 left-2.5 right-14 flex flex-col gap-1 text-[11px] text-white" aria-hidden="true">
                    <span><b class="text-white/70">Awa</b> A1 svp</span>
                    <span><b class="text-white/70">Mariam</b> c'est combien ?</span>
                    <span><b class="text-white/70">Fatou</b> j'ai envoyé A1</span>
                  </div>
                  <div class="absolute bottom-3.5 left-2.5 right-14 flex h-8 items-center rounded-full bg-black/35 pl-3 text-[11px] text-white/75" aria-hidden="true">Ajouter un commentaire…</div>
                  <svg class="absolute bottom-4 right-3" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z" /></svg>
                </template>
                <div class="absolute left-[3%] w-[55%]" :class="big ? 'top-[54px]' : 'top-[3%]'" style="aspect-ratio: 480 / 780"><LiveOverlay v-if="display" :data="display" format="carte" /></div>
              </div>
            </Teleport>
          </div>
        </div>

        <!-- Copilote, Articles, Plus -->
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-white/10 p-1" role="tablist" aria-label="Console du live">
          <button v-for="[key, label] in SUBS" :key="key" type="button" role="tab" :aria-selected="sub === key" class="rounded-lg py-2 text-[12px] font-extrabold" :class="sub === key ? 'bg-white text-slate-900' : 'text-slate-300'" @click="sub = key">
            {{ label }}<span v-if="key === 'copilot' && questions.length" class="ml-0.5 rounded-full bg-red-600 px-1 text-[9px] text-white">{{ questions.length }}</span>
          </button>
        </div>
        <p v-if="showError" class="rounded-xl bg-red-500/15 px-3 py-2 text-sm font-bold text-red-200">{{ showError }}</p>

        <template v-if="sub === 'copilot'">
          <!-- 1. Le plan : ce qui est à l'écran maintenant, ce qui vient ensuite, et le bouton pour y passer -->
          <div class="rounded-2xl bg-violet-500/15 p-2.5 ring-1 ring-violet-400/30">
            <div class="flex items-center gap-2 text-[11px] font-black tracking-wider text-violet-200 uppercase">
              <span>📋 Plan du live<template v-if="plan.current.value"> · étape {{ plan.currentIndex.value + 1 }} sur {{ plan.steps.value.length }}</template></span>
              <span class="ml-auto font-bold tracking-normal normal-case text-slate-400">{{ planLeft }}</span>
            </div>
            <div class="mt-1.5 flex gap-0.5" aria-hidden="true">
              <i v-for="step in plan.steps.value" :key="step.key" class="h-1.5 flex-1 rounded-full" :class="step === plan.current.value ? 'bg-emerald-400' : step.done ? 'bg-amber-400' : 'bg-white/15'" />
            </div>

            <!-- Maintenant à l'écran -->
            <div v-if="plan.current.value" class="mt-2 rounded-xl bg-emerald-500/15 p-2 ring-1 ring-emerald-400/40">
              <p class="text-[10.5px] font-black tracking-wider text-emerald-300 uppercase">🟢 Maintenant à l'écran</p>
              <p class="text-[14px] leading-snug font-extrabold">{{ plan.current.value.label }}</p>
              <p class="text-[12px]" :class="overtime ? 'font-bold text-amber-300' : 'text-slate-400'">depuis {{ stepClock }} · ≈ {{ plan.current.value.time }} conseillé{{ overtime ? ' : passe à la suite' : '' }}</p>
              <p class="mt-1 text-[12.5px] leading-snug text-amber-200">« {{ plan.current.value.say }} »</p>
            </div>
            <p v-else class="mt-2 text-[13px] text-slate-300">Le plan n'a pas encore commencé.</p>

            <!-- Sorti du plan -->
            <div v-if="showOff" class="mt-2 rounded-xl bg-amber-500/15 p-2 ring-1 ring-amber-400/50">
              <p class="text-[12.5px] font-bold text-amber-200">↪ Tu es sorti du plan : l'écran ne montre plus « {{ plan.current.value.label }} ».</p>
              <div class="mt-1.5 flex flex-wrap gap-1.5">
                <button type="button" class="rounded-lg bg-amber-400 px-3 py-1.5 text-[12.5px] font-extrabold text-slate-900" @click="applyStep(plan.current.value)">↩ Reprendre le plan</button>
                <button v-if="adoptable" type="button" class="rounded-lg bg-white/15 px-3 py-1.5 text-[12.5px] font-extrabold" @click="plan.adopt(current.code)">Continuer depuis {{ current.code }}</button>
              </div>
            </div>

            <!-- Ensuite, et le bouton pour y passer -->
            <template v-if="plan.next.value">
              <p class="mt-2 text-[10.5px] font-black tracking-wider text-violet-200 uppercase">⏭ Ensuite</p>
              <p class="text-[13.5px] font-bold">{{ plan.next.value.label }}</p>
              <button type="button" class="mt-1.5 w-full rounded-xl bg-amber-400 px-3 py-3 text-[14px] font-extrabold text-slate-900" :class="{ 'animate-pulse': overtime }" @click="act({ kind: 'plan' })">
                {{ plan.current.value ? 'Passer à' : 'Commencer' }} : {{ plan.next.value.label.replace(/^\S+\s/, '') }} ▶
              </button>
            </template>
            <p v-else class="mt-2 text-[13px] font-bold text-emerald-300">✓ Plan terminé : bravo !</p>

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] font-bold text-violet-200">
              <button v-if="plan.current.value" type="button" class="underline" @click="backStep">◀ Étape précédente</button>
              <button v-if="plan.current.value || plan.steps.value.some((x) => x.done)" type="button" class="underline" @click="restartPlan">↺ Recommencer le plan</button>
              <details class="w-full">
                <summary class="cursor-pointer">Voir tout le plan</summary>
                <ol class="mt-1 space-y-0.5 font-normal">
                  <li v-for="(step, k) in plan.steps.value" :key="step.key" class="flex items-center gap-2 rounded-lg px-2 py-1 text-[12.5px]" :class="step === plan.current.value ? 'bg-emerald-500/15 font-bold text-white' : step.done ? 'text-slate-500 line-through' : step === plan.next.value ? 'bg-white/10 font-bold text-white' : 'text-slate-300'">
                    <span class="w-4 text-center">{{ step === plan.current.value ? '🟢' : step.done ? '✓' : step === plan.next.value ? '⏭' : k + 1 }}</span>{{ step.label }}
                  </li>
                </ol>
              </details>
            </div>
          </div>
          <!-- 2. Les conseils du moment -->
          <LiveCopilot :live="live" :products="products" :current="current" :next="next" :state="showState" :orders="screenOrders" :with-plan="false"
                       :questions="questions.filter(isQuestion)" :now="now" :plan="plan" :last-at="moments.lastAt" :featured-since="track.since" :sold-at-feature="track.sold" :shown-codes="track.shown" @act="act" />
        </template>

        <template v-else-if="sub === 'articles'">
          <div class="grid grid-cols-4 gap-1.5" role="radiogroup" aria-label="Que montrer à l'écran ?">
            <button v-for="[value, label] in MODES" :key="value" type="button" role="radio" :aria-checked="screenMode === value" :disabled="busy"
                    class="rounded-lg px-1 py-2 text-[11.5px] font-extrabold" :class="screenMode === value ? 'bg-white text-slate-900' : 'bg-white/10 text-slate-300'" @click="emit('mode', value)">{{ label }}</button>
          </div>
          <p class="pt-1 text-[11px] font-extrabold tracking-wider text-slate-400 uppercase">Touchez un article pour le montrer · {{ soldPieces }} pièce{{ soldPieces > 1 ? 's' : '' }} vendue{{ soldPieces > 1 ? 's' : '' }}</p>
          <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <button v-for="p in products" :key="p.code" type="button" :disabled="busy || (p.mystery && !p.revealed)"
                    class="relative overflow-hidden rounded-xl border-[3px] bg-white text-left text-slate-900" :class="current?.code === p.code ? 'border-red-600' : 'border-transparent'" @click="current?.code === p.code ? (screenMode !== 'product' && emit('mode', 'product')) : show(p)">
              <span class="block h-16 bg-slate-100" :class="{ 'opacity-40 grayscale': !p.remaining, 'blur-[3px]': p.mystery && !p.revealed }"><img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy" /></span>
              <span class="absolute top-1 left-1 rounded-md bg-slate-900 px-1.5 text-xs font-black text-white">{{ p.code }}</span>
              <span v-if="p.mystery && !p.revealed" class="absolute inset-x-0 top-0 grid h-16 place-items-center text-xs font-black text-violet-700">🎁 MYSTÈRE</span>
              <span v-else-if="!p.remaining" class="absolute inset-x-0 top-0 grid h-16 place-items-center text-sm font-black text-red-600">VENDU</span>
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
          <button type="button" class="w-full rounded-xl border border-white/25 bg-white/10 py-3 text-base font-extrabold" :disabled="busy || !previous" @click="show(previous)">◀ Précédent</button>
        </template>

        <template v-else>
          <!-- Plus : tous les moments, musique, thème, modes ; article mystère ; durée prévue -->
          <LiveShowRemote :id="live.id" dark no-reactions :settings="display?.live?.show?.settings" :state="showState" :has-mystery="hasMystery" @changed="loadDisplay" @launched="(t) => { moments.add(t); if (t === 'reveal') emit('reload') }" />
          <details class="rounded-xl bg-white/5 p-3" :open="!products.some((p) => p.mystery)">
            <summary class="cursor-pointer text-[13px] font-extrabold">🎁 Choisir l'article mystère et ses indices</summary>
            <div class="mt-2"><LiveMysteryEditor :live="live" dark @saved="(state) => { emit('updated', state); loadDisplay() }" /></div>
          </details>
          <div class="flex items-center gap-2 rounded-xl bg-white/5 p-3 text-[13px] font-bold">
            <span class="min-w-0 flex-1">⏱ Durée prévue du live <span class="block text-[11.5px] font-normal text-slate-400">le copilote propose la dernière chance avant la fin</span></span>
            <select v-model.number="plan.minutes.value" class="input w-auto py-1 text-slate-900" aria-label="Durée prévue">
              <option v-for="m in [30, 45, 60, 90, 120]" :key="m" :value="m">{{ m }} min</option>
            </select>
          </div>
        </template>

        <!-- En bas, sous le pouce : l'article à l'écran et Suivant ; les réactions ; les sons et la musique de l'écran -->
        <div class="sticky bottom-0 z-10 -mx-1 space-y-1.5 border-t border-white/10 bg-[#0b1120] px-1 pt-2 pb-[max(.5rem,env(safe-area-inset-bottom))]">
          <div class="flex items-center gap-2 rounded-xl bg-white/10 px-2.5 py-1.5">
            <b class="text-xl font-black text-amber-300">{{ current?.code ?? '—' }}</b>
            <span class="min-w-0 flex-1 truncate text-[12px] text-slate-300">{{ current ? `${sinceFeature} · ${current.sold - track.sold} vente${current.sold - track.sold > 1 ? 's' : ''} · ${current.remaining} pièce${current.remaining > 1 ? 's' : ''}` : 'Aucun article à l\'écran' }}</span>
            <button type="button" class="shrink-0 rounded-xl bg-red-600 px-4 py-2 text-sm font-extrabold disabled:opacity-50" :disabled="busy || !(current ? next : showable[0])" @click="show(current ? next : showable[0])">{{ current ? 'Suivant' : 'Commencer' }} ▶</button>
          </div>
          <div class="grid grid-cols-7 gap-1">
            <button v-for="e in ['❤️', '🔥', '😂', '👏', '🎁']" :key="e" type="button" class="rounded-xl bg-white/10 py-1.5 text-xl" :aria-label="`Réaction ${e}`" @click="moment('react', { emoji: e })">{{ e }}</button>
            <button type="button" class="rounded-xl py-1.5 text-xl" :class="showSettings.sound !== false ? 'bg-emerald-500/25' : 'bg-red-500/25'" :aria-pressed="showSettings.sound !== false" :aria-label="showSettings.sound !== false ? 'Couper les sons de l\'écran' : 'Remettre les sons de l\'écran'" @click="toggleShow('sound')">{{ showSettings.sound !== false ? '🔊' : '🔇' }}</button>
            <button type="button" class="relative rounded-xl py-1.5 text-xl" :class="showSettings.music ? 'bg-emerald-500/25' : 'bg-white/10 opacity-60'" :aria-pressed="!!showSettings.music" :aria-label="showSettings.music ? 'Arrêter la musique' : 'Lancer la musique'" @click="toggleShow('music')">🎵<span v-if="!showSettings.music" class="absolute inset-0 grid place-items-center text-2xl text-red-400" aria-hidden="true">／</span></button>
          </div>
        </div>
      </template>

      <!-- Étiquette à épingler : à jour, ou ce qui a changé et la nouvelle -->
      <template v-else>
        <div v-if="pinStale" class="rounded-xl bg-amber-500/15 p-3 ring-1 ring-amber-400/60">
          <b class="block text-[14px] text-amber-200">⚠ {{ pinShared ? 'Étiquette à refaire' : 'Étiquette pas encore en fond vert' }}</b>
          <template v-if="changes.length">
            <span class="block text-[12.5px] text-amber-100">Depuis la dernière<template v-if="pinTime"> ({{ pinTime }})</template> :</span>
            <span v-for="c in changes" :key="c" class="block text-[13px] font-bold">{{ c }}</span>
          </template>
          <span class="mt-1 block text-[12.5px] text-amber-100">Voici la nouvelle : téléchargez-la, puis dans votre live TikTok ouvrez <b>Fond vert</b> et choisissez-la.</span>
        </div>
        <p v-else class="rounded-xl bg-emerald-500/15 px-3 py-2 text-[13px] font-extrabold text-emerald-300">✓ Étiquette à jour · {{ pinCount }} article{{ pinCount > 1 ? 's' : '' }}<template v-if="pinTime"> · faite à {{ pinTime }}</template></p>
        <div class="rounded-xl bg-white p-3 text-slate-900"><LivePin :live="live" bare @shared="(sig) => emit('pin-shared', sig)" /></div>
        <!-- Sans écrans : les articles à toucher -->
        <template v-if="!cardsOn">
          <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
            <button v-for="p in showable" :key="p.code" type="button" :disabled="busy" class="relative overflow-hidden rounded-xl border-[3px] bg-white text-left text-slate-900" :class="current?.code === p.code ? 'border-red-600' : 'border-transparent'" @click="show(p)">
              <span class="block h-16 bg-slate-100"><img v-if="p.image" :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy" /></span>
              <span class="absolute top-1 left-1 rounded-md bg-slate-900 px-1.5 text-xs font-black text-white">{{ p.code }}</span>
              <span class="block truncate px-1.5 py-0.5 text-[11px] font-bold">{{ p.title }}</span>
            </button>
          </div>
        </template>
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
      <article v-for="m in messages" :key="m.id" role="button" tabindex="0" class="cursor-pointer rounded-xl bg-white p-2 active:bg-slate-100" :class="needsAction(m) ? 'shadow-[inset_4px_0_0_#dc2626]' : ''" @click="openChat = m" @keydown.enter.self="openChat = m">
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
          <span class="shrink-0 text-xl font-black text-slate-300" aria-hidden="true">›</span>
        </div>
        <div v-if="m.place_check && needsAction(m)" class="mt-1.5 pl-12" @click.stop>
          <button type="button" class="rounded-lg bg-slate-900 px-3 py-1 text-xs font-extrabold text-white" @click="openPlace(m.reservation_id, m.name, m.body)">Choisir le quartier</button>
        </div>
        <div v-if="isQuestion(m)" class="mt-1.5 pl-12" @click.stop>
          <span v-if="replying.id !== m.id" class="flex flex-wrap gap-1.5">
            <button type="button" class="rounded-lg bg-slate-900 px-3 py-1 text-xs font-extrabold text-white" @click="openReply(m)">Répondre</button>
            <button v-if="cardsOn" type="button" class="rounded-lg bg-violet-600 px-3 py-1 text-xs font-extrabold text-white" @click="moment('question', { message_id: m.id })">📺 Montrer à l'écran</button>
          </span>
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
      <p v-if="retryError" class="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ retryError }}</p>
      <p v-if="!orders.length" class="rounded-xl bg-white p-4 text-center text-sm text-slate-500">Les commandes s'afficheront ici dès les premières.</p>
      <article v-for="r in orders" :key="r.id" role="button" tabindex="0" class="flex cursor-pointer items-center gap-2.5 rounded-xl bg-white p-2 active:bg-slate-100" @click="openOrder = r.id" @keydown.enter="openOrder = r.id" :class="{ 'opacity-55': r.status === 'waiting' || r.order?.status === 'cancelled', 'ring-[3px] ring-emerald-400': r.order?.proof }">
        <span class="relative h-10 w-10 shrink-0">
          <img v-if="byCode[r.code]?.image" :src="byCode[r.code].image" alt="" class="h-10 w-10 rounded-lg object-cover" :class="{ grayscale: r.status === 'waiting' }" />
          <span v-else class="block h-10 w-10 rounded-lg bg-slate-100" />
          <span class="absolute -bottom-1 -left-1 rounded border-2 border-slate-900 bg-white px-0.5 text-[10px] leading-tight font-black">{{ r.code }}</span>
        </span>
        <div class="min-w-0 flex-1">
          <p class="truncate text-[13px] font-extrabold">
            {{ r.customer_name || phoneDisplay(r.customer_phone) || 'Cliente' }}
            <template v-if="r.order"> · {{ money(r.unit_price) }} · {{ ORDER_STATE[r.order.status] ?? 'commande créée' }}</template>
            <template v-else-if="r.status === 'reserved' && r.shipping_place_id"> · <span class="text-red-600">commande pas créée ⚠️</span></template>
            <template v-else-if="r.status === 'reserved'"> · gardée{{ minutesLeft(r) ? ` ${minutesLeft(r)} min` : '' }} ⏳</template>
            <template v-else> · liste d'attente</template>
          </p>
          <p class="truncate text-xs text-slate-600">
            <template v-if="itemOf(r)?.variant">Taille {{ itemOf(r).variant }} · </template>
            <template v-if="r.order">{{ r.shipping_address || 'commande créée' }}</template>
            <template v-else-if="r.status === 'reserved' && r.shipping_place_id">{{ r.order_error || 'Quartier reçu : commande à créer' }}</template>
            <template v-else-if="r.status === 'reserved'">quartier attendu</template>
            <template v-else>{{ r.code }} épuisé · servie si une pièce se libère</template>
          </p>
        </div>
        <span class="flex shrink-0 flex-col items-end gap-0.5 self-start">
          <span class="text-[10px] text-slate-400">{{ ago(r.created_at) }}</span>
          <button v-if="r.status === 'reserved' && r.shipping_place_id" type="button" class="text-[11px] font-bold text-red-700 underline" :disabled="retrying" @click.stop="retryOrder(r)">🔁 Créer la commande</button>
          <button v-else-if="r.status === 'reserved'" type="button" class="text-[11px] font-bold underline" @click.stop="openPlace(r.id, r.customer_name || phoneDisplay(r.customer_phone) || 'Cliente')">Choisir le quartier</button>
          <RouterLink v-if="r.order?.proof" @click.stop :to="`/gestion/commandes/${r.order.id}`" class="rounded-full bg-emerald-500 px-2 py-0.5 text-[10.5px] font-black text-emerald-950">💳 Reçu à vérifier</RouterLink>
          <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="text-[11px] font-bold underline" @click.stop>{{ r.order.reference }}</RouterLink>
          <button v-else type="button" class="text-[11px] font-bold text-slate-500 underline" :disabled="busy" @click.stop="emit('cancel', r)">Annuler</button>
        </span>
        <span class="shrink-0 text-xl font-black text-slate-300" aria-hidden="true">›</span>
      </article>
    </section>

    <!-- Commande ou conversation touchée -->
    <LiveOrderSheet v-if="openedReservation" :key="openedReservation.id" :reservation="openedReservation" :busy="busy || retrying" :failure="retryError"
                    @close="openOrder = null" @place="placeFromSheet" @retry="retryOrder" @cancel="cancelFromSheet" @changed="emit('reload')" />
    <LiveChatSheet v-if="openChat" :key="openChat.id" :message="openChat" :quick="QUICK" :order-id="chatOrderId(openChat)" @close="openChat = null" @sent="emit('reload')" />

    <!-- Connecter la télé ou TikTok Studio : fenêtre qui monte du bas -->
    <div v-if="connecting" class="fixed inset-0 z-50 flex items-end bg-slate-950/60" @click.self="connecting = null">
      <div class="max-h-[88vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))] text-slate-900" role="dialog" aria-modal="true" :aria-label="CONNECT[connecting].title">
        <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
        <div v-if="connected" class="py-8 text-center">
          <span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-emerald-500 text-4xl font-black text-white">✓</span>
          <b class="mt-2 block text-lg">{{ CONNECT[connecting].done }}</b>
          <span class="text-sm text-slate-500">Retour au live…</span>
        </div>
        <template v-else>
          <div class="flex items-center gap-2">
            <b class="min-w-0 flex-1 text-[18px]">{{ managing ? CONNECT[connecting].manage : CONNECT[connecting].title }}</b>
            <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100" aria-label="Fermer" @click="connecting = null">✕</button>
          </div>
          <p v-if="managing" class="mt-1 text-[14px] text-slate-600">{{ CONNECT[connecting].manageWhy }}</p>
          <p v-if="!managing" class="mt-1 text-[14px] text-slate-600">{{ CONNECT[connecting].why }}</p>
          <video v-if="!managing" :key="connecting" :src="CONNECT[connecting].video" :poster="CONNECT[connecting].poster" class="mt-2 aspect-video w-full rounded-2xl bg-slate-900" controls playsinline preload="metadata" />
          <div class="mt-2"><LiveScreenTabs :id="live.id" screen-only :part="connecting" :no-phone="connecting === 'studio'" @add="connecting = null" /></div>
          <p v-if="!managing" class="mt-2 flex items-center justify-center gap-2 text-[13px] font-bold text-slate-500"><span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-sky-200 border-t-sky-700" />En attente {{ connecting === 'tv' ? 'de la télé' : 'de LIVE Studio' }}…</p>
        </template>
      </div>
    </div>

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
