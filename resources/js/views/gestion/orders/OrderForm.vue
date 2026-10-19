<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import PlacePicker from '../../../components/PlacePicker.vue'
import ProductPicker from '../../../components/ProductPicker.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { SOURCES } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const props = defineProps({ id: { type: String, default: null } })
const router = useRouter()
const route = useRoute()
const editing = computed(() => !!props.id)

const form = reactive({
  source: 'whatsapp',
  customer_phone: '',
  customer_name: '',
  delivery_method: 'delivery',
  shipping_phone2: '',
  shipping_address: '',
  shipping_landmark: '',
  scheduled_date: '',
  discount_total: 0,
  discount_code: '',
  deposit_required: '',
  customer_note: '',
  internal_note: '',
  confirmed: false,
})
const place = ref(null)
const items = ref([])
const manualFee = ref(false)
const shippingFee = ref('')
const zones = ref([])

const paste = ref('')
const parsing = ref(false)
const parseInfo = ref('')
const auth = useAuthStore()
const known = ref(null) // client déjà connu
const elsewhere = ref([]) // le même numéro dans les autres boutiques de l'adresse
const errors = ref({})
const error = ref('')
const saving = ref(false)
const loading = ref(editing.value)
// Commande créée depuis la boîte « Messages » : le client est l'expéditeur du message
const conversation = ref(null)

onMounted(async () => {
  zones.value = (await http.get('/delivery-zones')).data.data
  if (editing.value) {
    const { data } = await http.get(`/orders/${props.id}`)
    const o = data.data
    Object.assign(form, {
      customer_phone: o.customer_phone_display, customer_name: o.customer_name ?? '', delivery_method: o.delivery_method,
      shipping_phone2: o.shipping_phone2 ?? '', shipping_address: o.shipping_address ?? '', shipping_landmark: o.shipping_landmark ?? '',
      scheduled_date: o.scheduled_date ?? '', discount_total: o.discount_total, discount_code: o.discount_code ?? '', deposit_required: o.deposit_required ?? '',
      customer_note: o.customer_note ?? '', internal_note: o.internal_note ?? '',
    })
    place.value = o.shipping_place_id ? { id: o.shipping_place_id, label: o.shipping_place_label, ancestor_ids: [] } : null
    // Prix convenus à la commande : gardés tels quels (pas de recalcul par les promotions du moment)
    items.value = o.items.map((i) => ({ variant_id: i.variant_id, title: i.title, variant_title: i.variant_title, quantity: i.quantity, unit_price: i.unit_price, thumb: i.thumb, price_edited: true }))
    shippingFee.value = o.shipping_fee
    manualFee.value = true
    chosen.value = o.planned_courier ? `courier:${o.planned_courier.id}` : o.planned_carrier ? `carrier:${o.planned_carrier.id}` : ''
    showPhone2.value = !!o.shipping_phone2
    showNotes.value = !!(o.customer_note || o.internal_note)
    loading.value = false
    await nextTick()
  } else if (route.query.conversation) {
    await fromConversation(route.query.conversation)
  }
  ready.value = true
})

async function fromConversation(id) {
  try {
    conversation.value = (await http.get(`/conversations/${id}`)).data.data
  } catch {
    return
  }
  const c = conversation.value
  form.source = { messenger: 'facebook', instagram: 'instagram' }[c.channel] ?? 'whatsapp'
  paste.value = c.order_text
  pasteOpen.value = true
  if (paste.value.trim().length >= 5) await analyse()
  // WhatsApp : le numéro de la conversation ; un autre numéro cité devient le second numéro de livraison.
  // Messenger et Instagram : le numéro est celui que le client a donné dans ses messages.
  if (c.phone) {
    const parsed = form.customer_phone.replace(/\D/g, '')
    if (parsed && !c.phone.endsWith(parsed.slice(-10))) form.shipping_phone2 ||= form.customer_phone
    showPhone2.value = !!form.shipping_phone2
    form.customer_phone = c.phone_display
  }
  form.customer_name ||= c.customer?.name ?? c.name ?? ''
  await lookupCustomer()
}

// Prix du moment (vente flash, prix par quantité) et code promo, recalculés par le serveur ; un prix modifié à la main est gardé
const promo = ref(null)
let pricingTimer
async function reprice() {
  const lines = items.value.map((i) => ({ variant_id: i.variant_id ?? null, quantity: Number(i.quantity) || 1, unit_price: i.unit_price === '' ? null : Number(i.unit_price) }))
  if (!lines.length && !form.discount_code) return (promo.value = null)
  try {
    const { data } = await http.post('/orders/pricing', { items: lines, discount_code: form.discount_code || null, customer_phone: form.customer_phone || null, order_id: editing.value ? Number(props.id) : null })
    data.data.lines.forEach((line, index) => {
      const item = items.value[index]
      if (!item || !item.variant_id || item.price_edited) return
      Object.assign(item, { unit_price: line.unit_price, line_total: line.total, compare_at: line.compare_at, volume: line.volume, flash: line.flash })
    })
    promo.value = data.data.discount
  } catch {
    promo.value = null
  }
}
watch(() => [items.value.map((i) => `${i.variant_id}:${i.quantity}:${i.price_edited ? i.unit_price : ''}`).join(','), form.discount_code, form.customer_phone], () => {
  clearTimeout(pricingTimer)
  pricingTimer = setTimeout(reprice, 400)
})

