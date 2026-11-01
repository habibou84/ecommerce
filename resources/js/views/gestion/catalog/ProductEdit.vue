<script setup>
import AiMeter from '../../../components/AiMeter.vue'
import { aiExhausted, countAiAction } from '../../../lib/aiQuota'
import Icon from '../../../components/Icon.vue'
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import BundleEditor from '../../../components/BundleEditor.vue'
import ChipsInput from '../../../components/ChipsInput.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import SocialPublish from '../../../components/SocialPublish.vue'
import { platformOn } from '../../../router'
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
// Prix et stock du produit : chaque variante les prend, sauf si on lui en donne un autre (case vide = même prix)
const defaults = reactive({ price: '', on_hand: '' })
const showSeo = ref(false)

function newRow(combo) {
  return { id: null, options: combo, include: true, price: '', compare_at_price: '', cost_price: '', sku: '', barcode: '', weight_g: '', image_id: null, image_pending: null, picking: false, is_active: true, on_hand: '', original_on_hand: null, levels: [] }
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
    // Prix le plus courant = prix du produit ; les variantes à ce prix affichent « même prix »
    const counts = {}
    data.variants.forEach((v) => (counts[v.price] = (counts[v.price] ?? 0) + 1))
    const common = Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0]
    defaults.price = common ?? ''
    defaults.on_hand = ''
    Object.values(rows).forEach((row) => row.id && String(row.price) === String(common) && (row.price = ''))
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

const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))

// Variantes rangées par couleur (ou par la première option s'il y en a deux) : un groupe = une photo à choisir
const groupIndex = computed(() => {
  if (!hasVariants.value || activeOptions.value.length < 2) return null
  const color = activeOptions.value.findIndex((o) => /couleur|color|coloris/i.test(o.name))
  return color >= 0 ? color : 0
})
const variantGroups = computed(() => {
  if (groupIndex.value === null) return [{ name: null, rows: variantRows.value }]
  const groups = []
  for (const row of variantRows.value) {
    const name = row.options[groupIndex.value]
    let group = groups.find((g) => g.name === name)
    if (!group) groups.push((group = { name, rows: [] }))
    group.rows.push(row)
  }
  return groups
})
const rowLabel = (row) => (groupIndex.value === null ? row.options.join(' / ') : row.options.filter((_, i) => i !== groupIndex.value).join(' / '))

// Photos à choisir pour une variante : celles déjà envoyées et celles choisies avant la création
const pictures = computed(() => [
  ...images.value.map((image) => ({ key: `i${image.id}`, url: image.urls.thumb, id: image.id, pending: null })),
  ...pendingFiles.value.map((p, index) => ({ key: `p${index}`, url: p.url, id: null, pending: index })),
])
const pictureOf = (row) => pictures.value.find((p) => (row.image_id !== null ? p.id === row.image_id : row.image_pending !== null && p.pending === row.image_pending)) ?? null
function setPicture(target, picture) {
  for (const row of Array.isArray(target) ? target : [target]) {
    row.image_id = picture?.id ?? null
    row.image_pending = picture?.pending ?? null
    row.picking = false
  }
}
const groupPicture = (group) => {
  const keys = new Set(group.rows.filter((r) => r.include).map((r) => pictureOf(r)?.key ?? ''))
  return keys.size === 1 ? [...keys][0] : null
}

function variantPayload(row) {
  const payload = {
    id: row.id ?? undefined,
    options: row.options,
    price: num(row.price) ?? num(defaults.price) ?? 0,
    compare_at_price: num(row.compare_at_price),
    cost_price: num(row.cost_price),
    sku: row.sku || null,
    barcode: row.barcode || null,
    weight_g: num(row.weight_g),
    image_id: row.image_id,
    is_active: row.is_active,
  }
  // Quantité envoyée seulement si elle a changé (elle remplace le stock de l'emplacement choisi)
  const stock = row.on_hand !== '' ? row.on_hand : row.id ? '' : defaults.on_hand
  if (canStock.value && stock !== '' && Number(stock) !== row.original_on_hand) payload.on_hand = Number(stock)
  return payload
}

