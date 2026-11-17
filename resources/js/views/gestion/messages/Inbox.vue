<script setup>
import AiMeter from '../../../components/AiMeter.vue'
import { aiExhausted, countAiAction } from '../../../lib/aiQuota'
import NetworkMark from '../../../components/NetworkMark.vue'
import Icon from '../../../components/Icon.vue'
import MessagePhoto from '../../../components/MessagePhoto.vue'
import WhatsAppLink from '../../../components/WhatsAppLink.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import { install, isIos, promptInstall } from '../../../lib/install'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../../../stores/auth'

// Boîte « Messages » : conversations WhatsApp, Messenger et Instagram des clients, réponses, et commande créée depuis la conversation.
// Présentée comme WhatsApp : la liste, la discussion en plein écran sur téléphone (un seul en-tête, commande épinglée),
// les actions de vente derrière le bouton +, et sur grand écran le client et sa commande dans une colonne à droite.
const props = defineProps({ id: { type: String, default: null } })
const auth = useAuthStore()
const router = useRouter()

const conversations = ref([])
const connected = ref(true)
const linked = ref(true)
const listLoaded = ref(false)
const listPage = ref(1)
const listLastPage = ref(1)
const filter = ref('all')
const search = ref('')

const current = ref(null)
const reply = ref('')
const replyError = ref('')
const sending = ref(false)
const thread = ref(null)
const input = ref(null)
const newBelow = ref(false) // nouveaux messages arrivés pendant la lecture plus haut
const loadingOlder = ref(false)

// Numéros connectés (officiel de Meta, ou relié par QR code pour les anciennes boutiques), en haut de la boîte.
// Le gérant peut s'en déconnecter ; la connexion se fait dans Réglages › WhatsApp (avec Facebook).
const canManage = computed(() => auth.can('store.manage'))
const numbers = ref([])
const metaBlock = ref(null) // numéro officiel bloqué par Meta
const disconnecting = ref('')
const disconnectError = ref('')
// WhatsApp relié par QR code : proposé seulement si la console l'a autorisé pour la boutique
const canLink = computed(() => canManage.value && auth.profile?.whatsapp_qr === true)
const offerLink = computed(() => listLoaded.value && canLink.value && !linked.value)
const showLink = ref(false)
const justLinked = ref(false)
function onLinked() {
  showLink.value = false
  justLinked.value = true
  loadList()
}
const NUMBER_KIND = { official: 'Numéro officiel (Meta)', linked: 'WhatsApp relié par QR code' }
// Bandeau au-dessus de la boîte : la boîte raccourcit d'autant (sur téléphone, caché dans une conversation). Les numéros
// connectés sont dans le menu de la liste (⋮).
const banner = computed(() => {
  if (!listLoaded.value || (props.id && window.innerWidth < 1024)) return 0
  if (metaBlock.value) return 3.5
  return !connected.value && canManage.value ? 4.5 : 0
})
async function disconnectNumber(n) {
  const label = n.phone ? `le numéro ${n.phone}` : 'ce numéro'
  const effect = n.kind === 'official'
    ? 'Les messages de vos clients n\'arriveront plus ici et les messages automatiques s\'arrêtent.'
    : 'Les messages de ce WhatsApp n\'arriveront plus ici.'
  if (!window.confirm(`Déconnecter ${label} ? ${effect}`)) return
  disconnecting.value = n.kind
  disconnectError.value = ''
  try {
    await http.delete(n.kind === 'official' ? '/whatsapp' : '/whatsapp/link')
    await loadList()
  } catch (e) {
    disconnectError.value = errorMessage(e)
  } finally {
    disconnecting.value = ''
  }
}

// Assistant IA : réponse proposée avec les vrais prix, stock et frais de livraison ; le marchand relit et envoie
const ai = computed(() => auth.profile?.ai ?? { enabled: false, used: 0, limit: 0 })
const suggesting = ref(false)
const suggestionCheck = ref('')
async function suggest() {
  suggesting.value = true
  replyError.value = ''
  suggestionCheck.value = ''
  try {
    const { data } = await http.post(`/conversations/${props.id}/suggest`)
    reply.value = data.data.reply
    suggestionCheck.value = data.data.to_check ?? ''
    countAiAction(auth.profile?.ai)
    nextTick(grow)
  } catch (e) {
    replyError.value = errorMessage(e)
  } finally {
    suggesting.value = false
  }
}

// Conversations : 30 par page, « Voir plus » ajoute la suivante ; l'actualisation garde ce qui est déjà affiché
async function loadList(more = false) {
  const pages = more ? [listPage.value + 1] : Array.from({ length: listPage.value }, (_, i) => i + 1)
  try {
    const results = await Promise.all(pages.map((page) => http.get('/conversations', { params: { filter: filter.value, q: search.value || undefined, page } })))
    const items = results.flatMap((r) => r.data.data)
    conversations.value = more ? [...conversations.value, ...items] : items
    const last = results[results.length - 1].data
    listLastPage.value = last.meta.last_page
    if (more) listPage.value++
    connected.value = last.connected
    linked.value = last.linked
    numbers.value = last.numbers ?? []
    metaBlock.value = last.meta_block ?? null
    listLoaded.value = true
  } catch {
    // réseau passager : nouvel essai au prochain tour
  }
}

const nearBottom = () => !thread.value || thread.value.scrollHeight - thread.value.scrollTop - thread.value.clientHeight < 120

async function loadCurrent(scroll = false) {
  if (!props.id) return (current.value = null)
  const id = props.id
  const lastId = current.value?.messages.at(-1)?.id
  const wasNearBottom = nearBottom()
  let data
  try {
    data = (await http.get(`/conversations/${id}`)).data.data
  } catch {
    return
  }
  // Une autre conversation a été ouverte entre-temps : cette réponse arrive trop tard
  if (props.id !== id) return
  // Messages plus anciens déjà chargés : gardés au-dessus des nouveaux
  const older = current.value?.id === data.id ? current.value.messages.filter((m) => m.id < (data.messages[0]?.id ?? 0)) : []
  current.value = { ...data, messages: [...older, ...data.messages], has_more: older.length ? current.value.has_more : data.has_more }
  const item = conversations.value.find((c) => c.id === data.id)
  if (item) item.unread = 0
  const arrived = data.messages.at(-1)?.id !== lastId
  if (scroll || (arrived && wasNearBottom)) scrollDown()
  else if (arrived) newBelow.value = true
}

