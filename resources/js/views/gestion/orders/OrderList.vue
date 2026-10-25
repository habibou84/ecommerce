<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import DelivererPicker from '../../../components/DelivererPicker.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import Pagination from '../../../components/Pagination.vue'
import http, { download, errorMessage } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'
import { CANCEL_REASONS, PAYMENT_TONES, SOURCES, STATUS_TONES, riskLabel } from '../../../lib/orders'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const canManage = computed(() => auth.can('orders.manage'))
const canAssign = computed(() => auth.can('shipments.manage'))

const TABS = [
  { value: '', label: 'Toutes' },
  { value: 'pending_confirmation', label: 'À confirmer' },
  { value: 'to_ship', label: 'À faire livrer' },
  { value: 'shipped', label: 'En route' },
  { value: 'late', label: 'En retard', alert: true },
  { value: 'completed', label: 'Livrées' },
  { value: 'cancelled', label: 'Annulées' },
  { value: 'returned', label: 'Retournées' },
]
const PERIODS = [
  { value: '', label: 'Tout' },
  { value: 'today', label: 'Aujourd\'hui' },
  { value: 'yesterday', label: 'Hier' },
  { value: 'tomorrow', label: 'Demain' },
  { value: '7d', label: '7 jours' },
  { value: 'month', label: 'Ce mois' },
  { value: 'custom', label: 'Période…' },
]
const SORTS = [
  { value: 'scheduled', label: 'Date de livraison' },
  { value: 'recent', label: 'Commandes récentes' },
  { value: 'oldest', label: 'Commandes anciennes' },
  { value: 'amount', label: 'Montant' },
]

// Filtres, gardés dans l'adresse de la page (retour arrière, lien partagé)
const q = route.query
const filters = reactive({
  status: q.status ?? '', search: q.search ?? '', source: q.source ?? '', period: q.period ?? '', date_field: q.date_field ?? 'scheduled',
  from: q.from ?? '', to: q.to ?? '', deliverer: q.deliverer ?? '', commune_id: q.commune_id ?? '', payment: q.payment ?? '', alert: q.alert ?? '', sort: q.sort ?? 'scheduled', page: 1,
})
const params = () => Object.fromEntries(Object.entries(filters).filter(([k, v]) => v !== '' && v !== null && !(k === 'date_field' && v === 'scheduled') && !(k === 'sort' && v === 'scheduled')))

const orders = ref([])
const counts = ref({})
const summary = ref(null)
const meta = ref(null)
const days = ref({})
const error = ref('')
const loading = ref(false)

// more : ajoute la page suivante sous la liste (« Voir plus » sur téléphone) au lieu de la remplacer
const stacked = ref(false)
async function load(more = false) {
  // Après « Voir plus », un rechargement (action, filtre) repart de la première page
  if (!more && stacked.value) filters.page = 1
  stacked.value = more
  router.replace({ query: { ...params(), page: undefined } })
  loading.value = true
  try {
    const { data } = await http.get('/orders', { params: params() })
    orders.value = more ? [...orders.value, ...data.data.filter((o) => !orders.value.some((x) => x.id === o.id))] : data.data
    counts.value = data.counts
    summary.value = data.summary
    meta.value = data.meta
    days.value = more ? { ...days.value, ...data.days } : data.days
    selected.value = new Set([...selected.value].filter((id) => orders.value.some((o) => o.id === id)))
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

// Qui livre et communes, pour les filtres et pour confier les commandes
const options = ref(null)
const communes = ref([])
onMounted(async () => {
  await load()
  communes.value = (await http.get('/places', { params: { type: 'commune' } }).catch(() => ({ data: { data: [] } }))).data.data
  if (canManage.value) options.value = (await http.post('/orders/delivery-options', {}).catch(() => ({ data: { data: null } }))).data.data
})

let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => { filters.page = 1; load() }, 300)
})
watch(() => [filters.status, filters.source, filters.period, filters.date_field, filters.from, filters.to, filters.deliverer, filters.commune_id, filters.payment, filters.alert, filters.sort], () => {
  if (filters.period === 'custom' && !filters.from) return // en attente de la première date
  filters.page = 1
  load()
})
function setPeriod(value) {
  filters.period = value
  if (value !== 'custom') Object.assign(filters, { from: '', to: '' })
}
const total = (c) => Object.entries(c).filter(([k]) => !['to_ship', 'late'].includes(k)).reduce((s, [, n]) => s + n, 0)

