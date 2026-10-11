<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import PlacePicker from '../../../components/PlacePicker.vue'
import ProductPicker from '../../../components/ProductPicker.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { SOURCES } from '../../../lib/orders'

const props = defineProps({ id: { type: String, default: null } })
const router = useRouter()
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
const known = ref(null) // client déjà connu
const errors = ref({})
const error = ref('')
const saving = ref(false)
const loading = ref(editing.value)

onMounted(async () => {
  zones.value = (await http.get('/delivery-zones')).data.data
  if (editing.value) {
    const { data } = await http.get(`/orders/${props.id}`)
    const o = data.data
    Object.assign(form, {
      customer_phone: o.customer_phone_display, customer_name: o.customer_name ?? '', delivery_method: o.delivery_method,
      shipping_phone2: o.shipping_phone2 ?? '', shipping_address: o.shipping_address ?? '', shipping_landmark: o.shipping_landmark ?? '',
      scheduled_date: o.scheduled_date ?? '', discount_total: o.discount_total, deposit_required: o.deposit_required ?? '',
      customer_note: o.customer_note ?? '', internal_note: o.internal_note ?? '',
    })
    place.value = o.shipping_place_id ? { id: o.shipping_place_id, label: o.shipping_place_label, ancestor_ids: [] } : null
    items.value = o.items.map((i) => ({ variant_id: i.variant_id, title: i.title, variant_title: i.variant_title, quantity: i.quantity, unit_price: i.unit_price, thumb: i.thumb }))
    shippingFee.value = o.shipping_fee
    manualFee.value = true
    loading.value = false
  }
})

const subtotal = computed(() => items.value.reduce((sum, i) => sum + Number(i.unit_price || 0) * Number(i.quantity || 0), 0))
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
  if (!zone.value) return 0
  return zone.value.free_above !== null && subtotal.value >= zone.value.free_above ? 0 : zone.value.fee
})
const total = computed(() => Math.max(0, subtotal.value - Number(form.discount_total || 0)) + fee.value)

async function analyse() {
  parsing.value = true
  parseInfo.value = ''
  try {
    const { data } = await http.post('/orders/parse', { text: paste.value })
    const r = data.data
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
  if (!match) return (known.value = null)
  const detail = (await http.get(`/customers/${match.id}`)).data.data
  known.value = { ...detail, address: detail.addresses[0] }
  form.customer_name ||= detail.name ?? ''
  if (!place.value && detail.addresses[0]?.place_id) {
    place.value = { id: detail.addresses[0].place_id, label: detail.addresses[0].place_label, ancestor_ids: [] }
    form.shipping_address ||= detail.addresses[0].address_line ?? ''
    form.shipping_landmark ||= detail.addresses[0].landmark ?? ''
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
      items: items.value.map((i) => ({ variant_id: i.variant_id ?? null, title: i.variant_id ? undefined : i.title, quantity: Number(i.quantity), unit_price: Number(i.unit_price) })),
    }
    if (manualFee.value && form.delivery_method === 'delivery') payload.shipping_fee = Number(shippingFee.value || 0)
    if (editing.value) {
      delete payload.source
      delete payload.customer_phone
      delete payload.confirmed
      await http.put(`/orders/${props.id}`, payload)
      router.push(`/gestion/commandes/${props.id}`)
    } else {
      const { data } = await http.post('/orders', payload)
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
    <RouterLink :to="editing ? `/gestion/commandes/${id}` : '/gestion/commandes'" class="text-sm text-slate-500 hover:text-emerald-700">← {{ editing ? 'Commande' : 'Commandes' }}</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>

    <section v-if="!editing" class="card space-y-3 p-4 sm:p-6">
      <h2 class="font-semibold">Saisie rapide</h2>
      <p class="text-sm text-slate-600">Collez le message du client (WhatsApp, Facebook…) : numéro, nom, quartier et produits se remplissent.</p>
      <textarea v-model="paste" class="input" rows="3" placeholder="Bonsoir, je veux 2 robes wax taille M. Je suis à Yop Siporex, 07 07 07 07 07…" />
      <button type="button" class="btn-secondary" :disabled="parsing || paste.trim().length < 5" @click="analyse">{{ parsing ? 'Analyse…' : 'Analyser le message' }}</button>
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
                <label class="flex items-center gap-1 text-slate-500">Prix <input v-model.number="item.unit_price" type="number" min="0" class="input w-28 py-1.5" required /></label>
                <button type="button" class="btn-ghost px-1.5 text-red-600" @click="items.splice(index, 1)">✕</button>
              </div>
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
            <span>Remise</span>
            <input v-model.number="form.discount_total" type="number" min="0" class="input w-28 py-1 text-right" />
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
