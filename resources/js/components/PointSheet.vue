<script setup>
// « Faire le point » : qui doit de l'argent à la boutique (livreurs, entreprises de livraison) et encaissement de chacun,
// dans la même fenêtre. Toucher une ligne déroule ses commandes, cochées, avec le montant rempli.
// Même enregistrement que la Caisse : la Caisse voit l'encaissement tout de suite.
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Alert from './Alert.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { formatDate, money } from '../lib/format'
import { avatar } from '../lib/orders'
import { loadPoint } from '../lib/point'
import { useAuthStore } from '../stores/auth'

const emit = defineEmits(['close', 'changed'])
const auth = useAuthStore()

const point = ref(null)
const error = ref('')
const notice = ref('')
async function load() {
  try {
    point.value = await loadPoint(auth)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

// Aujourd'hui : l'heure seule ; sinon le jour
function when(iso) {
  const d = new Date(iso)
  const time = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  if (d.toDateString() === new Date().toDateString()) return time
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
}
const since = (iso) => (new Date(iso).toDateString() === new Date().toDateString() ? `depuis ${when(iso)}` : `depuis le ${when(iso)}`)

const METHODS = [
  { value: 'cash', label: 'Espèces' },
  { value: 'wave', label: 'Wave' },
  { value: 'orange_money', label: 'Orange Money' },
  { value: 'mtn_momo', label: 'MTN MoMo' },
  { value: 'moov_money', label: 'Moov Money' },
]

// Ligne ouverte : { kind: 'courier' | 'carrier', id, lines, selected, amount, method, loading }
const open = reactive({ kind: null, id: null, lines: [], selected: [], amount: 0, method: 'cash', loading: false, saving: false })
const isOpen = (kind, id) => open.kind === kind && open.id === id
async function toggle(kind, id) {
  if (isOpen(kind, id)) {
    open.kind = null
    return
  }
  Object.assign(open, { kind, id, lines: [], selected: [], method: 'cash', loading: true })
  error.value = ''
  try {
    if (kind === 'courier') {
      open.lines = (await http.get(`/cash/couriers/${id}`)).data.data.map((l) => ({ key: l.id, order_id: l.order_id, reference: l.reference, name: l.customer, when: `encaissé ${new Date(l.collected_at).toDateString() === new Date().toDateString() ? 'à' : 'le'} ${when(l.collected_at)}`, amount: l.amount }))
    } else {
      open.lines = (await http.get(`/carrier-balances/${id}`)).data.data.map((l) => ({ key: l.shipment_id, order_id: l.order_id, reference: l.order_reference, name: '', when: `livrée le ${formatDate(l.delivered_at)}`, amount: l.net, collected: l.collected, fees: l.fees, overdue: l.overdue }))
    }
    open.selected = open.lines.map((l) => l.key)
    open.amount = expected.value
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    open.loading = false
  }
}
const expected = computed(() => open.lines.filter((l) => open.selected.includes(l.key)).reduce((s, l) => s + l.amount, 0))
// Une commande cochée ou décochée : le montant reçu suit
watch(expected, (v) => (open.amount = v))
const fees = computed(() => open.lines.filter((l) => open.selected.includes(l.key)).reduce((s, l) => s + (l.fees ?? 0), 0))

async function receive(name) {
  open.saving = true
  error.value = ''
  try {
    let difference = 0
    if (open.kind === 'courier') {
      difference = (await http.post(`/cash/couriers/${open.id}/remittances`, { received_amount: open.amount, method: open.method, collection_ids: open.selected })).data.data.difference
    } else {
      difference = (await http.post(`/carriers/${open.id}/payouts`, { shipment_ids: open.selected, amount: open.amount, method: open.method })).data.data.difference
    }
    notice.value = `${money(open.amount)} reçus de ${name}.${difference ? ` Écart de ${money(difference)} noté dans la Caisse.` : ''}`
    open.kind = null
    await load()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    open.saving = false
  }
}

const settledNames = computed(() => (point.value?.settled ?? []).map((c) => c.name).join(', '))
const couriersTotal = computed(() => (point.value?.couriers ?? []).reduce((s, c) => s + c.held, 0))
const companiesTotal = computed(() => (point.value?.companies ?? []).reduce((s, c) => s + Math.max(c.amount, 0), 0))
</script>

<template>
  <Modal title="Faire le point" @close="emit('close')">
    <div class="space-y-4">
      <div class="rounded-2xl bg-emerald-900 px-4 py-3.5 text-white">
        <p class="text-sm font-semibold text-emerald-200">On vous doit</p>
        <p class="font-display text-3xl font-extrabold tracking-tight">{{ point ? money(point.total) : '…' }}</p>
        <p v-if="point && point.total" class="text-sm text-emerald-100">
          <template v-if="couriersTotal">Livreurs {{ money(couriersTotal) }}</template><template v-if="couriersTotal && companiesTotal"> · </template><template v-if="companiesTotal">Entreprises {{ money(companiesTotal) }}</template>
        </p>
      </div>

      <Alert v-if="error">{{ error }}</Alert>
      <Alert v-if="notice" tone="success">{{ notice }}</Alert>

      <RouterLink v-if="point?.declarations.length" to="/gestion/caisse/livreurs" class="block rounded-xl bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-900" @click="emit('close')">
        {{ point.declarations.length }} versement{{ point.declarations.length > 1 ? 's' : '' }} déclaré{{ point.declarations.length > 1 ? 's' : '' }} par vos livreurs, à confirmer dans la Caisse ›
      </RouterLink>

      <p v-if="point && !point.couriers.length && !point.companies.length" class="rounded-xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-600">Personne ne vous doit d'argent pour le moment.</p>

      <!-- Livreurs : espèces en main -->
      <section v-if="point?.couriers.length" class="space-y-2">
        <h3 class="px-1 text-xs font-bold uppercase tracking-wide text-slate-500">Vos livreurs · espèces en main</h3>
        <article v-for="c in point.couriers" :key="c.courier_id" class="overflow-hidden rounded-2xl border bg-white" :class="isOpen('courier', c.courier_id) ? 'border-2 border-emerald-700' : c.over_limit ? 'border-red-300' : 'border-slate-200'">
          <button type="button" class="flex w-full items-center gap-3 px-3.5 py-3 text-left" :aria-expanded="isOpen('courier', c.courier_id)" @click="toggle('courier', c.courier_id)">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-sm font-extrabold" :class="c.over_limit ? 'bg-red-100 text-red-800' : avatar(c.name).tone">{{ avatar(c.name).initials }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-bold">{{ c.name }}</span>
              <span class="block text-sm" :class="c.over_limit ? 'text-red-700' : 'text-slate-500'">{{ c.collections_count }} livraison{{ c.collections_count > 1 ? 's' : '' }}<template v-if="c.oldest_at"> · {{ since(c.oldest_at) }}</template><template v-if="c.over_limit"> · plafond dépassé</template></span>
            </span>
            <span class="shrink-0 text-base font-extrabold">{{ money(c.held) }}</span>
            <span class="shrink-0 text-xs text-slate-400">{{ isOpen('courier', c.courier_id) ? '▲' : '▼' }}</span>
          </button>
          <div v-if="isOpen('courier', c.courier_id)" class="border-t border-slate-100 bg-slate-50/60 px-3.5 pb-3.5">
            <p v-if="open.loading" class="py-3 text-sm text-slate-500">Chargement…</p>
            <template v-else>
              <ul class="divide-y divide-dashed divide-slate-200">
                <li v-for="l in open.lines" :key="l.key">
                  <label class="flex items-center gap-3 py-2 text-sm">
                    <input v-model="open.selected" type="checkbox" :value="l.key" class="h-5 w-5 shrink-0 accent-emerald-700" />
                    <span class="min-w-0 flex-1"><span class="font-semibold">{{ l.name || 'Client' }}</span> · {{ l.reference }}<span class="block text-xs text-slate-500">{{ l.when }}</span></span>
                    <span class="shrink-0 font-bold">{{ money(l.amount) }}</span>
                  </label>
                </li>
              </ul>
              <p class="flex justify-between border-t border-slate-200 pt-2 text-sm font-extrabold"><span>Il doit vous remettre</span><span>{{ money(expected) }}</span></p>
              <div class="mt-3 flex flex-wrap gap-1.5">
                <button v-for="m in METHODS" :key="m.value" type="button" class="rounded-xl border px-3 py-1.5 text-sm font-semibold" :class="open.method === m.value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-700'" @click="open.method = m.value">{{ m.label }}</button>
              </div>
              <label class="mt-3 flex items-center gap-3 rounded-xl border border-field bg-white px-3 py-2">
                <span class="text-sm text-slate-500">Reçu</span>
                <input v-model.number="open.amount" type="number" min="0" inputmode="numeric" class="min-w-0 flex-1 bg-transparent text-right text-lg font-extrabold outline-none" aria-label="Montant reçu" />
                <span class="text-sm font-bold">F</span>
              </label>
              <button type="button" class="btn-brand mt-3 w-full py-3" :disabled="open.saving || !open.selected.length" @click="receive(c.name)">Encaisser {{ money(open.amount) }} de {{ c.name }}</button>
            </template>
          </div>
        </article>
        <p v-if="settledNames" class="px-1 text-sm text-emerald-800">✓ Rien à verser : {{ settledNames }}</p>
      </section>

      <!-- Entreprises de livraison : reversement -->
      <section v-if="point?.companies.length" class="space-y-2">
        <h3 class="px-1 text-xs font-bold uppercase tracking-wide text-slate-500">Entreprises de livraison · reversement</h3>
        <article v-for="c in point.companies" :key="c.connection_id" class="overflow-hidden rounded-2xl border bg-white" :class="isOpen('carrier', c.connection_id) ? 'border-2 border-emerald-700' : c.overdue_count ? 'border-red-300' : 'border-slate-200'">
          <button type="button" class="flex w-full items-center gap-3 px-3.5 py-3 text-left" :aria-expanded="isOpen('carrier', c.connection_id)" @click="toggle('carrier', c.connection_id)">
            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-sky-100 text-sm font-extrabold text-sky-800">{{ avatar(c.carrier).initials }}</span>
            <span class="min-w-0 flex-1">
              <span class="block truncate font-bold">{{ c.carrier }}</span>
              <span class="block text-sm" :class="c.overdue_count ? 'text-red-700' : 'text-slate-500'">{{ c.count }} livraison{{ c.count > 1 ? 's' : '' }}<template v-if="c.overdue_count"> · {{ c.overdue_count }} en retard</template><template v-else-if="c.oldest_delivered_at"> · depuis le {{ formatDate(c.oldest_delivered_at) }}</template></span>
            </span>
            <span class="shrink-0 text-base font-extrabold" :class="c.amount < 0 ? 'text-red-700' : ''">{{ money(c.amount) }}</span>
            <span class="shrink-0 text-xs text-slate-400">{{ isOpen('carrier', c.connection_id) ? '▲' : '▼' }}</span>
          </button>
          <div v-if="isOpen('carrier', c.connection_id)" class="border-t border-slate-100 bg-slate-50/60 px-3.5 pb-3.5">
            <p v-if="open.loading" class="py-3 text-sm text-slate-500">Chargement…</p>
            <template v-else>
              <ul class="divide-y divide-dashed divide-slate-200">
                <li v-for="l in open.lines" :key="l.key">
                  <label class="flex items-center gap-3 py-2 text-sm">
                    <input v-if="!c.is_api" v-model="open.selected" type="checkbox" :value="l.key" class="h-5 w-5 shrink-0 accent-emerald-700" />
                    <span class="min-w-0 flex-1"><span class="font-semibold">{{ l.reference }}</span><span class="block text-xs" :class="l.overdue ? 'text-red-700' : 'text-slate-500'">{{ l.when }} · encaissé {{ money(l.collected) }} − frais {{ money(l.fees) }}</span></span>
                    <span class="shrink-0 font-bold">{{ money(l.amount) }}</span>
                  </label>
                </li>
              </ul>
              <template v-if="!c.is_api">
                <p class="flex justify-between border-t border-slate-200 pt-2 text-sm font-extrabold"><span>À vous reverser<span v-if="fees" class="font-normal text-slate-500"> (frais déduits)</span></span><span>{{ money(expected) }}</span></p>
                <div class="mt-3 flex flex-wrap gap-1.5">
                  <button v-for="m in [...METHODS, { value: 'bank', label: 'Virement' }]" :key="m.value" type="button" class="rounded-xl border px-3 py-1.5 text-sm font-semibold" :class="open.method === m.value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white text-slate-700'" @click="open.method = m.value">{{ m.label }}</button>
                </div>
                <label class="mt-3 flex items-center gap-3 rounded-xl border border-field bg-white px-3 py-2">
                  <span class="text-sm text-slate-500">Reçu</span>
                  <input v-model.number="open.amount" type="number" min="0" inputmode="numeric" class="min-w-0 flex-1 bg-transparent text-right text-lg font-extrabold outline-none" aria-label="Montant reçu" />
                  <span class="text-sm font-bold">F</span>
                </label>
                <button type="button" class="btn-brand mt-3 w-full py-3" :disabled="open.saving || !open.selected.length" @click="receive(c.carrier)">Marquer reçu · {{ money(open.amount) }}</button>
              </template>
              <p v-else class="mt-2 rounded-xl bg-sky-50 px-3 py-2 text-sm text-sky-900">Les reversements de {{ c.carrier }} arrivent automatiquement : ils s'enregistrent tout seuls dès qu'elle paie.</p>
            </template>
          </div>
        </article>
      </section>

      <p class="text-center text-xs text-slate-500">Chaque encaissement est enregistré dans la Caisse.</p>
    </div>
  </Modal>
</template>