const lineTotal = (i) => (!i.price_edited && i.line_total !== undefined ? i.line_total : Number(i.unit_price || 0) * Number(i.quantity || 0))
const subtotal = computed(() => items.value.reduce((sum, i) => sum + lineTotal(i), 0))
const codeDiscount = computed(() => (promo.value && !promo.value.error ? promo.value.amount : 0))
const zone = computed(() => {
  if (!place.value) return null
  for (const id of [place.value.id, ...(place.value.ancestor_ids ?? [])]) {
    const found = zones.value.find((z) => z.is_active && z.places.some((p) => p.id === id))
    if (found) return found
  }
  return null
})
// Qui livre : un de mes livreurs ou une entreprise de livraison, choisi dès la saisie. Les zones et tarifs de
// l'entreprise sont lus pour le quartier : les frais affichés sont ceux qui seront enregistrés.
const chosen = ref('') // '' | 'courier:ID' | 'carrier:ID'
const options = ref(null)
const optionsLoading = ref(false)
let optionsTimer
async function loadOptions() {
  if (form.delivery_method !== 'delivery') return
  optionsLoading.value = true
  try {
    options.value = (await http.post('/orders/delivery-options', {
      place_id: place.value?.id ?? null,
      items: items.value.filter((i) => i.variant_id).map((i) => ({ variant_id: i.variant_id, quantity: Number(i.quantity) || 1 })),
    })).data.data
  } catch {
    options.value = null
  } finally {
    optionsLoading.value = false
  }
}
watch(() => [place.value?.id, form.delivery_method, items.value.map((i) => `${i.variant_id}:${i.quantity}`).join(',')], () => {
  clearTimeout(optionsTimer)
  optionsTimer = setTimeout(loadOptions, 300)
}, { immediate: true })
const chosenCourier = computed(() => (chosen.value.startsWith('courier:') ? options.value?.couriers.find((c) => `courier:${c.id}` === chosen.value) ?? null : null))
const chosenCarrier = computed(() => (chosen.value.startsWith('carrier:') ? options.value?.carriers.find((c) => `carrier:${c.id}` === chosen.value) ?? null : null))
// Une entreprise choisie qui a un tarif pour ce quartier : c'est son prix
const carrierFee = computed(() => (chosenCarrier.value?.covered && chosenCarrier.value.fee !== null ? chosenCarrier.value.fee : null))
const ready = ref(false)
watch(chosen, () => {
  if (editing.value && ready.value) manualFee.value = false // nouveau choix : frais recalculés
})
const freeShipping = computed(() => !!(promo.value?.free_shipping && !promo.value.error))
const fee = computed(() => {
  if (form.delivery_method === 'pickup') return 0
  if (manualFee.value) return Number(shippingFee.value || 0)
  if (freeShipping.value) return 0
  if (carrierFee.value !== null) return carrierFee.value
  if (!zone.value) return 0
  return zone.value.free_above !== null && subtotal.value >= zone.value.free_above ? 0 : zone.value.fee
})
const feeSource = computed(() => {
  if (form.delivery_method === 'pickup') return ''
  if (manualFee.value) return 'saisis à la main'
  if (freeShipping.value) return 'offerts par le code promo'
  if (carrierFee.value !== null) return `tarif ${chosenCarrier.value.name}${chosenCarrier.value.zone ? `, zone ${chosenCarrier.value.zone}` : ''}`
  if (zone.value) return `votre zone ${zone.value.name}${zone.value.eta_label ? ` · ${zone.value.eta_label}` : ''}`
  return place.value ? 'hors de vos zones : indiquez les frais' : ''
})
function editFee() {
  shippingFee.value = fee.value
  manualFee.value = true
}

// Zones et tarifs d'une entreprise de livraison
const tariffs = ref(null)
async function openTariffs() {
  tariffs.value = { name: chosenCarrier.value.name, zones: null }
  tariffs.value = (await http.get(`/orders/delivery-options/carriers/${chosenCarrier.value.id}`)).data.data
}

// Date souhaitée : aujourd'hui, demain ou une autre date
const isoDay = (offset) => {
  const d = new Date()
  d.setDate(d.getDate() + offset)
  return d.toLocaleDateString('sv-SE')
}
const pickDate = ref(false)

const showPhone2 = ref(false)
const showNotes = ref(false)
const showDiscount = ref(false)
const showDeposit = ref(false)
const pasteOpen = ref(false)

