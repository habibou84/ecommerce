<script setup>
import AiMeter from '../../../components/AiMeter.vue'
import { aiExhausted, countAiAction } from '../../../lib/aiQuota'
import NetworkMark from '../../../components/NetworkMark.vue'
import Icon from '../../../components/Icon.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

// Boîte « Messages » : conversations WhatsApp, Messenger et Instagram des clients, réponses, et commande créée depuis la conversation
const props = defineProps({ id: { type: String, default: null } })
const auth = useAuthStore()

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
const NUMBER_KIND = { official: 'Numéro officiel (Meta)', linked: 'WhatsApp relié par QR code' }
// Bandeau au-dessus de la boîte : la boîte raccourcit d'autant (sur téléphone, caché dans une conversation)
const banner = computed(() => {
  if (!listLoaded.value || (props.id && window.innerWidth < 1024)) return 0
  const blocked = metaBlock.value ? 3.5 : 0
  if (numbers.value.length) return blocked + 1.5 + 2.5 * numbers.value.length
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
    metaBlock.value = last.meta ?? null
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
  timer = setInterval(() => document.visibilityState === 'visible' && refresh(), 15000)
})
onBeforeUnmount(() => {
  clearInterval(timer)
  saveDraft(props.id, reply.value)
})
watch(() => props.id, (id, previous) => {
  saveDraft(previous, reply.value)
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
const statusIcon = { queued: '🕓', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠' }
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
const linkify = (text) => text.split(/(https?:\/\/\S+)/g)
</script>

<template>
  <div class="space-y-4">
    <!-- Numéro officiel bloqué par Meta -->
    <div v-if="metaBlock" class="flex-col gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900 sm:flex-row sm:items-center" :class="id ? 'hidden lg:flex' : 'flex'">
      <span class="min-w-0 flex-1"><b>Meta bloque vos messages WhatsApp</b><template v-if="metaBlock.reason"> : {{ metaBlock.reason }}</template>.</span>
      <RouterLink v-if="canManage" to="/gestion/whatsapp" class="font-semibold text-red-800 underline">Voir quoi faire</RouterLink>
    </div>
    <!-- Numéros connectés, avec « Déconnecter » pour le gérant -->
    <div v-if="listLoaded && numbers.length" class="flex-col gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm text-emerald-900" :class="id ? 'hidden lg:flex' : 'flex'">
      <div v-for="n in numbers" :key="n.kind" class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <span class="flex min-w-0 flex-1 items-center gap-2">
          <Icon name="messages" :size="18" class="shrink-0" />
          <span class="min-w-0 truncate"><b>{{ n.phone || 'Numéro en cours d\'activation' }}</b><template v-if="n.name"> · {{ n.name }}</template> <span class="text-emerald-700">· {{ NUMBER_KIND[n.kind] }}</span></span>
        </span>
        <button v-if="canManage" type="button" class="btn-ghost px-2 py-1 text-sm text-red-600" :disabled="disconnecting === n.kind" @click="disconnectNumber(n)">
          {{ disconnecting === n.kind ? 'Déconnexion…' : 'Déconnecter' }}
        </button>
      </div>
      <p v-if="disconnectError" class="text-red-700">{{ disconnectError }}</p>
    </div>
    <!-- Aucun WhatsApp : la connexion se fait avec Facebook dans Réglages › WhatsApp -->
    <div v-else-if="listLoaded && !connected && canManage" class="flex-col gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 sm:flex-row sm:items-center" :class="id ? 'hidden lg:flex' : 'flex'">
      <span class="flex min-w-0 flex-1 items-start gap-2"><Icon name="messages" :size="20" class="mt-0.5 shrink-0" />Recevez ici les messages WhatsApp de vos clients : connectez votre numéro avec Facebook.</span>
      <RouterLink to="/gestion/whatsapp" class="btn-brand px-3 py-1.5 text-sm">Connecter mon WhatsApp</RouterLink>
    </div>
    <Alert v-else-if="listLoaded && !connected" tone="warning">
      Les messages des clients arrivent ici quand {{ sources }} {{ metaMessages ? 'sont connectés' : 'est connecté' }}. Demandez au gérant de la boutique de les relier.
    </Alert>

    <div class="card grid grid-cols-1 overflow-hidden lg:grid-cols-[20rem_1fr]" :style="{ height: `calc(100vh - ${9 + banner}rem)`, minHeight: '28rem' }">
      <!-- Conversations -->
      <aside class="flex min-h-0 min-w-0 flex-col border-slate-200 lg:border-r" :class="{ 'hidden lg:flex': id }">
        <div class="space-y-2 border-b border-slate-100 p-3">
          <input v-model="search" class="input" type="search" placeholder="Nom ou numéro" aria-label="Chercher une conversation" />
          <div class="flex gap-1">
            <button
              v-for="f in filters"
              :key="f.value"
              type="button"
              class="rounded-full px-3 py-1 text-xs font-medium"
              :class="filter === f.value ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
              :aria-pressed="filter === f.value"
              @click="filter = f.value"
            >
              {{ f.label }}
            </button>
          </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
          <p v-if="!listLoaded" class="p-4 text-sm text-slate-500">Chargement…</p>
          <p v-else-if="!conversations.length" class="p-4 text-sm text-slate-500">{{ search || filter !== 'all' ? 'Aucune conversation ne correspond.' : 'Aucune conversation pour l\'instant.' }}</p>
          <RouterLink
            v-for="c in conversations"
            :key="c.id"
            :to="`/gestion/messages/${c.id}`"
            class="block border-b border-slate-100 px-3 py-2.5 hover:bg-slate-50"
            :class="{ 'bg-emerald-50': String(c.id) === id }"
          >
            <div class="flex items-baseline gap-2">
              <NetworkMark :network="c.channel ?? 'whatsapp'" :title="channelLabel(c)" />
              <span class="truncate text-sm" :class="c.unread ? 'font-semibold' : 'font-medium'">{{ c.name || c.phone_display }}</span>
              <span class="ml-auto shrink-0 text-xs text-slate-400">{{ time(c.last_message_at) }}</span>
            </div>
            <div class="mt-0.5 flex items-center gap-2">
              <span class="truncate text-xs" :class="c.unread ? 'text-slate-800' : 'text-slate-500'"><Icon v-if="drafts[c.id] && String(c.id) !== id" name="file" :size="12" class="mr-0.5 inline-block align-[-1px] text-amber-600" />{{ c.preview }}</span>
              <span v-if="c.order" class="ml-auto shrink-0 rounded bg-slate-100 px-1.5 text-[11px] text-slate-600">{{ c.order.reference }}</span>
              <span v-if="c.unread" class="shrink-0 rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white" :class="{ 'ml-auto': !c.order }">{{ c.unread }}</span>
            </div>
          </RouterLink>
          <button v-if="listPage < listLastPage" type="button" class="w-full p-3 text-sm font-medium text-emerald-700 hover:bg-slate-50" @click="loadList(true)">Voir plus de conversations</button>
        </div>
      </aside>

      <!-- Conversation ouverte -->
      <section v-if="current" class="relative flex min-h-0 min-w-0 flex-col">
        <header class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
          <RouterLink to="/gestion/messages" class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Retour aux conversations">←</RouterLink>
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ current.name || current.phone_display }}</p>
            <p class="text-xs text-slate-500">
              {{ channelLabel(current) }}<template v-if="current.phone"> · {{ current.phone_display }}</template>
              <RouterLink v-if="current.customer && auth.can('customers.view')" :to="`/gestion/clients/${current.customer.id}`" class="ml-1 text-emerald-700 hover:underline">Fiche client</RouterLink>
            </p>
          </div>
          <div class="ml-auto flex flex-wrap items-center gap-2">
            <RouterLink v-if="current.order" :to="`/gestion/commandes/${current.order.id}`" class="btn-secondary px-3 py-1.5 text-sm">Commande {{ current.order.reference }}</RouterLink>
            <button v-if="canOrder" type="button" class="btn-secondary px-3 py-1.5 text-sm" :aria-pressed="selecting" @click="toggleSelecting">
              <template v-if="selecting">Annuler</template><template v-else>Choisir<span class="hidden sm:inline"> les messages</span></template>
            </button>
            <RouterLink v-if="canOrder && !selecting" :to="{ path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }" class="btn-brand px-3 py-1.5 text-sm" title="Avec les messages du client depuis la dernière commande">
              {{ current.order ? 'Nouvelle commande' : 'Créer la commande' }}
            </RouterLink>
          </div>
        </header>

        <p v-if="selecting" class="border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">Touchez les messages du client qui forment la commande, puis « Créer la commande ».</p>
        <div ref="thread" class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4" @scroll.passive="onScroll">
          <div v-if="current.has_more" class="text-center">
            <button type="button" class="btn-ghost px-3 py-1 text-xs" :disabled="loadingOlder" @click="loadOlder">{{ loadingOlder ? 'Chargement…' : 'Voir les messages précédents' }}</button>
          </div>
          <template v-for="row in rows" :key="row.key">
            <p v-if="row.day" class="py-1 text-center"><span class="rounded-full bg-white px-3 py-0.5 text-xs font-medium text-slate-500 shadow-sm first-letter:uppercase">{{ row.day }}</span></p>
            <div v-else class="flex flex-col" :class="row.message.direction === 'out' ? 'items-end' : 'items-start'">
              <div
                class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm"
                :class="[
                  row.message.direction === 'in' ? (row.message.type === 'order' ? 'rounded-bl-sm bg-amber-50 ring-1 ring-amber-200' : 'rounded-bl-sm bg-white') : row.message.type === 'template' ? 'rounded-br-sm bg-slate-200 text-slate-700' : row.message.status === 'failed' ? 'rounded-br-sm bg-red-50 ring-1 ring-red-200' : 'rounded-br-sm bg-emerald-100',
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
                <span v-if="selecting && row.message.direction === 'in'" class="mb-1 flex items-center gap-1.5 text-xs font-medium" :class="selected.includes(row.message.id) ? 'text-emerald-700' : 'text-slate-400'">
                  <span class="grid h-4 w-4 place-items-center rounded border" :class="selected.includes(row.message.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'">{{ selected.includes(row.message.id) ? '✓' : '' }}</span>
                  {{ selected.includes(row.message.id) ? 'Dans la commande' : 'Ajouter à la commande' }}
                </span>
                <p class="whitespace-pre-wrap break-words">
                  <template v-for="(part, i) in linkify(row.message.body ?? '')" :key="i">
                    <a v-if="i % 2" :href="part" target="_blank" rel="noopener" class="text-emerald-700 underline">{{ part }}</a>
                    <template v-else>{{ part }}</template>
                  </template>
                </p>
                <p class="mt-1 text-right text-[11px] text-slate-500" :title="formatDateTime(row.message.sent_at)">
                  <span v-if="row.message.by">{{ row.message.by }} · </span>{{ hour(row.message.sent_at) }}
                  <span v-if="row.message.direction === 'out' && statusIcon[row.message.status]" :class="row.message.status === 'read' ? 'text-sky-600' : row.message.status === 'failed' ? 'text-red-600' : ''" :aria-label="STATUS_LABEL[row.message.status]">{{ statusIcon[row.message.status] }}</span>
                </p>
              </div>
              <!-- Échec d'envoi : la raison et un nouvel essai, visibles aussi sur téléphone -->
              <p v-if="row.message.status === 'failed'" class="mt-1 flex max-w-[85%] flex-wrap items-center justify-end gap-2 text-xs text-red-700">
                <span>Non envoyé{{ row.message.error ? ' : ' + row.message.error.replace(/^WhatsApp : /, '') : '' }}</span>
                <button v-if="current.can_reply && !selecting" type="button" class="font-medium underline" :disabled="sending" @click="send(row.message.body)">Renvoyer</button>
              </p>
              <RouterLink v-if="row.message.order_hint && canOrder && !selecting" :to="orderLink([row.message.id])" class="mt-1 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-100">
                <Icon name="orders" :size="14" />Faire une commande
              </RouterLink>
            </div>
          </template>
        </div>
        <button v-if="newBelow" type="button" class="absolute bottom-24 left-1/2 -translate-x-1/2 rounded-full bg-emerald-700 px-3 py-1.5 text-xs font-medium text-white shadow-lg" @click="scrollDown">Nouveaux messages ↓</button>
        <div v-if="selecting" class="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-white p-3">
          <span class="text-sm text-slate-600">{{ selected.length ? `${selected.length} message${selected.length > 1 ? 's' : ''} choisi${selected.length > 1 ? 's' : ''}` : 'Aucun message choisi' }}</span>
          <RouterLink v-if="selected.length" :to="orderLink(current.messages.filter((m) => selected.includes(m.id)).map((m) => m.id))" class="btn-brand ml-auto px-3 py-1.5 text-sm">Créer la commande</RouterLink>
        </div>

        <form v-if="current.can_reply && !selecting" class="space-y-2 border-t border-slate-100 p-3" @submit.prevent="send()">
          <p v-if="replyError" class="text-sm text-red-600" role="alert">{{ replyError }}</p>
          <div v-if="ai.enabled" class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn-secondary px-3 py-1.5 text-sm" :disabled="suggesting || aiExhausted(ai)" @click="suggest">
              <Icon name="sparkles" :size="16" class="mr-1 inline-block align-[-3px]" />{{ suggesting ? 'L\'assistant écrit…' : 'Proposer une réponse' }}
            </button>
            <span class="hidden text-xs text-slate-500 sm:inline">Avec vos prix, votre stock et vos frais de livraison. Relisez avant d'envoyer.</span>
          </div>
          <AiMeter v-if="ai.enabled" />
          <p v-if="suggestionCheck" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">À vérifier : {{ suggestionCheck }}</p>
          <div class="flex items-end gap-2">
            <textarea
              ref="input"
              v-model="reply"
              class="input max-h-[200px] min-h-[2.75rem] resize-none"
              rows="1"
              maxlength="4000"
              placeholder="Votre réponse…"
              aria-label="Votre réponse"
              @input="grow"
              @keydown.enter="onEnter"
            />
            <button class="btn-brand" :disabled="sending || !reply.trim()">{{ sending ? '…' : 'Envoyer' }}</button>
          </div>
          <!-- Règle des 24 h : seulement pour la connexion officielle de Meta, Messenger et Instagram -->
          <p v-if="!current.linked && replyUntil" class="text-xs text-slate-500">
            {{ current.channel === 'whatsapp' ? 'Connexion officielle de Meta' : channelLabel(current) }} : réponse possible jusqu'au {{ replyUntil }}.
          </p>
          <p v-else-if="enterSends" class="text-xs text-slate-400">Entrée pour envoyer, Maj + Entrée pour aller à la ligne.</p>
        </form>
        <div v-else-if="!selecting" class="space-y-2 border-t border-slate-100 p-3 text-sm text-slate-600">
          <p>
            {{ current.channel === 'whatsapp' ? 'Connexion officielle de Meta' : channelLabel(current) }} : le dernier message du client date de plus de 24 h, la réponse n'est plus possible d'ici.
            <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="font-medium text-emerald-700 underline">Écrire depuis WhatsApp</a>
          </p>
        </div>
      </section>
      <section v-else class="hidden place-items-center p-6 text-center text-sm text-slate-500 lg:grid">
        <div>
          <Icon name="messages" :size="36" class="mx-auto text-slate-400" />
          <p class="mt-2">Choisissez une conversation.</p>
          <p class="mt-1">Les messages des clients arrivent ici dès qu'ils écrivent sur {{ sources }}.</p>
        </div>
      </section>
    </div>
  </div>
</template>
