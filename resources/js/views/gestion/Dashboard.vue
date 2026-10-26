<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import Icon from '../../components/Icon.vue'
import http from '../../lib/http'
import { daysUntil, formatDate, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()

const stats = ref(null)
async function load() {
  stats.value = (await http.get('/dashboard')).data.data
}
onMounted(load)

// Vendre en live, en tête : en direct, les chiffres se mettent à jour toutes les 30 secondes
const live = computed(() => stats.value?.live ?? null)
const now = ref(Date.now())
const timer = setInterval(() => {
  now.value = Date.now()
  if (live.value?.state === 'live' && !document.hidden) load()
}, 30000)
onBeforeUnmount(() => clearInterval(timer))
const liveMinutes = computed(() => {
  const at = live.value?.session?.started_at
  if (!at) return null
  const minutes = Math.max(0, Math.floor((now.value - new Date(at).getTime()) / 60000))
  if (minutes >= 1440) return `depuis ${Math.floor(minutes / 1440)} j`
  return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')}`
})
const copied = ref(false)
async function copyLink() {
  try {
    await navigator.clipboard.writeText(live.value.page_url)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    window.prompt('Lien de la page du live :', live.value.page_url)
  }
}

// Le reste (raccourcis, semaine, conseils) derrière « Tout voir » ; le choix est retenu sur cet appareil
const more = ref(false)
try { more.value = localStorage.getItem('dashboard-more') === '1' } catch {}
function toggleMore() {
  more.value = !more.value
  try { localStorage.setItem('dashboard-more', more.value ? '1' : '0') } catch {}
}

const welcome = computed(() => route.query.bienvenue === '1')
const subscription = computed(() => auth.store?.subscription)
// L'abonnement ne concerne que le propriétaire
const trialDays = computed(() =>
  auth.can('billing.manage') && !auth.store?.free && subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null,
)
const today = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })

// Aujourd'hui comparé à hier à la même heure : flèche verte si ça monte, rouge si ça baisse
function trend(now, before, format = String) {
  if (now === before) return { tone: 'text-slate-500', icon: null, text: 'comme hier à la même heure' }
  if (!before) return { tone: 'text-emerald-700', icon: 'arrow-up', text: 'hier à la même heure : rien' }
  if (!now) return { tone: 'text-red-700', icon: 'arrow-down', text: `hier à la même heure : ${format(before)}` }
  const pct = Math.round(((now - before) / before) * 100)
  return now > before
    ? { tone: 'text-emerald-700', icon: 'arrow-up', text: `+${pct} % par rapport à hier` }
    : { tone: 'text-red-700', icon: 'arrow-down', text: `${pct} % par rapport à hier` }
}
const amountTrend = computed(() => stats.value?.orders && trend(stats.value.orders.today_amount, stats.value.orders.yesterday_amount, money))
const countTrend = computed(() => stats.value?.orders && trend(stats.value.orders.today_count, stats.value.orders.yesterday_count))

// Ventes des 7 derniers jours : une barre par jour, le détail au survol (ou au toucher)
const week = computed(() => stats.value?.week ?? [])
const weekTotal = computed(() => week.value.reduce((sum, d) => sum + d.amount, 0))
const weekMax = computed(() => Math.max(...week.value.map((d) => d.amount), 1))
const activeDay = ref(null)
const dayLabel = (date, long = false) =>
  new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', long ? { weekday: 'long', day: 'numeric', month: 'long' } : { weekday: 'short' }).replace('.', '')

