<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { riskLabel } from '../../../lib/orders'

// File de confirmation : appeler ou écrire à chaque client, noter la réponse, passer au suivant
const orders = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(null)
const done = ref(0)

async function load() {
  loading.value = true
  try {
    orders.value = (await http.get('/orders/confirmation-queue')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function due(order) {
  return order.callback_at && new Date(order.callback_at) <= new Date()
}

async function answer(order, outcome, channel, extra = {}) {
  busy.value = order.id
  error.value = ''
  try {
    await http.post(`/orders/${order.id}/confirmations`, { outcome, channel, ...extra })
    if (['confirmed', 'refused', 'wrong_number'].includes(outcome)) {
      orders.value = orders.value.filter((o) => o.id !== order.id)
      done.value++
    } else {
      await load()
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = null
  }
}

function later(order, hours) {
  answer(order, 'callback', 'call', { callback_at: new Date(Date.now() + hours * 3600000).toISOString() })
}
</script>

<template>
  <div class="space-y-4">
    <p class="text-sm text-slate-600">
      Appelez ou écrivez à chaque client avant de faire livrer : c'est le meilleur moyen d'éviter les refus à la livraison.
      <span v-if="done" class="font-medium text-emerald-700">{{ done }} traitée(s) depuis l'ouverture.</span>
    </p>
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="loading && !orders.length" class="text-sm text-slate-500">Chargement…</p>
    <div v-else-if="!orders.length" class="card p-8 text-center">
      <p class="text-lg font-medium">🎉 Rien à confirmer</p>
      <p class="text-sm text-slate-500">Les nouvelles commandes apparaîtront ici.</p>
    </div>

    <article v-for="o in orders" :key="o.id" class="card space-y-3 p-4" :class="{ 'border-amber-400': due(o), 'opacity-60': busy === o.id }">
      <div class="flex flex-wrap items-start gap-2">
        <div class="min-w-0 flex-1">
          <RouterLink :to="`/gestion/commandes/${o.id}`" class="font-semibold hover:text-emerald-700">{{ o.reference }} · {{ o.customer_name || 'Sans nom' }}</RouterLink>
          <p class="text-sm text-slate-500">{{ o.customer_phone_display }} · {{ o.shipping_place_label || (o.delivery_method === 'pickup' ? 'Retrait' : 'Adresse à préciser') }} · {{ o.source_label }}</p>
        </div>
        <span class="text-lg font-semibold">{{ money(o.total) }}</span>
      </div>
      <p class="text-sm">{{ o.items.map((i) => `${i.quantity} × ${i.label}`).join(', ') }}</p>
      <div class="flex flex-wrap gap-2 text-xs">
        <span v-if="riskLabel(o.risk_score)" class="rounded-full px-2 py-0.5" :class="riskLabel(o.risk_score).tone">{{ riskLabel(o.risk_score).label }}</span>
        <span v-if="o.attempts" class="rounded-full bg-slate-100 px-2 py-0.5">{{ o.attempts }} tentative(s) · dernière : {{ o.last_attempt?.outcome_label }}</span>
        <span v-if="o.callback_at" class="rounded-full px-2 py-0.5" :class="due(o) ? 'bg-amber-100 text-amber-900' : 'bg-slate-100'">Rappel {{ formatDateTime(o.callback_at) }}</span>
      </div>
      <div class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        <a :href="`tel:${o.customer_phone}`" class="btn-secondary">📞 Appeler</a>
        <a :href="o.whatsapp" target="_blank" rel="noopener" class="btn-secondary">💬 WhatsApp</a>
        <button class="btn-brand col-span-2 sm:col-span-1" :disabled="busy === o.id" @click="answer(o, 'confirmed', 'call')">✓ Confirmée</button>
        <button class="btn-secondary" :disabled="busy === o.id" @click="answer(o, 'no_answer', 'call')">Injoignable</button>
        <button class="btn-secondary" :disabled="busy === o.id" @click="later(o, 3)">Rappeler dans 3 h</button>
        <RouterLink :to="`/gestion/commandes/${o.id}/modifier`" class="btn-ghost">Modifier</RouterLink>
        <button class="btn-ghost text-red-600" :disabled="busy === o.id" @click="answer(o, 'refused', 'call')">Refusée</button>
        <button class="btn-ghost text-red-600" :disabled="busy === o.id" @click="answer(o, 'wrong_number', 'call')">Faux numéro</button>
      </div>
    </article>
  </div>
</template>
