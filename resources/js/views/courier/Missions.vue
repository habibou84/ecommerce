<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'
import { SHIPMENT_TONES } from '../../lib/shipping'

const missions = ref(null)
const me = ref(null)
const error = ref('')
let timer

async function load() {
  try {
    const [m, p] = await Promise.all([http.get('/courier/missions'), http.get('/courier/me')])
    missions.value = m.data.data
    me.value = p.data.data
    error.value = ''
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(load, 60000) // nouvelles missions
})
onUnmounted(() => clearInterval(timer))

const FINAL = ['delivered', 'returned', 'cancelled', 'lost']
const active = computed(() => (missions.value ?? []).filter((m) => !FINAL.includes(m.status)))
const done = computed(() => (missions.value ?? []).filter((m) => FINAL.includes(m.status)))
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="me" class="grid grid-cols-3 gap-2 text-center">
      <div class="rounded-xl bg-white p-3 shadow-sm"><p class="text-xl font-bold">{{ active.length }}</p><p class="text-xs text-slate-500">à faire</p></div>
      <div class="rounded-xl bg-white p-3 shadow-sm"><p class="text-xl font-bold">{{ me.today.delivered }}</p><p class="text-xs text-slate-500">livrées aujourd'hui</p></div>
      <RouterLink to="/livreur/argent" class="rounded-xl bg-white p-3 shadow-sm" :class="{ 'ring-2 ring-amber-400': me.cash_limit && me.cash_held > me.cash_limit }">
        <p class="text-base font-bold">{{ money(me.cash_held) }}</p><p class="text-xs text-slate-500">à verser</p>
      </RouterLink>
    </div>

    <p v-if="missions && !active.length" class="rounded-xl bg-white p-6 text-center text-slate-500 shadow-sm">Aucune livraison en attente. Les nouvelles missions apparaissent ici.</p>
    <RouterLink v-for="m in active" :key="m.id" :to="`/livreur/missions/${m.id}`" class="block rounded-xl bg-white p-4 shadow-sm active:bg-slate-50">
      <div class="flex items-center gap-2">
        <span class="font-semibold">{{ m.place || 'Quartier ?' }}</span>
        <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-medium" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
      </div>
      <p class="mt-1 text-sm text-slate-600"><span v-if="m.store" class="mr-1 rounded bg-sky-50 px-1.5 text-xs font-medium text-sky-800">{{ m.store }}</span>{{ m.customer_name || 'Client' }} · {{ m.reference }}</p>
      <p class="mt-1 text-sm">À encaisser : <strong>{{ money(m.cod_amount) }}</strong><span v-if="m.attempts_count" class="text-amber-700"> · {{ m.attempts_count }} tentative(s)</span></p>
      <p v-if="m.customer_response === 'unavailable'" class="mt-1 text-sm font-medium text-amber-800">⚠ Client pas disponible : appelez-le</p>
    </RouterLink>

    <template v-if="done.length">
      <h2 class="pt-2 text-sm font-semibold text-slate-500">Terminées aujourd'hui</h2>
      <RouterLink v-for="m in done" :key="m.id" :to="`/livreur/missions/${m.id}`" class="flex items-center gap-2 rounded-xl bg-white px-4 py-3 text-sm opacity-70 shadow-sm">
        <span class="flex-1"><template v-if="m.store">{{ m.store }} · </template>{{ m.place }} · {{ m.customer_name }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs" :class="SHIPMENT_TONES[m.status]">{{ m.status_label }}</span>
      </RouterLink>
    </template>
  </div>
</template>
