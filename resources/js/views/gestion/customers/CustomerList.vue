<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ChipsInput from '../../../components/ChipsInput.vue'
import Field from '../../../components/Field.vue'
import Icon from '../../../components/Icon.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDate, money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

/**
 * Fichier clients : chiffres cliquables, recherche, filtres en pastilles (dont les étiquettes), clients à relancer
 * avec le message WhatsApp prêt, liste qui se charge en défilant, ajout d'un client. Utilisé dans l'onglet « Clients »
 * de l'accueil du téléphone et sur la page Clients.
 */
const props = defineProps({ filter: { type: String, default: '' } })
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const canEdit = computed(() => auth.can('orders.manage'))

// Filtre ouvert depuis l'accueil (« Clientes fidèles à relancer » → ?filtre=to_relaunch)
const FILTERS = ['blacklisted', 'risky', 'loyal', 'to_relaunch']
const filters = reactive({ search: '', filter: FILTERS.includes(route.query.filtre) ? route.query.filtre : FILTERS.includes(props.filter) ? props.filter : '', tag: '', sort: 'recent' })
const customers = ref([])
const meta = ref(null)
const loading = ref(false)
const summary = ref(null)
const relaunchList = ref([])
const error = ref('')

const CHIPS = [
  ['', 'Tous'],
  ['loyal', '⭐ Fidèles'],
  ['to_relaunch', '⏰ À relancer'],
  ['risky', '⚠ Avec refus'],
  ['blacklisted', '⛔ Liste noire'],
]
const SORTS = [['recent', 'Dernière commande'], ['spent', 'Montant dépensé'], ['orders', 'Nombre de commandes']]

function params(page) {
  return Object.fromEntries(Object.entries({ ...filters, page, per_page: 20 }).filter(([, v]) => v !== ''))
}
// Première page : remplace la liste ; pages suivantes : s'ajoutent (défilement continu)
async function load(page = 1) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get('/customers', { params: params(page) })
    customers.value = page === 1 ? data.data : [...customers.value, ...data.data]
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
async function loadSummary() {
  try {
    summary.value = (await http.get('/customers/summary')).data.data
    if (summary.value.to_relaunch) {
      relaunchList.value = (await http.get('/customers', { params: { filter: 'to_relaunch', sort: 'spent', per_page: 3 } })).data.data
    } else {
      relaunchList.value = []
    }
  } catch {
    // chiffres seulement : la liste reste utilisable
  }
}
const hasMore = computed(() => meta.value && meta.value.current_page < meta.value.last_page)
const showRelaunch = computed(() => !filters.search && !filters.filter && !filters.tag && relaunchList.value.length > 0)

onMounted(() => { load(); loadSummary() })
let timer
watch(() => filters.search, () => {
  clearTimeout(timer)
  timer = setTimeout(() => load(1), 300)
})
watch(() => [filters.filter, filters.tag, filters.sort], () => load(1))
// Filtre demandé de l'extérieur (lien « à relancer »)
watch(() => props.filter, (value) => { filters.filter = FILTERS.includes(value) ? value : '' })
onBeforeUnmount(() => { clearTimeout(timer); observer?.disconnect() })

// Défilement continu : la fin de liste déclenche la page suivante
const sentinel = ref(null)
let observer
onMounted(async () => {
  await nextTick()
  if (!('IntersectionObserver' in window)) return
  observer = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting) && hasMore.value && !loading.value) load(meta.value.current_page + 1)
  }, { rootMargin: '200px' })
  watch(sentinel, (el, old) => { if (old) observer.unobserve(old); if (el) observer.observe(el) }, { immediate: true })
})

function pick(key) {
  filters.filter = filters.filter === key ? '' : key
}
function pickTag(name) {
  filters.tag = filters.tag === name ? '' : name
}
const stats = computed(() => !summary.value ? [] : [
  { label: 'Clients', value: summary.value.total, filter: '' },
  { label: 'Nouveaux ce mois', value: summary.value.new_month, filter: null },
  { label: 'Fidèles', value: summary.value.loyal, filter: 'loyal' },
  { label: 'À relancer', value: summary.value.to_relaunch, filter: 'to_relaunch', tone: summary.value.to_relaunch ? 'text-amber-700' : '' },
])

const initial = (c) => (c.name || '#').trim().charAt(0).toUpperCase()