async function loadOlder() {
  loadingOlder.value = true
  const box = thread.value
  const fromBottom = box ? box.scrollHeight - box.scrollTop : 0
  try {
    const { data } = await http.get(`/conversations/${current.value.id}/messages`, { params: { before: current.value.messages[0].id } })
    current.value.messages = [...data.data.messages, ...current.value.messages]
    current.value.has_more = data.data.has_more
    // La lecture reste au même endroit
    nextTick(() => box && (box.scrollTop = box.scrollHeight - fromBottom))
  } catch (e) {
    replyError.value = errorMessage(e)
  } finally {
    loadingOlder.value = false
  }
}

function scrollDown() {
  newBelow.value = false
  nextTick(() => thread.value && (thread.value.scrollTop = thread.value.scrollHeight))
}
function onScroll() {
  if (nearBottom()) newBelow.value = false
}

// Brouillons : la réponse commencée reste quand on passe d'une conversation à l'autre (et après un rechargement)
const DRAFTS = 'jibiat-inbox-drafts'
const drafts = reactive((() => {
  try {
    return JSON.parse(localStorage.getItem(DRAFTS) ?? '{}') ?? {}
  } catch {
    return {}
  }
})())
function saveDraft(id, text) {
  if (!id) return
  if (text.trim()) drafts[id] = text
  else delete drafts[id]
  try {
    localStorage.setItem(DRAFTS, JSON.stringify(drafts))
  } catch {
    // stockage indisponible (navigation privée) : brouillon gardé le temps de la visite
  }
}

// Récap : « Envoyer le récap » (la commande part au client, sans le mot) et « Confirmer la commande » (le client a
// dit oui par écrit) ; le résultat s'affiche sous les boutons
const recapBusy = ref('')
const recapResult = ref(null)
async function recapAction(kind) {
  if (recapBusy.value) return
  recapBusy.value = kind
  recapResult.value = null
  try {
    const { data } = await http.post(`/conversations/${props.id}/${kind === 'confirm' ? 'recap/confirm' : 'recap'}`)
    applyRecap(data)
  } catch (e) {
    if (e.response?.data?.result) applyRecap(e.response.data)
    else recapResult.value = { sent: false, message: errorMessage(e) }
  } finally {
    recapBusy.value = ''
  }
}
function applyRecap(data) {
  recapResult.value = data.result
  const older = current.value.messages.filter((m) => m.id < (data.data.messages[0]?.id ?? 0))
  current.value = { ...data.data, messages: [...older, ...data.data.messages], has_more: older.length ? current.value.has_more : data.data.has_more }
  scrollDown()
  loadList()
}

async function send(text = reply.value) {
  if (!text.trim() || sending.value) return
  sending.value = true
  replyError.value = ''
  try {
    const { data } = await http.post(`/conversations/${props.id}/messages`, { body: text })
    const older = current.value.messages.filter((m) => m.id < (data.data.messages[0]?.id ?? 0))
    current.value = { ...data.data, messages: [...older, ...data.data.messages], has_more: older.length ? current.value.has_more : data.data.has_more }
    if (text === reply.value) {
      reply.value = ''
      saveDraft(props.id, '')
      suggestionCheck.value = ''
      nextTick(grow)
    }
    scrollDown()
    loadList()
  } catch (e) {
    replyError.value = fieldErrors(e).body ?? errorMessage(e)
  } finally {
    sending.value = false
  }
}

// Entrée envoie sur ordinateur ; sur téléphone, Entrée va à la ligne (le bouton envoie)
const enterSends = typeof window !== 'undefined' && window.matchMedia?.('(pointer: fine)').matches
function onEnter(event) {
  if (!enterSends || event.shiftKey || event.isComposing) return
  event.preventDefault()
  send()
}
// La zone de réponse grandit avec le texte (jusqu'à environ 8 lignes)
function grow() {
  const el = input.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = Math.min(el.scrollHeight, 200) + 'px'
}

// Nouveaux messages : la liste et la conversation ouverte se mettent à jour toutes seules
let timer
function refresh() {
  if (document.hidden) return
  loadList()
  loadCurrent()
}
onMounted(() => {
  loadList()
  loadCurrent(true).then(() => {
    reply.value = drafts[props.id] ?? ''
    nextTick(grow)
  })
  timer = setInterval(() => {
    now.value = Date.now()
    if (document.visibilityState === 'visible') refresh()
  }, 15000)
  useMessagesManifest(true)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  useMessagesManifest(false)
  saveDraft(props.id, reply.value)
})
watch(() => props.id, (id, previous) => {
  saveDraft(previous, reply.value)
  recapResult.value = null
  closeMenus()
  selecting.value = false
  selected.value = []
  reply.value = drafts[id] ?? ''
  replyError.value = ''
  suggestionCheck.value = ''
  newBelow.value = false
  current.value = null
  loadCurrent(true)
  nextTick(grow)
})
let draftTimer
watch(reply, (text) => {
  clearTimeout(draftTimer)
  draftTimer = setTimeout(() => saveDraft(props.id, text), 500)
})
let searchTimer
watch([filter, search], () => {
  clearTimeout(searchTimer)
  listPage.value = 1
  searchTimer = setTimeout(() => loadList(), 300)
})

const replyUntil = computed(() => (current.value?.reply_until ? formatDateTime(current.value.reply_until) : ''))
const waLink = computed(() => (current.value?.phone ? `https://wa.me/${current.value.phone.replace(/\D/g, '')}` : ''))
const CHANNELS = { whatsapp: ['WhatsApp'], messenger: ['Messenger'], instagram: ['Instagram'] }
const channelLabel = (c) => CHANNELS[c?.channel]?.[0] ?? 'WhatsApp'
// Messenger et Instagram : seulement quand la console les a allumés
const metaMessages = computed(() => auth.profile?.features?.meta_messages !== false)
const sources = computed(() => (metaMessages.value ? 'votre WhatsApp, votre page Facebook ou votre Instagram' : 'votre WhatsApp'))
const filters = [
  { value: 'all', label: 'Toutes' },
  { value: 'unread', label: 'Non lues' },
  { value: 'pending', label: 'À confirmer' },
  { value: 'without_order', label: 'Sans commande' },
]
const time = (iso) => {
  const d = new Date(iso)
  return d.toDateString() === new Date().toDateString() ? d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}