// À faire maintenant : seulement ce qui attend quelqu'un
const todo = computed(() => {
  const s = stats.value
  if (!s) return []
  const items = []
  if (s.live?.state === 'live') {
    items.push({ key: 'live', icon: 'live', label: 'Réservations du live en attente', count: s.live.session.summary.pending, to: `/gestion/live/${s.live.session.id}`,
      hint: s.live.session.summary.pending ? 'clients qui n\'ont pas encore donné leur quartier' : '', urgent: s.live.session.summary.pending > 0 })
  }
  if (s.orders) {
    items.push({ key: 'confirm', icon: 'phone', label: 'Commandes à confirmer', count: s.orders.to_confirm, to: auth.can('orders.confirm') ? '/gestion/confirmation' : '/gestion/commandes?status=pending_confirmation',
      hint: s.orders.callbacks_due ? `dont ${s.orders.callbacks_due} rappel(s) à faire maintenant` : '', urgent: s.orders.callbacks_due > 0 })
    items.push({ key: 'ship', icon: 'truck', label: 'Commandes à faire livrer', count: s.orders.to_ship, to: '/gestion/commandes?status=to_ship',
      hint: s.orders.waiting?.unassigned ? `dont ${s.orders.waiting.unassigned} sans livreur, à livrer aujourd'hui ou en retard` : '', urgent: s.orders.waiting?.unassigned > 0 })
    if (s.orders.waiting) {
      items.push({ key: 'not_picked', icon: 'box', label: 'Colis pas encore ramassés', count: s.orders.waiting.not_picked, to: '/gestion/commandes?alert=not_picked' })
      items.push({ key: 'unhandled', icon: 'alert', label: 'Problèmes de livraison à traiter', count: s.orders.waiting.unhandled, to: '/gestion/commandes?alert=unhandled', urgent: s.orders.waiting.unhandled > 0 })
    }
  }
  // Argent et colis qui traînent chez les livreurs (délais des alertes)
  if (s.couriers) {
    const late = s.couriers.late
    items.push({ key: 'courier_cash', icon: 'cash', label: 'Argent à récupérer chez les livreurs', count: late.length, to: '/gestion/caisse/livreurs',
      hint: late.length ? `${money(late.reduce((sum, c) => sum + c.held, 0))} pas encore versés depuis trop longtemps` : '', urgent: late.length > 0 })
  }
  if (s.carriers) {
    const overdue = s.carriers.overdue
    items.push({ key: 'carrier_payout', icon: 'receipt', label: 'Reversements en retard', count: overdue.length, to: '/gestion/caisse/a-recevoir',
      hint: overdue.length ? `${money(overdue.reduce((sum, c) => sum + c.amount, 0))} pas encore reversés` : '', urgent: overdue.length > 0 })
  }
  if (s.couriers || s.carriers) {
    const late = (s.couriers?.late_returns ?? 0) + (s.carriers?.late_returns ?? 0)
    items.push({ key: 'returns', icon: 'box', label: 'Colis à rapporter', count: (s.couriers?.to_bring_back ?? 0) + (s.carriers?.to_bring_back ?? 0), to: '/gestion/commandes?alert=to_bring_back',
      hint: late ? `dont ${late} pas rapporté(s) depuis trop longtemps` : '', urgent: late > 0 })
  }
  if (s.unread_messages !== undefined) items.push({ key: 'messages', icon: 'messages', label: 'Messages non lus', count: s.unread_messages, to: '/gestion/messages' })
  if (s.stock) items.push({ key: 'stock', icon: 'box', label: 'Articles épuisés', count: s.stock.out, to: '/gestion/stock', hint: s.stock.low ? `et ${s.stock.low} en stock bas` : '', urgent: s.stock.out > 0 })
  return items
})
// Dehors en ce moment : argent et colis chez les livreurs et les entreprises de livraison
// « depuis hier », « depuis le 23 octobre »
function since(at) {
  const day = new Date(at)
  const days = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(day).setHours(0, 0, 0, 0)) / 86400000)
  return days <= 0 ? 'depuis aujourd\'hui' : days === 1 ? 'depuis hier' : `depuis le ${day.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}`
}
const lateTone = (level) => (level > 1 ? 'bg-red-50 text-red-800' : 'bg-ocre-50 text-ocre-800')
const pending = computed(() => todo.value.filter((t) => t.count > 0 || t.hint))
// Téléphone : l'urgent d'abord, trois lignes au plus
const pendingShort = computed(() => [...pending.value].sort((a, b) => Number(!!b.urgent) - Number(!!a.urgent)).slice(0, 3))
const pendingHidden = computed(() => pending.value.length - pendingShort.value.length)

