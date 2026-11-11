<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Icon from './Icon.vue'
import LiveNewProduct from './LiveNewProduct.vue'
import LiveSignPicker from './LiveSignPicker.vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { money } from '../lib/format'

/**
 * « Préparer un live », étape 2 : fenêtre plein écran pour choisir les articles. Trois onglets : le catalogue (cocher,
 * pièces + / −), un live passé (« Reprendre » ses articles, son ordre et ses prix) et la sélection (ordre = codes A1,
 * A2…, prix du live, pièces, code à changer). « Valider » enregistre le live en préparation (créé s'il n'existe pas).
 * « Nouvel article » crée au catalogue un article qui n'y est pas (photo, fiche remplie par l'IA). Un article sans photo
 * ne passe pas en live : « Ajouter la photo » est proposé à sa place, et « Valider » attend les photos.
 */
const props = defineProps({
  draft: { type: Object, default: null }, // état du live en préparation (GET lives/{id}) ou null
  past: { type: Array, default: () => [] }, // lives terminés (liste de GET lives)
  channel: { type: String, default: 'live' }, // « whatsapp » : commandes WhatsApp sans live
})
const emit = defineEmits(['saved', 'close'])

const tab = ref('catalog')
const items = ref([])
const error = ref('')
const errors = ref({})
const busy = ref(false)

// Article chargé d'un live (en préparation ou passé) → ligne de la sélection
const fromState = (i) => ({ variant_id: i.variant_id, product_id: i.product_id, title: i.title, image: i.image, price: i.price, quantity: i.quantity, available: null })

onMounted(() => {
  if (props.draft?.items?.length) {
    items.value = props.draft.items.map(fromState)
    props.draft.items.forEach((i) => { signs[productKey(i)] = i.code })
    tab.value = 'selection'
  }
  find()
})

// ── Catalogue ──
const search = ref('')
const results = ref([])
let timer
function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    results.value = (await http.get('/lives/variants', { params: { search: search.value || undefined } }).catch(() => ({ data: { data: [] } }))).data.data
  }, 250)
}
const line = (id) => items.value.find((i) => i.variant_id === id)
function toggle(v) {
  const index = items.value.findIndex((i) => i.variant_id === v.id)
  if (index >= 0) items.value.splice(index, 1)
  else items.value.push({ variant_id: v.id, product_id: v.product_id, title: v.title, image: v.image, price: v.price, quantity: Math.max(1, Math.min(v.available || 1, 10)), available: v.available })
}
function step(v, delta) {
  const l = line(v.id)
  if (l) l.quantity = Math.max(1, (Number(l.quantity) || 1) + delta)
}
// ── Nouvel article (créé au catalogue puis ajouté, toutes ses variantes sous un code) ──
async function created({ id, title, quantity }) {
  const variants = (await http.get('/lives/variants', { params: { search: title } })).data.data.filter((v) => v.product_id === id)
  variants.forEach((v) => line(v.id) || items.value.push({ variant_id: v.id, product_id: v.product_id, title: v.title, image: v.image, price: v.price, quantity, available: v.available }))
  search.value = ''
  find()
  tab.value = 'selection'
}

// ── Photo manquante : ajoutée ici, puis reportée sur le catalogue et la sélection ──
const uploading = ref(null)
async function addPhoto(productId, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  uploading.value = productId
  error.value = ''
  try {
    const body = new FormData()
    body.append('image', file)
    const thumb = (await http.post(`/products/${productId}/images`, body)).data.data.urls.thumb
    results.value.filter((v) => v.product_id === productId).forEach((v) => { v.image = thumb })
    items.value.filter((i) => i.product_id === productId).forEach((i) => { i.image = thumb })
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    uploading.value = null
  }
}
const withoutPhoto = computed(() => [...new Set(items.value.filter((i) => !i.image).map((i) => productKey(i)))].length)

const groups = computed(() => {
  const list = []
  results.value.forEach((v) => {
    const g = list.find((x) => x.id === v.product_id)
    if (g) g.variants.push(v)
    else list.push({ id: v.product_id, title: v.product ?? v.title, variants: [v] })
  })
  return list
})
function toggleProduct(g) {
  const all = g.variants.filter((v) => v.available > 0).length ? g.variants.filter((v) => v.available > 0) : g.variants
  const every = all.every((v) => line(v.id))
  all.forEach((v) => !!line(v.id) === every && toggle(v))
}

