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
import ProductPicker from '../../../components/ProductPicker.vue'
import http, { download, errorMessage, fieldErrors } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { SOURCES, SOURCE_ICONS } from '../../../lib/orders'
import ColorIcon from '../../../components/ColorIcon.vue'
import { useAuthStore } from '../../../stores/auth'
import { platformOn } from '../../../router'

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
  // Coché par défaut : la commande saisie a déjà été vue avec le client ; à décocher pour la file « À confirmer »
  confirmed: true,
  // Expédition vers une autre ville : qui paie la compagnie de transport, paiement avant de partir à la gare
  expedition_payer: 'included',
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
    paidBefore.value = Math.max(0, (o.amount_paid ?? 0) - (o.amount_refunded ?? 0))
    // Acompte fixé à la main : repris comme montant
    if (o.upfront?.deposit_manual && o.deposit_required) {
      depositMode.value = 'amount'
      depositFixed.value = String(o.deposit_required)
    } else if (o.upfront?.deposit_manual) {
      depositMode.value = 'none'
      depositTouched.value = true
    }
    Object.assign(form, {
      customer_phone: o.customer_phone_display, customer_name: o.customer_name ?? '', delivery_method: o.delivery_method,
      shipping_phone2: o.shipping_phone2 ?? '', shipping_address: joinAddress(o.shipping_address, o.shipping_landmark), shipping_landmark: '',
      scheduled_date: o.scheduled_date ?? '', discount_total: o.discount_total, discount_code: o.discount_code ?? '', deposit_required: o.upfront?.deposit_manual ? o.deposit_required ?? '' : '',
      customer_note: o.customer_note ?? '', internal_note: o.internal_note ?? '',
      expedition_payer: o.expedition?.payer ?? 'arrival', expedition_prepay: o.expedition?.prepay ?? null,
    })
    place.value = o.shipping_place_id ? { id: o.shipping_place_id, label: o.shipping_place_label, ancestor_ids: [] } : null
    // Prix convenus à la commande : gardés tels quels (pas de recalcul par les promotions du moment)
    items.value = o.items.map((i) => ({ variant_id: i.variant_id, title: i.title, variant_title: i.variant_title, quantity: i.quantity, unit_price: i.unit_price, thumb: i.thumb, price_edited: true }))
    shippingFee.value = o.shipping_fee
    manualFee.value = true
    chosen.value = o.planned_courier ? `courier:${o.planned_courier.id}` : o.planned_carrier ? `carrier:${o.planned_carrier.id}` : o.planned_carrier_request ? `directory:${o.planned_carrier_request.slug}` : ''
    showMore.value = !!(o.shipping_phone2 || o.customer_note || o.internal_note || o.discount_total || o.discount_code)
    loading.value = false
    await nextTick()
  } else if (route.query.conversation) {
    await fromConversation(route.query.conversation)
  } else if (route.query.client) {
    await fromCustomer(route.query.client)
  }
  if (!editing.value && !route.query.conversation && habits.source && SOURCES.some((x) => x.value === habits.source)) {
    form.source = habits.source
    habitsUsed.source = true
  }
  ready.value = true
})

// Commande ouverte depuis la fiche d'un client : son téléphone et son nom, puis son adresse habituelle
async function fromCustomer(id) {
  try {
    const c = (await http.get(`/customers/${id}`)).data.data
    form.customer_phone = c.phone_display
    form.customer_name = c.name ?? ''
  } catch {
    return
  }
  await lookupCustomer()
}

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
    if (form.shipping_phone2) showMore.value = true
    form.customer_phone = c.phone_display
  }
  form.customer_name ||= c.customer?.name ?? c.name ?? ''
  await lookupCustomer()
}

// Prix du moment (vente flash, prix par quantité) et code promo, recalculés par le serveur ; un prix modifié à la main est gardé
const promo = ref(null)
// Acompte « Auto » : acompte des articles et règle de la boutique, renvoyés avec les prix
const depositRule = ref(null)
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
    depositRule.value = data.data.deposit ?? null
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
// Entreprise de l'annuaire pas encore partenaire : même forme qu'une entreprise connectée (prix du devis), « directory »
const chosenDirectory = computed(() => (chosen.value.startsWith('directory:') ? (options.value?.directory ?? []).find((d) => `directory:${d.slug}` === chosen.value) ?? null : null))
const chosenCarrier = computed(() => {
  if (chosenDirectory.value) return { ...chosenDirectory.value, covered: true, zones_count: 0, directory: true }
  return chosen.value.startsWith('carrier:') ? options.value?.carriers.find((c) => `carrier:${c.id}` === chosen.value) ?? null : null
})
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
  // Pas encore de prix pour les expéditions : la case du prix convenu s'ouvre tout de suite
  if (e && e.fee === null && !manualFee.value && canPrice.value) {
    shippingFee.value = ''
    manualFee.value = true
  }
})
// Qui paie les frais d'expédition : « compris dans le prix convenu » visible, les autres cas repliés
const otherPayer = ref(false)
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
  if (expedition.value) return expedition.value.usual ? `prix habituel pour ${expedition.value.city}` : expedition.value.fee !== null ? 'prix convenu avec le client' : 'indiquez le prix convenu avec le client'
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
  // Expédition : le prix convenu pour chaque ville est retenu tout seul (dernière commande vers cette ville)
  if (expedition.value) return []
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
  ['included', 'Compris dans le prix convenu', 'Le client a tout payé ; le prix réel de la compagnie est pour la boutique (gain ou perte vu au dépôt).'],
  ['before', 'Le client me les rembourse après l\'envoi', 'Le livreur note le prix au dépôt ; le client paie cette somme en plus.'],
  ['arrival', 'Le client les paie à la compagnie en récupérant son colis', 'Cas rare : rien à encaisser pour la compagnie.'],
  ['store', 'La boutique les offre', 'Cadeau au client : les frais d\'expédition sont une dépense de la boutique (mettez le prix ci-dessus à 0 si la livraison est offerte aussi).'],
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
// Habitudes : canal de vente et jour (aujourd'hui ou demain) repris de la dernière commande, modifiables
const HABITS = 'jm.commande.habitudes'
function readHabits() {
  try {
    return JSON.parse(localStorage.getItem(HABITS) || '{}') || {}
  } catch {
    return {}
  }
}
const habits = readHabits()
const habitsUsed = reactive({ source: false, day: false })
function saveHabits() {
  try {
    const day = form.scheduled_date === isoDay(0) ? 'today' : form.scheduled_date === isoDay(1) ? 'tomorrow' : null
    localStorage.setItem(HABITS, JSON.stringify({ source: form.source, day }))
  } catch {
    // Navigation privée : rien n'est gardé
  }
}
// Les trois boutons de « Quand livrer » : aujourd'hui, demain (ou les deux premiers jours possibles), une autre date
const quickDays = computed(() => dayChoices.value.slice(0, 2))
const otherDay = computed(() => pickDate.value || (!!form.scheduled_date && !quickDays.value.some((d) => d.date === form.scheduled_date)))
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
    // Habitude : livrer demain, si demain est possible
    const tomorrow = isoDay(1)
    if (!editing.value && habits.day === 'tomorrow' && min < tomorrow && calendar.value?.days.some((d) => d.date === tomorrow && d.open)) {
      form.scheduled_date = tomorrow
      habitsUsed.day = true
    }
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
// Qui livre, en grand dans la page : le choix actuel, puis les plus utilisés, mes livreurs disponibles qui couvrent le
// quartier, les entreprises qui le livrent (les moins chères d'abord) ; tous les autres dans « Voir tous »
const delivererCards = computed(() => {
  const o = options.value
  if (!o) return []
  const zoneFee = zone.value?.fee ?? null
  const courier = (c) => ({
    key: `courier:${c.id}`, name: c.name, tone: 'bg-emerald-100 text-emerald-800', price: zoneFee,
    sub: ['Mon livreur', c.connected === false ? 'pas encore connecté' : c.is_available ? 'disponible' : 'indisponible', c.all_zones ? 'livre partout' : c.covers_zone && place.value ? `couvre ${place.value.label.split(',')[0]}` : '', c.active_missions ? `${c.active_missions} course(s)` : ''].filter(Boolean).join(' · '),
    rank: c.is_available && (c.covers_zone || c.all_zones) ? 1 : c.is_available ? 3 : 5,
  })
  const carrier = (c) => ({
    key: `carrier:${c.id}`, name: c.name, tone: 'bg-sky-100 text-sky-800', price: c.covered ? c.fee : null, disabled: !c.covered && !!place.value,
    sub: ['Entreprise', place.value ? (c.covered ? (c.day_message || `livre ${place.value.label.split(',')[0]}`) : `ne livre pas ${place.value.label.split(',')[0]}`) : ''].filter(Boolean).join(' · '),
    rank: c.covered ? 2 : 6,
  })
  const directory = (d) => ({
    key: `directory:${d.slug}`, name: d.name, tone: 'bg-violet-100 text-violet-800', price: d.fee,
    sub: ['Pas encore partenaire', d.request?.status === 'pending' ? 'demande en cours' : d.request?.status === 'confirmation' ? 'à confirmer sur WhatsApp' : 'compte ouvert en un clic'].join(' · '),
    rank: 4,
  })
  const all = [...(o.couriers ?? []).map(courier), ...(o.carriers ?? []).map(carrier), ...(o.directory ?? []).map(directory)]
  const recent = o.recent ?? []
  const sorted = all.filter((e) => !e.disabled).sort((a, b) => (recent.includes(b.key) - recent.includes(a.key)) || (a.rank - b.rank) || ((a.price ?? 1e9) - (b.price ?? 1e9)))
  const top = sorted.slice(0, 3)
  const current = all.find((e) => e.key === chosen.value)
  if (current && !top.includes(current)) top.splice(2, 1, current)
  return top
})
const moreDeliverers = computed(() => Math.max(0, (options.value?.couriers?.length ?? 0) + (options.value?.carriers?.length ?? 0) + (options.value?.directory?.length ?? 0) - delivererCards.value.length))

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
  if (expedition.value) return { tone: 'text-sky-800', text: `📦 Expédition vers ${expedition.value.city} : un prix convenu avec le client couvre la livraison jusqu'à la gare et les frais d'expédition.` }
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

