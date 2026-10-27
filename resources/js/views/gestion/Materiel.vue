<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

// Matériel de la plateforme : étiquettes, imprimantes, accessoires de live… commandés depuis la gestion
const auth = useAuthStore()
const data = ref(null)
const error = ref('')
const notice = ref('')
const category = ref('')
const cart = reactive({})
const EMOJI = { impression: '🖨️', live: '🎥', caisse: '🧾', consommables: '📦' }

async function load() {
  try {
    data.value = (await http.get('/materiel')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const products = computed(() => (data.value?.products ?? []).filter((p) => !category.value || p.category === category.value))
const usedCategories = computed(() => Object.entries(data.value?.categories ?? {}).filter(([key]) => (data.value?.products ?? []).some((p) => p.category === key)))
const lines = computed(() => (data.value?.products ?? []).filter((p) => cart[p.id] > 0).map((p) => ({ ...p, quantity: cart[p.id] })))
const count = computed(() => lines.value.reduce((sum, l) => sum + l.quantity, 0))
const subtotal = computed(() => lines.value.reduce((sum, l) => sum + l.price * l.quantity, 0))

function add(p, step) {
  const next = Math.max(0, (cart[p.id] ?? 0) + step)
  cart[p.id] = p.stock !== null ? Math.min(next, p.stock) : next
}

// Commande : livraison, coordonnées, paiement
const checkout = ref(false)
const form = reactive({ delivery: '', payment: '', contact_name: '', contact_phone: '', address: '', notes: '', payer_phone: '' })
const errors = ref({})
const busy = ref(false)
const delivery = computed(() => data.value?.deliveries.find((d) => d.code === form.delivery))
const total = computed(() => subtotal.value + (delivery.value?.fee ?? 0))
const placed = ref(null)

function openCheckout() {
  Object.assign(form, {
    delivery: form.delivery || data.value.deliveries[0]?.code || '',
    payment: form.payment || Object.keys(data.value.methods)[0] || '',
    contact_name: form.contact_name || auth.user?.name || '',
    contact_phone: form.contact_phone || auth.user?.phone_display || '',
  })
  errors.value = {}
  error.value = ''
  placed.value = null
  checkout.value = true
}

async function order() {
  busy.value = true
  errors.value = {}
  try {
    placed.value = (await http.post('/materiel/orders', { ...form, items: lines.value.map((l) => ({ id: l.id, quantity: l.quantity })) })).data.data
    Object.keys(cart).forEach((k) => delete cart[k])
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function claim(o) {
  try {
    const updated = (await http.post(`/materiel/orders/${o.id}/paid`)).data.data
    if (placed.value?.id === o.id) placed.value = updated
    notice.value = 'Merci ! Nous vérifions votre paiement Wave et confirmons la commande.'
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function cancel(o) {
  if (!window.confirm(`Annuler la commande ${o.number} ?`)) return
  try {
    await http.post(`/materiel/orders/${o.id}/cancel`)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

const TONE = { pending: 'bg-amber-100 text-amber-900', confirmed: 'bg-sky-100 text-sky-800', preparing: 'bg-sky-100 text-sky-800', shipping: 'bg-violet-100 text-violet-800', delivered: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-500' }
</script>

<template>
  <div v-if="data" class="space-y-5" :class="count ? 'pb-20' : ''">
    <div>
      <h2 class="text-xl font-extrabold">Matériel</h2>
      <p class="text-sm text-slate-600">Imprimantes d'étiquettes, accessoires pour vos lives, matériel de caisse… choisis pour marcher avec Jibiat Marchand.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <p v-if="!data.open" class="card px-4 py-8 text-center text-sm text-slate-500">Le matériel n'est pas proposé pour le moment. Revenez bientôt !</p>
    <template v-else>
      <div v-if="usedCategories.length > 1" class="flex gap-2 overflow-x-auto pb-1 text-sm">
        <button type="button" class="shrink-0 rounded-full px-3 py-1.5 font-semibold" :class="!category ? 'bg-emerald-700 text-white' : 'bg-white ring-1 ring-slate-200'" @click="category = ''">Tout</button>
        <button v-for="[key, label] in usedCategories" :key="key" type="button" class="shrink-0 rounded-full px-3 py-1.5 font-semibold" :class="category === key ? 'bg-emerald-700 text-white' : 'bg-white ring-1 ring-slate-200'" @click="category = key">{{ EMOJI[key] }} {{ label }}</button>
      </div>

      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="p in products" :key="p.id" class="card flex flex-col overflow-hidden">
          <img v-if="p.image" :src="p.image" :alt="p.name" class="aspect-[4/3] w-full bg-slate-50 object-cover" loading="lazy" />
          <div v-else class="grid aspect-[4/3] w-full place-items-center bg-emerald-50 text-6xl">{{ EMOJI[p.category] ?? '📦' }}</div>
          <div class="flex flex-1 flex-col gap-1 p-4">
            <p class="text-xs font-semibold text-slate-500">{{ p.category_label }}</p>
            <h3 class="font-bold leading-tight">{{ p.name }}</h3>
            <p v-if="p.description" class="line-clamp-3 text-sm text-slate-600">{{ p.description }}</p>
            <div class="mt-auto flex items-center justify-between gap-2 pt-3">
              <span class="text-lg font-extrabold">{{ money(p.price) }}</span>
              <span v-if="!p.available" class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-500">Épuisé</span>
              <button v-else-if="!cart[p.id]" type="button" class="btn-brand px-4" @click="add(p, 1)">Ajouter</button>
              <span v-else class="flex items-center gap-2">
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full ring-1 ring-slate-300" aria-label="Moins" @click="add(p, -1)">−</button>
                <b class="w-6 text-center">{{ cart[p.id] }}</b>
                <button type="button" class="grid h-9 w-9 place-items-center rounded-full ring-1 ring-slate-300" aria-label="Plus" :disabled="p.stock !== null && cart[p.id] >= p.stock" @click="add(p, 1)">+</button>
              </span>
            </div>
          </div>
        </article>
      </div>
    </template>

    <section v-if="data.orders.length" class="space-y-2">
      <h3 class="text-[17px] font-extrabold">Mes commandes de matériel</h3>
      <article v-for="o in data.orders" :key="o.id" class="card space-y-2 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <b>{{ o.number }}</b>
          <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="TONE[o.status]">{{ o.status_label }}</span>
          <span class="text-slate-500">{{ formatDateTime(o.created_at) }}</span>
          <span class="ml-auto font-extrabold">{{ money(o.total) }}</span>
        </div>
        <p class="text-slate-600">{{ o.items.map((l) => `${l.quantity} × ${l.name}`).join(', ') }} · {{ o.delivery.label }}</p>
        <p class="text-slate-600">{{ o.payment_label }} · <span :class="o.payment_status === 'paid' ? 'font-semibold text-emerald-700' : ''">{{ o.payment_status_label }}</span></p>
        <div v-if="o.wave_url || o.can_cancel" class="flex flex-wrap gap-2 pt-1">
          <a v-if="o.wave_url" :href="o.wave_url" target="_blank" rel="noopener" class="btn-brand">Payer {{ money(o.total) }} avec Wave</a>
          <button v-if="o.wave_url" type="button" class="btn-secondary" @click="claim(o)">J'ai payé</button>
          <button v-if="o.can_cancel" type="button" class="btn-ghost text-red-600" @click="cancel(o)">Annuler</button>
        </div>
      </article>
    </section>

    <!-- Panier -->
    <div v-if="count" class="fixed inset-x-0 bottom-16 z-20 px-4 lg:bottom-4 lg:left-64">
      <div class="mx-auto flex max-w-3xl items-center gap-3 rounded-2xl bg-slate-900 px-4 py-3 text-white shadow-xl">
        <span class="min-w-0 flex-1"><b>{{ count }} article{{ count > 1 ? 's' : '' }}</b> · {{ money(subtotal) }}</span>
        <button type="button" class="btn-brand" @click="openCheckout">Commander</button>
      </div>
    </div>

    <Modal v-if="checkout" :title="placed ? 'Commande envoyée' : 'Commander le matériel'" @close="checkout = false">
      <div v-if="placed" class="space-y-4 text-sm">
        <Alert tone="success">Commande {{ placed.number }} enregistrée : {{ money(placed.total) }}. Vous êtes prévenu à chaque étape.</Alert>
        <template v-if="placed.wave_url || placed.wave_qr_url">
          <p>Payez maintenant avec Wave, puis touchez « J'ai payé ».</p>
          <img v-if="placed.wave_qr_url" :src="placed.wave_qr_url" alt="QR code Wave" class="mx-auto w-48 rounded-xl" />
          <a v-if="placed.wave_url" :href="placed.wave_url" target="_blank" rel="noopener" class="btn-brand w-full justify-center">Payer {{ money(placed.total) }} avec Wave</a>
          <button type="button" class="btn-secondary w-full justify-center" :disabled="placed.payment_status !== 'unpaid'" @click="claim(placed)">{{ placed.payment_status === 'unpaid' ? 'J\'ai payé' : 'Paiement annoncé ✓' }}</button>
        </template>
        <p v-else>Vous payez à la livraison.</p>
        <button type="button" class="btn-ghost w-full justify-center" @click="checkout = false">Fermer</button>
      </div>
      <form v-else class="space-y-4 text-sm" @submit.prevent="order">
        <Alert v-if="error">{{ error }}</Alert>
        <Alert v-if="errors.items">{{ errors.items }}</Alert>
        <ul class="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200">
          <li v-for="l in lines" :key="l.id" class="flex justify-between gap-3 px-3 py-2"><span>{{ l.quantity }} × {{ l.name }}</span><span class="whitespace-nowrap">{{ money(l.price * l.quantity) }}</span></li>
        </ul>
        <fieldset class="space-y-2">
          <legend class="mb-1 font-semibold">Livraison</legend>
          <label v-for="d in data.deliveries" :key="d.code" class="flex items-start gap-3 rounded-xl p-3 ring-1" :class="form.delivery === d.code ? 'ring-emerald-600' : 'ring-slate-200'">
            <input v-model="form.delivery" type="radio" :value="d.code" class="mt-1 accent-emerald-700" />
            <span class="flex-1"><b>{{ d.label }}</b><span v-if="d.note" class="block text-slate-500">{{ d.note }}</span></span>
            <span class="font-semibold">{{ d.fee ? money(d.fee) : 'Gratuit' }}</span>
          </label>
        </fieldset>
        <Field v-if="delivery?.address" id="m-address" label="Adresse de livraison" :error="errors.address"><input id="m-address" v-model="form.address" class="input" placeholder="Commune, quartier, repère" required /></Field>
        <div class="grid gap-3 sm:grid-cols-2">
          <Field id="m-name" label="Nom" :error="errors.contact_name"><input id="m-name" v-model="form.contact_name" class="input" required /></Field>
          <Field id="m-phone" label="Téléphone" :error="errors.contact_phone"><input id="m-phone" v-model="form.contact_phone" class="input" inputmode="tel" required /></Field>
        </div>
        <fieldset class="space-y-2">
          <legend class="mb-1 font-semibold">Paiement</legend>
          <label v-for="(label, key) in data.methods" :key="key" class="flex items-center gap-3 rounded-xl p-3 ring-1" :class="form.payment === key ? 'ring-emerald-600' : 'ring-slate-200'">
            <input v-model="form.payment" type="radio" :value="key" class="accent-emerald-700" /><b>{{ label }}</b>
          </label>
        </fieldset>
        <Field v-if="form.payment === 'wave'" id="m-payer" label="Numéro Wave qui va payer" :error="errors.payer_phone"><input id="m-payer" v-model="form.payer_phone" class="input" inputmode="tel" required /></Field>
        <Field id="m-notes" label="Remarque (facultatif)"><input id="m-notes" v-model="form.notes" class="input" maxlength="500" /></Field>
        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 font-semibold"><span>Total</span><span class="text-lg font-extrabold">{{ money(total) }}</span></div>
        <button class="btn-brand w-full justify-center" :disabled="busy">Commander · {{ money(total) }}</button>
      </form>
    </Modal>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
