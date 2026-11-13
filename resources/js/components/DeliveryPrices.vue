<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

/**
 * Prix de livraison à toucher : le prix de la ville, toutes les communes déjà listées (prix de la ville en gris, prix
 * changé en vert), les quartiers à part, les expéditions vers les autres villes (un seul prix pour toutes, paiement
 * avant l'envoi) et la livraison offerte. Chaque toucher est enregistré tout de suite.
 */
const emit = defineEmits(['loaded'])

const rates = ref(null)
const error = ref('')
const busy = ref(false)
const allCommunes = ref(false)
const sheet = ref(null) // { kind, place?, title, value }
const other = ref('')
const quartiers = ref(null) // quartiers de la commune ouverte dans la fenêtre

const CITY_CHIPS = [1000, 1500, 2000, 2500, 3000]
const SHIP_CHIPS = [2000, 2500, 3000, 3500, 4000, 5000]
const FREE_CHIPS = [20000, 30000, 50000, 100000]

async function run(action) {
  busy.value = true
  error.value = ''
  try {
    rates.value = (await action()).data.data
    emit('loaded', rates.value)
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  } finally {
    busy.value = false
  }
}
onMounted(() => run(() => http.get('/delivery-rates')))

const base = computed(() => rates.value?.base_fee ?? null)
const homeName = computed(() => rates.value?.home?.name ?? 'la ville')
const communes = computed(() => (allCommunes.value ? rates.value?.places ?? [] : (rates.value?.places ?? []).slice(0, 6)))
const shipping = computed(() => rates.value?.expedition?.fee ?? null)

function open(kind, place = null) {
  other.value = ''
  quartiers.value = null
  const value = {
    base: base.value,
    place: place?.learned ? place.fee : null,
    quartier: place?.learned ? place.fee : null,
    shipping: shipping.value,
    free: rates.value?.free_shipping_above ?? null,
  }[kind]
  const title = { base: `${homeName.value} (partout)`, shipping: 'Expédition · toutes les villes', free: 'Livraison offerte dès' }[kind] ?? place.name
  sheet.value = { kind, place, title, value, parent: kind === 'quartier' ? sheet.value?.place : null }
}

const chips = computed(() => ({ shipping: SHIP_CHIPS, free: FREE_CHIPS }[sheet.value?.kind] ?? CITY_CHIPS))

async function choose(fee) {
  const s = sheet.value
  const ok = await run(() => ({
    base: () => http.put('/delivery-rates', { base_fee: fee }),
    shipping: () => http.put('/delivery-rates', { expedition_fee: fee }),
    free: () => http.put('/delivery-rates', { free_shipping_above: fee }),
  }[s.kind] ?? (() => http.post('/delivery-rates/remember', { place_id: s.place.id, fee })))())
  if (ok) sheet.value = null
}
async function reset() {
  const s = sheet.value
  if (s.kind === 'free') return choose(null)
  if (await run(() => http.delete(`/delivery-rates/places/${s.place.id}`))) sheet.value = null
}
const resetLabel = computed(() => {
  const s = sheet.value
  if (!s) return ''
  if (s.kind === 'free') return 'Jamais offerte'
  if (s.kind === 'place') return `Comme ${homeName.value} (${money(base.value ?? 0)})`
  if (s.kind === 'quartier') return `Comme ${s.parent?.name ?? 'la commune'}`
  return ''
})
const canReset = computed(() => sheet.value && (sheet.value.kind === 'free' ? sheet.value.value !== null : ['place', 'quartier'].includes(sheet.value.kind) && sheet.value.place?.learned))

async function loadQuartiers() {
  quartiers.value = (await http.get(`/delivery-rates/places/${sheet.value.place.id}`)).data.data.places
}

