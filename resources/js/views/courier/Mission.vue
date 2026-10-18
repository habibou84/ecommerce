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
const form = reactive({ code: '', payment_method: 'cash', collected_amount: 0, reason: '', rescheduled_to: '', photo: null })

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

async function step(status) {
  busy.value = true
  error.value = ''
  errors.value = {}
  queued.value = false
  const fields = {}
  if (status === 'delivered') {
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
        <p v-if="mission.store" class="text-sm text-sky-800">Boutique {{ mission.store }}</p>
        <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[mission.status]">{{ mission.status_label }}</span>
      </div>
      <template v-if="mission.pending_sync && mission.status === 'delivered'">
        <p class="mt-2 text-2xl font-bold">{{ money(mission.collected_amount) }}</p>
        <p class="text-sm text-slate-500">encaissé · pas encore envoyé à la boutique</p>
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

    <section class="space-y-2 rounded-xl bg-white p-4 shadow-sm">
      <p class="font-semibold">{{ mission.customer_name || 'Client' }}</p>
      <p class="text-sm">{{ mission.place }}<template v-if="mission.address"> · {{ mission.address }}</template></p>
      <p v-if="mission.landmark" class="text-sm text-slate-600">Repère : {{ mission.landmark }}</p>
      <p v-if="mission.customer_note" class="rounded bg-amber-50 p-2 text-sm">« {{ mission.customer_note }} »</p>
      <p v-if="mission.note" class="rounded bg-slate-50 p-2 text-sm">Boutique : {{ mission.note }}</p>
      <div class="grid grid-cols-3 gap-2 pt-1">
        <a :href="`tel:${mission.customer_phone}`" class="btn-secondary whitespace-nowrap px-1 text-sm">📞 Appeler</a>
        <a :href="whatsapp" target="_blank" class="btn-secondary whitespace-nowrap px-1 text-sm">💬 WhatsApp</a>
        <a :href="mapsUrl" target="_blank" class="btn-secondary whitespace-nowrap px-1 text-sm">🗺️ Carte</a>
      </div>
      <p v-if="mission.customer_notified_at && !mission.customer_response" class="text-xs text-emerald-700">✓ Client prévenu par la boutique que vous êtes en route.</p>
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
    <Alert v-if="queued" tone="warning">Étape gardée sur le téléphone : elle sera envoyée à la boutique dès que le réseau revient, avec l'heure où vous l'avez faite.</Alert>
    <p v-else-if="mission.pending_sync" class="text-sm text-amber-800">⏳ Des étapes de cette livraison ne sont pas encore envoyées.</p>

    <div class="space-y-2">
      <button v-if="can('in_transit')" class="btn-brand w-full py-4 text-base" :disabled="busy" @click="step('in_transit')">📦 J'ai récupéré le colis</button>
      <button v-if="can('out_for_delivery')" class="btn-brand w-full py-4 text-base" :disabled="busy" @click="step('out_for_delivery')">🛵 Je pars livrer</button>
      <button v-if="can('delivered') && panel !== 'delivered'" class="btn-brand w-full py-4 text-base" @click="panel = 'delivered'">✓ Livré</button>
      <button v-if="can('failed') && panel !== 'failed'" class="btn-secondary w-full py-3" @click="panel = 'failed'">✕ Pas livré</button>
      <button v-if="can('returning')" class="btn-secondary w-full py-3" :disabled="busy" @click="step('returning')">↩ Je rapporte le colis à la boutique</button>
    </div>

    <form v-if="panel === 'delivered'" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" @submit.prevent="step('delivered')">
      <h2 class="font-semibold">Livraison</h2>
      <div v-if="mission.requires_code">
        <label for="code" class="label">Code donné par le client</label>
        <input id="code" v-model="form.code" inputmode="numeric" maxlength="6" class="input text-center text-2xl tracking-widest" required />
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
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
