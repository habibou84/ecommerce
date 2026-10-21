<script setup>
import Icon from '../../components/Icon.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import http, { download, errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const error = ref('')
const notice = ref(null)
const busy = ref(false)
const choice = reactive({ plan: 'pro', cycle: 'monthly', method: 'wave' })

const FEATURES = { custom_domain: 'Domaine personnalisé', online_payment: 'Paiement en ligne', whatsapp_api: 'WhatsApp automatique', advanced_stats: 'Statistiques avancées', merchant_api: 'API marchand', multi_store: 'Plusieurs boutiques' }
const LIMITS = { orders_per_month: 'commandes / mois', products: 'produits', members: 'membres', couriers: 'livreurs', carrier_connections: 'entreprises de livraison', payment_accounts: 'comptes de paiement', ai_actions_per_month: 'actions de l\'assistant IA / mois' }
const limitLine = (p, key) => (p.limits[key] === null || p.limits[key] === undefined ? 'Illimité' : p.limits[key])

async function load() {
  data.value = (await http.get('/billing')).data.data
  choice.method = Object.keys(data.value.methods)[0] ?? 'wave'
}

onMounted(async () => {
  try {
    // Retour du prestataire de paiement : statut relu chez lui
    if (route.query.facture) {
      const { data: res } = await http.post(`/billing/invoices/${route.query.facture}/check`)
      notice.value = res.data.status === 'paid'
        ? { tone: 'success', text: `Paiement reçu : offre ${res.data.plan} active. Merci !` }
        : { tone: 'warning', text: 'Paiement pas encore confirmé. S\'il a bien été fait, l\'offre s\'activera dans quelques minutes.' }
      router.replace('/gestion/abonnement')
    }
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const sub = computed(() => data.value?.subscription)
const paidPlans = computed(() => data.value?.plans.filter((p) => p.monthly_price > 0) ?? [])
const selected = computed(() => paidPlans.value.find((p) => p.code === choice.plan))
const price = computed(() => (choice.cycle === 'yearly' ? selected.value?.yearly_price : selected.value?.monthly_price) ?? 0)
const percent = (u) => (u.limit ? Math.min(100, Math.round((u.used / u.limit) * 100)) : 0)
// Utilisation affichée : le nombre de boutiques seulement pour une offre qui en couvre plusieurs
const usage = computed(() => Object.fromEntries(Object.entries(data.value?.usage ?? {}).filter(([key, u]) => key !== 'stores' || (u.limit !== 1 && !data.value.covered_by))))
const changing = computed(() => !!selected.value && (selected.value.code !== data.value?.plan.code))
// Jours payés non utilisés : convertis en jours de la nouvelle offre
const creditDays = computed(() => {
  if (!data.value?.credit || !changing.value || !price.value) return 0
  return Math.min(366, Math.floor(data.value.credit / (price.value / (choice.cycle === 'yearly' ? 365 : 30))))
})

async function pay() {
  busy.value = true
  error.value = ''
  try {
    const { data: res } = await http.post('/billing/checkout', choice)
    window.location.assign(res.redirect)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

async function cancel(value) {
  if (value && !window.confirm('Revenir à l\'offre gratuite à la fin de la période payée ?')) return
  data.value = (await http.post('/billing/cancel', { cancel: value })).data.data
}
</script>

<template>
  <div v-if="data" class="space-y-5">
    <Alert v-if="notice" :tone="notice.tone">{{ notice.text }}</Alert>
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card space-y-2 p-4">
      <div class="flex flex-wrap items-baseline gap-2">
        <h3 class="text-lg font-semibold">Offre {{ data.plan.name }}</h3>
        <span v-if="sub?.status === 'trialing'" class="rounded bg-sky-100 px-2 py-0.5 text-sm text-sky-800">Essai gratuit jusqu'au {{ formatDateTime(sub.trial_ends_at) }}</span>
        <span v-else-if="sub?.status === 'past_due'" class="rounded bg-amber-100 px-2 py-0.5 text-sm text-amber-900">À renouveler avant le {{ formatDateTime(sub.grace_ends_at) }}</span>
        <span v-else-if="sub" class="text-sm text-slate-600">{{ sub.cancel_at_period_end ? 'Se termine' : 'Renouvellement' }} le {{ formatDateTime(sub.current_period_end) }} · {{ sub.billing_cycle === 'yearly' ? 'annuel' : 'mensuel' }}</span>
      </div>
      <p v-if="sub?.cancel_at_period_end" class="text-sm">Votre espace marchand passera à l'offre gratuite à l'échéance. <button class="text-emerald-700 underline" @click="cancel(false)">Garder mon abonnement</button></p>
      <p v-else-if="sub && sub.status === 'active'" class="text-sm"><button class="text-slate-500 underline" @click="cancel(true)">Revenir à l'offre gratuite à l'échéance</button></p>
      <div class="grid gap-x-6 gap-y-2 pt-2 sm:grid-cols-2">
        <div v-for="(u, key) in usage" :key="key" class="text-sm">
          <div class="flex justify-between"><span>{{ u.label }}</span><span :class="u.limit !== null && u.used >= u.limit ? 'font-medium text-red-700' : 'text-slate-600'">{{ u.used }}{{ u.limit !== null ? ` / ${u.limit}` : ' · illimité' }}</span></div>
          <div v-if="u.limit !== null" class="mt-1 h-1.5 rounded bg-slate-100"><div class="h-1.5 rounded" :class="percent(u) >= 100 ? 'bg-red-500' : 'bg-emerald-600'" :style="{ width: `${percent(u)}%` }" /></div>
        </div>
      </div>
    </section>

    <Alert v-if="data.covered_by" tone="info">
      Cette boutique est couverte par l'abonnement {{ data.plan.name }} de <strong>{{ data.covered_by.name }}</strong> : rien à payer ici.
      Le renouvellement se fait <a :href="data.covered_by.url" class="underline">depuis cette boutique</a>.
    </Alert>

    <section v-if="!data.covered_by" class="card space-y-4 p-4">
      <h3 class="font-semibold">{{ sub && sub.status !== 'trialing' ? 'Renouveler ou changer d\'offre' : 'Choisir une offre' }}</h3>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:w-fit">
        <button class="flex-1 rounded-md px-3 py-1" :class="choice.cycle === 'monthly' ? 'bg-white shadow-sm' : ''" @click="choice.cycle = 'monthly'">Mensuel</button>
        <button class="flex-1 rounded-md px-3 py-1" :class="choice.cycle === 'yearly' ? 'bg-white shadow-sm' : ''" @click="choice.cycle = 'yearly'">Annuel <span class="text-emerald-700">2 mois offerts</span></button>
      </div>
      <div class="grid gap-3 md:grid-cols-3">
        <label v-for="p in paidPlans" :key="p.code" class="flex cursor-pointer flex-col gap-2 rounded-xl border p-4 text-sm" :class="choice.plan === p.code ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
          <span class="flex items-center gap-2"><input v-model="choice.plan" type="radio" :value="p.code" class="accent-emerald-700" /><span class="text-base font-semibold">{{ p.name }}</span>
            <span v-if="p.code === data.plan.code && sub?.status !== 'trialing'" class="ml-auto rounded bg-white px-1.5 text-xs text-emerald-800">Actuelle</span></span>
          <span class="text-xl font-semibold">{{ money(choice.cycle === 'yearly' ? p.yearly_price : p.monthly_price) }}<span class="text-sm font-normal text-slate-500"> / {{ choice.cycle === 'yearly' ? 'an' : 'mois' }}</span></span>
          <ul class="space-y-0.5 text-slate-600">
            <li class="text-emerald-800">✓ Boutique en ligne{{ p.features.includes('custom_domain') ? ' à votre nom de domaine' : '' }}</li>
            <li v-for="(label, key) in LIMITS" :key="key">{{ limitLine(p, key) }} {{ label }}</li>
            <li v-if="p.features.includes('multi_store')" class="text-emerald-800">✓ {{ p.limits.stores ? `Jusqu'à ${p.limits.stores} boutiques` : 'Toutes vos boutiques' }} sous un seul abonnement</li>
            <template v-for="f in p.features" :key="f">
              <li v-if="f !== 'multi_store' && f !== 'custom_domain'" :class="data.coming_soon.includes(f) ? 'text-slate-400' : 'text-emerald-800'">✓ {{ FEATURES[f] ?? f }}<template v-if="data.coming_soon.includes(f)"> (bientôt)</template></li>
            </template>
          </ul>
        </label>
      </div>
      <p v-if="creditDays > 0" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">
        Vos {{ money(data.credit) }} payés et non utilisés de l'offre {{ data.plan.name }} deviennent environ <strong>{{ creditDays }} jour{{ creditDays > 1 ? 's' : '' }} en plus</strong> sur l'offre {{ selected.name }}.
      </p>
      <div class="flex flex-wrap items-center gap-3">
        <select v-model="choice.method" class="input w-auto" aria-label="Moyen de paiement">
          <option v-for="(label, key) in data.methods" :key="key" :value="key">{{ label }}</option>
        </select>
        <button class="btn-brand" :disabled="busy || !price" @click="pay">{{ busy ? 'Redirection…' : `Payer ${money(price)}` }}</button>
        <span class="text-xs text-slate-500">Paiement sécurisé chez le prestataire. Sans renouvellement, votre espace marchand repasse à l'offre gratuite (rien n'est supprimé).</span>
      </div>
    </section>

    <section v-if="data.invoices.length" class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Factures</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="i in data.invoices" :key="i.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="flex-1">{{ i.number }} · {{ i.plan }} ({{ i.cycle_label }})<span class="block text-xs text-slate-500">{{ i.status_label }}<template v-if="i.period_end"> · jusqu'au {{ formatDateTime(i.period_end) }}</template><template v-if="i.credit_days"> · dont {{ i.credit_days }} jours reportés de l'offre précédente</template></span></span>
          <span class="font-medium">{{ money(i.amount) }}</span>
          <button v-if="['paid', 'offered'].includes(i.status)" class="btn-ghost px-2" @click="download(`/billing/invoices/${i.id}/receipt`, `facture-${i.number}.pdf`)"><Icon name="file" :size="16" class="mr-1 inline-block align-[-3px]" />PDF</button>
          <a v-else-if="i.checkout_url" :href="i.checkout_url" class="btn-ghost px-2">Payer</a>
        </li>
      </ul>
    </section>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
