<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { download, errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { CANCEL_REASONS, PAYMENT_METHODS, PAYMENT_TONES, STATUS_TONES, eventLabel, riskLabel } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const props = defineProps({ id: { type: String, required: true } })
const auth = useAuthStore()

const order = ref(null)
const error = ref('')
const busy = ref(false)
const modal = ref(null) // 'cancel' | 'complete' | 'return' | 'payment' | 'callback'
const form = reactive({ reason: 'customer_refused', note: '', collect_payment: true, method: 'cash', restock: true, amount: '', reference: '', callback_at: '' })
const formErrors = ref({})
const note = ref('')

async function load() {
  try {
    order.value = (await http.get(`/orders/${props.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const canManage = computed(() => auth.can('orders.manage'))
const canConfirm = computed(() => auth.can('orders.confirm') && order.value?.status === 'pending_confirmation')
const nextStatuses = computed(() => (order.value?.next_statuses ?? []).map((s) => s.value))

async function call(request) {
  busy.value = true
  error.value = ''
  formErrors.value = {}
  try {
    order.value = (await request()).data.data
    modal.value = null
  } catch (e) {
    formErrors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

const setStatus = (status, extra = {}) => call(() => http.post(`/orders/${props.id}/status`, { status, ...extra }))
const confirm = (outcome, extra = {}) => call(() => http.post(`/orders/${props.id}/confirmations`, { outcome, channel: 'call', ...extra }))

function openModal(name) {
  Object.assign(form, { reason: 'customer_refused', note: '', collect_payment: order.value.amount_due > 0, method: 'cash', restock: true, amount: order.value.amount_due, reference: '', callback_at: '' })
  formErrors.value = {}
  modal.value = name
}

function quickCallback(when) {
  const at = new Date()
  if (when === 'tomorrow') {
    at.setDate(at.getDate() + 1)
    at.setHours(9, 0, 0, 0)
  } else {
    at.setTime(at.getTime() + when * 3600000)
  }
  confirm('callback', { callback_at: at.toISOString() })
}

async function addNote() {
  await http.post(`/orders/${props.id}/notes`, { note: note.value })
  note.value = ''
  await load()
}
</script>

<template>
  <div v-if="order" class="space-y-5">
    <RouterLink to="/gestion/commandes" class="text-sm text-slate-500 hover:text-emerald-700">← Commandes</RouterLink>

    <div class="flex flex-wrap items-center gap-3">
      <h2 class="text-2xl font-bold">{{ order.reference }}</h2>
      <span class="rounded-full px-2.5 py-0.5 text-sm font-medium" :class="STATUS_TONES[order.status]">{{ order.status_label }}</span>
      <span class="text-sm font-medium" :class="PAYMENT_TONES[order.payment_status]">{{ order.payment_status_label }}</span>
      <span v-if="riskLabel(order.risk_score)" class="rounded-full px-2 py-0.5 text-xs" :class="riskLabel(order.risk_score).tone">{{ riskLabel(order.risk_score).label }}</span>
      <span class="text-sm text-slate-500">{{ order.source_label }} · {{ formatDateTime(order.created_at) }}</span>
    </div>

    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="order.duplicates.length" tone="warning">
      Autre(s) commande(s) en cours de ce client depuis 24 h :
      <RouterLink v-for="d in order.duplicates" :key="d.id" :to="`/gestion/commandes/${d.id}`" class="font-medium underline">{{ d.reference }}</RouterLink>. Doublon ?
    </Alert>
    <Alert v-if="order.customer?.is_blacklisted" tone="error">Client sur liste noire{{ order.customer.blacklist_reason ? ` : ${order.customer.blacklist_reason}` : '' }}.</Alert>
    <Alert v-if="order.status === 'cancelled'" tone="info">Annulée : {{ order.cancel_reason_label }}{{ order.cancel_note ? ` — ${order.cancel_note}` : '' }}</Alert>

    <!-- Actions -->
    <div class="flex flex-wrap gap-2">
      <template v-if="canConfirm">
        <button class="btn-brand" :disabled="busy" @click="confirm('confirmed')">✓ Client a confirmé</button>
        <button class="btn-secondary" :disabled="busy" @click="confirm('no_answer')">Injoignable</button>
        <button class="btn-secondary" :disabled="busy" @click="openModal('callback')">À rappeler…</button>
      </template>
      <template v-if="canManage">
        <button v-if="nextStatuses.includes('processing')" class="btn-secondary" :disabled="busy" @click="setStatus('processing')">En préparation</button>
        <button v-if="nextStatuses.includes('shipped')" class="btn-brand" :disabled="busy" @click="setStatus('shipped')">Expédiée</button>
        <button v-if="nextStatuses.includes('completed')" class="btn-secondary" :disabled="busy" @click="openModal('complete')">Livrée</button>
        <button v-if="order.amount_due > 0 && !['cancelled', 'returned'].includes(order.status)" class="btn-secondary" @click="openModal('payment')">+ Paiement</button>
        <RouterLink v-if="order.is_editable" :to="`/gestion/commandes/${order.id}/modifier`" class="btn-secondary">Modifier</RouterLink>
        <button v-if="nextStatuses.includes('returned')" class="btn-ghost text-red-600" @click="openModal('return')">Retour</button>
        <button v-if="nextStatuses.includes('cancelled')" class="btn-ghost text-red-600" @click="openModal('cancel')">Annuler</button>
      </template>
      <RouterLink v-else-if="canConfirm && order.is_editable" :to="`/gestion/commandes/${order.id}/modifier`" class="btn-secondary">Modifier</RouterLink>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
      <div class="space-y-5">
        <section class="card">
          <ul class="divide-y divide-slate-100">
            <li v-for="item in order.items" :key="item.id" class="flex items-center gap-3 px-4 py-3 text-sm">
              <img v-if="item.thumb" :src="item.thumb" alt="" class="h-12 w-12 rounded-lg object-cover" />
              <span class="min-w-0 flex-1">
                <span class="block font-medium">{{ item.title }}</span>
                <span class="block text-slate-500">{{ [item.variant_title, item.sku].filter(Boolean).join(' · ') }}</span>
              </span>
              <span class="text-slate-500">{{ item.quantity }} × {{ money(item.unit_price) }}</span>
              <span class="w-24 text-right font-medium">{{ money(item.total) }}</span>
            </li>
          </ul>
          <div class="space-y-1 border-t border-slate-200 px-4 py-3 text-sm">
            <div class="flex justify-between"><span>Sous-total</span><span>{{ money(order.subtotal) }}</span></div>
            <div v-if="order.discount_total" class="flex justify-between"><span>Remise</span><span>− {{ money(order.discount_total) }}</span></div>
            <div class="flex justify-between"><span>Livraison{{ order.delivery_zone ? ` (${order.delivery_zone.name})` : '' }}</span><span>{{ order.shipping_fee ? money(order.shipping_fee) : 'Offerte' }}</span></div>
            <div class="flex justify-between text-base font-semibold"><span>Total</span><span>{{ money(order.total) }}</span></div>
            <div v-if="order.amount_paid" class="flex justify-between text-slate-600"><span>Payé</span><span>{{ money(order.amount_paid) }}</span></div>
            <div v-if="order.amount_due && order.amount_paid" class="flex justify-between font-medium text-amber-800"><span>Reste à payer</span><span>{{ money(order.amount_due) }}</span></div>
            <div v-if="order.cost_total !== null && auth.can('finance.view')" class="flex justify-between text-slate-500"><span>Marge</span><span>{{ money(order.subtotal - order.discount_total - order.cost_total) }}</span></div>
          </div>
        </section>

        <section v-if="order.payments.length" class="card p-4">
          <h3 class="mb-2 font-semibold">Paiements</h3>
          <ul class="divide-y divide-slate-100 text-sm">
            <li v-for="p in order.payments" :key="p.id" class="flex justify-between gap-3 py-2">
              <span>{{ p.kind_label }} · {{ p.method_label }}<span v-if="p.reference" class="text-slate-400"> · {{ p.reference }}</span><span class="block text-xs text-slate-500">{{ formatDateTime(p.paid_at) }} · {{ p.recorded_by }}</span></span>
              <span class="font-medium">{{ money(p.amount) }}</span>
            </li>
          </ul>
        </section>

        <section class="card p-4">
          <h3 class="mb-3 font-semibold">Historique</h3>
          <form class="mb-3 flex gap-2" @submit.prevent="addNote">
            <input v-model="note" class="input" placeholder="Ajouter une note…" maxlength="1000" />
            <button class="btn-secondary" :disabled="!note.trim()">Ajouter</button>
          </form>
          <ol class="space-y-2 text-sm">
            <li v-for="(e, i) in order.events" :key="i" class="flex gap-3">
              <span class="w-28 shrink-0 text-xs text-slate-500">{{ formatDateTime(e.created_at) }}</span>
              <span>{{ eventLabel(e) }}<span v-if="e.user" class="text-slate-400"> · {{ e.user }}</span></span>
            </li>
          </ol>
        </section>
      </div>

      <aside class="space-y-4">
        <section class="card space-y-2 p-4 text-sm">
          <h3 class="font-semibold">Client</h3>
          <RouterLink v-if="order.customer_id && auth.can('customers.view')" :to="`/gestion/clients/${order.customer_id}`" class="block font-medium hover:text-emerald-700">{{ order.customer_name || 'Sans nom' }}</RouterLink>
          <p v-else class="font-medium">{{ order.customer_name }}</p>
          <p>{{ order.customer_phone_display }}</p>
          <p v-if="order.customer" class="text-slate-500">
            {{ order.customer.orders_count }} commande(s)<template v-if="order.customer.delivery_rate !== null"> · {{ order.customer.delivery_rate }} % livrées</template>
          </p>
          <div class="flex gap-2 pt-1">
            <a :href="order.call_url" class="btn-secondary flex-1 px-2 py-1.5">📞 Appeler</a>
            <a :href="`https://wa.me/${order.customer_phone.replace('+', '')}`" target="_blank" class="btn-secondary flex-1 px-2 py-1.5">WhatsApp</a>
          </div>
        </section>

        <section class="card space-y-1 p-4 text-sm">
          <h3 class="font-semibold">{{ order.delivery_method === 'pickup' ? 'Retrait en boutique' : 'Livraison' }}</h3>
          <template v-if="order.delivery_method === 'delivery'">
            <p>{{ order.shipping_place_label || 'Quartier à préciser' }}</p>
            <p v-if="order.shipping_address">{{ order.shipping_address }}</p>
            <p v-if="order.shipping_landmark" class="text-slate-500">Repère : {{ order.shipping_landmark }}</p>
            <p v-if="order.shipping_phone2" class="text-slate-500">Autre numéro : {{ order.shipping_phone2 }}</p>
          </template>
          <p v-if="order.scheduled_date" class="text-slate-500">Souhaitée le {{ new Date(order.scheduled_date).toLocaleDateString('fr-FR') }}</p>
          <p v-if="order.customer_note" class="mt-2 rounded bg-amber-50 p-2">« {{ order.customer_note }} »</p>
          <p v-if="order.internal_note" class="mt-2 rounded bg-slate-50 p-2 text-slate-600">Note : {{ order.internal_note }}</p>
        </section>

        <section v-if="order.messages.length" class="card space-y-2 p-4 text-sm">
          <h3 class="font-semibold">Message WhatsApp au client</h3>
          <a v-for="m in order.messages" :key="m.key" :href="m.url" target="_blank" rel="noopener" class="btn-secondary w-full justify-start">💬 {{ m.label }}</a>
        </section>

        <section class="card space-y-2 p-4 text-sm">
          <h3 class="font-semibold">Documents</h3>
          <button class="btn-secondary w-full justify-start" @click="download(`/orders/${order.id}/invoice`, 'facture.pdf')">📄 Facture</button>
          <button class="btn-secondary w-full justify-start" @click="download(`/orders/${order.id}/label`, 'etiquette.pdf')">🏷️ Étiquette colis</button>
          <a :href="order.tracking_url" target="_blank" class="btn-ghost w-full justify-start">Page de suivi du client ↗</a>
        </section>

        <section v-if="order.confirmations.length" class="card p-4 text-sm">
          <h3 class="mb-2 font-semibold">Tentatives de confirmation</h3>
          <ul class="space-y-1">
            <li v-for="(c, i) in order.confirmations" :key="i">
              <span class="font-medium">{{ c.outcome_label }}</span> <span class="text-slate-500">· {{ formatDateTime(c.created_at) }} · {{ c.user }}</span>
              <span v-if="c.note" class="block text-slate-600">{{ c.note }}</span>
            </li>
          </ul>
        </section>
      </aside>
    </div>

    <Modal v-if="modal === 'cancel'" title="Annuler la commande" @close="modal = null">
      <form class="space-y-4" @submit.prevent="setStatus('cancelled', { reason: form.reason, note: form.note })">
        <Field id="reason" label="Motif" :error="formErrors.reason">
          <select id="reason" v-model="form.reason" class="input">
            <option v-for="r in CANCEL_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </Field>
        <Field id="cancel-note" label="Précision (facultatif)"><input id="cancel-note" v-model="form.note" class="input" maxlength="500" /></Field>
        <p class="text-sm text-slate-500">Les articles réservés redeviennent disponibles.</p>
        <button class="btn-danger w-full" :disabled="busy">Annuler la commande</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'complete'" title="Commande livrée" @close="modal = null">
      <form class="space-y-4" @submit.prevent="setStatus('completed', form.collect_payment ? { collect_payment: true, method: form.method } : {})">
        <label v-if="order.amount_due > 0" class="flex items-center gap-2 text-sm">
          <input v-model="form.collect_payment" type="checkbox" class="h-4 w-4 accent-emerald-700" />
          Le reste à payer ({{ money(order.amount_due) }}) a été encaissé
        </label>
        <Field v-if="form.collect_payment && order.amount_due > 0" id="method" label="Moyen de paiement">
          <select id="method" v-model="form.method" class="input">
            <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </Field>
        <button class="btn-brand w-full" :disabled="busy">Marquer comme livrée</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'return'" title="Commande retournée" @close="modal = null">
      <form class="space-y-4" @submit.prevent="setStatus('returned', { restock: form.restock, note: form.note })">
        <label class="flex items-center gap-2 text-sm"><input v-model="form.restock" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Remettre les articles en stock (en bon état)</label>
        <Field id="return-note" label="Précision (facultatif)"><input id="return-note" v-model="form.note" class="input" maxlength="500" placeholder="Refusé à la porte, article abîmé…" /></Field>
        <button class="btn-danger w-full" :disabled="busy">Enregistrer le retour</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'payment'" title="Paiement reçu" @close="modal = null">
      <form class="space-y-4" @submit.prevent="call(() => http.post(`/orders/${props.id}/payments`, { amount: Number(form.amount), method: form.method, reference: form.reference || null }))">
        <Field id="amount" label="Montant (F)" :error="formErrors.amount" :hint="`Reste à payer : ${money(order.amount_due)}`">
          <input id="amount" v-model="form.amount" class="input" type="number" min="1" :max="order.amount_due" required />
        </Field>
        <Field id="pay-method" label="Moyen de paiement">
          <select id="pay-method" v-model="form.method" class="input">
            <option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </Field>
        <Field id="pay-ref" label="Référence de la transaction (facultatif)"><input id="pay-ref" v-model="form.reference" class="input" maxlength="100" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'callback'" title="Rappeler le client" @close="modal = null">
      <div class="space-y-3">
        <div class="grid grid-cols-3 gap-2">
          <button class="btn-secondary" @click="quickCallback(1)">Dans 1 h</button>
          <button class="btn-secondary" @click="quickCallback(3)">Dans 3 h</button>
          <button class="btn-secondary" @click="quickCallback('tomorrow')">Demain 9 h</button>
        </div>
        <p class="text-sm text-slate-500">Autres réponses :</p>
        <div class="flex flex-wrap gap-2">
          <button class="btn-ghost text-red-600" @click="confirm('wrong_number')">Faux numéro</button>
          <button class="btn-ghost text-red-600" @click="confirm('refused')">Refuse la commande</button>
        </div>
      </div>
    </Modal>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
