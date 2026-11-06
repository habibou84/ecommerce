<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

/**
 * « Préparer un live » › Prix de livraison : ce que paie la cliente, ajouté à sa commande. Le prix de base de la ville,
 * les communes à prix différent et la livraison offerte. La marchande fixe ses prix ; l'entreprise de livraison n'y
 * change rien (son prix reste le coût de la boutique).
 */
const emit = defineEmits(['close', 'done'])

const rates = ref(null)
const error = ref('')
const busy = ref(false)
const base = ref('')
const free = ref('')
const adding = ref(false)
const pick = ref('')
const fee = ref('')
const QUICK = [1000, 1500, 2000, 2500]

function fill(data) {
  rates.value = data
  base.value = data.base_fee ?? ''
  free.value = data.free_shipping_above ?? ''
}
onMounted(async () => {
  try {
    fill((await http.get('/delivery-rates')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Communes avec leur propre prix, et celles qu'on peut ajouter
const special = computed(() => (rates.value?.places ?? []).filter((p) => p.learned).sort((a, b) => a.name.localeCompare(b.name, 'fr')))
const others = computed(() => (rates.value?.places ?? []).filter((p) => !p.learned).sort((a, b) => a.name.localeCompare(b.name, 'fr')))

async function run(action) {
  busy.value = true
  error.value = ''
  try {
    fill((await action()).data.data)
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}

async function addPlace() {
  if (!pick.value || fee.value === '') return
  if (await run(() => http.post('/delivery-rates/remember', { place_id: Number(pick.value), fee: Number(fee.value) }))) {
    adding.value = false
    pick.value = ''
    fee.value = ''
  }
}
const removePlace = (p) => run(() => http.delete(`/delivery-rates/places/${p.id}`))

async function save() {
  const body = { free_shipping_above: free.value === '' ? null : Number(free.value) }
  if (rates.value?.home) body.base_fee = Number(base.value || 0)
  if (await run(() => http.put('/delivery-rates', body))) emit('done')
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex flex-col bg-slate-50" role="dialog" aria-modal="true" aria-label="Prix de livraison">
    <header class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
      <h2 class="text-[18px] font-black">Prix de livraison</h2>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg" aria-label="Fermer" @click="emit('close')">✕</button>
    </header>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-3">
      <p v-if="error" class="rounded-xl bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700">{{ error }}</p>
      <p v-if="!rates && !error" class="py-10 text-center text-slate-500">Chargement…</p>

      <template v-if="rates">
        <!-- Prix de base -->
        <section class="rounded-2xl bg-white p-4">
          <p class="text-[14px] text-slate-600">Ce que paie la cliente, ajouté à sa commande</p>
          <template v-if="rates.home">
            <label class="mt-2 flex items-center justify-between gap-3">
              <b class="text-[17px]">{{ rates.home.name }} (partout)</b>
              <span class="flex items-center gap-1 rounded-xl border-2 border-slate-300 px-2">
                <input v-model="base" type="number" inputmode="numeric" min="0" class="w-20 border-0 bg-transparent p-1 text-right text-[17px] font-black focus:ring-0" aria-label="Prix de base">
                <b>F</b>
              </span>
            </label>
            <div class="mt-2 flex flex-wrap gap-2">
              <button v-for="q in QUICK" :key="q" type="button" class="rounded-full px-3 py-1 text-[13px] font-bold" :class="Number(base) === q ? 'bg-emerald-600 text-white' : 'bg-slate-100'" @click="base = q">{{ money(q) }}</button>
            </div>
          </template>
          <p v-else class="mt-2 text-[15px]">Choisis d'abord la ville de ta boutique dans <RouterLink to="/gestion/livraisons/tarifs" class="font-bold text-emerald-700 underline">Tarifs de livraison</RouterLink>.</p>
        </section>

        <!-- Communes à prix différent -->
        <section v-if="rates.home" class="rounded-2xl bg-white p-4 text-[15px]">
          <b class="block">Prix différents</b>
          <p v-if="!special.length && !adding" class="mt-1 text-[14px] text-slate-500">Aucun : le prix de base partout.</p>
          <div v-for="p in special" :key="p.id" class="flex items-center gap-3 border-b border-slate-100 py-2 last:border-0">
            <span class="min-w-0 flex-1 truncate">{{ p.name }}</span>
            <b>{{ money(p.fee ?? 0) }}</b>
            <button type="button" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 text-sm" :aria-label="`Retirer ${p.name}`" :disabled="busy" @click="removePlace(p)">✕</button>
          </div>
          <div v-if="adding" class="mt-2 space-y-2 rounded-xl bg-slate-50 p-3">
            <select v-model="pick" class="w-full rounded-xl border-slate-300 text-[15px]" aria-label="Commune">
              <option value="" disabled>Choisis la commune</option>
              <option v-for="p in others" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <div class="flex items-center gap-2">
              <input v-model="fee" type="number" inputmode="numeric" min="0" placeholder="Prix" class="min-w-0 flex-1 rounded-xl border-slate-300 text-[15px]" aria-label="Prix">
              <button type="button" class="rounded-xl bg-emerald-600 px-4 py-2 font-black text-white" :disabled="busy || !pick || fee === ''" @click="addPlace">Ajouter</button>
            </div>
          </div>
          <button v-else-if="others.length" type="button" class="mt-2 font-black text-emerald-700" @click="adding = true">＋ Ajouter une commune</button>
        </section>

        <!-- Livraison offerte -->
        <section class="rounded-2xl bg-white p-4 text-[15px]">
          <label class="flex items-center justify-between gap-3">
            <b>Livraison offerte dès</b>
            <span class="flex items-center gap-1 rounded-xl border-2 border-slate-300 px-2">
              <input v-model="free" type="number" inputmode="numeric" min="0" placeholder="jamais" class="w-24 border-0 bg-transparent p-1 text-right font-black focus:ring-0" aria-label="Livraison offerte dès">
              <b>F</b>
            </span>
          </label>
          <p class="mt-1 text-[13px] text-slate-500">Laisse vide si la livraison est toujours payante.</p>
        </section>

        <p class="px-1 text-[13px] text-slate-600">C'est toujours ce prix qui est mis dans la commande, même si l'entreprise de livraison a un autre prix.</p>
        <RouterLink to="/gestion/livraisons/tarifs" class="block text-center text-[14px] font-bold text-emerald-700 underline">Tous les tarifs (quartiers, autres villes) ▸</RouterLink>
      </template>
    </div>

    <footer class="border-t border-slate-200 bg-white p-3">
      <button type="button" class="w-full rounded-2xl bg-emerald-600 py-3.5 text-[16px] font-black text-white" :disabled="busy || !rates" @click="save">C'est bon ✓</button>
    </footer>
  </div>
</template>
