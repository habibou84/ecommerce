<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { formatDateTime } from '../lib/format'

/**
 * Relier le WhatsApp de la boutique (comme WhatsApp Web) : en scannant un QR code, ou avec le numéro (code à 8
 * caractères à saisir sur le téléphone). Les messages des clients arrivent ensuite dans la boîte Messages et
 * l'équipe y répond depuis le numéro de la boutique. L'état se rafraîchit toutes les trois secondes tant que le
 * téléphone n'est pas relié. « compact » : version de la boîte Messages, sans la mise en garde détaillée.
 * « support » : numéro du support de la plateforme, dans la console (même service, autre adresse).
 */
const props = defineProps({ compact: { type: Boolean, default: false }, support: { type: Boolean, default: false } })
const endpoint = props.support ? '/console/whatsapp/link' : '/whatsapp/link'
const emit = defineEmits(['linked'])

const link = ref(null)
const busy = ref(false)
const error = ref('')
const justLinked = ref(false)
const method = ref('qr') // qr | phone
const phone = ref('')
let timer = null

const STATUS = {
  none: ['Non relié', 'bg-slate-100 text-slate-700'],
  pending: ['En attente du téléphone', 'bg-amber-100 text-amber-800'],
  connected: ['Relié', 'bg-emerald-100 text-emerald-800'],
  disconnected: ['Déconnecté', 'bg-red-100 text-red-800'],
}

function apply(data) {
  if (data.status === 'connected' && link.value?.status === 'pending') {
    justLinked.value = true
    emit('linked')
  }
  if (!phone.value && data.store_phone) phone.value = data.store_phone
  link.value = data
  // Suivi de la liaison : seulement pendant l'attente du téléphone
  if (data.status === 'pending' && !timer) timer = setInterval(refresh, 3000)
  if (data.status !== 'pending' && timer) {
    clearInterval(timer)
    timer = null
  }
}

