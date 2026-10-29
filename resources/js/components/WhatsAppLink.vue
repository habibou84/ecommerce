<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'
import { formatDateTime, phoneDisplay } from '../lib/format'

/**
 * Relier le WhatsApp de la boutique (comme WhatsApp Web) : en scannant un QR code, ou avec le numéro (code à 8
 * caractères à saisir sur le téléphone). Les messages des clients arrivent ensuite dans la boîte Messages et
 * l'équipe y répond depuis le numéro de la boutique. L'état se rafraîchit toutes les trois secondes tant que le
 * téléphone n'est pas relié. « compact » : version de la boîte Messages, sans la mise en garde détaillée.
 */
defineProps({ compact: { type: Boolean, default: false } })
const emit = defineEmits(['linked', 'change'])

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
  emit('change', data)
  // Suivi de la liaison : seulement pendant l'attente du téléphone
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

async function pair() {
  busy.value = true
  error.value = ''
  try {
    apply((await http.post('/whatsapp/link/pair', { phone: phone.value })).data.data)
  } catch (e) {
    error.value = fieldErrors(e).phone ?? errorMessage(e)
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
  <section v-if="link" class="card space-y-3 p-4" :class="compact ? 'border-emerald-200' : 'sm:p-5'">
    <div v-if="compact" class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-semibold">Recevez vos messages WhatsApp ici</h3>
      <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS[link.status][1]">{{ STATUS[link.status][0] }}</span>
    </div>
    <div v-else class="flex items-start gap-3">
      <ColorIcon name="telephone" :size="44" />
      <div class="min-w-0 flex-1">
        <h3 class="text-[17px] font-extrabold">Conversations clients dans Messages</h3>
        <p class="text-sm text-slate-600">Vos clients vous écrivent, vous répondez depuis l'application, et tout reste sur votre téléphone.</p>
      </div>
      <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS[link.status][1]">{{ STATUS[link.status][0] }}</span>
    </div>
    <p v-if="link.status !== 'connected' && compact" class="text-sm text-slate-600">
      Reliez votre WhatsApp comme sur WhatsApp Web. Les messages de vos clients arrivent
      <template v-if="compact">ici</template><RouterLink v-else to="/gestion/messages" class="font-medium text-emerald-700 underline">dans Messages</RouterLink>,
      vous répondez depuis votre numéro, à tout moment, et tout reste aussi sur votre téléphone.
    </p>

    <!-- Numéro relié -->
    <div v-if="link.status === 'connected'" class="space-y-2">
      <Alert v-if="justLinked" tone="success">C'est relié : les prochains messages de vos clients arrivent dans Messages.</Alert>
      <div v-if="!compact" class="flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 p-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-600 text-white"><Icon name="check" :size="18" /></span>
        <div class="min-w-0 flex-1 text-sm">
          <p class="font-semibold text-emerald-900">Relié au {{ phoneDisplay(link.phone) }}<span v-if="link.name" class="font-normal"> ({{ link.name }})</span></p>
          <p v-if="link.connected_at" class="text-emerald-800">depuis le {{ formatDateTime(link.connected_at) }}</p>
        </div>
        <RouterLink to="/gestion/messages" class="btn-secondary">Ouvrir Messages</RouterLink>
      </div>
      <p v-else class="text-sm">
        Relié au numéro <strong>{{ phoneDisplay(link.phone) }}</strong><span v-if="link.name"> ({{ link.name }})</span>
        <span v-if="link.connected_at" class="text-slate-500"> depuis le {{ formatDateTime(link.connected_at) }}</span>.
      </p>
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
            <li>Ouvrez WhatsApp sur le téléphone de la boutique.</li>
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
          <label for="link-phone" class="block text-sm font-medium">Numéro WhatsApp de la boutique</label>
          <div class="flex flex-wrap gap-2">
            <input id="link-phone" v-model="phone" class="input max-w-xs" type="tel" inputmode="tel" autocomplete="tel" placeholder="07 00 00 00 00" required />
            <button class="btn-brand" :disabled="busy || !link.available || !phone.trim()">{{ busy ? 'Demande…' : 'Obtenir le code' }}</button>
          </div>
        </form>
      </div>
      <p v-if="!link.available" class="text-xs text-slate-500">Bientôt disponible : le service n'est pas encore installé sur le serveur.</p>
    </template>

    <details v-if="!compact" class="text-xs text-slate-500">
      <summary class="cursor-pointer">Connexion non officielle : parfaite pour répondre aux clients, pas pour les envois en masse. <span class="font-medium text-emerald-700 underline">En savoir plus</span></summary>
      <p class="mt-2 rounded-lg bg-amber-50 p-3 text-amber-900">
        Gardez le téléphone allumé avec Internet de temps en temps. N'envoyez pas de messages en masse par ce moyen :
        WhatsApp peut bloquer un numéro qui le fait. Pour les messages automatiques, utilisez le numéro officiel
        (onglet Messages automatiques).
      </p>
    </details>
    <p v-if="!compact && link.status === 'connected'" class="text-right">
      <button type="button" class="text-xs font-medium text-red-600 hover:underline" :disabled="busy" @click="unlink">Déconnecter ce numéro</button>
    </p>
    <p v-else-if="link.status !== 'connected'" class="text-xs text-slate-500">
      Connexion non officielle, pour les conversations seulement (pas d'envois en masse).
      <RouterLink to="/gestion/whatsapp" class="font-medium text-emerald-700 underline">Réglages WhatsApp</RouterLink>
    </p>
    <Alert v-if="error">{{ error }}</Alert>
  </section>
</template>