// Message après l'enregistrement (au-dessus du bouton), avec la suite à choisir
const notice = ref(null)
let noticeTimer
function showNotice(value) {
  notice.value = value
  clearTimeout(noticeTimer)
  if (!value.created) noticeTimer = setTimeout(() => (notice.value = null), 5000)
}
onUnmounted(() => clearTimeout(noticeTimer))

// Erreur : la page descend jusqu'au champ à corriger (ou au message d'erreur)
async function showError() {
  await nextTick()
  const field = Object.keys(errors.value)[0]
  const target = (field && document.getElementById(field.split('.')[0])) || document.querySelector('[data-variant-error], [data-form-error]')
  target?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

async function save() {
  error.value = ''
  errors.value = {}
  saved.value = false
  notice.value = null
  const included = hasVariants.value ? variantRows.value.filter((r) => r.include) : [simple]
  if (included.some((r) => num(r.price) === null) && num(hasVariants.value ? defaults.price : simple.price) === null) {
    errors.value = { price: 'Indiquez le prix du produit.' }
    error.value = 'Indiquez le prix du produit.'
    await showError()
    return
  }
  saving.value = true
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
      // Photos choisies avant la création : envoyées, puis rattachées aux variantes qui les ont choisies
      const chosen = Object.fromEntries(Object.entries(rows).filter(([, r]) => r.image_pending !== null).map(([key, r]) => [key, r.image_pending]))
      const simplePending = simple.image_pending
      const ids = await uploadFiles(data.data.id, pendingFiles.value.map((p) => p.file))
      pendingFiles.value = []
      let fresh = (await http.get(`/products/${data.data.id}`)).data.data
      if (Object.keys(chosen).length || simplePending !== null) {
        load(fresh)
        Object.entries(chosen).forEach(([key, index]) => rows[key] && ids[index] && (rows[key].image_id = ids[index]))
        if (simplePending !== null && ids[simplePending]) simple.image_id = ids[simplePending]
        fresh = (await http.put(`/products/${fresh.id}`, { ...payload, variants: hasVariants.value ? variantRows.value.filter((r) => r.include).map(variantPayload) : [variantPayload(simple)] })).data.data
      }
      opening = fresh
      await router.replace(`/gestion/catalogue/${fresh.id}`)
      load(fresh)
      showNotice({ created: true, title: fresh.title, variants: fresh.variants.length, photos: fresh.images.length })
      return
    }
    load(data.data)
    saved.value = true
    showNotice({ created: false, title: data.data.title, variants: data.data.variants.length, photos: data.data.images.length })
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
    await showError()
  } finally {
    saving.value = false
  }
}