async function refresh() {
  try {
    apply((await http.get(endpoint)).data.data)
  } catch (e) {
    if (!link.value) error.value = errorMessage(e)
  }
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    apply((await http.post(endpoint)).data.data)
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
    apply((await http.post(`${endpoint}/pair`, { phone: phone.value })).data.data)
  } catch (e) {
    error.value = fieldErrors(e).phone ?? errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function unlink() {
  if (!window.confirm(props.support ? 'Déconnecter le numéro du support ? Les nouveaux messages n\'arriveront plus dans la console (le téléphone ne change pas).' : 'Déconnecter ce numéro ? Les nouveaux messages des clients n\'arriveront plus dans Messages (votre téléphone ne change pas).')) return
  busy.value = true
  try {
    apply((await http.delete(endpoint)).data.data)
    justLinked.value = false
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(refresh)
onBeforeUnmount(() => timer && clearInterval(timer))
</script>

<template>
  <section v-if="link" class="card space-y-3 border-emerald-200 p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-semibold">{{ support ? 'Relier le WhatsApp du support (QR code ou numéro)' : compact ? 'Recevez vos messages WhatsApp ici' : 'Vos conversations clients dans Messages' }}</h3>
      <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS[link.status][1]">{{ STATUS[link.status][0] }}</span>
    </div>
    <p v-if="link.status !== 'connected' && support" class="text-sm text-slate-600">
      Reliez le numéro du support comme sur WhatsApp Web, sans passer par Meta : les messages arrivent ici et vous répondez
      depuis ce numéro, à tout moment. Tout reste aussi sur le téléphone.
    </p>
    <p v-else-if="link.status !== 'connected'" class="text-sm text-slate-600">
      Reliez votre WhatsApp comme sur WhatsApp Web. Les messages de vos clients arrivent
      <template v-if="compact">ici</template><RouterLink v-else to="/gestion/messages" class="font-medium text-emerald-700 underline">dans Messages</RouterLink>,
      vous répondez depuis votre numéro, à tout moment, et tout reste aussi sur votre téléphone.
    </p>

    <!-- Numéro relié -->
    <div v-if="link.status === 'connected'" class="space-y-2">
      <Alert v-if="justLinked" tone="success">{{ support ? 'C\'est relié : les prochains messages arrivent ici.' : 'C\'est relié : les prochains messages de vos clients arrivent dans Messages.' }}</Alert>
      <p class="text-sm">
        Relié au numéro <strong>{{ link.phone }}</strong><span v-if="link.name"> ({{ link.name }})</span>
        <span v-if="link.connected_at" class="text-slate-500"> depuis le {{ formatDateTime(link.connected_at) }}</span>.
      </p>
      <button v-if="!compact" type="button" class="btn-ghost text-red-600" :disabled="busy" @click="unlink">Déconnecter ce numéro</button>
    </div>

    <template v-else>
      <p v-if="link.last_error" class="text-sm text-amber-700">{{ link.last_error }}</p>
      <!-- Deux façons de relier, comme WhatsApp Web -->
      <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="tablist">
        <button type="button" role="tab" :aria-selected="method === 'qr'" class="rounded-md px-3 py-1.5 font-medium" :class="method === 'qr' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="method = 'qr'">Avec un QR code</button>
        <button type="button" role="tab" :aria-selected="method === 'phone'" class="rounded-md px-3 py-1.5 font-medium" :class="method === 'phone' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="method = 'phone'">Avec le numéro</button>
      </div>

      <!-- QR code -->
      <div v-if="method === 'qr'">
        <div v-if="link.qr" class="flex flex-wrap items-start gap-4">
          <img :src="link.qr" alt="QR code WhatsApp à scanner" class="h-56 w-56 rounded-lg border border-slate-200 bg-white p-2" />
          <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
            <li>Ouvrez WhatsApp sur le téléphone {{ support ? 'du support' : 'de la boutique' }}.</li>
            <li>Touchez ⋮ (Android) ou Réglages (iPhone), puis « Appareils connectés ».</li>
            <li>Touchez « Connecter un appareil » et visez ce QR code.</li>
            <li>Le QR code change toutes les vingt secondes : il se met à jour tout seul.</li>
          </ol>
        </div>
        <div v-else class="space-y-2">
          <p class="text-sm text-slate-500">Pratique quand cet écran est sur un ordinateur ou une tablette : vous scannez avec le téléphone.</p>
          <button type="button" class="btn-brand" :disabled="busy || !link.available" @click="start">{{ busy ? 'Préparation…' : 'Afficher le QR code' }}</button>
        </div>
      </div>

      <!-- Code avec le numéro -->
      <div v-else class="space-y-3">
        <div v-if="link.code" class="space-y-2">
          <p class="text-sm text-slate-600">Code à saisir sur le téléphone :</p>
          <p class="select-all font-mono text-3xl font-bold tracking-widest text-emerald-800">{{ link.code }}</p>
          <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
            <li>Sur le téléphone, WhatsApp vous propose de saisir un code (une notification arrive), ou bien :</li>
            <li>Touchez ⋮ (Android) ou Réglages (iPhone), puis « Appareils connectés », puis « Connecter un appareil ».</li>
            <li>En bas, touchez « Connecter avec le numéro de téléphone à la place », puis saisissez le code.</li>
          </ol>
          <button type="button" class="btn-ghost px-2 text-sm" :disabled="busy" @click="pair">Recevoir un autre code</button>
        </div>
        <form v-else class="space-y-2" @submit.prevent="pair">
          <p class="text-sm text-slate-500">Pratique quand cet écran est sur le téléphone lui-même : pas besoin de scanner.</p>
          <label for="link-phone" class="block text-sm font-medium">Numéro WhatsApp {{ support ? 'du support' : 'de la boutique' }}</label>
          <div class="flex flex-wrap gap-2">
            <input id="link-phone" v-model="phone" class="input max-w-xs" type="tel" inputmode="tel" autocomplete="tel" placeholder="07 00 00 00 00" required />
            <button class="btn-brand" :disabled="busy || !link.available || !phone.trim()">{{ busy ? 'Demande…' : 'Obtenir le code' }}</button>
          </div>
        </form>
      </div>
      <p v-if="!link.available" class="text-xs text-slate-500">Bientôt disponible : le service n'est pas encore installé sur le serveur.</p>
    </template>

    <p v-if="!compact && !support" class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
      Connexion non officielle de WhatsApp, faite pour vos conversations avec les clients qui vous écrivent. Gardez le
      téléphone allumé avec Internet de temps en temps. N'envoyez pas de messages en masse par ce moyen : WhatsApp peut
      bloquer un numéro qui le fait. Pour les messages automatiques, utilisez la connexion officielle plus bas.
    </p>
    <p v-else-if="link.status !== 'connected' && !support" class="text-xs text-slate-500">
      Connexion non officielle, pour les conversations seulement (pas d'envois en masse).
      <RouterLink to="/gestion/whatsapp" class="font-medium text-emerald-700 underline">Réglages WhatsApp</RouterLink>
    </p>
    <Alert v-if="error">{{ error }}</Alert>
  </section>
</template>
