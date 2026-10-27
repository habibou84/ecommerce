<script setup>
import Icon from '../../components/Icon.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import Modal from '../../components/Modal.vue'
import http, { download, errorMessage } from '../../lib/http'
import { formatDate, formatDateTime, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const error = ref('')
const notice = ref(null)
const busy = ref(false)
const choice = reactive({ plan: 'pro', cycle: 'monthly', method: 'wave', payer_phone: '' })
const auth = useAuthStore()
// Clé d'accès (carte à QR code) : lien « ?cle= » depuis la page de la carte, ou code saisi
const keyCode = ref(typeof route.query.cle === 'string' ? route.query.cle : '')
const keyOpen = ref(!!keyCode.value)
const keyError = ref('')
const KEY_STATE = { active: 'Active', paused: 'En pause', ended: 'Terminée', free: 'Mois offerts', discount: 'Réduction en cours', lapsed: 'Réduction arrêtée' }

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
        ? { tone: 'success', text: res.data.kind === 'ai_pack' ? `Paiement reçu : ${res.data.plan.toLowerCase()} ajoutée. Merci !` : `Paiement reçu : offre ${res.data.plan} active. Merci !` }
        : { tone: 'warning', text: 'Paiement pas encore confirmé. S\'il a bien été fait, l\'offre s\'activera dans quelques minutes.' }
      router.replace('/gestion/abonnement')
    }
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
})

// Messages affichés en haut de la page : on y remonte pour qu'ils se voient tout de suite
watch([notice, error], ([n, e]) => {
  if (n || e) window.scrollTo({ top: 0, behavior: 'smooth' })
})

const sub = computed(() => data.value?.subscription)
const paidPlans = computed(() => data.value?.plans.filter((p) => p.monthly_price > 0) ?? [])
const selected = computed(() => paidPlans.value.find((p) => p.code === choice.plan))
// Rythme sans prix pour l'offre choisie (ex. Pro à la semaine) : première offre qui l'a
watch(() => choice.cycle, (cycle) => {
  if (selected.value && !priceOf(selected.value, cycle)) choice.plan = paidPlans.value.find((p) => priceOf(p, cycle))?.code ?? choice.plan
})
const CYCLE_PRICE = { weekly: 'weekly_price', monthly: 'monthly_price', yearly: 'yearly_price' }
const CYCLE_UNIT = { weekly: 'semaine', monthly: 'mois', yearly: 'an' }
const CYCLE_LABEL = { weekly: 'à la semaine', monthly: 'mensuel', yearly: 'annuel' }
// Carte Membre Premium offerte par une entreprise : prix réduit tant que la boutique livre avec elle
const discount = computed(() => data.value?.premium_discount ?? null)
const priceOf = (p, cycle = choice.cycle) => {
  const raw = p?.[CYCLE_PRICE[cycle]] ?? null
  return raw && discount.value ? Math.round(raw * (1 - discount.value.rate)) : raw
}
const price = computed(() => priceOf(selected.value) ?? 0)
// Paiement à la semaine proposé seulement si une offre a un prix à la semaine
const weekly = computed(() => paidPlans.value.some((p) => p.weekly_price))
// Économie de l'abonnement annuel par rapport à 12 mois payés au mois
const yearlySaving = (p) => (p.yearly_price ? Math.max(0, p.monthly_price * 12 - p.yearly_price) : 0)
const bestSaving = computed(() => Math.max(0, ...paidPlans.value.map(yearlySaving)))
const percent = (u) => (u.limit ? Math.min(100, Math.round((u.used / u.limit) * 100)) : 0)
// Utilisation affichée : le nombre de boutiques seulement pour une offre qui en couvre plusieurs ; l'assistant IA
// seulement si l'offre en comprend (sinon il marche avec les recharges)
const usage = computed(() => Object.fromEntries(Object.entries(data.value?.usage ?? {}).filter(([key, u]) => (key !== 'stores' || (u.limit !== 1 && !data.value.covered_by)) && (key !== 'ai_actions_per_month' || u.limit !== 0))))
const aiInPlan = computed(() => data.value?.usage?.ai_actions_per_month?.limit !== 0)
const changing = computed(() => !!selected.value && (selected.value.code !== data.value?.plan.code))
// Jours payés non utilisés : convertis en jours de la nouvelle offre
const creditDays = computed(() => {
  if (!data.value?.credit || !changing.value || !price.value) return 0
  return Math.min(366, Math.floor(data.value.credit / (price.value / { weekly: 7, monthly: 30, yearly: 365 }[choice.cycle])))
})