const hour = (iso) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
// Fil : un repère par jour (« Aujourd'hui », « Hier », « mardi 21 octobre »), l'heure seule sur chaque message
function dayLabel(iso) {
  const d = new Date(iso)
  const today = new Date()
  const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)
  if (d.toDateString() === today.toDateString()) return 'Aujourd\'hui'
  if (d.toDateString() === yesterday.toDateString()) return 'Hier'
  return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })
}
const rows = computed(() => {
  const out = []
  let day = null
  for (const m of current.value?.messages ?? []) {
    const label = dayLabel(m.sent_at)
    if (label !== day) out.push({ key: `day-${m.id}`, day: (day = label) })
    out.push({ key: m.id, message: m })
  }
  return out
})
const statusIcon = { queued: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '!' }
const STATUS_LABEL = { queued: 'En cours d\'envoi', sent: 'Envoyé', delivered: 'Reçu', read: 'Lu', failed: 'Non envoyé' }

// Commande depuis un ou plusieurs messages précis du client (plutôt que toute la conversation)
const selecting = ref(false)
const selected = ref([])
const canOrder = computed(() => auth.can('orders.manage'))
const orderLink = (ids) => ({ path: '/gestion/commandes/nouvelle', query: { conversation: current.value.id, messages: ids.join(',') } })
function toggleSelecting() {
  selecting.value = !selecting.value
  selected.value = []
}
function toggleMessage(m) {
  if (!selecting.value || m.direction !== 'in') return
  selected.value = selected.value.includes(m.id) ? selected.value.filter((id) => id !== m.id) : [...selected.value, m.id]
}
// Retour à la liste : on revient en arrière quand on vient d'elle (l'historique ne s'allonge pas à chaque
// conversation), sinon on la remplace
function backToList() {
  if (window.history.state?.back === '/gestion/messages') router.back()
  else router.replace('/gestion/messages')
}
const linkify = (text) => text.split(/(https?:\/\/\S+)/g)
// Mise en forme de WhatsApp : *gras* (les messages automatiques s'en servent)
const boldParts = (text) => text.split(/\*([^*\n]+)\*/g)
const plain = (text) => (text ?? '').replace(/\*([^*\n]+)\*/g, '$1').replace(/(^|\s)_([^_\n]+)_(?=\s|$)/g, '$1$2')

// Rond du contact : initiales (ou les 2 derniers chiffres du numéro), teinte douce tirée de l'identifiant
const AVATAR_TONES = ['bg-emerald-100 text-emerald-900', 'bg-sky-100 text-sky-900', 'bg-ocre-100 text-ocre-900', 'bg-slate-200 text-slate-800']
function initials(c) {
  const name = (c?.name ?? '').replace(/[^\p{L}\p{N} ]/gu, ' ').trim()
  if (!name) return (c?.phone ?? '').replace(/\D/g, '').slice(-2) || '?'
  const words = name.split(/\s+/)
  return (words[0][0] + (words[1]?.[0] ?? words[0][1] ?? '')).toUpperCase()
}
const avatarTone = (c) => AVATAR_TONES[(c?.id ?? 0) % AVATAR_TONES.length]
// Commande de la conversation : ocre quand elle attend le client, bleu ciel ensuite
const orderTone = (o) => (o?.status === 'pending_confirmation' ? 'bg-ocre-50 text-ocre-800' : ['cancelled', 'returned'].includes(o?.status) ? 'bg-slate-100 text-slate-700' : 'bg-sky-100 text-sky-800')
const money = (n) => `${new Intl.NumberFormat('fr-FR').format(n ?? 0)} F`

// Fenêtre de 24 h (connexion officielle de Meta) : sous le nom, en ocre dans les 2 dernières heures
const now = ref(Date.now())
const windowLeft = computed(() => {
  const c = current.value
  if (!c) return null
  if (c.linked) return { text: 'WhatsApp relié par QR code', warn: false }
  if (!c.reply_until) return null
  const minutes = Math.floor((new Date(c.reply_until).getTime() - now.value) / 60000)
  if (minutes <= 0) return { text: 'Réponse fermée : plus de 24 h', warn: true }
  return { text: `Réponse possible encore ${minutes >= 60 ? `${Math.floor(minutes / 60)} h` : `${minutes} min`}`, warn: minutes < 120 }
})

// Menus : ⋮ de la liste (numéros, icône sur l'écran d'accueil), ⋮ de la discussion, bouton + (actions de vente)
const listMenu = ref(false)
const chatMenu = ref(false)
const actions = ref(false)
function closeMenus() {
  listMenu.value = false
  chatMenu.value = false
  actions.value = false
}
// Colonne « client et commande » à droite (grand écran), ouverte par défaut
const PANEL = 'jibiat-inbox-panel'
const panelOpen = ref((() => {
  try {
    return localStorage.getItem(PANEL) !== '0'
  } catch {
    return true
  }
})())
function togglePanel() {
  panelOpen.value = !panelOpen.value
  try {
    localStorage.setItem(PANEL, panelOpen.value ? '1' : '0')
  } catch {
    // stockage indisponible : réglage gardé le temps de la visite
  }
}
// Commande épinglée : « Confirmer » quand le client n'a pas encore touché « Je confirme » d'un récap
const pendingRecap = computed(() => current.value?.recap?.pending ?? null)
const pinned = computed(() => current.value?.order ?? (pendingRecap.value ? { id: pendingRecap.value.id, reference: pendingRecap.value.reference, total: pendingRecap.value.total, status: 'pending_confirmation', status_label: 'À confirmer' } : null))
function runAction(kind) {
  actions.value = false
  if (kind === 'suggest') return suggest()
  if (kind === 'select') return toggleSelecting()
  return recapAction(kind)
}

// Icône « Messages » sur l'écran d'accueil : manifeste propre à Messages tant que l'écran est ouvert ; Android installe
// d'un geste, l'iPhone montre Partager › Sur l'écran d'accueil
const MESSAGES_MANIFEST = '/gestion/messages/manifest.webmanifest'
let manifestBefore = null
function useMessagesManifest(on) {
  const link = document.querySelector('link[rel="manifest"]')
  if (!link) return
  if (on && !link.getAttribute('href').startsWith(MESSAGES_MANIFEST)) {
    manifestBefore = link.getAttribute('href')
    install.prompt = null // l'invitation reçue avant concernait l'application entière
    link.setAttribute('href', MESSAGES_MANIFEST)
  } else if (!on && manifestBefore) {
    link.setAttribute('href', manifestBefore)
    install.prompt = null
  }
}
const installHelp = ref(false)
const INSTALL_TIP = 'jibiat-inbox-install-tip'
const installTipHidden = ref((() => {
  try {
    return localStorage.getItem(INSTALL_TIP) === '1'
  } catch {
    return false
  }
})())
const canOfferInstall = computed(() => !install.installed && (!!install.prompt || isIos()))
async function addToHomeScreen() {
  listMenu.value = false
  if (install.prompt) {
    if (await promptInstall()) hideInstallTip()
    return
  }
  installHelp.value = true
}
function hideInstallTip() {
  installTipHidden.value = true
  try {
    localStorage.setItem(INSTALL_TIP, '1')
  } catch {
    // stockage indisponible
  }
}
</script>

