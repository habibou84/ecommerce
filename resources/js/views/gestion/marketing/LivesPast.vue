<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import ColorIcon from '../../../components/ColorIcon.vue'
import Icon from '../../../components/Icon.vue'
import LiveTraining from '../../../components/LiveTraining.vue'
import MaterielTip from '../../../components/MaterielTip.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'

/**
 * Menu Live › « Lives passés » : les chiffres de tous les lives (lives, commandes, montant, commandes par live,
 * produit le plus commandé, meilleure heure), puis la liste ; « Refaire ce live » le remet en préparation.
 */
const router = useRouter()
const lives = ref(null)
const stats = ref(null)
// Paiements des lives (à part de l'abonnement) : 24 h, 30 jours, lives offerts
const billing = ref(null)
const pageUrl = ref('')
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  http.get('/lives/billing').then(({ data }) => (billing.value = data.data)).catch(() => null)
  try {
    const [l, s] = await Promise.all([http.get('/lives'), http.get('/lives/stats')])
    lives.value = l.data.data
    pageUrl.value = l.data.meta.page_url
    stats.value = s.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const past = computed(() => (lives.value ?? []).filter((l) => l.status === 'ended'))
const day = (iso) => (iso ? new Date(iso).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' }) : '')
const length = (m) => (m == null ? '' : m >= 60 ? `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')}` : `${m} min`)
const short = (n) => (n >= 1e6 ? `${(n / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} M` : n >= 1e4 ? `${Math.round(n / 1e3)} k` : money(n))

async function redo(live) {
  busy.value = true
  try {
    await http.post(`/lives/${live.id}/duplicate`)
    router.push('/gestion/live')
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="stats">
      <div class="grid grid-cols-3 gap-2">
        <div class="card p-3 text-center"><b class="block text-xl font-black">{{ stats.lives }}</b><span class="text-xs text-slate-500">lives</span></div>
        <div class="card p-3 text-center"><b class="block text-xl font-black">{{ stats.orders }}</b><span class="text-xs text-slate-500">commandes</span></div>
        <div class="card p-3 text-center"><b class="block text-xl font-black">{{ short(stats.amount) }}</b><span class="text-xs text-slate-500">vendus</span></div>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div class="card p-3 text-center"><b class="block text-xl font-black">{{ stats.orders_per_live }}</b><span class="text-xs text-slate-500">commandes / live</span></div>
        <div class="card min-w-0 p-3 text-center"><b class="block truncate text-base font-black" :title="stats.best_product?.title">{{ stats.best_product?.title ?? '—' }}</b><span class="text-xs text-slate-500">le plus commandé</span></div>
        <div class="card p-3 text-center"><b class="block text-xl font-black">{{ stats.best_hour != null ? `${stats.best_hour} h` : '—' }}</b><span class="text-xs text-slate-500">meilleure heure</span></div>
      </div>
    </template>

    <p v-if="lives && !past.length" class="card p-5 text-center text-slate-500">Pas encore de live terminé. <RouterLink to="/gestion/live" class="font-semibold text-emerald-700">Préparer un live ▸</RouterLink></p>

    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
      <div v-for="l in past" :key="l.id" class="card flex min-w-0 items-center gap-3 p-4">
        <ColorIcon name="live" :size="42" />
        <RouterLink :to="`/gestion/live/${l.id}`" class="min-w-0 flex-1">
          <b class="block first-letter:uppercase">{{ day(l.started_at ?? l.ended_at) }}</b>
          <span class="block truncate text-sm text-slate-500">{{ l.summary?.orders ?? 0 }} commande(s)<template v-if="l.minutes != null"> · {{ length(l.minutes) }}</template></span>
        </RouterLink>
        <span class="shrink-0 text-right">
          <b class="block">{{ money(l.summary?.amount ?? 0) }}</b>
          <button type="button" class="text-xs font-semibold text-emerald-700" :disabled="busy" @click="redo(l)">Refaire ce live</button>
        </span>
      </div>
    </div>

    <details class="card p-4">
      <summary class="flex cursor-pointer items-center gap-3">
        <span class="text-2xl" aria-hidden="true">🎓</span>
        <span class="min-w-0 flex-1"><b class="block">Se former au live</b><span class="text-sm text-slate-500">12 vidéos d'une minute : préparer, animer, après le live</span></span>
        <Icon name="chevron" :size="16" class="text-slate-400" />
      </summary>
      <div class="mt-3"><LiveTraining :url="pageUrl" @prepare="router.push('/gestion/live')" /></div>
    </details>

    <section v-if="billing?.paid" class="card p-4">
      <h3 class="font-bold">💳 Crédit et paiements des lives</h3>
      <p class="mt-1 text-sm font-bold" :class="billing.credit < 0 ? 'text-red-700' : 'text-emerald-700'">Crédit live : {{ money(billing.credit) }}</p>
      <p v-if="billing.month_until" class="mt-1 text-sm font-bold text-emerald-700">Lives illimités jusqu'au {{ new Date(billing.month_until).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' }) }}</p>
      <p class="mt-1 text-sm text-slate-500">Commandes en live offertes restantes : {{ billing.free_left }} sur {{ billing.free_orders }}</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="l in billing.billed_lives" :key="`l${l.id}`" class="flex items-center justify-between gap-2 py-2">
          <span class="min-w-0 truncate">{{ day(l.started_at) }} · {{ l.title }} · {{ l.orders }} cmd</span>
          <b class="shrink-0" :class="l.amount ? '' : 'text-emerald-700'">{{ l.amount ? `− ${money(l.amount)}` : 'Gratuit' }}</b>
        </li>
        <li v-for="p in billing.payments" :key="p.number" class="flex items-center justify-between gap-2 py-2">
          <span class="min-w-0 truncate">{{ day(p.paid_at || p.created_at) }} · {{ p.label }}</span>
          <b class="shrink-0">{{ money(p.amount) }}</b>
          <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-black" :class="p.status === 'pending' ? 'bg-amber-100 text-amber-900' : p.status === 'offered' ? 'bg-sky-100 text-sky-900' : 'bg-emerald-100 text-emerald-900'">{{ { paid: 'Payé', pending: 'En attente', offered: 'Offert' }[p.status] }}</span>
        </li>
      </ul>
    </section>

    <MaterielTip category="live" text="Pour des lives nets et bien éclairés :" />
  </div>
</template>
