<script setup>
// Carte d'une commande sur la page Commandes de l'accueil : le contact bien visible, Appeler, WhatsApp,
// et le livreur choisi, qu'on peut changer (mêmes règles que la liste des commandes).
import { computed, ref } from 'vue'
import DelivererPicker from './DelivererPicker.vue'
import RedeliverSheet from './RedeliverSheet.vue'
import Icon from './Icon.vue'
import StateBadge from './StateBadge.vue'
import http, { errorMessage } from '../lib/http'
import { money, phoneDisplay } from '../lib/format'
import { avatar } from '../lib/orders'
import { useAuthStore } from '../stores/auth'

const props = defineProps({ order: { type: Object, required: true }, options: { type: Object, default: null } })
const emit = defineEmits(['changed', 'message'])
const auth = useAuthStore()

const o = computed(() => props.order)
const phone = computed(() => phoneDisplay(o.value.customer_phone) || o.value.customer_phone_display || '')
const closed = computed(() => ['completed', 'cancelled', 'returned'].includes(o.value.status))
const canAssign = computed(() => auth.can('shipments.manage'))
// Pas encore de livreur : on peut en choisir un (commande à livrer, pas encore partie, rien à payer avant)
const assignable = computed(() => o.value.delivery_method === 'delivery' && ['pending_confirmation', 'confirmed', 'processing'].includes(o.value.status)
  && !o.value.shipment_id && !(o.value.prepay_due > 0 && o.value.status !== 'pending_confirmation'))
const canChange = computed(() => canAssign.value && !closed.value && (o.value.deliverer ? o.value.deliverer_editable : assignable.value))

