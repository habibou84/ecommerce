<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import Alert from './Alert.vue'
import LiveDisplay from './LiveDisplay.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { boot } from '../lib/boot'
import { money } from '../lib/format'

/**
 * Sur la page du live, sous la carte du live : deux onglets.
 * - « Mes produits » : ce que montre l'écran (un seul produit, défilement, comment commander), l'aperçu de ce que
 *   voient les clientes et la liste des produits à toucher pour les afficher ;
 * - « Écran » : relier une télé ou une tablette (adresse + code), ou TikTok LIVE Studio (lien à coller), et tester.
 */
const props = defineProps({
  id: { type: [String, Number], required: true },
  // Onglet ouvert au départ : 'products' ou 'screen'
  initial: { type: String, default: 'products' },
})
// « + Ajouter » : la page du live ouvre sa fenêtre d'ajout de produits
const emit = defineEmits(['add'])

const tab = ref(props.initial === 'screen' ? 'screen' : 'products')
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
onMounted(() => {
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
  ['product', '🏷️', 'Montrer un seul produit', 'Touchez un produit ci-dessous : il s\'affiche aussitôt à l\'écran.'],
  ['scroll', '🔁', 'Faire défiler tous les produits', 'Les produits passent tout seuls à l\'écran, l\'un après l\'autre.'],
  ['how', '🛒', 'Montrer comment commander', 'L\'écran montre le WhatsApp et le lien du site pour commander.'],
]
const SHORT = { product: 'Un produit', scroll: 'Défiler', how: 'Commander' }
// Carte « À l'écran maintenant » agrandie en plein écran (Échap ferme)
const expanded = ref(false)
function onKey(e) {
  if (e.key === 'Escape') expanded.value = false
}
watch(expanded, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  open ? document.addEventListener('keydown', onKey) : document.removeEventListener('keydown', onKey)
})
onUnmounted(() => {
  document.body.style.overflow = ''
  document.removeEventListener('keydown', onKey)
})
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
// Produit suivant ou précédent (les produits épuisés sont sautés)
function step(delta) {
  const list = items.value.filter((i) => i.remaining > 0)
  if (!list.length) return
  const at = list.findIndex((i) => i.id === featured.value)
  const to = at < 0 ? (delta > 0 ? 0 : list.length - 1) : (at + delta + list.length) % list.length
  showItem(list[to])
}
const next = () => step(1)
const previous = () => step(-1)
// Bande de miniatures : le produit à l'écran reste visible quand il change
const strip = ref(null)
watch(featured, async (id) => {
  await nextTick()
  const box = strip.value
  const el = box?.querySelector(`[data-item="${id}"]`)
  // Défilement de la bande seulement (la page ne bouge pas)
  if (el) box.scrollTo({ left: el.offsetLeft - (box.clientWidth - el.clientWidth) / 2, behavior: 'smooth' })
})

