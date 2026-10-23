<script setup>
import NetworkMark from '../../../components/NetworkMark.vue'
import Icon from '../../../components/Icon.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

// Boîte « Messages » : conversations WhatsApp, Messenger et Instagram des clients, réponses, et commande créée depuis la conversation
const props = defineProps({ id: { type: String, default: null } })
const auth = useAuthStore()

const conversations = ref([])
const connected = ref(true)
const listLoaded = ref(false)
const filter = ref('all')
const search = ref('')

const current = ref(null)
const reply = ref('')
const replyError = ref('')
const sending = ref(false)
const thread = ref(null)

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
    if (auth.profile?.ai) auth.profile.ai.used++
  } catch (e) {
    replyError.value = errorMessage(e)
  } finally {
    suggesting.value = false
  }
}

async function loadList() {
  const { data } = await http.get('/conversations', { params: { filter: filter.value, q: search.value || undefined } })
  conversations.value = data.data
  connected.value = data.connected
  listLoaded.value = true
}

async function loadCurrent(scroll = false) {
  if (!props.id) return (current.value = null)
  const before = current.value?.messages.length
  const { data } = await http.get(`/conversations/${props.id}`)
  current.value = data.data
  const item = conversations.value.find((c) => c.id === data.data.id)
  if (item) item.unread = 0
  if (scroll || data.data.messages.length !== before) scrollDown()
}

function scrollDown() {
  nextTick(() => thread.value && (thread.value.scrollTop = thread.value.scrollHeight))
}

async function send() {
  sending.value = true
  replyError.value = ''
  try {
    const { data } = await http.post(`/conversations/${props.id}/messages`, { body: reply.value })
    current.value = data.data
    reply.value = ''
    suggestionCheck.value = ''
    scrollDown()
    loadList()
  } catch (e) {
    replyError.value = fieldErrors(e).body ?? errorMessage(e)
  } finally {
    sending.value = false
  }
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
  loadCurrent(true)
  timer = setInterval(() => document.visibilityState === 'visible' && refresh(), 15000)
})
onBeforeUnmount(() => clearInterval(timer))
watch(() => props.id, () => {
  selecting.value = false
  selected.value = []
  reply.value = ''
  replyError.value = ''
  suggestionCheck.value = ''
  loadCurrent(true)
})
let searchTimer
watch([filter, search], () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadList, 300)
})

const replyUntil = computed(() => (current.value?.reply_until ? formatDateTime(current.value.reply_until) : ''))
const waLink = computed(() => (current.value?.phone ? `https://wa.me/${current.value.phone.replace(/\D/g, '')}` : ''))
const CHANNELS = { whatsapp: ['WhatsApp'], messenger: ['Messenger'], instagram: ['Instagram'] }
const channelLabel = (c) => CHANNELS[c?.channel]?.[0] ?? 'WhatsApp'
const filters = [
  { value: 'all', label: 'Toutes' },
  { value: 'unread', label: 'Non lues' },
  { value: 'without_order', label: 'Sans commande' },
]
const time = (iso) => {
  const d = new Date(iso)
  return d.toDateString() === new Date().toDateString() ? d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}