// ── Livraison ratée, reportée ou colis qui revient ──
const f = computed(() => o.value.failure)
const hhmm = (iso) => (iso ? new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : '')
function dayWords(date) {
  if (!date) return ''
  const d = new Date(`${date}T12:00:00`)
  const diff = Math.round((d - new Date(`${new Date().toLocaleDateString('sv-SE')}T12:00:00`)) / 86400000)
  return diff === 0 ? 'à aujourd\'hui' : diff === 1 ? 'à demain' : `au ${d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}`
}
const box = computed(() => {
  const x = f.value
  if (!x) return null
  const who = o.value.deliverer || 'Le livreur'
  if (x.status === 'returning') return { tone: 'bg-orange-50 text-orange-900', title: `↩ Colis à rapporter · chez ${who}`, line: x.at ? `Demandé à ${hhmm(x.at)}` : '' }
  if (x.status === 'rescheduled' && !x.needs_decision) return { tone: 'bg-sky-50 text-sky-900', title: `📅 Reportée ${dayWords(x.rescheduled_to)}`, line: [x.reason ? `« ${x.reason} »` : '', 'rien à décider'].filter(Boolean).join(' · ') }
  if (!x.own_courier && x.needs_decision) return { tone: 'bg-orange-50 text-orange-900', title: `⏳ ${who} attend votre décision`, line: [x.reason ? `« ${x.reason} »` : '', hhmm(x.at)].filter(Boolean).join(' · ') }
  return { tone: 'bg-red-50 text-red-900', title: `⚠ ${x.reason || 'Échec de livraison'}${x.at ? ` · ${hhmm(x.at)}` : ''}`, line: [who, x.attempts ? `${x.attempts === 1 ? '1re' : `${x.attempts}e`} tentative` : '', 'colis encore chez lui'].filter(Boolean).join(' · ') }
})
const canManage = computed(() => auth.can('orders.manage') || auth.can('shipments.manage'))
const sheet = ref(null) // 'redeliver' | 'date'
async function bringBack() {
  if (!window.confirm(`Faire revenir le colis de ${o.value.customer_name || o.value.reference} à la boutique ?`)) return
  busy.value = true
  try {
    await http.post(`/shipments/${o.value.shipment_id}/decision`, { decision: 'return' })
    emit('message', { text: `${o.value.reference} : ${o.value.deliverer || 'le livreur'} doit rapporter le colis.` })
    emit('changed')
  } catch (e) {
    emit('message', { error: true, text: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
async function received() {
  busy.value = true
  try {
    await http.post(`/shipments/${o.value.shipment_id}/status`, { status: 'returned', note: 'Colis reçu à la boutique' })
    emit('message', { text: `${o.value.reference} : colis rentré, articles remis en stock.` })
    emit('changed')
  } catch (e) {
    emit('message', { error: true, text: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
// La liste se recharge une fois la fenêtre fermée (la carte peut quitter la liste du jour)
const reloadOnClose = ref(false)
function sheetClose() {
  sheet.value = null
  if (reloadOnClose.value) emit('changed')
  reloadOnClose.value = false
}
function sheetDone(text) {
  sheet.value = null
  if (text) emit('message', { text })
  emit('changed')
}

const picking = ref(false)
const busy = ref(false)
const nameOf = (key) => {
  const [type, id] = key.split(':')
  return (type === 'courier' ? props.options?.couriers : props.options?.carriers)?.find((d) => String(d.id) === id)?.name ?? 'le livreur'
}
async function choose(key) {
  picking.value = false
  if (!key || key === o.value.deliverer_key) return
  const [type, id] = key.split(':')
  if (!['courier', 'carrier'].includes(type)) {
    emit('message', { error: true, text: 'Connectez d\'abord cette entreprise de livraison (Livraisons › Entreprises), puis choisissez-la ici.' })
    return
  }
  busy.value = true
  try {
    if (o.value.shipment_id) {
      // Course déjà chez un de mes livreurs : elle passe à un autre livreur
      if (type !== 'courier') {
        emit('message', { error: true, text: `Pour confier ${o.value.reference} à une entreprise de livraison, ouvrez la commande et annulez d'abord la course de ${o.value.deliverer}.` })
        return
      }
      await http.post(`/shipments/${o.value.shipment_id}/assign`, { courier_id: Number(id) })
    } else {
      const { data } = await http.post('/orders/bulk', { ids: [o.value.id], action: 'assign', ...(type === 'courier' ? { courier_id: Number(id) } : { carrier_connection_id: Number(id) }) })
      if (!data.data.done) {
        emit('message', { error: true, text: data.data.skipped?.[0]?.reason ?? 'Livreur non changé.' })
        return
      }
    }
    emit('message', { text: `${o.value.reference} confiée à ${nameOf(key)}.` })
    emit('changed')
  } catch (e) {
    emit('message', { error: true, text: errorMessage(e) })
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <article class="rounded-2xl bg-white p-3.5 shadow-soft" :class="o.delivery_failed ? 'ring-1 ring-red-200' : ''">
    <RouterLink :to="`/gestion/commandes/${o.id}`" class="flex items-start gap-3">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-sm font-black" :class="o.delivery_failed ? 'bg-red-100 text-red-700' : avatar(o.customer_name || phone).tone">{{ avatar(o.customer_name || phone).initials }}</span>
      <span class="min-w-0 flex-1">
        <span class="flex items-baseline justify-between gap-2">
          <b class="truncate text-base">{{ o.customer_name || 'Client' }}</b>
          <b class="shrink-0 text-base">{{ money(o.total) }}</b>
        </span>
        <span class="flex items-baseline justify-between gap-2">
          <span class="truncate text-base font-bold text-slate-800">{{ phone || 'Pas de numéro' }}</span>
          <span class="shrink-0 text-xs font-semibold text-slate-500">{{ o.reference }}</span>
        </span>
        <span class="mt-0.5 block truncate text-sm text-slate-500">{{ o.delivery_method === 'pickup' ? 'Retrait' : o.expedition_city ? `📦 Expédition · ${o.expedition_city}` : o.shipping_place_label || 'Quartier à préciser' }} · {{ o.items_label }}</span>
      </span>
    </RouterLink>

    <div class="mt-2 flex flex-wrap items-center gap-1.5 pl-14">
      <StateBadge :status="o.status" :label="o.status_label" />
      <StateBadge v-if="o.shipment_status_label && !closed" kind="shipment" :status="o.shipment_status" :label="o.shipment_status_label" />
      <span v-if="o.amount_due > 0 && !['cancelled', 'returned'].includes(o.status)" class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-800">À encaisser {{ money(o.amount_due) }}</span>
    </div>

    <!-- Livraison ratée, reportée ou colis qui revient : ce qui s'est passé et quoi faire -->
    <template v-if="box">
      <div class="mt-3 rounded-xl px-3 py-2 text-sm" :class="box.tone"><b class="block">{{ box.title }}</b><span v-if="box.line" class="opacity-90">{{ box.line }}</span></div>
      <div v-if="canManage" class="mt-2 grid grid-cols-2 gap-2">
        <template v-if="f.needs_decision">
          <button type="button" class="flex h-11 items-center justify-center gap-1.5 rounded-xl bg-emerald-700 text-sm font-bold text-white" :disabled="busy" @click="sheet = 'redeliver'">↻ Relivrer</button>
          <button type="button" class="flex h-11 items-center justify-center gap-1.5 rounded-xl border border-orange-300 bg-white text-sm font-bold text-orange-800" :disabled="busy" @click="bringBack">↩ Faire revenir</button>
        </template>
        <button v-else-if="f.status === 'rescheduled' && f.own_courier" type="button" class="col-span-2 flex h-11 items-center justify-center rounded-xl border border-slate-200 text-sm font-bold text-slate-700" :disabled="busy" @click="sheet = 'date'">Changer la date</button>
        <button v-else-if="f.status === 'returning' && (f.own_courier || f.manual)" type="button" class="col-span-2 flex h-11 items-center justify-center gap-1.5 rounded-xl bg-emerald-700 text-sm font-bold text-white" :disabled="busy" @click="received">📦 Colis reçu à la boutique</button>
        <p v-else-if="f.status === 'returning'" class="col-span-2 text-center text-xs text-slate-500">L'entreprise rapporte le colis : il rentrera tout seul.</p>
      </div>
    </template>

    <!-- Livreur -->
    <div v-if="o.delivery_method === 'delivery' && !(f && f.status === 'returning')" class="mt-3 flex min-h-11 items-center gap-2 rounded-xl border pl-3 pr-1" :class="o.deliverer ? 'border-emerald-200 bg-emerald-50' : 'border-dashed border-slate-300 bg-slate-50'">
      <Icon name="truck" :size="17" class="shrink-0" :class="o.deliverer ? 'text-emerald-700' : 'text-slate-400'" />
      <span class="min-w-0 flex-1 truncate text-sm font-bold" :class="o.deliverer ? 'text-emerald-900' : 'text-slate-500'">{{ o.deliverer || (o.prepay_due > 0 && !o.shipment_id ? `⏳ ${o.prepay_kind === 'expedition' ? 'Paiement avant l\'envoi' : 'Acompte attendu'} · ${money(o.prepay_due)}` : 'Pas encore de livreur') }}<span v-if="o.deliverer_planned" class="font-normal text-emerald-700"> · prévu</span></span>
      <button v-if="canChange" type="button" class="h-9 shrink-0 rounded-lg bg-white px-3 text-sm font-bold text-emerald-800 shadow-sm" :disabled="busy || !options" @click="picking = true">{{ o.deliverer ? 'Changer' : 'Choisir' }}</button>
    </div>

    <!-- Contact -->
    <div class="mt-2 grid grid-cols-2 gap-2">
      <a v-if="o.customer_phone" :href="`tel:${o.customer_phone}`" class="flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 text-sm font-bold text-slate-700"><Icon name="phone" :size="17" />Appeler</a>
      <a v-if="o.whatsapp_url" :href="o.whatsapp_url" target="_blank" rel="noopener" class="flex h-11 items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 text-sm font-bold text-emerald-800"><Icon name="whatsapp" :size="17" />WhatsApp</a>
    </div>
    <RedeliverSheet v-if="sheet" :order="o" :options="options" :mode="sheet" @close="sheetClose" @done="sheetDone" @changed="reloadOnClose = true" />

    <DelivererPicker v-if="picking" :model-value="o.deliverer_key ?? ''" :options="options" :place-label="o.shipping_place_label ?? ''" @update:model-value="choose" @close="picking = false" />
  </article>
</template>
