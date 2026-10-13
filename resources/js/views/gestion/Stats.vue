<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const stats = ref(null)
const error = ref('')
const upgrade = ref(false)
const loading = ref(false)
const iso = (d) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const period = reactive({ preset: '30', from: '', to: iso(new Date()) })
const report = ref(null)
const reportSaved = ref(false)
const reportError = ref('')

const PRESETS = [['7', '7 jours'], ['30', '30 jours'], ['90', '90 jours'], ['month', 'Ce mois'], ['custom', 'Dates…']]

function range() {
  const today = new Date()
  if (period.preset === 'custom') return { from: period.from, to: period.to }
  if (period.preset === 'month') return { from: iso(new Date(today.getFullYear(), today.getMonth(), 1)), to: iso(today) }
  return { from: iso(new Date(today.getTime() - (Number(period.preset) - 1) * 86400000)), to: iso(today) }
}

async function load() {
  if (!auth.can('finance.view')) return
  loading.value = true
  error.value = ''
  try {
    stats.value = (await http.get('/stats', { params: range() })).data.data
  } catch (e) {
    upgrade.value = e.response?.data?.upgrade === true
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function loadReport() {
  report.value = (await http.get('/reports/schedule')).data.data
}

async function saveReport() {
  reportSaved.value = false
  reportError.value = ''
  try {
    report.value = (await http.put('/reports/schedule', { channels: report.value.channels })).data.data
    reportSaved.value = true
  } catch (e) {
    reportError.value = errorMessage(e)
  }
}

onMounted(() => {
  load()
  loadReport()
})

const o = computed(() => stats.value?.overview)
const p = computed(() => stats.value?.profit)
const pct = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')} %`)
const hours = (h) => (h === null ? '—' : h < 24 ? `${Math.round(h)} h` : `${(h / 24).toFixed(1).replace('.', ',')} j`)
</script>

<template>
  <div class="space-y-5">
    <div v-if="auth.can('finance.view')" class="flex flex-wrap items-center gap-2">
      <button v-for="[value, label] in PRESETS" :key="value" class="rounded-full border px-3 py-1 text-sm"
              :class="period.preset === value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-300 bg-white'"
              @click="period.preset = value; value !== 'custom' && load()">{{ label }}</button>
      <template v-if="period.preset === 'custom'">
        <input v-model="period.from" type="date" class="input w-auto py-1" />
        <input v-model="period.to" type="date" class="input w-auto py-1" />
        <button class="btn-brand px-3 py-1" @click="load">Voir</button>
      </template>
      <span v-if="loading" class="text-sm text-slate-500">Chargement…</span>
    </div>

    <Alert v-if="error" :tone="upgrade ? 'warning' : 'error'">
      {{ error }}
      <template v-if="upgrade"> Vous gardez le tableau de bord de l'accueil.</template>
    </Alert>

    <template v-if="stats">
      <p class="text-sm text-slate-500">Commandes reçues du {{ new Date(stats.period.from).toLocaleDateString('fr-FR') }} au {{ new Date(stats.period.to).toLocaleDateString('fr-FR') }}.</p>
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-slate-500">Commandes</p><p class="text-2xl font-semibold">{{ o.orders }}</p><p class="text-xs text-slate-500">{{ o.cancelled }} annulée(s)</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Chiffre d'affaires livré</p><p class="text-2xl font-semibold">{{ money(o.revenue) }}</p><p class="text-xs text-slate-500">{{ money(o.in_progress) }} en cours</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Taux de confirmation</p><p class="text-2xl font-semibold">{{ pct(o.confirmation_rate) }}</p><p class="text-xs text-slate-500">Panier moyen {{ o.avg_basket ? money(o.avg_basket) : '—' }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Livraisons réussies</p><p class="text-2xl font-semibold">{{ pct(o.delivery_rate) }}</p><p class="text-xs text-slate-500">{{ o.delivered }} livrée(s), {{ o.returned }} retour(s)</p></div>
      </div>

      <section class="card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Marge des commandes livrées</h3>
        <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
          <div class="flex justify-between"><dt>Marge sur les produits</dt><dd>{{ money(o.product_margin) }}</dd></div>
          <div class="flex justify-between"><dt>Remises accordées</dt><dd>− {{ money(o.discounts) }}</dd></div>
          <div class="flex justify-between"><dt>Frais de livraison facturés</dt><dd>{{ money(o.shipping_income) }}</dd></div>
          <div class="flex justify-between"><dt>Coût des livraisons</dt><dd>− {{ money(o.shipping_costs) }}</dd></div>
          <div class="flex justify-between border-t border-slate-200 pt-1 font-semibold sm:col-span-2"><dt>Marge nette</dt><dd>{{ money(o.net_margin) }}</dd></div>
        </dl>
        <p v-if="o.without_cost" class="mt-2 text-xs text-amber-800">{{ o.without_cost }} commande(s) sans prix d'achat : renseignez le coût de vos produits pour une marge exacte.</p>
      </section>

      <section v-if="p" class="card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Bénéfice réel</h3>
        <dl class="space-y-1">
          <div class="flex justify-between"><dt>Marge nette des commandes livrées</dt><dd>{{ money(p.net_margin) }}</dd></div>
          <div v-if="p.payment_fees" class="flex justify-between"><dt>Frais des paiements en ligne</dt><dd>− {{ money(p.payment_fees) }}</dd></div>
          <div v-if="p.return_costs" class="flex justify-between"><dt>Livraisons des commandes retournées</dt><dd>− {{ money(p.return_costs) }}</dd></div>
          <div v-if="p.courier_extras" class="flex justify-between"><dt>Primes et ajustements des livreurs</dt><dd>− {{ money(p.courier_extras) }}</dd></div>
          <div v-for="e in p.expenses_by_category" :key="e.category" class="flex justify-between"><dt>{{ e.label }}</dt><dd>− {{ money(e.total) }}</dd></div>
          <div class="flex justify-between border-t border-slate-200 pt-1 text-base font-semibold" :class="p.profit < 0 ? 'text-red-700' : 'text-emerald-800'">
            <dt>Bénéfice</dt><dd>{{ money(p.profit) }}</dd>
          </div>
        </dl>
        <p v-if="p.stock_purchases" class="mt-2 text-xs text-slate-500">
          Achats de marchandises de la période : {{ money(p.stock_purchases) }}. Ils ne sont pas retirés ici : le prix d'achat des produits vendus est déjà compté dans la marge.
        </p>
        <p v-else-if="!p.expenses" class="mt-2 text-xs text-slate-500">Notez vos dépenses (loyer, transport, emballages…) dans Caisse › Dépense pour un bénéfice complet.</p>
      </section>

      <div class="grid gap-5 lg:grid-cols-2">
        <section class="card overflow-x-auto p-4 text-sm">
          <h3 class="mb-2 font-semibold">Par canal de vente</h3>
          <table class="w-full">
            <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Canal</th><th class="text-right">Commandes</th><th class="text-right">Livrées</th><th class="text-right">Réussite</th><th class="text-right">Ventes</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in stats.by_source" :key="r.source"><td class="py-1.5">{{ r.label }}</td><td class="text-right">{{ r.orders }}</td><td class="text-right">{{ r.delivered }}</td><td class="text-right">{{ pct(r.delivery_rate) }}</td><td class="text-right font-medium">{{ money(r.revenue) }}</td></tr>
            </tbody>
          </table>
        </section>

        <section class="card overflow-x-auto p-4 text-sm">
          <h3 class="mb-2 font-semibold">Par zone</h3>
          <table class="w-full">
            <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Zone</th><th class="text-right">Commandes</th><th class="text-right">Réussite</th><th class="text-right">Frais moyens</th><th class="text-right">Ventes</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in stats.by_zone" :key="r.zone_id ?? 0"><td class="py-1.5">{{ r.name }}</td><td class="text-right">{{ r.orders }}</td><td class="text-right">{{ pct(r.delivery_rate) }}</td><td class="text-right">{{ r.avg_fee !== null ? money(r.avg_fee) : '—' }}</td><td class="text-right font-medium">{{ money(r.revenue) }}</td></tr>
            </tbody>
          </table>
        </section>
      </div>

      <section class="card overflow-x-auto p-4 text-sm">
        <h3 class="mb-2 font-semibold">Produits livrés</h3>
        <table class="w-full">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Produit</th><th class="text-right">Quantité</th><th class="text-right">Ventes</th><th class="text-right">Marge</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="(r, i) in stats.by_product" :key="i">
              <td class="py-1.5">{{ r.title }}<span v-if="r.variant_title" class="text-slate-500"> · {{ r.variant_title }}</span></td>
              <td class="text-right">{{ r.quantity }}</td><td class="text-right font-medium">{{ money(r.revenue) }}</td><td class="text-right">{{ r.margin !== null ? money(r.margin) : '—' }}</td>
            </tr>
            <tr v-if="!stats.by_product.length"><td colspan="4" class="py-2 text-slate-500">Aucune commande livrée sur la période.</td></tr>
          </tbody>
        </table>
      </section>

      <div class="grid gap-5 lg:grid-cols-2">
        <section v-for="[key, title] in [['by_courier', 'Mes livreurs'], ['by_carrier', 'Entreprises de livraison']]" :key="key" class="card overflow-x-auto p-4 text-sm">
          <h3 class="mb-2 font-semibold">{{ title }}</h3>
          <table class="w-full">
            <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Nom</th><th class="text-right">Courses</th><th class="text-right">Réussite</th><th class="text-right">Délai</th><th class="text-right">Coût moyen</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="r in stats[key]" :key="r.id"><td class="py-1.5">{{ r.name }}</td><td class="text-right">{{ r.shipments }}</td><td class="text-right">{{ pct(r.success_rate) }}</td><td class="text-right">{{ hours(r.avg_hours) }}</td><td class="text-right">{{ r.avg_cost !== null ? money(r.avg_cost) : '—' }}</td></tr>
              <tr v-if="!stats[key].length"><td colspan="5" class="py-2 text-slate-500">Aucune course sur la période.</td></tr>
            </tbody>
          </table>
        </section>
      </div>

      <section v-if="stats.by_promotion.length" class="card overflow-x-auto p-4 text-sm">
        <h3 class="mb-2 font-semibold">Promotions</h3>
        <table class="w-full">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Promotion</th><th class="text-right">Commandes</th><th class="text-right">Remises</th><th class="text-right">Ventes livrées</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="r in stats.by_promotion" :key="r.id"><td class="py-1.5">{{ r.kind === 'flash' ? '⚡ ' : '' }}{{ r.name }}</td><td class="text-right">{{ r.orders }}</td><td class="text-right">{{ money(r.discounted) }}</td><td class="text-right font-medium">{{ money(r.revenue) }}</td></tr>
          </tbody>
        </table>
      </section>
    </template>

    <section v-if="report" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">📬 Mon rapport du jour</h3>
      <p class="text-slate-600">Chaque jour à l'heure choisie : ventes du jour, commandes à confirmer et à faire livrer, espèces chez les livreurs.</p>
      <Alert v-if="reportError">{{ reportError }}</Alert>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-slate-200 p-3">
          <label class="flex items-center gap-2 font-medium"><input v-model="report.channels.whatsapp.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Par WhatsApp ({{ report.phone }})</label>
          <input v-model="report.channels.whatsapp.send_at" type="time" class="input mt-2 w-32" />
          <p v-if="!report.whatsapp_ready" class="mt-2 text-xs text-amber-800">Connectez le WhatsApp de la boutique et indiquez le modèle « Rapport du jour » (menu WhatsApp).</p>
        </div>
        <div class="rounded-lg border border-slate-200 p-3">
          <label class="flex items-center gap-2 font-medium"><input v-model="report.channels.email.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Par email {{ report.email ? `(${report.email})` : '' }}</label>
          <input v-model="report.channels.email.send_at" type="time" class="input mt-2 w-32" />
          <p v-if="report.channels.email.last_sent_at" class="mt-2 text-xs text-slate-500">Dernier envoi : {{ formatDateTime(report.channels.email.last_sent_at) }}</p>
        </div>
      </div>
      <details class="text-slate-600"><summary class="cursor-pointer">Aperçu d'aujourd'hui</summary><pre class="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 font-sans">{{ report.preview }}</pre></details>
      <div class="flex items-center gap-3"><button class="btn-brand" @click="saveReport">Enregistrer</button><span v-if="reportSaved" class="text-emerald-700">Enregistré ✓</span></div>
    </section>
  </div>
</template>
