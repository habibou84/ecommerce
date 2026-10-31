<script setup>
import Icon from './Icon.vue'
import { useAuthStore } from '../stores/auth'
import CourierWhatsAppButton from './CourierWhatsAppButton.vue'
import NewCourierForm from './NewCourierForm.vue'
import DelivererPicker from './DelivererPicker.vue'
import { computed, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Field from './Field.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { formatDateTime, money } from '../lib/format'
import { MANUAL_STATUSES, SHIPMENT_TONES, SOURCE_LABELS } from '../lib/shipping'

// « aujourd'hui », « demain » ou « le lundi 23 novembre »
function dayLabel(iso) {
  const day = new Date(`${iso}T00:00:00`)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diff = Math.round((day - today) / 86400000)
  if (diff === 0) return 'aujourd\'hui'
  if (diff === 1) return 'demain'
  return 'le ' + day.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}

const props = defineProps({ order: { type: Object, required: true } })
const emit = defineEmits(['changed'])

const shipments = computed(() => [...(props.order.shipments ?? [])].reverse())
const active = computed(() => shipments.value.find((s) => !['cancelled', 'rejected'].includes(s.status)))
const canShip = computed(() => props.order.delivery_method === 'delivery' && ['confirmed', 'processing'].includes(props.order.status) && !active.value)

// Livreur ou entreprise déjà choisi pour la commande (pas encore de course) : affiché à la place de « Faire livrer »
const planned = computed(() => (!active.value && props.order.delivery_method === 'delivery' ? props.order.planned_courier ?? props.order.planned_carrier ?? null : null))
const plannedKey = computed(() => (props.order.planned_courier ? `courier:${props.order.planned_courier.id}` : props.order.planned_carrier ? `carrier:${props.order.planned_carrier.id}` : ''))
const pickerOpen = ref(false)
const pickerOptions = ref(null)
async function openPicker() {
  error.value = ''
  pickerOpen.value = true
  pickerOptions.value ??= (await http.post('/orders/delivery-options', {
    place_id: props.order.shipping_place_id ?? null,
    items: (props.order.items ?? []).filter((i) => i.variant_id).map((i) => ({ variant_id: i.variant_id, quantity: i.quantity })),
  }).catch(() => ({ data: { data: null } }))).data.data
}
// Commande à confirmer : le choix est gardé ; confirmée : la course part aussitôt chez le livreur choisi
async function assignTo(key) {
  pickerOpen.value = false
  if (!key) return
  const [type, id] = key.split(':')
  await run(async () => {
    const { data } = await http.post('/orders/bulk', { ids: [props.order.id], action: 'assign', [type === 'courier' ? 'courier_id' : 'carrier_connection_id']: Number(id) })
    const skipped = data.data.skipped?.[0]
    if (skipped) throw new Error(skipped.reason)
  }, false)
}

const modal = ref(null) // 'ship' | 'status' | 'cancel'
const quotes = ref(null)
const locations = ref([])
const couriers = ref([])
const suggestion = ref(null)
const form = reactive({ target: null, carrier_connection_id: null, courier_id: null, origin_location_id: null, fee_payer: '', note: '', status: 'delivered', collected_amount: '', reason: '', date: '' })
const auth = useAuthStore()
const current = ref(null)
// Livreur créé dans la fenêtre « Faire livrer » : ajouté à la liste et choisi
const addingCourier = ref(false)
function courierCreated(c) {
  couriers.value = [...couriers.value, { id: c.id, name: c.name, is_available: true, active_missions: 0, covers_zone: false, connected: false, stats: null, cost: null }]
  form.target = `courier:${c.id}`
  addingCourier.value = false
}
const error = ref('')
const busy = ref(false)

async function openShip() {
  modal.value = 'ship'
  error.value = ''
  quotes.value = null
  try {
    const { data } = await http.get(`/orders/${props.order.id}/shipping-quotes`)
    quotes.value = data.data
    locations.value = data.locations
    couriers.value = data.couriers ?? []
    form.origin_location_id = data.location_id ?? data.locations[0]?.id ?? null
    // Suggestion des règles d'affectation (ou par défaut : mon livreur du quartier, sinon le moins cher)
    suggestion.value = data.assignment_mode === 'off' ? null : data.suggestion
    form.target = suggestion.value ? `${suggestion.value.type}:${suggestion.value.id}` : null
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Historique sur 90 jours : réussite et délai moyen (dans le quartier si assez de courses)
function history(stats) {
  if (!stats?.shipments) return 'Pas encore d’historique'
  const parts = [`${stats.success_rate} % livrées`]
  if (stats.avg_hours !== null) parts.push(stats.avg_hours < 24 ? `${Math.round(stats.avg_hours)} h en moyenne` : `${Math.round(stats.avg_hours / 24)} j en moyenne`)
  parts.push(`${stats.shipments} course(s)${stats.scope === 'zone' ? ' dans cette zone' : ''}`)
  return parts.join(' · ')
}

async function run(request, close = true) {
  busy.value = true
  error.value = ''
  try {
    await request()
    if (close) modal.value = null
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const ship = () => run(() => http.post(`/orders/${props.order.id}/shipments`, {
  carrier_connection_id: form.target?.startsWith('carrier:') ? Number(form.target.split(':')[1]) : null,
  courier_id: form.target?.startsWith('courier:') ? Number(form.target.split(':')[1]) : null,
  origin_location_id: form.origin_location_id,
  fee_payer: form.fee_payer || null,
  note: form.note || null,
}))
const reassign = () => run(() => http.post(`/shipments/${current.value.id}/assign`, { courier_id: form.courier_id }))
const received = (s) => run(() => http.post(`/shipments/${s.id}/status`, { status: 'returned', note: 'Colis reçu à la boutique' }), false)

async function openReassign(s) {
  current.value = s
  error.value = ''
  const { data } = await http.get(`/orders/${props.order.id}/shipping-quotes`)
  couriers.value = (data.couriers ?? []).filter((c) => c.id !== s.courier?.id)
  form.courier_id = couriers.value[0]?.id ?? null
  modal.value = 'reassign'
}

const refresh = (s) => run(() => http.post(`/shipments/${s.id}/refresh`), false)
const cancel = () => run(() => http.post(`/shipments/${current.value.id}/cancel`, { reason: form.reason || null }))
const setStatus = () => run(() => http.post(`/shipments/${current.value.id}/status`, {
  status: form.status,
  note: form.note || null,
  collected_amount: form.status === 'delivered' && form.collected_amount !== '' ? Number(form.collected_amount) : null,
}))

// Actions sur la course chez l'entreprise de livraison (Delivery App)
const todayIso = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const edit = reactive({})
function openEdit(s) {
  const o = props.order
  Object.assign(edit, {
    delivery_address: o.shipping_address || '',
    delivery_landmark: o.shipping_landmark || '',
    recipient_phone: o.shipping_phone || o.customer_phone || '',
    recipient_phone2: o.shipping_phone2 || '',
    delivery_scheduled_date: s.scheduled_date || '',
    items_amount: s.cod_amount,
  })
  openModal('edit', s)
}
function saveEdit() {
  const fields = { ...edit }
  if (current.value.carrier_actions.edit !== 'all' || current.value.fee_payer !== 'store') delete fields.items_amount
  if (!fields.delivery_scheduled_date) delete fields.delivery_scheduled_date
  return run(() => http.patch(`/shipments/${current.value.id}/carrier`, fields))
}
const decide = (decision) => run(() => http.post(`/shipments/${current.value.id}/decision`, { decision, date: decision === 'redeliver' ? form.date : null, note: form.note || null }))
const askReturn = () => run(() => http.post(`/shipments/${current.value.id}/return-request`, { note: form.note || null }))
const sendNote = () => run(() => http.post(`/shipments/${current.value.id}/notes`, { note: form.note }))

// Noter le livreur chez l'entreprise de livraison (remarques du contrat : Contract::MERCHANT_RATING_TAGS)
const RATING_TAGS = [
  { code: 'pickup_on_time', label: 'Ramassage à l\'heure', good: true },
  { code: 'careful', label: 'Colis bien traité', good: true },
  { code: 'good_with_customers', label: 'Bon contact avec mes clients', good: true },
  { code: 'cash_returned', label: 'Argent bien rendu', good: true },
  { code: 'pickup_late', label: 'Ramassage en retard', good: false },
  { code: 'customer_mistreated', label: 'Client mal traité', good: false },
  { code: 'unreachable', label: 'Pas joignable', good: false },
]
const rating = reactive({ score: 0, tags: [], comment: '' })
const stars = (n) => '★'.repeat(n) + '☆'.repeat(5 - n)
function openRating(s) {
  Object.assign(rating, { score: 0, tags: [], comment: '' })
  openModal('rate', s)
}
function toggleTag(code) {
  rating.tags = rating.tags.includes(code) ? rating.tags.filter((c) => c !== code) : [...rating.tags, code]
}
const sendRating = () => run(() => http.post(`/shipments/${current.value.id}/rating`, { score: rating.score, tags: rating.tags, comment: rating.comment || null }))

function openModal(name, s) {
  current.value = s
  form.note = ''
  form.reason = ''
  form.date = ''
  form.collected_amount = s.cod_amount
  error.value = ''
  modal.value = name
}

async function whatsapp(s) {
  const { data } = await http.get(`/shipments/${s.id}/message`)
  if (data.data.whatsapp_url) window.open(data.data.whatsapp_url, '_blank')
  else {
    await navigator.clipboard?.writeText(data.data.text)
    alert('Message copié : collez-le dans WhatsApp.')
  }
}
</script>

<template>
  <section class="card space-y-3 p-4 text-sm">
    <div class="flex items-center gap-2">
      <h3 class="font-semibold">Livraison</h3>
      <button v-if="canShip && !planned" class="btn-brand ml-auto px-3 py-1.5" @click="openShip"><Icon name="truck" :size="16" class="mr-1 inline-block align-[-3px]" />Faire livrer</button>
    </div>
    <!-- Livreur déjà choisi : son nom et « Modifier » au lieu de « Faire livrer » -->
    <div v-if="planned" class="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-600 text-white"><Icon name="truck" :size="18" /></span>
      <span class="min-w-0 flex-1">
        <span class="block font-semibold">{{ planned.name }}</span>
        <span class="block text-xs text-emerald-800">{{ order.status === 'pending_confirmation' ? 'Livreur choisi · la course part dès la confirmation' : canShip ? 'Livreur choisi · course en préparation' : 'Livreur choisi' }}</span>
      </span>
      <button type="button" class="btn-secondary shrink-0 px-3 py-1.5" :disabled="busy" @click="openPicker">Modifier</button>
    </div>
    <button v-if="planned && canShip" type="button" class="btn-brand w-full py-2" :disabled="busy" @click="assignTo(plannedKey)">Envoyer la course à {{ planned.name }}</button>
    <p v-if="!shipments.length && !canShip && !planned" class="text-slate-500">
      {{ order.delivery_method === 'pickup' ? 'Retrait en boutique.' : 'Confirmez la commande pour la faire livrer.' }}
    </p>
    <Alert v-if="error && !modal">{{ error }}</Alert>

    <article v-for="s in shipments" :key="s.id" class="space-y-2 rounded-lg border border-slate-200 p-3" :class="{ 'opacity-60': ['cancelled', 'rejected'].includes(s.status) }">
      <div class="flex flex-wrap items-center gap-2">
        <span class="font-medium">{{ s.reference }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[s.status]">{{ s.status_label }}</span>
        <span class="text-slate-500">{{ s.carrier?.name }}</span>
      </div>
      <p v-if="s.tracking_code" class="text-slate-600">
        N° {{ s.tracking_code }}
        <a v-if="s.tracking_url" :href="s.tracking_url" target="_blank" rel="noopener" class="ml-1 text-emerald-700 underline">suivi</a>
        <span v-if="s.delivery_code"> · code client <strong>{{ s.delivery_code }}</strong></span>
      </p>
      <p class="text-slate-600">
        À encaisser {{ money(s.cod_amount) }}<template v-if="s.carrier_fee !== null"> · tarif {{ money(s.carrier_fee) }}</template>
        <template v-if="s.collected_amount !== null"> · encaissé {{ money(s.collected_amount) }}</template>
      </p>
      <p v-if="s.scheduled_date && !s.is_final" class="text-slate-600">Livraison prévue <strong>{{ dayLabel(s.scheduled_date) }}</strong></p>
      <div v-if="s.needs_decision && !s.is_final" class="rounded bg-rose-50 p-2 text-rose-900">
        <p class="font-medium">{{ s.carrier?.name || 'L\'entreprise de livraison' }} attend votre décision : nouvelle date ou retour du colis.</p>
        <div v-if="s.carrier_actions?.decide" class="mt-2 flex flex-wrap gap-2">
          <button class="btn-brand px-3 py-1.5" @click="openModal('redeliver', s)">Relivrer le…</button>
          <button class="btn-secondary px-3 py-1.5" @click="openModal('return', s)">Faire revenir le colis</button>
        </div>
      </div>
      <p v-else-if="s.return_requested && !s.is_final" class="rounded bg-violet-50 p-2 text-violet-900">Retour du colis demandé.</p>
      <p v-if="s.failure_reason" class="rounded bg-amber-50 p-2 text-amber-900">{{ s.failure_reason }}<template v-if="s.attempts_count"> ({{ s.attempts_count }} tentative{{ s.attempts_count > 1 ? 's' : '' }})</template></p>
      <a v-if="s.proof_photo_url" :href="s.proof_photo_url" target="_blank" rel="noopener" class="text-emerald-700 underline">Photo de livraison</a>
      <!-- Étiquette de l'entreprise de livraison, à coller sur le colis avant le ramassage -->
      <a v-if="s.label_url && !s.is_final" :href="s.label_url" target="_blank" rel="noopener" class="btn-secondary inline-flex w-fit px-3 py-1.5">🏷️ Imprimer l'étiquette{{ s.carrier?.name ? ` de ${s.carrier.name}` : '' }}</a>
      <!-- Notes du livreur chez l'entreprise de livraison -->
      <div v-if="s.carrier_ratings && (s.can_rate_courier || s.carrier_ratings.merchant || s.carrier_ratings.recipient)" class="rounded bg-slate-50 p-2 space-y-1">
        <p v-if="s.carrier_ratings.merchant">Votre note du livreur : <span class="text-amber-500" aria-hidden="true">{{ stars(s.carrier_ratings.merchant.score) }}</span> <strong>{{ s.carrier_ratings.merchant.score }}/5</strong><template v-if="s.carrier_ratings.merchant.comment"> · « {{ s.carrier_ratings.merchant.comment }} »</template></p>
        <p v-if="s.carrier_ratings.recipient">Note du client : <span class="text-amber-500" aria-hidden="true">{{ stars(s.carrier_ratings.recipient.score) }}</span> <strong>{{ s.carrier_ratings.recipient.score }}/5</strong><template v-if="s.carrier_ratings.recipient.comment"> · « {{ s.carrier_ratings.recipient.comment }} »</template></p>
        <button v-if="s.can_rate_courier" class="btn-secondary px-2 py-1" @click="openRating(s)">⭐ Noter le livreur</button>
      </div>
      <ol class="space-y-1 border-l-2 border-slate-100 pl-3 text-xs text-slate-600">
        <li v-for="(e, i) in s.events" :key="i">
          <span class="text-slate-400">{{ formatDateTime(e.occurred_at) }}</span> · {{ e.status_label }}
          <span class="text-slate-400">({{ SOURCE_LABELS[e.source] ?? e.source }})</span><template v-if="e.note"> — {{ e.note }}</template>
        </li>
      </ol>
      <p v-if="s.courier" class="text-slate-600">Livreur : <strong>{{ s.courier.name }}</strong> · <a :href="`tel:${s.courier.phone}`" class="text-emerald-700 underline">appeler</a></p>
      <CourierWhatsAppButton v-if="s.courier && !s.is_final && auth.can('couriers.manage')" :courier-id="s.courier.id" small />
      <p v-if="s.courier && !s.is_final" class="text-xs" :class="s.courier.accepted_at ? 'text-emerald-700' : 'text-amber-700'">
        <template v-if="s.courier.accepted_at">✓ Mission acceptée à {{ new Date(s.courier.accepted_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</template>
        <template v-else-if="s.courier.seen_at">Vue à {{ new Date(s.courier.seen_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}, pas encore acceptée</template>
        <template v-else-if="s.status === 'awaiting_pickup'">Pas encore vue par le livreur</template>
      </p>
      <div v-if="!s.is_final" class="flex flex-wrap gap-2 pt-1">
        <template v-if="s.mode === 'own_courier'">
          <button v-if="s.status === 'returning'" class="btn-brand px-2 py-1" :disabled="busy" @click="received(s)">Colis reçu à la boutique</button>
          <button v-else-if="!['in_transit', 'out_for_delivery'].includes(s.status)" class="btn-secondary px-2 py-1" @click="openReassign(s)">Modifier le livreur</button>
        </template>
        <template v-else-if="s.carrier?.driver === 'manual'">
          <button class="btn-secondary px-2 py-1" @click="whatsapp(s)"><Icon name="messages" :size="16" class="mr-1 inline-block align-[-3px]" />Envoyer la course</button>
          <button class="btn-secondary px-2 py-1" @click="openModal('status', s)">Mettre à jour</button>
        </template>
        <template v-else>
          <button class="btn-secondary px-2 py-1" :disabled="busy" @click="refresh(s)">Actualiser</button>
          <!-- Actions sur la course chez l'entreprise de livraison -->
          <button v-if="s.carrier_actions?.edit" class="btn-secondary px-2 py-1" @click="openEdit(s)">Modifier la course</button>
          <button v-if="s.carrier_actions?.return" class="btn-secondary px-2 py-1" @click="openModal('return', s)">Demander le retour</button>
          <button v-if="s.carrier_actions?.note" class="btn-ghost px-2 py-1" @click="openModal('note', s)">Ajouter une note</button>
        </template>
        <button v-if="s.is_cancellable" class="btn-ghost px-2 py-1 text-red-600" @click="openModal('cancel', s)">Annuler la course</button>
      </div>
    </article>

    <Modal v-if="modal === 'ship'" title="Faire livrer la commande" @close="modal = null">
      <form class="space-y-4" @submit.prevent="ship">
        <Alert v-if="error">{{ error }}</Alert>
        <p v-if="!quotes" class="text-sm text-slate-500">Demande des tarifs…</p>
        <template v-else-if="!quotes.length && !couriers.length">
          <p class="text-sm">Aucun livreur ni entreprise de livraison pour l'instant.</p>
          <NewCourierForm v-if="auth.can('couriers.manage')" @created="courierCreated" @cancel="modal = null" />
          <RouterLink to="/gestion/livraisons/entreprises" class="btn-secondary">Connecter une entreprise de livraison</RouterLink>
        </template>
        <template v-else>
          <p v-if="suggestion" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-900"><Icon name="lightbulb" :size="16" class="mr-1 inline-block align-[-3px]" />Suggestion : <strong>{{ suggestion.name }}</strong> <span class="text-emerald-700">({{ suggestion.reason }})</span></p>
          <fieldset v-if="couriers.length" class="space-y-2">
            <legend class="label">Mes livreurs</legend>
            <label v-for="c in couriers" :key="c.id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                   :class="form.target === `courier:${c.id}` ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
              <input v-model="form.target" type="radio" :value="`courier:${c.id}`" class="accent-emerald-700" />
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ c.name }}</span>
                <span class="block text-xs text-slate-500">{{ c.connected === false ? 'Pas encore connecté' : c.is_available ? 'Disponible' : 'Indisponible' }}{{ c.covers_zone ? ' · couvre ce quartier' : '' }} · {{ c.active_missions }} en cours</span>
                <span class="block text-xs text-slate-400">{{ history(c.stats) }}</span>
              </span>
              <span v-if="c.cost !== null && c.cost !== undefined" class="text-right"><span class="block font-semibold">{{ money(c.cost) }}</span><span class="block text-xs text-slate-400">sa paie</span></span>
            </label>
          </fieldset>
          <template v-if="auth.can('couriers.manage')">
            <NewCourierForm v-if="addingCourier" @created="courierCreated" @cancel="addingCourier = false" />
            <button v-else type="button" class="btn-ghost w-full border border-dashed border-emerald-600 text-emerald-800" @click="addingCourier = true">+ Nouveau livreur</button>
          </template>
          <fieldset v-if="quotes.length" class="space-y-2">
            <legend class="label">Entreprises de livraison</legend>
            <label v-for="q in quotes" :key="q.connection_id" class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm"
                   :class="form.target === `carrier:${q.connection_id}` ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
              <input v-model="form.target" type="radio" :value="`carrier:${q.connection_id}`" :disabled="!!q.error" class="accent-emerald-700" />
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ q.name }}</span>
                <span class="block text-xs" :class="q.error ? 'text-amber-800' : 'text-slate-500'">{{ q.error || q.zone || (q.driver === 'manual' ? 'Course demandée par WhatsApp' : '') }}</span>
                <span v-if="q.earliest_date && !q.error" class="block text-xs text-slate-500">Livrable {{ dayLabel(q.earliest_date) }}<template v-if="q.day_message"> · {{ q.day_message }}</template></span>
                <span v-if="q.stats" class="block text-xs text-slate-400">{{ history(q.stats) }}</span>
              </span>
              <span v-if="q.fee !== null" class="font-semibold">{{ money(q.fee) }}</span>
            </label>
          </fieldset>
          <Field id="ship-origin" label="Départ du colis">
            <select id="ship-origin" v-model="form.origin_location_id" class="input">
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </Field>
          <Field v-if="form.target?.startsWith('carrier:')" id="ship-payer" label="Livraison payée par" hint="Par vous : le client paie exactement le montant de la commande ; le tarif de l'entreprise est retenu sur ce qu'elle vous reverse.">
            <select id="ship-payer" v-model="form.fee_payer" class="input">
              <option value="">Réglage de l'entreprise</option>
              <option value="store">Vous</option>
              <option value="customer">Le client (tarif de l'entreprise)</option>
            </select>
          </Field>
          <Field id="ship-note" label="Note pour l'entreprise de livraison">
            <input id="ship-note" v-model="form.note" class="input" maxlength="255" />
          </Field>
          <p class="text-sm text-slate-600">À encaisser auprès du client : <strong>{{ money(order.amount_due) }}</strong></p>
          <button class="btn-brand w-full" :disabled="busy || !form.target">{{ busy ? 'Envoi…' : form.target?.startsWith('courier:') ? 'Confier au livreur' : 'Demander la course' }}</button>
        </template>
      </form>
    </Modal>

    <Modal v-if="modal === 'status'" :title="`Livraison ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="setStatus">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="sh-status" label="Nouveau statut">
          <select id="sh-status" v-model="form.status" class="input">
            <option v-for="st in MANUAL_STATUSES" :key="st.value" :value="st.value">{{ st.label }}</option>
          </select>
        </Field>
        <Field v-if="form.status === 'delivered'" id="sh-collected" label="Montant encaissé par le livreur">
          <input id="sh-collected" v-model="form.collected_amount" type="number" min="0" class="input" />
        </Field>
        <Field id="sh-note" label="Note">
          <input id="sh-note" v-model="form.note" class="input" maxlength="255" />
        </Field>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'reassign'" :title="`Autre livreur pour ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="reassign">
        <Alert v-if="error">{{ error }}</Alert>
        <p v-if="!couriers.length" class="text-sm text-slate-500">Aucun autre livreur actif.</p>
        <select v-else v-model="form.courier_id" class="input" aria-label="Livreur">
          <option v-for="c in couriers" :key="c.id" :value="c.id">{{ c.name }}{{ c.is_available ? '' : ' (indisponible)' }}</option>
        </select>
        <button class="btn-brand w-full" :disabled="busy || !form.courier_id">Confier</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'edit'" :title="`Modifier la course ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="saveEdit">
        <Alert v-if="error">{{ error }}</Alert>
        <p class="text-xs text-slate-500">
          {{ current.carrier_actions.edit === 'all' ? 'Avant le ramassage, tout se modifie.' : 'Le colis est déjà récupéré : seule la livraison se modifie.' }}
          Pour changer le quartier, modifiez la commande : la course suivra.
        </p>
        <Field id="ce-address" label="Adresse"><input id="ce-address" v-model="edit.delivery_address" class="input" maxlength="500" /></Field>
        <Field id="ce-landmark" label="Repère"><input id="ce-landmark" v-model="edit.delivery_landmark" class="input" maxlength="255" /></Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="ce-phone" label="Téléphone du client"><input id="ce-phone" v-model="edit.recipient_phone" class="input" type="tel" required /></Field>
          <Field id="ce-phone2" label="Second téléphone"><input id="ce-phone2" v-model="edit.recipient_phone2" class="input" type="tel" /></Field>
        </div>
        <Field id="ce-date" label="Date de livraison"><input id="ce-date" v-model="edit.delivery_scheduled_date" class="input" type="date" :min="todayIso" /></Field>
        <Field v-if="current.carrier_actions.edit === 'all' && current.fee_payer === 'store'" id="ce-amount" label="Montant à encaisser (F)">
          <input id="ce-amount" v-model.number="edit.items_amount" class="input" type="number" min="0" />
        </Field>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer chez {{ current.carrier?.name }}</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'redeliver'" :title="`Relivrer ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="decide('redeliver')">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="cd-date" label="Nouvelle date de livraison"><input id="cd-date" v-model="form.date" class="input" type="date" :min="todayIso" required /></Field>
        <Field id="cd-note" label="Note pour le livreur (facultatif)"><input id="cd-note" v-model="form.note" class="input" maxlength="1000" placeholder="Ex. le client sera là après 14 h" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Demander la relivraison</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'return'" :title="`Faire revenir le colis ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="current.carrier_actions?.decide ? decide('return') : askReturn()">
        <Alert v-if="error">{{ error }}</Alert>
        <p class="text-sm text-slate-600">{{ current.carrier?.name }} ramènera le colis à la boutique.</p>
        <Field id="cr-note" label="Raison (facultatif)"><input id="cr-note" v-model="form.note" class="input" maxlength="1000" placeholder="Ex. le client a annulé" /></Field>
        <button class="btn-danger w-full" :disabled="busy">Demander le retour</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'note'" :title="`Note pour ${current.carrier?.name}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="sendNote">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="cn-note" label="Note pour l'agence et le livreur"><textarea id="cn-note" v-model="form.note" class="input" rows="3" maxlength="2000" required placeholder="Ex. appeler avant, portail bleu" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Envoyer</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'rate'" :title="`Noter le livreur de ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="sendRating">
        <Alert v-if="error">{{ error }}</Alert>
        <div>
          <p class="mb-1 text-sm font-medium">Note</p>
          <div class="flex gap-1" role="radiogroup" aria-label="Note du livreur">
            <button v-for="n in 5" :key="n" type="button" role="radio" :aria-checked="rating.score === n" :aria-label="`${n} sur 5`" :class="['text-3xl leading-none', n <= rating.score ? 'text-amber-500' : 'text-slate-300']" @click="rating.score = n">★</button>
          </div>
        </div>
        <div>
          <p class="mb-1 text-sm font-medium">Remarques (facultatif)</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="t in RATING_TAGS" :key="t.code" type="button" :aria-pressed="rating.tags.includes(t.code)" :class="['rounded-full border px-3 py-1 text-sm', rating.tags.includes(t.code) ? (t.good ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-red-400 bg-red-50 text-red-800') : 'border-slate-300']" @click="toggleTag(t.code)">{{ t.label }}</button>
          </div>
        </div>
        <Field id="rt-comment" label="Commentaire (facultatif)"><textarea id="rt-comment" v-model="rating.comment" class="input" rows="2" maxlength="500" /></Field>
        <p class="text-xs text-slate-500">Envoyé à {{ current.carrier?.name }}. Votre note reste anonyme pour le livreur.</p>
        <button class="btn-brand w-full" :disabled="busy || !rating.score">Enregistrer la note</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'cancel'" :title="`Annuler la course ${current.reference}`" @close="modal = null">
      <form class="space-y-4" @submit.prevent="cancel">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="sh-reason" label="Motif">
          <input id="sh-reason" v-model="form.reason" class="input" maxlength="255" />
        </Field>
        <button class="btn-danger w-full" :disabled="busy">Annuler la course</button>
      </form>
    </Modal>
    <DelivererPicker v-if="pickerOpen" :model-value="plannedKey" :options="pickerOptions" :place-label="order.shipping_place_label ?? ''" @update:model-value="assignTo" @close="pickerOpen = false" />
  </section>
</template>