const toggleShipping = () => run(() => http.put('/delivery-rates', { expedition_fee: shipping.value === null ? 3000 : null }))
const togglePrepay = () => run(() => http.put('/delivery-rates', { expedition_prepay: !rates.value.expedition.prepay }))
// Lien de paiement Wave de la boutique : envoyé à la cliente quand l'expédition est payée avant l'envoi
const wave = ref('')
const waveSaved = ref(false)
watch(() => rates.value?.wave_link, (v) => (wave.value = v ?? ''), { immediate: true })
// Le message copié depuis Wave contient du texte avant le lien : on ne garde que le lien
function onWavePaste(e) {
  const text = e.clipboardData?.getData('text') ?? ''
  const link = text.match(/https?:\/\/([a-z0-9-]+\.)*wave\.[a-z]{2,}\/[^\s<>"']*/i)?.[0]
  if (link) {
    e.preventDefault()
    wave.value = link.replace(/[.,;:!?)»]+$/, '').replace(/^http:\/\//i, 'https://')
  }
}
async function saveWave() {
  if (await run(() => http.put('/delivery-rates', { wave_link: wave.value.trim() || null }))) {
    waveSaved.value = true
    setTimeout(() => (waveSaved.value = false), 2000)
  }
}

const pill = (on) => ['shrink-0 whitespace-nowrap rounded-xl border-2 px-2.5 py-1 text-[15px] font-black tabular-nums', on ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-field font-bold text-slate-500']
</script>

<template>
  <div class="space-y-3">
    <p v-if="error" class="rounded-xl bg-rose-50 px-3 py-2 text-sm font-bold text-rose-700">{{ error }}</p>
    <p v-if="!rates && !error" class="py-10 text-center text-slate-500">Chargement…</p>

    <template v-if="rates">
      <p v-if="!rates.home" class="rounded-2xl bg-white p-4 text-[15px]">Choisissez d'abord la ville de votre boutique dans <RouterLink to="/gestion/livraisons/tarifs" class="font-bold text-emerald-700 underline">Tarifs de livraison</RouterLink>.</p>
      <template v-else>
        <!-- Prix de la ville -->
        <section class="rounded-2xl bg-white p-4">
          <p class="text-[14px] text-slate-600">Ce que paie la cliente, ajouté à sa commande</p>
          <button type="button" class="mt-2 flex w-full items-center justify-between gap-3 text-left" @click="open('base')">
            <b class="text-[17px]">{{ homeName }} (partout)</b>
            <span :class="pill(true)">{{ base !== null ? money(base) : 'À fixer' }}</span>
          </button>
        </section>

        <!-- Communes -->
        <p class="px-1 text-[13px] font-black uppercase tracking-wide text-slate-500">Par commune · touchez un prix</p>
        <section class="rounded-2xl bg-white px-4 py-1">
          <button v-for="c in communes" :key="c.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-slate-100 py-2.5 text-left last:border-0" @click="open('place', c)">
            <span class="min-w-0 truncate text-[16px]">{{ c.name }}</span>
            <span :class="pill(c.learned)">{{ money((c.learned ? c.fee : base) ?? 0) }}</span>
          </button>
          <button v-if="!allCommunes && rates.places.length > 6" type="button" class="block w-full py-2.5 text-center text-[14px] font-black text-emerald-700" @click="allCommunes = true">Voir les {{ rates.places.length - 6 }} autres communes ▾</button>
        </section>

        <!-- Expéditions -->
        <p class="px-1 pt-1 text-[13px] font-black uppercase tracking-wide text-slate-500">Expéditions · autres villes</p>
        <section class="rounded-2xl bg-white p-4">
          <button type="button" class="flex w-full items-center justify-between gap-3 text-left text-[16px] font-extrabold" :disabled="busy" @click="toggleShipping">
            <span>J'envoie dans les autres villes</span>
            <span class="relative h-6 w-10 shrink-0 rounded-full transition" :class="shipping !== null ? 'bg-emerald-600' : 'bg-slate-300'"><span class="absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white transition-all" :class="shipping !== null ? 'right-[3px]' : 'left-[3px]'" /></span>
          </button>
          <template v-if="shipping !== null">
            <button type="button" class="mt-3 flex w-full items-center justify-between gap-3 text-left" @click="open('shipping')">
              <span><b class="block text-[15.5px]">Prix pour toutes les villes</b><small class="text-[12.5px] text-slate-500">Toutes les autres villes, tout compris : jusqu'à la gare + le car</small></span>
              <span :class="pill(true)">{{ money(shipping) }}</span>
            </button>
          </template>
        </section>
        <template v-if="shipping !== null">
          <section class="rounded-2xl bg-white p-4">
            <button type="button" class="flex w-full items-center justify-between gap-3 text-left text-[16px] font-extrabold" :disabled="busy" @click="togglePrepay">
              <span>Paiement avant l'envoi</span>
              <span class="relative h-6 w-10 shrink-0 rounded-full transition" :class="rates.expedition.prepay ? 'bg-emerald-600' : 'bg-slate-300'"><span class="absolute top-[3px] h-[18px] w-[18px] rounded-full bg-white transition-all" :class="rates.expedition.prepay ? 'right-[3px]' : 'left-[3px]'" /></span>
            </button>
            <p class="mt-1 text-[13px] text-slate-500">Le colis part quand la cliente a payé.</p>
            <!-- Paiement avant l'envoi : le lien Wave de la boutique, envoyé tout seul à la cliente -->
            <div v-if="rates.expedition.prepay" class="mt-3 rounded-xl p-3" :class="rates.wave_link ? 'bg-sky-50' : 'bg-amber-50 ring-2 ring-amber-300'">
              <b class="block text-[15px]">🌊 Votre lien de paiement Wave</b>
              <p class="text-[13px] text-slate-600">Collez-le ici : il est envoyé tout seul à la cliente. Elle paie, envoie la capture du reçu, et vous validez.</p>
              <div class="mt-2 flex gap-2">
                <input v-model="wave" type="url" @paste="onWavePaste" inputmode="url" placeholder="Collez votre lien Wave" class="min-w-0 flex-1 rounded-xl border-2 border-field px-3 py-2 text-[14px]" aria-label="Lien de paiement Wave">
                <button type="button" class="shrink-0 rounded-xl bg-sky-600 px-4 font-black text-white" :disabled="busy || wave.trim() === (rates.wave_link ?? '')" @click="saveWave">{{ waveSaved ? '✓' : 'OK' }}</button>
              </div>
              <p class="mt-1 text-[12px] text-slate-500">Sur l'accueil de Wave : <b>Envoi de lien</b> › <b>Partager</b> sans taper de somme › <b>Copier</b>, puis collez ici.</p>
            </div>
          </section>
        </template>

        <!-- Livraison offerte -->
        <section class="rounded-2xl bg-white p-4">
          <button type="button" class="flex w-full items-center justify-between gap-3 text-left" @click="open('free')">
            <b class="text-[16px]">Livraison offerte dès</b>
            <span :class="pill(!!rates.free_shipping_above)">{{ rates.free_shipping_above ? money(rates.free_shipping_above) : 'Jamais' }}</span>
          </button>
        </section>
      </template>
    </template>

    <!-- Fenêtre du prix touché -->
    <div v-if="sheet" class="fixed inset-0 z-[60] flex items-end bg-slate-900/45" @click.self="sheet = null">
      <div class="max-h-[85vh] w-full overflow-y-auto rounded-t-[22px] bg-white p-4 pb-6" role="dialog" aria-modal="true" :aria-label="sheet.title">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="text-[19px] font-black">{{ sheet.title }}</p>
            <p class="text-[14px] text-slate-500">{{ sheet.kind === 'free' ? 'Montant des articles à partir duquel la livraison est offerte' : 'Ce que paie la cliente' }}</p>
          </div>
          <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100" aria-label="Fermer" @click="sheet = null">✕</button>
        </div>
        <div class="mt-3 grid grid-cols-3 gap-2">
          <button v-for="c in chips" :key="c" type="button" class="rounded-xl py-3 text-[16px] font-black tabular-nums" :class="sheet.value === c ? 'bg-emerald-700 text-white' : 'bg-slate-100'" :disabled="busy" @click="choose(c)">{{ c.toLocaleString('fr-FR') }}</button>
        </div>
        <form class="mt-2 flex items-center gap-2" @submit.prevent="other !== '' && choose(Number(other))">
          <input v-model="other" type="number" inputmode="numeric" min="0" placeholder="Autre prix" class="min-w-0 flex-1 rounded-xl border-2 border-field px-3 py-2.5 text-[16px] font-bold" aria-label="Autre prix">
          <button type="submit" class="rounded-xl bg-emerald-700 px-4 py-3 font-black text-white" :disabled="busy || other === ''">OK</button>
        </form>
        <button v-if="canReset" type="button" class="mt-3 block w-full rounded-xl border-2 border-field py-3 text-[15px] font-black" :disabled="busy" @click="reset">{{ resetLabel }}</button>

        <!-- Quartiers à part dans la commune -->
        <template v-if="sheet.kind === 'place' && sheet.place.has_children">
          <button v-if="!quartiers" type="button" class="mt-3 block w-full text-center text-[14px] font-black text-emerald-700" @click="loadQuartiers">Un quartier a un autre prix ? ▸</button>
          <div v-else class="mt-3 rounded-xl bg-slate-50 px-3 py-1">
            <button v-for="q in quartiers" :key="q.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-slate-200 py-2 text-left last:border-0" @click="open('quartier', q)">
              <span class="min-w-0 truncate text-[15px]">{{ q.name }}</span>
              <span :class="pill(q.learned)">{{ money(q.fee ?? 0) }}</span>
            </button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
