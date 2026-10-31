<script setup>
import AiMeter from '../../../components/AiMeter.vue'
import { aiExhausted, countAiAction } from '../../../lib/aiQuota'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import DelivererPicker from '../../../components/DelivererPicker.vue'
import DeliveryPlacePicker from '../../../components/DeliveryPlacePicker.vue'
import PlaceTapPicker from '../../../components/PlaceTapPicker.vue'
import ProductPicker from '../../../components/ProductPicker.vue'
import http, { download, errorMessage, fieldErrors } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { SOURCES, SOURCE_ICONS } from '../../../lib/orders'
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
  // Expédition vers une autre ville : qui paie la compagnie de transport, paiement avant de partir à la gare
  expedition_payer: 'arrival',
  expedition_prepay: null,
})
const blank = { ...form } // formulaire vide, pour « Nouvelle commande »
const place = ref(null)
const items = ref([])
const manualFee = ref(false)
const shippingFee = ref('')
const zones = ref([])
// Prix de livraison : communes et quartiers à toucher, ville de la boutique, expéditions
const rates = ref(null)

const paste = ref('')
const parsing = ref(false)
const parseInfo = ref('')
const auth = useAuthStore()
// Agent de confirmation : prix, remise, frais et livreur affichés mais réservés à la gestion des commandes
const canPrice = computed(() => auth.can('orders.manage'))
const known = ref(null) // client déjà connu
const elsewhere = ref([]) // le même numéro dans les autres boutiques de l'adresse
const errors = ref({})
const error = ref('')
const saving = ref(false)
const loading = ref(editing.value)
// Commande créée depuis la boîte « Messages » : le client est l'expéditeur du message
const conversation = ref(null)