// Acompte : vide = calculé tout seul (articles, réglage de la boutique) ; envoyé seulement s'il a été changé ici
const depositTouched = ref(false)
// Non, Auto (calculé, seulement s'il y en a un), en pourcentage du total, ou un montant
const depositMode = ref('none')
const depositPercent = ref('')
const depositFixed = ref('')
function setDepositMode(mode) {
  depositTouched.value = true
  depositMode.value = mode
  applyDeposit()
}
// Le montant envoyé suit le mode : '' = Auto ; 0 = pas d'acompte ; pourcentage arrondi aux 100 F, jamais plus que le total
function applyDeposit() {
  if (depositMode.value === 'auto') form.deposit_required = ''
  else if (depositMode.value === 'none') form.deposit_required = depositTouched.value ? 0 : ''
  else if (depositMode.value === 'percent') {
    const p = Number(depositPercent.value) || 0
    form.deposit_required = p > 0 ? Math.min(total.value, Math.ceil((total.value * Math.min(100, p)) / 100 / 100) * 100) : 0
  } else form.deposit_required = Math.max(0, Number(depositFixed.value) || 0)
}
// « Coller un message » depuis l'accueil (?coller=1) : la zone du message est ouverte d'emblée
const pasteOpen = ref(route.query.coller === '1')
// « Coller le message du client » : lit le presse-papiers d'un toucher ; un message avec un numéro remplit tout
// de suite la commande, sinon (ou si le navigateur refuse) la zone pour coller s'ouvre
async function pasteFromClipboard() {
  let text = ''
  try {
    text = (await navigator.clipboard?.readText?.()) ?? ''
  } catch {
    text = ''
  }
  if ((text.match(/\d/g) ?? []).length >= 8) {
    paste.value = text.slice(0, 4000)
    await analyse(false)
  } else {
    if (text.trim()) paste.value = text.slice(0, 4000)
    pasteOpen.value = true
  }
}

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
// Déjà payé à la saisie : rien, une partie ou tout
const PAY_METHODS = [['wave', 'Wave'], ['orange_money', 'Orange Money'], ['mtn_momo', 'MTN'], ['moov_money', 'Moov'], ['cash', 'Espèces'], ['bank', 'Virement']]
const paid = reactive({ mode: 'none', amount: '', method: 'wave', reference: '' })
const due = computed(() => (paid.mode === 'all' ? 0 : paid.mode === 'part' ? Math.max(0, total.value - Number(paid.amount || 0)) : total.value))

// Acompte : « Auto » (même calcul que le serveur, OrderDesk::autoDeposit) ou montant saisi, jamais plus que le total
const paidBefore = ref(0)
const autoDeposit = computed(() => {
  const r = depositRule.value
  if (!r) return null
  const t = total.value
  const ruleApplies = r.percent > 0 && (r.mode === 'always' || (r.mode === 'above' && r.min_total !== null && t >= r.min_total))
  const deposit = Math.max(r.items, ruleApplies ? Math.round((t * Math.min(100, r.percent)) / 100) : 0)
  return deposit > 0 ? Math.min(t, Math.ceil(deposit / 100) * 100) : 0
})
const depositAuto = computed(() => form.deposit_required === '' || form.deposit_required === null)
const depositAmount = computed(() => (depositAuto.value ? autoDeposit.value ?? 0 : Math.max(0, Number(form.deposit_required) || 0)))
const depositTooHigh = computed(() => depositMode.value === 'amount' && Number(depositFixed.value) > total.value)
const percentTooHigh = computed(() => depositMode.value === 'percent' && Number(depositPercent.value) > 100)
watch([depositPercent, depositFixed, total], () => {
  if (['percent', 'amount'].includes(depositMode.value)) applyDeposit()
})
// Pas encore choisi à la main : « Auto » quand il y a un acompte automatique, sinon « Non »
watch(autoDeposit, (auto) => {
  if (!depositTouched.value && ['auto', 'none'].includes(depositMode.value)) depositMode.value = auto ? 'auto' : 'none'
})
// Déjà reçu : avant cette modification, plus ce qui est noté à la saisie
const paidSoFar = computed(() => paidBefore.value + (editing.value ? 0 : total.value - due.value))
const depositLeft = computed(() => Math.max(0, depositAmount.value - paidSoFar.value))

// Étapes : ce qui est rempli se replie en une ligne quand on passe à la suite (« Modifier » le rouvre)
const folded = reactive({ client: false, articles: false, livraison: false })
const clientOk = computed(() => editing.value || (form.customer_phone.replace(/\D/g, '').length >= 8 && !phoneIncomplete.value))
const articlesOk = computed(() => items.value.length > 0 && !items.value.some((i) => i.needs_variant || (!i.variant_id && !String(i.title ?? '').trim())))
const livraisonOk = computed(() => form.delivery_method === 'pickup' || (!!place.value && !!form.scheduled_date && !rememberChoices.value.length && !(chosenCarrier.value && !chosenCarrier.value.covered && !!place.value)))
const STEPS = [['client', clientOk], ['articles', articlesOk], ['livraison', livraisonOk]]
// Appelé après le toucher (pas à l'appui : la page ne bouge pas sous le doigt), et l'étape touchée reste au même
// endroit de l'écran pendant que celles du dessus se replient
async function enter(n, event) {
  const toFold = STEPS.filter(([key, ok], i) => i < n && ok.value && !folded[key])
  if (!toFold.length) return
  const el = event?.currentTarget
  const before = el?.getBoundingClientRect().top
  toFold.forEach(([key]) => (folded[key] = true))
  if (!el) return
  await nextTick()
  const shift = el.getBoundingClientRect().top - before
  let box = el.parentElement
  while (box && !(box.scrollHeight > box.clientHeight && /(auto|scroll)/.test(getComputedStyle(box).overflowY))) box = box.parentElement
  ;(box ?? window).scrollBy(0, shift)
}
const sourceLabel = computed(() => SOURCES.find((x) => x.value === form.source)?.label ?? '')
const clientSummary = computed(() => [form.customer_name, form.customer_phone, sourceLabel.value].filter(Boolean).join(' · '))
const articlesSummary = computed(() => `${items.value.map((i) => `${i.quantity} × ${i.title}${i.variant_title ? ` ${i.variant_title}` : ''}`).join(', ')} · ${money(subtotal.value)}`)
const livraisonSummary = computed(() => (form.delivery_method === 'pickup'
  ? 'Retrait au magasin'
  : [place.value?.label?.split(',')[0], form.scheduled_date ? dateLabel(form.scheduled_date) : '', delivererName.value || 'qui livre : plus tard', fee.value ? money(fee.value) : 'offerte'].filter(Boolean).join(' · ')))
// Le reste (remise, notes, autre numéro), replié tant qu'on n'en a pas besoin
const showMore = ref(false)
const moreSummary = computed(() => [discount.value || pointsDiscount.value ? `remise ${money(discount.value + pointsDiscount.value)}` : '', form.customer_note || form.internal_note ? 'note' : '', form.shipping_phone2 ? 'autre numéro' : ''].filter(Boolean).join(' · '))