// Fiche ouverte depuis une autre (création terminée, « Ajouter un autre produit ») : rechargée ou vidée
let opening = null
watch(() => props.id, async (id) => {
  if (opening && String(opening.id) === String(id)) {
    opening = null
    return
  }
  error.value = ''
  errors.value = {}
  if (!id) {
    Object.assign(form, { title: '', description: '', brand: '', status: 'active', collection_ids: [], tags: [], seo_title: '', seo_description: '' })
    product.value = null
    images.value = []
    hasVariants.value = false
    Object.keys(rows).forEach((k) => delete rows[k])
    Object.assign(simple, newRow([]))
    Object.assign(defaults, { price: '', on_hand: '' })
    options.value = [{ name: 'Taille', values: [] }]
    window.scrollTo({ top: 0 })
    return
  }
  loading.value = true
  try {
    load((await http.get(`/products/${id}`)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
})
function addAnother() {
  notice.value = null
  router.push('/gestion/catalogue/nouveau')
}

async function uploadFiles(productId, files) {
  uploading.value = true
  const ids = []
  try {
    for (const file of files) {
      const body = new FormData()
      body.append('image', file)
      const { data } = await http.post(`/products/${productId}/images`, body)
      images.value.push(data.data)
      ids.push(data.data.id)
    }
  } catch (e) {
    error.value = errorMessage(e, 'Une photo n\'a pas pu être envoyée.')
  } finally {
    uploading.value = false
  }
  return ids
}

// Photo choisie avant la création du produit : retirée avant l'envoi
function removePending(index) {
  URL.revokeObjectURL(pendingFiles.value[index].url)
  pendingFiles.value.splice(index, 1)
  // Variantes qui avaient choisi cette photo (ou une suivante, décalée d'un rang)
  for (const row of [simple, ...Object.values(rows)]) {
    if (row.image_pending === index) row.image_pending = null
    else if (row.image_pending > index) row.image_pending -= 1
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
    countAiAction(auth.profile?.ai)
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

// Menu ⋯ (Dupliquer, Supprimer)
const menu = ref(false)
// Clavier ouvert au téléphone : la bande « Enregistrer » se cache pour ne pas couvrir le champ
const typing = ref(false)
let blurTimer
const isTyping = (el) => window.innerWidth < 1024 && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || (el.tagName === 'INPUT' && !['checkbox', 'radio', 'file', 'button', 'submit'].includes(el.type)))
function onFocus(event) {
  clearTimeout(blurTimer)
  if (isTyping(event.target)) typing.value = true
}
function onBlur() {
  blurTimer = setTimeout(() => (typing.value = false), 150)
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

  <form v-else class="space-y-5 pb-40 lg:pb-24" @submit.prevent="save" @focusin="onFocus" @focusout="onBlur">
    <div class="flex flex-wrap items-center gap-2">
      <RouterLink to="/gestion/catalogue" class="text-sm text-slate-500 hover:text-emerald-700">← Mes produits</RouterLink>
      <a v-if="product && form.status === 'active' && form.is_visible_online" :href="product.url" target="_blank" class="ml-auto text-sm text-slate-500 hover:text-emerald-700">Voir sur le site ↗</a>
      <button v-if="product && form.status === 'active' && form.is_visible_online" type="button" class="btn-secondary px-3 py-1.5" :disabled="sharing" @click="openShare"><Icon name="share" :size="16" class="mr-1 inline-block align-[-3px]" />Partager</button>
      <button v-if="product && form.status === 'active' && form.is_visible_online && auth.can('store.manage') && platformOn(auth, 'social_meta|social_tiktok')" type="button" class="btn-secondary px-3 py-1.5" @click="publishing = true"><Icon name="megaphone" :size="16" class="mr-1 inline-block align-[-3px]" />Publier sur les réseaux</button>
      <!-- Supprimer, Dupliquer : dans le menu ⋯ (la bande du bas ne garde que « Enregistrer ») -->
      <div v-if="props.id && canEdit" class="relative" :class="{ 'ml-auto': !(product && form.status === 'active' && form.is_visible_online) }">
        <button type="button" class="btn-secondary px-3 py-1.5 font-bold" aria-label="Plus d'actions" :aria-expanded="menu" @click="menu = !menu">⋯</button>
        <div v-if="menu" class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-lg">
          <button type="button" class="block w-full px-4 py-2.5 text-left hover:bg-slate-50" @click="menu = false; duplicate()">Dupliquer</button>
          <button type="button" class="block w-full px-4 py-2.5 text-left text-red-600 hover:bg-red-50" @click="menu = false; destroy()">Supprimer</button>
        </div>
      </div>
    </div>

    <div v-if="error" data-form-error><Alert>{{ error }}</Alert></div>

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
        <button type="button" class="btn-brand" :disabled="drafting || !pendingFiles.length || aiExhausted(ai)" @click="draftFromPhotos">
          {{ drafting ? 'L\'assistant regarde la photo…' : 'Proposer la fiche' }}
        </button>
      </div>
      <AiMeter />
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
            <template v-if="canEdit">
              <button type="button" class="absolute right-1 top-1 grid h-8 w-8 place-items-center rounded-full bg-white/95 text-base font-bold text-red-600 shadow ring-1 ring-slate-200" aria-label="Retirer cette photo" title="Retirer cette photo" @click="removeImage(image)">✕</button>
              <div class="mt-1 flex justify-center gap-1 text-xs">
                <button type="button" class="btn-ghost px-2 py-0.5" :disabled="index === 0" aria-label="Avancer la photo" @click="moveImage(index, -1)">←</button>
                <button type="button" class="btn-ghost px-2 py-0.5" :disabled="index === images.length - 1" aria-label="Reculer la photo" @click="moveImage(index, 1)">→</button>
              </div>
            </template>
          </div>
          <div v-for="(pending, index) in pendingFiles" :key="pending.url" class="relative">
            <img :src="pending.url" alt="" class="aspect-square w-full rounded-lg border border-dashed border-slate-300 object-cover opacity-80" />
            <button type="button" class="absolute right-1 top-1 grid h-8 w-8 place-items-center rounded-full bg-white/95 text-base font-bold text-red-600 shadow ring-1 ring-slate-200" aria-label="Retirer cette photo" title="Retirer cette photo" @click="removePending(index)">✕</button>
            <p class="mt-1 text-center text-xs leading-tight text-slate-500">Envoyée à l'enregistrement</p>
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
            <!-- Prix et stock du produit : pris par toutes les variantes (case vide = même prix) -->
            <div class="grid grid-cols-2 gap-3 rounded-lg bg-emerald-50/60 p-3">
              <Field id="price" label="Prix (pour toutes)" :error="errors.price">
                <input id="price" v-model="defaults.price" class="input" type="number" min="0" inputmode="numeric" placeholder="15000" />
              </Field>
              <Field v-if="canStock && form.track_inventory" id="stock" label="Stock par variante" hint="Pour les nouvelles variantes">
                <input id="stock" v-model="defaults.on_hand" class="input" type="number" min="0" inputmode="numeric" />
              </Field>
              <p class="col-span-2 text-xs text-slate-600">Une variante sans prix prend ce prix. Écrivez un prix seulement pour celle qui coûte autre chose.</p>
            </div>

            <!-- Photo de chaque couleur : toutes les tailles de la couleur la prennent -->
            <div v-if="groupIndex !== null && pictures.length" class="space-y-2 rounded-lg border border-slate-200 p-3">
              <p class="text-sm font-semibold">Photo de chaque {{ activeOptions[groupIndex].name.toLowerCase() }}</p>
              <div v-for="group in variantGroups" :key="group.name" class="flex items-center gap-2">
                <span class="w-20 shrink-0 truncate text-sm font-bold">{{ group.name }}</span>
                <div class="flex min-w-0 flex-1 gap-1.5 overflow-x-auto pb-1">
                  <button v-for="pic in pictures" :key="pic.key" type="button" class="h-11 w-11 shrink-0 overflow-hidden rounded-lg border-[3px]" :class="groupPicture(group) === pic.key ? 'border-emerald-600' : 'border-transparent'" :aria-label="`Photo pour ${group.name}`" @click="setPicture(group.rows, pic)">
                    <img :src="pic.url" alt="" class="h-full w-full object-cover" />
                  </button>
                </div>
              </div>
            </div>

            <p v-if="errors.variants" class="text-sm text-red-600" data-variant-error>{{ errors.variants }}</p>

            <div v-for="group in variantGroups" :key="group.name ?? 'all'" class="overflow-hidden rounded-lg border border-slate-200">
              <p v-if="group.name" class="bg-slate-50 px-3 py-2 text-sm font-extrabold">{{ group.name }}</p>
              <div v-for="row in group.rows" :key="row.options.join('|')" class="border-t border-slate-100 p-2.5 first:border-t-0" :class="{ 'bg-slate-50 opacity-60': !row.include }">
                <div class="flex items-center gap-2">
                  <input v-model="row.include" type="checkbox" class="h-4 w-4 shrink-0 accent-emerald-700" :aria-label="`Vendre ${row.options.join(' / ')}`" />
                  <button type="button" class="h-9 w-9 shrink-0 overflow-hidden rounded-md border border-slate-200 bg-slate-50 text-xs text-slate-400" :disabled="!row.include || !pictures.length" :aria-label="`Photo de ${row.options.join(' / ')}`" @click="row.picking = !row.picking">
                    <img v-if="pictureOf(row)" :src="pictureOf(row).url" alt="" class="h-full w-full object-cover" />
                    <span v-else>📷</span>
                  </button>
                  <b class="w-16 shrink-0 truncate text-sm">{{ rowLabel(row) }}</b>
                  <template v-if="row.include">
                    <input v-model="row.price" class="input min-w-0 flex-1 px-2 py-1.5 text-sm" type="number" min="0" inputmode="numeric" :placeholder="defaults.price !== '' ? `= ${defaults.price}` : 'Prix'" :aria-label="`Prix ${row.options.join(' / ')}`" />
                    <input v-if="canStock && form.track_inventory" v-model="row.on_hand" class="input w-16 shrink-0 px-2 py-1.5 text-sm" type="number" min="0" inputmode="numeric" :placeholder="row.id ? '0' : (defaults.on_hand || 'Stock')" :aria-label="`Stock ${row.options.join(' / ')}`" />
                  </template>
                  <span v-else class="text-sm text-slate-500">Non vendue</span>
                </div>
                <div v-if="row.picking" class="mt-2 flex gap-1.5 overflow-x-auto pl-6">
                  <button type="button" class="grid h-11 w-11 shrink-0 place-items-center rounded-lg border-[3px] text-xs text-slate-500" :class="!pictureOf(row) ? 'border-emerald-600' : 'border-slate-200'" @click="setPicture(row, null)">Aucune</button>
                  <button v-for="pic in pictures" :key="pic.key" type="button" class="h-11 w-11 shrink-0 overflow-hidden rounded-lg border-[3px]" :class="pictureOf(row)?.key === pic.key ? 'border-emerald-600' : 'border-transparent'" @click="setPicture(row, pic)">
                    <img :src="pic.url" alt="" class="h-full w-full object-cover" />
                  </button>
                </div>
                <details v-if="row.include" class="mt-1 pl-6 text-xs text-slate-500">
                  <summary class="cursor-pointer">Prix barré, référence</summary>
                  <div class="mt-1.5 grid grid-cols-2 gap-2">
                    <label>Prix barré <input v-model="row.compare_at_price" class="input mt-0.5" type="number" min="0" inputmode="numeric" /></label>
                    <label>SKU <input v-model="row.sku" class="input mt-0.5" maxlength="60" /></label>
                  </div>
                </details>
                <p v-if="variantError(variantRows.filter((r) => r.include).indexOf(row), 'price') || variantError(variantRows.filter((r) => r.include).indexOf(row), 'sku')" class="pl-6 text-xs text-red-600" data-variant-error>
                  {{ variantError(variantRows.filter((r) => r.include).indexOf(row), 'price') || variantError(variantRows.filter((r) => r.include).indexOf(row), 'sku') }}
                </p>
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
          <Field v-if="form.track_inventory" id="threshold" label="Alerte de stock bas" :hint="`Vide = seuil de votre espace marchand (${auth.store?.low_stock_threshold ?? 3})`">
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

    <!-- Après l'enregistrement : message au-dessus du bouton, avec la suite -->
    <div v-if="notice" class="fixed inset-x-3 bottom-[calc(8.5rem+env(safe-area-inset-bottom))] z-20 mx-auto max-w-md rounded-2xl bg-emerald-900 p-4 text-white shadow-2xl lg:bottom-20 lg:left-64" role="status">
      <div class="flex items-start gap-2">
        <p class="min-w-0 flex-1"><b>✅ Produit enregistré</b><br /><span class="text-sm text-emerald-100">{{ notice.title }} · {{ notice.variants }} variante{{ notice.variants > 1 ? 's' : '' }} · {{ notice.photos }} photo{{ notice.photos > 1 ? 's' : '' }}</span></p>
        <button type="button" class="text-lg leading-none text-emerald-200" aria-label="Fermer" @click="notice = null">✕</button>
      </div>
      <div v-if="notice.created" class="mt-3 grid gap-1.5 text-sm font-bold">
        <button type="button" class="rounded-xl bg-white py-2.5 text-emerald-900" @click="addAnother">+ Ajouter un autre produit</button>
        <RouterLink to="/gestion/catalogue" class="rounded-xl bg-white/15 py-2.5 text-center">Voir la liste des produits</RouterLink>
        <button type="button" class="rounded-xl bg-white/15 py-2.5" @click="notice = null">Rester sur la fiche</button>
      </div>
    </div>

    <!-- Bande « Enregistrer » : cachée pendant la saisie au téléphone (clavier ouvert) -->
    <div v-if="canEdit && !typing" class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 border-t border-slate-200 bg-white/95 px-4 py-2 backdrop-blur lg:bottom-0 lg:left-64">
      <div class="mx-auto flex max-w-5xl items-center gap-2">
        <span v-if="hasVariants" class="text-sm text-slate-500">{{ variantRows.filter((r) => r.include).length }} variante(s)</span>
        <button class="btn-brand ml-auto" :disabled="saving || uploading">{{ saving ? 'Enregistrement…' : uploading ? 'Envoi des photos…' : 'Enregistrer' }}</button>
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