<template>
  <div class="space-y-4" @keydown.esc="closeMenus">
    <!-- Numéro officiel bloqué par Meta -->
    <div v-if="metaBlock" class="flex-col gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900 sm:flex-row sm:items-center" :class="id ? 'hidden lg:flex' : 'flex'">
      <span class="min-w-0 flex-1"><b>Meta bloque vos messages WhatsApp</b><template v-if="metaBlock.reason"> : {{ metaBlock.reason }}</template>.</span>
      <RouterLink v-if="canManage" to="/gestion/whatsapp" class="font-semibold text-red-800 underline">Voir quoi faire</RouterLink>
    </div>
    <!-- WhatsApp relié par QR code (autorisé par la console pour cette boutique) -->
    <WhatsAppLink v-if="showLink && offerLink" compact @linked="onLinked" />
    <Alert v-if="justLinked" tone="success">WhatsApp relié : les messages de vos clients arrivent maintenant ici.</Alert>
    <!-- Aucun WhatsApp : la connexion se fait avec Facebook dans WhatsApp › Accueil -->
    <div v-if="!metaBlock && listLoaded && !connected && canManage" class="flex-col gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 sm:flex-row sm:items-center" :class="id ? 'hidden lg:flex' : 'flex'">
      <span class="flex min-w-0 flex-1 items-start gap-2"><Icon name="messages" :size="20" class="mt-0.5 shrink-0" />Recevez ici les messages WhatsApp de vos clients : connectez votre numéro avec Facebook.</span>
      <RouterLink to="/gestion/whatsapp" class="btn-brand px-3 py-1.5 text-sm">Connecter mon WhatsApp</RouterLink>
      <button v-if="offerLink && !showLink" type="button" class="btn-secondary px-3 py-1.5 text-sm" @click="showLink = true">Relier par QR code</button>
    </div>
    <Alert v-else-if="listLoaded && !connected && !canManage" tone="warning">
      Les messages des clients arrivent ici quand {{ sources }} {{ metaMessages ? 'sont connectés' : 'est connecté' }}. Demandez au gérant de la boutique de les relier.
    </Alert>

    <div class="card grid grid-cols-1 overflow-hidden lg:grid-cols-[20rem_1fr]" :class="{ 'xl:grid-cols-[20rem_1fr_17rem]': current && panelOpen }" :style="{ height: `calc(100vh - ${9 + banner}rem)`, minHeight: '28rem' }">
      <!-- Conversations -->
      <aside class="relative flex min-h-0 min-w-0 flex-col border-slate-200 lg:border-r" :class="{ 'hidden lg:flex': id }">
        <div class="space-y-2 p-3 pb-2">
          <div class="flex items-center gap-2">
            <label class="flex min-w-0 flex-1 items-center gap-2 rounded-full bg-slate-100 px-3 py-2 text-slate-500 focus-within:ring-2 focus-within:ring-emerald-600">
              <Icon name="search" :size="18" class="shrink-0" />
              <input v-model="search" class="min-w-0 flex-1 bg-transparent text-base text-slate-900 outline-none placeholder:text-slate-500" type="search" placeholder="Nom ou numéro" aria-label="Chercher une conversation" />
            </label>
            <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-slate-600 hover:bg-slate-100" aria-label="Plus d'options" :aria-expanded="listMenu" @click="listMenu = !listMenu">
              <Icon name="more-vertical" :size="22" />
            </button>
          </div>
          <div class="-mx-3 flex gap-1.5 overflow-x-auto px-3 pb-1" role="radiogroup" aria-label="Filtrer les conversations">
            <button
              v-for="f in filters"
              :key="f.value"
              type="button"
              role="radio"
              class="shrink-0 rounded-full px-3 py-1 text-sm font-semibold"
              :class="filter === f.value ? 'bg-emerald-100 text-emerald-900' : f.value === 'pending' ? 'bg-ocre-50 text-ocre-800 hover:bg-ocre-100' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
              :aria-checked="filter === f.value"
              @click="filter = f.value"
            >
              {{ f.label }}
            </button>
          </div>
        </div>

        <!-- Menu de la liste : numéros connectés, icône Messages sur l'écran d'accueil -->
        <div v-if="listMenu" class="fixed inset-0 z-20" aria-hidden="true" @click="listMenu = false" />
        <div v-if="listMenu" class="absolute right-3 top-14 z-30 w-72 max-w-[calc(100%-1.5rem)] rounded-2xl border border-slate-200 bg-white p-2 text-sm shadow-lg" role="menu">
          <div v-for="n in numbers" :key="n.kind" class="flex items-center gap-2 rounded-xl px-3 py-2">
            <Icon name="messages" :size="18" class="shrink-0 text-emerald-700" />
            <span class="min-w-0 flex-1"><b class="block truncate">{{ n.phone || 'Numéro en cours d\'activation' }}</b><span class="text-xs text-slate-600">{{ NUMBER_KIND[n.kind] }}</span></span>
            <button v-if="canManage" type="button" class="btn-ghost px-2 py-1 text-sm text-red-600" :disabled="disconnecting === n.kind" @click="disconnectNumber(n)">
              {{ disconnecting === n.kind ? '…' : 'Déconnecter' }}
            </button>
          </div>
          <p v-if="disconnectError" class="px-3 py-1 text-sm text-red-600" role="alert">{{ disconnectError }}</p>
          <button v-if="offerLink && !showLink" type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left hover:bg-slate-50" role="menuitem" @click="showLink = true; listMenu = false">
            <Icon name="phone" :size="18" class="text-slate-600" />Relier un WhatsApp par QR code
          </button>
          <button v-if="!install.installed" type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left hover:bg-slate-50" role="menuitem" @click="addToHomeScreen">
            <Icon name="install" :size="18" class="text-slate-600" />Ajouter Messages à l'écran d'accueil
          </button>
          <RouterLink v-if="canManage" to="/gestion/whatsapp" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-slate-50" role="menuitem" @click="listMenu = false">
            <Icon name="whatsapp" :size="18" class="text-slate-600" />Accueil WhatsApp
          </RouterLink>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
          <p v-if="!listLoaded" class="p-4 text-sm text-slate-500">Chargement…</p>
          <p v-else-if="!conversations.length" class="p-4 text-sm text-slate-600">{{ search || filter !== 'all' ? 'Aucune conversation ne correspond.' : 'Aucune conversation pour l\'instant.' }}</p>
          <RouterLink
            v-for="c in conversations"
            :key="c.id"
            :to="`/gestion/messages/${c.id}`"
            :replace="!!id"
            class="flex items-center gap-3 px-3 py-2.5 hover:bg-slate-50"
            :class="{ 'bg-emerald-50 hover:bg-emerald-50': String(c.id) === id }"
          >
            <span class="relative grid h-12 w-12 shrink-0 place-items-center rounded-full text-base font-bold" :class="avatarTone(c)" aria-hidden="true">
              {{ initials(c) }}
              <NetworkMark v-if="c.channel && c.channel !== 'whatsapp'" :network="c.channel" class="absolute -bottom-0.5 -right-0.5 ring-2 ring-white" />
            </span>
            <span class="min-w-0 flex-1 border-b border-slate-100 pb-2.5 pt-0.5">
              <span class="flex items-baseline gap-2">
                <span class="min-w-0 flex-1 truncate text-base" :class="c.unread ? 'font-bold' : 'font-semibold'">{{ c.name || c.phone_display }}</span>
                <span class="shrink-0 text-xs" :class="c.unread ? 'font-semibold text-emerald-700' : 'text-slate-500'">{{ time(c.last_message_at) }}</span>
              </span>
              <span class="mt-0.5 flex items-center gap-2">
                <span class="min-w-0 flex-1 truncate text-sm" :class="c.unread ? 'text-slate-900' : 'text-slate-600'"><Icon v-if="drafts[c.id] && String(c.id) !== id" name="file" :size="13" class="mr-0.5 inline-block align-[-2px] text-ocre-600" /><span class="sr-only">{{ channelLabel(c) }} · </span>{{ plain(c.preview) }}</span>
                <span v-if="c.order" class="shrink-0 rounded-md px-1.5 text-xs font-semibold" :class="orderTone(c.order)" :title="c.order.status_label">{{ c.order.status === 'pending_confirmation' ? 'À confirmer' : c.order.reference }}</span>
                <span v-if="c.unread" class="grid h-5 min-w-5 shrink-0 place-items-center rounded-full bg-emerald-700 px-1.5 text-xs font-bold text-white" :aria-label="`${c.unread} non lu${c.unread > 1 ? 's' : ''}`">{{ c.unread }}</span>
              </span>
            </span>
          </RouterLink>
          <button v-if="listPage < listLastPage" type="button" class="w-full p-3 text-sm font-medium text-emerald-700 hover:bg-slate-50" @click="loadList(true)">Voir plus de conversations</button>
        </div>
        <!-- Une fois : proposer l'icône Messages sur l'écran d'accueil -->
        <div v-if="listLoaded && canOfferInstall && !installTipHidden" class="flex items-center gap-3 border-t border-slate-100 bg-emerald-50 px-3 py-2.5">
          <Icon name="install" :size="20" class="shrink-0 text-emerald-700" />
          <span class="min-w-0 flex-1 text-sm text-emerald-900"><b class="block">Messages d'un geste</b>Ajoutez son icône sur l'écran d'accueil.</span>
          <button type="button" class="btn-brand px-3 py-1.5 text-sm" @click="addToHomeScreen">Ajouter</button>
          <button type="button" class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-emerald-800" aria-label="Ne plus proposer" @click="hideInstallTip"><Icon name="close" :size="16" /></button>
        </div>
      </aside>

      <!-- Discussion : plein écran sur téléphone, un seul en-tête -->
      <section v-if="current" class="fixed inset-0 z-50 flex min-h-0 min-w-0 flex-col bg-slate-100 lg:relative lg:inset-auto lg:z-auto">
        <header class="flex shrink-0 items-center gap-1 bg-emerald-800 px-1.5 pb-2 pt-[max(0.5rem,env(safe-area-inset-top))] text-white lg:px-3">
          <button type="button" class="grid h-10 w-9 shrink-0 place-items-center rounded-full hover:bg-white/10 lg:hidden" aria-label="Retour aux conversations" @click="backToList"><Icon name="back" :size="22" /></button>
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-bold" :class="avatarTone(current)" aria-hidden="true">{{ initials(current) }}</span>
          <div class="ml-1.5 min-w-0 flex-1 leading-tight">
            <p class="truncate text-base font-bold">{{ current.name || current.phone_display }}</p>
            <p class="truncate text-xs">
              <span v-if="windowLeft" class="rounded px-1" :class="windowLeft.warn ? 'bg-ocre-100 font-semibold text-ocre-900' : 'text-emerald-50'">{{ windowLeft.text }}</span>
              <span v-else class="text-emerald-50">{{ channelLabel(current) }}<template v-if="current.phone"> · {{ current.phone_display }}</template></span>
            </p>
          </div>
          <RouterLink v-if="canOrder && !selecting" :to="current.order ? `/gestion/commandes/${current.order.id}` : { path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }"
                      class="grid h-10 w-10 shrink-0 place-items-center rounded-full hover:bg-white/10" :aria-label="current.order ? `Commande ${current.order.reference}` : 'Créer la commande'" :title="current.order ? `Commande ${current.order.reference}` : 'Créer la commande avec les messages du client'">
            <Icon name="orders" :size="21" />
          </RouterLink>
          <button type="button" class="hidden h-10 w-10 shrink-0 place-items-center rounded-full hover:bg-white/10 xl:grid" :aria-pressed="panelOpen" aria-label="Client et commande" title="Client et commande" @click="togglePanel"><Icon name="panel" :size="20" /></button>
          <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full hover:bg-white/10" aria-label="Plus d'options" :aria-expanded="chatMenu" @click="chatMenu = !chatMenu"><Icon name="more-vertical" :size="22" /></button>
        </header>
        <!-- Menu de la discussion -->
        <div v-if="chatMenu" class="fixed inset-0 z-20" aria-hidden="true" @click="chatMenu = false" />
        <div v-if="chatMenu" class="absolute right-2 top-14 z-30 mt-[env(safe-area-inset-top)] w-64 rounded-2xl border border-slate-200 bg-white p-2 text-sm text-slate-900 shadow-lg lg:mt-0" role="menu">
          <RouterLink v-if="current.customer && auth.can('customers.view')" :to="`/gestion/clients/${current.customer.id}`" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-slate-50" role="menuitem"><Icon name="user" :size="18" class="text-slate-600" />Fiche client</RouterLink>
          <button v-if="canOrder" type="button" class="flex w-full items-center gap-2 rounded-xl px-3 py-2.5 text-left hover:bg-slate-50" role="menuitem" @click="chatMenu = false; toggleSelecting()"><Icon name="select" :size="18" class="text-slate-600" />{{ selecting ? 'Annuler le choix des messages' : 'Choisir les messages de la commande' }}</button>
          <RouterLink v-if="canOrder && current.order" :to="{ path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-slate-50" role="menuitem"><Icon name="plus" :size="18" class="text-slate-600" />Nouvelle commande</RouterLink>
          <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="flex items-center gap-2 rounded-xl px-3 py-2.5 hover:bg-slate-50" role="menuitem"><Icon name="whatsapp" :size="18" class="text-slate-600" />Écrire depuis WhatsApp</a>
          <p class="px-3 pb-1 pt-2 text-xs text-slate-600">{{ channelLabel(current) }}<template v-if="current.phone"> · {{ current.phone_display }}</template></p>
        </div>

        <!-- Commande épinglée -->
        <div v-if="pinned && !selecting" class="flex shrink-0 items-center gap-2.5 border-b border-slate-200 bg-white px-3 py-2">
          <Icon name="receipt" :size="16" class="shrink-0 text-ocre-600" />
          <RouterLink :to="`/gestion/commandes/${pinned.id}`" class="min-w-0 flex-1 truncate text-sm">
            <b>Commande {{ pinned.reference }}</b><template v-if="pinned.total"> · {{ money(pinned.total) }}</template>
            <span class="ml-1 rounded-md px-1.5 text-xs font-semibold" :class="orderTone(pinned)">{{ pinned.status_label }}</span>
          </RouterLink>
          <button v-if="pendingRecap" type="button" class="shrink-0 rounded-full bg-emerald-700 px-3 py-1 text-sm font-semibold text-white hover:bg-emerald-800" :disabled="!!recapBusy" @click="recapAction('confirm')">{{ recapBusy === 'confirm' ? '…' : 'Confirmer' }}</button>
        </div>
        <p v-if="selecting" class="shrink-0 border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">Touchez les messages du client qui forment la commande, puis « Créer la commande ».</p>

        <div ref="thread" class="chat-wall min-h-0 flex-1 space-y-1.5 overflow-y-auto px-3 py-3 sm:px-6" @scroll.passive="onScroll">
          <div v-if="current.has_more" class="text-center">
            <button type="button" class="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-700 shadow-sm" :disabled="loadingOlder" @click="loadOlder">{{ loadingOlder ? 'Chargement…' : 'Voir les messages précédents' }}</button>
          </div>
          <template v-for="row in rows" :key="row.key">
            <p v-if="row.day" class="py-1.5 text-center"><span class="rounded-lg bg-white px-2.5 py-0.5 text-xs font-medium text-slate-600 shadow-sm first-letter:uppercase">{{ row.day }}</span></p>
            <div v-else class="flex items-center gap-1.5" :class="row.message.direction === 'out' ? 'flex-row-reverse' : ''">
              <div
                class="max-w-[85%] rounded-2xl px-2.5 pb-1 pt-1.5 text-base shadow-sm sm:max-w-[70%]"
                :class="[
                  row.message.direction === 'in' ? (row.message.type === 'order' ? 'rounded-tl-md bg-ocre-50 ring-1 ring-ocre-200' : 'rounded-tl-md bg-white') : row.message.status === 'failed' ? 'rounded-tr-md bg-red-50 ring-1 ring-red-200' : row.message.type === 'template' ? 'rounded-tr-md bg-slate-200' : 'rounded-tr-md bg-emerald-100',
                  selecting && row.message.direction === 'in' ? 'cursor-pointer' : '',
                  selected.includes(row.message.id) ? 'ring-2 ring-emerald-600' : '',
                ]"
                :role="selecting && row.message.direction === 'in' ? 'checkbox' : undefined"
                :aria-checked="selecting && row.message.direction === 'in' ? selected.includes(row.message.id) : undefined"
                :tabindex="selecting && row.message.direction === 'in' ? 0 : undefined"
                @click="toggleMessage(row.message)"
                @keydown.enter.prevent="toggleMessage(row.message)"
                @keydown.space.prevent="toggleMessage(row.message)"
              >
                <span v-if="selecting && row.message.direction === 'in'" class="mb-1 flex items-center gap-1.5 text-xs font-medium" :class="selected.includes(row.message.id) ? 'text-emerald-700' : 'text-slate-500'">
                  <span class="grid h-4 w-4 place-items-center rounded border" :class="selected.includes(row.message.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-field'">{{ selected.includes(row.message.id) ? '✓' : '' }}</span>
                  {{ selected.includes(row.message.id) ? 'Dans la commande' : 'Ajouter à la commande' }}
                </span>
                <!-- Qui a écrit, pour les messages partis sans l'équipe (assistant, message automatique, téléphone) -->
                <span v-if="row.message.direction === 'out' && row.message.by" class="block text-xs font-bold text-emerald-800">{{ row.message.by }}</span>
                <MessagePhoto v-if="row.message.media_url" :key="row.message.media_url" :src="row.message.media_url" alt="Photo envoyée" />
                <MessagePhoto v-else-if="row.message.photo" :key="`p${row.message.id}`" :path="`/conversations/${current.id}/messages/${row.message.id}/photo`" alt="Photo du client" />
                <p class="whitespace-pre-wrap break-words leading-snug">
                  <template v-for="(part, i) in linkify(row.message.body ?? '')" :key="i">
                    <a v-if="i % 2" :href="part" target="_blank" rel="noopener" class="text-emerald-700 underline">{{ part }}</a>
                    <template v-else><template v-for="(bit, j) in boldParts(part)" :key="j"><b v-if="j % 2">{{ bit }}</b><template v-else>{{ bit }}</template></template></template>
                  </template>
                </p>
                <!-- Choix proposés au client : boutons, ou lignes d'une liste -->
                <div v-if="row.message.options?.buttons" class="-mx-2.5 mt-1.5 divide-y divide-emerald-200 border-t border-emerald-200">
                  <span v-for="b in row.message.options.buttons" :key="b" class="block py-1.5 text-center text-sm font-semibold text-emerald-800">{{ b }}</span>
                </div>
                <div v-else-if="row.message.options?.rows" class="mt-1.5 rounded-xl bg-white/70 p-2 text-sm">
                  <p class="mb-1 font-semibold text-emerald-800">{{ row.message.options.button || 'Choisir' }}</p>
                  <ol class="space-y-0.5">
                    <li v-for="(r, i) in row.message.options.rows" :key="i"><b>{{ r.title }}</b><span v-if="r.description" class="text-slate-600"> · {{ r.description }}</span></li>
                  </ol>
                </div>
                <p class="mt-0.5 flex items-center justify-end gap-1 text-xs text-slate-500" :title="formatDateTime(row.message.sent_at)">
                  {{ hour(row.message.sent_at) }}
                  <span v-if="row.message.direction === 'out' && statusIcon[row.message.status]" class="font-bold" :class="row.message.status === 'read' ? 'text-sky-600' : row.message.status === 'failed' ? 'text-red-600' : ''" :aria-label="STATUS_LABEL[row.message.status]">{{ statusIcon[row.message.status] }}</span>
                </p>
              </div>
              <!-- Message qui parle d'achat : un petit sac pour en faire une commande -->
              <RouterLink v-if="row.message.order_hint && canOrder && !selecting" :to="orderLink([row.message.id])" class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-slate-200 bg-white text-emerald-700 shadow-sm hover:bg-emerald-50" aria-label="Faire une commande avec ce message" title="Faire une commande avec ce message">
                <Icon name="orders" :size="15" />
              </RouterLink>
            </div>
            <!-- Échec d'envoi : la raison et un nouvel essai -->
            <p v-if="row.message?.status === 'failed'" class="flex flex-wrap items-center justify-end gap-2 text-xs text-red-700">
              <span>Non envoyé{{ row.message.error ? ' : ' + row.message.error.replace(/^WhatsApp : /, '') : '' }}</span>
              <button v-if="current.can_reply && !selecting" type="button" class="font-medium underline" :disabled="sending" @click="send(row.message.body)">Renvoyer</button>
            </p>
          </template>
          <!-- Résultat du récap : une ligne dans le fil -->
          <p v-if="recapResult" class="py-1 text-center" role="status">
            <span class="inline-block max-w-[90%] rounded-lg px-3 py-1 text-sm font-medium" :class="recapResult.sent ? 'bg-sky-100 text-sky-800' : 'bg-ocre-50 text-ocre-900'">{{ recapResult.message }}</span>
          </p>
        </div>
        <button v-if="newBelow" type="button" class="absolute bottom-24 left-1/2 -translate-x-1/2 rounded-full bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white shadow-lg" @click="scrollDown">Nouveaux messages ↓</button>

        <div v-if="selecting" class="flex shrink-0 flex-wrap items-center gap-2 border-t border-slate-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <span class="text-sm text-slate-600">{{ selected.length ? `${selected.length} message${selected.length > 1 ? 's' : ''} choisi${selected.length > 1 ? 's' : ''}` : 'Aucun message choisi' }}</span>
          <button type="button" class="btn-ghost ml-auto px-3 py-1.5 text-sm" @click="toggleSelecting">Annuler</button>
          <RouterLink v-if="selected.length" :to="orderLink(current.messages.filter((m) => selected.includes(m.id)).map((m) => m.id))" class="btn-brand px-3 py-1.5 text-sm">Créer la commande</RouterLink>
        </div>

        <form v-else-if="current.can_reply" class="chat-wall shrink-0 space-y-1.5 px-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] pt-1 sm:px-4" @submit.prevent="send()">
          <p v-if="replyError" class="rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-700" role="alert">{{ replyError }}</p>
          <p v-if="suggestionCheck" class="rounded-lg bg-ocre-50 px-3 py-1.5 text-sm text-ocre-900">À vérifier : {{ suggestionCheck }}</p>
          <!-- Suggestions du moment : récap ou confirmation, réponse proposée par l'IA -->
          <div v-if="current.recap || ai.enabled" class="flex gap-1.5 overflow-x-auto">
            <button v-if="current.recap && !pendingRecap" type="button" class="flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-semibold text-emerald-800 shadow-sm" :disabled="!!recapBusy" @click="recapAction('send')">
              <Icon name="receipt" :size="15" />{{ recapBusy === 'send' ? 'Préparation…' : 'Envoyer le récap' }}
            </button>
            <button v-if="ai.enabled" type="button" class="flex shrink-0 items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1 text-sm font-semibold text-emerald-800 shadow-sm" :disabled="suggesting || aiExhausted(ai)" @click="suggest">
              <Icon name="sparkles" :size="15" />{{ suggesting ? 'Rédaction…' : 'Proposer une réponse' }}
            </button>
          </div>
          <div class="flex items-end gap-1.5">
            <div class="flex min-w-0 flex-1 items-end gap-1 rounded-3xl bg-white px-1.5 shadow-sm focus-within:ring-2 focus-within:ring-emerald-600">
              <button type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-slate-600 hover:bg-slate-100" aria-label="Actions de vente" :aria-expanded="actions" @click="actions = !actions"><Icon name="plus" :size="22" /></button>
              <textarea
                ref="input"
                v-model="reply"
                class="max-h-[200px] min-h-10 min-w-0 flex-1 resize-none bg-transparent py-2 text-base text-slate-900 outline-none placeholder:text-slate-500"
                rows="1"
                maxlength="4000"
                placeholder="Message"
                aria-label="Votre réponse"
                @input="grow"
                @keydown.enter="onEnter"
              />
            </div>
            <button class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-emerald-700 text-white shadow-sm hover:bg-emerald-800 disabled:opacity-60" :disabled="sending || !reply.trim()" aria-label="Envoyer"><Icon name="send" :size="19" /></button>
          </div>
          <p v-if="enterSends" class="hidden px-2 text-xs text-slate-500 lg:block">Entrée pour envoyer, Maj + Entrée pour aller à la ligne.</p>
        </form>
        <div v-else class="shrink-0 border-t border-slate-200 bg-white p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-sm text-slate-700">
          <b class="block">Le client a écrit il y a plus de 24 h.</b>
          <span class="explain-detail">{{ current.channel === 'whatsapp' ? 'Meta ne permet plus de lui répondre d\'ici.' : `${channelLabel(current)} ne permet plus de lui répondre d'ici.` }}</span>
          <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="ml-1 font-medium text-emerald-700 underline">Écrire depuis WhatsApp</a>
        </div>

        <!-- Bouton + : les actions de vente, comme les pièces jointes de WhatsApp -->
        <template v-if="actions">
          <div class="absolute inset-0 z-30 bg-slate-900/40" aria-hidden="true" @click="actions = false" />
          <div class="absolute inset-x-0 bottom-0 z-40 rounded-t-3xl bg-white px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-2 shadow-lg" role="dialog" aria-label="Actions pour ce client">
            <span class="mx-auto mb-3 block h-1 w-10 rounded-full bg-slate-200" aria-hidden="true" />
            <p class="mb-3 font-display text-base font-bold">Pour ce client</p>
            <div class="grid grid-cols-4 gap-x-2 gap-y-4">
              <button v-if="current.recap" type="button" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700" :disabled="!!recapBusy" @click="runAction('send')">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-emerald-700 text-white"><Icon name="receipt" :size="22" /></span>Envoyer le récap
              </button>
              <button v-if="pendingRecap" type="button" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700" :disabled="!!recapBusy" @click="runAction('confirm')">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-sky-700 text-white"><Icon name="check" :size="22" /></span>Confirmer la commande
              </button>
              <RouterLink v-if="canOrder" :to="{ path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-emerald-800 text-white"><Icon name="orders" :size="22" /></span>{{ current.order ? 'Nouvelle commande' : 'Créer la commande' }}
              </RouterLink>
              <button v-if="canOrder" type="button" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700" @click="runAction('select')">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-slate-700 text-white"><Icon name="select" :size="22" /></span>Choisir les messages
              </button>
              <button v-if="ai.enabled" type="button" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700" :disabled="suggesting || aiExhausted(ai)" @click="runAction('suggest')">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-violet-700 text-white"><Icon name="sparkles" :size="22" /></span>Proposer une réponse
              </button>
              <RouterLink v-if="current.order" :to="`/gestion/commandes/${current.order.id}`" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-ocre-700 text-white"><Icon name="box" :size="22" /></span>Commande {{ current.order.reference }}
              </RouterLink>
              <RouterLink v-if="current.customer && auth.can('customers.view')" :to="`/gestion/clients/${current.customer.id}`" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-slate-600 text-white"><Icon name="user" :size="22" /></span>Fiche client
              </RouterLink>
              <RouterLink to="/gestion/livraisons/tarifs" class="flex flex-col items-center gap-1.5 text-center text-xs font-medium text-slate-700">
                <span class="grid h-12 w-12 place-items-center rounded-full bg-ocre-600 text-white"><Icon name="truck" :size="22" /></span>Prix de livraison
              </RouterLink>
            </div>
            <AiMeter v-if="ai.enabled" class="mt-4 border-t border-slate-100 pt-3" />
          </div>
        </template>
      </section>
      <section v-else class="hidden place-items-center p-6 text-center lg:grid chat-wall">
        <div class="rounded-2xl bg-white/80 px-6 py-5">
          <Icon name="messages" :size="36" class="mx-auto text-emerald-700" />
          <p class="explain mt-2">Choisissez une conversation.</p>
          <p class="explain-detail mt-1">Les messages arrivent ici dès que vos clients écrivent sur {{ sources }}.</p>
        </div>
      </section>

      <!-- Grand écran : le client et sa commande -->
      <aside v-if="current && panelOpen" class="hidden min-h-0 flex-col gap-4 overflow-y-auto border-l border-slate-200 p-4 xl:flex">
        <div class="text-center">
          <span class="mx-auto grid h-16 w-16 place-items-center rounded-full text-2xl font-bold" :class="avatarTone(current)" aria-hidden="true">{{ initials(current) }}</span>
          <p class="mt-2 text-lg font-bold">{{ current.name || current.phone_display }}</p>
          <p class="text-sm text-slate-600">{{ channelLabel(current) }}<template v-if="current.phone"> · {{ current.phone_display }}</template></p>
        </div>
        <div v-if="pinned" class="space-y-2 rounded-2xl border border-slate-200 p-3 text-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Commande en cours</p>
          <p class="flex justify-between gap-2"><span>{{ pinned.reference }}</span><b class="tabular-nums">{{ money(pinned.total) }}</b></p>
          <p class="flex items-center justify-between gap-2"><span>Statut</span><span class="rounded-md px-1.5 text-xs font-semibold" :class="orderTone(pinned)">{{ pinned.status_label }}</span></p>
          <div class="flex flex-wrap gap-2 pt-1">
            <button v-if="pendingRecap" type="button" class="btn-brand px-3 py-1.5 text-sm" :disabled="!!recapBusy" @click="recapAction('confirm')">Confirmer</button>
            <RouterLink :to="`/gestion/commandes/${pinned.id}`" class="btn-secondary px-3 py-1.5 text-sm">Ouvrir</RouterLink>
          </div>
        </div>
        <div class="space-y-2 rounded-2xl border border-slate-200 p-3 text-sm">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Client</p>
          <template v-if="current.customer">
            <p class="flex justify-between gap-2"><span>Commandes</span><b class="tabular-nums">{{ current.customer.orders }}</b></p>
            <p class="flex justify-between gap-2"><span>Dépensé</span><b class="tabular-nums">{{ money(current.customer.spent) }}</b></p>
          </template>
          <p v-else class="explain-detail">Pas encore de fiche : elle se crée avec sa première commande.</p>
          <div class="flex flex-wrap gap-2 pt-1">
            <RouterLink v-if="current.customer && auth.can('customers.view')" :to="`/gestion/clients/${current.customer.id}`" class="btn-secondary px-3 py-1.5 text-sm">Fiche client</RouterLink>
            <RouterLink v-if="canOrder" :to="{ path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }" class="btn-secondary px-3 py-1.5 text-sm">{{ current.order ? 'Nouvelle commande' : 'Créer la commande' }}</RouterLink>
          </div>
        </div>
      </aside>
    </div>

    <!-- Icône Messages : iPhone (Partager › Sur l'écran d'accueil) ou navigateur sans installation en un geste -->
    <div v-if="installHelp" class="fixed inset-0 z-[60] grid place-items-end bg-slate-900/40 p-0 sm:place-items-center sm:p-4" @click.self="installHelp = false">
      <div class="w-full max-w-md rounded-t-3xl bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] sm:rounded-3xl" role="dialog" aria-label="Ajouter Messages à l'écran d'accueil">
        <p class="explain">Ajouter Messages à l'écran d'accueil</p>
        <p class="explain-detail mt-1">L'icône ouvre directement vos conversations.</p>
        <ol v-if="isIos()" class="mt-4 space-y-3 text-base">
          <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">1</span><span>Touchez <b>Partager</b> en bas de Safari (le carré avec une flèche vers le haut).</span></li>
          <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">2</span><span>Choisissez <b>Sur l'écran d'accueil</b>, puis <b>Ajouter</b>.</span></li>
        </ol>
        <ol v-else class="mt-4 space-y-3 text-base">
          <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">1</span><span>Ouvrez le menu du navigateur <b>⋮</b>.</span></li>
          <li class="flex gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-900">2</span><span>Choisissez <b>Installer l'application</b> ou <b>Ajouter à l'écran d'accueil</b>.</span></li>
        </ol>
        <button type="button" class="btn-brand mt-5 w-full" @click="installHelp = false; hideInstallTip()">C'est noté</button>
      </div>
    </div>
  </div>
</template>
