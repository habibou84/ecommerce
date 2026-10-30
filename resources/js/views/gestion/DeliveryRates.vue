<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import DeliveryTabs from '../../components/DeliveryTabs.vue'
import ToggleSwitch from '../../components/ToggleSwitch.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

// Tarifs de livraison sans zones à construire : la ville de la boutique et son prix de départ, puis les communes
// (et quelques quartiers à part) avec leur prix, souvent retenu depuis les commandes ; les expéditions vers les
// autres villes (livraison jusqu'à la gare, paiement avant).
const rates = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const base = ref('')
const expedition = reactive({ fee: '', prepay: true })
const editing = ref(null) // id du lieu dont le prix est modifié
const draft = ref('')
const open = reactive({}) // commune → quartiers chargés
const homeOpen = ref(false)
const homeChoice = ref(null)
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
  expedition.fee = rates.value.expedition.fee ?? ''
  expedition.prepay = rates.value.expedition.prepay
  homeChoice.value = rates.value.home?.id ?? null
}
onMounted(load)

// Communes : celles avec un prix retenu d'abord, puis les autres par ordre alphabétique
const communes = computed(() => [...(rates.value?.places ?? [])].sort((a, b) => (b.learned - a.learned) || a.name.localeCompare(b.name, 'fr')))
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
const saveExpedition = () => run(() => http.put('/delivery-rates', { expedition_fee: expedition.fee === '' ? null : Number(expedition.fee), expedition_prepay: expedition.prepay }), 'Expéditions enregistrées ✓')
async function saveHome() {
  if (await run(() => http.put('/delivery-rates', { home_place_id: homeChoice.value }), 'Ville de la boutique enregistrée ✓')) {
    homeOpen.value = false
    await load()
  }
}

