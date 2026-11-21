<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

// Livreur de Jibiat chez le marchand avec des colis retournés : « Tout reçu » ou « Il en manque ».
// Le stock revient seulement quand l'entreprise passe les commandes « Retournée », donc après « Tout reçu ».
const auth = useAuthStore()
const handovers = ref([])
const busy = ref(null)
const error = ref('')
const missing = ref(null) // remise pour laquelle on demande « Il en manque ? »

async function load() {
  if (!auth.can('shipments.manage') || document.hidden) return
  try {
    handovers.value = (await http.get('/return-handovers')).data.data
  } catch {
    // Silencieux : le bandeau n'est qu'un raccourci
  }
}
onMounted(load)
const onVisible = () => { if (!document.hidden) load() }
document.addEventListener('visibilitychange', onVisible)
const timer = setInterval(load, 60000)
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('visibilitychange', onVisible) })

async function answer(h, allReceived) {
  busy.value = h.id
  error.value = ''
  try {
    const updated = (await http.post(`/return-handovers/${h.id}/${allReceived ? 'confirm' : 'dispute'}`)).data.data
    handovers.value = handovers.value.map((x) => (x.id === h.id ? updated : x))
    missing.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = null
  }
}

const OPEN = ['pending', 'expired', 'unconfirmed']
const plural = (n) => (n > 1 ? `${n} colis` : 'un colis')
</script>

<template>
  <div v-if="handovers.length" class="space-y-3">
    <section v-for="h in handovers" :key="h.id" class="rounded-2xl border p-4" :class="OPEN.includes(h.status) ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white'">
      <template v-if="OPEN.includes(h.status)">
        <p class="text-xs font-bold uppercase tracking-wide text-amber-800">Retour {{ h.reference }} · {{ h.carrier }}</p>
        <h3 class="mt-1 font-bold text-slate-900">
          <template v-if="h.status === 'unconfirmed'">Colis laissés à {{ h.received_by_name || 'votre équipe' }}</template>
          <template v-else>{{ h.courier_name || 'Le livreur' }} vous rapporte {{ plural(h.parcels.length) }}</template>
        </h3>
        <ul class="mt-2 space-y-1 text-sm text-slate-700">
          <li v-for="p in h.parcels" :key="p.tracking_code" class="flex flex-wrap gap-x-2">
            <RouterLink v-if="p.order_id" :to="`/gestion/commandes/${p.order_id}`" class="font-semibold underline">{{ p.order_reference }}</RouterLink>
            <span v-else class="font-semibold">{{ p.tracking_code }}</span>
            <span>{{ p.customer_name || p.recipient_name }}</span>
          </li>
        </ul>
        <p class="mt-2 text-xs text-slate-600">Comptez les colis. Le stock revient après « Tout reçu ».</p>
        <p v-if="error && busy === null" class="mt-2 text-sm text-red-700">{{ error }}</p>
        <div v-if="missing === h.id" class="mt-3 rounded-xl bg-white p-3 text-sm">
          <p>Les colis restent chez le livreur et l'agence vérifie. Confirmer qu'il en manque ?</p>
          <div class="mt-2 flex gap-2">
            <button type="button" class="btn-secondary flex-1" :disabled="busy === h.id" @click="missing = null">Retour</button>
            <button type="button" class="btn-danger flex-1" :disabled="busy === h.id" @click="answer(h, false)">Il en manque</button>
          </div>
        </div>
        <div v-else-if="auth.can('shipments.manage')" class="mt-3 flex gap-2">
          <button type="button" class="btn-secondary flex-1" :disabled="busy === h.id" @click="missing = h.id">Il en manque</button>
          <button type="button" class="btn-primary flex-1" :disabled="busy === h.id" @click="answer(h, true)">{{ busy === h.id ? '…' : 'Tout reçu' }}</button>
        </div>
      </template>
      <p v-else-if="h.status === 'confirmed'" class="text-sm text-emerald-800">✓ Retour {{ h.reference }} confirmé : {{ plural(h.parcels.length) }} reçu{{ h.parcels.length > 1 ? 's' : '' }}, le stock est mis à jour.</p>
      <p v-else-if="h.status === 'disputed'" class="text-sm text-slate-700">Retour {{ h.reference }} : {{ h.carrier }} vérifie les colis manquants.</p>
    </section>
  </div>
</template>