const statusIcon = { sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠' }

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
    <Alert v-if="listLoaded && !connected" tone="warning">
      Les messages des clients arrivent ici quand votre WhatsApp, votre page Facebook ou votre Instagram sont connectés.
      <RouterLink v-if="auth.can('store.manage')" to="/gestion/whatsapp" class="font-medium underline">Relier WhatsApp par QR code</RouterLink>
      <RouterLink v-if="auth.can('store.manage')" to="/gestion/marketing/reseaux" class="ml-2 font-medium underline">Connecter Facebook et Instagram</RouterLink>
    </Alert>

    <div class="card grid grid-cols-1 overflow-hidden lg:grid-cols-[20rem_1fr]" style="height: calc(100vh - 9rem); min-height: 28rem">
      <!-- Conversations -->
      <aside class="flex min-h-0 min-w-0 flex-col border-slate-200 lg:border-r" :class="{ 'hidden lg:flex': id }">
        <div class="space-y-2 border-b border-slate-100 p-3">
          <input v-model="search" class="input" type="search" placeholder="Nom ou numéro" />
          <div class="flex gap-1">
            <button
              v-for="f in filters"
              :key="f.value"
              type="button"
              class="rounded-full px-3 py-1 text-xs font-medium"
              :class="filter === f.value ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
              @click="filter = f.value"
            >
              {{ f.label }}
            </button>
          </div>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto">
          <p v-if="listLoaded && !conversations.length" class="p-4 text-sm text-slate-500">Aucune conversation.</p>
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
              <span class="truncate text-xs" :class="c.unread ? 'text-slate-800' : 'text-slate-500'">{{ c.preview }}</span>
              <span v-if="c.order" class="ml-auto shrink-0 rounded bg-slate-100 px-1.5 text-[11px] text-slate-600">{{ c.order.reference }}</span>
              <span v-if="c.unread" class="shrink-0 rounded-full bg-emerald-600 px-1.5 text-[11px] font-semibold text-white" :class="{ 'ml-auto': !c.order }">{{ c.unread }}</span>
            </div>
          </RouterLink>
        </div>
      </aside>

      <!-- Conversation ouverte -->
      <section v-if="current" class="flex min-h-0 min-w-0 flex-col">
        <header class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
          <RouterLink to="/gestion/messages" class="btn-ghost -ml-2 px-2 lg:hidden" aria-label="Retour">←</RouterLink>
          <div class="min-w-0">
            <p class="truncate font-semibold">{{ current.name || current.phone_display }}</p>
            <p class="text-xs text-slate-500">
              {{ channelLabel(current) }}<template v-if="current.phone"> · {{ current.phone_display }}</template>
              <RouterLink v-if="current.customer && auth.can('customers.view')" :to="`/gestion/clients/${current.customer.id}`" class="ml-1 text-emerald-700 hover:underline">Fiche client</RouterLink>
            </p>
          </div>
          <div class="ml-auto flex items-center gap-2">
            <RouterLink v-if="current.order" :to="`/gestion/commandes/${current.order.id}`" class="btn-secondary px-3 py-1.5 text-sm">Commande {{ current.order.reference }}</RouterLink>
            <button v-if="canOrder" type="button" class="btn-secondary px-3 py-1.5 text-sm" @click="toggleSelecting">{{ selecting ? 'Annuler' : 'Choisir les messages' }}</button>
            <RouterLink v-if="canOrder && !selecting" :to="{ path: '/gestion/commandes/nouvelle', query: { conversation: current.id } }" class="btn-brand px-3 py-1.5 text-sm" title="Avec les messages du client depuis la dernière commande">
              {{ current.order ? 'Nouvelle commande' : 'Créer la commande' }}
            </RouterLink>
          </div>
        </header>

        <p v-if="selecting" class="border-b border-emerald-100 bg-emerald-50 px-4 py-2 text-sm text-emerald-900">Touchez les messages du client qui forment la commande, puis « Créer la commande ».</p>
        <div ref="thread" class="min-h-0 flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4">
          <div v-for="m in current.messages" :key="m.id" class="flex flex-col" :class="m.direction === 'out' ? 'items-end' : 'items-start'">
            <div
              class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm"
              :class="[
                m.direction === 'in' ? 'rounded-bl-sm bg-white' : m.type === 'template' ? 'rounded-br-sm bg-slate-200 text-slate-700' : 'rounded-br-sm bg-emerald-100',
                selecting && m.direction === 'in' ? 'cursor-pointer' : '',
                selected.includes(m.id) ? 'ring-2 ring-emerald-600' : '',
              ]"
              @click="toggleMessage(m)"
            >
              <span v-if="selecting && m.direction === 'in'" class="mb-1 flex items-center gap-1.5 text-xs font-medium" :class="selected.includes(m.id) ? 'text-emerald-700' : 'text-slate-400'">
                <span class="grid h-4 w-4 place-items-center rounded border" :class="selected.includes(m.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'">{{ selected.includes(m.id) ? '✓' : '' }}</span>
                {{ selected.includes(m.id) ? 'Dans la commande' : 'Ajouter à la commande' }}
              </span>
              <p class="whitespace-pre-wrap break-words">
                <template v-for="(part, i) in linkify(m.body ?? '')" :key="i">
                  <a v-if="i % 2" :href="part" target="_blank" rel="noopener" class="text-emerald-700 underline">{{ part }}</a>
                  <template v-else>{{ part }}</template>
                </template>
              </p>
              <p class="mt-1 text-right text-[11px] text-slate-500">
                <span v-if="m.by">{{ m.by }} · </span>{{ formatDateTime(m.sent_at) }}
                <span v-if="m.direction === 'out' && statusIcon[m.status]" :class="m.status === 'read' ? 'text-sky-600' : m.status === 'failed' ? 'text-red-600' : ''" :title="m.error || m.status">{{ statusIcon[m.status] }}</span>
              </p>
            </div>
            <RouterLink v-if="m.order_hint && canOrder && !selecting" :to="orderLink([m.id])" class="mt-1 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-100">
              <Icon name="orders" :size="14" />Faire une commande
            </RouterLink>
          </div>
        </div>
        <div v-if="selecting" class="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-white p-3">
          <span class="text-sm text-slate-600">{{ selected.length ? `${selected.length} message${selected.length > 1 ? 's' : ''} choisi${selected.length > 1 ? 's' : ''}` : 'Aucun message choisi' }}</span>
          <RouterLink v-if="selected.length" :to="orderLink(current.messages.filter((m) => selected.includes(m.id)).map((m) => m.id))" class="btn-brand ml-auto px-3 py-1.5 text-sm">Créer la commande</RouterLink>
        </div>

        <form v-if="current.can_reply && !selecting" class="space-y-2 border-t border-slate-100 p-3" @submit.prevent="send">
          <p v-if="replyError" class="text-sm text-red-600">{{ replyError }}</p>
          <div v-if="ai.enabled" class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn-secondary px-3 py-1.5 text-sm" :disabled="suggesting || (ai.limit !== null && ai.used >= ai.limit)" @click="suggest">
              <Icon name="sparkles" :size="16" class="mr-1 inline-block align-[-3px]" />{{ suggesting ? 'L\'assistant écrit…' : 'Proposer une réponse' }}
            </button>
            <span class="text-xs text-slate-500">Avec vos prix, votre stock et vos frais de livraison. Relisez avant d'envoyer.</span>
          </div>
          <p v-if="suggestionCheck" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">À vérifier : {{ suggestionCheck }}</p>
          <div class="flex items-end gap-2">
            <textarea v-model="reply" class="input min-h-[2.75rem]" rows="2" maxlength="4000" placeholder="Votre réponse…" @keydown.enter.exact.prevent="reply.trim() && !sending && send()" />
            <button class="btn-brand" :disabled="sending || !reply.trim()">{{ sending ? '…' : 'Envoyer' }}</button>
          </div>
          <p v-if="current.linked" class="text-xs text-slate-500">Envoyé depuis votre WhatsApp relié : le message apparaît aussi sur votre téléphone. Entrée pour envoyer, Maj + Entrée pour aller à la ligne.</p>
          <p v-else class="text-xs text-slate-500">Réponse possible jusqu'au {{ replyUntil }} (24 h après le dernier message du client). Entrée pour envoyer, Maj + Entrée pour aller à la ligne.</p>
        </form>
        <div v-else-if="!selecting" class="border-t border-slate-100 p-3 text-sm text-slate-600">
          Le dernier message du client date de plus de 24 h : {{ channelLabel(current) }} ne permet plus d'y répondre d'ici.
          <template v-if="current.channel === 'whatsapp' && auth.can('store.manage')">
            Reliez votre WhatsApp par QR code (<RouterLink to="/gestion/whatsapp" class="font-medium text-emerald-700 underline">Réglages › WhatsApp</RouterLink>) pour répondre à tout moment.
          </template>
          <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="font-medium text-emerald-700 underline">Écrire depuis WhatsApp</a>
        </div>
      </section>
      <section v-else class="hidden place-items-center p-6 text-center text-sm text-slate-500 lg:grid">
        <div>
          <Icon name="messages" :size="36" class="mx-auto text-slate-400" />
          <p class="mt-2">Choisissez une conversation.</p>
          <p class="mt-1">Les messages des clients arrivent ici dès qu'ils écrivent sur votre WhatsApp, votre page Facebook ou votre Instagram.</p>
        </div>
      </section>
    </div>
  </div>
</template>
