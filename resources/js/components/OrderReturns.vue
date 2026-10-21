<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Field from './Field.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { formatDateTime, money } from '../lib/format'
import { useAuthStore } from '../stores/auth'

// Retours et échanges d'une commande livrée : articles rendus, remplacement (commande d'échange), remboursement
const props = defineProps({ order: { type: Object, required: true } })
const emit = defineEmits(['changed'])
const auth = useAuthStore()

const returns = ref([])
const error = ref('')
const busy = ref(false)
const form = reactive({ open: false, lines: [], reason: '', restock: true, note: '', exchange_shipping_fee: 0, error: '' })
const variants = ref({}) // product_id → variantes actives

async function load() {
  returns.value = (await http.get(`/orders/${props.order.id}/returns`)).data.data
}
onMounted(load)

async function start() {
  Object.assign(form, { open: true, reason: '', restock: true, note: '', exchange_shipping_fee: 0, error: '' })
  form.lines = props.order.items.map((i) => ({ item: i, quantity: 0, action: 'refund', replacement_variant_id: null }))
  const ids = [...new Set(props.order.items.map((i) => i.product_id).filter(Boolean))]
  await Promise.all(ids.map(async (id) => {
    try {
      const product = (await http.get(`/products/${id}`)).data.data
      variants.value[id] = product.variants.filter((v) => v.is_active)
    } catch {
      variants.value[id] = []
    }
  }))
}

const chosen = computed(() => form.lines.filter((l) => l.quantity > 0))
const unitValue = (item) => Math.floor(item.total / item.quantity)
const returnedValue = computed(() => chosen.value.reduce((s, l) => s + unitValue(l.item) * l.quantity, 0))
const replacementValue = computed(() => chosen.value.filter((l) => l.action === 'exchange' && l.replacement_variant_id)
  .reduce((s, l) => s + (variants.value[l.item.product_id]?.find((v) => v.id === l.replacement_variant_id)?.price ?? 0) * l.quantity, 0))
const hasExchange = computed(() => chosen.value.some((l) => l.action === 'exchange'))