// Ce qui manque encore : le bouton le dit au lieu d'être grisé sans explication
const missing = computed(() => {
  if (!editing.value && form.customer_phone.replace(/\D/g, '').length < 8) return { text: 'Indiquez le téléphone du client', field: 'customer_phone', target: '#phone' }
  if (!items.value.length) return { text: 'Ajoutez au moins un article', field: 'items', target: '#product-search' }
  if (items.value.some((i) => i.needs_variant)) return { text: 'Choisissez la variante des articles signalés', field: 'items', target: '[data-needs-variant]' }
  if (items.value.some((i) => !i.variant_id && !String(i.title ?? '').trim())) return { text: 'Donnez un nom à l\'article hors catalogue', field: 'items', target: '[data-untitled]' }
  if (form.delivery_method === 'delivery' && !form.scheduled_date) return { text: 'Choisissez la date de livraison', field: 'scheduled_date', target: '#delivery-date' }
  if (chosenCarrier.value && !chosenCarrier.value.covered) return { text: `${chosenCarrier.value.name} ne livre pas ce quartier`, field: 'shipping_place_id', target: '#place' }
  if (depositTooHigh.value) return { text: `Baissez l'acompte : il dépasse le total (${money(total.value)})`, field: 'deposit_required', target: '#deposit' }
  if (percentTooHigh.value) return { text: 'L\'acompte ne peut pas dépasser 100 %', field: 'deposit_required', target: '#deposit' }
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
async function showMissing() {
  const m = missing.value
  missingNotice.value = m.text
  errors.value = { [m.field]: `${m.text}.` }
  const step = { customer_phone: 'client', items: 'articles', scheduled_date: 'livraison', shipping_place_id: 'livraison' }[m.field]
  if (step) folded[step] = false
  await nextTick()
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
      showMore.value = true
    }
    if (r.customer_name || r.customer?.name) form.customer_name = r.customer_name ?? r.customer.name
    if (r.place) place.value = { ...r.place, ancestor_ids: [] }
    if (r.address || r.landmark) form.shipping_address = joinAddress(r.address, r.landmark)
    if (r.note) {
      form.customer_note = r.note
      showMore.value = true
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
// Adresse reprise : une fois « Reprendre l'adresse » touché (ou l'adresse reprise toute seule), la carte le confirme et
// le bouton disparaît, même si le quartier est ensuite ajusté (entreprise de livraison choisie, adresse retouchée…)
const addressKey = (a) => (a ? `${a.place_id}|${a.address_line ?? ''}|${a.landmark ?? ''}` : '')
const takenFor = ref('') // adresse du client déjà reprise (le client peut être relu entre-temps)
const addressTaken = computed(() => !!lastAddress.value && takenFor.value === addressKey(lastAddress.value))
const addressFlash = ref(false)
// Adresse : une ligne au départ, jusqu'à 4 quand on écrit plus
const addressRows = computed(() => Math.min(4, Math.max(1, Math.ceil(String(form.shipping_address ?? '').length / 36), String(form.shipping_address ?? '').split('\n').length)))
async function useLastAddress(show = false) {
  const a = lastAddress.value
  if (!a) return
  takenFor.value = addressKey(a)
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

// Acompte demandé : le réclamer tout de suite, depuis la fenêtre « Commande enregistrée »
const depositDone = reactive({ link: null, paying: false, amount: '', method: 'wave', reference: '', busy: false, error: '', received: false })
const doneDeposit = computed(() => (done.value && !done.value.expedition?.prepay ? done.value.upfront?.deposit_due ?? 0 : 0))
const canPayLink = computed(() => platformOn(auth, 'online_payment'))
// Récapitulatif à envoyer au client sur WhatsApp (depuis le téléphone du vendeur)
const recapUrl = computed(() => {
  const d = done.value
  if (!d?.customer_phone) return null
  const first = String(d.customer_name ?? '').split(' ')[0]
  const lines = [
    `Bonjour${first ? ` ${first}` : ''}, merci pour votre commande ${d.reference} chez ${auth.store?.name ?? 'nous'} :`,
    ...(d.items ?? []).map((i) => `• ${i.quantity} × ${i.title}${i.variant_title ? ` (${i.variant_title})` : ''}`),
    `Total : ${money(d.total)}${d.amount_due > 0 && d.amount_due < d.total ? ` · reste à payer : ${money(d.amount_due)}` : ''}`,
    d.delivery_method === 'pickup' ? 'À retirer en boutique.' : `Livraison prévue ${d.scheduled_date ? dateLabel(d.scheduled_date) : 'bientôt'}${d.shipping_place_label ? ` à ${d.shipping_place_label}` : ''}.`,
    d.tracking_url ? `Suivi : ${d.tracking_url}` : '',
  ].filter(Boolean)
  return `https://wa.me/${String(d.customer_phone).replace(/\D/g, '')}?text=${encodeURIComponent(lines.join('\n'))}`
})
// Remis à zéro pour chaque nouvelle commande enregistrée (pas quand la même est relue après un paiement)
watch(() => done.value?.id, () => Object.assign(depositDone, { link: null, paying: false, amount: doneDeposit.value || '', method: 'wave', reference: '', busy: false, error: '', received: false }))
async function sendDepositLink() {
  Object.assign(depositDone, { busy: true, error: '' })
  try {
    depositDone.link = (await http.post(`/orders/${done.value.id}/payment-links`, { amount: doneDeposit.value })).data.data
  } catch (e) {
    depositDone.error = errorMessage(e)
  } finally {
    depositDone.busy = false
  }
}
async function recordDeposit() {
  Object.assign(depositDone, { busy: true, error: '' })
  try {
    await http.post(`/orders/${done.value.id}/payments`, { amount: Number(depositDone.amount), method: depositDone.method, reference: depositDone.reference || null, kind: 'deposit' })
    const fresh = (await http.get(`/orders/${done.value.id}`)).data.data
    done.value = fresh
    depositDone.received = true
  } catch (e) {
    depositDone.error = errorMessage(e)
  } finally {
    depositDone.busy = false
  }
}
function clearCustomer() {
  Object.assign(form, { customer_phone: '', customer_name: '', shipping_phone2: '', shipping_address: '', shipping_landmark: '', customer_note: '' })
  place.value = null
  takenFor.value = ''
  known.value = null
  elsewhere.value = []
  rewardPoints.value = 0
  conversation.value = null
  manualFee.value = false
  shippingFee.value = ''
  errors.value = {}
  error.value = ''
}
// Après l'enregistrement : « same » même client, autre commande ; « copy » mêmes articles, autre client ; sinon tout neuf
function again(mode) {
  const copy = mode === 'copy'
  const keep = mode === 'same' ? { phone: form.customer_phone, name: form.customer_name, phone2: form.shipping_phone2, address: form.shipping_address, place: place.value, method: form.delivery_method } : null
  clearCustomer()
  Object.assign(paid, { mode: 'none', amount: '', reference: '' })
  Object.keys(folded).forEach((k) => (folded[k] = false))
  if (!copy) {
    Object.assign(form, blank)
    items.value = []
    chosen.value = ''
    promo.value = null
    paste.value = ''
    parseInfo.value = ''
    depositPercent.value = ''
    depositFixed.value = ''
    depositMode.value = autoDeposit.value ? 'auto' : 'none'
    depositTouched.value = false
    showMore.value = false
    if (habits.source) form.source = habits.source
  }
  // Même client : repris après la remise à zéro, l'étape client déjà repliée
  if (keep) {
    Object.assign(form, { customer_phone: keep.phone, customer_name: keep.name, shipping_phone2: keep.phone2, shipping_address: keep.address, delivery_method: keep.method })
    place.value = keep.place
    if (keep.phone2) showMore.value = true
    lookupCustomer()
    folded.client = true
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
      ...(depositTouched.value ? {} : { deposit_required: undefined }),
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
      payload.planned_carrier_slug = delivering && chosen.value.startsWith('directory:') ? chosen.value.slice('directory:'.length) : null
    }
    if (editing.value) {
      delete payload.source
      delete payload.customer_phone
      delete payload.confirmed
      const { data } = await http.put(`/orders/${props.id}`, payload)
      await offerCarrierSync(data.data)
      router.push(`/gestion/commandes/${props.id}`)
    } else {
      if (paid.mode !== 'none') payload.paid = { all: paid.mode === 'all', amount: paid.mode === 'part' ? Number(paid.amount) || null : null, method: paid.method, reference: paid.reference || null }
      const { data } = await http.post('/orders', { ...payload, conversation_id: conversation.value?.id ?? null })
      // Résumé avec la suite : nouvelle commande, dupliquer, étiquette, liste (la commande complète, relue)
      done.value = (await http.get(`/orders/${data.data.id}`).catch(() => ({ data: { data: data.data } }))).data.data
      saveHabits()
      window.scrollTo({ top: 0 })
    }
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
    // Erreur : tout se rouvre, pour voir le champ concerné
    Object.keys(folded).forEach((k) => (folded[k] = false))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <p v-if="loading" class="text-sm text-slate-500">Chargement…</p>

  <!-- Commande enregistrée : un écran, avec la suite en un toucher -->
  <div v-else-if="done" class="mx-auto max-w-lg space-y-4 pb-24 pt-2">
    <div class="text-center">
      <div class="flex justify-center"><ColorIcon name="livre" :size="80" /></div>
      <h2 class="mt-3 text-2xl font-extrabold">Commande enregistrée</h2>
      <p class="text-slate-600">{{ done.delivery_method === 'pickup' ? 'À retirer en boutique.' : done.scheduled_date ? `Livraison prévue ${dateLabel(done.scheduled_date)}` : 'Livraison à programmer.' }}</p>
    </div>
    <div class="card p-4 text-sm">
      <p class="text-slate-500">Commande</p>
      <p class="text-xl font-extrabold tracking-wide">{{ done.reference }}</p>
      <p class="mt-1.5 font-semibold text-slate-900">{{ done.customer_name || done.customer_phone_display }}<span v-if="done.customer_name" class="font-normal text-slate-600"> · {{ done.customer_phone_display }}</span></p>
      <ul class="mt-1 space-y-0.5 text-slate-700">
        <li v-for="(i, n) in done.items" :key="n">{{ i.quantity }} × {{ i.title }}<span v-if="i.variant_title" class="text-slate-500"> ({{ i.variant_title }})</span></li>
      </ul>
      <p class="mt-1 text-slate-600">
        {{ done.delivery_method === 'pickup' ? 'Retrait en boutique' : done.shipping_place_label || 'Livraison' }}<span v-if="done.planned_courier"> · {{ done.planned_courier.name }}</span><span v-else-if="done.planned_carrier"> · {{ done.planned_carrier.name }}</span>
      </p>
      <p class="mt-2 text-base font-bold text-slate-900">{{ done.amount_due > 0 ? `À encaisser : ${money(done.amount_due)}` : `Total ${money(done.total)} · déjà payé ✅` }}</p>
    </div>
    <!-- Acompte demandé : le lien au client, ou noter qu'il est reçu -->
    <div v-if="doneDeposit > 0" class="space-y-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
      <div>
        <p class="text-base font-bold">⏳ Acompte à recevoir : {{ money(doneDeposit) }}</p>
        <p>Reste à la livraison : {{ money(Math.max(0, done.amount_due - doneDeposit)) }}<template v-if="done.upfront?.blocking"> · la commande attend l'acompte avant de partir chez le livreur</template>.</p>
      </div>
      <Alert v-if="depositDone.error">{{ depositDone.error }}</Alert>
      <template v-if="depositDone.link">
        <p class="font-semibold">✅ Lien de {{ money(depositDone.link.amount) }} créé<template v-if="depositDone.link.sent_by_whatsapp"> et envoyé au client par votre WhatsApp</template>.</p>
        <input class="input text-xs" :value="depositDone.link.url" readonly aria-label="Lien de paiement" @focus="$event.target.select()" />
        <a v-if="!depositDone.link.sent_by_whatsapp" :href="depositDone.link.whatsapp_url" target="_blank" rel="noopener" class="btn-brand w-full"><Icon name="whatsapp" :size="16" />Envoyer par WhatsApp</a>
      </template>
      <form v-else-if="depositDone.paying" class="space-y-2" @submit.prevent="recordDeposit">
        <div class="flex items-center justify-between gap-2">
          <label for="deposit-received" class="font-semibold">Montant reçu (F)</label>
          <input id="deposit-received" v-model="depositDone.amount" type="number" min="1" :max="done.amount_due" required class="input w-32 py-1 text-right" />
        </div>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="[value, label] in PAY_METHODS" :key="value" type="button" class="rounded-full border bg-white px-3 py-1 text-sm" :class="depositDone.method === value ? 'border-2 border-emerald-700 font-bold text-emerald-900' : 'border-field'" @click="depositDone.method = value">{{ label }}</button>
        </div>
        <input v-model.trim="depositDone.reference" class="input py-1.5 text-sm" maxlength="100" placeholder="N° de transaction (facultatif)" aria-label="N° de transaction" />
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="btn-secondary" :disabled="depositDone.busy" @click="depositDone.paying = false">Retour</button>
          <button class="btn-brand" :disabled="depositDone.busy || !(Number(depositDone.amount) > 0)">{{ depositDone.busy ? 'Enregistrement…' : 'Enregistrer' }}</button>
        </div>
      </form>
      <div v-else class="grid grid-cols-2 gap-2">
        <button v-if="canPayLink && done.customer_phone" type="button" class="btn-brand" :disabled="depositDone.busy" @click="sendDepositLink">📲 {{ depositDone.busy ? 'Création…' : 'Lien d\'acompte' }}</button>
        <button type="button" class="btn-secondary" :class="{ 'col-span-2': !(canPayLink && done.customer_phone) }" :disabled="depositDone.busy" @click="depositDone.paying = true">Acompte reçu</button>
      </div>
    </div>
    <p v-else-if="depositDone.received" class="rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">✅ Acompte reçu<template v-if="done.status === 'confirmed'"> : la commande est confirmée</template>.</p>
    <a v-if="recapUrl" :href="recapUrl" target="_blank" rel="noopener" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#1fa855] px-4 py-3 text-center text-base font-bold text-white"><Icon name="whatsapp" :size="18" />Envoyer le récapitulatif au client sur WhatsApp</a>
    <!-- Enchaîner : même client avec une autre commande, ou un autre client (réglages gardés) -->
    <div class="grid grid-cols-2 gap-2">
      <button type="button" class="btn-secondary min-h-12" @click="again('same')">Même client, autre commande</button>
      <button type="button" class="btn-brand min-h-12" @click="again('new')">Autre client</button>
    </div>
    <button type="button" class="btn-secondary w-full" title="Mêmes articles et même livraison, pour un autre client" @click="again('copy')">⧉ Mêmes articles, autre client</button>
    <button type="button" class="card flex w-full items-center gap-3 p-3 text-left font-semibold" :disabled="printing" @click="printLabel"><ColorIcon name="etiquette" :size="34" />{{ printing ? 'Préparation…' : 'Imprimer l\'étiquette du colis' }}</button>
    <div class="flex justify-center gap-5 text-sm font-bold text-emerald-700">
      <RouterLink :to="`/gestion/commandes/${done.id}`" class="hover:underline">Voir la commande</RouterLink>
      <RouterLink to="/gestion/commandes" class="hover:underline">Liste des commandes</RouterLink>
    </div>
  </div>

  <!-- Toute la commande sur une page : le client, les articles, la livraison, l'argent ; le total suit en bas -->
  <form v-else class="order-form space-y-3 pb-56 lg:pb-6" v-no-autofill novalidate @submit.prevent="save" @focusin="bringToTop">
    <!-- Ligne du lien de retour : retirée dans la fenêtre du téléphone, où le lien est caché (pas de bande vide) -->
    <div class="flex items-center gap-2" :class="{ 'back-row': !conversation }">
      <RouterLink v-if="conversation" :to="`/gestion/messages/${conversation.id}`" class="min-w-0 truncate text-sm text-slate-500 hover:text-emerald-700">← Conversation avec {{ conversation.name || conversation.phone_display }}</RouterLink>
      <RouterLink v-else :to="editing ? `/gestion/commandes/${id}` : '/gestion/commandes'" class="back-link text-sm text-slate-500 hover:text-emerald-700">← {{ editing ? 'Commande' : 'Commandes' }}</RouterLink>
    </div>
    <!-- Plusieurs clients d'un coup (après un live, une liste à saisir) -->
    <RouterLink v-if="!editing && !conversation" to="/gestion/commandes/plusieurs" class="flex items-center justify-end gap-1.5 text-sm font-bold text-emerald-700 hover:underline">
      <ColorIcon name="colis" :size="22" :tile="false" />Plusieurs commandes d'un coup ›
    </RouterLink>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="parseInfo" tone="info">{{ parseInfo }}</Alert>
    <!-- Ce qui manque, après un appui sur « Créer la commande » -->
    <div v-if="missingNotice" data-missing-notice class="sticky top-[4.5rem] z-20 flex items-center gap-2 rounded-xl bg-amber-100 px-4 py-3 text-base font-medium text-amber-900 shadow" role="alert">
      <Icon name="alert" :size="20" class="shrink-0" />{{ missingNotice }} pour {{ editing ? 'enregistrer' : 'créer la commande' }}.
    </div>

    <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_22rem] lg:gap-5">
      <div class="min-w-0 space-y-3">
        <!-- Message du client : lu dans le presse-papiers d'un toucher, ou collé à la main -->
        <template v-if="!editing">
          <section v-if="pasteOpen" class="card space-y-3 border-emerald-100 bg-emerald-50/60 p-4">
            <div class="flex items-center justify-between gap-2">
              <p class="font-bold">Message du client</p>
              <button type="button" class="text-sm font-semibold text-slate-600 hover:underline" @click="pasteOpen = false">Fermer</button>
            </div>
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
            <p v-if="ai.enabled" class="explain-detail">L'assistant comprend les messages mal écrits, en plusieurs fois ou en capture d'écran.</p>
            <AiMeter />
          </section>
          <button v-else type="button" class="flex w-full items-center gap-3 rounded-[22px] border-2 border-dashed border-emerald-200 bg-emerald-50/70 p-3.5 text-left transition active:scale-[.99]" :disabled="parsing" @click="pasteFromClipboard">
            <ColorIcon name="coller" :size="46" />
            <span class="min-w-0">
              <span class="block text-base font-bold text-slate-900">{{ parsing ? 'Analyse du message…' : 'Coller le message du client' }}</span>
              <span class="block text-sm text-slate-600">Numéro, nom, quartier et articles se remplissent seuls.</span>
            </span>
          </button>
        </template>

        <!-- 1. Le client -->
        <section class="card space-y-3 p-4 sm:p-5" aria-labelledby="step-client" @click.capture="enter(0, $event)">
          <h2 id="step-client" class="flex items-center gap-2.5 text-lg font-extrabold"><ColorIcon name="profil" :size="34" />Le client<Icon v-if="folded.client" name="check" :size="18" class="ml-auto text-emerald-600" /></h2>
          <button v-if="folded.client" type="button" class="flex w-full items-center justify-between gap-3 text-left text-sm" @click="folded.client = false">
            <span class="min-w-0 truncate font-semibold">{{ clientSummary }}</span><span class="shrink-0 font-bold text-emerald-700">Modifier</span>
          </button>
          <template v-else>
            <Field id="phone" label="Téléphone" :error="errors.customer_phone || (phoneIncomplete ? 'Numéro incomplet : 10 chiffres attendus.' : '')">
              <template #after-label><span class="text-red-600"> *</span></template>
              <input id="phone" v-model="form.customer_phone" class="input-lg !text-xl !font-bold" type="tel" inputmode="tel" autocomplete="off" placeholder="07 00 00 00 00" :disabled="editing" @input="onPhoneInput" @blur="phoneTouched = true; lookupCustomer()" />
            </Field>

            <!-- Client connu : fiche résumée et son adresse habituelle -->
            <Alert v-if="known?.is_blacklisted" tone="error">Ce client est sur liste noire{{ known.blacklist_reason ? ` : ${known.blacklist_reason}` : '' }}.</Alert>
            <Alert v-else-if="known?.refused_count" tone="warning">Ce client a refusé {{ known.refused_count }} commande(s) : pensez à demander un acompte ou à confirmer avant d'expédier.</Alert>
            <div v-else-if="known" class="flex flex-wrap items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
              <div class="min-w-[11rem] flex-1 text-sm">
                <p class="font-bold">{{ known.name || 'Client connu' }}</p>
                <p class="text-slate-600">{{ known.orders_count }} commande(s)<template v-if="known.delivery_rate !== null && known.delivery_rate !== undefined"> · {{ known.delivery_rate }} % livrées</template></p>
                <p v-if="lastAddressText && addressTaken && form.delivery_method === 'delivery'" class="flex items-start gap-1 font-semibold text-emerald-800"><Icon name="check" :size="16" class="mt-0.5 shrink-0" /><span>Adresse reprise : {{ lastAddressText }}</span></p>
                <p v-else-if="lastAddressText" class="text-slate-600">Dernière adresse : {{ lastAddressText }}</p>
              </div>
              <button v-if="lastAddress && !addressTaken && form.delivery_method === 'delivery'" type="button" class="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-bold text-white max-sm:w-full" @click="useLastAddress(true)">Reprendre l'adresse</button>
            </div>
            <template v-for="o in elsewhere" :key="o.store_id">
              <Alert v-if="o.is_blacklisted" tone="error">Sur liste noire dans {{ o.store_name }}{{ o.blacklist_reason ? ` : ${o.blacklist_reason}` : '' }}.</Alert>
              <p v-else class="text-sm text-slate-600">
                Client de {{ o.store_name }} : {{ o.orders_count }} commande(s), {{ o.delivered_count }} livrée(s)<template v-if="o.refused_count">, {{ o.refused_count }} refusée(s)</template>.
              </p>
            </template>

            <Field id="name" label="Nom" :error="errors.customer_name">
              <template #after-label><span class="font-normal text-slate-600"> (facultatif)</span></template>
              <input id="name" v-model="form.customer_name" class="input text-base" maxlength="120" autocomplete="off" placeholder="Ex. : Fatou Diallo" />
            </Field>

            <div v-if="!editing">
              <p class="label">Vendu sur</p>
              <!-- Une ligne qu'on fait glisser -->
              <div v-tab-strip class="-mx-4 flex gap-1.5 overflow-x-auto px-4 sm:-mx-5 sm:px-5" role="group" aria-label="Canal de vente">
                <button
                  v-for="s in SOURCES"
                  :key="s.value"
                  type="button"
                  class="inline-flex min-h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 text-sm font-semibold"
                  :class="form.source === s.value ? 'border-emerald-700 bg-emerald-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:border-emerald-500'"
                  :aria-pressed="form.source === s.value"
                  @click="form.source = s.value; habitsUsed.source = false"
                >
                  <Icon :name="SOURCE_ICONS[s.value] ?? 'more'" :size="15" />{{ s.label }}
                </button>
              </div>
              <p v-if="habitsUsed.source" class="mt-1.5 text-xs text-slate-600">Comme la dernière fois.</p>
            </div>
          </template>
        </section>

        <!-- 2. Les articles -->
        <section class="card space-y-3 p-4 sm:p-5" aria-labelledby="step-items" @click.capture="enter(1, $event)">
          <h2 id="step-items" class="flex items-center gap-2.5 text-lg font-extrabold">
            <ColorIcon name="panier" :size="34" />Les articles<span v-if="items.length && !folded.articles" class="text-sm font-normal text-slate-500">· {{ items.length }}</span>
            <Icon v-if="folded.articles" name="check" :size="18" class="ml-auto text-emerald-600" />
            <button v-else type="button" class="ml-auto text-sm font-semibold text-emerald-700 hover:underline" @click="items.push({ variant_id: null, title: '', quantity: 1, unit_price: '' })">+ Hors catalogue</button>
          </h2>
          <button v-if="folded.articles" type="button" class="flex w-full items-center justify-between gap-3 text-left text-sm" @click="folded.articles = false">
            <span class="min-w-0 truncate font-semibold">{{ articlesSummary }}</span><span class="shrink-0 font-bold text-emerald-700">Modifier</span>
          </button>
          <template v-else>
            <p v-if="errors.items" class="text-sm text-red-600">{{ errors.items }}</p>
            <ProductPicker @add="(line) => items.push(line)" />
            <ul v-if="items.length" class="space-y-2">
              <li v-for="(item, index) in items" :key="index" class="rounded-xl border border-slate-200 p-3">
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
                  <div class="flex items-center gap-2">
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg font-bold text-slate-700 disabled:opacity-40" :disabled="item.quantity <= 1" aria-label="Moins" @click="step(item, -1)">−</button>
                    <input v-model.number="item.quantity" type="number" min="1" class="w-10 border-0 bg-transparent p-0 text-center text-base font-bold" aria-label="Quantité" />
                    <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-emerald-700 text-lg font-bold text-white" aria-label="Plus" @click="step(item, 1)">+</button>
                  </div>
                  <label class="flex items-center gap-1.5 text-sm text-slate-500">
                    Prix
                    <input v-model.number="item.unit_price" type="number" min="0" class="input w-24 py-1.5 text-right" :readonly="!canPrice" :class="{ 'bg-slate-50 text-slate-500': !canPrice }" @input="item.price_edited = true" />
                  </label>
                  <span class="ml-auto font-bold tabular-nums">{{ money(lineTotal(item)) }}</span>
                </div>
                <p v-if="!item.price_edited && (item.flash || item.volume)" class="mt-2 text-xs text-emerald-700">
                  {{ item.flash ? 'Prix de vente flash' : 'Prix par quantité' }}<template v-if="item.compare_at"> (au lieu de {{ money(item.compare_at) }})</template><template v-if="item.volume"> · {{ money(item.line_total) }} pour {{ item.quantity }}</template>
                </p>
                <p v-if="errors[`items.${index}.quantity`] || errors[`items.${index}.title`]" class="mt-2 text-sm text-red-600">{{ errors[`items.${index}.quantity`] || errors[`items.${index}.title`] }}</p>
                <div v-if="item.needs_variant" data-needs-variant class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                  <span class="text-amber-800">Quelle variante ?</span>
                  <button v-if="!item.choices" type="button" class="btn-secondary px-2 py-1" @click="chooseVariantFor(item)">Choisir</button>
                  <button v-for="v in item.choices ?? []" :key="v.id" type="button" class="rounded-full border border-field bg-white px-3 py-1 hover:border-emerald-600" @click="setVariant(item, v)">
                    {{ v.title }} ({{ v.available }})
                  </button>
                </div>
              </li>
            </ul>
            <p v-else class="flex items-center gap-1.5 text-sm text-slate-600">
              <Icon name="up" :size="16" class="shrink-0 text-emerald-700" />Tapez le nom ou le code, puis touchez l'article.
            </p>
          </template>
        </section>

        <!-- 3. La livraison : où, quand, qui livre -->
        <section class="card space-y-3 p-4 sm:p-5" aria-labelledby="step-delivery" @click.capture="enter(2, $event)">
          <div class="flex items-center gap-2.5">
            <h2 id="step-delivery" class="flex items-center gap-2.5 whitespace-nowrap text-lg font-extrabold"><ColorIcon name="moto" :size="34" />La livraison</h2>
            <Icon v-if="folded.livraison" name="check" :size="18" class="ml-auto text-emerald-600" />
            <div v-else class="ml-auto grid shrink-0 grid-cols-2 gap-1 rounded-xl bg-slate-100 p-1 text-sm font-semibold" role="group" aria-label="Livraison ou retrait">
              <button type="button" class="rounded-lg px-2.5 py-1.5" :class="form.delivery_method === 'delivery' ? 'bg-white shadow-sm' : 'text-slate-600'" :aria-pressed="form.delivery_method === 'delivery'" @click="form.delivery_method = 'delivery'">Livraison</button>
              <button type="button" class="rounded-lg px-2.5 py-1.5" :class="form.delivery_method === 'pickup' ? 'bg-white shadow-sm' : 'text-slate-600'" :aria-pressed="form.delivery_method === 'pickup'" @click="form.delivery_method = 'pickup'">Retrait</button>
            </div>
          </div>
          <button v-if="folded.livraison" type="button" class="flex w-full items-center justify-between gap-3 text-left text-sm" @click="folded.livraison = false">
            <span class="min-w-0 truncate font-semibold">{{ livraisonSummary }}</span><span class="shrink-0 font-bold text-emerald-700">Modifier</span>
          </button>

          <template v-else-if="form.delivery_method === 'delivery'">
            <!-- Où livrer -->
            <div id="place-field" :class="addressFlash ? 'rounded-xl ring-4 ring-emerald-300 transition' : 'transition'">
              <p class="mb-1.5 flex items-center gap-1.5 text-base font-extrabold"><ColorIcon name="lieu" :size="24" :tile="false" />Où livrer <span class="text-red-600">*</span></p>
              <DeliveryPlacePicker id="place" v-model="place" :carrier-id="placeCarrier?.id ?? null" :carrier-name="placeCarrier?.name ?? ''" @change-deliverer="pickerOpen = true" />
              <p v-if="errors.shipping_place_id" class="mt-1.5 text-sm text-red-600">{{ errors.shipping_place_id }}</p>
              <p v-else-if="placeHint" class="mt-2 text-sm" :class="placeHint.tone === 'text-emerald-800' ? 'inline-flex rounded-full bg-emerald-50 px-3 py-1 font-bold text-emerald-800' : placeHint.tone">{{ placeHint.text }}</p>
              <label for="address" class="sr-only">Adresse et repère</label>
              <textarea id="address" v-model="form.shipping_address" class="input mt-2 resize-none text-base" :rows="addressRows" maxlength="255" placeholder="Adresse et repère (face pharmacie…)" />
            </div>

            <!-- Expédition : prix convenu (livraison + expédition), qui paie les frais d'expédition, paiement avant le départ -->
            <div v-if="expedition" class="space-y-2 rounded-xl border border-sky-200 bg-sky-50/60 p-3">
              <p class="font-semibold text-sky-900">📦 Expédition vers {{ expedition.city }}</p>
              <p class="explain-detail">Prix de livraison et d'expédition convenu : plus bas (« Modifier » pour le changer).</p>
              <p class="pt-1 text-sm font-semibold text-slate-700">Qui paie les frais d'expédition (la compagnie de transport) ?</p>
              <template v-for="[value, label, hint] in PAYERS" :key="value">
                <label v-if="value === 'included' || otherPayer || form.expedition_payer === value" class="flex items-start gap-2 rounded-lg bg-white px-3 py-2">
                  <input v-model="form.expedition_payer" type="radio" :value="value" class="mt-1 h-4 w-4 accent-emerald-700" />
                  <span><span class="block font-medium">{{ label }}<span v-if="value === 'included'" class="font-normal text-slate-600"> (habituel)</span></span><span class="block text-xs text-slate-600">{{ hint }}</span></span>
                </label>
              </template>
              <button v-if="!otherPayer" type="button" class="text-sm font-semibold text-emerald-700 hover:underline" @click="otherPayer = true">Autre cas ▾</button>
              <label class="flex items-start gap-2 pt-1">
                <input v-model="form.expedition_prepay" type="checkbox" class="mt-1 h-4 w-4 accent-emerald-700" />
                <span><span class="block font-medium">Payé avant le départ</span><span class="block explain-detail">Le livreur ne part à la gare qu'une fois la commande payée.</span></span>
              </label>
            </div>

            <!-- Quand livrer -->
            <div>
              <p id="delivery-date" class="mb-1.5 flex items-center gap-1.5 text-base font-extrabold"><ColorIcon name="calendrier" :size="24" :tile="false" />Quand livrer <span class="text-red-600">*</span></p>
              <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="Jour de livraison">
                <button
                  v-for="d in quickDays"
                  :key="d.date"
                  type="button"
                  role="radio"
                  :aria-checked="!otherDay && form.scheduled_date === d.date"
                  class="rounded-xl border-2 px-1 py-2 text-center text-sm font-bold"
                  :class="!otherDay && form.scheduled_date === d.date ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : d.disabled ? 'cursor-not-allowed border-slate-200 text-slate-400 line-through' : 'border-slate-200 text-slate-700'"
                  :disabled="d.disabled"
                  :title="!d.open ? 'Pas de livraison ce jour-là' : d.disabled ? 'Trop tôt pour qui livre' : undefined"
                  @click="pickDate = false; form.scheduled_date = d.date; habitsUsed.day = false"
                >
                  <span class="block first-letter:uppercase">{{ d.label }}</span>
                  <span class="block text-xs font-medium" :class="d.disabled ? '' : 'text-slate-600'">{{ d.disabled && d.open && calendar?.cutoff ? `après ${calendar.cutoff.replace(':', ' h ')}` : new Date(`${d.date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }) }}</span>
                </button>
                <button type="button" role="radio" :aria-checked="otherDay" class="rounded-xl border-2 px-1 py-2 text-center text-sm font-bold" :class="otherDay ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'" @click="pickDate = true; habitsUsed.day = false">
                  <span class="block">Autre date</span><span class="block text-xs font-medium text-slate-600">{{ otherDay && form.scheduled_date ? new Date(`${form.scheduled_date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' }) : '📅' }}</span>
                </button>
              </div>
              <p v-if="!calendar && optionsLoading" class="mt-1.5 text-sm text-slate-600">Chargement des jours…</p>
              <input v-if="otherDay" v-model="form.scheduled_date" type="date" :min="earliestDate ?? isoDay(0)" aria-label="Date de livraison" class="input mt-2 w-auto" />
              <p v-if="errors.scheduled_date" class="mt-1.5 text-sm text-red-600">{{ errors.scheduled_date }}</p>
              <p v-else-if="dateNotice" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-base text-amber-900">{{ dateNotice }}</p>
              <p v-else-if="closedChosen" class="mt-2 text-sm text-amber-800">D'habitude, pas de livraison ce jour-là.</p>
              <p v-else-if="habitsUsed.day" class="mt-1.5 text-xs text-slate-600">Comme la dernière fois.</p>
            </div>

            <!-- Qui livre : en grand, avec le prix de chacun pour cette commande -->
            <div>
              <p class="mb-1.5 flex items-center gap-1.5 text-base font-extrabold"><ColorIcon name="moto" :size="24" :tile="false" />Qui livre</p>
              <p v-if="!options && optionsLoading" class="text-sm text-slate-600">Chargement…</p>
              <div v-else class="space-y-1.5">
                <button
                  v-for="d in delivererCards"
                  :key="d.key"
                  type="button"
                  class="flex w-full items-center gap-3 rounded-xl border-2 px-3 py-2.5 text-left disabled:opacity-60"
                  :class="chosen === d.key ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-400'"
                  :aria-pressed="chosen === d.key"
                  :disabled="!canPrice"
                  @click="chosen = d.key"
                >
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-sm font-extrabold" :class="d.tone">{{ initials(d.name) }}</span>
                  <span class="min-w-0 flex-1"><span class="block truncate font-bold">{{ d.name }}</span><span class="block truncate text-xs text-slate-600">{{ d.sub }}</span></span>
                  <span v-if="canPrice && d.price !== null && d.price !== undefined" class="shrink-0 whitespace-nowrap font-extrabold text-emerald-800">{{ money(d.price) }}</span>
                </button>
                <button type="button" class="flex w-full items-center gap-3 rounded-xl border-2 px-3 py-2.5 text-left disabled:opacity-60" :class="!chosen ? 'border-emerald-700 bg-emerald-50' : 'border-slate-200 bg-white hover:border-emerald-400'" :aria-pressed="!chosen" :disabled="!canPrice" @click="chosen = ''">
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-500"><Icon name="clock" :size="18" /></span>
                  <span class="min-w-0 flex-1"><span class="block font-bold">Décider plus tard</span><span class="block text-xs text-slate-600">Choisir au moment de faire livrer</span></span>
                </button>
                <button v-if="canPrice" type="button" class="pt-1 text-sm font-bold text-emerald-700 hover:underline" @click="pickerOpen = true">{{ moreDeliverers ? `Voir tous les livreurs et entreprises (${moreDeliverers} de plus) ›` : 'Ajouter un livreur ›' }}</button>
              </div>
              <p v-if="errors.planned_courier_id || errors.planned_carrier_connection_id || errors.planned_carrier_slug" class="mt-1.5 text-sm text-red-600">{{ errors.planned_courier_id || errors.planned_carrier_connection_id || errors.planned_carrier_slug }}</p>
              <p v-else-if="chosenDirectory" class="mt-1.5 rounded-lg bg-violet-50 p-2 text-sm text-violet-900">
                Enregistrer ouvre votre compte chez <b>{{ chosenDirectory.name }}</b> (nom, téléphone et adresse partagés).
                <template v-if="chosenDirectory.accepts === 'request'"> Sans accord en 24 h, la commande revient à assigner.</template>
              </p>
              <p v-else-if="chosenCourier && place && !chosenCourier.covers_zone && !chosenCourier.all_zones" class="mt-1.5 text-sm text-amber-800">{{ chosenCourier.name }} ne couvre pas ce quartier d'habitude.</p>
              <button v-if="chosenCarrier && !chosenCarrier.directory" type="button" class="mt-1 block text-sm font-medium text-emerald-700 hover:underline" @click="openTariffs">Zones et tarifs de {{ chosenCarrier.name }}</button>
            </div>

            <!-- Frais : calculés, modifiables d'un geste -->
            <div class="flex items-center gap-2 border-t border-slate-100 pt-3 text-sm">
              <span class="shrink-0 text-slate-600">{{ expedition ? 'Livraison + expédition' : 'Frais de livraison' }}</span>
              <template v-if="manualFee">
                <input v-model="shippingFee" class="input w-24 py-1 text-right" type="number" min="0" :aria-label="expedition ? 'Livraison + expédition' : 'Frais de livraison'" @input="askRemember = true" />
                <span>F</span>
                <button type="button" class="font-semibold text-emerald-700 hover:underline" @click="manualFee = false">Recalculer</button>
              </template>
              <template v-else>
                <span class="min-w-0 flex-1 truncate" :title="feeSource || undefined">
                  <b class="font-bold tabular-nums">{{ fee ? money(fee) : expedition && expedition.fee === null ? 'À indiquer' : 'Offerts' }}</b><span v-if="feeSource" class="text-slate-600"> · {{ feeSource }}</span><span v-if="optionsLoading" class="text-slate-400"> …</span>
                </span>
                <button v-if="canPrice" type="button" class="shrink-0 font-bold text-emerald-700 hover:underline" @click="editFee">Modifier</button>
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
          </template>
          <div v-else>
            <p class="explain">Pas de frais de livraison.</p>
            <p class="explain-detail">Le client vient chercher sa commande.</p>
          </div>
        </section>

        <!-- 4. L'argent -->
        <section class="card space-y-3 p-4 sm:p-5" aria-labelledby="step-money" @click.capture="enter(3, $event)">
          <h2 id="step-money" class="flex items-center gap-2.5 text-lg font-extrabold"><ColorIcon name="billets" :size="34" />L'argent</h2>

          <!-- Déjà payé (Wave, Orange Money…) avant l'enregistrement : noté avec la commande -->
          <div v-if="!editing && canPrice" class="space-y-2">
            <p class="label">Le client a-t-il déjà payé ?</p>
            <div class="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="Le client a-t-il déjà payé ?">
              <button v-for="[value, label] in [['none', 'Rien'], ['part', 'Une partie'], ['all', 'Tout']]" :key="value" type="button" role="radio" :aria-checked="paid.mode === value"
                      class="rounded-xl border-2 px-1 py-2 text-sm font-bold" :class="paid.mode === value ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'" @click="paid.mode = value">{{ value === 'all' && paid.mode === 'all' ? '✅ ' : '' }}{{ label }}</button>
            </div>
            <template v-if="paid.mode !== 'none'">
              <div v-if="paid.mode === 'part'" class="flex items-center justify-between gap-2">
                <label for="paid-amount" class="text-slate-600">Montant reçu (F)</label>
                <input id="paid-amount" v-model.number="paid.amount" type="number" min="1" :max="total" class="input w-32 py-1 text-right" />
              </div>
              <p class="text-sm text-slate-600">Payé par</p>
              <div class="flex flex-wrap gap-1.5">
                <button v-for="[value, label] in PAY_METHODS" :key="value" type="button" class="rounded-full border px-3 py-1 text-sm" :class="paid.method === value ? 'border-2 border-emerald-700 bg-emerald-50 font-bold text-emerald-900' : 'border-field'" @click="paid.method = value">{{ label }}</button>
              </div>
              <input v-model.trim="paid.reference" class="input py-1.5 text-sm" maxlength="100" placeholder="N° de transaction (facultatif)" aria-label="N° de transaction" />
              <p v-if="errors['paid.amount'] || errors['paid.method']" class="text-xs text-red-600">{{ errors['paid.amount'] || errors['paid.method'] }}</p>
              <div class="flex items-baseline justify-between">
                <span class="text-slate-600">Reste à encaisser</span>
                <span class="font-bold tabular-nums">{{ money(due) }}<span v-if="due === 0" class="ml-1.5 rounded-full bg-emerald-700 px-2 py-0.5 text-xs font-extrabold text-white">PAYÉ</span></span>
              </div>
            </template>
            <p v-if="expedition && form.expedition_prepay && paid.mode !== 'none'" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="due === 0 ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'">
              {{ due === 0 ? 'Expédition déjà payée : le livreur peut partir à la gare sans attendre.' : `Expédition à payer d'avance : il manque ${money(due)} avant le départ.` }}
            </p>
          </div>

          <!-- Acompte : non, automatique (s'il y en a un), en pourcentage ou un montant -->
          <div class="space-y-2">
            <p class="label">Acompte avant de livrer ?</p>
            <div class="grid gap-1.5" :class="autoDeposit ? 'grid-cols-4' : 'grid-cols-3'" role="radiogroup" aria-label="Acompte">
              <button v-for="[mode, label] in [['none', 'Non'], ...(autoDeposit ? [['auto', `Auto ${money(autoDeposit)}`]] : []), ['percent', '%'], ['amount', 'Montant']]" :key="mode" type="button" role="radio" :aria-checked="depositMode === mode"
                      class="rounded-xl border-2 px-1 py-2 text-sm font-bold leading-tight" :class="depositMode === mode ? 'border-emerald-700 bg-emerald-50 text-emerald-900' : 'border-slate-200 text-slate-700'" @click="setDepositMode(mode)">{{ label }}</button>
            </div>
            <div v-if="depositMode !== 'none'" class="flex items-center justify-between gap-2">
              <label for="deposit" class="text-slate-600">{{ depositMode === 'auto' ? 'Acompte automatique' : depositMode === 'percent' ? 'Tapez le pourcentage de l\'acompte' : 'Tapez le montant de l\'acompte' }}</label>
              <div class="relative w-32 shrink-0">
                <input v-if="depositMode === 'auto'" id="deposit" :value="autoDeposit" type="number" disabled class="input w-full cursor-not-allowed bg-slate-100 py-1 pr-7 text-right text-slate-500" />
                <input v-else-if="depositMode === 'percent'" id="deposit" v-model="depositPercent" type="number" min="1" max="100" inputmode="numeric" class="input w-full py-1 pr-7 text-right" :class="{ 'border-red-500': percentTooHigh }" placeholder="30" :aria-invalid="percentTooHigh" />
                <input v-else id="deposit" v-model="depositFixed" type="number" min="0" :max="total" inputmode="numeric" class="input w-full py-1 pr-7 text-right" :class="{ 'border-red-500': depositTooHigh }" placeholder="5000" :aria-invalid="depositTooHigh" />
                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-slate-500">{{ depositMode === 'percent' ? '%' : 'F' }}</span>
              </div>
            </div>
            <p v-if="depositTooHigh || percentTooHigh || errors.deposit_required" class="text-right text-xs font-semibold text-red-600" role="alert">{{ depositTooHigh ? `L'acompte ne peut pas dépasser le total de la commande (${money(total)}).` : percentTooHigh ? 'L\'acompte ne peut pas dépasser 100 % du total.' : errors.deposit_required }}</p>
            <p v-else-if="depositMode === 'auto'" class="text-right explain-detail">Calculé selon les articles et le réglage de la boutique.</p>
            <p v-else-if="depositMode === 'percent' && depositAmount > 0" class="text-right explain-detail">{{ depositPercent }} % de {{ money(total) }}, arrondi aux 100 F.</p>
            <!-- Ce que l'acompte veut dire pour cette commande -->
            <div v-if="!depositTooHigh && !percentTooHigh && depositAmount > 0" class="rounded-lg px-3 py-2 text-sm font-semibold" :class="depositLeft === 0 ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'">
              <p v-if="depositLeft === 0">✅ Acompte de {{ money(depositAmount) }} déjà couvert par ce qui a été payé.</p>
              <p v-else-if="paidSoFar > 0">⏳ Il manque {{ money(depositLeft) }} d'acompte (sur {{ money(depositAmount) }}).</p>
              <p v-else>⏳ Acompte à recevoir : {{ money(depositAmount) }} · reste à la livraison : {{ money(Math.max(0, total - depositAmount)) }}</p>
              <p v-if="depositLeft > 0 && depositRule?.blocks !== false" class="mt-0.5 text-xs font-normal">La commande attend l'acompte avant de partir chez le livreur.</p>
            </div>
          </div>
        </section>

        <!-- Le reste, seulement si besoin -->
        <section class="card px-4 py-1 sm:px-5" @click.capture="enter(4, $event)">
          <button type="button" class="flex w-full items-center justify-between gap-2 py-3 text-left text-base font-semibold" :aria-expanded="showMore" @click="showMore = !showMore">
            <span>{{ showMore ? '−' : '+' }} Remise, note, autre numéro</span>
            <span v-if="!showMore && moreSummary" class="truncate text-sm font-normal text-slate-600">{{ moreSummary }}</span>
          </button>
          <div v-show="showMore" class="space-y-4 pb-4 pt-1">
            <div v-if="canPrice || form.discount_code || form.discount_total" class="space-y-2">
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
            <Field id="customer_note" label="Demande du client">
              <textarea id="customer_note" v-model="form.customer_note" class="input" rows="2" placeholder="Ex. : appeler avant d'arriver" />
            </Field>
            <Field id="internal_note" label="Note interne" hint="Visible seulement par l'équipe">
              <textarea id="internal_note" v-model="form.internal_note" class="input" rows="2" />
            </Field>
            <Field id="phone2" label="Autre numéro du client" :error="errors.shipping_phone2">
              <input id="phone2" v-model="form.shipping_phone2" class="input text-base" type="tel" inputmode="tel" placeholder="Si le premier ne répond pas" @input="form.shipping_phone2 = formatPhone(form.shipping_phone2)" />
            </Field>
          </div>
        </section>
      </div>

      <!-- Ordinateur : le récapitulatif à droite, toujours visible -->
      <aside class="hidden lg:block lg:sticky lg:top-20 lg:self-start">
        <section class="card space-y-3 p-5">
          <h2 class="text-base font-extrabold">Récapitulatif</h2>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-slate-600">Articles</span><span class="tabular-nums">{{ money(subtotal) }}</span></div>
            <div class="flex justify-between gap-2"><span class="text-slate-600">{{ expedition ? 'Livraison + expédition' : 'Livraison' }}</span><span class="tabular-nums">{{ form.delivery_method === 'pickup' ? '—' : fee ? money(fee) : expedition && form.expedition_payer !== 'store' ? 'À indiquer' : 'Offerte' }}</span></div>
            <div v-if="discount || pointsDiscount" class="flex justify-between text-emerald-700"><span>Remise</span><span class="tabular-nums">− {{ money(discount + pointsDiscount) }}</span></div>
          </div>
          <div class="flex items-baseline justify-between border-t border-slate-200 pt-3">
            <span class="font-semibold">Total</span>
            <span class="text-2xl font-extrabold tabular-nums">{{ money(total) }}</span>
          </div>
          <p v-if="due !== total || depositAmount" class="text-sm text-slate-600">{{ form.delivery_method === 'pickup' ? 'À encaisser' : 'Le livreur encaisse' }} <b class="text-slate-900">{{ money(due) }}</b></p>
          <label v-if="!editing" class="flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 px-3 py-2.5">
            <input v-model="form.confirmed" type="checkbox" class="peer sr-only" />
            <span class="h-6 w-11 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-5 w-5 rounded-full bg-white shadow transition" :class="{ 'translate-x-5': form.confirmed }" /></span>
            <span class="text-sm font-semibold">Déjà confirmée avec le client<span class="block text-xs font-normal text-slate-600">Sinon elle va dans « À confirmer ».</span></span>
          </label>
          <button class="btn-brand w-full py-3 text-base" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer les modifications' : 'Créer la commande' }}</button>
        </section>
      </aside>
    </div>

    <!-- Téléphone : le total en direct et la validation, toujours en bas -->
    <div class="fixed inset-x-0 bottom-[calc(4rem+env(safe-area-inset-bottom))] z-10 space-y-2 border-t border-slate-200 bg-white/95 px-4 py-2.5 shadow-[0_-6px_18px_rgba(15,23,42,0.06)] backdrop-blur lg:hidden">
      <label v-if="!editing" class="flex cursor-pointer items-center gap-2.5">
        <input v-model="form.confirmed" type="checkbox" class="peer sr-only" />
        <span class="h-5 w-9 shrink-0 rounded-full bg-slate-300 p-0.5 transition peer-checked:bg-emerald-600"><span class="block h-4 w-4 rounded-full bg-white shadow transition" :class="{ 'translate-x-4': form.confirmed }" /></span>
        <span class="text-sm font-semibold">Déjà confirmée avec le client</span>
      </label>
      <div class="flex items-end justify-between gap-3 text-sm">
        <p class="min-w-0 text-slate-600">
          <span class="block truncate">Articles <b class="text-slate-900">{{ money(subtotal) }}</b> · Livraison <b class="text-slate-900">{{ form.delivery_method === 'pickup' ? '—' : fee ? money(fee) : 'offerte' }}</b></span>
          <span class="block truncate">{{ form.delivery_method === 'pickup' ? 'À encaisser' : 'Le livreur encaisse' }} <b class="text-slate-900">{{ money(due) }}</b></span>
        </p>
        <p class="shrink-0 text-right"><span class="block text-xs text-slate-600">Total</span><span class="text-xl font-extrabold tabular-nums text-emerald-800">{{ money(total) }}</span></p>
      </div>
      <button class="btn-brand min-h-12 w-full text-base" :disabled="saving">{{ saving ? 'Enregistrement…' : editing ? 'Enregistrer' : 'Créer la commande' }}</button>
    </div>

    <DelivererPicker v-if="pickerOpen" v-model="chosen" :options="options" :place-label="place?.label ?? ''" @close="pickerOpen = false" @courier-created="addCourier" />

    <!-- Zones et tarifs de l'entreprise choisie -->
    <Modal v-if="tariffs" :title="`Zones et tarifs de ${tariffs.name}`" @close="tariffs = null">
      <p v-if="!tariffs.zones" class="text-sm text-slate-500">Chargement…</p>
      <template v-else>
        <p class="explain">{{ tariffs.is_api ? 'Ces tarifs sont indicatifs.' : 'Prix payé par le client selon la zone.' }}</p>
        <p class="explain-detail mb-3">{{ tariffs.is_api ? 'Le prix exact est demandé à l\'entreprise pour chaque commande (poids, colis fragile).' : 'Pour les modifier : Livraisons › Entreprises de livraison › Zones et tarifs.' }}</p>
        <p v-if="!tariffs.zones.length" class="explain-detail">Pas encore de zone : elle est proposée partout, aux frais de vos propres zones.</p>
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
