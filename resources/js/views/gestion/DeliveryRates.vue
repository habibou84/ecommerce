<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import DeliveryPrices from '../../components/DeliveryPrices.vue'
import DeliveryTabs from '../../components/DeliveryTabs.vue'
import http, { errorMessage } from '../../lib/http'

// Tarifs de livraison : les prix à toucher (ville, communes, quartiers à part, expéditions vers les autres villes,
// livraison offerte), la ville de la boutique, et la question du guide de démarrage.
const rates = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const base = ref('')
const homeOpen = ref(false)
const homeChoice = ref(null)
const pricesKey = ref(0)
// Venue du guide de démarrage : une seule question, le prix habituel dans la ville
const route = useRoute()
const router = useRouter()
const start = ref(route.query.depart === '1')
const QUICK = [1000, 1500, 2000, 2500]
async function saveStart() {
  if (await saveBase()) router.push('/gestion/demarrage')
}

async function load() {
  rates.value = (await http.get('/delivery-rates')).data.data
  base.value = rates.value.base_fee ?? ''
  homeChoice.value = rates.value.home?.id ?? null
}
onMounted(load)

const cities = computed(() => [...(rates.value?.home ? [rates.value.home] : []), ...(rates.value?.cities ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'fr')))

async function run(action, message) {
  busy.value = true
  error.value = ''
  try {
    rates.value = (await action()).data.data
    notice.value = message
    setTimeout(() => (notice.value = ''), 3000)
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

const saveBase = () => run(() => http.put('/delivery-rates', { base_fee: Number(base.value || 0) }), 'Prix de départ enregistré ✓')
async function saveHome() {
  if (await run(() => http.put('/delivery-rates', { home_place_id: homeChoice.value }), 'Ville de la boutique enregistrée ✓')) {
    homeOpen.value = false
    await load()
    pricesKey.value++
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <DeliveryTabs />
    <div>
      <h1 class="text-xl font-extrabold">Tarifs de livraison</h1>
      <p class="text-sm text-slate-600">Rien à remplir d'avance : quand vous changez un prix dans une commande, l'application vous propose de le retenir pour la commune ou le quartier.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <!-- Question du guide de démarrage -->
    <section v-if="start && rates" class="card space-y-4 border-emerald-300 p-5">
      <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Livraison</p>
      <h2 class="text-2xl font-extrabold leading-tight">Combien faites-vous payer la livraison dans {{ rates.home?.name }} ?</h2>
      <p class="text-slate-600">Votre prix habituel. Vous l'ajusterez commune par commune au fil des commandes, sans revenir ici.</p>
      <div class="flex items-center justify-center gap-2 rounded-2xl border-2 border-emerald-600 bg-white p-4">
        <input v-model="base" class="w-40 bg-transparent text-center text-4xl font-black outline-none" type="number" min="0" inputmode="numeric" aria-label="Prix de livraison habituel" />
        <span class="text-lg font-bold text-slate-500">F CFA</span>
      </div>
      <div class="grid grid-cols-4 gap-2">
        <button v-for="q in QUICK" :key="q" type="button" class="rounded-xl border py-2 font-bold" :class="Number(base) === q ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200'" @click="base = q">{{ q.toLocaleString('fr-FR') }}</button>
      </div>
      <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-700">💡 Hors de {{ rates.home?.name }} (Bouaké, Yamoussoukro…), ce sont des expéditions : la livraison jusqu'à la gare se règle plus bas.</p>
      <button type="button" class="btn-brand w-full" :disabled="busy" @click="saveStart">Continuer</button>
      <button type="button" class="block w-full text-center text-sm text-slate-500 underline" @click="start = false">Voir tous les tarifs</button>
    </section>

    <template v-if="rates && !start">
      <!-- Prix à toucher : ville, communes, quartiers, expéditions, livraison offerte (comme dans « Préparer un live ») -->
      <DeliveryPrices :key="pricesKey" />

      <!-- Ville de la boutique -->
      <section class="card space-y-2 p-4 text-sm">
        <button type="button" class="font-medium text-emerald-700 underline" @click="homeOpen = !homeOpen">Ma boutique n'est pas à {{ rates.home?.name }}</button>
        <div v-if="homeOpen" class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-3">
          <span>Ville de la boutique :</span>
          <select v-model="homeChoice" class="input w-auto">
            <option v-for="c in cities" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <button type="button" class="btn-secondary px-3 py-1.5" :disabled="busy" @click="saveHome">Enregistrer</button>
          <p class="w-full text-xs text-slate-500">Les autres villes passent alors en expédition.</p>
        </div>
      </section>

      <p class="text-center text-sm text-slate-500">
        Besoin de regrouper plusieurs lieux sous un même prix, ou d'un délai par zone ?
        <RouterLink to="/gestion/livraisons/zones" class="font-medium text-emerald-700 underline">Zones avancées</RouterLink>
      </p>
    </template>
  </div>
</template>
