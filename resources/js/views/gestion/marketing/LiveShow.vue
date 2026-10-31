<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import LiveDisplay from '../../../components/LiveDisplay.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { boot } from '../../../lib/boot'
import { money } from '../../../lib/format'

/**
 * Écran du live, en deux onglets :
 * - « Mes produits » : ce que montre l'écran (un seul produit, défilement, comment commander), l'aperçu de ce que
 *   voient les clientes et la liste des produits à toucher pour les afficher ;
 * - « Écran » : relier une télé ou une tablette (adresse + code), ou TikTok LIVE Studio (lien à coller), et tester.
 */
const props = defineProps({ id: { type: [String, Number], required: true } })
const route = useRoute()
const router = useRouter()

const tab = ref(route.query.onglet === 'ecran' ? 'screen' : 'products')
watch(tab, (t) => router.replace({ query: t === 'screen' ? { onglet: 'ecran' } : {} }))

const live = ref(null) // état du live (statut, titre)
const display = ref(null) // ce que montrent les écrans : { store, live: { mode, featured_item_id, items… } }
const screens = ref([])
const error = ref('')
const busy = ref(false)
let timer

async function loadDisplay() {
  try {
    display.value = (await http.get(`/lives/${props.id}/display`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function loadScreens() {
  try {
    screens.value = (await http.get('/lives/screens')).data.data
  } catch {
    // affichage secondaire
  }
}
async function loadLive() {
  try {
    live.value = (await http.get(`/lives/${props.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  loadLive()
  loadDisplay()
  loadScreens()
  timer = setInterval(() => {
    loadDisplay()
    loadScreens()
  }, 3000)
})
onUnmounted(() => clearInterval(timer))

// ── Mes produits ──
const items = computed(() => display.value?.live?.items ?? [])
const mode = computed(() => display.value?.live?.mode ?? 'product')
const featured = computed(() => display.value?.live?.featured_item_id ?? null)
const shown = computed(() => items.value.find((i) => i.id === featured.value) ?? null)
const MODES = [
  ['product', '👆', 'Un seul produit', 'Touchez un produit : il s\'affiche aussitôt à l\'écran.'],
  ['scroll', '🔁', 'Faire défiler', 'Les produits passent tout seuls à l\'écran, l\'un après l\'autre.'],
  ['how', '🛒', 'Comment commander', 'L\'écran explique comment commander, avec le WhatsApp et le lien du site.'],
]
const hint = computed(() => MODES.find((m) => m[0] === mode.value)?.[3] ?? '')

async function run(request) {
  busy.value = true
  error.value = ''
  try {
    await request()
    await loadDisplay()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const setMode = (value) => run(() => http.post(`/lives/${props.id}/display`, { mode: value }))
// Toucher un produit l'affiche (et repasse en « un seul produit »)
const showItem = (item) => run(() => http.post(`/lives/${props.id}/feature`, { item_id: item.id }))
function next() {
  const list = items.value.filter((i) => i.remaining > 0)
  if (!list.length) return
  const at = list.findIndex((i) => i.id === featured.value)
  showItem(list[(at + 1) % list.length])
}

// ── Écran ──
const host = String(boot.platformUrl ?? `https://${boot.domain}`).replace(/^https?:\/\//, '').replace(/\/$/, '')
const tvs = computed(() => screens.value.filter((s) => !s.is_link))
const studio = computed(() => screens.value.find((s) => s.is_link) ?? null)
const code = ref('')
const name = ref('')
const pairErrors = ref({})
const adding = ref(false)
const tested = ref(null)

async function pair() {
  busy.value = true
  pairErrors.value = {}
  error.value = ''
  try {
    await http.post('/lives/screens/pair', { code: code.value, name: name.value })
    code.value = ''
    name.value = ''
    adding.value = false
    await loadScreens()
  } catch (e) {
    pairErrors.value = fieldErrors(e)
    if (!pairErrors.value.code) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function addAnother() {
  adding.value = true
  await nextTick()
  document.getElementById('screen-code')?.focus()
}
async function test(screen) {
  try {
    await http.post(`/lives/screens/${screen.id}/test`)
    tested.value = screen.id
    setTimeout(() => (tested.value = null), 10000)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function remove(screen) {
  if (!window.confirm(`Déconnecter « ${screen.name} » ? Il n'affichera plus votre live.`)) return
  await http.delete(`/lives/screens/${screen.id}`)
  if (screen.is_link) link.value = null
  await loadScreens()
}

// TikTok LIVE Studio : le lien à coller
const link = ref(null)
const copied = ref(false)
async function loadLink() {
  if (!link.value) link.value = (await http.post('/lives/screens/link')).data.data.url
}
async function copy() {
  try {
    await loadLink()
    await navigator.clipboard.writeText(link.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 3000)
  } catch {
    if (link.value) window.prompt('Copiez ce lien :', link.value)
  }
}
async function openLink() {
  await loadLink()
  window.open(link.value, '_blank')
}
watch(tab, (t) => t === 'screen' && loadLink().catch(() => {}), { immediate: true })
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-4">
    <RouterLink :to="`/gestion/live/${id}`" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Retour au live</RouterLink>

    <header>
      <h1 class="text-xl font-extrabold">{{ live?.title ?? 'Écran du live' }}</h1>
      <p v-if="live?.status === 'live'" class="text-sm font-bold text-red-600">● EN DIRECT<template v-if="display?.live?.orders"> · {{ display.live.orders }} commande{{ display.live.orders > 1 ? 's' : '' }}</template></p>
      <p v-else-if="live?.status === 'draft'" class="text-sm text-slate-500">Live pas encore lancé : préparez et testez votre écran.</p>
    </header>

    <div class="grid grid-cols-2 border-b border-slate-200" role="tablist">
      <button v-for="[value, label] in [['products', '🛍️ Mes produits'], ['screen', '📺 Écran']]" :key="value" type="button" role="tab" :aria-selected="tab === value"
              class="-mb-px border-b-[3px] py-3 text-base font-extrabold" :class="tab === value ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500'" @click="tab = value">
        {{ label }}
      </button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- ═══ Mes produits ═══ -->
    <template v-if="tab === 'products'">
      <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Que montrer à l'écran ?</p>
      <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Que montrer à l'écran ?">
        <button v-for="[value, icon, label] in MODES" :key="value" type="button" role="radio" :aria-checked="mode === value" :disabled="busy"
                class="rounded-2xl border-2 bg-white px-1 py-3 text-center text-sm font-extrabold leading-tight" :class="mode === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100' : 'border-slate-200 text-slate-700'" @click="setMode(value)">
          <span class="mb-1 block text-2xl" aria-hidden="true">{{ icon }}</span>{{ label }}
        </button>
      </div>
      <p class="rounded-xl bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-900">{{ hint }}</p>

      <!-- Aperçu : ce que voient les clientes en ce moment -->
      <div class="overflow-hidden rounded-2xl bg-slate-900">
        <p class="px-3 pt-2 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">À l'écran maintenant</p>
        <div class="mx-auto aspect-video w-full max-w-md p-2">
          <div class="h-full overflow-hidden rounded-lg"><LiveDisplay v-if="display" :data="display" /></div>
        </div>
      </div>

      <button v-if="mode === 'product' && items.length > 1" type="button" class="btn-brand w-full py-4 text-base" :disabled="busy" @click="next">Produit suivant ▶</button>

      <p v-if="!items.length && display" class="card p-4 text-sm text-slate-500">Aucun produit dans ce live : ajoutez-en depuis la page du live.</p>
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <button v-for="i in items" :key="i.id" type="button" :disabled="busy"
                class="relative overflow-hidden rounded-2xl border-[3px] bg-white text-left shadow-sm" :class="mode === 'product' && featured === i.id ? 'border-red-600' : 'border-transparent'" @click="showItem(i)">
          <span class="block aspect-[4/3] bg-gradient-to-br from-orange-300 to-rose-400">
            <img v-if="i.image" :src="i.image" alt="" class="h-full w-full object-contain bg-slate-100" loading="lazy" />
          </span>
          <span class="absolute left-1.5 top-1.5 rounded-md bg-red-600 px-1.5 text-sm font-black text-white">{{ i.code }}</span>
          <span v-if="mode === 'product' && featured === i.id" class="absolute right-1.5 top-1.5 rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-black text-white">À L'ÉCRAN</span>
          <span v-if="i.remaining === 0" class="absolute inset-x-0 top-0 grid aspect-[4/3] place-items-center bg-white/60 text-xl font-black text-red-600">VENDU</span>
          <span class="block p-2 text-sm">
            <b class="block truncate">{{ i.title }}</b>
            <span class="text-xs text-slate-500">{{ money(i.price) }} · {{ i.remaining === 0 ? 'épuisé' : `${i.remaining} pièce${i.remaining > 1 ? 's' : ''}` }}</span>
          </span>
        </button>
      </div>
    </template>

    <!-- ═══ Écran ═══ -->
    <template v-else>
      <!-- Télé ou tablette -->
      <section class="rounded-2xl border-2 border-emerald-700 bg-white p-4">
        <h2 class="text-lg font-extrabold">📺 Télé ou tablette</h2>
        <p class="text-sm text-slate-500">Posée à côté de vous, filmée avec vous</p>

        <template v-if="tvs.length && !adding">
          <div v-for="s in tvs" :key="s.id" class="mt-3 rounded-xl border-2 p-3" :class="s.online ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200'">
            <p class="text-base font-extrabold" :class="s.online ? 'text-emerald-800' : 'text-slate-600'">{{ s.online ? '✅ Écran connecté' : '○ Écran éteint' }} · {{ s.name }}</p>
            <p v-if="!s.online" class="mt-1 text-sm text-slate-500">Allumez-le et ouvrez <b>{{ host }}/ecran</b> : il se reconnecte tout seul.</p>
            <div class="mt-2 flex gap-2">
              <button type="button" class="btn-secondary flex-1 py-3 text-base" :disabled="!s.online" @click="test(s)">{{ tested === s.id ? '✓ Regardez la télé' : '🧪 Tester' }}</button>
              <button type="button" class="btn-ghost text-red-600" @click="remove(s)">Déconnecter</button>
            </div>
          </div>
          <button type="button" class="mt-3 text-sm font-semibold text-emerald-700" @click="addAnother">+ Connecter un autre écran</button>
        </template>

        <form v-else class="space-y-4" @submit.prevent="pair">
          <div class="mt-4 flex gap-3">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 font-black text-white">1</span>
            <div class="min-w-0">
              <p class="text-base font-bold">Sur la télé, ouvrez :</p>
              <p class="mt-1 break-words rounded-xl bg-emerald-50 px-3 py-2 text-2xl font-black text-emerald-800">{{ host }}/ecran</p>
            </div>
          </div>
          <div class="flex gap-3">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 font-black text-white">2</span>
            <div class="min-w-0 flex-1">
              <label for="screen-code" class="block text-base font-bold">Tapez le code affiché sur la télé :</label>
              <input id="screen-code" v-model="code" class="input mt-2 py-3 text-center text-4xl font-black tracking-[.6em]" inputmode="numeric" maxlength="4" placeholder="····" autocomplete="off" />
              <p v-if="pairErrors.code" class="mt-1 text-sm text-red-600">{{ pairErrors.code }}</p>
            </div>
          </div>
          <button class="btn-brand w-full py-4 text-lg" :disabled="busy || code.length !== 4">{{ busy ? 'Connexion…' : 'Connecter l\'écran' }}</button>
          <button v-if="tvs.length" type="button" class="btn-ghost w-full" @click="adding = false">Annuler</button>
        </form>
      </section>

      <!-- TikTok LIVE Studio -->
      <section class="rounded-2xl border-2 border-slate-200 bg-white p-4">
        <h2 class="text-lg font-extrabold">🖥️ TikTok LIVE Studio</h2>
        <p class="text-sm text-slate-500">Sur l'ordinateur</p>
        <p v-if="studio?.online" class="mt-3 rounded-xl bg-emerald-50 p-2.5 text-base font-extrabold text-emerald-800">✅ Connecté à LIVE Studio</p>
        <div class="mt-3 flex gap-3">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 font-black text-white">1</span>
          <div class="min-w-0 flex-1">
            <p class="text-base font-bold">Copiez ce lien :</p>
            <div class="mt-1 flex gap-2">
              <span class="min-w-0 flex-1 truncate rounded-xl border-2 border-dashed border-slate-400 bg-slate-50 px-3 py-2.5 font-mono text-xs">{{ link ?? 'Préparation du lien…' }}</span>
              <button type="button" class="btn-brand shrink-0 px-4" @click="copy">{{ copied ? '✓ Copié' : 'Copier' }}</button>
            </div>
          </div>
        </div>
        <div class="mt-3 flex gap-3">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 font-black text-white">2</span>
          <p class="text-base font-bold">Dans LIVE Studio : <span class="text-emerald-800">Ajouter une source › Lien</span>, collez-le.</p>
        </div>
        <div class="mt-3 flex gap-3">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-900 font-black text-white">3</span>
          <p class="text-base font-bold">Placez-le en haut, la caméra en bas.</p>
        </div>
        <button type="button" class="btn-secondary mt-4 w-full py-3 text-base" @click="openLink">🧪 Tester : ouvrir le lien</button>
      </section>
    </template>
  </div>
</template>
