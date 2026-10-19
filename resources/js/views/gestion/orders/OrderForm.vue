<script setup>
import Icon from '../../../components/Icon.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
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
    loading.value = false
  } else if (route.query.conversation) {
    await fromConversation(route.query.conversation)
  }
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
  if (paste.value.trim().length >= 5) await analyse()
  // WhatsApp : le numéro de la conversation ; un autre numéro cité devient le second numéro de livraison.
  // Messenger et Instagram : le numéro est celui que le client a donné dans ses messages.
  if (c.phone) {
    const parsed = form.customer_phone.replace(/\D/g, '')
    if (parsed && !c.phone.endsWith(parsed.slice(-10))) form.shipping_phone2 ||= form.customer_phone
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
const fee = computed(() => {
  if (form.delivery_method === 'pickup') return 0
  if (manualFee.value) return Number(shippingFee.value || 0)
  if (!zone.value || (promo.value?.free_shipping && !promo.value.error)) return 0
  return zone.value.free_above !== null && subtotal.value >= zone.value.free_above ? 0 : zone.value.fee
})
const discount = computed(() => (form.discount_code ? codeDiscount.value : Number(form.discount_total || 0)))
// Points de fidélité du client (nouvelle commande) : réduction = points × valeur d'un point
const rewardPoints = ref(0)
const loyalty = computed(() => (!editing.value && known.value?.loyalty?.usable ? known.value.loyalty : null))
const pointsDiscount = computed(() => (loyalty.value && rewardPoints.value > 0 ? Math.round(rewardPoints.value * loyalty.value.value / Math.max(1, loyalty.value.points)) : 0))
const total = computed(() => Math.max(0, subtotal.value - discount.value - pointsDiscount.value) + fee.value)

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
    if (r.phone2) form.shipping_phone2 = phoneDisplay(r.phone2)
    if (r.customer_name || r.customer?.name) form.customer_name = r.customer_name ?? r.customer.name
    if (r.place) place.value = { ...r.place, ancestor_ids: [] }
    if (r.address) form.shipping_address = r.address
    if (r.landmark) form.shipping_landmark = r.landmark
    if (r.note) form.customer_note = r.note
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
  saving.value = true
  error.value = ''
  errors.value = {}
  if (items.value.some((i) => i.needs_variant)) {
    error.value = 'Choisissez la variante de chaque produit.'
    saving.value = false
    return
  }
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
  <form v-else class="space-y-5 pb-24" @submit.prevent="save">
    <RouterLink v-if="conversation" :to="`/gestion/messages/${conversation.id}`" class="text-sm text-slate-500 hover:text-emerald-700">← Conversation avec {{ conversation.name || conversation.phone_display }}</RouterLink>
    <RouterLink v-else :to="editing ? `/gestion/commandes/${id}` : '/gestion/commandes'" class="text-sm text-slate-500 hover:text-emerald-700">← {{ editing ? 'Commande' : 'Commandes' }}</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>

    <section v-if="!editing" class="card space-y-3 p-4 sm:p-6">
      <h2 class="font-semibold">Saisie rapide</h2>
      <p class="text-sm text-slate-600">Collez le message du client (WhatsApp, Facebook…) : numéro, nom, quartier et produits se remplissent.</p>
      <textarea v-model="paste" class="input" rows="3" placeholder="Bonsoir, je veux 2 robes wax taille M. Je suis à Yop Siporex, 07 07 07 07 07…" />
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-secondary" :disabled="parsing || paste.trim().length < 5" @click="analyse(false)">{{ parsing ? 'Analyse…' : 'Analyser le message' }}</button>
        <template v-if="ai.enabled">
          <label class="btn-secondary cursor-pointer px-3">
            <Icon name="image" :size="16" class="mr-1 inline-block align-[-3px]" />Capture d'écran{{ screenshots.length ? ` (${screenshots.length})` : '' }}
            <input type="file" accept="image/*" multiple class="hidden" @change="onScreenshots" />
          </label>
          <button type="button" class="btn-brand" :disabled="parsing || (paste.trim().length < 5 && !screenshots.length) || (ai.limit !== null && ai.used >= ai.limit)" @click="analyse(true)">
            <Icon name="sparkles" :size="16" class="mr-1 inline-block align-[-3px]" />{{ parsing ? 'L\'assistant lit…' : 'Analyser avec l\'assistant' }}
          </button>
        </template>
      </div>
      <p v-if="ai.enabled" class="text-xs text-slate-500">L'assistant comprend les messages mal écrits, en plusieurs fois ou en capture d'écran ({{ ai.used }}{{ ai.limit !== null ? ` / ${ai.limit}` : '' }} action(s) ce mois-ci).</p>
      <Alert v-if="parseInfo" tone="info">{{ parseInfo }}</Alert>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1fr_20rem]">
      <div class="space-y-5">
        <section class="card space-y-4 p-4 sm:p-6">
          <h2 class="font-semibold">Client</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <Field id="phone" label="Téléphone" :error="errors.customer_phone">
              <input id="phone" v-model="form.customer_phone" class="input" type="tel" inputmode="tel" :disabled="editing" required @blur="lookupCustomer" />
            </Field>
            <Field id="name" label="Nom" :error="errors.customer_name">
              <input id="name" v-model="form.customer_name" class="input" maxlength="120" />
            </Field>
            <Field v-if="!editing" id="source" label="Canal de vente">
              <select id="source" v-model="form.source" class="input">
                <option v-for="s in SOURCES" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </Field>
            <Field id="phone2" label="Autre numéro (facultatif)" :error="errors.shipping_phone2">
              <input id="phone2" v-model="form.shipping_phone2" class="input" type="tel" inputmode="tel" />
            </Field>
          </div>
          <Alert v-if="known?.is_blacklisted" tone="error">Ce client est sur liste noire{{ known.blacklist_reason ? ` : ${known.blacklist_reason}` : '' }}.</Alert>
          <p v-else-if="known" class="text-sm text-slate-600">
            Client connu : {{ known.orders_count }} commande(s)<template v-if="known.delivery_rate !== null && known.delivery_rate !== undefined">, {{ known.delivery_rate }} % livrées</template>.
          </p>
          <template v-for="o in elsewhere" :key="o.store_id">
            <Alert v-if="o.is_blacklisted" tone="error">Sur liste noire dans {{ o.store_name }}{{ o.blacklist_reason ? ` : ${o.blacklist_reason}` : '' }}.</Alert>
            <p v-else class="text-sm text-slate-600">
              Client de {{ o.store_name }} : {{ o.orders_count }} commande(s), {{ o.delivered_count }} livrée(s)<template v-if="o.refused_count">, {{ o.refused_count }} refusée(s)</template>.
            </p>
          </template>
        </section>

        <section class="card space-y-4 p-4 sm:p-6">
          <h2 class="font-semibold">Articles</h2>
          <p v-if="errors.items" class="text-sm text-red-600">{{ errors.items }}</p>
          <ul v-if="items.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
            <li v-for="(item, index) in items" :key="index" class="space-y-2 px-3 py-2">
              <div class="flex flex-wrap items-center gap-2 text-sm">
                <img v-if="item.thumb" :src="item.thumb" alt="" class="h-9 w-9 rounded object-cover" />
                <span class="min-w-0 flex-1">
                  <template v-if="item.variant_id || item.needs_variant">{{ item.title }}<span v-if="item.variant_title" class="text-slate-500"> — {{ item.variant_title }}</span></template>
                  <input v-else v-model="item.title" class="input py-1.5" placeholder="Nom de l'article" required />
                  <span v-if="item.available !== null && item.available !== undefined" class="block text-xs" :class="item.available < item.quantity ? 'text-red-600' : 'text-slate-400'">{{ item.available }} disponible(s)</span>
                </span>
                <label class="flex items-center gap-1 text-slate-500">Qté <input v-model.number="item.quantity" type="number" min="1" class="input w-16 py-1.5" required /></label>
                <label class="flex items-center gap-1 text-slate-500">Prix <input v-model.number="item.unit_price" type="number" min="0" class="input w-28 py-1.5" required @input="item.price_edited = true" /></label>
                <button type="button" class="btn-ghost px-1.5 text-red-600" @click="items.splice(index, 1)">✕</button>
              </div>
              <p v-if="!item.price_edited && (item.flash || item.volume)" class="text-xs text-emerald-700">
                {{ item.flash ? 'Prix de vente flash' : 'Prix par quantité' }}<template v-if="item.compare_at"> (au lieu de {{ money(item.compare_at) }})</template><template v-if="item.volume"> · {{ money(item.line_total) }} pour {{ item.quantity }}</template>
              </p>
              <p v-if="errors[`items.${index}.quantity`] || errors[`items.${index}.title`]" class="text-sm text-red-600">{{ errors[`items.${index}.quantity`] || errors[`items.${index}.title`] }}</p>
              <div v-if="item.needs_variant" class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-amber-800">Quelle variante ?</span>
                <button v-if="!item.choices" type="button" class="btn-secondary px-2 py-1" @click="chooseVariantFor(item)">Choisir</button>
                <button v-for="v in item.choices ?? []" :key="v.id" type="button" class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 hover:border-emerald-600" @click="setVariant(item, v)">
                  {{ v.title }} ({{ v.available }})
                </button>
              </div>
            </li>
          </ul>
          <ProductPicker @add="(line) => items.push(line)" />
          <button type="button" class="text-sm text-emerald-700 hover:underline" @click="items.push({ variant_id: null, title: '', quantity: 1, unit_price: '' })">+ Article hors catalogue</button>
        </section>

        <section class="card space-y-4 p-4 sm:p-6">
          <div class="flex items-center justify-between">
            <h2 class="font-semibold">Livraison</h2>
            <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm">
              <button type="button" class="rounded-md px-3 py-1" :class="form.delivery_method === 'delivery' ? 'bg-white shadow-sm' : ''" @click="form.delivery_method = 'delivery'">Livraison</button>
              <button type="button" class="rounded-md px-3 py-1" :class="form.delivery_method === 'pickup' ? 'bg-white shadow-sm' : ''" @click="form.delivery_method = 'pickup'">Retrait</button>
            </div>
          </div>
          <template v-if="form.delivery_method === 'delivery'">
            <Field id="place" label="Quartier / commune" :error="errors.shipping_place_id" :hint="zone ? `Zone ${zone.name} : ${money(zone.fee)}${zone.free_above ? `, offerte dès ${money(zone.free_above)}` : ''}${zone.eta_label ? ` · ${zone.eta_label}` : ''}` : place ? 'Hors de vos zones de livraison : indiquez les frais.' : ''">
              <PlacePicker id="place" v-model="place" />
            </Field>
            <div class="grid gap-4 sm:grid-cols-2">
              <Field id="address" label="Adresse">
                <input id="address" v-model="form.shipping_address" class="input" maxlength="255" />
              </Field>
              <Field id="landmark" label="Point de repère">
                <input id="landmark" v-model="form.shipping_landmark" class="input" maxlength="255" placeholder="Face pharmacie…" />
              </Field>
              <Field id="date" label="Date souhaitée (facultatif)">
                <input id="date" v-model="form.scheduled_date" class="input" type="date" />
              </Field>
              <div>
                <label class="flex items-center gap-2 text-sm"><input v-model="manualFee" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Frais de livraison saisis à la main</label>
                <input v-if="manualFee" v-model="shippingFee" class="input mt-2" type="number" min="0" placeholder="Frais (F)" />
              </div>
            </div>
          </template>
          <p v-else class="text-sm text-slate-600">Le client vient chercher sa commande : pas de frais de livraison.</p>
        </section>

        <section class="card space-y-4 p-4 sm:p-6">
          <h2 class="font-semibold">Notes</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <Field id="customer_note" label="Demande du client">
              <textarea id="customer_note" v-model="form.customer_note" class="input" rows="2" />
            </Field>
            <Field id="internal_note" label="Note interne" hint="Visible seulement par l'équipe">
              <textarea id="internal_note" v-model="form.internal_note" class="input" rows="2" />
            </Field>
          </div>
        </section>
      </div>

      <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">
        <section class="card space-y-3 p-4 text-sm">
          <h2 class="font-semibold">Récapitulatif</h2>
          <div class="flex justify-between"><span>Articles</span><span>{{ money(subtotal) }}</span></div>
          <div class="flex items-center justify-between gap-2">
            <span>Code promo</span>
            <input v-model.trim="form.discount_code" class="input w-28 py-1 text-right uppercase" maxlength="40" placeholder="—" />
          </div>
          <p v-if="form.discount_code && promo?.error" class="text-xs text-red-600">{{ promo.error }}</p>
          <p v-else-if="errors.discount_code" class="text-xs text-red-600">{{ errors.discount_code }}</p>
          <div class="flex items-center justify-between gap-2">
            <span>Remise</span>
            <span v-if="form.discount_code" class="text-emerald-700">{{ codeDiscount ? `− ${money(codeDiscount)}` : promo?.free_shipping ? 'Livraison offerte' : '—' }}</span>
            <input v-else v-model.number="form.discount_total" type="number" min="0" class="input w-28 py-1 text-right" />
          </div>
          <div v-if="loyalty" class="space-y-1 rounded-lg bg-emerald-50 p-2">
            <div class="flex items-center justify-between gap-2">
              <span>Points de fidélité <span class="text-xs text-slate-500">({{ loyalty.points }} dispo.)</span></span>
              <input v-model.number="rewardPoints" type="number" min="0" :max="loyalty.points" class="input w-24 py-1 text-right" />
            </div>
            <p v-if="pointsDiscount" class="text-right text-emerald-700">− {{ money(pointsDiscount) }}</p>
            <p v-if="errors.reward_points" class="text-xs text-red-600">{{ errors.reward_points }}</p>
          </div>
          <div class="flex justify-between"><span>Livraison</span><span>{{ fee ? money(fee) : 'Offerte' }}</span></div>
          <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold"><span>Total</span><span>{{ money(total) }}</span></div>
          <div class="flex items-center justify-between gap-2">
            <span>Acompte exigé</span>
            <input v-model="form.deposit_required" type="number" min="0" class="input w-28 py-1 text-right" placeholder="—" />
          </div>
          <label v-if="!editing" class="flex items-start gap-2 pt-2">
            <input v-model="form.confirmed" type="checkbox" class="mt-0.5 h-4 w-4 accent-emerald-700" />
            <span>Déjà confirmée avec le client <span class="block text-xs text-slate-500">Sinon elle va dans la file « À confirmer ».</span></span>
          </label>
        </section>
        <button class="btn-brand w-full py-3" :disabled="saving || !items.length">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer les modifications' : 'Créer la commande' }}</button>
      </aside>
    </div>
  </form>
</template>