async function save() {
  form.error = ''
  busy.value = true
  try {
    await http.post(`/orders/${props.order.id}/returns`, {
      items: chosen.value.map((l) => ({
        order_item_id: l.item.id,
        quantity: l.quantity,
        replacement_variant_id: l.action === 'exchange' ? l.replacement_variant_id : null,
        replacement_quantity: l.action === 'exchange' ? l.quantity : null,
      })),
      reason: form.reason || null,
      restock: form.restock,
      note: form.note || null,
      exchange_shipping_fee: hasExchange.value ? Number(form.exchange_shipping_fee) || 0 : null,
    })
    form.open = false
    await load()
    emit('changed')
  } catch (e) {
    form.error = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function act(r, action) {
  error.value = ''
  if (action === 'receive' && !confirm(`Articles de ${r.reference} bien reçus ?${r.restock ? ' Ils seront remis en stock.' : ''}${r.refund_due && auth.can('payments.manage') ? ` ${money(r.refund_due)} seront notés comme remboursés au client.` : ''}`)) return
  if (action === 'cancel' && !confirm(`Annuler ${r.reference} ?${r.exchange_order ? ' La commande d\'échange sera annulée.' : ''}`)) return
  try {
    await http.post(`/returns/${r.id}/${action}`)
    await load()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <section v-if="order.status === 'completed' || returns.length" class="card space-y-3 p-4 text-sm">
    <div class="flex items-center justify-between gap-2">
      <h3 class="font-semibold">Retours et échanges</h3>
      <button v-if="order.status === 'completed' && auth.can('orders.manage')" class="btn-secondary" @click="start">Retour / échange</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <ul v-if="returns.length" class="divide-y divide-slate-100">
      <li v-for="r in returns" :key="r.id" class="space-y-1 py-2">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">{{ r.reference }}</span>
          <span class="rounded px-2 py-0.5 text-xs" :class="r.status === 'pending' ? 'bg-amber-100 text-amber-800' : r.status === 'received' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ r.status_label }}</span>
          <span class="text-xs text-slate-500">{{ formatDateTime(r.created_at) }}</span>
        </div>
        <p v-for="(l, i) in r.items" :key="i">{{ l.quantity }} × {{ l.label }}<template v-if="l.replacement"> → {{ l.replacement_quantity }} × {{ l.replacement }}</template></p>
        <p v-if="r.reason" class="text-slate-600">Motif : {{ r.reason }}</p>
        <p v-if="r.exchange_order">Commande d'échange : <RouterLink :to="`/gestion/commandes/${r.exchange_order.id}`" class="text-emerald-700">{{ r.exchange_order.reference }}</RouterLink> · {{ r.exchange_order.status_label }}<template v-if="r.exchange_order.amount_due"> · le client paie {{ money(r.exchange_order.amount_due) }}</template></p>
        <p v-if="r.refund_due" class="text-slate-600">À rembourser : {{ money(r.refund_due) }}<template v-if="r.refunded_amount"> · remboursé {{ money(r.refunded_amount) }}</template></p>
        <div v-if="r.status === 'pending' && auth.can('orders.manage')" class="flex gap-2 pt-1">
          <button class="btn-secondary px-3 py-1" @click="act(r, 'receive')">Articles reçus</button>
          <button class="btn-ghost px-3 py-1 text-red-600" @click="act(r, 'cancel')">Annuler</button>
        </div>
      </li>
    </ul>

    <Modal v-if="form.open" title="Retour ou échange" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="form.error">{{ form.error }}</Alert>
        <div v-for="l in form.lines" :key="l.item.id" class="space-y-2 rounded-lg border border-slate-200 p-3">
          <div class="flex items-center gap-2">
            <span class="flex-1">{{ l.item.title }}<span v-if="l.item.variant_title" class="text-slate-500"> · {{ l.item.variant_title }}</span> <span class="text-xs text-slate-500">({{ l.item.quantity }} acheté(s), {{ money(unitValue(l.item)) }} pièce)</span></span>
            <input v-model.number="l.quantity" type="number" min="0" :max="l.item.quantity" class="input w-20" aria-label="Quantité rendue" />
          </div>
          <div v-if="l.quantity > 0" class="flex flex-wrap items-center gap-2">
            <select v-model="l.action" class="input w-auto">
              <option value="refund">Rendu et remboursé</option>
              <option value="exchange" :disabled="!variants[l.item.product_id]?.length">Échangé contre…</option>
            </select>
            <select v-if="l.action === 'exchange'" v-model="l.replacement_variant_id" class="input w-auto" required>
              <option :value="null" disabled>Choisir</option>
              <option v-for="v in variants[l.item.product_id]" :key="v.id" :value="v.id">{{ v.title === 'Default' ? l.item.title : v.title }} · {{ money(v.price) }}</option>
            </select>
          </div>
        </div>

        <Field id="ret-reason" label="Motif"><input id="ret-reason" v-model="form.reason" class="input" maxlength="255" placeholder="Ex. Taille trop petite" /></Field>
        <label class="flex items-center gap-2"><input v-model="form.restock" type="checkbox" /> Remettre les articles rendus en stock à leur réception (décochez si abîmés)</label>
        <Field v-if="hasExchange" id="ret-fee" label="Frais de livraison de l'échange" hint="0 si vous offrez la livraison de l'échange.">
          <input id="ret-fee" v-model.number="form.exchange_shipping_fee" type="number" min="0" class="input" />
        </Field>

        <div v-if="chosen.length" class="rounded-lg bg-slate-50 p-3">
          <p>Articles rendus : <strong>{{ money(returnedValue) }}</strong></p>
          <p v-if="hasExchange">Articles de remplacement : <strong>{{ money(replacementValue) }}</strong></p>
          <p v-if="returnedValue > replacementValue">À rembourser au client : <strong>{{ money(returnedValue - replacementValue) }}</strong></p>
          <p v-else-if="replacementValue > returnedValue">Le client paie la différence à la livraison : <strong>{{ money(replacementValue - returnedValue) }}</strong> (+ frais de livraison)</p>
          <p v-if="hasExchange" class="mt-1 text-xs text-slate-500">Une commande d'échange est créée, déjà confirmée : confiez-la à un livreur, il reprendra l'article rendu en livrant le nouveau.</p>
        </div>
        <button class="btn-brand w-full" :disabled="busy || !chosen.length">Enregistrer</button>
      </form>
    </Modal>
  </section>
</template>