onMounted(async () => {
  http.get('/delivery-rates').then(({ data }) => (rates.value = data.data)).catch(() => {})
  zones.value = (await http.get('/delivery-zones')).data.data
  if (editing.value) {
    const { data } = await http.get(`/orders/${props.id}`)
    const o = data.data
    original.value = deliverySignature(o)
    Object.assign(form, {
      customer_phone: o.customer_phone_display, customer_name: o.customer_name ?? '', delivery_method: o.delivery_method,
      shipping_phone2: o.shipping_phone2 ?? '', shipping_address: joinAddress(o.shipping_address, o.shipping_landmark), shipping_landmark: '',
      scheduled_date: o.scheduled_date ?? '', discount_total: o.discount_total, discount_code: o.discount_code ?? '', deposit_required: o.deposit_required ?? '',
      customer_note: o.customer_note ?? '', internal_note: o.internal_note ?? '',
      expedition_payer: o.expedition?.payer ?? 'arrival', expedition_prepay: o.expedition?.prepay ?? null,
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
  // Messages choisis dans la conversation (« Faire une commande ») ; sinon tout ce que le client a écrit depuis la dernière commande
  const ids = String(route.query.messages ?? '').split(',').map(Number).filter(Boolean)
  const picked = c.messages.filter((m) => m.direction === 'in' && ids.includes(m.id)).map((m) => m.body)
  paste.value = picked.length ? picked.join('\n') : c.order_text
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
  // Lieu sans sa commune connue ici (adresse reprise d'un client) : la zone trouvée par le serveur
  return options.value?.place?.id === place.value.id ? options.value.zone : null
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
// Livreur créé depuis « Qui livre ? » : ajouté à la liste pour être choisi tout de suite
function addCourier(c) {
  if (!options.value) return
  options.value.couriers = [...(options.value.couriers ?? []), { id: c.id, name: c.name, is_available: true, active_missions: 0, covers_zone: false, connected: false }]
}
const chosenCourier = computed(() => (chosen.value.startsWith('courier:') ? options.value?.couriers.find((c) => `courier:${c.id}` === chosen.value) ?? null : null))
const chosenCarrier = computed(() => (chosen.value.startsWith('carrier:') ? options.value?.carriers.find((c) => `carrier:${c.id}` === chosen.value) ?? null : null))
// Une entreprise choisie qui a un tarif pour ce quartier : c'est son prix
const carrierFee = computed(() => (chosenCarrier.value?.covered && chosenCarrier.value.fee !== null ? chosenCarrier.value.fee : null))
const ready = ref(false)
watch(chosen, () => {
  if (editing.value && ready.value) manualFee.value = false // nouveau choix : frais recalculés
})
const freeShipping = computed(() => !!(promo.value?.free_shipping && !promo.value.error))
// Autre ville que celle de la boutique : expédition (livraison jusqu'à la gare, puis compagnie de transport)
const expedition = computed(() => (form.delivery_method === 'delivery' && place.value && options.value?.place?.id === place.value.id ? options.value.expedition : null))
watch(expedition, (e) => {
  if (e && form.expedition_prepay === null) form.expedition_prepay = e.prepay
})
const fee = computed(() => {
  if (form.delivery_method === 'pickup') return 0
  if (manualFee.value) return Number(shippingFee.value || 0)
  if (freeShipping.value) return 0
  if (carrierFee.value !== null) return carrierFee.value
  if (expedition.value) return expedition.value.fee ?? 0
  if (!zone.value) return 0
  return zone.value.free_above !== null && subtotal.value >= zone.value.free_above ? 0 : zone.value.fee
})
const feeSource = computed(() => {
  if (form.delivery_method === 'pickup') return ''
  if (manualFee.value) return 'saisis à la main'
  if (freeShipping.value) return 'offerts par le code promo'
  if (carrierFee.value !== null) return `tarif ${chosenCarrier.value.name}${chosenCarrier.value.zone ? `, zone ${chosenCarrier.value.zone}` : ''}`
  if (expedition.value) return expedition.value.fee !== null ? 'livraison jusqu\'à la gare' : 'livraison jusqu\'à la gare : indiquez le prix'
  if (zone.value) return `votre zone ${zone.value.name}${zone.value.eta_label ? ` · ${zone.value.eta_label}` : ''}`
  return place.value ? 'hors de vos zones : indiquez les frais' : ''
})
function editFee() {
  shippingFee.value = fee.value
  manualFee.value = true
}

// Prix tapé à la main : proposé à retenir pour la suite (commune, quartier ou toutes les expéditions)
const askRemember = ref(false)
const remembered = ref(null) // { text, undo }
const autoFee = computed(() => {
  if (carrierFee.value !== null) return carrierFee.value
  if (expedition.value) return expedition.value.fee
  return zone.value?.fee ?? null
})
const rememberChoices = computed(() => {
  const p = options.value?.place
  if (!askRemember.value || !manualFee.value || !canPrice.value || !p || carrierFee.value !== null || p.id !== place.value?.id) return []
  if (shippingFee.value === '' || Number(shippingFee.value) === autoFee.value) return []
  if (expedition.value) return [{ key: 'expedition', label: 'Pour toutes les expéditions', hint: 'Livraison jusqu\'à la gare, quelle que soit la ville' }]
  if (p.commune) {
    return [
      { key: 'commune', id: p.commune.id, name: p.commune.name, label: `Pour tout ${p.commune.name}`, hint: `Toutes les prochaines commandes à ${p.commune.name}` },
      { key: 'place', id: p.id, name: p.name, label: `Seulement pour ${p.name}`, hint: autoFee.value !== null ? `Le reste de ${p.commune.name} reste à ${money(autoFee.value)}` : '' },
    ]
  }
  return [{ key: 'place', id: p.id, name: p.name, label: `Pour ${p.name}`, hint: `Toutes les prochaines commandes à ${p.name}` }]
})
async function refreshRates() {
  const [r, z] = await Promise.all([http.get('/delivery-rates'), http.get('/delivery-zones')])
  rates.value = r.data.data
  zones.value = z.data.data
  await loadOptions()
}
async function remember(choice) {
  const amount = Number(shippingFee.value || 0)
  const before = { fee: autoFee.value, learned: !!(zone.value && choice.name && zone.value.name === choice.name), expedition: rates.value?.expedition?.fee ?? null }
  try {
    await http.post('/delivery-rates/remember', choice.key === 'expedition' ? { expedition: true, fee: amount } : { place_id: choice.id, fee: amount })
    await refreshRates()
    manualFee.value = false
    askRemember.value = false
    remembered.value = {
      text: `✓ ${money(amount)} retenu ${choice.key === 'expedition' ? 'pour les expéditions' : `pour ${choice.name}`}.`,
      undo: async () => {
        if (choice.key === 'expedition') await http.put('/delivery-rates', { expedition_fee: before.expedition })
        else if (before.learned && before.fee !== null) await http.post('/delivery-rates/remember', { place_id: choice.id, fee: before.fee })
        else await http.delete(`/delivery-rates/places/${choice.id}`)
        remembered.value = null
        await refreshRates()
      },
    }
    setTimeout(() => (remembered.value = null), 8000)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
const PAYERS = [
  ['arrival', 'Le client, à l\'arrivée', 'Il paie la compagnie en récupérant son colis'],
  ['before', 'Le client, avant le départ', 'Ajoutés à la commande quand le livreur les connaît'],
  ['store', 'La boutique (offerts)', 'Comptés comme une dépense'],
]

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
// Date de livraison obligatoire : la première date possible est choisie d'office (heure limite et jours sans
// livraison de la boutique ; journée de l'entreprise choisie quand elle est plus tardive) ; les jours fermés sont grisés
const calendar = computed(() => options.value?.calendar ?? null)
const earliestDate = computed(() => {
  if (form.delivery_method !== 'delivery') return null
  const dates = [calendar.value?.earliest, chosenCarrier.value?.earliest_date].filter(Boolean)
  return dates.length ? dates.sort().at(-1) : null
})
const dayChoices = computed(() => (calendar.value?.days ?? []).slice(0, 4).map((d) => ({
  ...d,
  label: d.date === isoDay(0) ? 'Aujourd\'hui' : d.date === isoDay(1) ? 'Demain' : new Date(`${d.date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }),
  disabled: !d.open || (earliestDate.value && d.date < earliestDate.value),
})))
const dateLabel = (date) => (date === isoDay(1) ? 'demain' : 'le ' + new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }))
const dateNotice = ref('')
// Date vide, ou trop tôt pour qui livre : la première date possible, avec un mot d'explication
watch(earliestDate, (min) => {
  if (!min) return
  if (!form.scheduled_date) {
    form.scheduled_date = min
  } else if (form.scheduled_date < min && (!editing.value || ready.value)) {
    dateNotice.value = `${chosenCarrier.value?.earliest_date === min ? `${chosenCarrier.value.name} ne livre` : 'Vous ne livrez'} pas plus tôt que ${dateLabel(min)} : date avancée.`
    form.scheduled_date = min
  }
}, { immediate: true })
watch(() => form.scheduled_date, () => {
  if (earliestDate.value && form.scheduled_date >= earliestDate.value) setTimeout(() => (dateNotice.value = ''), 4000)
})
const closedChosen = computed(() => form.scheduled_date && calendar.value?.days.some((d) => d.date === form.scheduled_date && !d.open))

// Adresse précise et point de repère : un seul champ (les anciennes commandes réunissent les deux)
function joinAddress(address, landmark) {
  const [a, l] = [address, landmark].map((v) => String(v ?? '').trim())
  // Repère déjà écrit dans l'adresse (« 8e tranche, près de la pharmacie ») : pas de doublon
  if (l && a.toLowerCase().includes(l.toLowerCase())) return a
  return [a, l].filter(Boolean).join(' — ')
}

const pickerOpen = ref(false)
const initials = (name) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
// Quartiers limités à ceux de l'entreprise choisie (si elle a des zones)
const placeCarrier = computed(() => (chosenCarrier.value && chosenCarrier.value.zones_count > 0 ? chosenCarrier.value : null))
const delivererName = computed(() => chosenCarrier.value?.name ?? chosenCourier.value?.name ?? '')
// Sous le quartier : zone et prix de qui livre
const placeHint = computed(() => {
  if (!place.value || form.delivery_method !== 'delivery') return null
  const c = chosenCarrier.value
  if (c) {
    if (!c.covered) return { tone: 'text-red-700', text: `${c.name} ne livre pas ce quartier.` }
    const parts = [c.zone ? `Zone ${c.zone} de ${c.name}` : c.name, c.fee !== null ? money(c.fee) : 'tarif non renseigné', c.day_message].filter(Boolean)
    return { tone: 'text-emerald-800', text: `✓ ${parts.join(' · ')}` }
  }
  if (expedition.value) return { tone: 'text-sky-800', text: `📦 Expédition vers ${expedition.value.city} : livraison jusqu'à la gare, puis compagnie de transport.` }
  if (zone.value) return { tone: 'text-emerald-800', text: `✓ ${zone.value.name} · ${money(zone.value.fee)}${zone.value.eta_label ? ` · ${zone.value.eta_label}` : ''}` }
  return { tone: 'text-amber-800', text: 'Hors de vos zones de livraison : indiquez les frais.' }
})
// Champ touché : ramené en haut de l'écran, avec son libellé, sous l'en-tête (le clavier du téléphone ne le cache plus
// et la liste des quartiers a la place de s'ouvrir). Avec le clavier ouvert, le navigateur décale la partie visible
// de la page (visualViewport) : la position est comptée dans ce qui se voit vraiment, puis corrigée une fois la page
// immobile (contenu ajouté ou retiré au-dessus pendant le défilement).
let scrollTimers = []
function offsetFromTop(anchor) {
  const vv = window.visualViewport
  const shift = vv?.offsetTop ?? 0
  const header = document.querySelector('header')?.offsetHeight ?? 64
  // En-tête encore visible ou non, et le message « ce qui manque » quand il est affiché
  const notice = document.querySelector('[data-missing-notice]')?.offsetHeight ?? 0
  const wanted = Math.max(0, header - shift) + 12 + (notice ? notice + 8 : 0)
  return anchor.getBoundingClientRect().top - shift - wanted
}
function bringToTop(event) {
  const el = event.target
  if (!el.matches?.('input:not([type=checkbox]):not([type=radio]):not([type=file]), textarea, select')) return
  const anchor = (el.id && document.querySelector(`label[for="${el.id}"]`)) || el
  scrollTimers.forEach(clearTimeout)
  scrollTimers = [
    setTimeout(() => {
      const delta = offsetFromTop(anchor)
      if (Math.abs(delta) > 4) window.scrollBy({ top: delta, behavior: 'smooth' })
    }, 300), // le temps que le clavier s'ouvre
    setTimeout(() => {
      const delta = offsetFromTop(anchor)
      if (Math.abs(delta) > 8) window.scrollBy({ top: delta })
    }, 900),
  ]
}

function focusPlaceSearch() {
  document.getElementById('place')?.focus()
}

function removePhone2() {
  form.shipping_phone2 = ''
  showPhone2.value = false
}

const showPhone2 = ref(false)
const showNotes = ref(false)
const showDiscount = ref(false)
const showDeposit = ref(false)
// « Coller WhatsApp » depuis l'accueil (?coller=1) : la zone du message est ouverte d'emblée
const pasteOpen = ref(route.query.coller === '1')

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
const missing = computed(() => {
  if (!editing.value && form.customer_phone.replace(/\D/g, '').length < 8) return { text: 'Indiquez le téléphone du client', field: 'customer_phone', target: '#phone' }
  if (!items.value.length) return { text: 'Ajoutez au moins un article', field: 'items', target: '#product-search' }
  if (items.value.some((i) => i.needs_variant)) return { text: 'Choisissez la variante des articles signalés', field: 'items', target: '[data-needs-variant]' }
  if (items.value.some((i) => !i.variant_id && !String(i.title ?? '').trim())) return { text: 'Donnez un nom à l\'article hors catalogue', field: 'items', target: '[data-untitled]' }
  if (form.delivery_method === 'delivery' && !form.scheduled_date) return { text: 'Choisissez la date de livraison', field: 'scheduled_date', target: '#delivery-date' }
  if (chosenCarrier.value && !chosenCarrier.value.covered) return { text: `${chosenCarrier.value.name} ne livre pas ce quartier`, field: 'shipping_place_id', target: '#place' }
  return null
})
const blocker = computed(() => missing.value?.text ?? '')
// Message affiché seulement après un appui sur « Créer la commande » ; il disparaît dès que c'est réglé
const missingNotice = ref('')
// Le message s'efface dès que ce qu'il demandait est réglé (le suivant s'affichera au prochain appui)
watch(missing, (now) => {
  if (missingNotice.value && now?.text !== missingNotice.value) {
    missingNotice.value = ''
    errors.value = {}
  }
})
function showMissing() {
  const m = missing.value
  missingNotice.value = m.text
  errors.value = { [m.field]: `${m.text}.` }
  const el = document.querySelector(m.target)
  if (!el) return
  if (el.matches('input, textarea')) el.focus({ preventScroll: true })
  const header = document.querySelector('header')?.offsetHeight ?? 64
  window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - header - 110, behavior: 'smooth' })
}

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
      countAiAction(auth.profile?.ai)
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
    if (r.address || r.landmark) form.shipping_address = joinAddress(r.address, r.landmark)
    if (r.note) {
      form.customer_note = r.note
      showNotes.value = true
    }
    // Adresse habituelle du client connu, si le message n'en donne pas
    if (r.customer?.address && !r.place) {
      place.value = r.customer.address.place_id ? { id: r.customer.address.place_id, label: r.customer.address.place_label, ancestor_ids: [] } : null
      form.shipping_address ||= joinAddress(r.customer.address.address_line, r.customer.address.landmark)
    }
    known.value = r.customer
    lastAddress.value = r.customer?.address?.place_id ? r.customer.address : lastAddress.value
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

// Adresse habituelle du client connu : quartier et adresse remplis d'un geste
const lastAddress = ref(null)
const lastAddressText = computed(() => (lastAddress.value ? [lastAddress.value.place_label, joinAddress(lastAddress.value.address_line, lastAddress.value.landmark)].filter(Boolean).join(' · ') : ''))
// Adresse déjà reprise : la carte n'affiche plus le bouton mais une confirmation
const addressTaken = computed(() => !!lastAddress.value && place.value?.id === lastAddress.value.place_id
  && (!joinAddress(lastAddress.value.address_line, lastAddress.value.landmark) || form.shipping_address === joinAddress(lastAddress.value.address_line, lastAddress.value.landmark)))
const addressFlash = ref(false)
async function useLastAddress(show = false) {
  const a = lastAddress.value
  if (!a) return
  place.value = { id: a.place_id, label: a.place_label, ancestor_ids: [] }
  form.shipping_address = joinAddress(a.address_line, a.landmark) || form.shipping_address
  await refreshPlace()
  if (show) {
    // Montrer où l'adresse a été remplie
    addressFlash.value = true
    setTimeout(() => (addressFlash.value = false), 1800)
    document.getElementById('place')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
}

// Téléphone mis en forme pendant la frappe (07 99 00 08 71), numéro incomplet signalé
function formatPhone(value) {
  const raw = String(value ?? '')
  if (raw.trim().startsWith('+')) return raw
  const digits = raw.replace(/\D/g, '').slice(0, 10)
  return digits.replace(/(\d{2})(?=\d)/g, '$1 ')
}
const phoneTouched = ref(false)
const phoneIncomplete = computed(() => {
  const raw = form.customer_phone.trim()
  return !editing.value && phoneTouched.value && raw !== '' && !raw.startsWith('+') && raw.replace(/\D/g, '').length < 10
})
function onPhoneInput() {
  form.customer_phone = formatPhone(form.customer_phone)
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
  lastAddress.value = address?.place_id ? address : null
  if (!place.value && address?.place_id) await useLastAddress()
}

async function chooseVariantFor(item) {
  const { data } = await http.get(`/products/${item.product_id}`)
  item.choices = data.data.variants.filter((v) => v.is_active)
}

function setVariant(item, variant) {
  Object.assign(item, { variant_id: variant.id, variant_title: variant.title, unit_price: variant.price, needs_variant: false, choices: null, available: variant.available })
}

// Commande modifiée alors qu'une entreprise de livraison a déjà la course : proposer de la mettre à jour chez elle
const original = ref(null)
const deliverySignature = (o) => JSON.stringify([o.shipping_address, o.shipping_landmark, o.shipping_phone, o.shipping_phone2, o.shipping_place_id, o.scheduled_date, o.amount_due])
async function offerCarrierSync(saved) {
  const shipment = (saved.shipments ?? []).find((s) => s.carrier_actions?.edit)
  if (!shipment || deliverySignature(saved) === original.value) return
  if (!window.confirm(`Mettre aussi à jour la course chez ${shipment.carrier?.name} (adresse, téléphones, date${shipment.carrier_actions.edit === 'all' ? ', montant' : ''}) ?`)) return
  try {
    await http.patch(`/shipments/${shipment.id}/carrier`, { from_order: true })
  } catch (e) {
    window.alert(`La commande est enregistrée, mais ${shipment.carrier?.name} n'a pas accepté la modification : ${errorMessage(e)}`)
  }
}

// Après l'enregistrement : petit résumé et la suite
const done = ref(null)
const printing = ref(false)
function clearCustomer() {
  Object.assign(form, { customer_phone: '', customer_name: '', shipping_phone2: '', shipping_address: '', shipping_landmark: '', customer_note: '' })
  place.value = null
  known.value = null
  elsewhere.value = []
  rewardPoints.value = 0
  conversation.value = null
  showPhone2.value = false
  manualFee.value = false
  shippingFee.value = ''
  errors.value = {}
  error.value = ''
}
function again(copy) {
  clearCustomer()
  if (!copy) {
    Object.assign(form, blank)
    items.value = []
    chosen.value = ''
    promo.value = null
    paste.value = ''
    parseInfo.value = ''
    showNotes.value = false
  }
  done.value = null
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
async function printLabel() {
  printing.value = true
  try {
    await download(`/orders/${done.value.id}/label`, 'etiquette.pdf')
  } catch (e) {
    window.alert(errorMessage(e))
  } finally {
    printing.value = false
  }
}

async function save() {
  if (missing.value) {
    showMissing()
    return
  }
  missingNotice.value = ''
  saving.value = true
  error.value = ''
  errors.value = {}
  try {
    const payload = {
      ...form,
      shipping_place_id: form.delivery_method === 'delivery' ? place.value?.id ?? null : null,
      deposit_required: form.deposit_required === '' ? null : Number(form.deposit_required),
      scheduled_date: form.scheduled_date || null,
      shipping_landmark: null,
      discount_code: form.discount_code || null,
      reward_points: loyalty.value && rewardPoints.value > 0 ? Number(rewardPoints.value) : null,
      items: items.value.map((i) => ({ variant_id: i.variant_id ?? null, title: i.variant_id ? undefined : i.title, quantity: Number(i.quantity), unit_price: Number(i.unit_price) })),
    }
    if (form.discount_code) delete payload.discount_total
    if (expedition.value) {
      payload.expedition_payer = form.expedition_payer
      payload.expedition_prepay = !!form.expedition_prepay
    } else {
      // Pas d'expédition vers une autre ville : ces réglages ne s'envoient pas
      delete payload.expedition_payer
      delete payload.expedition_prepay
    }
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
      const { data } = await http.put(`/orders/${props.id}`, payload)
      await offerCarrierSync(data.data)
      router.push(`/gestion/commandes/${props.id}`)
    } else {
      const { data } = await http.post('/orders', { ...payload, conversation_id: conversation.value?.id ?? null })
      // Résumé avec la suite : nouvelle commande, dupliquer, étiquette, liste
      done.value = data.data
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
  <form v-else class="space-y-5 pb-40 lg:pb-6" v-no-autofill novalidate @submit.prevent="save" @focusin="bringToTop">
    <RouterLink v-if="conversation" :to="`/gestion/messages/${conversation.id}`" class="text-sm text-slate-500 hover:text-emerald-700">← Conversation avec {{ conversation.name || conversation.phone_display }}</RouterLink>
    <RouterLink v-else :to="editing ? `/gestion/commandes/${id}` : '/gestion/commandes'" class="text-sm text-slate-500 hover:text-emerald-700">← {{ editing ? 'Commande' : 'Commandes' }}</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Saisie rapide : un bandeau, ouvert seulement quand on en a besoin -->
    <section v-if="!editing" class="card border-emerald-100 bg-emerald-50/60">
      <button type="button" class="flex w-full items-center gap-3 p-4 text-left" :aria-expanded="pasteOpen" @click="pasteOpen = !pasteOpen">
        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-emerald-700 shadow-sm"><Icon name="file" :size="20" /></span>
        <span class="min-w-0 flex-1">
          <span class="block font-bold">Coller le message du client</span>
          <span class="block text-sm text-slate-500">WhatsApp, Facebook… : numéro, nom, quartier et produits se remplissent.</span>
        </span>
        <Icon name="chevron" :size="18" class="text-slate-400 transition" :class="{ 'rotate-90': pasteOpen }" />
      </button>
      <div v-if="pasteOpen" class="space-y-3 border-t border-emerald-100 p-4">
        <textarea v-model="paste" class="input" rows="3" placeholder="Bonsoir, je veux 2 robes wax taille M. Je suis à Yop Siporex, 07 07 07 07 07…" />
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="btn-secondary" :disabled="parsing || paste.trim().length < 5" @click="analyse(false)">{{ parsing ? 'Analyse…' : 'Analyser le message' }}</button>
          <template v-if="ai.enabled">
            <label class="btn-secondary cursor-pointer px-3">
              <Icon name="image" :size="16" />Capture d'écran{{ screenshots.length ? ` (${screenshots.length})` : '' }}
              <input type="file" accept="image/*" multiple class="hidden" @change="onScreenshots" />
            </label>
            <button type="button" class="btn-brand" :disabled="parsing || (paste.trim().length < 5 && !screenshots.length) || aiExhausted(ai)" @click="analyse(true)">
              <Icon name="sparkles" :size="16" />{{ parsing ? 'L\'assistant lit…' : 'Analyser avec l\'assistant' }}
            </button>
          </template>
        </div>
        <p v-if="ai.enabled" class="text-xs text-slate-500">L'assistant comprend les messages mal écrits, en plusieurs fois ou en capture d'écran.</p>
        <AiMeter />
      </div>
    </section>
    <Alert v-if="parseInfo" tone="info">{{ parseInfo }}</Alert>
    <!-- Ce qui manque, après un appui sur « Créer la commande » -->
    <div v-if="missingNotice" data-missing-notice class="sticky top-[4.5rem] z-20 flex items-center gap-2 rounded-xl bg-amber-100 px-4 py-3 text-[15px] font-medium text-amber-900 shadow" role="alert">
      <Icon name="alert" :size="20" class="shrink-0" />{{ missingNotice }} pour {{ editing ? 'enregistrer' : 'créer la commande' }}.
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <div class="min-w-0 space-y-5">
        <!-- 1. Client -->
        <section class="card space-y-5 p-4 sm:p-6">
          <h2 class="flex items-center gap-2.5 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-700 text-sm font-extrabold text-white">1</span>Client
          </h2>

          <div v-if="!editing">
            <p class="label-lg">Canal de vente</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="s in SOURCES"
                :key="s.value"
                type="button"
                class="inline-flex min-h-11 items-center gap-2 rounded-xl border px-3.5 text-[15px] font-semibold"
                :class="form.source === s.value ? 'border-2 border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
                :aria-pressed="form.source === s.value"
                @click="form.source = s.value"
              >
                <Icon :name="SOURCE_ICONS[s.value] ?? 'more'" :size="17" />{{ s.label }}
              </button>
            </div>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <Field id="phone" label="Téléphone" large :error="errors.customer_phone || (phoneIncomplete ? 'Numéro incomplet : 10 chiffres attendus.' : '')">
              <template #after-label><span class="text-red-600"> *</span></template>
              <input id="phone" v-model="form.customer_phone" class="input-lg" type="tel" inputmode="tel" autocomplete="off" placeholder="07 00 00 00 00" :disabled="editing" @input="onPhoneInput" @blur="phoneTouched = true; lookupCustomer()" />
            </Field>
            <Field id="name" label="Nom du client" large :error="errors.customer_name">
              <input id="name" v-model="form.customer_name" class="input-lg" maxlength="120" autocomplete="off" />
            </Field>
          </div>
          <Field v-if="showPhone2" id="phone2" label="Autre numéro" large :error="errors.shipping_phone2">
            <div class="flex gap-2">
              <input id="phone2" v-model="form.shipping_phone2" class="input-lg sm:max-w-xs" type="tel" inputmode="tel" placeholder="07 00 00 00 00" @input="form.shipping_phone2 = formatPhone(form.shipping_phone2)" />
              <button type="button" class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-300 px-3 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="removePhone2"><Icon name="close" :size="18" />Retirer</button>
            </div>
          </Field>
          <button v-else type="button" class="-mt-1 text-[15px] font-medium text-emerald-700 hover:underline" @click="showPhone2 = true">+ Ajouter un autre numéro</button>

          <!-- Client connu : fiche résumée et son adresse habituelle -->
          <Alert v-if="known?.is_blacklisted" tone="error">Ce client est sur liste noire{{ known.blacklist_reason ? ` : ${known.blacklist_reason}` : '' }}.</Alert>
          <div v-else-if="known" class="flex flex-wrap items-center gap-3 rounded-xl bg-emerald-50 px-4 py-3">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-emerald-600 font-bold text-white">{{ (known.name ?? '?').charAt(0).toUpperCase() }}</span>
            <div class="min-w-[12rem] flex-1">
              <p class="font-semibold">Client connu · {{ known.orders_count }} commande(s)<template v-if="known.delivery_rate !== null && known.delivery_rate !== undefined"> · {{ known.delivery_rate }} % livrées</template></p>
              <p v-if="lastAddressText && addressTaken && form.delivery_method === 'delivery'" class="flex items-start gap-1 text-sm font-semibold text-emerald-800"><Icon name="check" :size="16" class="mt-0.5 shrink-0" /><span>Adresse reprise : {{ lastAddressText }}</span></p>
              <p v-else-if="lastAddressText" class="text-sm text-slate-600">Dernière adresse : {{ lastAddressText }}</p>
            </div>
            <button v-if="lastAddress && !addressTaken && form.delivery_method === 'delivery'" type="button" class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-emerald-800 shadow-sm max-sm:w-full" @click="useLastAddress(true)">Reprendre l'adresse</button>
          </div>
          <template v-for="o in elsewhere" :key="o.store_id">
            <Alert v-if="o.is_blacklisted" tone="error">Sur liste noire dans {{ o.store_name }}{{ o.blacklist_reason ? ` : ${o.blacklist_reason}` : '' }}.</Alert>
            <p v-else class="text-sm text-slate-600">
              Client de {{ o.store_name }} : {{ o.orders_count }} commande(s), {{ o.delivered_count }} livrée(s)<template v-if="o.refused_count">, {{ o.refused_count }} refusée(s)</template>.
            </p>
          </template>
        </section>

        <!-- 2. Livraison : qui livre d'abord, puis le quartier (limité aux zones de l'entreprise choisie) -->
        <section class="card space-y-5 p-4 sm:p-6">
          <h2 class="flex items-center gap-2.5 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-700 text-sm font-extrabold text-white">2</span>Livraison
          </h2>
          <div class="grid grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-base font-semibold sm:w-96">
            <button type="button" class="rounded-lg py-2.5" :class="form.delivery_method === 'delivery' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="form.delivery_method = 'delivery'">Livraison</button>
            <button type="button" class="rounded-lg py-2.5" :class="form.delivery_method === 'pickup' ? 'bg-white shadow-sm' : 'text-slate-600'" @click="form.delivery_method = 'pickup'">Retrait au magasin</button>
          </div>

          <template v-if="form.delivery_method === 'delivery'">
            <div>
              <p class="label-lg">Qui livre ?</p>
              <button type="button" class="flex min-h-14 w-full items-center gap-3 rounded-xl border-2 bg-white px-4 py-2.5 text-left disabled:opacity-70" :class="chosen ? 'border-emerald-600' : 'border-slate-300 border-dashed'" :disabled="!canPrice" @click="pickerOpen = true">
                <template v-if="chosenCarrier || chosenCourier">
                  <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-sm font-bold" :class="chosenCarrier ? 'bg-sky-100 text-sky-800' : 'bg-emerald-100 text-emerald-800'">{{ initials(delivererName) }}</span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-base font-semibold">{{ delivererName }}</span>
                    <span class="block text-sm text-slate-500">
                      <template v-if="chosenCarrier">Entreprise de livraison{{ chosenCarrier.is_api ? ' · Delivery App' : chosenCarrier.zones_count ? ` · ${chosenCarrier.zones_count} zone(s)` : '' }}</template>
                      <template v-else>Mon livreur · {{ chosenCourier.is_available ? 'disponible' : 'indisponible' }}{{ chosenCourier.active_missions ? ` · ${chosenCourier.active_missions} course(s) en cours` : '' }}</template>
                    </span>
                  </span>
                </template>
                <template v-else>
                  <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-500"><Icon name="truck" :size="18" /></span>
                  <span class="min-w-0 flex-1">
                    <span class="block text-base font-semibold">{{ chosen ? 'Chargement…' : 'Décider plus tard' }}</span>
                    <span class="block text-sm text-slate-500">Choisir un livreur ou une entreprise de livraison</span>
                  </span>
                </template>
                <span class="text-sm font-semibold text-emerald-700">{{ chosen ? 'Changer' : 'Choisir' }}</span>
              </button>
              <p v-if="errors.planned_courier_id || errors.planned_carrier_connection_id" class="mt-1 text-sm text-red-600">{{ errors.planned_courier_id || errors.planned_carrier_connection_id }}</p>
              <p v-else-if="chosenCourier && place && !chosenCourier.covers_zone" class="mt-1.5 text-sm text-amber-800">{{ chosenCourier.name }} ne couvre pas ce quartier d'habitude.</p>
              <button v-if="chosenCarrier" type="button" class="mt-1.5 text-sm font-medium text-emerald-700 hover:underline" @click="openTariffs">Voir toutes ses zones et tarifs</button>
            </div>

            <Field id="place" label="Quartier / commune" large :class="addressFlash ? 'rounded-xl ring-4 ring-emerald-300 transition' : 'transition'" :error="errors.shipping_place_id">
              <DeliveryPlacePicker id="place" v-model="place" :carrier-id="placeCarrier?.id ?? null" :carrier-name="placeCarrier?.name ?? ''" @change-deliverer="pickerOpen = true" />
              <p v-if="placeHint && !errors.shipping_place_id" class="mt-1.5 text-sm" :class="placeHint.tone">{{ placeHint.text }}</p>
              <PlaceTapPicker v-if="!place && !placeCarrier && rates?.home" class="mt-3" :rates="rates" :show-fees="canPrice" @pick="place = $event" @search="focusPlaceSearch" />
            </Field>

            <Field id="address" label="Adresse précise et point de repère" large :class="addressFlash ? 'rounded-xl ring-4 ring-emerald-300 transition' : 'transition'">
              <textarea id="address" v-model="form.shipping_address" class="input-lg" rows="3" maxlength="255" placeholder="Rue, immeuble, étage… et un repère : face pharmacie, à côté de l'école…" />
            </Field>

            <div>
              <p id="delivery-date" class="label-lg">Date de livraison <span class="text-red-600">*</span></p>
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-for="d in dayChoices"
                  :key="d.date"
                  type="button"
                  class="rounded-full border px-4 py-2 text-[15px] first-letter:uppercase"
                  :class="!pickDate && form.scheduled_date === d.date ? 'border-2 border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : d.disabled ? 'cursor-not-allowed border-slate-200 text-slate-400 line-through' : 'border-slate-300 text-slate-700'"
                  :disabled="d.disabled"
                  :title="!d.open ? 'Pas de livraison ce jour-là' : d.disabled ? 'Trop tôt pour qui livre' : undefined"
                  @click="pickDate = false; form.scheduled_date = d.date"
                >
                  {{ d.label }}
                </button>
                <button type="button" class="rounded-full border px-4 py-2 text-[15px]" :class="pickDate || (form.scheduled_date && !dayChoices.some((d) => d.date === form.scheduled_date)) ? 'border-2 border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-slate-300 text-slate-700'" @click="pickDate = true">Autre date</button>
                <input v-if="pickDate || (form.scheduled_date && !dayChoices.some((d) => d.date === form.scheduled_date))" v-model="form.scheduled_date" class="input w-auto" type="date" :min="earliestDate ?? isoDay(0)" aria-label="Date de livraison" />
              </div>
              <p v-if="errors.scheduled_date" class="mt-2 text-sm text-red-600">{{ errors.scheduled_date }}</p>
              <p v-else-if="dateNotice" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[15px] text-amber-900">{{ dateNotice }}</p>
              <p v-else-if="closedChosen" class="mt-2 text-sm text-amber-800">D'habitude, pas de livraison ce jour-là.</p>
              <p v-else-if="earliestDate && form.scheduled_date === earliestDate" class="mt-2 text-sm text-slate-500">Première date possible{{ calendar ? ` (commandes livrées le jour même jusqu'à ${calendar.cutoff.replace(':', ' h ')})` : '' }}.</p>
            </div>

            <!-- Frais : calculés, modifiables d'un geste -->
            <div class="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 px-4 py-3 text-base">
              <Icon name="truck" :size="20" class="text-slate-500" />
              <span class="font-semibold">Frais de livraison</span>
              <template v-if="manualFee">
                <input v-model="shippingFee" class="input w-28 py-1 text-right" type="number" min="0" aria-label="Frais de livraison" @input="askRemember = true" />
                <span>F</span>
                <button type="button" class="font-medium text-emerald-700 hover:underline" @click="manualFee = false">Recalculer</button>
              </template>
              <template v-else>
                <span class="font-semibold tabular-nums">{{ fee ? money(fee) : expedition && expedition.fee === null ? 'À indiquer' : 'Offerts' }}</span>
                <span v-if="feeSource" class="text-slate-500">· {{ feeSource }}</span>
                <span v-if="optionsLoading" class="text-slate-400">…</span>
                <button v-if="canPrice" type="button" class="ml-auto font-medium text-emerald-700 hover:underline" @click="editFee">Modifier</button>
              </template>
            </div>
            <!-- Prix tapé : le retenir pour la suite -->
            <div v-if="rememberChoices.length" class="rounded-xl border border-amber-300 bg-amber-50 p-3">
              <p class="mb-2 font-bold text-amber-900">Retenir {{ money(Number(shippingFee || 0)) }} pour la suite ?</p>
              <div class="flex flex-col gap-1.5">
                <button v-for="(c, i) in rememberChoices" :key="c.key" type="button" class="rounded-lg border px-3 py-2 text-left font-semibold" :class="i === 0 ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-amber-200 bg-white text-slate-700'" @click="remember(c)">
                  {{ i === 0 ? '✓ ' : '' }}{{ c.label }}<span v-if="c.hint" class="block text-xs font-normal opacity-80">{{ c.hint }}</span>
                </button>
                <button type="button" class="rounded-lg border border-amber-200 bg-white px-3 py-2 text-left font-semibold text-slate-700" @click="askRemember = false">Juste cette fois</button>
              </div>
            </div>
            <p v-if="remembered" class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
              {{ remembered.text }}
              <button type="button" class="font-bold underline" @click="remembered.undo()">Annuler</button>
            </p>

            <!-- Expédition : qui paie la compagnie, paiement avant de partir à la gare -->
            <div v-if="expedition" class="space-y-2 rounded-xl border border-sky-200 bg-sky-50/60 p-3">
              <p class="font-semibold text-sky-900">Frais d'expédition vers {{ expedition.city }} : à préciser au dépôt</p>
              <p class="text-sm text-slate-600">Qui les paie ?</p>
              <label v-for="[value, label, hint] in PAYERS" :key="value" class="flex items-start gap-2 rounded-lg bg-white px-3 py-2">
                <input v-model="form.expedition_payer" type="radio" :value="value" class="mt-1 h-4 w-4 accent-emerald-700" />
                <span><span class="block font-medium">{{ label }}</span><span class="block text-xs text-slate-500">{{ hint }}</span></span>
              </label>
              <label class="flex items-start gap-2 pt-1">
                <input v-model="form.expedition_prepay" type="checkbox" class="mt-1 h-4 w-4 accent-emerald-700" />
                <span><span class="block font-medium">Paiement avant expédition</span><span class="block text-xs text-slate-500">Le livreur ne part à la gare qu'une fois les articles et la livraison payés.</span></span>
              </label>
            </div>
          </template>
          <p v-else class="text-base text-slate-600">Le client vient chercher sa commande : pas de frais de livraison.</p>
        </section>

        <!-- 2. Articles -->
        <section class="card space-y-4 p-4 sm:p-6">
          <h2 class="flex items-center gap-2.5 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-700 text-sm font-extrabold text-white">3</span>Articles
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
                  <input v-else v-model="item.title" class="input py-1.5" placeholder="Nom de l'article" aria-label="Nom de l'article" :data-untitled="!String(item.title ?? '').trim() || undefined" />
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
                  <input v-model.number="item.unit_price" type="number" min="0" class="input w-28 py-1.5 text-right" :readonly="!canPrice" :class="{ 'bg-slate-50 text-slate-500': !canPrice }" @input="item.price_edited = true" />
                </label>
                <span class="ml-auto font-semibold tabular-nums">{{ money(lineTotal(item)) }}</span>
              </div>
              <p v-if="!item.price_edited && (item.flash || item.volume)" class="mt-2 text-xs text-emerald-700">
                {{ item.flash ? 'Prix de vente flash' : 'Prix par quantité' }}<template v-if="item.compare_at"> (au lieu de {{ money(item.compare_at) }})</template><template v-if="item.volume"> · {{ money(item.line_total) }} pour {{ item.quantity }}</template>
              </p>
              <p v-if="errors[`items.${index}.quantity`] || errors[`items.${index}.title`]" class="mt-2 text-sm text-red-600">{{ errors[`items.${index}.quantity`] || errors[`items.${index}.title`] }}</p>
              <div v-if="item.needs_variant" data-needs-variant class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-amber-800">Quelle variante ?</span>
                <button v-if="!item.choices" type="button" class="btn-secondary px-2 py-1" @click="chooseVariantFor(item)">Choisir</button>
                <button v-for="v in item.choices ?? []" :key="v.id" type="button" class="rounded-full border border-slate-300 bg-white px-3 py-1 hover:border-emerald-600" @click="setVariant(item, v)">
                  {{ v.title }} ({{ v.available }})
                </button>
              </div>
            </li>
          </ul>
          <p v-else class="flex items-start gap-2 text-[15px] leading-snug text-slate-600">
            <Icon name="up" :size="18" class="mt-0.5 shrink-0 text-emerald-700" />
            <span>Tapez le nom de l'article (ou son code) dans le champ ci-dessus : la liste des articles s'affiche, touchez celui que le client veut pour l'ajouter à la commande.</span>
          </p>
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
        <section class="card space-y-3 p-4 text-[15px] sm:p-5">
          <h2 class="flex items-center gap-2.5 text-lg font-extrabold">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-emerald-700 text-sm font-extrabold text-white">4</span>Paiement
          </h2>
          <div class="flex justify-between"><span class="text-slate-600">Articles</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-slate-600">Livraison<template v-if="delivererName && form.delivery_method === 'delivery'"> · {{ delivererName }}</template></span><span class="tabular-nums">{{ form.delivery_method === 'pickup' ? '—' : fee ? money(fee) : 'Offerte' }}</span></div>
          <div v-if="discount || pointsDiscount" class="flex justify-between text-emerald-700"><span>Remise</span><span class="tabular-nums">− {{ money(discount + pointsDiscount) }}</span></div>

          <!-- Une seule entrée pour toutes les remises -->
          <button v-if="canPrice && !showDiscount && !form.discount_code && !form.discount_total && !rewardPoints" type="button" class="text-emerald-700 hover:underline" @click="showDiscount = true">+ Ajouter une remise</button>
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
              <input id="discount" v-model.number="form.discount_total" type="number" min="0" class="input w-32 py-1 text-right" :readonly="!canPrice" />
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
          <label v-if="!editing" class="flex cursor-pointer items-start gap-3 rounded-xl bg-slate-50 p-3">
            <input v-model="form.confirmed" type="checkbox" class="peer sr-only" />
            <span class="mt-0.5 h-6 w-11 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-5 w-5 rounded-full bg-white shadow transition" :class="{ 'translate-x-5': form.confirmed }" /></span>
            <span>Déjà confirmée avec le client <span class="block text-sm text-slate-500">Sinon elle va dans la file « À confirmer ».</span></span>
          </label>
        </section>

        <!-- Ordinateur : validation sous le récapitulatif -->
        <div class="hidden space-y-3 lg:block">
          <button class="btn-brand w-full py-3 text-base" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer les modifications' : 'Créer la commande' }}</button>
        </div>
      </aside>
    </div>

    <!-- Téléphone : total et validation toujours visibles, au-dessus de la barre de navigation -->
    <!-- Une seule ligne, effacée pendant la saisie (clavier ouvert) -->
    <div class="hide-on-keyboard fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 flex items-center gap-3 border-t border-slate-200 bg-white/95 px-4 py-2.5 backdrop-blur lg:hidden">
      <div class="min-w-0">
        <p class="text-xs text-slate-500">Total</p>
        <p class="text-lg font-extrabold leading-tight tabular-nums">{{ money(total) }}</p>
      </div>
      <button class="btn-brand ml-auto min-h-12 flex-1 text-base" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer la commande' }}</button>
    </div>

    <DelivererPicker v-if="pickerOpen" v-model="chosen" :options="options" :place-label="place?.label ?? ''" @close="pickerOpen = false" @courier-created="addCourier" />

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

  <Modal v-if="done" title="✅ Commande enregistrée" @close="router.push(`/gestion/commandes/${done.id}`)">
    <div class="space-y-4">
      <div class="rounded-xl bg-emerald-50 p-4 text-sm">
        <p class="font-semibold text-slate-900">{{ done.reference }} · {{ done.customer_name || done.customer_phone_display }}</p>
        <p v-if="done.customer_name" class="text-slate-600">{{ done.customer_phone_display }}</p>
        <ul class="mt-2 space-y-0.5 text-slate-700">
          <li v-for="(i, n) in done.items" :key="n">{{ i.quantity }} × {{ i.title }}<span v-if="i.variant_title" class="text-slate-500"> ({{ i.variant_title }})</span></li>
        </ul>
        <p class="mt-2 text-slate-600">
          {{ done.delivery_method === 'pickup' ? 'Retrait en boutique' : done.shipping_place_label || 'Livraison' }}<span v-if="done.planned_courier"> · {{ done.planned_courier.name }}</span><span v-else-if="done.planned_carrier"> · {{ done.planned_carrier.name }}</span>
        </p>
        <p class="mt-1 text-base font-bold text-slate-900">À encaisser : {{ money(done.amount_due) }}</p>
      </div>
      <button type="button" class="btn-primary w-full" @click="again(false)">+ Enregistrer une nouvelle commande</button>
      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="btn-secondary" title="Mêmes articles et même livraison, pour une autre cliente" @click="again(true)">⧉ Dupliquer</button>
        <button type="button" class="btn-secondary" :disabled="printing" @click="printLabel">🖨️ {{ printing ? 'Préparation…' : 'Imprimer l\'étiquette' }}</button>
        <RouterLink :to="`/gestion/commandes/${done.id}`" class="btn-secondary justify-center text-center">Voir la commande</RouterLink>
        <RouterLink to="/gestion/commandes" class="btn-secondary justify-center text-center">Liste des commandes</RouterLink>
      </div>
    </div>
  </Modal>
</template>
