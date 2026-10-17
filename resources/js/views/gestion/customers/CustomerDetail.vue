<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ChipsInput from '../../../components/ChipsInput.vue'
import Field from '../../../components/Field.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { STATUS_TONES } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const props = defineProps({ id: { type: String, required: true } })
const auth = useAuthStore()

const customer = ref(null)
const form = ref(null)
const error = ref('')
const saved = ref(false)

async function load() {
  customer.value = (await http.get(`/customers/${props.id}`)).data.data
  form.value = { name: customer.value.name ?? '', phone2: customer.value.phone2 ?? '', email: customer.value.email ?? '', note: customer.value.note ?? '', tags: [...customer.value.tags] }
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

async function toggleBlacklist() {
  if (customer.value.is_blacklisted) {
    customer.value = (await http.delete(`/customers/${props.id}/blacklist`)).data.data
    return
  }
  const reason = prompt('Pourquoi mettre ce client sur liste noire ?')
  if (reason) customer.value = (await http.post(`/customers/${props.id}/blacklist`, { reason })).data.data
}
</script>

<template>
  <div v-if="customer" class="space-y-5">
    <RouterLink to="/gestion/clients" class="text-sm text-slate-500 hover:text-emerald-700">← Clients</RouterLink>
    <div class="flex flex-wrap items-center gap-3">
      <h2 class="text-2xl font-bold">{{ customer.name || 'Sans nom' }}</h2>
      <span v-if="customer.is_blacklisted" class="rounded-full bg-red-100 px-2.5 py-0.5 text-sm text-red-700">Liste noire</span>
      <div class="ml-auto flex gap-2">
        <a :href="`tel:${customer.phone}`" class="btn-secondary">📞 {{ customer.phone_display }}</a>
        <a :href="customer.whatsapp_url" target="_blank" class="btn-secondary">WhatsApp</a>
      </div>
    </div>
    <Alert v-if="customer.is_blacklisted" tone="error">{{ customer.blacklist_reason }}</Alert>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
      <div class="card p-3"><p class="text-xs text-slate-500">Commandes</p><p class="text-xl font-semibold">{{ customer.orders_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Livrées</p><p class="text-xl font-semibold">{{ customer.delivered_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Refus</p><p class="text-xl font-semibold" :class="customer.refused_count ? 'text-red-600' : ''">{{ customer.refused_count }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Dépensé</p><p class="text-xl font-semibold">{{ money(customer.total_spent) }}</p></div>
    </div>

    <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
      <section class="card">
        <h3 class="border-b border-slate-100 px-4 py-3 font-semibold">Commandes</h3>
        <ul class="divide-y divide-slate-100">
          <li v-for="o in customer.orders" :key="o.id">
            <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-slate-50">
              <span class="w-16 font-medium">{{ o.reference }}</span>
              <span class="flex-1 text-slate-500">{{ formatDateTime(o.created_at) }} · {{ o.source_label }}</span>
              <span>{{ money(o.total) }}</span>
              <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS_TONES[o.status]">{{ o.status_label }}</span>
            </RouterLink>
          </li>
        </ul>
      </section>

      <aside class="space-y-4">
        <form class="card space-y-3 p-4" @submit.prevent="save">
          <h3 class="font-semibold">Fiche</h3>
          <Alert v-if="error">{{ error }}</Alert>
          <Alert v-if="saved" tone="success">Enregistré.</Alert>
          <fieldset :disabled="!auth.can('orders.manage')" class="space-y-3">
            <Field id="c-name" label="Nom"><input id="c-name" v-model="form.name" class="input" /></Field>
            <Field id="c-phone2" label="Autre numéro"><input id="c-phone2" v-model="form.phone2" class="input" type="tel" /></Field>
            <Field id="c-email" label="E-mail"><input id="c-email" v-model="form.email" class="input" type="email" /></Field>
            <Field id="c-tags" label="Étiquettes"><ChipsInput id="c-tags" v-model="form.tags" placeholder="VIP, revendeur…" /></Field>
            <Field id="c-note" label="Note"><textarea id="c-note" v-model="form.note" class="input" rows="2" /></Field>
          </fieldset>
          <div v-if="auth.can('orders.manage')" class="flex gap-2">
            <button type="button" class="btn-ghost text-red-600" @click="toggleBlacklist">{{ customer.is_blacklisted ? 'Retirer de la liste noire' : 'Liste noire' }}</button>
            <button class="btn-brand ml-auto">Enregistrer</button>
          </div>
        </form>

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

        <section v-if="customer.addresses.length" class="card p-4 text-sm">
          <h3 class="mb-2 font-semibold">Adresses</h3>
          <ul class="space-y-2">
            <li v-for="a in customer.addresses" :key="a.id">
              {{ a.place_label }}<span v-if="a.is_default" class="text-xs text-emerald-700"> · habituelle</span>
              <span class="block text-slate-500">{{ a.address_line }}{{ a.landmark ? ` · ${a.landmark}` : '' }}</span>
            </li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>
