<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import GroupTabs from './GroupTabs.vue'

const stats = ref(null)
const error = ref('')
const upgrade = ref(false)
const loading = ref(false)
const hovered = ref(null)
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const period = reactive({ preset: '30', from: '', to: iso(new Date()) })
const PRESETS = [['today', 'Aujourd\'hui'], ['7', '7 jours'], ['30', '30 jours'], ['month', 'Ce mois'], ['custom', 'Dates…']]

function range() {
  const today = new Date()
  if (period.preset === 'custom') return { from: period.from, to: period.to }
  if (period.preset === 'today') return { from: iso(today), to: iso(today) }
  if (period.preset === 'month') return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) }
  return { from: iso(new Date(today.getTime() - (Number(period.preset) - 1) * 86400000)), to: iso(today) }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    stats.value = (await http.get('/my-stores/stats', { params: range() })).data.data
  } catch (e) {
    upgrade.value = e.response?.data?.upgrade === true
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const t = computed(() => stats.value?.total)
const names = computed(() => Object.fromEntries((stats.value?.stores ?? []).map((s) => [s.id, s.name])))
const pct = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')} %`)
const day = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })

// Barres du montant livré par jour (une seule série : le détail par boutique est au survol et dans le tableau)
const chart = computed(() => {
  const days = stats.value?.daily ?? []
  const max = Math.max(1, ...days.map((d) => d.revenue))
  const width = 100 / Math.max(1, days.length)
  return days.map((d, i) => ({ ...d, x: i * width, w: width, h: (d.revenue / max) * 100 }))
})
const shown = computed(() => hovered.value ?? chart.value.at(-1))
</script>

<template>
  <div class="space-y-5">
    <GroupTabs />
    <div class="flex flex-wrap items-center gap-2">
      <button v-for="[value, label] in PRESETS" :key="value" class="rounded-full border px-3 py-1 text-sm"
              :class="period.preset === value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-white'"
              @click="period.preset = value; value !== 'custom' && load()">{{ label }}</button>
      <template v-if="period.preset === 'custom'">
        <input v-model="period.from" type="date" class="input w-auto py-1" aria-label="Du" />
        <input v-model="period.to" type="date" class="input w-auto py-1" aria-label="Au" />
        <button class="btn-brand px-3 py-1" @click="load">Voir</button>
      </template>
      <span v-if="loading" class="text-sm text-slate-500">Chargement…</span>
    </div>

    <Alert v-if="error" :tone="upgrade ? 'warning' : 'error'">{{ error }}</Alert>

    <template v-if="stats">
      <p class="text-sm text-slate-500">{{ stats.stores.length }} boutiques · commandes reçues du {{ day(stats.period.from) }} au {{ day(stats.period.to) }}.</p>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-slate-500">Commandes</p><p class="text-2xl font-semibold">{{ t.orders }}</p><p class="text-xs text-slate-500">{{ t.cancelled }} annulée(s)</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Chiffre d'affaires livré</p><p class="text-2xl font-semibold">{{ money(t.revenue) }}</p><p class="text-xs text-slate-500">{{ money(t.in_progress) }} en cours</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Taux de confirmation</p><p class="text-2xl font-semibold">{{ pct(t.confirmation_rate) }}</p><p class="text-xs text-slate-500">Panier moyen {{ t.avg_basket ? money(t.avg_basket) : '—' }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Livraisons réussies</p><p class="text-2xl font-semibold">{{ pct(t.delivery_rate) }}</p><p class="text-xs text-slate-500">Bénéfice {{ money(t.profit) }}</p></div>
      </div>

      <section v-if="chart.length > 1" class="card p-4 text-sm">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="font-semibold">Chiffre d'affaires livré par jour</h3>
          <p v-if="shown" class="text-slate-600">{{ day(shown.date) }} : <strong class="text-slate-900">{{ money(shown.revenue) }}</strong> · {{ shown.orders }} commande(s)</p>
        </div>
        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="h-40 w-full" role="img" aria-label="Chiffre d'affaires livré par jour" @mouseleave="hovered = null">
          <line x1="0" y1="40" x2="100" y2="40" class="stroke-slate-200" stroke-width="0.3" vector-effect="non-scaling-stroke" />
          <g v-for="d in chart" :key="d.date" @mouseenter="hovered = d" @click="hovered = d">
            <rect :x="d.x" y="0" :width="d.w" height="40" fill="transparent" />
            <rect v-if="d.revenue" :x="d.x + d.w * 0.15" :y="40 - d.h * 0.4" :width="d.w * 0.7" :height="d.h * 0.4" rx="0.4"
                  :class="hovered?.date === d.date ? 'fill-emerald-800' : 'fill-emerald-600'" />
          </g>
        </svg>
        <div class="mt-1 flex justify-between text-xs text-slate-500"><span>{{ day(chart[0].date) }}</span><span>{{ day(chart.at(-1).date) }}</span></div>
        <ul v-if="shown && Object.keys(shown.by_store).length" class="mt-2 flex flex-wrap gap-x-4 text-xs text-slate-600">
          <li v-for="(amount, id) in shown.by_store" :key="id">{{ names[id] }} : {{ money(amount) }}</li>
        </ul>
      </section>

      <section class="card overflow-x-auto p-4 text-sm">
        <h3 class="mb-2 font-semibold">Boutique par boutique</h3>
        <table class="w-full min-w-[560px]">
          <thead class="text-left text-xs text-slate-500">
            <tr><th class="py-1">Boutique</th><th class="text-right">Commandes</th><th class="text-right">Confirmation</th><th class="text-right">Réussite</th><th class="text-right">Livré</th><th class="text-right">Marge nette</th><th class="text-right">Bénéfice</th></tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="s in stats.stores" :key="s.id">
              <td class="py-1.5 font-medium">{{ s.name }}</td><td class="text-right">{{ s.overview.orders }}</td><td class="text-right">{{ pct(s.overview.confirmation_rate) }}</td>
              <td class="text-right">{{ pct(s.overview.delivery_rate) }}</td><td class="text-right">{{ money(s.overview.revenue) }}</td><td class="text-right">{{ money(s.overview.net_margin) }}</td>
              <td class="text-right" :class="s.profit < 0 ? 'text-red-700' : ''">{{ money(s.profit) }}</td>
            </tr>
            <tr class="font-semibold">
              <td class="py-1.5">Total</td><td class="text-right">{{ t.orders }}</td><td class="text-right">{{ pct(t.confirmation_rate) }}</td><td class="text-right">{{ pct(t.delivery_rate) }}</td>
              <td class="text-right">{{ money(t.revenue) }}</td><td class="text-right">{{ money(t.net_margin) }}</td><td class="text-right" :class="t.profit < 0 ? 'text-red-700' : ''">{{ money(t.profit) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="card overflow-x-auto p-4 text-sm">
        <h3 class="mb-2 font-semibold">Produits les plus vendus</h3>
        <table class="w-full">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Produit</th><th class="text-right">Quantité</th><th class="text-right">Ventes</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(p, i) in stats.top_products" :key="i">
              <td class="py-1.5">{{ p.title }}<span class="block text-xs text-slate-500">{{ p.stores.join(', ') }}</span></td>
              <td class="text-right">{{ p.quantity }}</td><td class="text-right font-medium">{{ money(p.revenue) }}</td>
            </tr>
            <tr v-if="!stats.top_products.length"><td colspan="3" class="py-2 text-slate-500">Aucune commande livrée sur la période.</td></tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
