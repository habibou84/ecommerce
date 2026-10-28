<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import CatchUpCampaign from '../../components/console/CatchUpCampaign.vue'
import OnboardingMessages from '../../components/console/OnboardingMessages.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
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
// Connexion par QR code (service du serveur, comme WhatsApp Web) : QR code ou code à saisir avec le numéro
const live = ref(null)
const method = ref('qr') // qr | phone
const pairPhone = ref('')
let qrTimer
// Configuration manuelle : identifiants copiés depuis Meta (WhatsApp Manager, utilisateur système)
const manualOpen = ref(false)
const manual = ref({ phone_number_id: '', waba_id: '', access_token: '', app_secret: '', pin: '' })
const manualErrors = ref({})

async function saveManual() {
  busy.value = true
  error.value = ''
  manualErrors.value = {}
  try {
    account.value = (await http.post('/console/whatsapp/manual', manual.value)).data.data
    manual.value = { phone_number_id: '', waba_id: '', access_token: '', app_secret: '', pin: '' }
    manualOpen.value = false
    await loadConversations()
  } catch (e) {
    manualErrors.value = fieldErrors(e)
    if (!Object.keys(manualErrors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function applyQr(data) {
  account.value = data
  live.value = data.live ?? null
  if (data.connected) {
    stopQr()
    loadConversations().catch(() => {})
  } else if (!qrTimer) {
    qrTimer = setInterval(refreshQr, 3000)
  }
}

function stopQr() {
  clearInterval(qrTimer)
  qrTimer = null
}

async function refreshQr() {
  try {
    applyQr((await http.get('/console/whatsapp/qr')).data.data)
  } catch {
    // Service momentanément injoignable : on réessaie au prochain tour
  }
}

async function startQr() {
  busy.value = true
  error.value = ''
  try {
    applyQr((await http.post('/console/whatsapp/qr')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function pair() {
  busy.value = true
  error.value = ''
  try {
    applyQr((await http.post('/console/whatsapp/pair', { phone: pairPhone.value })).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

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
    else if (account.value.mode === 'qr' && account.value.qr_status === 'pending') await refreshQr()
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
onUnmounted(() => {
  clearInterval(timer)
  stopQr()
})

function connect() {
  busy.value = true
  const { app_id: appId, config_id: configId, graph_version: version } = account.value.embedded
  window.location.assign(facebookRelayUrl(boot.platformUrl, { appId, configId, version, coexistence: coexistence.value }, window.location.origin + window.location.pathname))
}

async function setSmsFallback(on) {
  if (on && !window.confirm('Chaque SMS coûte. Proposer le SMS même quand WhatsApp est possible ?')) {
    await loadAccount()
    return
  }
  busy.value = true
  error.value = ''
  try {
    account.value = (await http.put('/console/whatsapp/sms', { fallback: on })).data.data
  } catch (e) {
    error.value = errorMessage(e)
    await loadAccount()
  } finally {
    busy.value = false
  }
}

async function disconnect() {
  if (!window.confirm('Déconnecter le WhatsApp du support de la console ? Votre téléphone continue de recevoir les messages. Les inscriptions et connexions repasseront par SMS tant qu\'aucun numéro n\'est relié.')) return
  stopQr()
  account.value = (await http.delete('/console/whatsapp')).data.data
  live.value = null
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

    <!-- SMS : coût à surveiller, secours à n'activer qu'en connaissance de cause -->
    <section v-if="account?.sms" class="card flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
      <div>
        <p class="font-semibold">SMS de vérification envoyés (payants)</p>
        <p class="text-slate-600">
          Ce mois : <strong>{{ account.sms.month }}</strong> · mois dernier : {{ account.sms.last_month }}.
          <span v-if="!account.sms.ready" class="text-amber-700">Les SMS sont simulés sur ce serveur (aucun envoi réel).</span>
          <span v-else-if="account.connected">Numéro relié : les inscriptions et connexions passent par WhatsApp, aucun SMS sauf si le secours est activé.</span>
          <span v-else class="text-amber-700">Sans numéro relié, chaque vérification de numéro part par SMS.</span>
        </p>
      </div>
      <label class="flex items-center gap-2">
        <input type="checkbox" class="h-4 w-4 accent-emerald-700" :checked="account.sms.fallback" :disabled="busy" @change="setSmsFallback($event.target.checked)" />
        <span>Proposer aussi le SMS quand WhatsApp est possible</span>
      </label>
    </section>

    <section v-if="account && !account.connected" class="card max-w-2xl space-y-4 p-4 text-sm">
      <h3 class="text-base font-semibold">Recevoir les messages WhatsApp du support ici</h3>
      <p class="text-slate-600">
        Reliez le numéro WhatsApp de Jibiat Marchand. Les messages des clients arrivent dans la console et vous répondez depuis l'ordinateur.
        Ce numéro reçoit aussi les <strong>messages de vérification</strong> des inscriptions et connexions : tant qu'il est relié, aucun SMS n'est envoyé.
      </p>

      <!-- Par QR code (comme WhatsApp Web) : disponible tout de suite -->
      <div class="space-y-3 rounded-lg border border-emerald-200 p-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h4 class="font-semibold">Par QR code, comme WhatsApp Web</h4>
          <span v-if="account.qr_status === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">En attente du téléphone</span>
          <span v-else-if="account.qr_status === 'disconnected'" class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Déconnecté</span>
        </div>
        <p v-if="account.error" class="text-amber-700">{{ account.error }}</p>
        <template v-if="account.bridge">
          <div class="inline-flex rounded-lg bg-slate-100 p-1" role="tablist">
            <button type="button" role="tab" :aria-selected="method === 'qr'" class="rounded-md px-3 py-1.5 font-medium" :class="method === 'qr' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="method = 'qr'">Avec un QR code</button>
            <button type="button" role="tab" :aria-selected="method === 'phone'" class="rounded-md px-3 py-1.5 font-medium" :class="method === 'phone' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="method = 'phone'">Avec le numéro</button>
          </div>
          <div v-if="method === 'qr'">
            <div v-if="live?.qr" class="flex flex-wrap items-start gap-4">
              <img :src="live.qr" alt="QR code WhatsApp à scanner" class="h-56 w-56 rounded-lg border border-slate-200 bg-white p-2" />
              <ol class="list-decimal space-y-1 pl-5 text-slate-700">
                <li>Ouvrez WhatsApp sur le téléphone du support.</li>
                <li>Touchez ⋮ (Android) ou Réglages (iPhone), puis « Appareils connectés ».</li>
                <li>Touchez « Connecter un appareil » et visez ce QR code.</li>
                <li>Le QR code change toutes les vingt secondes : il se met à jour tout seul.</li>
              </ol>
            </div>
            <button v-else type="button" class="btn-brand" :disabled="busy" @click="startQr">{{ busy ? 'Préparation…' : 'Afficher le QR code' }}</button>
          </div>
          <div v-else class="space-y-2">
            <div v-if="live?.code" class="space-y-2">
              <p class="text-slate-600">Code à saisir sur le téléphone (« Appareils connectés » › « Connecter un appareil » › « Connecter avec le numéro de téléphone à la place ») :</p>
              <p class="select-all font-mono text-3xl font-bold tracking-widest text-emerald-800">{{ live.code }}</p>
              <button type="button" class="btn-ghost px-2" :disabled="busy" @click="pair">Recevoir un autre code</button>
            </div>
            <form v-else class="flex flex-wrap gap-2" @submit.prevent="pair">
              <input v-model="pairPhone" class="input max-w-xs" type="tel" inputmode="tel" placeholder="Numéro WhatsApp du support" required />
              <button class="btn-brand" :disabled="busy || !pairPhone.trim()">{{ busy ? 'Demande…' : 'Obtenir le code' }}</button>
            </form>
          </div>
          <p class="text-xs text-slate-500">Connexion non officielle : le téléphone doit rester allumé avec Internet de temps en temps. Pas d'envois en masse par ce moyen.</p>
        </template>
        <Alert v-else tone="warning">Le service WhatsApp par QR code n'est pas installé sur le serveur (mise à jour du serveur à relancer).</Alert>
      </div>

      <!-- Avec Facebook : connexion officielle (Meta) -->
      <h4 class="font-semibold">Ou avec Facebook (connexion officielle)</h4>
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

      <!-- Configuration manuelle : identifiants copiés depuis Meta -->
      <h4 class="font-semibold">Ou configuration manuelle (identifiants Meta)</h4>
      <button v-if="!manualOpen" type="button" class="text-emerald-700 underline" @click="manualOpen = true">Saisir les identifiants à la main</button>
      <form v-else class="space-y-3" @submit.prevent="saveManual">
        <ol class="list-decimal space-y-1 pl-5 text-slate-600">
          <li>Dans WhatsApp Manager (business.facebook.com) › Paramètres du numéro : copiez l'<strong>identifiant du numéro</strong> et l'<strong>identifiant du compte WhatsApp Business</strong>.</li>
          <li>Dans Paramètres de l'entreprise › Utilisateurs système : générez un <strong>jeton permanent</strong> avec les droits <code>whatsapp_business_messaging</code> et <code>whatsapp_business_management</code>.</li>
          <li>Si ce jeton vient d'une autre application Meta que celle de Jibiat Marchand, ajoutez son code secret : la console vous donnera ensuite l'adresse et le jeton à mettre dans le webhook de cette application.</li>
        </ol>
        <label class="block">Identifiant du numéro (Phone number ID)
          <input v-model="manual.phone_number_id" class="input mt-1 font-mono" inputmode="numeric" required />
          <span v-if="manualErrors.phone_number_id" class="text-red-700">{{ manualErrors.phone_number_id }}</span>
        </label>
        <label class="block">Identifiant du compte WhatsApp Business (WABA ID)
          <input v-model="manual.waba_id" class="input mt-1 font-mono" inputmode="numeric" required />
          <span v-if="manualErrors.waba_id" class="text-red-700">{{ manualErrors.waba_id }}</span>
        </label>
        <label class="block">Jeton d'accès permanent
          <input v-model="manual.access_token" class="input mt-1 font-mono" type="password" autocomplete="off" required />
          <span v-if="manualErrors.access_token" class="text-red-700">{{ manualErrors.access_token }}</span>
        </label>
        <label class="block">Code secret de l'application Meta <span class="text-slate-500">(seulement si ce n'est pas celle de Jibiat Marchand)</span>
          <input v-model="manual.app_secret" class="input mt-1 font-mono" type="password" autocomplete="off" />
          <span v-if="manualErrors.app_secret" class="text-red-700">{{ manualErrors.app_secret }}</span>
        </label>
        <label class="block">Code PIN à 6 chiffres <span class="text-slate-500">(seulement si le numéro n'est pas encore enregistré sur l'API)</span>
          <input v-model="manual.pin" class="input mt-1 w-40 font-mono" inputmode="numeric" maxlength="6" autocomplete="off" />
          <span v-if="manualErrors.pin" class="text-red-700">{{ manualErrors.pin }}</span>
        </label>
        <div class="flex flex-wrap gap-2">
          <button class="btn-brand" :disabled="busy">{{ busy ? 'Vérification auprès de Meta…' : 'Vérifier et connecter' }}</button>
          <button type="button" class="btn-ghost" @click="manualOpen = false">Annuler</button>
        </div>
        <p class="text-xs text-slate-500">Les identifiants sont vérifiés auprès de Meta avant d'être gardés ; le jeton et le code secret sont chiffrés et ne sont plus jamais affichés.</p>
      </form>
    </section>

    <template v-else-if="account">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="rounded bg-emerald-100 px-2 py-0.5 text-emerald-800">Connecté · {{ account.display_phone }}<template v-if="account.name"> · {{ account.name }}</template></span>
        <span v-if="account.mode === 'qr'" class="text-slate-500">Par QR code · reçoit les codes de vérification (aucun SMS)</span>
        <span v-else-if="account.mode === 'manual'" class="text-slate-500">Configuration manuelle (identifiants Meta)</span>
        <span v-else-if="account.coexistence" class="text-slate-500">Aussi sur le téléphone (WhatsApp Business)</span>
        <button class="ml-auto text-slate-500 underline" @click="disconnect">Déconnecter</button>
      </div>
      <Alert v-if="account.manual" tone="info">
        Dans votre application Meta › WhatsApp › Configuration › Webhook, mettez l'adresse
        <code class="select-all">{{ account.manual.webhook_url }}</code> et le jeton de vérification
        <code class="select-all">{{ account.manual.verify_token }}</code>, puis abonnez le champ « messages ».
      </Alert>
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

    <OnboardingMessages v-if="account" />
    <CatchUpCampaign v-if="account" />
  </div>
</template>
