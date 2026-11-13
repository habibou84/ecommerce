<script setup>
import Icon from '../../../components/Icon.vue'
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ChipsInput from '../../../components/ChipsInput.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDate, formatDateTime, money } from '../../../lib/format'
import { STATUS_TONES } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const adjust = ref({ points: null, note: '', error: '' })
async function adjustPoints() {
  adjust.value.error = ''
  try {
    customer.value.loyalty = (await http.post(`/customers/${props.id}/loyalty`, { points: adjust.value.points, note: adjust.value.note || null })).data.data
    adjust.value = { points: null, note: '', error: '' }
  } catch (e) {
    adjust.value.error = errorMessage(e)
  }
}

const props = defineProps({ id: { type: String, required: true } })
const auth = useAuthStore()

const customer = ref(null)
const initial = computed(() => (customer.value?.name || '#').trim().charAt(0).toUpperCase())
const form = ref(null)
const error = ref('')
const saved = ref(false)

async function load() {
  customer.value = (await http.get(`/customers/${props.id}`)).data.data
  form.value = { name: customer.value.name ?? '', phone2: customer.value.phone2 ?? '', email: customer.value.email ?? '', note: customer.value.note ?? '', accepts_marketing: !!customer.value.accepts_marketing, tags: [...customer.value.tags] }
}
onMounted(load)

