<script setup>
import Icon from '../../components/Icon.vue'
import { computed, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import ProductPicker from '../../components/ProductPicker.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { money } from '../../lib/format'
import { PAYMENT_METHODS } from '../../lib/orders'

// Vente au comptoir : articles, client facultatif, encaissement, monnaie à rendre
const METHODS = PAYMENT_METHODS.filter((m) => !['bank', 'other'].includes(m.value)).concat([{ value: 'card', label: 'Carte' }])

const lines = ref([])
const customer = reactive({ open: false, name: '', phone: '' })
const pay = reactive({ method: 'cash', received: null, second: false, secondMethod: 'wave', secondAmount: 0 })
const busy = ref(false)
const error = ref('')
const errors = ref({})
const done = ref(null)
const share = reactive({ phone: '', error: '', busy: false })

const total = computed(() => lines.value.reduce((sum, l) => sum + l.unit_price * l.quantity, 0))
// Encaissé avec le moyen principal : pour les espèces, le montant reçu au-delà du reste est rendu
const secondPart = computed(() => (pay.second ? Math.min(Math.max(0, pay.secondAmount || 0), total.value) : 0))
const firstDue = computed(() => total.value - secondPart.value)
const firstReceived = computed(() => (pay.received === null || pay.received === '' ? firstDue.value : Number(pay.received)))
const firstPaid = computed(() => Math.min(firstReceived.value, firstDue.value))
const change = computed(() => (pay.method === 'cash' ? Math.max(0, firstReceived.value - firstDue.value) : 0))
const remaining = computed(() => total.value - firstPaid.value - secondPart.value)

function add(item) {
  const line = lines.value.find((l) => l.variant_id === item.variant_id)
  if (line) line.quantity++
  else lines.value.push(item)
}

function step(line, delta) {
  line.quantity += delta
  if (line.quantity <= 0) lines.value = lines.value.filter((l) => l !== line)
}

async function sell() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const payments = [{ method: pay.method, amount: firstPaid.value }]
    if (secondPart.value) payments.push({ method: pay.secondMethod, amount: secondPart.value })
    const { data } = await http.post('/counter-sales', {
      items: lines.value.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity })),
      payments,
      customer_name: customer.name || null,
      customer_phone: customer.phone || null,
    })
    done.value = { ...data.data, change: change.value }
  } catch (e) {
    error.value = errorMessage(e)
    errors.value = fieldErrors(e)
    if (errors.value.customer_phone) customer.open = true
  } finally {
    busy.value = false
  }
}

// Ticket par WhatsApp à un numéro saisi maintenant (client anonyme). L'onglet est ouvert avant l'appel pour ne pas être bloqué.
async function sendWhatsapp() {
  share.error = ''
  share.busy = true
  const tab = window.open('', '_blank')
  try {
    const { data } = await http.post(`/counter-sales/${done.value.id}/whatsapp`, { phone: share.phone })
    tab ? (tab.location.href = data.data.whatsapp_url) : (window.location.href = data.data.whatsapp_url)
  } catch (e) {
    tab?.close()
    share.error = errorMessage(e)
  } finally {
    share.busy = false
  }
}

function reset() {
  lines.value = []
  Object.assign(customer, { open: false, name: '', phone: '' })
  Object.assign(pay, { method: 'cash', received: null, second: false, secondMethod: 'wave', secondAmount: 0 })
  done.value = null
  Object.assign(share, { phone: '', error: '', busy: false })
  error.value = ''
}
</script>

