<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import ShippingTabs from '../../../components/ShippingTabs.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const payouts = ref(null)
const open = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    payouts.value = (await http.get('/carrier-payouts')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})
</script>

<template>
  <div class="space-y-4">
    <ShippingTabs />
    <p class="text-sm text-slate-600">Reversements des transporteurs, rapprochés automatiquement de vos expéditions : encaissé par le livreur, moins le tarif annoncé.</p>
    <Alert v-if="error">{{ error }}</Alert>
    <p v-if="payouts && !payouts.length" class="card p-6 text-sm text-slate-500">Aucun reversement reçu pour l'instant.</p>
    <article v-for="p in payouts ?? []" :key="p.id" class="card text-sm">
      <button class="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left" @click="open = open === p.id ? null : p.id">
        <span class="font-medium">{{ p.reference }}</span>
        <span class="text-slate-500">{{ p.carrier }} · {{ formatDateTime(p.paid_at) }}</span>
        <span class="ml-auto font-semibold">{{ money(p.amount) }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs" :class="p.status === 'matched' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'">
          {{ p.status === 'matched' ? 'Conforme' : `Écart ${money(p.difference)}` }}
        </span>
      </button>
      <table v-if="open === p.id" class="w-full border-t border-slate-100 text-sm">
        <thead class="text-left text-xs text-slate-500"><tr><th class="px-4 py-1">Course</th><th>Commande</th><th class="text-right">Encaissé</th><th class="text-right">Frais</th><th class="text-right">Net</th><th class="px-4 text-right">Attendu</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="l in p.lines" :key="l.tracking_code" :class="{ 'bg-amber-50': l.difference }">
            <td class="px-4 py-1.5">{{ l.tracking_code }}</td>
            <td><RouterLink v-if="l.order_id" :to="`/gestion/commandes/${l.order_id}`" class="underline">{{ l.order_reference }}</RouterLink><span v-else class="text-amber-800">Inconnue</span></td>
            <td class="text-right">{{ money(l.collected) }}</td>
            <td class="text-right">{{ money(l.fees) }}</td>
            <td class="text-right">{{ money(l.net) }}</td>
            <td class="px-4 text-right">{{ l.expected_net === null ? '—' : money(l.expected_net) }}</td>
          </tr>
        </tbody>
      </table>
    </article>
  </div>
</template>