// Filtres actifs (pastilles retirables)
const delivererLabel = (key) => {
  if (key === 'none') return 'Pas encore choisi'
  const [type, id] = key.split(':')
  return (type === 'courier' ? options.value?.couriers : options.value?.carriers)?.find((d) => String(d.id) === id)?.name ?? 'Livreur'
}
// Files des alertes (ouvertes depuis une notification ou l'accueil)
const ALERTS = { unassigned: 'Sans livreur', not_picked: 'Pas encore ramassées', on_the_way: 'Encore en route', unhandled: 'Problème de livraison à traiter' }
watch(() => route.query.alert, (v) => { if ((v ?? '') !== filters.alert) filters.alert = v ?? '' })
const activeFilters = computed(() => [
  filters.alert && ALERTS[filters.alert] && { key: 'alert', label: ALERTS[filters.alert] },
  filters.deliverer && { key: 'deliverer', label: `Qui livre : ${delivererLabel(filters.deliverer)}` },
  filters.commune_id && { key: 'commune_id', label: `Commune : ${communes.value.find((c) => String(c.id) === String(filters.commune_id))?.name ?? '…'}` },
  filters.payment && { key: 'payment', label: filters.payment === 'paid' ? 'Payées' : 'À encaisser' },
  filters.source && { key: 'source', label: `Canal : ${[{ value: 'online_store', label: 'Site' }, { value: 'live', label: 'Live' }, ...SOURCES].find((s) => s.value === filters.source)?.label}` },
].filter(Boolean))
const filtersOpen = ref(false)
function clearFilters() {
  Object.assign(filters, { deliverer: '', commune_id: '', payment: '', source: '', alert: '' })
}

// Commandes regroupées par jour de livraison ; par jour de commande quand on trie par date de commande.
// Sans date : « à fixer » si la commande est à livrer et en cours, sinon le jour où elle a été passée.
// Le serveur donne le jour de chaque commande et le total de chaque jour sur toute la liste, pas seulement la page.
const iso = (d) => d.toLocaleDateString('sv-SE')
function dayLabel(key) {
  if (!key) return 'Date de livraison à fixer'
  const today = new Date()
  const offset = Math.round((new Date(`${key}T12:00:00`) - new Date(`${iso(today)}T12:00:00`)) / 86400000)
  const named = { 0: 'Aujourd\'hui', [-1]: 'Hier', 1: 'Demain' }[offset]
  const long = new Date(`${key}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
  return named ? `${named}, ${long}` : long.charAt(0).toUpperCase() + long.slice(1)
}
const groups = computed(() => {
  const list = []
  for (const o of orders.value) {
    const key = o.day ?? null
    let g = list.find((x) => x.key === key)
    if (!g) list.push((g = { key, orders: [], count: 0, amount: 0 }))
    g.orders.push(o)
    g.count++
    g.amount += ['cancelled', 'returned'].includes(o.status) ? 0 : o.total
  }
  return list.map((g) => ({ ...g, ...(days.value[g.key ?? 'none'] ?? {}) }))
})
const timeOf = (o) => new Date(o.created_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const phone = (o) => phoneDisplay(o.customer_phone) || o.customer_phone_display
function deliveryBadge(o) {
  if (o.delivery_method === 'pickup') return { text: 'Retrait', tone: 'bg-slate-100 text-slate-700' }
  if (!o.scheduled_date) return ['cancelled', 'returned', 'completed'].includes(o.status) ? null : { text: 'Date à fixer', tone: 'bg-amber-50 text-amber-800' }
  if (o.is_late) return { text: `En retard · prévue ${dayLabel(o.scheduled_date).split(',')[0].toLowerCase()}`, tone: 'bg-red-100 text-red-800', late: true }
  const label = dayLabel(o.scheduled_date)
  return { text: label.includes(',') ? label.split(',')[0] : new Date(`${o.scheduled_date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }), tone: o.scheduled_date === iso(new Date()) ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700' }
}

// Action principale de chaque ligne, selon l'étape
function mainAction(o) {
  if (o.status === 'pending_confirmation' && auth.can('orders.confirm')) return { label: 'Confirmer', run: () => confirmOrder(o) }
  if (['confirmed', 'processing'].includes(o.status) && o.delivery_method === 'delivery' && canAssign.value && (!o.deliverer || o.deliverer_planned)) return { label: 'Faire livrer', run: () => openAssign([o.id]) }
  if (o.status === 'shipped' && canManage.value) return { label: 'Livrée', run: () => openComplete(o) }
  return null
}