// Guide de démarrage : avancement et prochaine étape (le détail est sur sa page)
const guide = ref(null)
onMounted(async () => {
  guide.value = (await http.get('/setup-guide').catch(() => ({ data: { data: null } }))).data.data
})
// Raccourcis : les actions les plus fréquentes, selon les droits
const shortcuts = computed(() => [
  auth.can('orders.manage') && { to: '/gestion/commandes/nouvelle', icon: 'orders', label: 'Nouvelle commande' },
  auth.can('orders.manage') && { to: '/gestion/live', icon: 'live', label: 'Vendre en live' },
  auth.can('cash.manage') && { to: '/gestion/caisse/vente', icon: 'cash', label: 'Vente au comptoir' },
  auth.can('products.manage') && { to: '/gestion/catalogue/nouveau', icon: 'box', label: 'Ajouter un produit' },
].filter(Boolean))
const initials = computed(() => (auth.store?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase())

const guideNext = computed(() => guide.value?.steps.find((s) => s.key === guide.value.next) ?? null)
const showGuide = computed(() => guide.value && guide.value.total > 0 && guide.value.done < guide.value.total)
</script>

<template>
  <div class="space-y-5">
    <Alert v-if="welcome" tone="success">
      Votre espace marchand <strong>{{ auth.store?.name }}</strong> est créé. Il est à l'adresse
      <a :href="auth.store?.url" target="_blank" class="font-medium underline">{{ auth.store?.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</a>.
      <RouterLink to="/gestion/demarrage" class="mt-1 block font-semibold underline">Suivez le guide de démarrage, étape par étape</RouterLink>
    </Alert>

    <Alert v-if="trialDays !== null" tone="info">
      Essai gratuit de l'offre {{ subscription.plan }} : encore <strong>{{ trialDays }} jour{{ trialDays > 1 ? 's' : '' }}</strong>
      (jusqu'au {{ formatDate(subscription.trial_ends_at) }}).
    </Alert>

    <section class="flex items-center gap-3">
      <span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-700 text-sm font-extrabold text-white">{{ initials }}</span>
      <div class="min-w-0 flex-1">
        <p class="text-sm text-slate-500 first-letter:uppercase">{{ today }}</p>
        <h2 class="text-xl font-extrabold sm:text-2xl">Bonjour {{ auth.user?.name?.split(' ')[0] }} 👋</h2>
      </div>
      <RouterLink v-if="auth.can('orders.manage')" to="/gestion/commandes/nouvelle" class="btn-brand hidden lg:inline-flex"><Icon name="plus" :size="18" />Nouvelle commande</RouterLink>
    </section>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_24rem]">
      <div class="min-w-0 space-y-5">
        <!-- Vendre en live, en tête : invitation, live prêt à lancer ou live en direct -->
        <section v-if="live" class="relative overflow-hidden rounded-3xl p-5 text-white shadow-sm" :class="live.state === 'live' ? 'bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400' : 'bg-gradient-to-br from-emerald-900 to-emerald-700'">
          <template v-if="live.state === 'live'">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide">
              <span class="h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT<template v-if="liveMinutes"> · {{ liveMinutes }}</template>
            </span>
            <h3 class="mt-3 text-xl font-extrabold leading-tight">{{ live.session.title }}</h3>
            <p class="mt-2 text-4xl font-extrabold">{{ money(live.session.summary.amount) }}</p>
            <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.summary.reservations }}</b>réservation{{ live.session.summary.reservations > 1 ? 's' : '' }}</span>
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.summary.pending }}</b>en attente</span>
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.sold_out }}</b>épuisé{{ live.session.sold_out > 1 ? 's' : '' }}</span>
            </div>
            <RouterLink :to="`/gestion/live/${live.session.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-ocre-700 active:scale-[.99]">Revenir au live</RouterLink>
          </template>
          <template v-else-if="live.state === 'ready'">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />LIVE PRÊT</span>
            <h3 class="mt-3 text-xl font-extrabold leading-tight">{{ live.session.title }}</h3>
            <p class="mt-1 text-sm text-white/90">{{ live.session.items_count }} article{{ live.session.items_count > 1 ? 's' : '' }} prêt{{ live.session.items_count > 1 ? 's' : '' }} · partagez le lien avant de commencer.</p>
            <RouterLink :to="`/gestion/live/${live.session.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-emerald-800 active:scale-[.99]">Lancer le live</RouterLink>
            <button type="button" class="mt-2 block w-full text-center text-sm text-white/90 underline" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien pour WhatsApp et Facebook' }}</button>
          </template>
          <template v-else>
            <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />VENDRE EN LIVE</span>
            <h3 class="mt-3 text-xl font-extrabold leading-tight">Votre prochain live peut rapporter plus qu'une semaine de ventes.</h3>
            <p class="mt-1 text-sm text-white/90">Choisissez vos articles, partagez le lien : les clients commandent avec un code, sans vous écrire.</p>
            <RouterLink to="/gestion/live" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-emerald-800 active:scale-[.99]">Préparer un live</RouterLink>
            <RouterLink v-if="live.last" :to="`/gestion/live/${live.last.id}`" class="mt-2 block text-center text-sm text-white/90 underline">
              Dernier live : {{ live.last.orders }} commande{{ live.last.orders > 1 ? 's' : '' }} · {{ money(live.last.amount) }}
            </RouterLink>
          </template>
        </section>

        <!-- Ventes du jour, en petit -->
        <RouterLink v-if="stats?.orders" to="/gestion/commandes" class="card block p-4 hover:border-emerald-600">
          <span class="flex items-center justify-between gap-2">
            <span class="text-[15px] font-extrabold">Aujourd'hui</span>
            <span class="inline-flex items-center gap-1 text-sm font-semibold" :class="amountTrend.tone"><Icon v-if="amountTrend.icon" :name="amountTrend.icon" :size="15" />{{ amountTrend.text }}</span>
          </span>
          <span class="mt-1 flex items-baseline justify-between gap-2">
            <span class="text-2xl font-extrabold">{{ money(stats.orders.today_amount) }}</span>
            <span class="text-sm text-slate-500">{{ stats.orders.today_count }} commande{{ stats.orders.today_count > 1 ? 's' : '' }}</span>
          </span>
          <span class="mt-2 block border-t border-slate-100 pt-2 text-sm text-slate-500">Livré ce mois-ci : <strong class="text-slate-800">{{ money(stats.orders.month_revenue) }}</strong> · {{ stats.orders.month_delivered }} commande(s)</span>
        </RouterLink>

        <!-- Dehors en ce moment : argent et colis chez les livreurs et les entreprises -->
        <section v-if="stats?.couriers || stats?.carriers">
          <h3 class="mb-2 text-[17px] font-extrabold">Dehors en ce moment</h3>
          <div class="grid gap-3" :class="stats.couriers && stats.carriers ? 'sm:grid-cols-2' : ''">
            <div v-if="stats.couriers" class="card p-4">
              <RouterLink to="/gestion/caisse/livreurs" class="group block">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-600"><Icon name="cash" :size="17" class="text-emerald-700" />Argent chez mes livreurs<Icon name="chevron" :size="15" class="ml-auto text-slate-400" /></span>
                <span class="mt-1 block text-3xl font-extrabold group-hover:text-emerald-800">{{ money(stats.couriers.cash) }}</span>
                <span class="block text-sm text-slate-500">{{ stats.couriers.holders ? `chez ${stats.couriers.holders} livreur${stats.couriers.holders > 1 ? 's' : ''}, pas encore versé` : 'Tout a été versé.' }}</span>
              </RouterLink>
              <ul v-if="stats.couriers.late.length || stats.couriers.over_limit.length" class="mt-3 space-y-1.5">
                <li v-for="c in stats.couriers.late" :key="c.courier_id" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold" :class="lateTone(c.level)">
                  <Icon name="alert" :size="16" class="shrink-0" /><span class="min-w-0 flex-1">{{ c.name }} vous doit {{ money(c.held) }} {{ since(c.since) }}</span>
                </li>
                <li v-for="name in stats.couriers.over_limit.filter((n) => !stats.couriers.late.some((c) => c.name === n))" :key="name" class="flex items-center gap-2 rounded-xl bg-ocre-50 px-3 py-2 text-sm font-semibold text-ocre-800">
                  <Icon name="alert" :size="16" class="shrink-0" />{{ name }} a dépassé son plafond
                </li>
              </ul>
              <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3 text-sm font-semibold">
                <RouterLink to="/gestion/commandes?status=shipped" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-slate-700 hover:bg-slate-200"><Icon name="truck" :size="15" />{{ stats.couriers.on_the_way }} colis en route</RouterLink>
                <RouterLink to="/gestion/commandes?alert=to_bring_back" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1" :class="stats.couriers.late_returns ? 'bg-ocre-50 text-ocre-800' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"><Icon name="box" :size="15" />{{ stats.couriers.to_bring_back }} à rapporter</RouterLink>
              </div>
            </div>
            <div v-if="stats.carriers" class="card p-4">
              <RouterLink to="/gestion/caisse/a-recevoir" class="group block">
                <span class="flex items-center gap-2 text-sm font-semibold text-slate-600"><Icon name="receipt" :size="17" class="text-emerald-700" />À recevoir des entreprises<Icon name="chevron" :size="15" class="ml-auto text-slate-400" /></span>
                <span class="mt-1 block text-3xl font-extrabold group-hover:text-emerald-800">{{ money(stats.carriers.amount) }}</span>
                <span class="block text-sm text-slate-500">{{ stats.carriers.amount ? 'courses livrées, pas encore reversées' : 'Rien en attente.' }}</span>
              </RouterLink>
              <ul v-if="stats.carriers.overdue.length" class="mt-3 space-y-1.5">
                <li v-for="c in stats.carriers.overdue" :key="c.connection_id" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold" :class="lateTone(c.level)">
                  <Icon name="alert" :size="16" class="shrink-0" /><span class="min-w-0 flex-1">{{ c.carrier }} vous doit {{ money(c.amount) }} {{ since(c.since) }}</span>
                </li>
              </ul>
              <div class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3 text-sm font-semibold">
                <RouterLink to="/gestion/commandes?status=shipped" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-slate-700 hover:bg-slate-200"><Icon name="truck" :size="15" />{{ stats.carriers.on_the_way }} colis en route</RouterLink>
                <RouterLink to="/gestion/commandes?alert=to_bring_back" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1" :class="stats.carriers.late_returns ? 'bg-ocre-50 text-ocre-800' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"><Icon name="box" :size="15" />{{ stats.carriers.to_bring_back }} à rapporter</RouterLink>
              </div>
            </div>
          </div>
        </section>

        <!-- À faire maintenant : téléphone, juste sous les ventes -->
        <section v-if="todo.length" class="lg:hidden">
          <h3 class="mb-2 text-[17px] font-extrabold">À faire maintenant</h3>
          <div class="card">
            <p v-if="!pending.length" class="flex items-center gap-2 p-4 text-sm font-medium text-emerald-800"><Icon name="check" :size="18" />Tout est à jour.</p>
            <RouterLink v-for="t in (more ? pending : pendingShort)" :key="t.key" :to="t.to" class="flex items-center gap-3 border-t border-slate-100 px-4 py-3 first:border-t-0 hover:bg-slate-50">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="t.urgent ? 'bg-ocre-50 text-ocre-700' : 'bg-emerald-50 text-emerald-800'"><Icon :name="t.icon" :size="19" /></span>
              <span class="min-w-0 flex-1">
                <span class="block text-[15px] font-semibold">{{ t.label }}</span>
                <span v-if="t.hint" class="block text-sm" :class="t.urgent ? 'text-ocre-700' : 'text-slate-500'">{{ t.hint }}</span>
              </span>
              <span class="text-lg font-bold">{{ t.count }}</span>
              <Icon name="chevron" :size="16" class="text-slate-400" />
            </RouterLink>
            <button v-if="pendingHidden > 0 && !more" type="button" class="block w-full border-t border-slate-100 px-4 py-2.5 text-left text-sm font-semibold text-emerald-700" @click="toggleMore">
              Et {{ pendingHidden }} autre{{ pendingHidden > 1 ? 's' : '' }} ›
            </button>
          </div>
        </section>

        <!-- Guide de démarrage : disparaît quand l'essentiel est fait -->
        <section v-if="showGuide" class="card flex flex-wrap items-center gap-4 p-4 sm:p-5">
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-800"><Icon name="lightbulb" :size="22" /></span>
          <div class="min-w-0 flex-1">
            <p class="font-semibold">Bien démarrer : {{ guide.done }} sur {{ guide.total }} étapes faites</p>
            <p v-if="guideNext" class="text-sm text-slate-600">Prochaine étape : {{ guideNext.title }}</p>
            <div class="mt-2 h-2 max-w-xs overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-emerald-600" :style="{ width: `${(guide.done / guide.total) * 100}%` }" /></div>
          </div>
          <RouterLink :to="guideNext ? `/gestion/demarrage?etape=${guideNext.key}` : '/gestion/demarrage'" class="btn-brand w-full justify-center sm:w-auto">{{ guide.done ? 'Continuer le guide' : 'Commencer le guide' }}</RouterLink>
        </section>

        <button type="button" class="flex w-full items-center justify-center gap-1 py-1 text-sm font-semibold text-emerald-700" :aria-expanded="more" @click="toggleMore">
          {{ more ? 'Voir moins' : 'Tout voir : raccourcis, semaine, conseils' }}<Icon name="chevron" :size="15" :class="more ? '-rotate-90' : 'rotate-90'" />
        </button>

        <!-- Raccourcis -->
        <section v-if="more && shortcuts.length">
          <h3 class="mb-2 text-[17px] font-extrabold">Raccourcis</h3>
          <div class="grid grid-cols-4 gap-2.5">
            <RouterLink v-for="a in shortcuts" :key="a.to" :to="a.to" class="group text-center">
              <span class="card grid h-16 place-items-center text-emerald-700 transition group-hover:border-emerald-600"><Icon :name="a.icon" :size="24" /></span>
              <span class="mt-1.5 block text-xs font-semibold leading-tight text-slate-700">{{ a.label }}</span>
            </RouterLink>
          </div>
        </section>

        <!-- Ventes des 7 derniers jours -->
        <section v-if="more && week.length" class="card p-4 sm:p-5">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-[17px] font-extrabold">Cette semaine</h3>
            <p class="text-sm text-slate-600">Total <strong class="font-bold text-slate-900">{{ money(weekTotal) }}</strong></p>
          </div>
          <div class="relative mt-6 flex h-40 items-end gap-2 sm:gap-3" @mouseleave="activeDay = null">
            <button
              v-for="(d, i) in week"
              :key="d.date"
              type="button"
              class="group relative flex h-full flex-1 items-end justify-center focus:outline-none"
              :aria-label="`${dayLabel(d.date, true)} : ${money(d.amount)}, ${d.count} commande(s)`"
              @mouseenter="activeDay = i"
              @focus="activeDay = i"
              @blur="activeDay = null"
              @click="activeDay = activeDay === i ? null : i"
            >
              <span
                class="block w-full max-w-12 rounded-lg transition-colors"
                :class="d.amount ? (activeDay === i || i === week.length - 1 ? 'bg-emerald-700' : 'bg-emerald-200') : 'bg-slate-100'"
                :style="{ height: d.amount ? `${Math.max(6, (d.amount / weekMax) * 100)}%` : '4px' }"
              />
              <span
                v-if="activeDay === i"
                class="pointer-events-none absolute z-10 w-max rounded-lg bg-slate-900 px-2.5 py-1.5 text-left text-xs text-white shadow-lg"
                :class="i === 0 ? 'left-0' : i === week.length - 1 ? 'right-0' : 'left-1/2 -translate-x-1/2'"
                :style="{ bottom: `calc(${d.amount ? Math.max(6, (d.amount / weekMax) * 100) : 0}% + 6px)` }"
              >
                <span class="block font-medium first-letter:uppercase">{{ dayLabel(d.date, true) }}</span>
                <span class="block font-semibold">{{ money(d.amount) }} · {{ d.count }} commande(s)</span>
              </span>
            </button>
          </div>
          <div class="mt-2 flex gap-2 sm:gap-3">
            <span v-for="(d, i) in week" :key="d.date" class="flex-1 text-center text-xs" :class="i === week.length - 1 ? 'font-semibold text-slate-900' : 'text-slate-500'">
              {{ i === week.length - 1 ? 'auj.' : dayLabel(d.date) }}
            </span>
          </div>
        </section>
      </div>

      <!-- À faire maintenant : ordinateur, en colonne à droite (tout, même à zéro) -->
      <section v-if="todo.length" class="card hidden self-start p-4 sm:p-5 lg:block">
        <h3 class="text-[17px] font-extrabold">À faire maintenant</h3>
        <p v-if="!pending.length" class="mt-3 flex items-center gap-2 text-sm text-emerald-800"><Icon name="check" :size="18" />Tout est à jour.</p>
        <ul class="mt-2 divide-y divide-slate-100">
          <li v-for="t in todo" :key="t.key">
            <RouterLink :to="t.to" class="-mx-2 flex items-center gap-3 rounded-xl px-2 py-3 hover:bg-slate-50">
              <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl" :class="t.urgent ? 'bg-ocre-50 text-ocre-700' : t.count ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'">
                <Icon :name="t.icon" :size="19" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold" :class="t.count ? 'text-slate-900' : 'text-slate-500'">{{ t.label }}</span>
                <span v-if="t.hint" class="block text-xs" :class="t.urgent ? 'text-ocre-700' : 'text-slate-500'">{{ t.hint }}</span>
              </span>
              <span class="text-lg font-bold" :class="t.count ? 'text-slate-900' : 'text-slate-400'">{{ t.count }}</span>
              <Icon name="chevron" :size="16" class="text-slate-400" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>

    <section v-if="more && stats?.tips?.length" class="card space-y-2 p-4 sm:p-5">
      <h3 class="flex items-center gap-2 font-semibold"><Icon name="lightbulb" :size="18" class="text-amber-600" />Conseils du jour</h3>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li v-for="(tip, i) in stats.tips" :key="i">{{ tip }}</li>
      </ol>
      <p class="text-xs text-slate-500">Tirés de vos chiffres : ventes, stock, confirmations et livraisons. Ils sont aussi dans le rapport du soir.</p>
    </section>

  </div>
</template>
