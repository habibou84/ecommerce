<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { loadMission, offline, sendStep, shrinkPhoto } from '../../lib/courierOffline'
import { money } from '../../lib/format'
import { SHIPMENT_TONES } from '../../lib/shipping'

const props = defineProps({ id: { type: String, required: true } })
const mission = ref(null)
const error = ref('')
const errors = ref({})
const busy = ref(false)
const queued = ref(false) // dernière étape gardée sur le téléphone, pas encore envoyée
const panel = ref(null) // 'delivered' | 'failed'
const form = reactive({ code: '', payment_method: 'cash', collected_amount: 0, reason: '', rescheduled_to: '', photo: null, company: '', fee: '', ticket: '', advanced: true })

const REASONS = ['Client injoignable', 'Client absent', 'Adresse introuvable', 'Refus du client', 'Le client reporte']
const METHODS = [['cash', 'Espèces'], ['wave', 'Wave'], ['orange_money', 'Orange Money'], ['mtn_momo', 'MTN MoMo'], ['moov_money', 'Moov Money']]

async function load() {
  try {
    mission.value = await loadMission(Number(props.id))
    form.collected_amount = Math.min(mission.value.cod_amount, mission.value.amount_due)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
watch(() => offline.synced, load)
// Sans réseau, rien ne peut se vérifier ou se payer en ligne : le paiement à la porte attend le réseau
const noNetwork = computed(() => !offline.online || offline.savedAt !== null)

// Paiement à la porte : QR code mobile money, puis attente de la confirmation du prestataire
const door = ref(null)
let poll = null
async function startDoorPayment() {
  error.value = ''
  busy.value = true
  try {
    door.value = (await http.post(`/courier/missions/${props.id}/door-payment`)).data.data
    clearInterval(poll)
    poll = setInterval(async () => {
      await load()
      if (mission.value.amount_due === 0) clearInterval(poll)
    }, 4000)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
onUnmounted(() => clearInterval(poll))
const paidAtDoor = computed(() => door.value && mission.value?.amount_due === 0)

const can = (step) => mission.value?.next_steps.includes(step)
const mapsUrl = computed(() => {
  const m = mission.value
  return m ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([m.address, m.landmark, m.place, 'Côte d\'Ivoire'].filter(Boolean).join(', '))}` : '#'
})
const whatsapp = computed(() => mission.value ? `https://wa.me/${mission.value.customer_phone.replace('+', '')}` : '#')

// Réponse à une nouvelle mission (il faut du réseau)
const declining = ref(false)
const declineReason = ref('')
const router = useRouter()
async function respond(action) {
  busy.value = true
  error.value = ''
  try {
    if (action === 'accept') {
      await http.post(`/courier/missions/${props.id}/accept`)
      await load()
    } else {
      await http.post(`/courier/missions/${props.id}/decline`, { reason: declineReason.value })
      router.replace('/livreur')
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Action principale du moment (bouton fixé en bas)
const primary = computed(() => {
  if (!mission.value || panel.value) return null
  // Nouvelle mission : d'abord accepter ou refuser (le bouton fixe cacherait ce choix)
  if (mission.value.acceptance === 'pending' && mission.value.status === 'awaiting_pickup') return null
  if (can('in_transit')) return { label: '📦 J\'ai récupéré le colis', run: () => step('in_transit') }
  // Expédition : du colis récupéré au dépôt à la gare, sans « je pars livrer » (le client n'attend pas à sa porte)
  if (mission.value.expedition && can('delivered')) return { label: '🚉 Déposé à la gare', run: () => (panel.value = 'delivered') }
  if (can('out_for_delivery')) return { label: '🛵 Je pars livrer', run: () => step('out_for_delivery') }
  if (can('delivered')) return { label: '✓ Livré', run: () => (panel.value = 'delivered') }
  return null
})

// Code du client : une case par chiffre, le curseur avance tout seul
const CODE_LENGTH = 4
const digits = ref(Array(CODE_LENGTH).fill(''))
const boxes = []
function typeDigit(i, event) {
  const typed = event.target.value.replace(/\D/g, '')
  // Code collé ou dicté d'un coup : réparti dans les cases
  typed.split('').slice(0, CODE_LENGTH - i).forEach((c, k) => (digits.value[i + k] = c))
  if (!typed) digits.value[i] = ''
  event.target.value = digits.value[i]
  form.code = digits.value.join('')
  errors.value = { ...errors.value, code: undefined }
  if (typed) boxes[Math.min(i + typed.length, CODE_LENGTH - 1)]?.focus()
}
function backDigit(i, event) {
  if (!digits.value[i] && i > 0) {
    event.preventDefault()
    digits.value[i - 1] = ''
    form.code = digits.value.join('')
    boxes[i - 1]?.focus()
  }
}

async function step(status) {
  busy.value = true
  error.value = ''
  errors.value = {}
  queued.value = false
  const fields = {}
  if (status === 'delivered' && mission.value.expedition) {
    Object.assign(fields, { expedition_company: form.company, expedition_fee: form.fee === '' ? '' : Number(form.fee), expedition_ticket: form.ticket, expedition_advanced: form.advanced ? 1 : 0 })
  } else if (status === 'delivered') {
    if (mission.value.requires_code) fields.code = form.code
    fields.payment_method = form.payment_method
    fields.collected_amount = mission.value.amount_due > 0 ? form.collected_amount : 0
  }
  if (status === 'failed') {
    fields.reason = form.reason
    if (form.rescheduled_to) fields.rescheduled_to = form.rescheduled_to
  }
  const photo = form.photo && ['delivered', 'failed'].includes(status) ? await shrinkPhoto(form.photo) : null
  try {
    queued.value = await sendStep(mission.value, status, fields, photo)
    panel.value = null
    form.photo = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
    // Code faux : vibration, cases vidées pour ressaisir
    if (errors.value.code) {
      navigator.vibrate?.([200, 100, 200])
      digits.value = Array(CODE_LENGTH).fill('')
      form.code = ''
      boxes[0]?.focus()
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="mission" class="space-y-4">
    <RouterLink to="/livreur" class="text-sm text-slate-500">← Mes livraisons</RouterLink>
    <section class="rounded-xl bg-white p-4 shadow-sm">
      <div class="flex items-center gap-2">
        <h1 class="text-lg font-bold">{{ mission.reference }}</h1>
        <p v-if="mission.store" class="text-sm text-sky-800">Marchand {{ mission.store }}</p>
        <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[mission.status]">{{ mission.status_label }}</span>
      </div>
      <template v-if="mission.pending_sync && mission.status === 'delivered'">
        <p class="mt-2 text-2xl font-bold">{{ money(mission.collected_amount) }}</p>
        <p class="text-sm text-slate-500">encaissé · pas encore envoyé au marchand</p>
      </template>
      <template v-else-if="mission.amount_due > 0">
        <p class="mt-2 text-2xl font-bold">{{ money(Math.min(mission.cod_amount, mission.amount_due)) }}</p>
        <p class="text-sm text-slate-500">à encaisser auprès du client</p>
      </template>
      <template v-else>
        <p class="mt-2 text-2xl font-bold text-emerald-700">Déjà payé ✓</p>
        <p class="text-sm text-slate-500">Rien à encaisser : remettez le colis.</p>
      </template>
    </section>

    <Alert v-if="mission.customer_response === 'unavailable'" tone="warning">
      Le client a répondu « Pas disponible » {{ new Date(mission.customer_response_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }} : appelez-le avant de vous déplacer.
    </Alert>
    <Alert v-else-if="mission.customer_response === 'available'" tone="success">Le client vous attend ✓</Alert>

    <Alert v-if="mission.expedition" tone="info">📦 Expédition vers <strong>{{ mission.expedition.city }}</strong> : déposez le colis à la gare, puis notez la compagnie, les frais payés et le ticket.</Alert>

    <section class="space-y-2 rounded-xl bg-white p-4 shadow-sm">
      <p class="font-semibold">{{ mission.customer_name || 'Client' }}</p>
      <p class="text-sm">{{ mission.place }}<template v-if="mission.address"> · {{ mission.address }}</template></p>
      <p v-if="mission.landmark" class="text-sm text-slate-600">Repère : {{ mission.landmark }}</p>
      <p v-if="mission.customer_note" class="rounded bg-amber-50 p-2 text-sm">« {{ mission.customer_note }} »</p>
      <p v-if="mission.note" class="rounded bg-slate-50 p-2 text-sm">Marchand : {{ mission.note }}</p>
      <div class="grid grid-cols-3 gap-2 pt-1">
        <a :href="`tel:${mission.customer_phone}`" class="btn-secondary whitespace-nowrap px-1 text-sm">📞 Appeler</a>
        <a :href="whatsapp" target="_blank" class="btn-secondary whitespace-nowrap px-1 text-sm">💬 WhatsApp</a>
        <a :href="mapsUrl" target="_blank" class="btn-secondary whitespace-nowrap px-1 text-sm">🗺️ Carte</a>
      </div>
      <p v-if="mission.customer_notified_at && !mission.customer_response" class="text-xs text-emerald-700">✓ Client prévenu par le marchand que vous êtes en route.</p>
      <a v-else-if="!mission.customer_notified_at && ['in_transit', 'out_for_delivery'].includes(mission.status)" :href="mission.notify_url" target="_blank" class="btn-secondary w-full text-sm">💬 Prévenir le client que j'arrive</a>
      <p class="text-xs text-slate-500">{{ mission.customer_phone_display }}<template v-if="mission.customer_phone2"> · autre : {{ mission.customer_phone2 }}</template></p>
    </section>

    <section class="rounded-xl bg-white p-4 text-sm shadow-sm">
      <p v-if="mission.pickup" class="mb-2 text-slate-600">À récupérer : <strong>{{ mission.pickup.name }}</strong>{{ mission.pickup.address ? ` · ${mission.pickup.address}` : '' }}</p>
      <ul class="space-y-1">
        <li v-for="(item, i) in mission.items" :key="i">{{ item.quantity }} × {{ item.label }}</li>
      </ul>
      <div v-if="mission.take_back?.length" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-2">
        <p class="font-semibold text-amber-900">↩ Échange : reprenez au client</p>
        <ul><li v-for="(item, i) in mission.take_back" :key="i">{{ item.quantity }} × {{ item.label }}</li></ul>
      </div>
      <p v-if="mission.failure_reason" class="mt-2 text-amber-800">Dernier échec : {{ mission.failure_reason }}<template v-if="mission.rescheduled_to"> · reportée au {{ new Date(mission.rescheduled_to).toLocaleDateString('fr-FR') }}</template></p>
    </section>

    <Alert v-if="error">{{ error }}</Alert>
    <section v-if="mission.acceptance === 'pending' && mission.status === 'awaiting_pickup'" class="space-y-2 rounded-xl border-2 border-amber-400 bg-amber-50 p-4">
      <p class="font-semibold text-amber-900">🔔 Nouvelle mission : acceptez-la ou refusez-la</p>
      <template v-if="declining">
        <input v-model="declineReason" class="input" maxlength="255" placeholder="Motif (trop loin, trop de colis…)" />
        <div class="grid grid-cols-2 gap-2">
          <button class="btn-secondary" @click="declining = false">Annuler</button>
          <button class="btn-danger" :disabled="busy || !declineReason || noNetwork" @click="respond('decline')">Refuser</button>
        </div>
      </template>
      <div v-else class="grid grid-cols-2 gap-2">
        <button class="btn-secondary" :disabled="busy || noNetwork" @click="declining = true">✕ Refuser</button>
        <button class="btn-brand" :disabled="busy || noNetwork" @click="respond('accept')">✓ Accepter</button>
      </div>
    </section>
    <Alert v-if="queued" tone="warning">Étape gardée sur le téléphone : elle sera envoyée au marchand dès que le réseau revient, avec l'heure où vous l'avez faite.</Alert>
    <p v-else-if="mission.pending_sync" class="text-sm text-amber-800">⏳ Des étapes de cette livraison ne sont pas encore envoyées.</p>

    <!-- Action suivante : gros bouton fixé en bas de l'écran, facile à toucher d'une main ou avec des gants -->
    <div v-if="primary" class="fixed inset-x-0 bottom-[calc(3.75rem+env(safe-area-inset-bottom))] z-10 mx-auto max-w-lg bg-gradient-to-t from-slate-50 px-4 pt-3 pb-2">
      <button class="btn-brand w-full py-5 text-lg shadow-lg" :disabled="busy" @click="primary.run">{{ primary.label }}</button>
    </div>
    <div class="space-y-2">
      <button v-if="can('failed') && panel !== 'failed'" class="btn-secondary w-full py-3" @click="panel = 'failed'">✕ Pas livré</button>
      <button v-if="can('returning')" class="btn-secondary w-full py-3" :disabled="busy" @click="step('returning')">↩ Je rapporte le colis au marchand</button>
    </div>

    <!-- Expédition : dépôt à la gare -->
    <form v-if="panel === 'delivered' && mission.expedition" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" @submit.prevent="step('delivered')">
      <h2 class="font-semibold">Dépôt à la gare · {{ mission.expedition.city }}</h2>
      <div>
        <p class="label">Compagnie de transport</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="c in mission.expedition.companies" :key="c" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="form.company === c ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-slate-200'" @click="form.company = c">{{ c }}</button>
        </div>
        <input v-model="form.company" class="input mt-2" placeholder="Ou écrivez le nom" maxlength="60" required />
        <p v-if="errors.expedition_company" class="mt-1 text-sm text-red-600">{{ errors.expedition_company }}</p>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <div>
          <label for="exp-fee" class="label">Frais payés (F)</label>
          <input id="exp-fee" v-model="form.fee" type="number" min="0" inputmode="numeric" class="input" required />
        </div>
        <div>
          <label for="exp-ticket" class="label">N° du ticket</label>
          <input id="exp-ticket" v-model="form.ticket" class="input" maxlength="60" />
        </div>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="form.advanced" type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" />
        <span>J'ai payé les frais de ma poche <span class="block text-xs text-slate-500">Ils vous seront remboursés avec votre paie.</span></span>
      </label>
      <div>
        <label for="exp-photo" class="label">Photo du reçu</label>
        <input id="exp-photo" type="file" accept="image/*" capture="environment" class="text-sm" @change="form.photo = $event.target.files[0]" />
      </div>
      <button class="btn-brand w-full py-3" :disabled="busy">{{ busy ? 'Envoi…' : 'Confirmer le dépôt' }}</button>
    </form>

    <form v-else-if="panel === 'delivered'" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" @submit.prevent="step('delivered')">
      <h2 class="font-semibold">Livraison</h2>
      <div v-if="mission.requires_code">
        <p class="label">Code donné par le client</p>
        <div class="flex justify-center gap-2">
          <input v-for="(d, i) in digits" :key="i" :ref="(el) => (boxes[i] = el)" :value="d" inputmode="numeric" maxlength="1" :aria-label="`Chiffre ${i + 1} du code`"
                 class="h-16 w-14 rounded-xl border-2 text-center text-3xl font-bold" :class="errors.code ? 'border-red-500 bg-red-50' : 'border-slate-300 focus:border-emerald-600'"
                 @input="typeDigit(i, $event)" @keydown.backspace="backDigit(i, $event)" />
        </div>
        <p v-if="errors.code" class="mt-1 text-sm text-red-600">{{ errors.code }}</p>
        <p v-else-if="noNetwork" class="mt-1 text-xs text-slate-500">Sans réseau, le code sera vérifié à l'envoi : demandez bien le bon code au client.</p>
      </div>
      <div v-if="mission.door_payment && !paidAtDoor && !noNetwork" class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm">
        <template v-if="!door">
          <p class="mb-2">Le client préfère payer par Wave, Orange Money, MTN ou Moov ? Il scanne le code ou ouvre le lien, et la commande est payée sans espèces.</p>
          <button type="button" class="btn-secondary w-full" :disabled="busy" @click="startDoorPayment">📱 Paiement mobile money (QR code)</button>
        </template>
        <div v-else class="space-y-2 text-center">
          <p class="font-medium">Faites scanner ce code au client ({{ money(door.amount) }})</p>
          <img :src="door.qr" alt="QR code de paiement" class="mx-auto h-56 w-56 bg-white p-2" />
          <div class="grid grid-cols-2 gap-2">
            <a :href="door.whatsapp_url" target="_blank" class="btn-secondary px-1 text-sm">💬 Envoyer le lien</a>
            <a :href="door.sms_url" class="btn-secondary px-1 text-sm">✉️ Par SMS</a>
          </div>
          <p class="text-slate-600">En attente du paiement… la page se met à jour toute seule.</p>
        </div>
      </div>
      <Alert v-if="paidAtDoor" tone="success">Paiement reçu ✓ Vous n'avez rien à encaisser : confirmez la livraison.</Alert>
      <div v-if="mission.amount_due > 0">
        <p class="label">Payé en</p>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="[value, label] in METHODS" :key="value" type="button" class="rounded-lg border px-2 py-2 text-sm"
                  :class="form.payment_method === value ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-slate-200'" @click="form.payment_method = value">{{ label }}</button>
        </div>
      </div>
      <div v-if="mission.amount_due > 0">
        <label for="collected" class="label">Montant reçu</label>
        <input id="collected" v-model.number="form.collected_amount" type="number" min="0" :max="mission.cod_amount" class="input" />
      </div>
      <div>
        <label for="photo" class="label">Photo du colis remis <span class="font-normal text-slate-400">(facultatif)</span></label>
        <input id="photo" type="file" accept="image/*" capture="environment" class="text-sm" @change="form.photo = $event.target.files[0]" />
      </div>
      <button class="btn-brand w-full py-3" :disabled="busy">{{ busy ? 'Envoi…' : 'Confirmer la livraison' }}</button>
    </form>

    <form v-if="panel === 'failed'" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" @submit.prevent="step('failed')">
      <h2 class="font-semibold">Livraison impossible</h2>
      <div class="flex flex-wrap gap-2">
        <button v-for="r in REASONS" :key="r" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="form.reason === r ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="form.reason = r">{{ r }}</button>
      </div>
      <input v-model="form.reason" class="input" placeholder="Motif" maxlength="255" required />
      <div>
        <label for="reschedule" class="label">Nouvelle date <span class="font-normal text-slate-400">(si reportée)</span></label>
        <input id="reschedule" v-model="form.rescheduled_to" type="date" class="input" />
      </div>
      <button class="btn-danger w-full py-3" :disabled="busy">Enregistrer l'échec</button>
    </form>
    <div v-if="primary" class="h-24" aria-hidden="true" />
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
