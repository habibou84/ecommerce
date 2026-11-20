<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import DeliveryPlacePicker from '../../../components/DeliveryPlacePicker.vue'
import DelivererPicker from '../../../components/DelivererPicker.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import PlaceTapPicker from '../../../components/PlaceTapPicker.vue'
import ProductPicker from '../../../components/ProductPicker.vue'
import http, { download, errorMessage } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { SOURCES, SOURCE_ICONS } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

/**
 * Plusieurs commandes d'un coup (comme « Plusieurs courses » de Jibiat) : ce qui est commun est réglé une fois
 * (canal, jour, qui livre, confirmées), puis les clients s'ajoutent un par un dans une petite fenêtre ou en collant
 * une liste ; « Créer » enregistre chaque commande comme le formulaire, puis le bilan propose la suite pour toute la
 * série (étiquettes, faire livrer).
 */
const auth = useAuthStore()
const canAssign = computed(() => auth.can('shipments.manage'))

// ── Réglages communs ──
const common = reactive({ source: 'whatsapp', scheduled_date: '', chosen: '', confirmed: true })
const options = ref(null) // jours de livraison, mes livreurs, entreprises
const zones = ref([])
const rates = ref(null)
const pickDate = ref(false)
const pickerOpen = ref(false)
const isoDay = (offset) => {
  const d = new Date()
  d.setDate(d.getDate() + offset)
  return d.toLocaleDateString('sv-SE')
}
const dateLabel = (date) => (date === isoDay(0) ? 'aujourd\'hui' : date === isoDay(1) ? 'demain' : 'le ' + new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }))
const calendar = computed(() => options.value?.calendar ?? null)
const quickDays = computed(() => (calendar.value?.days ?? []).slice(0, 2).map((d) => ({
  ...d,
  label: d.date === isoDay(0) ? 'Aujourd\'hui' : d.date === isoDay(1) ? 'Demain' : new Date(`${d.date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }),
  disabled: !d.open || (calendar.value?.earliest && d.date < calendar.value.earliest),
})))
const otherDay = computed(() => pickDate.value || (!!common.scheduled_date && !quickDays.value.some((d) => d.date === common.scheduled_date)))
const couriers = computed(() => options.value?.couriers ?? [])
const carriers = computed(() => options.value?.carriers ?? [])
const delivererName = computed(() => {
  const [type, id] = common.chosen.split(':')
  return (type === 'courier' ? couriers.value : type === 'carrier' ? carriers.value : []).find((d) => String(d.id) === id)?.name ?? ''
})
// Qui livre : les choix en pastilles (mes livreurs disponibles d'abord, puis les entreprises), « Voir tous » sinon
const delivererChips = computed(() => [
  ...couriers.value.filter((c) => c.is_available).map((c) => ({ key: `courier:${c.id}`, name: c.name })),
  ...carriers.value.map((c) => ({ key: `carrier:${c.id}`, name: c.name })),
].slice(0, 6))

onMounted(async () => {
  http.get('/delivery-rates').then(({ data }) => (rates.value = data.data)).catch(() => {})
  http.get('/delivery-zones').then(({ data }) => (zones.value = data.data)).catch(() => {})
  try {
    options.value = (await http.post('/orders/delivery-options', { place_id: null, items: [] })).data.data
  } catch {
    options.value = null
  }
  common.scheduled_date = calendar.value?.earliest ?? isoDay(0)
  // Habitudes du formulaire de commande : le même canal et le même jour que la dernière fois
  try {
    const h = JSON.parse(localStorage.getItem('jm.commande.habitudes') || '{}') || {}
    if (h.source && SOURCES.some((s) => s.value === h.source)) common.source = h.source
    const tomorrow = isoDay(1)
    if (h.day === 'tomorrow' && common.scheduled_date < tomorrow && calendar.value?.days.some((d) => d.date === tomorrow && d.open)) common.scheduled_date = tomorrow
  } catch {
    // Navigation privée : rien n'est gardé
  }
})

// ── Les commandes de la série ──
let nextKey = 1
const blankRow = () => ({ key: nextKey++, phone: '', name: '', place: null, address: '', note: '', items: [], order: null, error: '' })
const rows = ref([])
const zoneOf = (place) => {
  if (!place) return null
  for (const id of [place.id, ...(place.ancestor_ids ?? [])]) {
    const found = zones.value.find((z) => z.is_active && z.places.some((p) => p.id === id))
    if (found) return found
  }
  return null
}
const itemsTotal = (r) => r.items.reduce((s, i) => s + (Number(i.unit_price) || 0) * (Number(i.quantity) || 0), 0)
// Frais affichés : ceux de vos zones (l'entreprise choisie applique son tarif à l'enregistrement)
const rowFee = (r) => (common.chosen.startsWith('carrier:') ? null : zoneOf(r.place)?.fee ?? null)
const missingOf = (r) => [
  r.phone.replace(/\D/g, '').length < 8 && 'téléphone',
  !r.place && 'commune',
  !r.items.length && 'articles',
  r.items.some((i) => i.needs_variant) && 'variante',
].filter(Boolean)
const isComplete = (r) => !missingOf(r).length
const pending = computed(() => rows.value.filter((r) => !r.order))
const ready = computed(() => pending.value.filter(isComplete))
const incomplete = computed(() => pending.value.filter((r) => !isComplete(r)))
const feesTotal = computed(() => ready.value.reduce((s, r) => s + (rowFee(r) ?? 0), 0))
const grandTotal = computed(() => ready.value.reduce((s, r) => s + itemsTotal(r) + (rowFee(r) ?? 0), 0))
const label = (r) => r.name || r.phone || 'Client sans numéro'
const itemsLabel = (r) => r.items.map((i) => `${i.quantity} × ${i.title}${i.variant_title ? ` ${i.variant_title}` : ''}`).join(', ')
function remove(r) {
  rows.value = rows.value.filter((x) => x !== r)
}

// ── Coller une liste : un client par paragraphe (ou par ligne) ──
const pasteOpen = ref(false)
const listText = ref('')
const parsing = ref(false)
const parseInfo = ref('')
async function placeWithAncestors(place) {
  if (!place?.label) return place
  try {
    const results = (await http.get('/places', { params: { search: place.label.split(',')[0] } })).data.data
    return results.find((p) => p.id === place.id) ?? { ...place, ancestor_ids: [] }
  } catch {
    return { ...place, ancestor_ids: [] }
  }
}
async function parseList() {
  const text = listText.value.trim()
  if (!text) return
  const blocks = (/\n\s*\n/.test(text) ? text.split(/\n\s*\n/) : text.split('\n')).map((b) => b.trim()).filter((b) => b.length >= 5)
  parsing.value = true
  parseInfo.value = ''
  let added = 0
  for (const block of blocks) {
    try {
      const r = (await http.post('/orders/parse', { text: block })).data.data
      const row = blankRow()
      row.phone = r.phone ? phoneDisplay(r.phone) : ''
      row.name = r.customer_name ?? r.customer?.name ?? ''
      const place = r.place ?? (r.customer?.address?.place_id ? { id: r.customer.address.place_id, label: r.customer.address.place_label } : null)
      row.place = place ? await placeWithAncestors(place) : null
      row.address = [r.address, r.landmark].filter(Boolean).join(', ') || (r.customer?.address?.address_line ?? '')
      row.note = r.note ?? ''
      row.items = (r.items ?? []).map((i) => ({ ...i }))
      rows.value.push(row)
      added++
    } catch (e) {
      parseInfo.value = errorMessage(e)
    }
  }
  parsing.value = false
  if (added) {
    parseInfo.value = `${added} client${added > 1 ? 's' : ''} ajouté${added > 1 ? 's' : ''} : vérifiez ceux en orange.`
    listText.value = ''
    pasteOpen.value = false
  }
}

// ── Fenêtre d'un client ──
const draft = ref(null) // copie de la ligne modifiée
const draftIsNew = ref(false)
let editing = null
const known = ref(null)
function edit(r) {
  editing = r
  draftIsNew.value = !r
  draft.value = r ? JSON.parse(JSON.stringify(r)) : blankRow()
  known.value = null
  nextTick(() => document.getElementById('d-phone')?.focus())
}
function formatPhone(value) {
  const digits = value.replace(/\D/g, '')
  if (value.trim().startsWith('+') || digits.length > 10) return value
  return digits.slice(0, 10).replace(/(\d{2})(?=\d)/g, '$1 ')
}
async function lookup() {
  const digits = draft.value.phone.replace(/\D/g, '')
  if (digits.length < 8) return
  try {
    const { data } = await http.get('/customers', { params: { search: digits.slice(-8) } })
    const match = data.data.find((c) => c.phone.replace(/\D/g, '').endsWith(digits.slice(-10)))
    if (!match) return (known.value = null)
    const detail = (await http.get(`/customers/${match.id}`)).data.data
    known.value = { ...detail, address: detail.addresses?.[0] ?? null }
  } catch {
    known.value = null
  }
}
async function useKnown() {
  const k = known.value
  draft.value.name ||= k.name ?? ''
  if (k.address?.place_id) {
    draft.value.place = await placeWithAncestors({ id: k.address.place_id, label: k.address.place_label })
    draft.value.address ||= [k.address.address_line, k.address.landmark].filter(Boolean).join(', ')
  }
  known.value = null
}
function focusPlace() {
  document.getElementById('d-place')?.focus()
}
function step(item, delta) {
  item.quantity = Math.max(1, (Number(item.quantity) || 1) + delta)
}
async function chooseVariantFor(item) {
  const { data } = await http.get(`/products/${item.product_id}`)
  item.choices = data.data.variants.filter((v) => v.is_active)
}
function setVariant(item, variant) {
  Object.assign(item, { variant_id: variant.id, variant_title: variant.title, unit_price: variant.price, needs_variant: false, choices: null, available: variant.available })
}
function saveDraft(next) {
  const row = draft.value
  if (editing) Object.assign(editing, row, { error: '' })
  else rows.value.push(row)
  if (next) edit(null)
  else draft.value = null
}

// ── Créer toute la série ──
const sending = ref(false)
const sentCount = ref(0)
const done = ref(false)
const dateChosen = computed(() => !!common.scheduled_date)
async function createAll() {
  if (!ready.value.length || !dateChosen.value) return
  sending.value = true
  sentCount.value = 0
  const [type, id] = common.chosen.split(':')
  for (const r of ready.value) {
    try {
      r.order = (await http.post('/orders', {
        source: common.source,
        customer_phone: r.phone,
        customer_name: r.name || null,
        delivery_method: 'delivery',
        shipping_place_id: r.place?.id ?? null,
        shipping_address: r.address || null,
        shipping_landmark: null,
        scheduled_date: common.scheduled_date,
        customer_note: r.note || null,
        confirmed: common.confirmed,
        planned_courier_id: type === 'courier' ? Number(id) : null,
        planned_carrier_connection_id: type === 'carrier' ? Number(id) : null,
        items: r.items.map((i) => ({ variant_id: i.variant_id ?? null, title: i.variant_id ? undefined : i.title, quantity: Number(i.quantity), unit_price: Number(i.unit_price) })),
      })).data.data
      r.error = ''
    } catch (e) {
      r.error = errorMessage(e)
    }
    sentCount.value++
  }
  sending.value = false
  done.value = true
  window.scrollTo({ top: 0 })
}
const created = computed(() => rows.value.filter((r) => r.order))
const failed = computed(() => rows.value.filter((r) => !r.order))

// ── Bilan : la suite pour toute la série ──
const busy = ref(false)
const notice = ref('')
const error = ref('')
async function printLabels() {
  busy.value = true
  try {
    await download(`/orders/labels?${created.value.map((r) => `ids[]=${r.order.id}`).join('&')}`, 'etiquettes.pdf')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
function fixRemaining() {
  rows.value = failed.value
  done.value = false
  notice.value = ''
}
function restart() {
  rows.value = []
  done.value = false
  notice.value = ''
  error.value = ''
}
watch(done, () => (notice.value = ''))
</script>

<template>
  <!-- Bilan de la série -->
  <div v-if="done" class="mx-auto max-w-lg space-y-4 pb-24 pt-2">
    <div class="text-center">
      <div class="flex justify-center"><ColorIcon name="livre" :size="80" /></div>
      <h2 class="mt-3 text-2xl font-extrabold">{{ created.length }} commande{{ created.length > 1 ? 's' : '' }} créée{{ created.length > 1 ? 's' : '' }}</h2>
      <p class="text-slate-600">Livraison prévue {{ dateLabel(common.scheduled_date) }}</p>
      <p v-if="failed.length" class="mt-1 text-sm font-semibold text-red-700">{{ failed.length }} n'{{ failed.length > 1 ? 'ont' : 'a' }} pas pu être créée{{ failed.length > 1 ? 's' : '' }} : corrigez-l{{ failed.length > 1 ? 'es' : 'a' }} et recréez-l{{ failed.length > 1 ? 'es' : 'a' }}.</p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>
    <ul class="card divide-y divide-slate-100 text-sm">
      <li v-for="(r, i) in rows" :key="r.key" class="flex items-center gap-3 px-4 py-3">
        <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-extrabold text-white" :class="r.order ? 'bg-emerald-700' : 'bg-orange-500'">{{ i + 1 }}</span>
        <span class="min-w-0 flex-1"><b class="block truncate">{{ label(r) }}</b><span class="block truncate text-slate-600">{{ r.place?.label?.split(',')[0] ?? 'commune ?' }}</span></span>
        <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="shrink-0 font-bold text-emerald-700 hover:underline">{{ r.order.reference }}</RouterLink>
        <span v-else class="max-w-[50%] shrink-0 text-right text-xs font-semibold text-red-700">{{ r.error || 'Non créée' }}</span>
      </li>
    </ul>
    <button v-if="created.length" type="button" class="btn-brand min-h-12 w-full text-base" :disabled="busy" @click="printLabels"><ColorIcon name="etiquette" :size="26" :tile="false" />{{ created.length > 1 ? `Imprimer les ${created.length} étiquettes` : 'Imprimer l\'étiquette' }}</button>
    <!-- Qui livre : déjà choisi pour la série (confiées dès la création si confirmées), sinon à choisir dans la liste -->
    <p v-if="created.length && common.chosen" class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm font-semibold text-emerald-900"><Icon name="truck" :size="18" class="shrink-0" />{{ common.confirmed ? `Confiée${created.length > 1 ? 's' : ''} à ${delivererName}.` : `${delivererName} prévu : la course partira à la confirmation.` }}</p>
    <RouterLink v-else-if="created.length && canAssign" to="/gestion/commandes?status=to_ship" class="btn-secondary min-h-12 w-full justify-center"><Icon name="truck" :size="18" />Choisir qui livre (À faire livrer)</RouterLink>
    <button v-if="failed.length" type="button" class="btn-secondary w-full" @click="fixRemaining">Corriger {{ failed.length > 1 ? `les ${failed.length} qui restent` : 'celle qui reste' }}</button>
    <div class="grid grid-cols-2 gap-2">
      <RouterLink to="/gestion/commandes" class="btn-secondary justify-center">Voir mes commandes</RouterLink>
      <button type="button" class="btn-brand" @click="restart">Nouvelle série</button>
    </div>
  </div>

  <div v-else class="space-y-3 pb-56 lg:pb-6">
    <RouterLink to="/gestion/commandes/nouvelle" class="back-link text-sm text-slate-500 hover:text-emerald-700">← Une seule commande</RouterLink>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-5">
      <div class="min-w-0 space-y-3">
        <!-- Réglages communs, choisis une fois -->
        <section class="card space-y-3 p-4 sm:p-5">
          <h2 class="flex items-center gap-2.5 text-lg font-extrabold"><ColorIcon name="reglages" :size="34" />Pour toutes ces commandes</h2>
          <div>
            <p class="label">Vendu sur</p>
            <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" role="group" aria-label="Canal de vente">
              <button v-for="s in SOURCES" :key="s.value" type="button" class="inline-flex min-h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 text-sm font-semibold"
                      :class="common.source === s.value ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-emerald-500'" :aria-pressed="common.source === s.value" @click="common.source = s.value">
                <Icon :name="SOURCE_ICONS[s.value] ?? 'more'" :size="15" />{{ s.label }}
              </button>
            </div>
          </div>
          <div>
            <p class="label">Quand livrer <span class="text-red-600">*</span></p>
            <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="Jour de livraison">
              <button v-for="d in quickDays" :key="d.date" type="button" role="radio" :aria-checked="!otherDay && common.scheduled_date === d.date" :disabled="d.disabled"
                      class="rounded-xl border-2 px-1 py-2 text-center text-sm font-bold"
                      :class="!otherDay && common.scheduled_date === d.date ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : d.disabled ? 'cursor-not-allowed border-slate-200 text-slate-400 line-through' : 'border-slate-200 text-slate-700'"
                      @click="pickDate = false; common.scheduled_date = d.date">
                <span class="block">{{ d.label }}</span>
                <span class="block text-xs font-medium" :class="d.disabled ? '' : 'text-slate-600'">{{ new Date(`${d.date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }) }}</span>
              </button>
              <button type="button" role="radio" :aria-checked="otherDay" class="rounded-xl border-2 px-1 py-2 text-center text-sm font-bold" :class="otherDay ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'" @click="pickDate = true">
                <span class="block">Autre date</span><span class="block text-xs font-medium text-slate-600">📅</span>
              </button>
            </div>
            <input v-if="otherDay" v-model="common.scheduled_date" type="date" :min="calendar?.earliest ?? isoDay(0)" aria-label="Date de livraison" class="input mt-2 w-auto" />
          </div>
          <div>
            <p class="label">Qui livre</p>
            <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" role="group" aria-label="Qui livre">
              <button type="button" class="inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-full border px-3 text-sm font-semibold" :class="!common.chosen ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700'" :aria-pressed="!common.chosen" @click="common.chosen = ''">Décider plus tard</button>
              <button v-for="d in delivererChips" :key="d.key" type="button" class="inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-full border px-3 text-sm font-semibold" :class="common.chosen === d.key ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700'" :aria-pressed="common.chosen === d.key" @click="common.chosen = d.key">{{ d.name }}</button>
              <button v-if="common.chosen && !delivererChips.some((d) => d.key === common.chosen)" type="button" class="inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-full border border-emerald-700 bg-emerald-700 px-3 text-sm font-semibold text-white" aria-pressed="true">{{ delivererName }}</button>
              <button type="button" class="inline-flex min-h-9 shrink-0 items-center whitespace-nowrap rounded-full border border-dashed border-slate-300 px-3 text-sm font-semibold text-emerald-700" @click="pickerOpen = true">Voir tous ›</button>
            </div>
          </div>
        </section>

        <!-- Coller une liste : un client par paragraphe ou par ligne -->
        <section v-if="pasteOpen" class="card space-y-3 border-emerald-100 bg-emerald-50/60 p-4">
          <div class="flex items-center justify-between gap-2">
            <label for="list" class="font-bold">Collez la liste des clients</label>
            <button type="button" class="text-sm font-semibold text-slate-600 hover:underline" @click="pasteOpen = false">Fermer</button>
          </div>
          <textarea id="list" v-model="listText" rows="6" class="input" placeholder="Aya 07 01 23 45 67 Abobo 2 robes wax M&#10;Jean 05 44 55 66 77 Cocody Riviera 1 sac pagne&#10;Fatou 01 22 33 44 55 Yopougon collier" />
          <p class="explain-detail">Un client par ligne, ou un paragraphe par client : nom, numéro, commune, articles.</p>
          <button type="button" class="btn-brand" :disabled="parsing || !listText.trim()" @click="parseList">{{ parsing ? 'Analyse…' : 'Ajouter ces clients' }}</button>
        </section>
        <button v-else type="button" class="flex w-full items-center gap-3 rounded-[22px] border-2 border-dashed border-emerald-200 bg-emerald-50/70 p-3.5 text-left" @click="pasteOpen = true">
          <ColorIcon name="coller" :size="46" />
          <span class="min-w-0"><span class="block text-base font-bold text-slate-900">Coller une liste de clients</span><span class="block text-sm text-slate-600">Un client par ligne : nom, numéro, commune, articles.</span></span>
        </button>
        <Alert v-if="parseInfo" tone="info">{{ parseInfo }}</Alert>

        <!-- Les commandes de la série -->
        <div class="flex items-center justify-between px-1">
          <h2 class="text-sm font-extrabold uppercase tracking-wider text-slate-600">{{ pending.length }} commande{{ pending.length > 1 ? 's' : '' }}</h2>
          <span v-if="incomplete.length" class="text-sm font-bold text-orange-700">{{ incomplete.length }} à compléter</span>
        </div>
        <ul v-if="pending.length" class="card divide-y divide-slate-100">
          <li v-for="(r, i) in pending" :key="r.key" class="flex items-center gap-3 px-3 py-3">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-extrabold text-white" :class="isComplete(r) ? 'bg-emerald-700' : 'bg-orange-500'">{{ i + 1 }}</span>
            <button type="button" class="min-w-0 flex-1 text-left text-sm" @click="edit(r)">
              <b class="block truncate">{{ label(r) }}<span v-if="r.name && r.phone" class="font-normal text-slate-600"> · {{ r.phone }}</span></b>
              <span class="block truncate text-slate-600">{{ r.place?.label?.split(',')[0] ?? 'commune ?' }}<template v-if="r.items.length"> · {{ itemsLabel(r) }} · {{ money(itemsTotal(r) + (rowFee(r) ?? 0)) }}</template></span>
              <span v-if="!isComplete(r)" class="block text-xs font-bold text-orange-700">À compléter : {{ missingOf(r).join(', ') }}</span>
              <span v-else-if="r.error" class="block text-xs font-bold text-red-700">{{ r.error }}</span>
            </button>
            <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-slate-500 hover:bg-slate-100" :aria-label="`Retirer la commande ${i + 1}`" @click="remove(r)"><Icon name="close" :size="16" /></button>
          </li>
        </ul>
        <p v-else class="rounded-2xl border border-dashed border-slate-300 px-4 py-6 text-center text-sm text-slate-600">Ajoutez vos clients un par un, ou collez une liste.</p>
        <button type="button" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-slate-300 bg-white font-bold text-slate-700 hover:border-emerald-500" @click="edit(null)"><Icon name="plus" :size="18" />Ajouter un client</button>
      </div>

      <!-- Ordinateur : le récapitulatif à droite -->
      <aside class="hidden lg:block lg:sticky lg:top-20 lg:self-start">
        <section class="card space-y-3 p-5">
          <h2 class="text-base font-extrabold">Récapitulatif</h2>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-slate-600">Commandes prêtes</span><span>{{ ready.length }}</span></div>
            <div class="flex justify-between"><span class="text-slate-600">Livraisons</span><span>{{ common.chosen.startsWith('carrier:') ? `tarif ${delivererName}` : money(feesTotal) }}</span></div>
          </div>
          <div class="flex items-baseline justify-between border-t border-slate-200 pt-3"><span class="font-semibold">À encaisser en tout</span><span class="text-2xl font-extrabold tabular-nums">{{ money(grandTotal) }}</span></div>
          <label class="flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
            <input v-model="common.confirmed" type="checkbox" class="peer sr-only" />
            <span class="h-6 w-11 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-5 w-5 rounded-full bg-white shadow transition" :class="{ 'translate-x-5': common.confirmed }" /></span>
            <span class="text-sm font-semibold">Déjà confirmées avec les clients</span>
          </label>
          <p v-if="incomplete.length" class="text-sm font-semibold text-orange-700">Il manque : {{ incomplete.map((r) => `${missingOf(r)[0]} de ${label(r)}`).join(', ') }}</p>
          <button type="button" class="btn-brand w-full py-3 text-base" :disabled="sending || !ready.length || !dateChosen" @click="createAll">{{ sending ? `Création… ${sentCount}/${ready.length}` : ready.length ? `Créer ${ready.length > 1 ? `les ${ready.length} commandes` : 'la commande'}` : 'Ajoutez au moins un client' }}</button>
        </section>
      </aside>
    </div>

    <!-- Téléphone : totaux et création, toujours en bas -->
    <div class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 space-y-2 border-t border-slate-200 bg-white/95 px-4 py-2.5 shadow-[0_-6px_18px_rgba(15,23,42,0.06)] backdrop-blur lg:hidden">
      <label class="flex cursor-pointer items-center gap-2.5">
        <input v-model="common.confirmed" type="checkbox" class="peer sr-only" />
        <span class="h-5 w-9 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-4 w-4 rounded-full bg-white shadow transition" :class="{ 'translate-x-4': common.confirmed }" /></span>
        <span class="text-sm font-semibold">Déjà confirmées avec les clients</span>
      </label>
      <div v-if="ready.length" class="flex items-end justify-between gap-3 text-sm">
        <p class="min-w-0 text-slate-600"><span class="block">Livraisons <b class="text-slate-900">{{ common.chosen.startsWith('carrier:') ? `tarif ${delivererName}` : money(feesTotal) }}</b></span><span class="block">À encaisser en tout</span></p>
        <p class="shrink-0 text-right"><span class="block text-xs text-slate-600">{{ ready.length }} prête{{ ready.length > 1 ? 's' : '' }}</span><span class="text-xl font-extrabold tabular-nums text-emerald-800">{{ money(grandTotal) }}</span></p>
      </div>
      <p v-if="incomplete.length" class="truncate text-sm font-semibold text-orange-700">Il manque : {{ incomplete.map((r) => `${missingOf(r)[0]} de ${label(r)}`).join(', ') }}</p>
      <button type="button" class="btn-brand min-h-12 w-full text-base" :disabled="sending || !ready.length || !dateChosen" @click="createAll">{{ sending ? `Création… ${sentCount}/${ready.length}` : ready.length ? `Créer ${ready.length > 1 ? `les ${ready.length} commandes` : 'la commande'}` : 'Ajoutez au moins un client' }}</button>
    </div>

    <!-- Fenêtre d'un client -->
    <Modal v-if="draft" :title="draftIsNew ? `Nouveau client · ${pending.length + 1}ᵉ commande` : 'Modifier le client'" @close="draft = null">
      <form class="space-y-3" @submit.prevent="saveDraft(false)">
        <div>
          <label class="label" for="d-phone">Téléphone <span class="text-red-600">*</span></label>
          <input id="d-phone" v-model="draft.phone" class="input-lg !text-xl !font-bold" type="tel" inputmode="tel" autocomplete="off" placeholder="07 00 00 00 00" @input="draft.phone = formatPhone(draft.phone)" @blur="lookup" />
        </div>
        <button v-if="known" type="button" class="flex w-full items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-left text-sm" @click="useKnown">
          <span class="min-w-0 flex-1"><b class="block truncate">{{ known.name || 'Client connu' }}</b><span class="block truncate text-slate-600">{{ [known.address?.place_label?.split(',')[0], `${known.orders_count} commande(s)`].filter(Boolean).join(' · ') }}</span></span>
          <span class="shrink-0 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white">Utiliser</span>
        </button>
        <div>
          <label class="label" for="d-name">Nom <span class="font-normal text-slate-600">(facultatif)</span></label>
          <input id="d-name" v-model="draft.name" class="input text-base" maxlength="120" autocomplete="off" placeholder="Ex. : Fatou Diallo" />
        </div>
        <div>
          <p class="label">Commune ou quartier <span class="text-red-600">*</span></p>
          <PlaceTapPicker v-if="!draft.place && rates?.home" class="mb-2" :rates="rates" @pick="draft.place = $event" @search="focusPlace" />
          <DeliveryPlacePicker id="d-place" v-model="draft.place" />
          <p v-if="draft.place && zoneOf(draft.place)" class="mt-2 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-sm font-bold text-emerald-800">Livraison : {{ money(zoneOf(draft.place).fee) }}</p>
        </div>
        <div>
          <p class="label">Articles <span class="text-red-600">*</span></p>
          <ProductPicker @add="(line) => draft.items.push(line)" />
          <ul v-if="draft.items.length" class="mt-2 space-y-2">
            <li v-for="(item, index) in draft.items" :key="index" class="rounded-xl border border-slate-200 p-2.5">
              <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-sm font-semibold">{{ item.title }}<span v-if="item.variant_title" class="font-normal text-slate-600"> · {{ item.variant_title }}</span></p>
                  <p class="text-xs text-slate-600">{{ money(Number(item.unit_price) || 0) }}<template v-if="item.available !== null && item.available !== undefined"> · {{ item.available }} en stock</template></p>
                </div>
                <div class="flex items-center gap-2">
                  <button type="button" class="grid h-8 w-8 place-items-center rounded-full bg-slate-100 font-bold disabled:opacity-40" :disabled="item.quantity <= 1" aria-label="Moins" @click="step(item, -1)">−</button>
                  <span class="w-6 text-center font-bold">{{ item.quantity }}</span>
                  <button type="button" class="grid h-8 w-8 place-items-center rounded-full bg-emerald-700 font-bold text-white" aria-label="Plus" @click="step(item, 1)">+</button>
                </div>
                <button type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Retirer ${item.title}`" @click="draft.items.splice(index, 1)"><Icon name="close" :size="16" /></button>
              </div>
              <div v-if="item.needs_variant" class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-amber-800">Quelle variante ?</span>
                <button v-if="!item.choices" type="button" class="btn-secondary px-2 py-1" @click="chooseVariantFor(item)">Choisir</button>
                <button v-for="v in item.choices ?? []" :key="v.id" type="button" class="rounded-full border border-field bg-white px-3 py-1 hover:border-emerald-600" @click="setVariant(item, v)">{{ v.title }} ({{ v.available }})</button>
              </div>
            </li>
          </ul>
        </div>
        <div>
          <label class="label" for="d-address">Adresse et repère</label>
          <input id="d-address" v-model="draft.address" class="input text-base" maxlength="255" autocomplete="off" placeholder="Ex. : face pharmacie" />
        </div>
        <p v-if="missingOf(draft).length" class="text-sm font-semibold text-orange-700">À compléter : {{ missingOf(draft).join(', ') }} (vous pouvez enregistrer et finir plus tard).</p>
        <div class="grid grid-cols-2 gap-2 pt-1">
          <button class="btn-secondary min-h-12">Enregistrer</button>
          <button type="button" class="btn-brand min-h-12" @click="saveDraft(true)">Enregistrer et suivant</button>
        </div>
      </form>
    </Modal>

    <DelivererPicker v-if="pickerOpen" v-model="common.chosen" :options="options" place-label="" @close="pickerOpen = false" />
  </div>
</template>