const notice = ref('')
const busy = ref(false)
async function confirmOrder(o) {
  busy.value = true
  try {
    await http.post(`/orders/${o.id}/confirmations`, { outcome: 'confirmed', channel: 'call' })
    notice.value = `${o.reference} confirmée.`
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Livrée : encaissement à la porte
const completing = ref(null)
const completeForm = reactive({ collect: true, method: 'cash' })
function openComplete(o) {
  completing.value = o
  Object.assign(completeForm, { collect: o.amount_due > 0, method: 'cash' })
}
async function complete() {
  busy.value = true
  try {
    await http.post(`/orders/${completing.value.id}/status`, { status: 'completed', ...(completeForm.collect ? { collect_payment: true, method: completeForm.method } : {}) })
    notice.value = `${completing.value.reference} livrée.`
    completing.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Sélection de plusieurs commandes
const selected = ref(new Set())
const allSelected = computed(() => orders.value.length > 0 && orders.value.every((o) => selected.value.has(o.id)))
function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}
function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(orders.value.map((o) => o.id))
}

async function bulk(body, ids = [...selected.value]) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post('/orders/bulk', { ids, ...body })
    const r = data.data
    notice.value = `${r.done} commande(s) traitée(s)${r.skipped.length ? `. Non traitées : ${r.skipped.map((s) => `${s.reference} (${s.reason})`).join(', ')}` : '.'}`
    selected.value = new Set()
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Confier à un livreur ou une entreprise (une ou plusieurs commandes)
const assignIds = ref(null)
const assignChoice = ref('')
function openAssign(ids) {
  assignIds.value = ids
  assignChoice.value = ''
}
watch(assignChoice, (key) => {
  if (!key || !assignIds.value) return
  const [type, id] = key.split(':')
  const ids = assignIds.value
  assignIds.value = null
  bulk(type === 'courier' ? { action: 'assign', courier_id: Number(id) } : { action: 'assign', carrier_connection_id: Number(id) }, ids)
})

// Changer le statut
const statusOpen = ref(false)
const statusForm = reactive({ status: 'processing', reason: 'customer_request' })
async function applyStatus() {
  statusOpen.value = false
  await bulk({ action: 'status', status: statusForm.status, reason: statusForm.status === 'cancelled' ? statusForm.reason : null })
}

async function labels() {
  const ids = [...selected.value].map((id) => `ids[]=${id}`).join('&')
  await download(`/orders/labels?${ids}`, 'etiquettes.pdf')
}

async function exportList() {
  await download(`/orders/export?${new URLSearchParams(params()).toString()}`, 'commandes.csv')
}
</script>

<template>
  <div class="space-y-4">
    <!-- Recherche, période, filtres, tri, export -->
    <div class="flex flex-wrap items-center gap-2">
      <div class="relative min-w-0 flex-1 sm:max-w-xs">
        <Icon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
        <input v-model="filters.search" type="search" class="input pl-10" placeholder="N°, nom ou téléphone" aria-label="Rechercher" />
      </div>
      <button type="button" class="btn-secondary relative shrink-0 px-3" @click="filtersOpen = true">
        <Icon name="filter" :size="18" />Filtres
        <span v-if="activeFilters.length" class="rounded-full bg-emerald-600 px-1.5 text-xs text-white">{{ activeFilters.length }}</span>
      </button>
      <select v-model="filters.sort" class="input hidden w-auto sm:block" aria-label="Trier">
        <option v-for="s in SORTS" :key="s.value" :value="s.value">Trier : {{ s.label.toLowerCase() }}</option>
      </select>
      <button type="button" class="btn-ghost hidden sm:inline-flex" @click="exportList">Exporter (Excel)</button>
      <RouterLink v-if="canManage" to="/gestion/commandes/retours" class="btn-ghost hidden xl:inline-flex">Retours</RouterLink>
      <RouterLink v-if="canManage" to="/gestion/commandes/nouvelle" class="btn-brand ml-auto hidden lg:inline-flex"><Icon name="plus" :size="18" />Commande</RouterLink>
    </div>

    <div v-tab-strip class="-mx-4 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <button
        v-for="p in PERIODS"
        :key="p.value"
        type="button"
        class="shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium"
        :class="filters.period === p.value ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
        @click="setPeriod(p.value)"
      >
        {{ p.label }}
      </button>
    </div>
    <div v-if="filters.period === 'custom'" class="flex flex-wrap items-center gap-2 text-sm">
      Du <input v-model="filters.from" type="date" class="input w-auto" aria-label="Du" />
      au <input v-model="filters.to" type="date" class="input w-auto" aria-label="Au" />
    </div>

    <div v-if="activeFilters.length" class="flex flex-wrap gap-2 text-sm">
      <button v-for="f in activeFilters" :key="f.key" type="button" class="inline-flex items-center gap-1 rounded-full bg-slate-900 px-3 py-1 text-white" @click="filters[f.key] = ''">
        {{ f.label }}<Icon name="close" :size="14" />
      </button>
      <button type="button" class="text-emerald-700 hover:underline" @click="clearFilters">Tout effacer</button>
    </div>

    <nav v-tab-strip class="-mx-4 flex gap-1 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0">
      <button
        v-for="tab in TABS"
        :key="tab.value"
        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
        :class="[filters.status === tab.value ? 'border-emerald-700 text-emerald-800' : 'border-transparent text-slate-600 hover:text-slate-900', tab.alert && counts.late ? 'text-red-700' : '']"
        @click="filters.status = tab.value"
      >
        {{ tab.label }}
        <span class="ml-1 rounded-full px-1.5 text-xs" :class="tab.alert && counts.late ? 'bg-red-100 text-red-800' : 'bg-slate-100'">{{ tab.value ? counts[tab.value] ?? 0 : total(counts) }}</span>
      </button>
    </nav>

    <!-- Résumé de la liste affichée -->
    <div v-if="summary" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="card p-3"><p class="text-sm text-slate-500">Commandes</p><p class="text-xl font-bold tabular-nums">{{ summary.count }}</p></div>
      <div class="card p-3"><p class="text-sm text-slate-500">Montant</p><p class="text-xl font-bold tabular-nums">{{ money(summary.amount) }}</p></div>
      <div class="card p-3"><p class="text-sm text-slate-500">À encaisser</p><p class="text-xl font-bold tabular-nums" :class="summary.to_collect ? 'text-amber-800' : ''">{{ money(summary.to_collect) }}</p></div>
      <button type="button" class="card p-3 text-left hover:border-emerald-600" @click="Object.assign(filters, { period: 'today', date_field: 'scheduled', status: '' })">
        <p class="text-sm text-slate-500">À livrer aujourd'hui</p><p class="text-xl font-bold tabular-nums text-emerald-800">{{ summary.due_today }}</p>
      </button>
    </div>

    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <!-- Plusieurs commandes cochées -->
    <div v-if="selected.size" class="sticky top-[4.5rem] z-20 flex flex-wrap items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm text-white shadow-lg">
      <span class="font-semibold">{{ selected.size }} sélectionnée(s)</span>
      <button v-if="canAssign" type="button" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/20" :disabled="busy" @click="openAssign([...selected])">Confier à un livreur</button>
      <button type="button" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/20" :disabled="busy" @click="labels">Imprimer les étiquettes</button>
      <button v-if="canManage" type="button" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/20" :disabled="busy" @click="statusOpen = true">Changer le statut</button>
      <button v-if="canManage" type="button" class="rounded-lg bg-white/10 px-3 py-1.5 hover:bg-white/20" :disabled="busy" @click="bulk({ action: 'whatsapp' })">Envoyer un WhatsApp</button>
      <button type="button" class="ml-auto text-white/70 hover:text-white" @click="selected = new Set()">Tout désélectionner</button>
    </div>

    <p v-if="loading && !orders.length" class="text-sm text-slate-500">Chargement…</p>
    <p v-else-if="!orders.length" class="card px-4 py-8 text-center text-sm text-slate-500">Aucune commande.</p>

    <!-- Ordinateur : tableau groupé par jour -->
    <div v-if="orders.length" class="card hidden overflow-x-auto lg:block">
      <table class="w-full text-left text-sm">
        <thead class="text-xs font-semibold uppercase tracking-wide text-slate-500">
          <tr>
            <th class="w-10 py-2.5 pl-4"><input type="checkbox" class="h-4 w-4 accent-emerald-700" :checked="allSelected" aria-label="Tout sélectionner" @change="toggleAll" /></th>
            <th class="py-2.5 pr-3">N°</th>
            <th class="pr-3">Client</th>
            <th class="pr-3">Livraison</th>
            <th class="pr-3">Articles</th>
            <th class="pr-3">Qui livre</th>
            <th class="pr-3 text-right">Montant</th>
            <th class="pr-3">Statut</th>
            <th class="pr-4 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="g in groups" :key="g.key ?? 'sans-date'">
            <tr class="bg-slate-50">
              <td colspan="9" class="px-4 py-2"><span class="font-bold text-slate-800">{{ dayLabel(g.key) }}</span><span class="text-slate-500"> · {{ g.count }} commande{{ g.count > 1 ? 's' : '' }} · {{ money(g.amount) }}</span><span v-if="g.count > g.orders.length" class="text-slate-500"> ({{ g.orders.length }} sur cette page)</span></td>
            </tr>
            <tr v-for="o in g.orders" :key="o.id" class="border-t border-slate-100" :class="selected.has(o.id) ? 'bg-emerald-50/60' : 'hover:bg-slate-50'">
              <td class="py-3 pl-4"><input type="checkbox" class="h-4 w-4 accent-emerald-700" :checked="selected.has(o.id)" :aria-label="`Sélectionner ${o.reference}`" @change="toggle(o.id)" /></td>
              <td class="py-3 pr-3"><RouterLink :to="`/gestion/commandes/${o.id}`" class="font-semibold hover:text-emerald-700">{{ o.reference }}</RouterLink><p class="text-xs text-slate-500">{{ timeOf(o) }}</p></td>
              <td class="py-3 pr-3">
                <RouterLink :to="`/gestion/commandes/${o.id}`" class="font-medium hover:text-emerald-700">{{ o.customer_name || phone(o) || 'Client au comptoir' }}</RouterLink>
                <p class="text-slate-500">{{ phone(o) }}<span v-if="riskLabel(o.risk_score)" class="ml-1 rounded-full px-1.5 text-xs" :class="riskLabel(o.risk_score).tone">{{ riskLabel(o.risk_score).label }}</span></p>
              </td>
              <td class="py-3 pr-3">
                <p>{{ o.delivery_method === 'pickup' ? 'Retrait au magasin' : o.shipping_place_label || 'Quartier à préciser' }}</p>
                <span v-if="deliveryBadge(o)" class="mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="deliveryBadge(o).tone">
                  <Icon :name="deliveryBadge(o).late ? 'alert' : 'truck'" :size="12" />{{ deliveryBadge(o).text }}
                </span>
              </td>
              <td class="max-w-56 py-3 pr-3 text-slate-600">{{ o.items_label }}</td>
              <td class="py-3 pr-3 whitespace-nowrap">{{ o.deliverer ?? '—' }}<span v-if="o.deliverer_planned" class="block text-xs text-slate-500">prévu</span></td>
              <td class="py-3 pr-3 text-right"><p class="font-semibold tabular-nums">{{ money(o.total) }}</p><p class="whitespace-nowrap text-xs" :class="PAYMENT_TONES[o.payment_status]">{{ o.amount_due > 0 && !['cancelled', 'returned'].includes(o.status) ? 'À encaisser' : o.payment_status_label }}</p></td>
              <td class="py-3 pr-3">
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_TONES[o.status]">{{ o.status_label }}</span>
                <p v-if="o.callback_at && o.status === 'pending_confirmation'" class="mt-0.5 text-xs text-amber-800">Rappel {{ new Date(o.callback_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) }}</p>
              </td>
              <td class="py-3 pr-4">
                <div class="flex items-center justify-end gap-1.5">
                  <a v-if="o.customer_phone" :href="`tel:${o.customer_phone}`" class="grid h-8 w-8 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" :title="`Appeler ${o.customer_name ?? ''}`"><Icon name="phone" :size="15" /></a>
                  <a v-if="o.whatsapp_url" :href="o.whatsapp_url" target="_blank" rel="noopener" class="grid h-8 w-8 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" title="WhatsApp"><Icon name="messages" :size="15" /></a>
                  <button v-if="mainAction(o)" type="button" class="whitespace-nowrap rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800" :disabled="busy" @click="mainAction(o).run()">{{ mainAction(o).label }}</button>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Téléphone et tablette : une carte par commande, groupées par jour -->
    <div class="space-y-3 lg:hidden">
      <template v-for="g in groups" :key="g.key ?? 'sans-date'">
        <p class="flex items-baseline justify-between pt-1 text-sm"><span class="font-bold text-slate-800">{{ dayLabel(g.key).split(',')[0] }} · {{ g.count }} commande{{ g.count > 1 ? 's' : '' }}</span><span class="font-semibold tabular-nums">{{ money(g.amount) }}</span></p>
        <div v-for="o in g.orders" :key="o.id" class="card p-4" :class="{ 'ring-2 ring-emerald-600': selected.has(o.id) }">
          <div class="flex items-start gap-3">
            <input type="checkbox" class="mt-1 h-5 w-5 shrink-0 accent-emerald-700" :checked="selected.has(o.id)" :aria-label="`Sélectionner ${o.reference}`" @change="toggle(o.id)" />
            <RouterLink :to="`/gestion/commandes/${o.id}`" class="min-w-0 flex-1">
              <p class="text-base font-semibold leading-snug">{{ o.customer_name || phone(o) || 'Client au comptoir' }}</p>
              <p class="text-sm text-slate-500">{{ o.reference }} · {{ timeOf(o) }} · {{ o.delivery_method === 'pickup' ? 'Retrait' : o.shipping_place_label || 'Quartier à préciser' }}</p>
            </RouterLink>
            <div class="text-right">
              <p class="font-bold tabular-nums">{{ money(o.total) }}</p>
              <p class="text-xs" :class="PAYMENT_TONES[o.payment_status]">{{ o.amount_due > 0 && !['cancelled', 'returned'].includes(o.status) ? 'À encaisser' : o.payment_status_label }}</p>
            </div>
          </div>
          <p class="mt-1.5 text-sm text-slate-600">{{ o.items_label }}</p>
          <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_TONES[o.status]">{{ o.status_label }}</span>
            <span v-if="deliveryBadge(o)" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="deliveryBadge(o).tone"><Icon :name="deliveryBadge(o).late ? 'alert' : 'truck'" :size="12" />{{ deliveryBadge(o).text }}</span>
            <span v-if="o.deliverer" class="text-xs text-slate-500">· {{ o.deliverer }}</span>
          </div>
          <div class="mt-3 flex gap-2">
            <a v-if="o.customer_phone" :href="`tel:${o.customer_phone}`" class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600" aria-label="Appeler"><Icon name="phone" :size="18" /></a>
            <a v-if="o.whatsapp_url" :href="o.whatsapp_url" target="_blank" rel="noopener" class="grid h-10 w-10 shrink-0 place-items-center rounded-xl border border-slate-200 text-slate-600" aria-label="WhatsApp"><Icon name="messages" :size="18" /></a>
            <button v-if="mainAction(o)" type="button" class="h-10 flex-1 rounded-xl bg-emerald-700 text-sm font-semibold text-white" :disabled="busy" @click="mainAction(o).run()">{{ mainAction(o).label }}</button>
            <RouterLink v-else :to="`/gestion/commandes/${o.id}`" class="grid h-10 flex-1 place-items-center rounded-xl border border-slate-200 text-sm font-medium text-slate-700">Voir la commande</RouterLink>
          </div>
        </div>
      </template>
    </div>

    <div class="hidden lg:block"><Pagination :meta="meta" @page="(p) => { filters.page = p; load() }" /></div>
    <div v-if="meta && meta.current_page < meta.last_page" class="lg:hidden">
      <button type="button" class="btn-secondary w-full py-3" :disabled="loading" @click="filters.page = meta.current_page + 1; load(true)">
        {{ loading ? 'Chargement…' : `Voir plus · ${meta.total - orders.length} autre${meta.total - orders.length > 1 ? 's' : ''}` }}
      </button>
    </div>
    <p v-else-if="stacked" class="text-center text-sm text-slate-500 lg:hidden">Toutes les commandes sont affichées ({{ meta.total }}).</p>

    <!-- Filtres et tri -->
    <Modal v-if="filtersOpen" title="Filtres et tri" @close="filtersOpen = false">
      <div class="space-y-5">
        <div>
          <p class="label-lg">Les dates portent sur</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="d in [['scheduled', 'La livraison'], ['created', 'La commande']]" :key="d[0]" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="filters.date_field === d[0] ? 'border-2 border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-slate-300'" @click="filters.date_field = d[0]">{{ d[1] }}</button>
          </div>
        </div>
        <div v-if="options">
          <p class="label-lg">Qui livre</p>
          <select v-model="filters.deliverer" class="input-lg">
            <option value="">Tous</option>
            <option value="none">Pas encore choisi</option>
            <optgroup v-if="options.couriers.length" label="Mes livreurs"><option v-for="c in options.couriers" :key="c.id" :value="`courier:${c.id}`">{{ c.name }}</option></optgroup>
            <optgroup v-if="options.carriers.length" label="Entreprises de livraison"><option v-for="c in options.carriers" :key="c.id" :value="`carrier:${c.id}`">{{ c.name }}</option></optgroup>
          </select>
        </div>
        <div>
          <p class="label-lg">Commune</p>
          <select v-model="filters.commune_id" class="input-lg">
            <option value="">Toutes</option>
            <option v-for="c in communes" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
          </select>
        </div>
        <div>
          <p class="label-lg">Paiement</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="p in [['', 'Tous'], ['to_collect', 'À encaisser'], ['paid', 'Payées']]" :key="p[0]" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="filters.payment === p[0] ? 'border-2 border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-slate-300'" @click="filters.payment = p[0]">{{ p[1] }}</button>
          </div>
        </div>
        <div>
          <p class="label-lg">Canal de vente</p>
          <select v-model="filters.source" class="input-lg">
            <option value="">Tous les canaux</option>
            <option value="online_store">Site</option>
            <option value="live">Live</option>
            <option v-for="s in SOURCES" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <p class="label-lg">Trier par</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in SORTS" :key="s.value" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="filters.sort === s.value ? 'border-2 border-emerald-600 bg-emerald-50 font-semibold text-emerald-800' : 'border-slate-300'" @click="filters.sort = s.value">{{ s.label }}</button>
          </div>
        </div>
        <div class="flex gap-2 sm:hidden">
          <button type="button" class="btn-secondary flex-1" @click="exportList">Exporter (Excel)</button>
          <RouterLink v-if="canManage" to="/gestion/commandes/retours" class="btn-secondary flex-1">Retours</RouterLink>
        </div>
        <div class="flex gap-2">
          <button type="button" class="btn-ghost" @click="clearFilters">Tout effacer</button>
          <button type="button" class="btn-brand flex-1 py-3" @click="filtersOpen = false">Voir les {{ summary?.count ?? '' }} commandes</button>
        </div>
      </div>
    </Modal>

    <DelivererPicker v-if="assignIds" v-model="assignChoice" :options="options" @close="assignIds = null" />

    <Modal v-if="statusOpen" :title="`Changer le statut de ${selected.size} commande(s)`" @close="statusOpen = false">
      <div class="space-y-4">
        <div class="grid gap-2">
          <label v-for="s in [['confirmed', 'Confirmée'], ['processing', 'En préparation'], ['cancelled', 'Annulée']]" :key="s[0]" class="flex items-center gap-3 rounded-xl border p-3" :class="statusForm.status === s[0] ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
            <input v-model="statusForm.status" type="radio" :value="s[0]" class="h-4 w-4 accent-emerald-700" />{{ s[1] }}
          </label>
        </div>
        <select v-if="statusForm.status === 'cancelled'" v-model="statusForm.reason" class="input" aria-label="Motif d'annulation">
          <option v-for="r in CANCEL_REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
        <p class="text-sm text-slate-500">Les commandes qui ne peuvent pas passer à ce statut sont laissées telles quelles et listées après.</p>
        <button type="button" class="btn-brand w-full" :class="{ 'btn-danger': statusForm.status === 'cancelled' }" @click="applyStatus">Appliquer</button>
      </div>
    </Modal>

    <Modal v-if="completing" :title="`${completing.reference} livrée`" @close="completing = null">
      <form class="space-y-4" @submit.prevent="complete">
        <label v-if="completing.amount_due > 0" class="flex items-center gap-2 text-sm">
          <input v-model="completeForm.collect" type="checkbox" class="h-4 w-4 accent-emerald-700" />
          {{ money(completing.amount_due) }} encaissés à la livraison
        </label>
        <select v-if="completeForm.collect && completing.amount_due > 0" v-model="completeForm.method" class="input" aria-label="Moyen de paiement">
          <option value="cash">Espèces</option>
          <option value="wave">Wave</option>
          <option value="orange_money">Orange Money</option>
          <option value="mtn_momo">MTN MoMo</option>
          <option value="moov_money">Moov Money</option>
        </select>
        <button class="btn-brand w-full" :disabled="busy">Marquer comme livrée</button>
      </form>
    </Modal>
  </div>
</template>