// Relancer : message du marchand préparé par le serveur, ouvert dans WhatsApp
const relaunching = ref(null)
async function relaunch(c) {
  relaunching.value = c.id
  // Fenêtre ouverte tout de suite (sinon le navigateur bloque l'ouverture après l'attente)
  const w = window.open('', '_blank')
  try {
    const url = (await http.get(`/customers/${c.id}/relaunch`)).data.data.whatsapp_url
    if (w) w.location.href = url
    else window.location.assign(url)
  } catch (e) {
    w?.close()
    error.value = errorMessage(e)
  } finally {
    relaunching.value = null
  }
}

// Ajout d'un client
const adding = ref(false)
const form = reactive({ name: '', phone: '', tags: [] })
const formError = ref('')
const saving = ref(false)
function openAdd() {
  Object.assign(form, { name: '', phone: '', tags: [] })
  formError.value = ''
  adding.value = true
}
async function create() {
  saving.value = true
  formError.value = ''
  try {
    const { data } = await http.post('/customers', { phone: form.phone, name: form.name || null, tags: form.tags })
    adding.value = false
    router.push(`/gestion/clients/${data.data.id}`)
  } catch (e) {
    formError.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <!-- Chiffres : un toucher applique le filtre -->
    <div v-if="summary" class="grid grid-cols-4 gap-2">
      <component :is="s.filter === null ? 'div' : 'button'" v-for="s in stats" :key="s.label" type="button"
                 class="rounded-2xl bg-white p-2.5 text-center shadow-soft" :class="s.filter !== null && filters.filter === s.filter && s.filter !== '' ? 'ring-2 ring-emerald-600' : ''"
                 @click="s.filter !== null && (filters.filter = s.filter)">
        <b class="block text-[20px] font-black leading-tight" :class="s.tone">{{ s.value }}</b>
        <span class="block text-[11px] font-semibold leading-tight text-slate-500">{{ s.label }}</span>
      </component>
    </div>

    <div class="flex gap-2">
      <input v-model="filters.search" type="search" class="input" placeholder="🔍 Nom ou téléphone" />
      <button v-if="canEdit" type="button" class="btn-brand shrink-0 px-4 font-black" @click="openAdd">＋ Client</button>
    </div>

    <!-- Filtres et étiquettes en pastilles -->
    <div class="-mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0" role="group" aria-label="Filtres">
      <button v-for="[key, label] in CHIPS" :key="key" type="button" class="shrink-0 rounded-full px-3 py-1.5 text-[13px] font-extrabold"
              :class="filters.filter === key ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 shadow-soft'" @click="pick(key)">{{ label }}</button>
      <button v-for="t in summary?.tags ?? []" :key="t" type="button" class="shrink-0 rounded-full px-3 py-1.5 text-[13px] font-extrabold"
              :class="filters.tag === t ? 'bg-emerald-700 text-white' : 'bg-emerald-50 text-emerald-900'" @click="pickTag(t)"># {{ t }}</button>
    </div>

    <Alert v-if="error">{{ error }}</Alert>

    <!-- À relancer : les meilleurs clients qui n'ont pas commandé depuis 30 jours -->
    <div v-if="showRelaunch" class="rounded-2xl border border-orange-200 bg-orange-50 px-3 py-2">
      <p class="text-[11px] font-black uppercase tracking-wide text-orange-800">⏰ À relancer · plus de commande depuis 30 jours</p>
      <div v-for="c in relaunchList" :key="c.id" class="flex items-center gap-2 border-t border-orange-100 py-2 first-of-type:border-0">
        <RouterLink :to="`/gestion/clients/${c.id}`" class="min-w-0 flex-1">
          <b class="block truncate text-[14px]">{{ c.name || c.phone_display }}</b>
          <span class="block truncate text-xs text-slate-600">{{ money(c.total_spent) }} · {{ c.delivered_count }} livrée(s)<template v-if="c.last_order_at"> · dernière le {{ formatDate(c.last_order_at) }}</template></span>
        </RouterLink>
        <button type="button" class="shrink-0 rounded-full bg-emerald-700 px-3 py-1.5 text-xs font-black text-white disabled:opacity-60" :disabled="relaunching === c.id" @click="relaunch(c)">Relancer ▸</button>
      </div>
      <button v-if="summary.to_relaunch > relaunchList.length" type="button" class="w-full border-t border-orange-100 pt-2 text-center text-xs font-black text-orange-800" @click="filters.filter = 'to_relaunch'">Voir les {{ summary.to_relaunch }} clients</button>
    </div>

    <div class="flex items-center justify-between px-1 text-xs text-slate-500">
      <span v-if="meta">{{ meta.total }} client{{ meta.total > 1 ? 's' : '' }}</span><span v-else />
      <label class="flex items-center gap-1">Trier :
        <select v-model="filters.sort" class="rounded-lg border-0 bg-transparent py-0 pl-1 pr-6 text-xs font-bold text-slate-700">
          <option v-for="[v, l] in SORTS" :key="v" :value="v">{{ l }}</option>
        </select>
      </label>
    </div>

    <div class="rounded-2xl bg-white p-1 shadow-soft">
      <div v-for="c in customers" :key="c.id" class="flex items-center gap-2 border-b border-slate-100 pr-2 last:border-0">
        <RouterLink :to="`/gestion/clients/${c.id}`" class="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full font-black" :class="c.is_blacklisted ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'">{{ initial(c) }}</span>
          <span class="min-w-0 flex-1">
            <b class="block truncate text-[14.5px]">{{ c.name || 'Sans nom' }}</b>
            <span class="flex flex-wrap gap-1 py-0.5 empty:hidden">
              <span v-if="c.is_blacklisted" class="rounded-full bg-red-100 px-2 text-[11px] font-extrabold text-red-700">Liste noire</span>
              <span v-else-if="c.is_risky" class="rounded-full bg-amber-100 px-2 text-[11px] font-extrabold text-amber-800">⚠ {{ c.refused_count }} refus</span>
              <span v-if="c.is_loyal" class="rounded-full bg-emerald-100 px-2 text-[11px] font-extrabold text-emerald-800">⭐ Fidèle</span>
              <span v-for="t in c.tags" :key="t" class="rounded-full bg-slate-100 px-2 text-[11px] font-bold text-slate-600">{{ t }}</span>
            </span>
            <span class="block truncate text-xs text-slate-500">{{ money(c.total_spent) }} · {{ c.orders_count }} cmd<template v-if="c.delivery_rate !== null"> · {{ c.delivery_rate }} % livrées</template><template v-if="c.last_order_at"> · {{ formatDate(c.last_order_at) }}</template></span>
          </span>
        </RouterLink>
        <a :href="`tel:${c.phone}`" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-700" :aria-label="`Appeler ${c.name || c.phone_display}`"><Icon name="phone" :size="16" /></a>
        <a :href="c.whatsapp_url" target="_blank" rel="noopener" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-emerald-50 text-sm font-black text-emerald-800" :aria-label="`WhatsApp ${c.name || c.phone_display}`">💬</a>
      </div>
      <p v-if="loading && !customers.length" class="p-6 text-center text-sm text-slate-500">Chargement…</p>
      <p v-else-if="!customers.length" class="p-6 text-center text-sm text-slate-500">Aucun client{{ filters.search || filters.filter || filters.tag ? ' pour ce filtre' : '' }}.</p>
    </div>
    <div ref="sentinel" class="h-1" aria-hidden="true" />
    <button v-if="hasMore" type="button" class="btn-secondary w-full justify-center" :disabled="loading" @click="load(meta.current_page + 1)">{{ loading ? 'Chargement…' : 'Voir plus' }}</button>

    <Modal v-if="adding" title="Nouveau client" @close="adding = false">
      <form class="space-y-3" @submit.prevent="create">
        <Alert v-if="formError">{{ formError }}</Alert>
        <Field id="n-phone" label="Téléphone"><input id="n-phone" v-model="form.phone" class="input" type="tel" inputmode="tel" required placeholder="07 07 07 07 07" /></Field>
        <Field id="n-name" label="Nom"><input id="n-name" v-model="form.name" class="input" /></Field>
        <Field id="n-tags" label="Étiquettes"><ChipsInput id="n-tags" v-model="form.tags" placeholder="VIP, revendeur…" /></Field>
        <button class="btn-brand w-full justify-center py-3 font-black" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Ajouter le client' }}</button>
      </form>
    </Modal>
  </div>
</template>
