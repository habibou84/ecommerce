<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Pagination from '../../../components/Pagination.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Retours et échanges de toutes les commandes : ceux dont l'article est encore à récupérer d'abord
const returns = ref([])
const meta = ref(null)
const counts = ref({})
const status = ref('pending')
const error = ref('')

async function load(page = 1) {
  try {
    const { data } = await http.get('/returns', { params: { status: status.value || undefined, page } })
    returns.value = data.data
    meta.value = data.meta
    counts.value = data.counts
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const FILTERS = [{ value: 'pending', label: 'À récupérer' }, { value: 'received', label: 'Reçus' }, { value: '', label: 'Tous' }]
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center gap-2">
      <RouterLink to="/gestion/commandes" class="text-sm text-slate-500">← Commandes</RouterLink>
    </div>
    <p class="text-sm text-slate-600">Un retour ou un échange se crée depuis la commande livrée (bouton « Retour / échange »).</p>
    <div class="flex flex-wrap gap-1">
      <button v-for="f in FILTERS" :key="f.value" class="rounded-full px-3 py-1 text-sm" :class="status === f.value ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'" @click="status = f.value; load()">
        {{ f.label }}<template v-if="f.value === 'pending' && counts.pending"> ({{ counts.pending }})</template>
      </button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <ul class="space-y-3">
      <li v-for="r in returns" :key="r.id" class="card space-y-1 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <RouterLink :to="`/gestion/commandes/${r.order_id}`" class="font-semibold text-emerald-800">{{ r.reference }} · {{ r.order_reference }}</RouterLink>
          <span>{{ r.customer_name }}</span>
          <span class="rounded px-2 py-0.5 text-xs" :class="r.status === 'pending' ? 'bg-amber-100 text-amber-800' : r.status === 'received' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ r.status_label }}</span>
          <span class="ml-auto text-xs text-slate-500">{{ formatDateTime(r.created_at) }}</span>
        </div>
        <p v-for="(l, i) in r.items" :key="i">{{ l.quantity }} × {{ l.label }}<template v-if="l.replacement"> → {{ l.replacement_quantity }} × {{ l.replacement }}</template></p>
        <p v-if="r.exchange_order" class="text-slate-600">Échange : {{ r.exchange_order.reference }} · {{ r.exchange_order.status_label }}</p>
        <p v-if="r.refund_due" class="text-slate-600">À rembourser : {{ money(r.refund_due) }}</p>
      </li>
      <li v-if="!returns.length" class="text-sm text-slate-500">Aucun retour.</li>
    </ul>
    <Pagination :meta="meta" @page="load" />
  </div>
</template>