// ── Écran ──
const host = String(boot.platformUrl ?? `https://${boot.domain}`).replace(/^https?:\/\//, '').replace(/\/$/, '')
const tvs = computed(() => screens.value.filter((s) => !s.is_link))
// En haut : les écrans allumés, sinon le dernier relié ; les autres sont rangés dans « Anciens écrans »
const seen = (s) => new Date(s.last_seen_at ?? s.paired_at ?? 0).getTime()
const mainTvs = computed(() => {
  const online = tvs.value.filter((s) => s.online)
  if (online.length) return online
  const last = [...tvs.value].sort((a, b) => new Date(b.paired_at ?? 0) - new Date(a.paired_at ?? 0))[0]
  return last ? [last] : []
})
const oldTvs = computed(() => tvs.value.filter((s) => !mainTvs.value.includes(s)).sort((a, b) => seen(b) - seen(a)))
const showOld = ref(false)
function since(iso) {
  if (!iso) return 'jamais allumé'
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 2) return 'à l\'instant'
  if (min < 60) return `il y a ${min} min`
  if (min < 24 * 60) return `il y a ${Math.round(min / 60)} h`
  const days = Math.round(min / 1440)
  return days === 1 ? 'hier' : `il y a ${days} j`
}
async function removeOld(screen) {
  await http.delete(`/lives/screens/${screen.id}`)
  await loadScreens()
}
async function removeAllOld() {
  if (!window.confirm(`Retirer les ${oldTvs.value.length} anciens écrans ?`)) return
  for (const s of oldTvs.value) await http.delete(`/lives/screens/${s.id}`)
  await loadScreens()
}
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
  if (!window.confirm(screen.online === false ? `Retirer « ${screen.name} » ?` : `Déconnecter « ${screen.name} » ? Il n'affichera plus votre live.`)) return
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
  <section class="space-y-4">

    <div class="grid grid-cols-2 border-b border-slate-200" role="tablist">
      <button v-for="[value, label] in [['products', '🛍️ Produits'], ['screen', '📺 Écran']]" :key="value" type="button" role="tab" :aria-selected="tab === value"
              class="-mb-px border-b-[3px] py-3 text-base font-extrabold" :class="tab === value ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-500'" @click="tab = value">
        {{ label }}
      </button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- ═══ Mes produits ═══ -->
    <template v-if="tab === 'products'">
      <p class="text-[17px] font-extrabold">Que voir sur l'écran du live ?</p>
      <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Que voir sur l'écran du live ?">
        <button v-for="[value, icon, label] in MODES" :key="value" type="button" role="radio" :aria-checked="mode === value" :disabled="busy"
                class="rounded-2xl border-2 bg-white px-1 py-3 text-center text-sm font-extrabold leading-tight" :class="mode === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900 ring-4 ring-emerald-100' : 'border-slate-200 text-slate-700'" @click="setMode(value)">
          <span class="mx-auto mb-1.5 grid h-11 w-11 place-items-center rounded-xl text-2xl" :class="mode === value ? 'bg-emerald-700' : 'bg-slate-100'" aria-hidden="true">{{ icon }}</span>{{ label }}
        </button>
      </div>
      <p class="rounded-xl bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-900">{{ hint }}</p>

      <!-- Un seul produit : les produits en miniatures à glisser, juste au-dessus de l'aperçu -->
      <template v-if="mode === 'product' && items.length">
        <div class="flex items-center justify-between px-0.5">
          <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Produits du live · {{ items.length }}</p>
          <p v-if="items.length > 3" class="text-xs font-bold text-emerald-700">Glissez →</p>
        </div>
        <div ref="strip" class="-mx-4 flex snap-x gap-2.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
          <button v-for="i in items" :key="i.id" type="button" :data-item="i.id" :disabled="busy"
                  class="relative w-24 shrink-0 snap-start overflow-hidden rounded-2xl border-[3px] bg-white text-left shadow-sm" :class="featured === i.id ? 'border-red-600' : 'border-transparent'" @click="showItem(i)">
            <span class="block h-[72px] bg-gradient-to-br from-orange-300 to-rose-400">
              <img v-if="i.image" :src="i.image" alt="" class="h-full w-full bg-slate-100 object-contain" loading="lazy" />
            </span>
            <span class="absolute left-1 top-1 rounded-md bg-red-600 px-1.5 text-xs font-black text-white">{{ i.code }}</span>
            <span v-if="featured === i.id" class="absolute right-1 top-1 rounded-full bg-red-600 px-1.5 py-0.5 text-[9px] font-black text-white">À L'ÉCRAN</span>
            <span v-if="i.remaining === 0" class="absolute inset-x-0 top-0 grid h-[72px] place-items-center bg-white/60 text-sm font-black text-red-600">VENDU</span>
            <span class="block px-1.5 pb-1.5 pt-1 text-xs">
              <b class="block truncate">{{ i.title }}</b>
              <span class="text-[11px] text-slate-500">{{ money(i.price) }}</span>
            </span>
          </button>
        </div>
      </template>

      <!-- Aperçu : ce que voient les clientes en ce moment -->
      <div class="relative cursor-zoom-in overflow-hidden rounded-2xl bg-slate-900" role="button" tabindex="0" aria-label="Agrandir l'écran du live" @click="expanded = true" @keydown.enter="expanded = true">
        <p class="px-3 pt-2 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">À l'écran maintenant</p>
        <div class="mx-auto aspect-video w-full max-w-md p-2">
          <div class="pointer-events-none h-full overflow-hidden rounded-lg"><LiveDisplay v-if="display" :data="display" /></div>
        </div>
        <span class="absolute bottom-3 right-3 rounded-full bg-white px-2.5 py-1 text-xs font-extrabold text-slate-900 shadow">⤢ Agrandir</span>
      </div>

      <!-- Carte agrandie : l'écran en grand, les produits à toucher pour changer, précédent / suivant -->
      <Teleport to="body">
        <div v-if="expanded" class="fixed inset-0 z-50 flex flex-col bg-[#0b1120] text-white" role="dialog" aria-modal="true" aria-label="À l'écran maintenant">
          <div class="flex items-center justify-between px-4 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))]">
            <b class="text-base">À l'écran maintenant</b>
            <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-white/15 text-lg" aria-label="Fermer" @click="expanded = false">✕</button>
          </div>
          <div class="mx-auto aspect-video w-full max-w-3xl shrink-0 px-3">
            <div class="h-full overflow-hidden rounded-xl bg-black"><LiveDisplay v-if="display" :data="display" /></div>
          </div>
          <div class="mx-auto mt-3 grid w-full max-w-3xl shrink-0 grid-cols-3 gap-1.5 px-3" role="radiogroup" aria-label="Que voir sur l'écran du live ?">
            <button v-for="[value, icon, label] in MODES" :key="value" type="button" role="radio" :aria-checked="mode === value" :disabled="busy"
                    class="rounded-lg px-1 py-2 text-xs font-extrabold" :class="mode === value ? 'bg-emerald-700 text-white' : 'bg-white/10 text-slate-300'" @click="setMode(value)">
              {{ icon }} {{ SHORT[value] }}
            </button>
          </div>
          <p class="mx-auto mt-3 w-full max-w-3xl px-4 text-[11px] font-extrabold uppercase tracking-wider text-slate-400">Touchez un produit pour changer</p>
          <div class="mx-auto mt-2 grid min-h-0 w-full max-w-3xl flex-1 auto-rows-min grid-cols-3 gap-2 overflow-y-auto px-3 pb-2 sm:grid-cols-4">
            <button v-for="i in items" :key="i.id" type="button" :disabled="busy"
                    class="relative overflow-hidden rounded-xl border-[3px] bg-white text-left text-slate-900" :class="mode === 'product' && featured === i.id ? 'border-red-600' : 'border-transparent'" @click="showItem(i)">
              <span class="block h-16 bg-gradient-to-br from-orange-300 to-rose-400">
                <img v-if="i.image" :src="i.image" alt="" class="h-full w-full bg-slate-100 object-contain" loading="lazy" />
              </span>
              <span class="absolute left-1 top-1 rounded-md bg-red-600 px-1.5 text-xs font-black text-white">{{ i.code }}</span>
              <span v-if="i.remaining === 0" class="absolute inset-x-0 top-0 grid h-16 place-items-center bg-white/60 text-sm font-black text-red-600">VENDU</span>
              <span class="block px-1.5 pb-1 pt-0.5 text-[11px]">
                <b class="block truncate">{{ i.title }}</b>
                <span class="text-slate-500">{{ money(i.price) }}</span>
              </span>
            </button>
          </div>
          <div class="mx-auto grid w-full max-w-3xl shrink-0 grid-cols-2 gap-2 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-2">
            <button type="button" class="rounded-xl border border-white/25 bg-white/10 py-3.5 text-base font-extrabold" :disabled="busy || items.length < 2" @click="previous">◀ Précédent</button>
            <button type="button" class="rounded-xl bg-emerald-700 py-3.5 text-base font-extrabold" :disabled="busy || items.length < 2" @click="next">Suivant ▶</button>
          </div>
        </div>
      </Teleport>

      <div v-if="mode === 'product' && items.length > 1" class="grid grid-cols-2 gap-2">
        <button type="button" class="btn-secondary whitespace-nowrap px-2 py-4 text-[15px]" :disabled="busy" @click="previous">◀ Produit précédent</button>
        <button type="button" class="btn-brand whitespace-nowrap px-2 py-4 text-[15px]" :disabled="busy" @click="next">Produit suivant ▶</button>
      </div>

      <div class="flex items-center justify-between px-0.5">
        <p class="text-xs font-extrabold uppercase tracking-wide text-slate-500">Produits du live<template v-if="items.length"> · {{ items.length }}</template></p>
        <button type="button" class="text-sm font-semibold text-emerald-700" @click="emit('add')">+ Ajouter</button>
      </div>
      <p v-if="!items.length && display" class="card p-4 text-sm text-slate-500">Aucun produit dans ce live : touchez « + Ajouter ».</p>
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
          <div v-for="s in mainTvs" :key="s.id" class="mt-3 rounded-xl border-2 p-3" :class="s.online ? 'border-emerald-500 bg-emerald-50' : 'border-slate-200'">
            <p class="text-base font-extrabold" :class="s.online ? 'text-emerald-800' : 'text-slate-600'">{{ s.online ? '✅ Connecté' : '○ Éteint' }} · {{ s.name }}</p>
            <p v-if="s.online" class="text-xs text-emerald-700">Relié {{ since(s.paired_at) }}</p>
            <p v-else class="mt-1 text-sm text-slate-500"><template v-if="s.last_seen_at">Vu pour la dernière fois {{ since(s.last_seen_at) }}. </template>Allumez-le et ouvrez <b>{{ host }}/ecran</b> : il se reconnecte tout seul.</p>
            <div class="mt-2 flex gap-2">
              <button type="button" class="btn-secondary flex-1 py-3 text-base" :disabled="!s.online" @click="test(s)">{{ tested === s.id ? '✓ Regardez la télé' : '🧪 Tester' }}</button>
              <button type="button" class="btn-ghost text-red-600" @click="remove(s)">{{ s.online ? 'Déconnecter' : 'Retirer' }}</button>
            </div>
          </div>
          <button type="button" class="mt-3 w-full rounded-xl border-2 border-dashed border-emerald-600 py-3 text-base font-extrabold text-emerald-800" @click="addAnother">+ Relier un autre écran</button>

          <!-- Anciens écrans : repliés -->
          <div v-if="oldTvs.length" class="mt-3 border-t border-slate-200 pt-2">
            <button type="button" class="flex w-full items-center justify-between py-1 text-sm font-bold text-slate-600" :aria-expanded="showOld" @click="showOld = !showOld">
              Anciens écrans ({{ oldTvs.length }})<span aria-hidden="true">{{ showOld ? '▴' : '▾' }}</span>
            </button>
            <template v-if="showOld">
              <div v-for="s in oldTvs" :key="s.id" class="flex items-center justify-between border-b border-slate-100 py-2 text-sm text-slate-500">
                <span><b class="font-semibold text-slate-700">{{ s.name }}</b> · {{ s.last_seen_at ? `vu ${since(s.last_seen_at)}` : 'jamais allumé' }}</span>
                <button type="button" class="text-xs font-bold text-red-600" @click="removeOld(s)">Retirer</button>
              </div>
              <div class="mt-1 text-right"><button type="button" class="text-xs font-bold text-red-600" @click="removeAllOld">Tout retirer</button></div>
            </template>
            <p class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-500">Un écran relié à nouveau sous le même nom remplace l'ancien ; un écran éteint depuis plus de 7 jours est retiré tout seul.</p>
          </div>
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
  </section>
</template>