// ── Live passé ──
const loading = ref(null)
const day = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' }) : '')
async function reuse(live) {
  loading.value = live.id
  error.value = ''
  try {
    const state = (await http.get(`/lives/${live.id}`)).data.data
    items.value = state.items.map(fromState)
    Object.keys(signs).forEach((k) => delete signs[k])
    state.items.forEach((i) => { signs[productKey(i)] = i.code })
    tab.value = 'selection'
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = null
  }
}

// ── Sélection : ordre = codes (A1…A9, B1…), code choisi gardé, variantes d'un produit sous le même code ──
const codeAt = (i) => String.fromCharCode(65 + (Math.floor(i / 9) % 26)) + ((i % 9) + 1)
const productKey = (i) => i.product_id ?? `v${i.variant_id}`
const signs = reactive({})
const codes = computed(() => {
  const result = {}
  const used = new Set()
  items.value.forEach((i) => {
    const key = productKey(i)
    if (signs[key] && !(key in result) && !used.has(signs[key])) {
      result[key] = signs[key]
      used.add(signs[key])
    }
  })
  let index = 0
  items.value.forEach((i) => {
    const key = productKey(i)
    if (key in result) return
    while (used.has(codeAt(index))) index++
    result[key] = codeAt(index)
    used.add(result[key])
  })
  return result
})
function move(index, delta) {
  const target = index + delta
  if (target < 0 || target >= items.value.length) return
  const [item] = items.value.splice(index, 1)
  items.value.splice(target, 0, item)
}
const signing = ref(null)
const usedByOthers = computed(() => {
  if (!signing.value) return {}
  const key = productKey(signing.value)
  return Object.fromEntries(items.value.filter((i) => productKey(i) !== key).map((i) => [codes.value[productKey(i)], i.title.split(' — ')[0]]))
})
function chooseSign(sign) {
  signs[productKey(signing.value)] = sign
  signing.value = null
}
const pieces = computed(() => items.value.reduce((sum, i) => sum + (Number(i.quantity) || 0), 0))
const signErrors = computed(() => Object.entries(errors.value).filter(([field]) => field.endsWith('.code')).map(([, m]) => m))