// Quantité : boutons − et +
function step(item, delta) {
  item.quantity = Math.max(1, (Number(item.quantity) || 1) + delta)
}
const stockTone = (i) => (i.available === null || i.available === undefined ? '' : i.available < i.quantity ? 'bg-red-50 text-red-700' : i.available <= 3 ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800')
const discount = computed(() => (form.discount_code ? codeDiscount.value : Number(form.discount_total || 0)))
// Points de fidélité du client (nouvelle commande) : réduction = points × valeur d'un point
const rewardPoints = ref(0)
const loyalty = computed(() => (!editing.value && known.value?.loyalty?.usable ? known.value.loyalty : null))
const pointsDiscount = computed(() => (loyalty.value && rewardPoints.value > 0 ? Math.round(rewardPoints.value * loyalty.value.value / Math.max(1, loyalty.value.points)) : 0))
const total = computed(() => Math.max(0, subtotal.value - discount.value - pointsDiscount.value) + fee.value)

// Ce qui manque encore : le bouton le dit au lieu d'être grisé sans explication
const blocker = computed(() => {
  if (!editing.value && form.customer_phone.replace(/\D/g, '').length < 8) return 'Indiquez le téléphone du client'
  if (!items.value.length) return 'Ajoutez au moins un article'
  if (items.value.some((i) => i.needs_variant)) return 'Choisissez la variante des articles signalés'
  if (items.value.some((i) => !i.variant_id && !String(i.title ?? '').trim())) return 'Donnez un nom à l\'article hors catalogue'
  if (chosenCarrier.value && !chosenCarrier.value.covered) return `${chosenCarrier.value.name} ne livre pas ce quartier`
  return ''
})

// Assistant IA : conversation mal écrite ou en plusieurs messages, captures d'écran
const ai = computed(() => auth.profile?.ai ?? { enabled: false, used: 0, limit: 0 })
const screenshots = ref([])
function onScreenshots(event) {
  screenshots.value = [...screenshots.value, ...event.target.files].slice(0, 3)
  event.target.value = ''
}

async function analyse(withAssistant = false) {
  parsing.value = true
  parseInfo.value = ''
  try {
    let r
    if (withAssistant) {
      const body = new FormData()
      if (paste.value.trim()) body.append('text', paste.value)
      screenshots.value.forEach((f) => body.append('screenshots[]', f))
      r = (await http.post('/orders/draft', body)).data.data
      if (auth.profile?.ai) auth.profile.ai.used++
    } else {
      r = (await http.post('/orders/parse', { text: paste.value })).data.data
    }
    if (r.phone) form.customer_phone = phoneDisplay(r.phone)
    if (r.phone2) {
      form.shipping_phone2 = phoneDisplay(r.phone2)
      showPhone2.value = true
    }
    if (r.customer_name || r.customer?.name) form.customer_name = r.customer_name ?? r.customer.name
    if (r.place) place.value = { ...r.place, ancestor_ids: [] }
    if (r.address) form.shipping_address = r.address
    if (r.landmark) form.shipping_landmark = r.landmark
    if (r.note) {
      form.customer_note = r.note
      showNotes.value = true
    }
    // Adresse habituelle du client connu, si le message n'en donne pas
    if (r.customer?.address && !r.place) {
      place.value = r.customer.address.place_id ? { id: r.customer.address.place_id, label: r.customer.address.place_label, ancestor_ids: [] } : null
      form.shipping_address ||= r.customer.address.address_line ?? ''
      form.shipping_landmark ||= r.customer.address.landmark ?? ''
    }
    known.value = r.customer
    for (const item of r.items) {
      if (!items.value.some((i) => i.variant_id && i.variant_id === item.variant_id)) {
        items.value.push({ ...item, needs_variant: item.needs_variant })
      }
    }
    await refreshPlace()
    const found = [r.phone && 'téléphone', r.place && 'quartier', r.items.length && `${r.items.length} produit(s)`].filter(Boolean)
    parseInfo.value = found.length ? `Trouvé : ${found.join(', ')}. Vérifiez avant d'enregistrer.` : 'Rien de reconnu : complétez à la main.'
    if (r.items.some((i) => i.needs_variant)) parseInfo.value += ' Choisissez la variante des produits signalés.'
    if (r.unmatched?.length) parseInfo.value += ` Pas au catalogue : ${r.unmatched.join(', ')}.`
    if (!r.place && r.place_text) parseInfo.value += ` Quartier « ${r.place_text} » à choisir dans la liste.`
    if (withAssistant) screenshots.value = []
  } catch (e) {
    parseInfo.value = errorMessage(e)
  } finally {
    parsing.value = false
  }
}

// Ancêtres du lieu (pour la zone) quand il vient de l'analyse ou d'une commande existante
async function refreshPlace() {
  if (!place.value?.label) return
  const results = (await http.get('/places', { params: { search: place.value.label.split(',')[0] } })).data.data
  const match = results.find((p) => p.id === place.value.id)
  if (match) place.value = match
}

async function lookupCustomer() {
  const digits = form.customer_phone.replace(/\D/g, '')
  if (digits.length < 8 || editing.value) return
  const { data } = await http.get('/customers', { params: { search: digits.slice(-8) } })
  const match = data.data.find((c) => c.phone.replace(/\D/g, '').endsWith(digits.slice(-10)))
  const detail = match ? (await http.get(`/customers/${match.id}`)).data.data : null
  known.value = detail ? { ...detail, address: detail.addresses[0] } : null
  // Pas encore client ici mais déjà dans une autre boutique de l'adresse : son nom et son adresse servent aussi
  elsewhere.value = detail ? detail.other_stores ?? [] : (auth.profile?.stores?.length ?? 0) > 1
    ? (await http.get('/customers/elsewhere', { params: { phone: form.customer_phone } })).data.data
    : []
  const name = detail?.name || elsewhere.value.find((o) => o.name)?.name
  const address = detail?.addresses[0] ?? elsewhere.value.find((o) => o.address?.place_id)?.address
  form.customer_name ||= name ?? ''
  if (!place.value && address?.place_id) {
    place.value = { id: address.place_id, label: address.place_label, ancestor_ids: [] }
    form.shipping_address ||= address.address_line ?? ''
    form.shipping_landmark ||= address.landmark ?? ''
    await refreshPlace()
  }
}

async function chooseVariantFor(item) {
  const { data } = await http.get(`/products/${item.product_id}`)
  item.choices = data.data.variants.filter((v) => v.is_active)
}

function setVariant(item, variant) {
  Object.assign(item, { variant_id: variant.id, variant_title: variant.title, unit_price: variant.price, needs_variant: false, choices: null, available: variant.available })
}

async function save() {
  if (blocker.value) {
    error.value = `${blocker.value}.`
    return
  }
  saving.value = true
  error.value = ''
  errors.value = {}
  try {
    const payload = {
      ...form,
      shipping_place_id: form.delivery_method === 'delivery' ? place.value?.id ?? null : null,
      deposit_required: form.deposit_required === '' ? null : Number(form.deposit_required),
      scheduled_date: form.scheduled_date || null,
      discount_code: form.discount_code || null,
      reward_points: loyalty.value && rewardPoints.value > 0 ? Number(rewardPoints.value) : null,
      items: items.value.map((i) => ({ variant_id: i.variant_id ?? null, title: i.variant_id ? undefined : i.title, quantity: Number(i.quantity), unit_price: Number(i.unit_price) })),
    }
    if (form.discount_code) delete payload.discount_total
    if (manualFee.value && form.delivery_method === 'delivery') payload.shipping_fee = Number(shippingFee.value || 0)
    else if (carrierFee.value !== null && form.delivery_method === 'delivery') payload.shipping_fee = fee.value
    // Choix de qui livre : seulement si la liste a pu être lue (sinon on garde celui déjà enregistré)
    if (options.value || form.delivery_method === 'pickup') {
      const delivering = form.delivery_method === 'delivery'
      payload.planned_courier_id = delivering && chosen.value.startsWith('courier:') ? Number(chosen.value.split(':')[1]) : null
      payload.planned_carrier_connection_id = delivering && chosen.value.startsWith('carrier:') ? Number(chosen.value.split(':')[1]) : null
    }
    if (editing.value) {
      delete payload.source
      delete payload.customer_phone
      delete payload.confirmed
      await http.put(`/orders/${props.id}`, payload)
      router.push(`/gestion/commandes/${props.id}`)
    } else {
      const { data } = await http.post('/orders', { ...payload, conversation_id: conversation.value?.id ?? null })
      router.push(`/gestion/commandes/${data.data.id}`)
    }
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <p v-if="loading" class="text-sm text-slate-500">Chargement…</p>
  <form v-else class="space-y-5 pb-40 lg:pb-6" novalidate @submit.prevent="save">
    <RouterLink v-if="conversation" :to="`/gestion/messages/${conversation.id}`" class="text-sm text-slate-500 hover:text-emerald-700">← Conversation avec {{ conversation.name || conversation.phone_display }}</RouterLink>
    <RouterLink v-else :to="editing ? `/gestion/commandes/${id}` : '/gestion/commandes'" class="text-sm text-slate-500 hover:text-emerald-700">← {{ editing ? 'Commande' : 'Commandes' }}</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Saisie rapide : un bandeau, ouvert seulement quand on en a besoin -->
    <section v-if="!editing" class="card">
      <button type="button" class="flex w-full items-center gap-3 p-4 text-left" :aria-expanded="pasteOpen" @click="pasteOpen = !pasteOpen">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-50 text-emerald-700"><Icon name="messages" :size="18" /></span>
        <span class="min-w-0 flex-1">
          <span class="block font-medium">Coller le message du client</span>
          <span class="block text-sm text-slate-500">WhatsApp, Facebook… : numéro, nom, quartier et produits se remplissent.</span>
        </span>
        <Icon name="chevron" :size="18" class="text-slate-400 transition" :class="{ 'rotate-90': pasteOpen }" />
      </button>
      <div v-if="pasteOpen" class="space-y-3 border-t border-slate-100 p-4">
        <textarea v-model="paste" class="input" rows="3" placeholder="Bonsoir, je veux 2 robes wax taille M. Je suis à Yop Siporex, 07 07 07 07 07…" />
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn-secondary" :disabled="parsing || paste.trim().length < 5" @click="analyse(false)">{{ parsing ? 'Analyse…' : 'Analyser le message' }}</button>
          <template v-if="ai.enabled">
            <label class="btn-secondary cursor-pointer px-3">
              <Icon name="image" :size="16" />Capture d'écran{{ screenshots.length ? ` (${screenshots.length})` : '' }}
              <input type="file" accept="image/*" multiple class="hidden" @change="onScreenshots" />
            </label>
            <button type="button" class="btn-brand" :disabled="parsing || (paste.trim().length < 5 && !screenshots.length) || (ai.limit !== null && ai.used >= ai.limit)" @click="analyse(true)">
              <Icon name="sparkles" :size="16" />{{ parsing ? 'L\'assistant lit…' : 'Analyser avec l\'assistant' }}
            </button>
          </template>
        </div>
        <p v-if="ai.enabled" class="text-xs text-slate-500">L'assistant comprend les messages mal écrits, en plusieurs fois ou en capture d'écran ({{ ai.used }}{{ ai.limit !== null ? ` / ${ai.limit}` : '' }} action(s) ce mois-ci).</p>
      </div>
    </section>
    <Alert v-if="parseInfo" tone="info">{{ parseInfo }}</Alert>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <div class="min-w-0 space-y-5">
        <!-- 1. Client et livraison -->
        <section class="card space-y-5 p-4 sm:p-6">
          <h2 class="flex items-center gap-2 font-semibold">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-slate-900 text-xs text-white">1</span>Client et livraison
          </h2>

          <div v-if="!editing">
            <p class="label">Canal de vente</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="s in SOURCES"
                :key="s.value"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm"
                :class="form.source === s.value ? 'border-emerald-700 bg-emerald-50 font-medium text-emerald-800' : 'border-slate-300 text-slate-700 hover:border-slate-400'"
                :aria-pressed="form.source === s.value"
                @click="form.source = s.value"
              >
                {{ s.label }}
              </button>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <Field id="phone" label="Téléphone *" :error="errors.customer_phone">
              <input id="phone" v-model="form.customer_phone" class="input" type="tel" inputmode="tel" autocomplete="off" :disabled="editing" @blur="lookupCustomer" />
            </Field>
            <Field id="name" label="Nom" :error="errors.customer_name">
              <input id="name" v-model="form.customer_name" class="input" maxlength="120" autocomplete="off" />
            </Field>
          </div>
          <Field v-if="showPhone2" id="phone2" label="Autre numéro" :error="errors.shipping_phone2">
            <input id="phone2" v-model="form.shipping_phone2" class="input sm:max-w-xs" type="tel" inputmode="tel" />
          </Field>
          <button v-else type="button" class="-mt-2 text-sm text-emerald-700 hover:underline" @click="showPhone2 = true">+ Ajouter un autre numéro</button>

          <!-- Client connu : fiche résumée -->
          <Alert v-if="known?.is_blacklisted" tone="error">Ce client est sur liste noire{{ known.blacklist_reason ? ` : ${known.blacklist_reason}` : '' }}.</Alert>
          <div v-else-if="known" class="flex items-center gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-100 font-semibold text-emerald-800">{{ (known.name ?? '?').charAt(0).toUpperCase() }}</span>
            <span class="min-w-0 flex-1">
              <span class="block font-medium">Client connu</span>
              <span class="block text-slate-600">{{ known.orders_count }} commande(s)<template v-if="known.delivery_rate !== null && known.delivery_rate !== undefined"> · {{ known.delivery_rate }} % livrées</template></span>
            </span>
          </div>
          <template v-for="o in elsewhere" :key="o.store_id">
            <Alert v-if="o.is_blacklisted" tone="error">Sur liste noire dans {{ o.store_name }}{{ o.blacklist_reason ? ` : ${o.blacklist_reason}` : '' }}.</Alert>
            <p v-else class="text-sm text-slate-600">
              Client de {{ o.store_name }} : {{ o.orders_count }} commande(s), {{ o.delivered_count }} livrée(s)<template v-if="o.refused_count">, {{ o.refused_count }} refusée(s)</template>.
            </p>
          </template>

          <div class="border-t border-slate-100 pt-5">
            <div class="grid grid-cols-2 gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:inline-grid sm:w-96">
              <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="form.delivery_method === 'delivery' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="form.delivery_method = 'delivery'">Livraison</button>
              <button type="button" class="rounded-md px-3 py-1.5 font-medium" :class="form.delivery_method === 'pickup' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="form.delivery_method = 'pickup'">Retrait au magasin</button>
            </div>
          </div>

          <template v-if="form.delivery_method === 'delivery'">
            <Field id="place" label="Quartier / commune" :error="errors.shipping_place_id">
              <PlacePicker id="place" v-model="place" />
            </Field>
            <div>
              <p class="label">Adresse précise</p>
              <div class="grid gap-2 sm:grid-cols-2">
                <input id="address" v-model="form.shipping_address" class="input" maxlength="255" placeholder="Rue, immeuble, porte…" aria-label="Adresse" />
                <input id="landmark" v-model="form.shipping_landmark" class="input" maxlength="255" placeholder="Point de repère : face pharmacie…" aria-label="Point de repère" />
              </div>
            </div>

            <!-- Qui livre : mes livreurs, entreprises de livraison (zones et tarifs lus pour le quartier) -->
            <Field id="carrier" label="Qui livre ?" :error="errors.planned_courier_id || errors.planned_carrier_connection_id">
              <select id="carrier" v-model="chosen" class="input">
                <option value="">Décider plus tard{{ options?.couriers.length || options?.carriers.length ? ' (à la confirmation, ou selon vos règles)' : '' }}</option>
                <optgroup v-if="options?.couriers.length" label="Mes livreurs">
                  <option v-for="c in options.couriers" :key="`courier:${c.id}`" :value="`courier:${c.id}`">
                    {{ c.name }}{{ c.covers_zone ? ' · couvre ce quartier' : '' }}{{ c.is_available ? '' : ' · indisponible' }}{{ c.active_missions ? ` · ${c.active_missions} course(s) en cours` : '' }}
                  </option>
                </optgroup>
                <optgroup v-if="options?.carriers.length" label="Entreprises de livraison">
                  <option v-for="c in options.carriers" :key="`carrier:${c.id}`" :value="`carrier:${c.id}`" :disabled="!c.covered && chosen !== `carrier:${c.id}`">
                    {{ c.name }}{{ !c.covered ? ' · ne livre pas ce quartier' : c.fee !== null ? ` · ${money(c.fee)}` : ' · tarif non renseigné' }}
                  </option>
                </optgroup>
              </select>
            </Field>
            <div v-if="chosenCarrier || chosenCourier" class="-mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <template v-if="chosenCarrier">
                <span v-if="!chosenCarrier.covered" class="text-red-700">{{ chosenCarrier.error || 'Ce quartier n\'est pas desservi.' }}</span>
                <span v-else-if="chosenCarrier.zone" class="text-slate-600">Zone {{ chosenCarrier.zone }}</span>
                <span v-else-if="!place" class="text-slate-600">Choisissez le quartier pour voir son tarif.</span>
                <button type="button" class="text-emerald-700 hover:underline" @click="openTariffs">Voir ses zones et tarifs</button>
              </template>
              <span v-else-if="chosenCourier && !chosenCourier.covers_zone && place" class="text-amber-800">{{ chosenCourier.name }} ne couvre pas ce quartier d'habitude.</span>
              <span v-else-if="chosenCourier && !chosenCourier.is_available" class="text-amber-800">{{ chosenCourier.name }} est indiqué indisponible.</span>
            </div>

            <!-- Frais : calculés, modifiables d'un geste -->
            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
              <Icon name="truck" :size="18" class="text-slate-500" />
              <span class="font-medium">Frais de livraison</span>
              <template v-if="manualFee">
                <input v-model="shippingFee" class="input w-28 py-1 text-right" type="number" min="0" aria-label="Frais de livraison" />
                <span>F</span>
                <button type="button" class="text-emerald-700 hover:underline" @click="manualFee = false">Recalculer</button>
              </template>
              <template v-else>
                <span class="tabular-nums">{{ fee ? money(fee) : 'Offerts' }}</span>
                <span v-if="feeSource" class="text-slate-500">· {{ feeSource }}</span>
                <span v-if="optionsLoading" class="text-slate-400">…</span>
                <button type="button" class="ml-auto text-emerald-700 hover:underline" @click="editFee">Modifier</button>
              </template>
            </div>

            <div>
              <p class="label">Date souhaitée</p>
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-for="d in [{ label: 'Dès que possible', value: '' }, { label: 'Aujourd\'hui', value: isoDay(0) }, { label: 'Demain', value: isoDay(1) }]"
                  :key="d.label"
                  type="button"
                  class="rounded-full border px-3 py-1.5 text-sm"
                  :class="!pickDate && form.scheduled_date === d.value ? 'border-emerald-700 bg-emerald-50 font-medium text-emerald-800' : 'border-slate-300 text-slate-700'"
                  @click="pickDate = false; form.scheduled_date = d.value"
                >
                  {{ d.label }}
                </button>
                <button type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="pickDate ? 'border-emerald-700 bg-emerald-50 font-medium text-emerald-800' : 'border-slate-300 text-slate-700'" @click="pickDate = true">Autre date</button>
                <input v-if="pickDate || (form.scheduled_date && ![isoDay(0), isoDay(1)].includes(form.scheduled_date))" v-model="form.scheduled_date" class="input w-auto py-1.5" type="date" aria-label="Date souhaitée" />
              </div>
            </div>
          </template>
          <p v-else class="text-sm text-slate-600">Le client vient chercher sa commande : pas de frais de livraison.</p>
        </section>

        <!-- 2. Articles -->
        <section class="card space-y-4 p-4 sm:p-6">
          <h2 class="flex items-center gap-2 font-semibold">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-slate-900 text-xs text-white">2</span>Articles
            <span v-if="items.length" class="text-sm font-normal text-slate-500">· {{ items.length }}</span>
          </h2>
          <p v-if="errors.items" class="text-sm text-red-600">{{ errors.items }}</p>
          <ProductPicker @add="(line) => items.push(line)" />
          <ul v-if="items.length" class="space-y-2">
            <li v-for="(item, index) in items" :key="index" class="rounded-lg border border-slate-200 p-3">
              <div class="flex items-start gap-3">
                <img v-if="item.thumb" :src="item.thumb" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" />
                <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-400"><Icon name="image" :size="18" /></span>
                <div class="min-w-0 flex-1">
                  <p v-if="item.variant_id || item.needs_variant" class="font-medium leading-snug">{{ item.title }}<span v-if="item.variant_title" class="font-normal text-slate-500"> — {{ item.variant_title }}</span></p>
                  <input v-else v-model="item.title" class="input py-1.5" placeholder="Nom de l'article" aria-label="Nom de l'article" />
                  <span v-if="stockTone(item)" class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="stockTone(item)">
                    {{ item.available < item.quantity ? `Seulement ${item.available} en stock` : `${item.available} en stock` }}
                  </span>
                </div>
                <button type="button" class="btn-ghost -mr-1 -mt-1 px-1.5 text-slate-400 hover:text-red-600" :aria-label="`Retirer ${item.title}`" @click="items.splice(index, 1)"><Icon name="close" :size="18" /></button>
              </div>
              <div class="mt-3 flex flex-wrap items-center gap-3">
                <div class="flex items-center rounded-lg border border-slate-300">
                  <button type="button" class="px-3 py-1.5 text-lg leading-none text-slate-600 disabled:opacity-40" :disabled="item.quantity <= 1" aria-label="Moins" @click="step(item, -1)">−</button>
                  <input v-model.number="item.quantity" type="number" min="1" class="w-12 border-x border-slate-300 py-1.5 text-center text-sm focus:outline-none" aria-label="Quantité" />
                  <button type="button" class="px-3 py-1.5 text-lg leading-none text-slate-600" aria-label="Plus" @click="step(item, 1)">+</button>
                </div>
                <label class="flex items-center gap-1.5 text-sm text-slate-500">
                  Prix
                  <input v-model.number="item.unit_price" type="number" min="0" class="input w-28 py-1.5 text-right" @input="item.price_edited = true" />
                </label>
                <span class="ml-auto font-semibold tabular-nums">{{ money(lineTotal(item)) }}</span>
              </div>
              <p v-if="!item.price_edited && (item.flash || item.volume)" class="mt-2 text-xs text-emerald-700">
                {{ item.flash ? 'Prix de vente flash' : 'Prix par quantité' }}<template v-if="item.compare_at"> (au lieu de {{ money(item.compare_at) }})</template><template v-if="item.volume"> · {{ money(item.line_total) }} pour {{ item.quantity }}</template>
              </p>
              <p v-if="errors[`items.${index}.quantity`] || errors[`items.${index}.title`]" class="mt-2 text-sm text-red-600">{{ errors[`items.${index}.quantity`] || errors[`items.${index}.title`] }}</p>
              <div v-if="item.needs_variant" class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-amber-800">Quelle variante ?</span>
                <button v-if="!item.choices" type="button" class="btn-secondary px-2 py-1" @click="chooseVariantFor(item)">Choisir</button>
                <button v-for="v in item.choices ?? []" :key="v.id" type="button" class="rounded-full border border-slate-300 bg-white px-3 py-1 hover:border-emerald-600" @click="setVariant(item, v)">
                  {{ v.title }} ({{ v.available }})
                </button>
              </div>
            </li>
          </ul>
          <p v-else class="rounded-lg border border-dashed border-slate-300 p-4 text-center text-sm text-slate-500">Cherchez un produit ci-dessus pour l'ajouter.</p>
          <button type="button" class="text-sm text-emerald-700 hover:underline" @click="items.push({ variant_id: null, title: '', quantity: 1, unit_price: '' })">+ Article hors catalogue</button>
        </section>

        <!-- Notes : repliées tant qu'on n'en a pas besoin -->
        <section class="card p-4 sm:p-6">
          <button v-if="!showNotes" type="button" class="w-full text-left text-sm text-emerald-700 hover:underline" @click="showNotes = true">+ Ajouter une note<span class="hidden sm:inline"> (demande du client, note pour l'équipe)</span></button>
          <div v-else class="grid gap-4 sm:grid-cols-2">
            <Field id="customer_note" label="Demande du client">
              <textarea id="customer_note" v-model="form.customer_note" class="input" rows="2" />
            </Field>
            <Field id="internal_note" label="Note interne" hint="Visible seulement par l'équipe">
              <textarea id="internal_note" v-model="form.internal_note" class="input" rows="2" />
            </Field>
          </div>
        </section>
      </div>

      <!-- 3. Paiement et validation -->
      <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">
        <section class="card space-y-3 p-4 text-sm sm:p-5">
          <h2 class="flex items-center gap-2 text-base font-semibold">
            <span class="grid h-6 w-6 place-items-center rounded-full bg-slate-900 text-xs text-white">3</span>Paiement
          </h2>
          <div class="flex justify-between"><span class="text-slate-600">Articles</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
          <div class="flex justify-between"><span class="text-slate-600">Livraison</span><span class="tabular-nums">{{ form.delivery_method === 'pickup' ? '—' : fee ? money(fee) : 'Offerte' }}</span></div>
          <div v-if="discount || pointsDiscount" class="flex justify-between text-emerald-700"><span>Remise</span><span class="tabular-nums">− {{ money(discount + pointsDiscount) }}</span></div>

          <!-- Une seule entrée pour toutes les remises -->
          <button v-if="!showDiscount && !form.discount_code && !form.discount_total && !rewardPoints" type="button" class="text-emerald-700 hover:underline" @click="showDiscount = true">+ Ajouter une remise</button>
          <div v-else class="space-y-2 rounded-lg bg-slate-50 p-3">
            <div class="flex items-center justify-between gap-2">
              <label for="promo" class="text-slate-600">Code promo</label>
              <input id="promo" v-model.trim="form.discount_code" class="input w-32 py-1 text-right uppercase" maxlength="40" placeholder="—" />
            </div>
            <p v-if="form.discount_code && promo?.error" class="text-xs text-red-600">{{ promo.error }}</p>
            <p v-else-if="form.discount_code && promo?.free_shipping" class="text-xs text-emerald-700">Livraison offerte</p>
            <p v-else-if="errors.discount_code" class="text-xs text-red-600">{{ errors.discount_code }}</p>
            <div v-if="!form.discount_code" class="flex items-center justify-between gap-2">
              <label for="discount" class="text-slate-600">Remise (F)</label>
              <input id="discount" v-model.number="form.discount_total" type="number" min="0" class="input w-32 py-1 text-right" />
            </div>
            <div v-if="loyalty" class="flex items-center justify-between gap-2">
              <label for="points" class="text-slate-600">Points de fidélité <span class="text-xs">({{ loyalty.points }} dispo.)</span></label>
              <input id="points" v-model.number="rewardPoints" type="number" min="0" :max="loyalty.points" class="input w-24 py-1 text-right" />
            </div>
            <p v-if="errors.reward_points" class="text-xs text-red-600">{{ errors.reward_points }}</p>
          </div>

          <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
            <span class="text-base font-semibold">Total</span>
            <span class="text-2xl font-bold tabular-nums">{{ money(total) }}</span>
          </div>

          <button v-if="!showDeposit && !form.deposit_required" type="button" class="text-emerald-700 hover:underline" @click="showDeposit = true">+ Demander un acompte</button>
          <div v-else class="flex items-center justify-between gap-2">
            <label for="deposit" class="text-slate-600">Acompte exigé (F)</label>
            <input id="deposit" v-model="form.deposit_required" type="number" min="0" class="input w-32 py-1 text-right" placeholder="—" />
          </div>
        </section>

        <!-- Ordinateur : validation sous le récapitulatif -->
        <div class="hidden space-y-3 lg:block">
          <label v-if="!editing" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-white p-3 text-sm">
            <input v-model="form.confirmed" type="checkbox" class="peer sr-only" />
            <span class="mt-0.5 h-5 w-9 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-4 w-4 rounded-full bg-white shadow transition" :class="{ 'translate-x-4': form.confirmed }" /></span>
            <span>Déjà confirmée avec le client <span class="block text-xs text-slate-500">Sinon elle va dans la file « À confirmer ».</span></span>
          </label>
          <button class="btn-brand w-full py-3 text-base" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer les modifications' : 'Créer la commande' }}</button>
          <p v-if="blocker" class="text-center text-sm text-amber-800">{{ blocker }}</p>
        </div>
      </aside>
    </div>

    <!-- Téléphone : total et validation toujours visibles, au-dessus de la barre de navigation -->
    <div class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 space-y-2 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
      <label v-if="!editing" class="flex items-center gap-2 text-sm">
        <input v-model="form.confirmed" type="checkbox" class="peer sr-only" />
        <span class="h-5 w-9 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-4 w-4 rounded-full bg-white shadow transition" :class="{ 'translate-x-4': form.confirmed }" /></span>
        Déjà confirmée avec le client
      </label>
      <div class="flex items-center gap-3">
        <div class="min-w-0">
          <p class="text-xs text-slate-500">Total</p>
          <p class="text-lg font-bold leading-tight tabular-nums">{{ money(total) }}</p>
        </div>
        <button class="btn-brand ml-auto flex-1 py-3" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer la commande' }}</button>
      </div>
      <p v-if="blocker" class="text-center text-xs text-amber-800">{{ blocker }}</p>
    </div>

    <!-- Zones et tarifs de l'entreprise choisie -->
    <Modal v-if="tariffs" :title="`Zones et tarifs de ${tariffs.name}`" @close="tariffs = null">
      <p v-if="!tariffs.zones" class="text-sm text-slate-500">Chargement…</p>
      <template v-else>
        <p class="mb-3 text-sm text-slate-600">{{ tariffs.is_api ? 'Le prix exact est demandé à l\'entreprise pour chaque commande (poids, colis fragile) ; les tarifs ci-dessous sont indicatifs.' : 'Prix payé par le client selon la zone. Pour les modifier : Livraisons › Entreprises de livraison › Zones et tarifs.' }}</p>
        <p v-if="!tariffs.zones.length" class="text-sm text-slate-500">Pas encore de zone : elle est proposée partout, aux frais de vos propres zones.</p>
        <ul class="divide-y divide-slate-100 text-sm">
          <li v-for="z in tariffs.zones" :key="z.id" class="py-2.5">
            <div class="flex items-baseline justify-between gap-3">
              <span class="font-medium">{{ z.label }}</span>
              <span class="tabular-nums">{{ z.fee !== null ? money(z.fee) : tariffs.is_api ? 'prix à la commande' : '—' }}</span>
            </div>
            <p v-if="z.places.length" class="mt-0.5 text-xs text-slate-500">{{ z.places.slice(0, 12).join(' · ') }}{{ z.places.length > 12 ? ` · et ${z.places.length - 12} autre(s)` : '' }}</p>
          </li>
        </ul>
      </template>
    </Modal>
  </form>
</template>
