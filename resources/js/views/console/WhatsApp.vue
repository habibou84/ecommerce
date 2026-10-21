<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { boot } from '../../lib/boot'
import { facebookRelayUrl, takeFacebookResult } from '../../lib/facebook'
import { formatDateTime } from '../../lib/format'
import { useConsoleStore } from '../../stores/console'

// WhatsApp du support : le numéro reste dans l'application WhatsApp Business du téléphone (coexistence) et les
// messages arrivent aussi ici, où l'on peut répondre
const console_ = useConsoleStore()
const account = ref(null)
const conversations = ref([])
const current = ref(null)
const search = ref('')
const reply = ref('')
const error = ref('')
const busy = ref(false)
const coexistence = ref(true)
const thread = ref(null)
let timer

async function loadAccount() {
  account.value = (await http.get('/console/whatsapp')).data.data
}

async function loadConversations() {
  const { data } = await http.get('/console/whatsapp/conversations', { params: { search: search.value || undefined } })
  conversations.value = data.data
  console_.unreadMessages = data.meta.unread
}

async function open(conversation, scroll = true) {
  current.value = (await http.get(`/console/whatsapp/conversations/${conversation.id}`)).data.data
  conversation.unread_count = 0
  console_.unreadMessages = conversations.value.filter((c) => c.unread_count > 0).length
  if (scroll) {
    await nextTick()
    thread.value?.scrollTo(0, thread.value.scrollHeight)
  }
}