<template>
  <div class="mx-auto max-w-xl space-y-4">
    <RouterLink to="/gestion/caisse" class="text-sm text-slate-600 hover:text-slate-900">← Caisse</RouterLink>

    <!-- Vente enregistrée -->
    <section v-if="done" class="card space-y-4 p-6 text-center">
      <p class="text-sm text-slate-600">Vente {{ done.reference }} enregistrée</p>
      <p class="text-3xl font-semibold">{{ money(done.total) }}</p>
      <p v-if="done.change" class="rounded-lg bg-amber-50 p-3 text-lg font-semibold text-amber-900">Monnaie à rendre : {{ money(done.change) }}</p>
      <p v-if="done.amount_due" class="text-sm text-red-700">Reste à payer par le client : {{ money(done.amount_due) }}</p>
      <div class="grid grid-cols-2 gap-2">
        <a :href="`${done.receipt_url}?imprimer=1`" target="_blank" class="btn-secondary"><Icon name="receipt" :size="16" class="mr-1 inline-block align-[-3px]" />Imprimer le ticket</a>
        <a v-if="done.whatsapp_url" :href="done.whatsapp_url" target="_blank" class="btn-secondary">Ticket par WhatsApp</a>
        <RouterLink v-else :to="`/gestion/commandes/${done.id}`" class="btn-secondary">Voir la commande</RouterLink>
      </div>
      <form v-if="!done.whatsapp_url" class="space-y-1 text-left" @submit.prevent="sendWhatsapp">
        <label for="cs-share" class="text-sm text-slate-600">Envoyer le ticket par WhatsApp</label>
        <div class="flex gap-2">
          <input id="cs-share" v-model="share.phone" type="tel" inputmode="tel" class="input flex-1" placeholder="Numéro du client" required />
          <button class="btn-secondary" :disabled="share.busy">Envoyer</button>
        </div>
        <p v-if="share.error" class="text-sm text-red-700">{{ share.error }}</p>
      </form>
      <button class="btn-brand w-full py-3 text-base" @click="reset">Nouvelle vente</button>
    </section>

    <form v-else class="space-y-4" @submit.prevent="sell">
      <Alert v-if="error">{{ error }}</Alert>

      <section class="card space-y-3 p-4">
        <ProductPicker @add="add" />
        <ul v-if="lines.length" class="divide-y divide-slate-100 text-sm">
          <li v-for="l in lines" :key="l.variant_id" class="flex items-center gap-3 py-2">
            <img v-if="l.thumb" :src="l.thumb" alt="" class="h-10 w-10 rounded object-cover" />
            <div class="min-w-0 flex-1">
              <p class="truncate font-medium">{{ l.title }}<span v-if="l.variant_title" class="text-slate-500"> · {{ l.variant_title }}</span></p>
              <p class="text-xs text-slate-500">{{ money(l.unit_price) }}<template v-if="l.available !== null"> · {{ l.available }} en stock</template></p>
            </div>
            <div class="flex items-center gap-1">
              <button type="button" class="btn-secondary h-8 w-8 p-0" :aria-label="`Retirer un ${l.title}`" @click="step(l, -1)">−</button>
              <span class="w-6 text-center">{{ l.quantity }}</span>
              <button type="button" class="btn-secondary h-8 w-8 p-0" :aria-label="`Ajouter un ${l.title}`" @click="step(l, 1)">+</button>
            </div>
            <span class="w-20 text-right font-medium">{{ money(l.unit_price * l.quantity) }}</span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Cherchez un produit par son nom, son SKU ou son code-barres.</p>
        <div class="flex items-baseline justify-between border-t border-slate-100 pt-3">
          <span class="text-sm text-slate-600">Total</span>
          <span class="text-2xl font-semibold">{{ money(total) }}</span>
        </div>
      </section>

      <section v-if="lines.length" class="card space-y-3 p-4">
        <p class="text-sm font-medium">Paiement</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="m in METHODS"
            :key="m.value"
            type="button"
            class="rounded-full border px-3 py-1 text-sm"
            :class="pay.method === m.value ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200'"
            @click="pay.method = m.value"
          >
            {{ m.label }}
          </button>
        </div>
        <Field id="cs-received" :label="pay.method === 'cash' ? 'Reçu du client' : 'Montant payé'">
          <input id="cs-received" v-model.number="pay.received" type="number" min="0" class="input text-lg" :placeholder="String(firstDue)" />
        </Field>
        <p v-if="change" class="rounded-lg bg-amber-50 px-3 py-2 font-semibold text-amber-900">Monnaie à rendre : {{ money(change) }}</p>

        <label class="flex items-center gap-2 text-sm"><input v-model="pay.second" type="checkbox" /> Payé en partie avec un autre moyen</label>
        <div v-if="pay.second" class="grid grid-cols-2 gap-2">
          <select v-model="pay.secondMethod" class="input" aria-label="Second moyen de paiement">
            <option v-for="m in METHODS.filter((x) => x.value !== pay.method)" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
          <input v-model.number="pay.secondAmount" type="number" min="0" class="input" aria-label="Montant du second moyen" />
        </div>

        <p v-if="remaining > 0" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">Vente à crédit : il restera {{ money(remaining) }} à payer. Indiquez le client.</p>

        <button type="button" class="text-sm text-emerald-800 underline" @click="customer.open = !customer.open">
          {{ customer.open ? 'Sans client' : 'Ajouter le client (facultatif)' }}
        </button>
        <div v-if="customer.open || remaining > 0" class="grid gap-2 sm:grid-cols-2">
          <Field id="cs-name" label="Nom"><input id="cs-name" v-model="customer.name" class="input" maxlength="100" /></Field>
          <Field id="cs-phone" label="Téléphone" :error="errors.customer_phone?.[0]">
            <input id="cs-phone" v-model="customer.phone" class="input" type="tel" inputmode="tel" />
          </Field>
        </div>

        <button class="btn-brand w-full py-3 text-base" :disabled="busy || !lines.length">
          {{ busy ? 'Enregistrement…' : `Encaisser ${money(firstPaid + secondPart)}` }}
        </button>
      </section>
    </form>
  </div>
</template>