function edit(p) {
  editing.value = p.id
  draft.value = p.fee ?? ''
}
async function savePlace(p, parent = null) {
  if (await run(() => http.post('/delivery-rates/remember', { place_id: p.id, fee: Number(draft.value || 0) }), `Prix retenu pour ${p.name} ✓`)) {
    editing.value = null
    if (parent) await toggle(parent, true)
  }
}
async function forget(p, parent = null) {
  if (await run(() => http.delete(`/delivery-rates/places/${p.id}`), `${p.name} reprend le prix ${parent ? `de ${parent.name}` : 'de départ'}`)) {
    editing.value = null
    if (parent) await toggle(parent, true)
  }
}
async function toggle(c, reload = false) {
  if (open[c.id] && !reload) return delete open[c.id]
  open[c.id] = (await http.get(`/delivery-rates/places/${c.id}`)).data.data.places
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
      <!-- Prix de départ -->
      <section class="card space-y-3 p-4">
        <div class="flex items-center gap-3">
          <div class="min-w-0 flex-1">
            <h2 class="font-semibold">Prix de départ · {{ rates.home?.name }}</h2>
            <p class="text-sm text-slate-500">Partout dans la ville où aucun prix n'est retenu.</p>
          </div>
          <input v-model="base" class="input w-28 text-right font-bold" type="number" min="0" inputmode="numeric" aria-label="Prix de départ" />
          <span class="text-sm">F</span>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="btn-brand" :disabled="busy" @click="saveBase">Enregistrer</button>
          <button type="button" class="text-sm font-medium text-emerald-700 underline" @click="homeOpen = !homeOpen">Ma boutique n'est pas à {{ rates.home?.name }}</button>
        </div>
        <div v-if="homeOpen" class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 p-3 text-sm">
          <span>Ville de la boutique :</span>
          <select v-model="homeChoice" class="input w-auto">
            <option v-for="c in cities" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
          <button type="button" class="btn-secondary px-3 py-1.5" :disabled="busy" @click="saveHome">Enregistrer</button>
          <p class="w-full text-xs text-slate-500">Les autres villes passent alors en expédition.</p>
        </div>
      </section>

      <!-- Communes (ou quartiers) de la ville -->
      <section class="card p-4">
        <h2 class="mb-1 flex items-baseline justify-between font-semibold">{{ rates.home?.name }} <span class="text-xs font-normal text-slate-500">{{ communes.length }} lieu(x)</span></h2>
        <p v-if="!communes.length" class="text-sm text-slate-500">Aucun quartier encore : ajoutez-les depuis une commande (« Quartier pas dans la liste »).</p>
        <ul class="divide-y divide-slate-100">
          <li v-for="c in communes" :key="c.id" class="py-2">
            <div class="flex items-center gap-2">
              <button type="button" class="min-w-0 flex-1 text-left" @click="c.has_children ? toggle(c) : edit(c)">
                <span class="block font-semibold">{{ c.name }}<span v-if="c.has_children" class="ml-1 text-xs text-slate-400">{{ open[c.id] ? '▾' : '▸' }}</span></span>
                <span class="block text-xs" :class="c.learned ? 'text-emerald-700' : 'text-slate-500'">{{ c.learned ? 'retenu de vos commandes' : 'prix de départ' }}</span>
              </button>
              <template v-if="editing === c.id">
                <input v-model="draft" class="input w-24 py-1 text-right" type="number" min="0" inputmode="numeric" :aria-label="`Prix pour ${c.name}`" />
                <button type="button" class="btn-brand px-3 py-1.5" :disabled="busy" @click="savePlace(c)">OK</button>
              </template>
              <button v-else type="button" class="font-semibold tabular-nums" :class="c.learned ? '' : 'text-slate-400'" @click="edit(c)">{{ c.fee !== null ? money(c.fee) : '—' }} ✎</button>
            </div>
            <button v-if="editing === c.id && c.learned" type="button" class="mt-1 text-xs text-red-600 underline" @click="forget(c)">Retirer ce prix (revenir au prix de départ)</button>
            <!-- Quartiers de la commune : un prix à part si besoin -->
            <ul v-if="open[c.id]" class="mt-2 space-y-1 border-l-2 border-emerald-100 pl-3">
              <li v-for="q in open[c.id]" :key="q.id" class="flex items-center gap-2 text-sm">
                <span class="min-w-0 flex-1">{{ q.name }}<span v-if="q.learned" class="ml-1 text-xs text-emerald-700">· à part</span></span>
                <template v-if="editing === q.id">
                  <input v-model="draft" class="input w-24 py-1 text-right" type="number" min="0" inputmode="numeric" :aria-label="`Prix pour ${q.name}`" />
                  <button type="button" class="btn-brand px-2 py-1" :disabled="busy" @click="savePlace(q, c)">OK</button>
                  <button v-if="q.learned" type="button" class="text-xs text-red-600 underline" @click="forget(q, c)">Retirer</button>
                </template>
                <button v-else type="button" class="tabular-nums" :class="q.learned ? 'font-semibold' : 'text-slate-400'" @click="edit(q)">{{ q.fee !== null ? money(q.fee) : '—' }} ✎</button>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <!-- Expéditions vers les autres villes -->
      <section class="card space-y-3 p-4">
        <h2 class="font-semibold">📦 Expéditions vers les autres villes</h2>
        <p class="text-sm text-slate-600">Le livreur dépose le colis à la gare. Les frais de la compagnie de transport (UTB, STIF, AVS…) sont notés au dépôt.</p>
        <div class="flex items-center gap-3">
          <span class="min-w-0 flex-1 text-sm font-medium">Livraison jusqu'à la gare</span>
          <input v-model="expedition.fee" class="input w-28 text-right font-bold" type="number" min="0" inputmode="numeric" placeholder="à indiquer" aria-label="Livraison jusqu'à la gare" />
          <span class="text-sm">F</span>
        </div>
        <div class="flex items-center gap-3">
          <ToggleSwitch v-model="expedition.prepay" label="Paiement avant expédition" />
          <span class="text-sm font-medium">Paiement avant expédition (conseillé)</span>
        </div>
        <p class="text-xs text-slate-500">Le livreur ne part à la gare qu'une fois les articles et la livraison payés. Modifiable commande par commande.</p>
        <button type="button" class="btn-brand" :disabled="busy" @click="saveExpedition">Enregistrer</button>
      </section>

      <p class="text-center text-sm text-slate-500">
        Besoin de regrouper plusieurs lieux sous un même prix, ou d'un délai par zone ?
        <RouterLink to="/gestion/livraisons/zones" class="font-medium text-emerald-700 underline">Zones avancées</RouterLink>
      </p>
    </template>
  </div>
</template>