onMounted(async () => {
  try {
    const result = takeFacebookResult()
    if (result?.error) error.value = result.error
    else if (result) {
      busy.value = true
      account.value = (await http.post('/console/whatsapp/embedded', result)).data.data
    }
    if (!account.value) await loadAccount()
    if (account.value.connected) await loadConversations()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
  // Nouveaux messages relus toutes les 20 secondes
  timer = setInterval(async () => {
    if (!account.value?.connected) return
    await loadConversations().catch(() => {})
    if (current.value) {
      const fresh = conversations.value.find((c) => c.id === current.value.id)
      if (fresh && fresh.last_message_at !== current.value.last_message_at) await open(fresh).catch(() => {})
    }
  }, 20000)
})
onUnmounted(() => clearInterval(timer))

function connect() {
  busy.value = true
  const { app_id: appId, config_id: configId, graph_version: version } = account.value.embedded
  window.location.assign(facebookRelayUrl(boot.platformUrl, { appId, configId, version, coexistence: coexistence.value }, window.location.origin + window.location.pathname))
}

async function disconnect() {
  if (!window.confirm('Déconnecter le WhatsApp du support de la console ? Votre téléphone continue de recevoir les messages.')) return
  account.value = (await http.delete('/console/whatsapp')).data.data
  conversations.value = []
  current.value = null
}

async function send() {
  if (!reply.value.trim()) return
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/console/whatsapp/conversations/${current.value.id}/reply`, { body: reply.value })
    current.value.messages.push(data.data)
    reply.value = ''
    await nextTick()
    thread.value?.scrollTo(0, thread.value.scrollHeight)
    loadConversations().catch(() => {})
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const STATUS = { sent: '✓', delivered: '✓✓', read: '✓✓ lu', failed: 'échec' }
const title = computed(() => current.value ? (current.value.contact_name ? `${current.value.contact_name} · ${current.value.contact}` : current.value.contact) : '')
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="!account" class="text-sm text-slate-500">Chargement…</p>

    <section v-else-if="!account.connected" class="card max-w-2xl space-y-3 p-4 text-sm">
      <h3 class="text-base font-semibold">Recevoir les messages WhatsApp du support ici</h3>
      <p class="text-slate-600">Connectez le numéro WhatsApp Business de Jibiat Marchand. Les messages des clients arrivent dans la console et vous répondez depuis l'ordinateur.</p>
      <template v-if="account.embedded">
        <label class="flex items-start gap-2">
          <input v-model="coexistence" type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span>Garder le numéro dans l'application <strong>WhatsApp Business</strong> du téléphone (recommandé). Les messages arrivent aux deux endroits, et vos réponses faites sur le téléphone apparaissent aussi ici.</span>
        </label>
        <ol class="list-decimal space-y-1 pl-5 text-slate-600">
          <li>Une fenêtre Facebook s'ouvre : choisissez le portefeuille Meta Business de Jibiat Marchand.</li>
          <li>Choisissez « Connecter votre application WhatsApp Business existante » et entrez le numéro.</li>
          <li>Un code s'affiche ou arrive dans WhatsApp Business sur le téléphone : validez-le, puis acceptez de partager l'historique si vous le souhaitez.</li>
        </ol>
        <button class="btn-brand" :disabled="busy" @click="connect">{{ busy ? 'Connexion…' : 'Connecter avec Facebook' }}</button>
        <p class="text-xs text-slate-500">L'application WhatsApp Business doit être à jour sur le téléphone. Si Meta ne propose pas de garder l'application pour ce numéro, décochez la case : le numéro ne fonctionnera alors plus que dans la console.</p>
      </template>
      <Alert v-else tone="warning">La connexion avec Facebook n'est pas encore activée sur le serveur (application Meta de la plateforme).</Alert>
    </section>

    <template v-else>
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="rounded bg-emerald-100 px-2 py-0.5 text-emerald-800">Connecté · {{ account.display_phone }}<template v-if="account.name"> · {{ account.name }}</template></span>
        <span v-if="account.coexistence" class="text-slate-500">Aussi sur le téléphone (WhatsApp Business)</span>
        <button class="ml-auto text-slate-500 underline" @click="disconnect">Déconnecter</button>
      </div>
      <div class="grid gap-4 md:grid-cols-[18rem_1fr]">
        <aside class="card overflow-hidden" :class="current ? 'hidden md:block' : ''">
          <input v-model="search" class="input m-2 w-[calc(100%-1rem)]" placeholder="Nom ou numéro" @input="loadConversations" />
          <p v-if="!conversations.length" class="p-4 text-sm text-slate-500">Aucun message pour l'instant.</p>
          <ul class="max-h-[65vh] divide-y divide-slate-100 overflow-y-auto text-sm">
            <li v-for="c in conversations" :key="c.id">
              <button class="w-full px-3 py-2 text-left hover:bg-slate-50" :class="current?.id === c.id ? 'bg-emerald-50' : ''" @click="open(c)">
                <span class="flex items-center gap-2"><span class="flex-1 truncate font-medium">{{ c.contact_name ?? c.contact }}</span>
                  <span v-if="c.unread_count" class="rounded-full bg-emerald-600 px-1.5 text-xs text-white">{{ c.unread_count }}</span></span>
                <span class="block truncate text-slate-500">{{ c.last_preview }}</span>
                <span class="block text-xs text-slate-400">{{ formatDateTime(c.last_message_at) }}</span>
              </button>
            </li>
          </ul>
        </aside>

        <section v-if="current" class="card flex min-h-[60vh] flex-col">
          <header class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 text-sm">
            <button class="md:hidden" aria-label="Retour" @click="current = null">←</button>
            <span class="font-semibold">{{ title }}</span>
            <a :href="`https://wa.me/${current.phone.replace('+', '')}`" target="_blank" rel="noopener" class="ml-auto text-emerald-800 underline">Ouvrir dans WhatsApp</a>
          </header>
          <div ref="thread" class="flex-1 space-y-2 overflow-y-auto bg-slate-50 p-4 text-sm" style="max-height: 55vh">
            <div v-for="m in current.messages" :key="m.id" class="flex" :class="m.direction === 'out' ? 'justify-end' : ''">
              <div class="max-w-[80%] whitespace-pre-line rounded-xl px-3 py-2" :class="m.direction === 'out' ? 'bg-emerald-100' : 'bg-white ring-1 ring-slate-200'">
                {{ m.body }}
                <span class="mt-1 block text-right text-[11px] text-slate-500">
                  <template v-if="m.author">{{ m.author }} · </template>{{ formatDateTime(m.sent_at) }}<template v-if="m.direction === 'out' && STATUS[m.status]"> · {{ STATUS[m.status] }}</template>
                </span>
                <span v-if="m.error" class="block text-xs text-red-700">{{ m.error }}</span>
              </div>
            </div>
          </div>
          <form v-if="current.can_reply" class="flex gap-2 border-t border-slate-100 p-3" @submit.prevent="send">
            <textarea v-model="reply" rows="2" class="input flex-1" placeholder="Votre réponse" @keydown.enter.exact.prevent="send" />
            <button class="btn-brand self-end" :disabled="busy || !reply.trim()">Envoyer</button>
          </form>
          <p v-else class="border-t border-slate-100 p-3 text-xs text-slate-500">Plus de 24 h depuis le dernier message du client : WhatsApp n'autorise plus de réponse libre. Répondez depuis le téléphone.</p>
        </section>
        <p v-else class="hidden self-center text-center text-sm text-slate-500 md:block">Choisissez une conversation.</p>
      </div>
    </template>
  </div>
</template>
