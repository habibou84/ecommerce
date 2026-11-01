<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Field from '../../../components/Field.vue'
import Icon from '../../../components/Icon.vue'
import LiveLink from '../../../components/LiveLink.vue'
import LiveTraining from '../../../components/LiveTraining.vue'
import LiveWhatsApp from '../../../components/LiveWhatsApp.vue'
import MaterielTip from '../../../components/MaterielTip.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'

/**
 * Vendre en live (menu Ventes) : le live en cours en premier, les lives prêts, les lives passés (avec « Refaire ce
 * live »), la formation repliée. « Préparer un live » : articles choisis d'un coup, ordre = codes, barre fixe.
 */
const router = useRouter()
const lives = ref(null)
const pageUrl = ref('')
const whatsapp = ref(null)
const error = ref('')
const errors = ref({})
const busy = ref(false)
const editing = ref(false)
const share = ref(false)
const copied = ref(false)
const form = reactive({ title: '', stream_url: '', hold_minutes: 120, items: [] })

async function load() {
  try {
    const { data } = await http.get('/lives')
    lives.value = data.data
    pageUrl.value = data.meta.page_url
    whatsapp.value = data.meta.whatsapp
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const current = computed(() => lives.value?.find((l) => l.status === 'live'))
const drafts = computed(() => (lives.value ?? []).filter((l) => l.status === 'draft'))
const past = computed(() => (lives.value ?? []).filter((l) => l.status === 'ended'))

const day = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' }) : '')
const capitalize = (s) => s.charAt(0).toUpperCase() + s.slice(1)
const length = (m) => (m == null ? '' : m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`)

async function copyLink() {
  try {
    await navigator.clipboard.writeText(pageUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copiez ce lien :', pageUrl.value)
  }
}

async function redo(live) {
  busy.value = true
  try {
    const { data } = await http.post(`/lives/${live.id}/duplicate`)
    router.push(`/gestion/live/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// ── Préparer un live ──
function startNew() {
  const date = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' })
  Object.assign(form, { title: `Live du ${date}`, stream_url: '', hold_minutes: 120, items: [] })
  errors.value = {}
  editing.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// Choix des articles : liste à cocher (recherche), plusieurs d'un coup
const picking = ref(false)
const search = ref('')
const results = ref([])
let searchTimer
function find() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    results.value = (await http.get('/lives/variants', { params: { search: search.value || undefined } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}
function openPicker() {
  picking.value = true
  find()
}
const chosen = (id) => form.items.some((i) => i.variant_id === id)
function toggle(v) {
  const index = form.items.findIndex((i) => i.variant_id === v.id)
  if (index >= 0) form.items.splice(index, 1)
  else form.items.push({ variant_id: v.id, product_id: v.product_id, title: v.title, image: v.image, price: v.price, quantity: Math.max(1, Math.min(v.available || 1, 10)), available: v.available })
}
// Résultats regroupés par produit : « Tout le produit » coche toutes ses tailles et couleurs en stock
const productsFound = computed(() => {
  const groups = []
  results.value.forEach((v) => {
    const group = groups.find((g) => g.id === v.product_id)
    if (group) group.variants.push(v)
    else groups.push({ id: v.product_id, title: v.product ?? v.title, variants: [v] })
  })
  return groups
})
function toggleProduct(group) {
  const all = group.variants.filter((v) => v.available > 0).length ? group.variants.filter((v) => v.available > 0) : group.variants
  const every = all.every((v) => chosen(v.id))
  all.forEach((v) => chosen(v.id) === every && toggle(v))
}
function move(index, step) {
  const target = index + step
  if (target < 0 || target >= form.items.length) return
  const [item] = form.items.splice(index, 1)
  form.items.splice(target, 0, item)
}

// A1…A9, B1… : même règle que le serveur ; les variantes d'un même produit partagent le code
const codeAt = (i) => String.fromCharCode(65 + (Math.floor(i / 9) % 26)) + ((i % 9) + 1)
const code = (index) => {
  const products = []
  form.items.slice(0, index + 1).forEach((i) => products.includes(i.product_id ?? `v${i.variant_id}`) || products.push(i.product_id ?? `v${i.variant_id}`))
  return codeAt(products.indexOf(form.items[index].product_id ?? `v${form.items[index].variant_id}`))
}
const totals = computed(() => ({
  pieces: form.items.reduce((sum, i) => sum + (Number(i.quantity) || 0), 0),
  value: form.items.reduce((sum, i) => sum + (Number(i.quantity) || 0) * (Number(i.price) || 0), 0),
}))

async function save() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const { data } = await http.post('/lives', {
      title: form.title, stream_url: form.stream_url.trim() || null, hold_minutes: Number(form.hold_minutes),
      items: form.items.map((i) => ({ variant_id: i.variant_id, price: Number(i.price), quantity: Number(i.quantity) })),
    })
    router.push(`/gestion/live/${data.data.id}`)
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>

    <!-- ═══ Liste des lives ═══ -->
    <template v-if="!editing">
      <!-- Live en cours : au style de l'accueil -->
      <section v-if="current" class="relative overflow-hidden rounded-[24px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 p-5 text-white">
        <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><span class="h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT · {{ length(current.minutes) }}</span>
        <h3 class="mt-3 text-xl font-extrabold">{{ current.title }}</h3>
        <p class="mt-1 text-4xl font-extrabold">{{ money(current.summary?.amount ?? 0) }}</p>
        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ current.summary?.orders ?? 0 }}</b>commandes</span>
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ (current.summary?.pending ?? 0) + (current.summary?.waiting ?? 0) }}</b>à traiter</span>
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ current.summary?.sold_out ?? 0 }}</b>épuisé(s)</span>
        </div>
        <RouterLink :to="`/gestion/live/${current.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-ocre-700">Revenir au live</RouterLink>
      </section>

      <!-- Premier live : explication courte -->
      <section v-if="lives && !lives.length" class="card p-5">
        <h2 class="text-xl font-extrabold leading-tight">Vendez en live, sans rien noter</h2>
        <ol class="mt-4 space-y-3 text-[15px]">
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-sm font-extrabold text-emerald-800">A1</span>Chaque article reçoit un code</li>
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Icon name="share" :size="17" /></span>Le client réserve par le lien ou sur WhatsApp</li>
          <li class="flex items-center gap-3"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Icon name="check" :size="17" /></span>La commande se crée toute seule</li>
        </ol>
      </section>

      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-brand" @click="startNew"><Icon name="plus" :size="18" />Préparer un live</button>
        <button v-if="pageUrl" type="button" class="btn-secondary" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien des lives' }}</button>
        <button v-if="pageUrl" type="button" class="text-sm font-medium text-emerald-700 underline" @click="share = true">Message à épingler</button>
      </div>

      <LiveWhatsApp v-if="lives && whatsapp === null" :status="whatsapp" />

      <!-- Lives prêts -->
      <template v-if="drafts.length">
        <h3 class="px-0.5 pt-1 text-[17px] font-extrabold">Prêts à lancer</h3>
        <RouterLink v-for="l in drafts" :key="l.id" :to="`/gestion/live/${l.id}`" class="card flex items-center gap-3 p-4 hover:border-emerald-600">
          <ColorIcon name="live" :size="42" />
          <span class="min-w-0 flex-1"><b class="block truncate">{{ l.title }}</b><span class="text-sm text-slate-500">{{ l.items_count }} article(s) · en préparation</span></span>
          <span class="shrink-0 text-sm font-semibold text-emerald-700">Ouvrir</span>
        </RouterLink>
      </template>

      <!-- Lives passés : la date d'abord, le résultat, « Refaire ce live » -->
      <template v-if="past.length">
        <h3 class="px-0.5 pt-1 text-[17px] font-extrabold">Lives passés</h3>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
          <div v-for="l in past" :key="l.id" class="card flex min-w-0 items-center gap-3 p-4">
            <ColorIcon name="live" :size="42" />
            <RouterLink :to="`/gestion/live/${l.id}`" class="min-w-0 flex-1">
              <b class="block">{{ capitalize(day(l.started_at ?? l.ended_at)) }}</b>
              <span class="block truncate text-sm text-slate-500">{{ l.summary?.orders ?? 0 }} commande(s)<template v-if="l.minutes != null"> · {{ length(l.minutes) }}</template> · {{ l.title }}</span>
            </RouterLink>
            <span class="shrink-0 text-right">
              <b class="block">{{ money(l.summary?.amount ?? 0) }}</b>
              <button type="button" class="text-xs font-semibold text-emerald-700" :disabled="busy" @click="redo(l)">Refaire ce live</button>
            </span>
          </div>
        </div>
      </template>

      <!-- Formation repliée (ouverte pour un premier live) -->
      <details class="card p-4" :open="lives && !lives.length">
        <summary class="flex cursor-pointer items-center gap-3">
          <span class="text-2xl" aria-hidden="true">🎓</span>
          <span class="min-w-0 flex-1"><b class="block">Se former au live</b><span class="text-sm text-slate-500">12 vidéos d'une minute : préparer, animer, après le live</span></span>
          <Icon name="chevron" :size="16" class="text-slate-400" />
        </summary>
        <div class="mt-3"><LiveTraining :url="pageUrl" @prepare="startNew" /></div>
      </details>

      <MaterielTip category="live" text="Pour des lives nets et bien éclairés :" />
    </template>

    <!-- ═══ Préparer un live ═══ -->
    <form v-else class="space-y-4 pb-32" @submit.prevent="save">
      <section class="card space-y-3 p-4">
        <Field id="live-title" label="Nom du live" :error="errors.title"><input id="live-title" v-model="form.title" class="input" required maxlength="120" /></Field>
        <Field id="live-stream" label="Lien du live (TikTok, Facebook…), facultatif" :error="errors.stream_url" hint="Vos clients pourront revenir regarder le live depuis la page de réservation.">
          <input id="live-stream" v-model="form.stream_url" class="input" type="url" inputmode="url" placeholder="https://www.tiktok.com/@votreboutique/live" maxlength="500" />
        </Field>
        <details class="text-sm">
          <summary class="cursor-pointer font-medium text-emerald-700">Réglages avancés</summary>
          <Field id="live-hold" label="Pièce gardée sans quartier pendant (minutes)" :error="errors.hold_minutes" hint="Réservation WhatsApp : si le client ne donne pas son quartier à temps, la pièce va au suivant." class="mt-2">
            <input id="live-hold" v-model="form.hold_minutes" type="number" min="10" max="1440" class="input w-32" />
          </Field>
        </details>
      </section>

      <section class="card p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h3 class="font-extrabold">Articles du live</h3>
          <button type="button" class="btn-secondary px-3 py-1.5 text-sm" @click="openPicker">+ Choisir des articles</button>
        </div>
        <p class="mt-1 text-xs text-slate-500">Dans l'ordre où vous les présenterez : les flèches changent l'ordre et les codes.</p>
        <p v-if="!form.items.length" class="mt-4 rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500">Aucun article pour l'instant. Touchez « Choisir des articles ».</p>
        <ul class="mt-3 space-y-2">
          <li v-for="(i, index) in form.items" :key="i.variant_id" class="rounded-2xl border border-slate-200 p-2">
            <div class="flex items-center gap-3">
              <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-red-600 font-black text-white">{{ code(index) }}</span>
              <img v-if="i.image" :src="i.image" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover" />
              <span class="min-w-0 flex-1"><b class="block truncate text-sm" :title="i.title">{{ i.title }}</b><span class="text-xs text-slate-500">{{ i.available }} en stock</span></span>
              <span class="flex shrink-0 flex-col">
                <button type="button" class="px-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" :disabled="index === 0" aria-label="Monter" @click="move(index, -1)">▲</button>
                <button type="button" class="px-1 text-slate-400 hover:text-slate-700 disabled:opacity-30" :disabled="index === form.items.length - 1" aria-label="Descendre" @click="move(index, 1)">▼</button>
              </span>
              <button type="button" class="shrink-0 px-1 text-red-600" aria-label="Retirer" @click="form.items.splice(index, 1)">✕</button>
            </div>
            <div class="mt-2 flex flex-wrap gap-3 text-sm sm:pl-14">
              <label class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28 py-1" /></label>
              <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20 py-1" /></label>
            </div>
          </li>
        </ul>
        <p v-if="errors.items" class="mt-2 text-sm text-red-600">{{ errors.items }}</p>
      </section>

      <!-- Barre fixe : totaux et enregistrement -->
      <div class="fixed inset-x-0 bottom-[76px] z-20 border-t border-slate-200 bg-white/95 p-3 backdrop-blur lg:bottom-0 lg:left-64">
        <div class="mx-auto flex max-w-5xl items-center gap-3">
          <button type="button" class="btn-ghost px-2" aria-label="Annuler" @click="editing = false">✕</button>
          <span class="min-w-0 flex-1 text-sm leading-tight"><b class="block whitespace-nowrap">{{ form.items.length }} art. · {{ totals.pieces }} pièces</b><span class="whitespace-nowrap text-slate-500">{{ money(totals.value) }} en vente</span></span>
          <button class="btn-brand" :disabled="busy || !form.items.length">Enregistrer le live</button>
        </div>
      </div>
    </form>

    <!-- Choisir des articles : plusieurs d'un coup -->
    <Modal v-if="picking" title="Choisir les articles du live" @close="picking = false">
      <div class="space-y-3 text-sm">
        <input v-model="search" class="input" placeholder="Chercher un article (nom ou référence)" @input="find" />
        <ul class="max-h-[55vh] divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200">
          <li v-for="g in productsFound" :key="g.id">
            <!-- Produit avec tailles ou couleurs : tout d'un coup (un seul code), ou variante par variante -->
            <div v-if="g.variants.length > 1" class="flex items-center gap-3 bg-slate-50 p-2">
              <img v-if="g.variants[0].image" :src="g.variants[0].image" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
              <b class="min-w-0 flex-1 truncate">{{ g.title }}</b>
              <button type="button" class="shrink-0 rounded-lg bg-emerald-700 px-2.5 py-1.5 text-xs font-bold text-white" @click="toggleProduct(g)">
                {{ g.variants.filter((v) => v.available > 0 || !g.variants.some((x) => x.available > 0)).every((v) => chosen(v.id)) ? 'Tout retirer' : `Tout le produit (${g.variants.length})` }}
              </button>
            </div>
            <button v-for="v in g.variants" :key="v.id" type="button" class="flex w-full items-center gap-3 p-2 text-left hover:bg-slate-50" :class="g.variants.length > 1 ? 'pl-6' : ''" :aria-pressed="chosen(v.id)" @click="toggle(v)">
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border-2" :class="chosen(v.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'"><Icon v-if="chosen(v.id)" name="check" :size="14" /></span>
              <img v-if="v.image && g.variants.length === 1" :src="v.image" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
              <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ g.variants.length > 1 ? v.variant : v.title }}</span><span class="text-xs text-slate-500">{{ money(v.price) }} · {{ v.available }} en stock</span></span>
            </button>
          </li>
          <li v-if="!results.length" class="p-4 text-center text-slate-500">Aucun article trouvé.</li>
        </ul>
        <button type="button" class="btn-brand w-full" @click="picking = false">Terminé · {{ form.items.length }} article(s) choisi(s)</button>
      </div>
    </Modal>

    <Modal v-if="share" title="Le lien de vos lives" @close="share = false">
      <LiveLink :url="pageUrl" />
    </Modal>
  </div>
</template>
