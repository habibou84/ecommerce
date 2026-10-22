<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import http, { errorMessage } from '../lib/http'
import { formatDateTime } from '../lib/format'

/**
 * Réglages › WhatsApp › Relier par QR code (comme WhatsApp Web) : les messages des clients arrivent dans la boîte
 * Messages et l'équipe y répond depuis le numéro de la boutique. Le QR code et l'état se rafraîchissent toutes les
 * trois secondes tant que le téléphone n'a pas scanné.
 */
const link = ref(null)
const busy = ref(false)
const error = ref('')
const justLinked = ref(false)
let timer = null

const STATUS = {
  none: ['Non relié', 'bg-slate-100 text-slate-700'],
  pending: ['QR code à scanner', 'bg-amber-100 text-amber-800'],
  connected: ['Relié', 'bg-emerald-100 text-emerald-800'],
  disconnected: ['Déconnecté', 'bg-red-100 text-red-800'],
}

function apply(data) {
  if (data.status === 'connected' && link.value?.status === 'pending') justLinked.value = true
  link.value = data
  // Suivi du scan : seulement pendant l'affichage du QR code
  if (data.status === 'pending' && !timer) timer = setInterval(refresh, 3000)
  if (data.status !== 'pending' && timer) {
    clearInterval(timer)
    timer = null
  }
}

async function refresh() {
  try {
    apply((await http.get('/whatsapp/link')).data.data)
  } catch (e) {
    if (!link.value) error.value = errorMessage(e)
  }
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    apply((await http.post('/whatsapp/link')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function unlink() {
  if (!window.confirm('Déconnecter ce numéro ? Les nouveaux messages des clients n\'arriveront plus dans Messages (votre téléphone ne change pas).')) return
  busy.value = true
  try {
    apply((await http.delete('/whatsapp/link')).data.data)
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
      <h3 class="font-semibold">Vos conversations clients dans Messages</h3>
      <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS[link.status][1]">{{ STATUS[link.status][0] }}</span>
    </div>
    <p class="text-sm text-slate-600">
      Reliez votre WhatsApp comme sur WhatsApp Web, en scannant un QR code avec votre téléphone. Les messages de vos clients
      arrivent dans <RouterLink to="/gestion/messages" class="font-medium text-emerald-700 underline">Messages</RouterLink>,
      vous y répondez depuis votre numéro, et tout reste aussi sur votre téléphone.
    </p>

    <!-- Numéro relié -->
    <div v-if="link.status === 'connected'" class="space-y-2">
      <Alert v-if="justLinked" tone="success">C'est relié : les prochains messages de vos clients arrivent dans Messages.</Alert>
      <p class="text-sm">
        Relié au numéro <strong>{{ link.phone }}</strong><span v-if="link.name"> ({{ link.name }})</span>
        <span v-if="link.connected_at" class="text-slate-500"> depuis le {{ formatDateTime(link.connected_at) }}</span>.
      </p>
      <button type="button" class="btn-ghost text-red-600" :disabled="busy" @click="unlink">Déconnecter ce numéro</button>
    </div>

    <!-- QR code à scanner -->
    <div v-else-if="link.qr" class="flex flex-wrap items-start gap-4">
      <img :src="link.qr" alt="QR code WhatsApp à scanner" class="h-64 w-64 rounded-lg border border-slate-200 bg-white p-2" />
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li>Ouvrez WhatsApp sur le téléphone de la boutique.</li>
        <li>Touchez ⋮ (Android) ou Réglages (iPhone), puis « Appareils connectés ».</li>
        <li>Touchez « Connecter un appareil » et visez ce QR code.</li>
        <li>Le QR code change toutes les vingt secondes : il se met à jour tout seul.</li>
      </ol>
    </div>

    <div v-else class="space-y-2">
      <p v-if="link.last_error" class="text-sm text-amber-700">{{ link.last_error }}</p>
      <p v-if="link.status === 'pending'" class="text-sm text-slate-500">Préparation du QR code…</p>
      <button type="button" class="btn-brand" :disabled="busy || !link.available" @click="start">
        {{ link.status === 'disconnected' ? 'Relier de nouveau' : 'Afficher le QR code' }}
      </button>
      <p v-if="!link.available" class="text-xs text-slate-500">Bientôt disponible : le service n'est pas encore installé sur le serveur.</p>
    </div>

    <p class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">
      Connexion non officielle de WhatsApp, faite pour vos conversations avec les clients qui vous écrivent. Gardez le
      téléphone allumé avec Internet de temps en temps. N'envoyez pas de messages en masse par ce moyen : WhatsApp peut
      bloquer un numéro qui le fait. Pour les messages automatiques, utilisez la connexion officielle plus bas.
    </p>
    <Alert v-if="error">{{ error }}</Alert>
  </section>
</template>