async function save() {
  busy.value = true
  error.value = ''
  errors.value = {}
  const date = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' })
  const payload = {
    title: props.draft?.title ?? (props.channel === 'whatsapp' ? `Commandes WhatsApp du ${date}` : `Live du ${date}`),
    channel: props.channel,
    hold_minutes: props.draft?.hold_minutes ?? 120,
    stream_url: props.draft?.stream_url ?? null,
    items: items.value.map((i) => ({ variant_id: i.variant_id, price: Number(i.price), quantity: Number(i.quantity), code: signs[productKey(i)] ? codes.value[productKey(i)] : null })),
  }
  try {
    const { data } = props.draft ? await http.put(`/lives/${props.draft.id}`, payload) : await http.post('/lives', payload)
    emit('saved', data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
    tab.value = 'selection'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex flex-col bg-white" role="dialog" aria-modal="true" aria-label="Choisis tes articles">
    <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
      <h2 class="text-[18px] font-black">{{ tab === 'new' ? 'Nouvel article' : 'Choisis tes articles' }}</h2>
      <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg" aria-label="Fermer" @click="emit('close')">✕</button>
    </header>
    <nav v-if="tab !== 'new'" class="grid grid-cols-3 gap-1.5 px-4 pt-3" role="tablist">
      <button v-for="[value, label] in [['catalog', 'Mon catalogue'], ['past', 'Un live passé'], ['selection', `Ma sélection (${items.length})`]]" :key="value" type="button" role="tab" :aria-selected="tab === value"
              class="rounded-xl py-2 text-[13px] font-extrabold" :class="tab === value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'" @click="tab = value">{{ label }}</button>
    </nav>

    <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
      <p v-if="error" class="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

      <!-- Nouvel article -->
      <LiveNewProduct v-if="tab === 'new'" @created="created" @cancel="tab = 'catalog'" />

      <!-- Catalogue -->
      <div v-else-if="tab === 'catalog'" class="space-y-3">
        <button type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 border-dashed border-emerald-300 bg-emerald-50 p-3 text-left" @click="tab = 'new'">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-700 text-2xl text-white">＋</span>
          <span class="min-w-0 flex-1"><b class="block text-[15px] text-emerald-900">Nouvel article</b><span class="text-xs text-emerald-800">Pas encore au catalogue ? Prends la photo, l'IA remplit la fiche.</span></span>
        </button>
        <input v-model="search" class="input" placeholder="🔍 Chercher un article" @input="find" />
        <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
          <li v-for="g in groups" :key="g.id">
            <div v-if="g.variants.length > 1" class="flex items-center gap-3 bg-slate-50 p-2">
              <img v-if="g.variants[0].image" :src="g.variants[0].image" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
              <label v-else class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border-2 border-dashed border-red-300 bg-red-50 text-base" :title="`Ajouter la photo de ${g.title}`">
                <input type="file" accept="image/*" class="sr-only" @change="addPhoto(g.id, $event)" />{{ uploading === g.id ? '…' : '📷' }}
              </label>
              <b class="min-w-0 flex-1 truncate text-sm">{{ g.title }}</b>
              <button type="button" class="shrink-0 rounded-lg bg-emerald-700 px-2.5 py-1.5 text-xs font-bold text-white" @click="toggleProduct(g)">Tout le produit</button>
            </div>
            <div v-for="v in g.variants" :key="v.id" class="flex items-center gap-3 p-2" :class="g.variants.length > 1 ? 'pl-5' : ''">
              <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :aria-pressed="!!line(v.id)" @click="toggle(v)">
                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-md border-2" :class="line(v.id) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-300'"><Icon v-if="line(v.id)" name="check" :size="14" /></span>
                <img v-if="v.image && g.variants.length === 1" :src="v.image" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover" />
                <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ g.variants.length > 1 ? v.variant : v.title }}</b><span class="text-xs text-slate-500">{{ money(v.price) }} · {{ v.available }} en stock</span></span>
              </button>
              <label v-if="!v.image && g.variants.length === 1" class="shrink-0 cursor-pointer rounded-lg border border-red-200 bg-red-50 px-2 py-1.5 text-xs font-bold text-red-700">
                <input type="file" accept="image/*" class="sr-only" @change="addPhoto(g.id, $event)" />{{ uploading === g.id ? 'Envoi…' : '📷 Photo' }}
              </label>
              <span v-if="line(v.id)" class="flex shrink-0 items-center gap-1.5 font-extrabold">
                <button type="button" class="grid h-7 w-7 place-items-center rounded-lg bg-slate-100" aria-label="Une pièce de moins" @click="step(v, -1)">−</button>
                <span class="w-6 text-center">{{ line(v.id).quantity }}</span>
                <button type="button" class="grid h-7 w-7 place-items-center rounded-lg bg-slate-100" aria-label="Une pièce de plus" @click="step(v, 1)">+</button>
              </span>
            </div>
          </li>
          <li v-if="!results.length" class="p-4 text-center text-sm text-slate-500">Aucun article trouvé.</li>
        </ul>
      </div>

      <!-- Live passé -->
      <div v-else-if="tab === 'past'" class="space-y-2">
        <p v-if="!past.length" class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500">Pas encore de live passé.</p>
        <div v-for="l in past" :key="l.id" class="flex items-center gap-3 rounded-xl border border-slate-200 p-3">
          <span class="min-w-0 flex-1"><b class="block first-letter:uppercase">{{ day(l.started_at ?? l.ended_at) }}</b><span class="text-sm text-slate-500">{{ l.items_count }} article(s) · {{ l.summary?.orders ?? 0 }} commande(s) · {{ money(l.summary?.amount ?? 0) }}</span></span>
          <button type="button" class="btn-brand shrink-0 px-3 py-2 text-sm" :disabled="loading === l.id" @click="reuse(l)">{{ loading === l.id ? '…' : 'Reprendre' }}</button>
        </div>
        <p v-if="past.length" class="text-sm text-slate-500">« Reprendre » remet les mêmes articles et prix.</p>
      </div>

      <!-- Sélection -->
      <div v-else class="space-y-2">
        <p v-if="!items.length" class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500">Aucun article. Coche-les dans « Mon catalogue » ou reprends un live passé.</p>
        <p v-else class="text-xs text-slate-500">L'ordre donne les codes (A1, A2…). Touche le code pour le changer.</p>
        <div v-for="(i, index) in items" :key="i.variant_id" class="rounded-2xl border border-slate-200 p-2">
          <div class="flex items-center gap-3">
            <button type="button" class="relative grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-red-600 font-black text-white" :aria-label="`Code ${codes[productKey(i)]} : changer`" @click="signing = i">
              {{ codes[productKey(i)] }}<span class="absolute -bottom-1 -right-1 grid h-4 w-4 place-items-center rounded-full bg-white text-[9px] text-red-600 shadow">✎</span>
            </button>
            <img v-if="i.image" :src="i.image" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover" />
            <label v-else class="grid h-11 w-11 shrink-0 cursor-pointer place-items-center rounded-xl border-2 border-dashed border-red-300 bg-red-50 text-lg" :aria-label="`Ajouter la photo de ${i.title.split(' — ')[0]}`">
              <input type="file" accept="image/*" class="sr-only" @change="addPhoto(i.product_id, $event)" />{{ uploading === i.product_id ? '…' : '📷' }}
            </label>
            <span class="min-w-0 flex-1"><b class="block truncate text-sm" :title="i.title">{{ i.title.split(' — ')[0] }}</b><span class="block truncate text-xs text-slate-500"><template v-if="i.title.includes(' — ')">{{ i.title.split(' — ').slice(1).join(' — ') }}</template><template v-if="i.title.includes(' — ') && i.available != null"> · </template><template v-if="i.available != null">{{ i.available }} en stock</template></span></span>
            <span class="flex shrink-0 flex-col">
              <button type="button" class="px-1 text-slate-400 disabled:opacity-30" :disabled="index === 0" aria-label="Monter" @click="move(index, -1)">▲</button>
              <button type="button" class="px-1 text-slate-400 disabled:opacity-30" :disabled="index === items.length - 1" aria-label="Descendre" @click="move(index, 1)">▼</button>
            </span>
            <button type="button" class="shrink-0 px-1 text-red-600" aria-label="Retirer" @click="items.splice(index, 1)">✕</button>
          </div>
          <label v-if="!i.image && i.product_id" class="mt-2 flex cursor-pointer items-center gap-2 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">
            <input type="file" accept="image/*" class="sr-only" @change="addPhoto(i.product_id, $event)" />
            <span class="min-w-0 flex-1">Sans photo, cet article ne peut pas passer en live.</span><b class="shrink-0">{{ uploading === i.product_id ? 'Envoi…' : '📷 Ajouter la photo' }}</b>
          </label>
          <div class="mt-2 flex flex-wrap gap-3 pl-14 text-sm">
            <label class="flex items-center gap-1">Prix <input v-model="i.price" type="number" min="0" class="input w-28 py-1" /></label>
            <label class="flex items-center gap-1">Pièces <input v-model="i.quantity" type="number" min="1" class="input w-20 py-1" /></label>
          </div>
        </div>
        <p v-if="errors.items" class="text-sm text-red-600">{{ errors.items }}</p>
        <p v-for="m in signErrors" :key="m" class="text-sm text-red-600">{{ m }}</p>
      </div>
    </div>

    <footer v-if="tab !== 'new'" class="border-t border-slate-200 p-3">
      <button v-if="withoutPhoto" type="button" class="w-full rounded-xl bg-red-50 py-3.5 text-[15px] font-black text-red-700" @click="tab = 'selection'">
        📷 Ajoute la photo de {{ withoutPhoto }} article(s) pour valider
      </button>
      <button v-else type="button" class="btn-brand w-full justify-center py-3.5 text-[16px] font-black" :disabled="busy || !items.length" @click="save">
        {{ busy ? 'Enregistrement…' : `Valider · ${items.length} article(s), ${pieces} pièces` }}
      </button>
    </footer>

    <LiveSignPicker v-if="signing" :title="signing.title.split(' — ')[0]" :current="codes[productKey(signing)]" :used="usedByOthers" @pick="chooseSign" @close="signing = null" />
  </div>
</template>