async function save() {
  error.value = ''
  saved.value = false
  try {
    customer.value = (await http.put(`/customers/${props.id}`, { ...form.value, phone2: form.value.phone2 || null, email: form.value.email || null })).data.data
    saved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Liste noire : le motif se saisit dans une fenêtre (plus de boîte du navigateur)
const blacklisting = ref(false)
const reason = ref('')
const reasonError = ref('')
async function toggleBlacklist() {
  if (customer.value.is_blacklisted) {
    customer.value = (await http.delete(`/customers/${props.id}/blacklist`)).data.data
    return
  }
  reason.value = ''
  reasonError.value = ''
  blacklisting.value = true
}
async function confirmBlacklist() {
  reasonError.value = ''
  try {
    customer.value = (await http.post(`/customers/${props.id}/blacklist`, { reason: reason.value })).data.data
    blacklisting.value = false
  } catch (e) {
    reasonError.value = errorMessage(e)
  }
}

// Onglets de la fiche : commandes d'abord, l'édition après le résumé
const tab = ref('orders')
const tabs = computed(() => [
  { key: 'orders', label: `Commandes${customer.value?.orders_total ? ` (${customer.value.orders_total})` : ''}` },
  { key: 'info', label: 'Infos' },
  ...(customer.value?.loyalty ? [{ key: 'loyalty', label: 'Fidélité' }] : []),
])

// Relancer : message du marchand préparé par le serveur, ouvert dans WhatsApp
const relaunching = ref(false)
async function relaunch() {
  relaunching.value = true
  const w = window.open('', '_blank')
  try {
    const url = (await http.get(`/customers/${props.id}/relaunch`)).data.data.whatsapp_url
    if (w) w.location.href = url
    else window.location.assign(url)
  } catch (e) {
    w?.close()
    error.value = errorMessage(e)
  } finally {
    relaunching.value = false
  }
}
</script>

<template>
  <div v-if="customer" class="space-y-4">
    <RouterLink to="/gestion/clients" class="text-sm text-slate-500 hover:text-emerald-700">← Clients</RouterLink>

    <!-- Résumé : qui c'est, et les gestes de tous les jours -->
    <div class="card space-y-3 p-4">
      <div class="flex items-center gap-3">
        <span class="grid h-14 w-14 shrink-0 place-items-center rounded-full text-xl font-black" :class="customer.is_blacklisted ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'">{{ initial }}</span>
        <div class="min-w-0 flex-1">
          <h2 class="truncate text-xl font-bold">{{ customer.name || 'Sans nom' }}</h2>
          <p class="text-sm text-slate-500">{{ customer.phone_display }}</p>
          <div class="mt-1 flex flex-wrap gap-1 empty:hidden">
            <span v-if="customer.is_blacklisted" class="rounded-full bg-red-100 px-2 text-[11px] font-extrabold text-red-700">Liste noire</span>
            <span v-else-if="customer.is_risky" class="rounded-full bg-amber-100 px-2 text-[11px] font-extrabold text-amber-800">⚠ {{ customer.refused_count }} refus</span>
            <span v-if="customer.is_loyal" class="rounded-full bg-emerald-100 px-2 text-[11px] font-extrabold text-emerald-800">⭐ Fidèle</span>
            <span v-for="t in customer.tags" :key="t" class="rounded-full bg-slate-100 px-2 text-[11px] font-bold text-slate-600">{{ t }}</span>
          </div>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <RouterLink v-if="auth.can('orders.manage') && !customer.is_blacklisted" :to="`/gestion/commandes/nouvelle?client=${customer.id}`" class="btn-brand justify-center font-black">＋ Commande</RouterLink>
        <a :href="`tel:${customer.phone}`" class="btn-secondary justify-center"><Icon name="phone" :size="16" class="mr-1 inline-block align-[-3px]" />Appeler</a>
        <a :href="customer.whatsapp_url" target="_blank" rel="noopener" class="btn-secondary justify-center">💬 WhatsApp</a>
        <button v-if="!customer.is_blacklisted" type="button" class="btn-secondary justify-center" :disabled="relaunching" @click="relaunch">⏰ Relancer</button>
      </div>
    </div>
    <Alert v-if="customer.is_blacklisted" tone="error">Sur liste noire : {{ customer.blacklist_reason }}</Alert>
    <Alert v-else-if="customer.is_risky" tone="warning">{{ customer.refused_count }} commande(s) refusée(s) sur {{ customer.delivered_count + customer.refused_count }} : demandez un acompte ou une confirmation avant d'expédier.</Alert>
    <Alert v-if="error">{{ error }}</Alert>

    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
      <div class="card p-3"><p class="text-xs text-slate-500">Commandes</p><p class="text-lg font-semibold">{{ customer.orders_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Livrées</p><p class="text-lg font-semibold">{{ customer.delivered_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Refus</p><p class="text-lg font-semibold" :class="customer.refused_count ? 'text-red-600' : ''">{{ customer.refused_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Dépensé</p><p class="text-lg font-semibold">{{ money(customer.total_spent) }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Panier moyen</p><p class="text-lg font-semibold">{{ customer.average_basket !== null ? money(customer.average_basket) : '—' }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Client depuis</p><p class="text-lg font-semibold">{{ customer.first_order_at ? formatDate(customer.first_order_at) : '—' }}</p></div>
    </div>

    <!-- Onglets -->
    <div class="flex gap-2 overflow-x-auto [scrollbar-width:none]" role="tablist">
      <button v-for="t in tabs" :key="t.key" type="button" role="tab" :aria-selected="tab === t.key" class="shrink-0 rounded-full px-3.5 py-1.5 text-[13.5px] font-extrabold"
              :class="tab === t.key ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 shadow-soft'" @click="tab = t.key">{{ t.label }}</button>
    </div>

    <!-- Commandes -->
    <section v-if="tab === 'orders'" class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="o in customer.orders" :key="o.id">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-slate-50">
            <span class="w-16 font-medium">{{ o.reference }}</span>
            <span class="min-w-0 flex-1 truncate text-slate-500">{{ formatDateTime(o.created_at) }} · {{ o.source_label }}</span>
            <span>{{ money(o.total) }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS_TONES[o.status]">{{ o.status_label }}</span>
          </RouterLink>
        </li>
        <li v-if="!customer.orders.length" class="px-4 py-6 text-center text-sm text-slate-500">Pas encore de commande.</li>
      </ul>
      <p v-if="customer.orders_total > customer.orders.length" class="border-t border-slate-100 px-4 py-2 text-center text-xs text-slate-500">Les {{ customer.orders.length }} dernières commandes sur {{ customer.orders_total }}.</p>
    </section>

    <!-- Infos : fiche modifiable, adresses, autres boutiques -->
    <div v-else-if="tab === 'info'" class="grid gap-4 lg:grid-cols-2">
      <form class="card space-y-3 p-4" @submit.prevent="save">
        <h3 class="font-semibold">Fiche</h3>
        <Alert v-if="saved" tone="success">Enregistré.</Alert>
        <fieldset :disabled="!auth.can('orders.manage')" class="space-y-3">
          <Field id="c-name" label="Nom"><input id="c-name" v-model="form.name" class="input" /></Field>
          <Field id="c-phone2" label="Autre numéro"><input id="c-phone2" v-model="form.phone2" class="input" type="tel" /></Field>
          <Field id="c-email" label="E-mail"><input id="c-email" v-model="form.email" class="input" type="email" /></Field>
          <Field id="c-tags" label="Étiquettes"><ChipsInput id="c-tags" v-model="form.tags" placeholder="VIP, revendeur…" /></Field>
          <Field id="c-note" label="Note"><textarea id="c-note" v-model="form.note" class="input" rows="2" /></Field>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="form.accepts_marketing" type="checkbox" class="mt-0.5" />
            <span>Accepte de recevoir vos nouveautés et offres sur WhatsApp<span v-if="customer.marketing_opt_out_at" class="block text-xs text-amber-700">A répondu STOP le {{ new Date(customer.marketing_opt_out_at).toLocaleDateString('fr-FR') }} : ne cochez que s'il vous a redonné son accord.</span></span>
          </label>
        </fieldset>
        <div v-if="auth.can('orders.manage')" class="flex gap-2">
          <button type="button" class="btn-ghost text-red-600" @click="toggleBlacklist">{{ customer.is_blacklisted ? 'Retirer de la liste noire' : 'Liste noire' }}</button>
          <button class="btn-brand ml-auto">Enregistrer</button>
        </div>
      </form>

      <div class="space-y-4">
        <section v-if="customer.addresses.length" class="card p-4 text-sm">
          <h3 class="mb-2 font-semibold">Adresses</h3>
          <ul class="space-y-2">
            <li v-for="a in customer.addresses" :key="a.id">
              {{ a.place_label }}<span v-if="a.is_default" class="text-xs text-emerald-700"> · habituelle</span>
              <span class="block text-slate-500">{{ a.address_line }}{{ a.landmark ? ` · ${a.landmark}` : '' }}</span>
            </li>
          </ul>
        </section>
        <section v-if="customer.other_stores?.length" class="card p-4 text-sm">
          <h3 class="mb-2 font-semibold">Dans vos autres boutiques</h3>
          <ul class="space-y-3">
            <li v-for="o in customer.other_stores" :key="o.store_id">
              <span class="font-medium">{{ o.store_name }}</span>
              <span class="block text-slate-500">
                {{ o.orders_count }} commande(s), {{ o.delivered_count }} livrée(s)<template v-if="o.refused_count">, {{ o.refused_count }} refusée(s)</template> · {{ money(o.total_spent) }}
              </span>
              <span v-if="o.is_blacklisted" class="block text-red-600">Sur liste noire{{ o.blacklist_reason ? ` : ${o.blacklist_reason}` : '' }}</span>
              <button class="text-emerald-700 hover:underline" @click="auth.switchTo(o.store_id, `/gestion/clients/${o.customer_id}`)">Voir sa fiche →</button>
            </li>
          </ul>
        </section>
        <p v-if="!customer.addresses.length && !customer.other_stores?.length" class="card p-4 text-sm text-slate-500">Pas encore d'adresse enregistrée.</p>
      </div>
    </div>

    <!-- Fidélité -->
    <section v-else-if="tab === 'loyalty' && customer.loyalty" class="card space-y-2 p-4 text-sm">
      <h3 class="font-semibold">Fidélité</h3>
      <p><strong class="text-lg">{{ customer.loyalty.points }}</strong> point(s) · {{ money(customer.loyalty.value) }} de réduction possible</p>
      <p v-if="customer.loyalty.referral_code">Code de parrainage : <strong class="font-mono">{{ customer.loyalty.referral_code }}</strong> · {{ customer.loyalty.referrals }} filleul(s)</p>
      <form v-if="auth.can('orders.manage')" class="flex gap-2" @submit.prevent="adjustPoints">
        <input v-model.number="adjust.points" type="number" class="input w-24 py-1" placeholder="± pts" required />
        <input v-model="adjust.note" class="input py-1" placeholder="Motif" maxlength="255" />
        <button class="btn-secondary px-3 py-1">Ajuster</button>
      </form>
      <p v-if="adjust.error" class="text-xs text-red-600">{{ adjust.error }}</p>
      <ul v-if="customer.loyalty.history.length" class="divide-y divide-slate-100 text-xs">
        <li v-for="(h, i) in customer.loyalty.history" :key="i" class="flex justify-between py-1">
          <span>{{ new Date(h.created_at).toLocaleDateString('fr-FR') }} · {{ h.label }}<template v-if="h.note"> · {{ h.note }}</template></span>
          <span :class="h.points < 0 ? 'text-red-700' : 'text-emerald-700'">{{ h.points > 0 ? '+' : '' }}{{ h.points }}</span>
        </li>
      </ul>
    </section>

    <Modal v-if="blacklisting" title="Mettre sur liste noire" @close="blacklisting = false">
      <form class="space-y-3" @submit.prevent="confirmBlacklist">
        <Alert v-if="reasonError">{{ reasonError }}</Alert>
        <Field id="c-reason" label="Pourquoi ?"><textarea id="c-reason" v-model="reason" class="input" rows="3" maxlength="255" required placeholder="Ex. trois refus à la livraison" /></Field>
        <div class="flex gap-2">
          <button type="button" class="btn-secondary flex-1 justify-center" @click="blacklisting = false">Annuler</button>
          <button class="btn-brand flex-1 justify-center bg-red-600">Mettre sur liste noire</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
