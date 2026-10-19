<script setup>
import Icon from '../../../components/Icon.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import BundleEditor from '../../../components/BundleEditor.vue'
import ChipsInput from '../../../components/ChipsInput.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import SocialPublish from '../../../components/SocialPublish.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { combinations, money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

const props = defineProps({ id: { type: String, default: null } })
const auth = useAuthStore()
const router = useRouter()

const canEdit = computed(() => auth.can('products.manage'))
const canStock = computed(() => auth.can('stock.manage'))

const OPTION_SUGGESTIONS = ['Taille', 'Couleur', 'Pointure', 'Matière', 'Modèle', 'Contenance']
const VALUE_SUGGESTIONS = {
  taille: ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
  pointure: ['38', '39', '40', '41', '42', '43', '44'],
  couleur: ['Noir', 'Blanc', 'Rouge', 'Bleu', 'Vert', 'Jaune', 'Rose', 'Marron'],
}

const loading = ref(!!props.id)
const saving = ref(false)
const error = ref('')
const errors = ref({})
const saved = ref(false)

const form = reactive({
  title: '',
  description: '',
  brand: '',
  status: 'active',
  is_visible_online: true,
  track_inventory: true,
  allow_backorder: false,
  preorder_enabled: false,
  preorder_ship_date: '',
  preorder_deposit_percent: null,
  requires_shipping: true,
  is_fragile: false,
  low_stock_threshold: null,
  seo_title: '',
  seo_description: '',
  collection_ids: [],
  tags: [],
})
const product = ref(null) // fiche chargée (photos, adresse publique)
const hasVariants = ref(false)
const options = ref([{ name: 'Taille', values: [] }])
const rows = reactive({}) // variantes par combinaison : « rouge||m » → ligne
const simple = reactive(newRow([]))
const images = ref([])
const pendingFiles = ref([]) // photos choisies avant la création du produit
const uploading = ref(false)
const collections = ref([])
const tagSuggestions = ref([])
const locations = ref([])
const locationId = ref(null)
const bulk = reactive({ price: '', on_hand: '' })
const showSeo = ref(false)

function newRow(combo) {
  return { id: null, options: combo, include: true, price: '', compare_at_price: '', cost_price: '', sku: '', barcode: '', weight_g: '', image_id: null, is_active: true, on_hand: '', original_on_hand: null, levels: [] }
}

const keyOf = (combo) => combo.map((v) => v.toLowerCase()).join('||')

const activeOptions = computed(() => options.value.filter((o) => o.name.trim() && o.values.length))
const combos = computed(() => (hasVariants.value && activeOptions.value.length ? combinations(activeOptions.value.map((o) => o.values)) : []))

// Une ligne par combinaison ; les lignes déjà saisies gardent leurs valeurs
watch(combos, (list) => {
  for (const combo of list) {
    rows[keyOf(combo)] ??= newRow(combo)
    rows[keyOf(combo)].options = combo
  }
}, { immediate: true })

const variantRows = computed(() => combos.value.map((combo) => rows[keyOf(combo)]).filter(Boolean))

function levelAt(variant) {
  return variant.levels.find((l) => l.location_id === locationId.value)?.on_hand ?? 0
}

function fillRow(row, v) {
  Object.assign(row, {
    id: v.id, include: true, price: v.price, compare_at_price: v.compare_at_price ?? '', cost_price: v.cost_price ?? '', sku: v.sku ?? '',
    barcode: v.barcode ?? '', weight_g: v.weight_g ?? '', image_id: v.image_id, is_active: v.is_active, levels: v.levels,
  })
}

function applyLocation() {
  for (const row of [simple, ...Object.values(rows)]) {
    if (row.id) {
      row.on_hand = levelAt(row)
      row.original_on_hand = row.on_hand
    }
  }
}
watch(locationId, applyLocation)

function load(data) {
  product.value = data
  Object.keys(form).forEach((k) => (form[k] = data[k] ?? form[k]))
  form.collection_ids = [...data.collection_ids]
  form.tags = [...data.tags]
  images.value = data.images
  hasVariants.value = data.has_variants
  Object.keys(rows).forEach((k) => delete rows[k])
  if (data.has_variants) {
    options.value = data.options.map((o) => ({ name: o.name, values: o.values.map((v) => v.value) }))
    for (const v of data.variants) {
      rows[keyOf(v.options)] = newRow(v.options)
      fillRow(rows[keyOf(v.options)], v)
    }
    // Combinaisons non vendues : décochées
    for (const combo of combinations(options.value.map((o) => o.values))) {
      if (!rows[keyOf(combo)]) rows[keyOf(combo)] = { ...newRow(combo), include: false }
    }
  } else {
    options.value = [{ name: 'Taille', values: [] }]
    Object.assign(simple, newRow([]))
    if (data.variants[0]) fillRow(simple, data.variants[0])
  }
  applyLocation()
}

onMounted(async () => {
  const requests = [http.get('/collections'), http.get('/tags')]
  if (canStock.value) requests.push(http.get('/stock/locations'))
  const [c, t, l] = await Promise.all(requests)
  collections.value = c.data.data
  tagSuggestions.value = t.data.data
  locations.value = (l?.data.data ?? []).filter((x) => x.is_active)
  locationId.value = locations.value[0]?.id ?? null

  if (props.id) {
    try {
      load((await http.get(`/products/${props.id}`)).data.data)
    } catch (e) {
      error.value = errorMessage(e)
    } finally {
      loading.value = false
    }
  }
})

function addOption() {
  const used = options.value.map((o) => o.name.toLowerCase())
  options.value.push({ name: OPTION_SUGGESTIONS.find((s) => !used.includes(s.toLowerCase())) ?? '', values: [] })
}

function applyBulk() {
  for (const row of variantRows.value.filter((r) => r.include)) {
    if (bulk.price !== '') row.price = Number(bulk.price)
    if (bulk.on_hand !== '' && canStock.value) row.on_hand = Number(bulk.on_hand)
  }
}

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

function variantPayload(row) {
  const payload = {
    id: row.id ?? undefined,
    options: row.options,
    price: num(row.price) ?? 0,
    compare_at_price: num(row.compare_at_price),
    cost_price: num(row.cost_price),
    sku: row.sku || null,
    barcode: row.barcode || null,
    weight_g: num(row.weight_g),
    image_id: row.image_id,
    is_active: row.is_active,
  }
  // Quantité envoyée seulement si elle a changé (elle remplace le stock de l'emplacement choisi)
  if (canStock.value && row.on_hand !== '' && Number(row.on_hand) !== row.original_on_hand) payload.on_hand = Number(row.on_hand)
  return payload
}

async function save() {
  saving.value = true
  error.value = ''
  errors.value = {}
  saved.value = false
  try {
    const payload = {
      ...form,
      low_stock_threshold: num(form.low_stock_threshold),
      preorder_ship_date: form.preorder_ship_date || null,
      preorder_deposit_percent: num(form.preorder_deposit_percent),
      options: hasVariants.value ? activeOptions.value : [],
      variants: hasVariants.value ? variantRows.value.filter((r) => r.include).map(variantPayload) : [variantPayload(simple)],
      location_id: locationId.value,
    }
    const { data } = props.id ? await http.put(`/products/${props.id}`, payload) : await http.post('/products', payload)

    if (!props.id) {
      await uploadFiles(data.data.id, pendingFiles.value.map((p) => p.file))
      pendingFiles.value = []
      router.replace(`/gestion/catalogue/${data.data.id}`)
      return
    }
    load(data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

async function uploadFiles(productId, files) {
  uploading.value = true
  try {
    for (const file of files) {
      const body = new FormData()
      body.append('image', file)
      const { data } = await http.post(`/products/${productId}/images`, body)
      images.value.push(data.data)
    }
  } catch (e) {
    error.value = errorMessage(e, 'Une photo n\'a pas pu être envoyée.')
  } finally {
    uploading.value = false
  }
}

async function onFiles(event) {
  const files = [...event.target.files]
  event.target.value = ''
  if (props.id) await uploadFiles(props.id, files)
  else pendingFiles.value.push(...files.map((file) => ({ file, url: URL.createObjectURL(file) })))
}

// Assistant IA : fiche proposée à partir des photos choisies (le marchand fixe le prix et valide)
const ai = computed(() => auth.profile?.ai ?? { enabled: false, used: 0, limit: 0 })
const aiNote = ref('')
const drafting = ref(false)
const aiNotice = ref('')
async function draftFromPhotos() {
  drafting.value = true
  aiNotice.value = ''
  error.value = ''
  try {
    const body = new FormData()
    pendingFiles.value.slice(0, 3).forEach((p) => body.append('photos[]', p.file))
    if (aiNote.value.trim()) body.append('note', aiNote.value.trim())
    const d = (await http.post('/products/draft', body)).data.data
    Object.assign(form, { title: d.title, description: d.description, brand: d.brand ?? '', seo_title: d.seo_title, seo_description: d.seo_description })
    form.tags = [...new Set([...form.tags, ...d.tags])]
    form.collection_ids = [...new Set([...form.collection_ids, ...d.collection_ids])]
    if (d.options.length) {
      hasVariants.value = true
      options.value = d.options.map((o) => ({ name: o.name, values: [...o.values] }))
    }
    aiNotice.value = `Fiche proposée : relisez-la, puis indiquez le prix${d.options.length ? ' de chaque variante' : ''} et le stock. ${d.to_check ?? ''}`.trim()
    if (auth.profile?.ai) auth.profile.ai.used++
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    drafting.value = false
  }
}

async function moveImage(index, delta) {
  const list = [...images.value]
  const [item] = list.splice(index, 1)
  list.splice(index + delta, 0, item)
  images.value = list
  await http.put(`/products/${props.id}/images/order`, { ids: list.map((i) => i.id) })
}

async function removeImage(image) {
  if (!confirm('Supprimer cette photo ?')) return
  await http.delete(`/products/${props.id}/images/${image.id}`)
  images.value = images.value.filter((i) => i.id !== image.id)
  for (const row of [simple, ...Object.values(rows)]) if (row.image_id === image.id) row.image_id = null
}

async function duplicate() {
  const { data } = await http.post(`/products/${props.id}/duplicate`)
  router.push(`/gestion/catalogue/${data.data.id}`)
}

async function destroy() {
  if (!confirm(`Supprimer « ${form.title} » ? Son stock sera remis à zéro.`)) return
  try {
    await http.delete(`/products/${props.id}`)
    router.replace('/gestion/catalogue')
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Prix par quantité (« 3 pour 25 000 F ») : enregistrés à part
const tiers = ref([])
const tiersSaved = ref(false)
const tiersErrors = ref({})
async function loadTiers() {
  if (props.id) tiers.value = (await http.get(`/products/${props.id}/volume-prices`)).data.data
}
async function saveTiers() {
  tiersSaved.value = false
  tiersErrors.value = {}
  try {
    tiers.value = (await http.put(`/products/${props.id}/volume-prices`, {
      tiers: tiers.value.filter((t) => t.min_quantity && t.price).map((t) => ({ min_quantity: Number(t.min_quantity), price: Number(t.price) })),
    })).data.data
    tiersSaved.value = true
  } catch (e) {
    tiersErrors.value = fieldErrors(e)
    if (!Object.keys(tiersErrors.value).length) tiersErrors.value = { form: errorMessage(e) }
  }
}
onMounted(loadTiers)

// Autres boutiques de l'adresse : le produit y est-il ? copie (photos, variantes, prix ; stock à zéro)
const otherStores = ref([])
const copying = ref(null)
const copied = ref('')
async function loadOtherStores() {
  if (props.id && (auth.profile?.stores?.length ?? 0) > 1) otherStores.value = (await http.get(`/products/${props.id}/stores`)).data.data
}
async function copyTo(store) {
  copying.value = store.id
  copied.value = ''
  error.value = ''
  try {
    const { data } = await http.post(`/products/${props.id}/copy`, { store_id: store.id })
    store.product_id = data.data.id
    copied.value = data.message
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    copying.value = null
  }
}
onMounted(loadOtherStores)

// Partage sur les réseaux : lien court et image carrée
const share = ref(null)
const publishing = ref(false)
const sharing = ref(false)
async function openShare() {
  sharing.value = true
  error.value = ''
  try {
    share.value = (await http.post(`/products/${props.id}/share`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    sharing.value = false
  }
}
const shareCopied = ref(false)
async function copyShare(text) {
  await navigator.clipboard?.writeText(text)
  shareCopied.value = true
  setTimeout(() => (shareCopied.value = false), 2000)
}

const variantError = (index, field) => errors.value[`variants.${index}.${field}`]
const margin = computed(() => {
  const price = num(simple.price)
  const cost = num(simple.cost_price)
  return price && cost !== null ? Math.round(((price - cost) / price) * 100) : null
})
</script>

<template>
  <p v-if="loading" class="text-sm text-slate-500">Chargement…</p>

  <form v-else class="space-y-5 pb-24" @submit.prevent="save">
    <div class="flex flex-wrap items-center gap-2">
      <RouterLink to="/gestion/catalogue" class="text-sm text-slate-500 hover:text-emerald-700">← Mes produits</RouterLink>
      <a v-if="product && form.status === 'active' && form.is_visible_online" :href="product.url" target="_blank" class="ml-auto text-sm text-slate-500 hover:text-emerald-700">Voir sur le site ↗</a>
      <button v-if="product && form.status === 'active' && form.is_visible_online" type="button" class="btn-secondary px-3 py-1.5" :disabled="sharing" @click="openShare"><Icon name="share" :size="16" class="mr-1 inline-block align-[-3px]" />Partager</button>
      <button v-if="product && form.status === 'active' && form.is_visible_online && auth.can('store.manage')" type="button" class="btn-secondary px-3 py-1.5" @click="publishing = true"><Icon name="megaphone" :size="16" class="mr-1 inline-block align-[-3px]" />Publier sur les réseaux</button>
    </div>

    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">Produit enregistré.</Alert>

    <section v-if="ai.enabled && !props.id && canEdit" class="card space-y-3 border-emerald-200 bg-emerald-50/60 p-4 sm:p-6">
      <div>
        <h2 class="font-semibold"><Icon name="sparkles" :size="18" class="mr-1 inline-block align-[-3px]" />Remplir la fiche avec une photo</h2>
        <p class="text-sm text-slate-600">Prenez le produit en photo : l'assistant propose le nom, la description, les tailles ou couleurs et les mots-clés. Vous fixez le prix et vérifiez avant d'enregistrer.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="btn-secondary cursor-pointer px-3 py-1.5">
          <Icon name="image" :size="16" class="mr-1 inline-block align-[-3px]" />Photo
          <input type="file" accept="image/*" capture="environment" class="hidden" @change="onFiles" />
        </label>
        <label class="btn-secondary cursor-pointer px-3 py-1.5">
          + Galerie
          <input type="file" accept="image/*" multiple class="hidden" @change="onFiles" />
        </label>
        <span class="text-sm text-slate-500">{{ pendingFiles.length ? `${Math.min(pendingFiles.length, 3)} photo(s) pour l'assistant` : 'Aucune photo choisie' }}</span>
      </div>
      <input v-model="aiNote" class="input" maxlength="1000" placeholder="Précision facultative : matière, tailles disponibles, marque…" />
      <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="btn-brand" :disabled="drafting || !pendingFiles.length || (ai.limit !== null && ai.used >= ai.limit)" @click="draftFromPhotos">
          {{ drafting ? 'L\'assistant regarde la photo…' : 'Proposer la fiche' }}
        </button>
        <span class="text-xs text-slate-500">{{ ai.used }}{{ ai.limit !== null ? ` / ${ai.limit}` : '' }} action(s) de l'assistant ce mois-ci</span>
      </div>
      <Alert v-if="aiNotice" tone="success">{{ aiNotice }}</Alert>
    </section>

    <fieldset :disabled="!canEdit" class="space-y-5">
      <section class="card space-y-4 p-4 sm:p-6">
        <Field id="title" label="Nom du produit" :error="errors.title">
          <input id="title" v-model="form.title" class="input" maxlength="255" placeholder="Robe wax évasée" required />
        </Field>
        <Field id="description" label="Description" :error="errors.description" hint="Matière, coupe, entretien, conseils de taille…">
          <textarea id="description" v-model="form.description" class="input min-h-28" rows="5" />
        </Field>
        <Field id="brand" label="Marque (facultatif)" :error="errors.brand">
          <input id="brand" v-model="form.brand" class="input" maxlength="80" />
        </Field>
      </section>

      <section class="card space-y-3 p-4 sm:p-6">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">Photos</h2>
          <div v-if="canEdit" class="flex gap-2">
            <!-- Sur téléphone, le choix de fichier n'ouvre que la galerie : l'appareil photo a son propre bouton -->
            <label class="btn-secondary cursor-pointer px-3 py-1.5" :class="{ 'opacity-60': uploading }">
              <Icon name="image" :size="16" class="mr-1 inline-block align-[-3px]" />Photo
              <input type="file" accept="image/*" capture="environment" class="hidden" :disabled="uploading" @change="onFiles" />
            </label>
            <label class="btn-secondary cursor-pointer px-3 py-1.5">
              {{ uploading ? 'Envoi…' : '+ Galerie' }}
              <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" @change="onFiles" />
            </label>
          </div>
        </div>
        <p v-if="!images.length && !pendingFiles.length" class="text-sm text-slate-500">La première photo est la photo principale. Prenez-les à la lumière du jour, sur fond uni.</p>
        <div class="grid grid-cols-3 gap-3 sm:grid-cols-5">
          <div v-for="(image, index) in images" :key="image.id" class="group relative">
            <img :src="image.urls.thumb" :alt="image.alt" class="aspect-square w-full rounded-lg border border-slate-200 object-cover" />
            <span v-if="index === 0" class="absolute left-1 top-1 rounded bg-white/90 px-1.5 text-[10px] font-medium">Principale</span>
            <div v-if="canEdit" class="mt-1 flex justify-center gap-1 text-xs">
              <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === 0" @click="moveImage(index, -1)">←</button>
              <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" @click="removeImage(image)">Suppr.</button>
              <button type="button" class="btn-ghost px-1.5 py-0.5" :disabled="index === images.length - 1" @click="moveImage(index, 1)">→</button>
            </div>
          </div>
          <div v-for="pending in pendingFiles" :key="pending.url">
            <img :src="pending.url" alt="" class="aspect-square w-full rounded-lg border border-dashed border-slate-300 object-cover opacity-80" />
            <p class="mt-1 text-center text-xs text-slate-500">À l'enregistrement</p>
          </div>
        </div>
      </section>

      <section class="card space-y-4 p-4 sm:p-6">
        <div class="flex items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold">Prix et variantes</h2>
            <p class="text-sm text-slate-500">Tailles, couleurs, pointures… Chaque variante a son prix, son stock et sa photo.</p>
          </div>
          <label class="flex shrink-0 items-center gap-2 text-sm">
            <input v-model="hasVariants" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Plusieurs variantes
          </label>
        </div>

        <div v-if="canStock && locations.length > 1" class="flex items-center gap-2 text-sm">
          <span class="text-slate-600">Quantités pour :</span>
          <select v-model="locationId" class="input w-auto py-1.5">
            <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </div>

        <!-- Produit simple -->
        <div v-if="!hasVariants" class="grid gap-4 sm:grid-cols-3">
          <Field id="price" label="Prix (F)" :error="variantError(0, 'price')">
            <input id="price" v-model="simple.price" class="input" type="number" min="0" inputmode="numeric" required />
          </Field>
          <Field id="compare" label="Prix barré" hint="Ancien prix affiché barré">
            <input id="compare" v-model="simple.compare_at_price" class="input" type="number" min="0" inputmode="numeric" />
          </Field>
          <Field id="cost" label="Coût d'achat" :hint="margin !== null ? `Marge : ${margin} %` : 'Pour calculer votre marge'">
            <input id="cost" v-model="simple.cost_price" class="input" type="number" min="0" inputmode="numeric" />
          </Field>
          <Field v-if="canStock && form.track_inventory" id="stock" label="Stock">
            <input id="stock" v-model="simple.on_hand" class="input" type="number" min="0" inputmode="numeric" />
          </Field>
          <Field id="sku" label="SKU (référence)" :error="variantError(0, 'sku')">
            <input id="sku" v-model="simple.sku" class="input" maxlength="60" />
          </Field>
          <Field id="barcode" label="Code-barres">
            <input id="barcode" v-model="simple.barcode" class="input" maxlength="60" />
          </Field>
          <Field id="weight" label="Poids (g)" hint="Pour les frais de livraison">
            <input id="weight" v-model="simple.weight_g" class="input" type="number" min="0" inputmode="numeric" />
          </Field>
        </div>

        <!-- Options et variantes -->
        <template v-else>
          <div class="space-y-3">
            <div v-for="(option, index) in options" :key="index" class="rounded-lg border border-slate-200 p-3">
              <div class="flex items-center gap-2">
                <input v-model="option.name" class="input" maxlength="40" placeholder="Nom de l'option (ex. Taille)" :list="`options-${index}`" />
                <datalist :id="`options-${index}`"><option v-for="s in OPTION_SUGGESTIONS" :key="s" :value="s" /></datalist>
                <button type="button" class="btn-ghost px-2 text-red-600" :aria-label="`Retirer ${option.name}`" @click="options.splice(index, 1)">✕</button>
              </div>
              <ChipsInput
                v-model="option.values"
                class="mt-2"
                placeholder="Valeurs : tapez puis Entrée (S, M, L…)"
                :suggestions="VALUE_SUGGESTIONS[option.name.trim().toLowerCase()] ?? []"
              />
            </div>
            <button v-if="options.length < 3" type="button" class="btn-secondary px-3 py-1.5" @click="addOption">+ Ajouter une option</button>
            <p v-if="errors.options" class="text-sm text-red-600">{{ errors.options }}</p>
          </div>

          <div v-if="variantRows.length" class="space-y-3">
            <div class="flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3">
              <span class="w-full text-sm font-medium text-slate-700 sm:w-auto sm:pb-2">Pour toutes les variantes :</span>
              <input v-model="bulk.price" class="input w-32" type="number" min="0" placeholder="Prix" />
              <input v-if="canStock && form.track_inventory" v-model="bulk.on_hand" class="input w-28" type="number" min="0" placeholder="Stock" />
              <button type="button" class="btn-secondary" @click="applyBulk">Appliquer</button>
            </div>

            <p v-if="errors.variants" class="text-sm text-red-600">{{ errors.variants }}</p>

            <div class="divide-y divide-slate-100 rounded-lg border border-slate-200">
              <div v-for="(row, index) in variantRows" :key="row.options.join('|')" class="grid grid-cols-2 gap-2 p-3 sm:grid-cols-[minmax(8rem,1.5fr)_repeat(4,minmax(0,1fr))_auto] sm:items-center" :class="{ 'bg-slate-50 opacity-60': !row.include }">
                <label class="col-span-2 flex items-center gap-2 font-medium sm:col-span-1">
                  <input v-model="row.include" type="checkbox" class="h-4 w-4 accent-emerald-700" />
                  {{ row.options.join(' / ') }}
                </label>
                <template v-if="row.include">
                  <label class="text-xs text-slate-500">Prix
                    <input v-model="row.price" class="input mt-0.5" type="number" min="0" inputmode="numeric" required />
                    <span v-if="variantError(variantRows.filter((r) => r.include).indexOf(row), 'price')" class="text-red-600">{{ variantError(variantRows.filter((r) => r.include).indexOf(row), 'price') }}</span>
                  </label>
                  <label class="text-xs text-slate-500">Prix barré
                    <input v-model="row.compare_at_price" class="input mt-0.5" type="number" min="0" inputmode="numeric" />
                  </label>
                  <label class="text-xs text-slate-500">SKU
                    <input v-model="row.sku" class="input mt-0.5" maxlength="60" />
                    <span v-if="variantError(variantRows.filter((r) => r.include).indexOf(row), 'sku')" class="text-red-600">{{ variantError(variantRows.filter((r) => r.include).indexOf(row), 'sku') }}</span>
                  </label>
                  <label v-if="canStock && form.track_inventory" class="text-xs text-slate-500">Stock
                    <input v-model="row.on_hand" class="input mt-0.5" type="number" min="0" inputmode="numeric" />
                  </label>
                  <span v-else />
                  <label class="col-span-2 text-xs text-slate-500 sm:col-span-1">Photo
                    <select v-model="row.image_id" class="input mt-0.5" :disabled="!images.length">
                      <option :value="null">—</option>
                      <option v-for="(image, i) in images" :key="image.id" :value="image.id">Photo {{ i + 1 }}</option>
                    </select>
                  </label>
                </template>
                <span v-else class="col-span-2 text-sm text-slate-500 sm:col-span-6">Non vendue</span>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-slate-500">Ajoutez une option et ses valeurs : les variantes apparaissent ici.</p>
        </template>
      </section>

      <section class="grid gap-5 lg:grid-cols-2">
        <div class="card space-y-4 p-4 sm:p-6">
          <h2 class="font-semibold">Organisation</h2>
          <Field id="status" label="Statut">
            <select id="status" v-model="form.status" class="input">
              <option value="active">Actif (en vente)</option>
              <option value="draft">Brouillon</option>
              <option value="archived">Archivé</option>
            </select>
          </Field>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.is_visible_online" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Visible sur la boutique en ligne
          </label>
          <div v-if="collections.length">
            <p class="label">Collections</p>
            <div class="flex flex-wrap gap-2">
              <label v-for="c in collections" :key="c.id" class="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm">
                <input v-model="form.collection_ids" type="checkbox" :value="c.id" class="accent-emerald-700" />
                {{ c.title }}
              </label>
            </div>
          </div>
          <Field id="tags" label="Étiquettes">
            <ChipsInput id="tags" v-model="form.tags" placeholder="promo, nouveauté…" :suggestions="tagSuggestions" />
          </Field>
        </div>

        <div class="card space-y-4 p-4 sm:p-6">
          <h2 class="font-semibold">Stock et livraison</h2>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.track_inventory" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Suivre le stock de ce produit
          </label>
          <label v-if="form.track_inventory" class="flex items-center gap-2 text-sm">
            <input v-model="form.allow_backorder" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Continuer à vendre en rupture de stock
          </label>
          <Field v-if="form.track_inventory" id="threshold" label="Alerte de stock bas" :hint="`Vide = seuil de la boutique (${auth.store?.low_stock_threshold ?? 3})`">
            <input id="threshold" v-model="form.low_stock_threshold" class="input" type="number" min="0" />
          </Field>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.is_fragile" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            Colis fragile
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="form.preorder_enabled" type="checkbox" class="h-4 w-4 accent-emerald-700" />
            En précommande (vendu avant son arrivée)
          </label>
          <div v-if="form.preorder_enabled" class="grid gap-3 sm:grid-cols-2">
            <Field id="po-ship" label="Expédition prévue vers le"><input id="po-ship" v-model="form.preorder_ship_date" type="date" class="input" /></Field>
            <Field id="po-dep" label="Acompte demandé (%)" hint="Vide : pas d'acompte. Payé en ligne à la commande si vous avez un compte de paiement.">
              <input id="po-dep" v-model="form.preorder_deposit_percent" type="number" min="1" max="100" class="input" />
            </Field>
          </div>
        </div>
      </section>

      <BundleEditor v-if="props.id && !hasVariants" :product-id="props.id" />

      <section v-if="props.id" class="card space-y-3 p-4 sm:p-6">
        <div>
          <h2 class="font-semibold">Prix par quantité</h2>
          <p class="text-sm text-slate-500">Ex. 3 pour 25 000 F : appliqué au panier, toutes tailles et couleurs confondues, si c'est plus avantageux pour le client.</p>
        </div>
        <Alert v-if="tiersErrors.form">{{ tiersErrors.form }}</Alert>
        <div v-for="(t, i) in tiers" :key="i" class="flex flex-wrap items-center gap-2 text-sm">
          <input v-model="t.min_quantity" type="number" min="2" class="input w-20" aria-label="Quantité" />
          <span>pour</span>
          <input v-model="t.price" type="number" min="1" class="input w-32" aria-label="Prix du lot" />
          <span>F</span>
          <span v-if="t.min_quantity > 1 && t.price" class="text-slate-500">soit {{ money(Math.round(t.price / t.min_quantity)) }} l'unité</span>
          <button type="button" class="btn-ghost px-2 text-red-600" aria-label="Retirer" @click="tiers.splice(i, 1)">✕</button>
          <p v-if="tiersErrors[`tiers.${i}.price`] || tiersErrors[`tiers.${i}.min_quantity`]" class="w-full text-red-700">{{ tiersErrors[`tiers.${i}.price`] || tiersErrors[`tiers.${i}.min_quantity`] }}</p>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" class="btn-ghost text-emerald-700" @click="tiers.push({ min_quantity: (tiers.at(-1)?.min_quantity ?? 1) + 1, price: '' })">+ Palier</button>
          <button type="button" class="btn-secondary ml-auto px-3 py-1.5" @click="saveTiers">Enregistrer les prix par quantité</button>
          <span v-if="tiersSaved" class="text-sm text-emerald-700">✓</span>
        </div>
      </section>

      <section class="card p-4 sm:p-6">
        <button type="button" class="flex w-full items-center justify-between font-semibold" @click="showSeo = !showSeo">
          Référencement (Google, partage WhatsApp)
          <span class="text-slate-400">{{ showSeo ? '−' : '+' }}</span>
        </button>
        <div v-if="showSeo" class="mt-4 space-y-4">
          <Field id="seo_title" label="Titre" :hint="`${(form.seo_title || form.title).length}/70`">
            <input id="seo_title" v-model="form.seo_title" class="input" maxlength="70" :placeholder="form.title" />
          </Field>
          <Field id="seo_description" label="Description" :hint="`${form.seo_description?.length ?? 0}/160`">
            <textarea id="seo_description" v-model="form.seo_description" class="input" rows="2" maxlength="160" />
          </Field>
        </div>
      </section>
    </fieldset>

    <section v-if="otherStores.length" class="card space-y-3 p-4 sm:p-6">
      <div>
        <h2 class="font-semibold">Dans vos autres boutiques</h2>
        <p class="text-sm text-slate-500">La copie reprend les photos, les variantes et les prix ; son stock part de zéro (envoyez-en depuis Stock › Transferts).</p>
      </div>
      <Alert v-if="copied" tone="success">{{ copied }}</Alert>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="s in otherStores" :key="s.id" class="flex items-center gap-3 py-2">
          <span class="min-w-0 flex-1 truncate font-medium">{{ s.name }}</span>
          <template v-if="s.product_id">
            <span class="text-emerald-700">✓ Présent</span>
            <button type="button" class="btn-secondary px-3 py-1.5" @click="auth.switchTo(s.id, `/gestion/catalogue/${s.product_id}`)">Ouvrir</button>
          </template>
          <button v-else-if="s.can_copy" type="button" class="btn-secondary px-3 py-1.5" :disabled="copying !== null" @click="copyTo(s)">
            {{ copying === s.id ? 'Copie…' : 'Copier ici' }}
          </button>
          <span v-else class="text-slate-400">Absent</span>
        </li>
      </ul>
    </section>

    <div v-if="canEdit" class="fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
      <div class="mx-auto flex max-w-5xl items-center gap-2">
        <template v-if="props.id">
          <button type="button" class="btn-ghost text-red-600" @click="destroy">Supprimer</button>
          <button type="button" class="btn-ghost" @click="duplicate">Dupliquer</button>
        </template>
        <span v-if="hasVariants" class="hidden text-sm text-slate-500 sm:inline">
          {{ variantRows.filter((r) => r.include).length }} variante(s)
        </span>
        <button class="btn-brand ml-auto" :disabled="saving || uploading">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
    </div>

    <SocialPublish v-if="publishing" :product="{ id: product.id, title: form.title }" @close="publishing = false" />
    <Modal v-if="share" title="Partager le produit" @close="share = null">
      <div class="space-y-3 text-sm">
        <img :src="share.image_url" alt="Image de partage" class="w-full rounded-lg border border-slate-200" width="1080" height="1080" />
        <p class="text-slate-600">Image prête pour un statut WhatsApp, Facebook ou TikTok. Le lien court mène à la fiche du produit ({{ share.clicks }} visite(s)).</p>
        <div class="flex gap-2">
          <input class="input text-xs" :value="share.url" readonly @focus="$event.target.select()" />
          <button type="button" class="btn-secondary shrink-0" @click="copyShare(share.url)">{{ shareCopied ? 'Copié ✓' : 'Copier' }}</button>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <a :href="share.image_url" download class="btn-brand">⬇ Télécharger l'image</a>
          <a :href="`https://wa.me/?text=${encodeURIComponent(share.text)}`" target="_blank" rel="noopener" class="btn-secondary"><Icon name="messages" :size="16" class="mr-1 inline-block align-[-3px]" />Envoyer le lien</a>
        </div>
      </div>
    </Modal>
  </form>
</template>