// Lien marchand Wave : la facture est créée (le validateur est prévenu), puis le panneau Wave s'ouvre :
// bouton vers Wave sur téléphone, QR code à scanner sur ordinateur, et « J'ai payé » pour une seconde alerte
const wave = ref(null)
const claiming = ref(false)

async function go(res) {
  if (!data.value.manual_payment) {
    window.location.assign(res.redirect)
    return
  }
  wave.value = { invoice: res.data, link: res.redirect, phone: choice.payer_phone }
  busy.value = false
  await load()
}

async function claimPaid() {
  claiming.value = true
  error.value = ''
  try {
    const { data: res } = await http.post(`/billing/invoices/${wave.value.invoice.number}/paid`)
    notice.value = { tone: 'info', text: `Merci ! Nous vérifions votre paiement de ${money(res.data.amount)}. Dès qu'il est confirmé, votre ${res.data.kind === 'ai_pack' ? 'recharge est ajoutée' : 'offre est activée'} et vous êtes prévenu.` }
    wave.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    claiming.value = false
  }
}

async function pay() {
  if (data.value.manual_payment && !choice.payer_phone.trim()) {
    error.value = 'Indiquez le numéro Wave qui va payer.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data: res } = await http.post('/billing/checkout', choice)
    await go(res)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

async function buyAiPack() {
  if (data.value.manual_payment && !choice.payer_phone.trim()) {
    error.value = 'Indiquez le numéro Wave qui va payer.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data: res } = await http.post('/billing/ai-pack', { method: choice.method, payer_phone: choice.payer_phone })
    await go(res)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

async function redeemKey() {
  busy.value = true
  keyError.value = ''
  try {
    await http.post('/billing/access-key', { code: keyCode.value })
    keyCode.value = ''
    keyOpen.value = false
    if (route.query.cle) router.replace({ query: { ...route.query, cle: undefined } })
    await Promise.all([load(), auth.fetch()])
    const k = data.value.access_key
    notice.value = { tone: 'success', text: k?.premium ? `Bienvenue parmi les Membres Premium Jibiat Marchand ! Votre offre ${k.plan} est active${k.founder ? '' : ` jusqu'au ${formatDate(k.until)}`}.` : `Clé activée : offre ${k?.plan} jusqu'au ${formatDate(k?.until)}.` }
  } catch (e) {
    keyError.value = errorMessage(e)
  } finally {
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
        <span v-if="data.free" class="rounded bg-emerald-100 px-2 py-0.5 text-sm font-medium text-emerald-800">🎁 Offerte {{ data.free.forever ? 'sans limite de temps' : data.free.until ? `jusqu'au ${formatDate(data.free.until)}` : 'en ce moment' }}</span>
        <span v-else-if="sub?.status === 'trialing'" class="rounded bg-sky-100 px-2 py-0.5 text-sm text-sky-800">Essai gratuit jusqu'au {{ formatDateTime(sub.trial_ends_at) }}</span>
        <span v-else-if="sub?.status === 'past_due'" class="rounded bg-amber-100 px-2 py-0.5 text-sm text-amber-900">À renouveler avant le {{ formatDateTime(sub.grace_ends_at) }}</span>
        <span v-else-if="sub" class="text-sm text-slate-600">{{ sub.cancel_at_period_end ? 'Se termine' : 'Renouvellement' }} le {{ formatDateTime(sub.current_period_end) }} · {{ CYCLE_LABEL[sub.billing_cycle] ?? 'mensuel' }}</span>
      </div>
      <p v-if="data.free" class="text-sm text-slate-600">Jibiat Marchand vous offre l'offre {{ data.free.plan }} : rien à payer{{ data.free.forever ? '' : ' pendant cette période' }}. Ses limites s'appliquent.</p>
      <p v-else-if="sub?.cancel_at_period_end" class="text-sm">Votre espace marchand passera à l'offre gratuite à l'échéance. <button class="text-emerald-700 underline" @click="cancel(false)">Garder mon abonnement</button></p>
      <p v-else-if="sub && sub.status === 'active' && !data.free" class="text-sm"><button class="text-slate-500 underline" @click="cancel(true)">Revenir à l'offre gratuite à l'échéance</button></p>
      <div class="grid gap-x-6 gap-y-2 pt-2 sm:grid-cols-2">
        <div v-for="(u, key) in usage" :key="key" class="text-sm">
          <div class="flex justify-between"><span>{{ u.label }}</span><span :class="u.limit !== null && u.used >= u.limit ? 'font-medium text-red-700' : 'text-slate-600'">{{ u.used }}{{ u.limit !== null ? ` / ${u.limit}` : ' · illimité' }}</span></div>
          <div v-if="u.limit !== null" class="mt-1 h-1.5 rounded bg-slate-100"><div class="h-1.5 rounded" :class="percent(u) >= 100 ? 'bg-red-500' : 'bg-emerald-600'" :style="{ width: `${percent(u)}%` }" /></div>
        </div>
      </div>
    </section>

    <section v-if="data.access_key" class="card space-y-1 p-4 text-sm" :class="data.access_key.premium ? 'bg-slate-950 text-white ring-0' : ''">
      <p v-if="data.access_key.premium" class="text-base font-semibold text-amber-300">🏅 {{ data.access_key.badge }}</p>
      <p v-else class="font-semibold">Clé d'accès</p>
      <p v-if="data.access_key.offered_by" class="text-slate-300">Offerte par <strong class="text-white">{{ data.access_key.offered_by }}</strong></p>
      <p>
        Offre {{ data.access_key.plan }} {{ data.access_key.founder ? 'à vie' : `jusqu'au ${formatDate(data.access_key.until)}` }} ·
        <strong>{{ KEY_STATE[data.access_key.state] ?? data.access_key.state }}</strong>
      </p>
      <p v-if="data.access_key.offered_by" class="text-slate-300">
        Après les {{ data.access_key.free_months }} mois offerts : −{{ Math.round(data.access_key.discount_rate * 100) }} % sur votre abonnement tant que vous livrez avec {{ data.access_key.offered_by }}.
        <template v-if="data.access_key.state === 'lapsed'"> Aucune livraison récente avec eux : confiez-leur votre prochaine livraison pour retrouver la réduction.</template>
      </p>
      <p v-if="data.access_key.state === 'paused'" :class="data.access_key.premium ? 'text-slate-200' : 'text-slate-600'">Sans commande depuis 2 mois, votre carte est en pause : elle se rallume dès votre prochaine commande.</p>
      <p v-else-if="data.access_key.pause_on" class="text-slate-200">Elle reste active tant que votre boutique enregistre au moins une commande tous les 2 mois (prochaine échéance : {{ formatDate(data.access_key.pause_on) }}).</p>
      <p v-else-if="data.access_key.state === 'ended'" class="text-slate-600">Votre clé est terminée : choisissez une offre ci-dessous pour garder toutes les fonctions.</p>
    </section>

    <Alert v-if="discount" tone="success">Carte Membre Premium : −{{ Math.round(discount.rate * 100) }} % sur votre abonnement tant que vous livrez avec {{ discount.company }}. Les prix ci-dessous sont déjà réduits.</Alert>

    <section v-if="!data.access_key?.premium" class="card space-y-3 p-4 text-sm">
      <button v-if="!keyOpen" type="button" class="font-medium text-emerald-700 underline" @click="keyOpen = true">Vous avez une carte ou une clé d'accès ?</button>
      <form v-else class="space-y-2" @submit.prevent="redeemKey">
        <label for="key-code" class="block font-semibold">Activer une clé d'accès</label>
        <p class="text-slate-600">Saisissez le code écrit sur la carte (ou scannez son QR code avec votre téléphone).</p>
        <div class="flex flex-wrap gap-2">
          <input id="key-code" v-model="keyCode" class="input w-48 font-mono uppercase tracking-widest" placeholder="ABCD-EFGH" maxlength="20" autocapitalize="characters" spellcheck="false" required />
          <button class="btn-brand" :disabled="busy">{{ busy ? 'Un instant…' : 'Activer' }}</button>
        </div>
        <p v-if="keyError" class="text-red-700">{{ keyError }}</p>
      </form>
    </section>

    <Alert v-if="data.covered_by" tone="info">
      Cette boutique est couverte par l'abonnement {{ data.plan.name }} de <strong>{{ data.covered_by.name }}</strong> : rien à payer ici.
      Le renouvellement se fait <a :href="data.covered_by.url" class="underline">depuis cette boutique</a>.
    </Alert>

    <section v-if="!data.covered_by" class="card space-y-4 p-4">
      <h3 class="font-semibold">{{ sub && sub.status !== 'trialing' ? 'Renouveler ou changer d\'offre' : 'Choisir une offre' }}</h3>
      <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:w-fit">
        <button v-if="weekly" class="flex-1 rounded-md px-3 py-1 whitespace-nowrap" :class="choice.cycle === 'weekly' ? 'bg-white shadow-sm' : ''" @click="choice.cycle = 'weekly'">Semaine</button>
        <button class="flex-1 rounded-md px-3 py-1" :class="choice.cycle === 'monthly' ? 'bg-white shadow-sm' : ''" @click="choice.cycle = 'monthly'">Mois</button>
        <button class="flex-1 rounded-md px-3 py-1 whitespace-nowrap" :class="choice.cycle === 'yearly' ? 'bg-white shadow-sm' : ''" @click="choice.cycle = 'yearly'">Année <span class="text-emerald-700">2 mois offerts</span></button>
      </div>
      <p v-if="choice.cycle === 'yearly' && bestSaving" class="text-sm text-emerald-800">12 mois pour le prix de 10 : jusqu'à <strong>{{ money(bestSaving) }}</strong> d'économie par an.</p>
      <p v-else-if="choice.cycle === 'weekly'" class="text-sm text-slate-600">Payez semaine après semaine, au rythme de vos ventes. Au mois, c'est moins cher.</p>
      <div class="grid gap-3 md:grid-cols-3">
        <label v-for="p in paidPlans" :key="p.code" class="flex flex-col gap-2 rounded-xl border p-4 text-sm" :class="[choice.plan === p.code ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200', priceOf(p) ? 'cursor-pointer' : 'opacity-50']">
          <span class="flex items-center gap-2"><input v-model="choice.plan" type="radio" :value="p.code" :disabled="!priceOf(p)" class="accent-emerald-700" /><span class="text-base font-semibold">{{ p.name }}</span>
            <span v-if="p.code === data.plan.code && sub?.status !== 'trialing'" class="ml-auto rounded bg-white px-1.5 text-xs text-emerald-800">Actuelle</span></span>
          <span v-if="priceOf(p)" class="text-xl font-semibold">{{ money(priceOf(p)) }}<span class="text-sm font-normal text-slate-500"> / {{ CYCLE_UNIT[choice.cycle] }}</span></span>
          <span v-else class="text-slate-500">Pas de paiement à la {{ CYCLE_UNIT[choice.cycle] }}</span>
          <span v-if="choice.cycle === 'yearly' && yearlySaving(p)" class="text-xs font-medium text-emerald-700">{{ money(yearlySaving(p)) }} d'économie par an</span>
          <ul class="space-y-0.5 text-slate-600">
            <li class="text-emerald-800">✓ Boutique en ligne{{ p.features.includes('custom_domain') ? ' à votre nom de domaine' : '' }}</li>
            <template v-for="(label, key) in LIMITS" :key="key"><li v-if="key !== 'ai_actions_per_month' || p.limits[key] !== 0">{{ limitLine(p, key) }} {{ label }}</li></template>
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
      <label v-if="data.manual_payment" class="block max-w-xs text-sm">Numéro Wave qui va payer
        <input v-model="choice.payer_phone" type="tel" class="input mt-1" placeholder="07 00 00 00 00" />
      </label>
      <div class="flex flex-wrap items-center gap-3">
        <select v-model="choice.method" class="input w-auto" aria-label="Moyen de paiement">
          <option v-for="(label, key) in data.methods" :key="key" :value="key">{{ label }}</option>
        </select>
        <button class="btn-brand" :disabled="busy || !price" @click="pay">{{ busy ? 'Un instant…' : `Payer ${money(price)}` }}</button>
        <span class="text-xs text-slate-500">Sans carte bancaire : Wave, Orange Money, MTN ou Moov. Une facture à chaque paiement. Sans renouvellement, votre espace marchand repasse à l'offre gratuite (rien n'est supprimé).</span>
      </div>
    </section>

    <section v-if="data.ai_pack" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">{{ aiInPlan ? 'Recharger l\'assistant' : 'Assistant IA' }}</h3>
      <p class="text-slate-600">
        <template v-if="aiInPlan">{{ data.ai_pack.actions }} actions de plus pour {{ money(data.ai_pack.price) }}, utilisées quand celles de votre offre sont épuisées.</template>
        <template v-else>L'assistant IA se paie à part, quelle que soit votre offre : {{ data.ai_pack.actions }} actions pour {{ money(data.ai_pack.price) }}. Une action, c'est une commande remplie, une fiche produit écrite ou une réponse préparée.</template>
        Elles ne périment pas.
        <template v-if="data.ai_credits"> En réserve : <strong>{{ data.ai_credits }} actions</strong>.</template>
      </p>
      <label v-if="data.manual_payment" class="block max-w-xs text-sm">Numéro Wave qui va payer
        <input v-model="choice.payer_phone" type="tel" class="input mt-1" placeholder="07 00 00 00 00" />
      </label>
      <div class="flex flex-wrap items-center gap-3">
        <select v-model="choice.method" class="input w-auto" aria-label="Moyen de paiement de la recharge">
          <option v-for="(label, key) in data.methods" :key="key" :value="key">{{ label }}</option>
        </select>
        <button class="btn-brand" :disabled="busy" @click="buyAiPack">{{ busy ? 'Un instant…' : `Recharger pour ${money(data.ai_pack.price)}` }}</button>
      </div>
    </section>

    <section v-if="data.invoices.length" class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Factures</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="i in data.invoices" :key="i.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="flex-1">{{ i.number }} · {{ i.plan }} ({{ i.cycle_label }})<span class="block text-xs text-slate-500">{{ i.status_label }}<template v-if="i.period_end"> · jusqu'au {{ formatDateTime(i.period_end) }}</template><template v-if="i.credit_days"> · dont {{ i.credit_days }} jours reportés de l'offre précédente</template></span></span>
          <span class="font-medium">{{ money(i.amount) }}</span>
          <button v-if="['paid', 'offered'].includes(i.status)" class="btn-ghost px-2" @click="download(`/billing/invoices/${i.id}/receipt`, `facture-${i.number}.pdf`)"><Icon name="file" :size="16" class="mr-1 inline-block align-[-3px]" />PDF</button>
          <template v-else-if="i.status === 'pending' && i.manual">
            <span v-if="i.claimed_at" class="text-xs text-slate-500">Vérification en cours</span>
            <button v-else class="btn-ghost px-2" @click="wave = { invoice: i, link: i.checkout_url, phone: '' }">Payer</button>
          </template>
          <a v-else-if="i.checkout_url" :href="i.checkout_url" class="btn-ghost px-2">Payer</a>
        </li>
      </ul>
    </section>

    <Modal v-if="wave" title="Payer avec Wave" @close="wave = null">
      <div class="space-y-5 text-sm">
        <div class="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
          <p class="text-2xl font-bold">{{ money(wave.invoice.amount) }} à payer</p>
          <p v-if="wave.phone" class="mt-1 text-slate-600">Depuis le numéro Wave {{ wave.phone }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ wave.invoice.plan }}<template v-if="wave.invoice.kind !== 'ai_pack'"> ({{ wave.invoice.cycle_label }})</template> · facture {{ wave.invoice.number }}</p>
        </div>

        <section class="space-y-3">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-sky-600 text-xs text-white">1</span>Payez avec Wave</h3>
          <!-- Téléphone : le bouton d'abord ; ordinateur : le QR code d'abord (on paie avec son téléphone) -->
          <div class="flex flex-col gap-3">
            <a v-if="wave.link" :href="wave.link" target="_blank" rel="noopener" class="order-1 flex w-full justify-center rounded-lg bg-sky-600 px-4 py-3 font-semibold text-white hover:bg-sky-700 md:order-2">Cliquez ici pour payer avec Wave</a>
            <div v-if="data.wave_qr_url" class="order-2 flex flex-col items-center gap-2 md:order-1">
              <p class="text-slate-600">{{ wave.link ? 'ou scannez ce code QR avec l\'application Wave' : 'Scannez ce code QR avec l\'application Wave' }}</p>
              <img :src="data.wave_qr_url" alt="Code QR Wave de Jibiat Marchand" class="h-52 w-52 rounded-lg bg-white object-contain ring-1 ring-slate-200" />
            </div>
          </div>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-4">
          <h3 class="flex items-center gap-2 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-700 text-xs text-white">2</span>Confirmez votre paiement</h3>
          <p class="text-slate-600">Une fois le paiement fait dans Wave :</p>
          <button class="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:opacity-60" :disabled="claiming" @click="claimPaid">{{ claiming ? 'Envoi…' : 'Cliquez ici pour confirmer votre paiement' }}</button>
          <p class="text-xs text-slate-500">Nous vérifions la réception, puis votre {{ wave.invoice.kind === 'ai_pack' ? 'recharge est ajoutée' : 'offre est activée' }}.</p>
        </section>
      </div>
    </Modal>
  </div>
  <p v-else-if="!error" class="text-sm text-slate-500">Chargement…</p>
  <Alert v-else>{{ error }}</Alert>
</template>
