<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import ColorIcon from '../../components/ColorIcon.vue'
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

// Le reste (semaine, conseils) derrière « Tout voir » ; le choix est retenu sur cet appareil
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
  // WhatsApp bloqué (ou en partie) par Meta
  // (bloqué : en tête de liste, tous les messages aux clients sont arrêtés)
  if (s.whatsapp_meta) {
    const blocked = s.whatsapp_meta.state === 'blocked'
    items[blocked ? 'unshift' : 'push']({ key: 'whatsapp_meta', icon: 'messages', label: blocked ? 'Meta bloque vos messages WhatsApp' : 'WhatsApp en partie bloqué par Meta',
      count: 1, to: '/gestion/whatsapp', hint: s.whatsapp_meta.reason ?? '', urgent: true })
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
// Argent à récupérer : livreurs et entreprises réunis (total, alertes, colis en route, à rapporter), carte repliée
const outsideOpen = ref(false)
const outside = computed(() => {
  const c = stats.value?.couriers
  const e = stats.value?.carriers
  const parts = [c && `${money(c.cash)} chez vos livreurs`, e && `${money(e.amount)} chez les entreprises`].filter(Boolean)
  return {
    total: (c?.cash ?? 0) + (e?.amount ?? 0),
    summary: parts.join(' · '),
    onTheWay: (c?.on_the_way ?? 0) + (e?.on_the_way ?? 0),
    toBringBack: (c?.to_bring_back ?? 0) + (e?.to_bring_back ?? 0),
    lateReturns: !!(c?.late_returns || e?.late_returns),
  }
})
const outsideAlerts = computed(() => {
  const c = stats.value?.couriers
  const e = stats.value?.carriers
  return [
    ...(c?.late ?? []).map((x) => ({ key: `c${x.courier_id}`, tone: lateTone(x.level), text: `${x.name} vous doit ${money(x.held)} ${since(x.since)}` })),
    ...(c?.over_limit ?? []).filter((n) => !(c?.late ?? []).some((x) => x.name === n)).map((n) => ({ key: `o${n}`, tone: 'bg-ocre-50 text-ocre-800', text: `${n} a dépassé son plafond` })),
    ...(e?.overdue ?? []).map((x) => ({ key: `e${x.connection_id}`, tone: lateTone(x.level), text: `${x.carrier} vous doit ${money(x.amount)} ${since(x.since)}` })),
  ]
})
const pending = computed(() => todo.value.filter((t) => t.count > 0 || t.hint))
// Téléphone : l'urgent d'abord, trois lignes au plus
const pendingShort = computed(() => [...pending.value].sort((a, b) => Number(!!b.urgent) - Number(!!a.urgent)).slice(0, 3))
const pendingHidden = computed(() => pending.value.length - pendingShort.value.length)

// Guide de démarrage : avancement et prochaine étape (le détail est sur sa page)
const guide = ref(null)
onMounted(async () => {
  guide.value = (await http.get('/setup-guide').catch(() => ({ data: { data: null } }))).data.data
})
// Icône en couleur de chaque ligne « À faire maintenant » (les mêmes que dans Jibiat)
const TODO_COLORS = { live: 'live', phone: 'telephone', truck: 'colis', box: 'colis', alert: 'aregler', cash: 'moto', receipt: 'camion', messages: 'whatsapp' }
const todoColor = (t) => (t.key === 'stock' ? 'stock' : TODO_COLORS[t.icon] ?? 'colis')

// Actions rapides, toujours visibles (selon les droits)
const quick = computed(() => [
  auth.can('orders.manage') && { to: '/gestion/commandes/nouvelle', color: 'panier', label: 'Nouvelle commande' },
  auth.can('orders.manage') && { to: '/gestion/commandes/nouvelle?coller=1', color: 'coller', label: 'Coller WhatsApp' },
  auth.can('products.manage') && { to: '/gestion/catalogue/nouveau', color: 'produit', label: 'Ajouter un produit' },
  auth.can('cash.manage') && { to: '/gestion/caisse/vente', color: 'caisse', label: 'Vente comptoir' },
].filter(Boolean))

// Ventes du jour par canal : une barre par canal, le plus fort en premier
const CHANNEL_COLORS = { whatsapp: 'whatsapp', facebook: 'partage', instagram: 'partage', tiktok: 'live', live: 'live', online_store: 'boutique', in_store: 'caisse', phone: 'telephone', other: 'panier' }
const channels = computed(() => stats.value?.channels ?? [])
const channelMax = computed(() => Math.max(...channels.value.map((c) => c.amount), 1))

// Boutique en ligne : l'adresse lisible et le partage sur WhatsApp
const shop = computed(() => stats.value?.shop ?? null)
const shopHost = computed(() => (shop.value?.url ?? '').replace(/^https?:\/\//, '').replace(/\/$/, ''))
const shopShare = computed(() => `https://wa.me/?text=${encodeURIComponent(`Découvrez ma boutique ${auth.store?.name ?? ''} : ${shop.value?.url ?? ''}`)}`)

// De l'argent à aller chercher : paniers laissés, clientes fidèles à relancer, promotion qui se termine
const endsIn = (at) => {
  const days = Math.ceil((new Date(at) - Date.now()) / 86400000)
  return days <= 1 ? 'se termine aujourd\'hui' : `encore ${days} jours`
}
const chances = computed(() => {
  const s = stats.value
  if (!s) return []
  return [
    s.shop?.abandoned && { key: 'carts', color: 'panier', to: '/gestion/paniers-abandonnes', label: 'Paniers abandonnés', hint: `${s.shop.abandoned} cliente${s.shop.abandoned > 1 ? 's ont' : ' a'} laissé des articles : relancez-les sur WhatsApp`, action: 'Relancer' },
    s.to_relaunch && { key: 'relaunch', color: 'clients', to: '/gestion/clients?filtre=to_relaunch', label: 'Clientes fidèles à relancer', hint: `${s.to_relaunch} n'ont pas commandé depuis 30 jours`, action: 'Écrire' },
    s.promotions?.active && { key: 'promo', color: 'promo', to: '/gestion/marketing', label: s.promotions.active > 1 ? `${s.promotions.active} promotions en cours` : 'Promotion en cours', hint: s.promotions.ending ? `${s.promotions.ending.title} : ${endsIn(s.promotions.ending.ends_at)}` : 'sans date de fin' },
  ].filter(Boolean)
})

const guideNext = computed(() => guide.value?.steps.find((s) => s.key === guide.value.next) ?? null)
const showGuide = computed(() => guide.value && guide.value.total > 0 && guide.value.done < guide.value.total)
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="welcome" tone="success">
      Votre espace marchand <strong>{{ auth.store?.name }}</strong> est créé. Il est à l'adresse
      <a :href="auth.store?.url" target="_blank" class="font-medium underline">{{ auth.store?.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</a>.
      <RouterLink to="/gestion/demarrage" class="mt-1 block font-semibold underline">Suivez le guide de démarrage, étape par étape</RouterLink>
    </Alert>

    <Alert v-if="trialDays !== null" tone="info">
      Essai gratuit de l'offre {{ subscription.plan }} : encore <strong>{{ trialDays }} jour{{ trialDays > 1 ? 's' : '' }}</strong>
      (jusqu'au {{ formatDate(subscription.trial_ends_at) }}).
    </Alert>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_24rem]">
      <div class="min-w-0 space-y-4">
        <!-- Vendre en live, en tête : invitation, live prêt à lancer ou live en direct (rose et violet, la couleur du live) -->
        <section v-if="live" class="relative overflow-hidden rounded-[24px] p-5 text-white" :class="live.state === 'live' ? 'bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400' : 'bg-live-grad'">
          <svg class="pointer-events-none absolute -right-8 -top-8 opacity-[.16]" width="180" height="180" viewBox="0 0 48 48" aria-hidden="true"><path d="M15.5 15.5a12 12 0 0 0 0 17M32.5 15.5a12 12 0 0 1 0 17M9.5 9.5a20 20 0 0 0 0 29M38.5 9.5a20 20 0 0 1 0 29" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round" /><circle cx="24" cy="24" r="6" fill="#fff" /></svg>
          <template v-if="live.state === 'live'">
            <span class="inline-flex items-center gap-2 whitespace-nowrap rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide">
              <span class="h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT SUR VOS RÉSEAUX
            </span>
            <h3 class="mt-3 text-xl font-extrabold leading-tight">{{ live.session.title }}<span v-if="liveMinutes" class="ml-2 text-sm font-semibold text-white/85">depuis {{ liveMinutes.replace(/^depuis /, '') }}</span></h3>
            <p class="mt-2 text-4xl font-extrabold">{{ money(live.session.summary.amount) }}</p>
            <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.summary.reservations }}</b>réservation{{ live.session.summary.reservations > 1 ? 's' : '' }}</span>
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.summary.pending }}</b>en attente</span>
              <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ live.session.sold_out }}</b>épuisé{{ live.session.sold_out > 1 ? 's' : '' }}</span>
            </div>
            <RouterLink :to="`/gestion/live/${live.session.id}`" class="relative mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-ocre-700 active:scale-[.99]">Suivre les commandes du live</RouterLink>
          </template>
          <template v-else-if="live.state === 'ready'">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />LIVE PRÊT</span>
            <h3 class="mt-3 text-xl font-extrabold leading-tight">{{ live.session.title }}</h3>
            <p class="mt-1 text-sm text-white/90">{{ live.session.items_count }} article{{ live.session.items_count > 1 ? 's' : '' }} prêt{{ live.session.items_count > 1 ? 's' : '' }}. <b>Passez à l'antenne sur TikTok ou Facebook, puis touchez « Je suis en live ».</b></p>
            <RouterLink :to="`/gestion/live/${live.session.id}`" class="relative mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-[#c02a4e] active:scale-[.99]">Je suis en live</RouterLink>
            <button type="button" class="relative mt-2 block w-full text-center text-sm text-white/90 underline" @click="copyLink">{{ copied ? 'Lien copié ✓' : 'Copier le lien pour WhatsApp et Facebook' }}</button>
          </template>
          <template v-else>
            <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold tracking-wide"><Icon name="live" :size="14" />VOTRE LIVE TIKTOK, FACEBOOK…</span>
            <h3 class="mt-2 text-lg font-extrabold leading-tight">Préparez ici, vendez en live sur vos réseaux</h3>
            <!-- Où se passe quoi, en trois mots : c'est l'explication -->
            <ol class="relative mt-3 grid grid-cols-3 gap-1.5 text-center text-[13px] font-extrabold leading-tight">
              <li class="rounded-xl bg-white/25 px-1 py-2"><span class="block text-lg leading-none">📝</span><span class="mt-1 block">Articles prêts ici</span></li>
              <li class="rounded-xl bg-white/25 px-1 py-2"><span class="block text-lg leading-none">📱</span><span class="mt-1 block">Live sur TikTok, Facebook</span></li>
              <li class="rounded-xl bg-white/25 px-1 py-2"><span class="block text-lg leading-none">🛒</span><span class="mt-1 block">Commandes ici</span></li>
            </ol>
            <p class="mt-2 text-[13px] font-semibold">Les clientes envoient le code (A1, A2…) sur WhatsApp : la commande se crée toute seule.</p>
            <div class="relative mt-3 flex items-center gap-2">
              <RouterLink to="/gestion/live" class="flex-1 rounded-2xl bg-white py-2.5 text-center text-base font-extrabold text-[#c02a4e] active:scale-[.99]">Préparer un live</RouterLink>
              <RouterLink v-if="live.last" :to="`/gestion/live/${live.last.id}`" class="rounded-2xl bg-white/20 px-3 py-2 text-center text-xs font-bold leading-tight">Dernier live<span class="block text-sm">{{ money(live.last.amount) }}</span></RouterLink>
            </div>
          </template>
        </section>

        <!-- Argent à récupérer, juste sous le live : le total chez les livreurs et les entreprises ; on déplie pour le détail -->
        <section v-if="stats?.couriers || stats?.carriers" class="card overflow-hidden">
          <button type="button" class="flex w-full items-center gap-3 p-4 text-left" :aria-expanded="outsideOpen" @click="outsideOpen = !outsideOpen">
            <ColorIcon name="caisse" :size="42" />
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-semibold text-slate-600">Argent à récupérer</span>
              <span class="block text-2xl font-extrabold">{{ money(outside.total) }}</span>
              <span class="block text-sm text-slate-500">{{ outside.summary }}</span>
            </span>
            <span v-if="outsideAlerts.length" class="shrink-0 rounded-full bg-red-50 px-2.5 py-1 text-xs font-bold text-red-700">{{ outsideAlerts.length }} en retard</span>
            <Icon name="chevron" :size="18" class="shrink-0 text-slate-400 transition" :class="outsideOpen ? '-rotate-90' : 'rotate-90'" />
          </button>
          <div v-if="outsideOpen" class="border-t border-slate-100 px-4 pb-4 pt-3">
            <div class="grid gap-3" :class="stats.couriers && stats.carriers ? 'sm:grid-cols-2' : ''">
              <RouterLink v-if="stats.couriers" to="/gestion/caisse/livreurs" class="group flex items-center gap-3 rounded-xl bg-slate-50 p-3">
                <ColorIcon name="moto" :size="36" />
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-2 text-sm font-semibold text-slate-600">Chez mes livreurs<Icon name="chevron" :size="15" class="ml-auto text-slate-400" /></span>
                  <span class="block text-xl font-extrabold group-hover:text-emerald-800">{{ money(stats.couriers.cash) }}</span>
                  <span class="block text-xs text-slate-500">{{ stats.couriers.holders ? `chez ${stats.couriers.holders} livreur${stats.couriers.holders > 1 ? 's' : ''}, pas encore versé` : 'Tout a été versé.' }}</span>
                </span>
              </RouterLink>
              <RouterLink v-if="stats.carriers" to="/gestion/caisse/a-recevoir" class="group flex items-center gap-3 rounded-xl bg-slate-50 p-3">
                <ColorIcon name="camion" :size="36" />
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-2 text-sm font-semibold text-slate-600">Chez les entreprises de livraison<Icon name="chevron" :size="15" class="ml-auto text-slate-400" /></span>
                  <span class="block text-xl font-extrabold group-hover:text-emerald-800">{{ money(stats.carriers.amount) }}</span>
                  <span class="block text-xs text-slate-500">{{ stats.carriers.amount ? 'courses livrées, pas encore reversées' : 'Rien en attente.' }}</span>
                </span>
              </RouterLink>
            </div>
            <ul v-if="outsideAlerts.length" class="mt-3 space-y-1.5">
              <li v-for="a in outsideAlerts" :key="a.key" class="flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold" :class="a.tone">
                <Icon name="alert" :size="16" class="shrink-0" /><span class="min-w-0 flex-1">{{ a.text }}</span>
              </li>
            </ul>
            <div class="mt-3 flex flex-wrap gap-2 text-sm font-semibold">
              <RouterLink to="/gestion/commandes?status=shipped" class="inline-flex items-center gap-1.5 rounded-full bg-[#e8f1ff] px-3 py-1 text-[#1d5bc7]"><Icon name="truck" :size="15" />{{ outside.onTheWay }} colis en route</RouterLink>
              <RouterLink to="/gestion/commandes?alert=to_bring_back" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1" :class="outside.lateReturns ? 'bg-ocre-50 text-ocre-800' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"><Icon name="box" :size="15" />{{ outside.toBringBack }} à rapporter</RouterLink>
            </div>
          </div>
        </section>

        <!-- Actions rapides : les gestes de commerçant -->
        <section v-if="quick.length" class="grid gap-2" :class="['grid-cols-1', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3', 'grid-cols-4'][quick.length]">
          <RouterLink v-for="a in quick" :key="a.to" :to="a.to" class="flex flex-col items-center gap-1.5 rounded-[20px] bg-white px-1 py-3 text-center shadow-soft transition active:scale-95 hover:ring-1 hover:ring-emerald-600">
            <ColorIcon :name="a.color" :size="44" />
            <span class="text-xs font-semibold leading-tight text-slate-700">{{ a.label }}</span>
          </RouterLink>
        </section>

        <!-- Ventes du jour, par canal : on vend partout -->
        <RouterLink v-if="stats?.orders" to="/gestion/commandes" class="card block p-4 hover:border-emerald-600">
          <span class="flex items-baseline justify-between gap-2">
            <span class="text-[16px] font-extrabold">Mes ventes du jour</span>
            <span class="text-2xl font-extrabold">{{ money(stats.orders.today_amount) }}</span>
          </span>
          <span class="mt-0.5 flex items-center justify-between gap-2 text-sm">
            <span class="text-slate-500">{{ stats.orders.today_count }} commande{{ stats.orders.today_count > 1 ? 's' : '' }}<template v-if="channels.length > 1"> sur {{ channels.length }} canaux</template></span>
            <span class="inline-flex items-center gap-1 font-semibold" :class="amountTrend.tone"><Icon v-if="amountTrend.icon" :name="amountTrend.icon" :size="15" />{{ amountTrend.text }}</span>
          </span>
          <span v-if="channels.length" class="mt-3 block space-y-2">
            <span v-for="c in channels" :key="c.source" class="flex items-center gap-2.5">
              <ColorIcon :name="CHANNEL_COLORS[c.source] ?? 'panier'" :size="30" />
              <span class="w-28 shrink-0 truncate text-sm font-semibold text-slate-700">{{ c.label }}</span>
              <span class="h-2 min-w-0 flex-1 rounded-full bg-slate-100"><span class="block h-full rounded-full bg-gradient-to-r from-[#8c95ff] to-[#545fe0]" :style="{ width: `${Math.max(4, (c.amount / channelMax) * 100)}%` }" /></span>
              <span class="w-[78px] shrink-0 text-right text-sm font-bold">{{ money(c.amount) }}</span>
            </span>
          </span>
          <span class="mt-3 block border-t border-slate-100 pt-2 text-sm text-slate-500">Livré ce mois-ci : <strong class="text-slate-800">{{ money(stats.orders.month_revenue) }}</strong> · {{ stats.orders.month_delivered }} commande(s)</span>
        </RouterLink>

        <!-- À faire maintenant : téléphone, juste sous les ventes -->
        <section v-if="todo.length" class="lg:hidden">
          <h3 class="mb-2 text-[17px] font-extrabold">À faire maintenant</h3>
          <div class="card">
            <p v-if="!pending.length" class="flex items-center gap-2 p-4 text-sm font-medium text-emerald-800"><ColorIcon name="livre" :size="26" :tile="false" />Tout est à jour.</p>
            <RouterLink v-for="t in (more ? pending : pendingShort)" :key="t.key" :to="t.to" class="flex items-center gap-3 border-t border-slate-100 px-4 py-3 first:border-t-0 hover:bg-slate-50">
              <ColorIcon :name="todoColor(t)" :size="40" />
              <span class="min-w-0 flex-1">
                <span class="block text-[15px] font-semibold">{{ t.label }}</span>
                <span v-if="t.hint" class="block text-sm" :class="t.urgent ? 'text-ocre-700' : 'text-slate-500'">{{ t.hint }}</span>
              </span>
              <span class="grid h-7 min-w-7 place-items-center rounded-full px-2 text-sm font-bold" :class="t.urgent ? 'bg-ocre-50 text-ocre-800' : 'bg-emerald-50 text-emerald-800'">{{ t.count }}</span>
            </RouterLink>
            <button v-if="pendingHidden > 0 && !more" type="button" class="block w-full border-t border-slate-100 px-4 py-2.5 text-left text-sm font-semibold text-emerald-700" @click="toggleMore">
              Et {{ pendingHidden }} autre{{ pendingHidden > 1 ? 's' : '' }} ›
            </button>
          </div>
        </section>

        <!-- Ma boutique en ligne : son adresse, ses commandes, les paniers laissés -->
        <section v-if="shop" class="card p-4">
          <div class="flex items-center gap-3">
            <ColorIcon name="boutique" :size="46" />
            <div class="min-w-0 flex-1">
              <p class="text-[15.5px] font-bold">Ma boutique en ligne</p>
              <a :href="shop.url" target="_blank" rel="noopener" class="block truncate text-sm text-slate-500 hover:text-emerald-700">{{ shopHost }}</a>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-2xl bg-[#f1ecff] py-2.5"><p class="text-xl font-extrabold text-[#5b3fc4]">{{ shop.today }}</p><p class="text-xs text-slate-600">commande{{ shop.today > 1 ? 's' : '' }} aujourd'hui</p></div>
            <div class="rounded-2xl bg-[#eceeff] py-2.5"><p class="text-xl font-extrabold text-[#3a43b5]">{{ shop.month }}</p><p class="text-xs text-slate-600">ce mois-ci</p></div>
            <RouterLink to="/gestion/paniers-abandonnes" class="rounded-2xl bg-[#ffecef] py-2.5"><p class="text-xl font-extrabold text-[#c02a4e]">{{ shop.abandoned }}</p><p class="text-xs text-slate-600">panier{{ shop.abandoned > 1 ? 's' : '' }} laissé{{ shop.abandoned > 1 ? 's' : '' }}</p></RouterLink>
          </div>
          <div class="mt-3 flex gap-2">
            <a :href="shopShare" target="_blank" rel="noopener" class="btn-brand flex-1 justify-center"><Icon name="share" :size="17" />Partager sur WhatsApp</a>
            <RouterLink v-if="auth.can('store.manage')" to="/gestion/boutique-en-ligne" class="btn-secondary">Modifier</RouterLink>
          </div>
        </section>

        <!-- De l'argent à aller chercher -->
        <section v-if="chances.length">
          <h3 class="mb-2 text-[17px] font-extrabold">De l'argent à aller chercher</h3>
          <div class="card divide-y divide-slate-100">
            <RouterLink v-for="c in chances" :key="c.key" :to="c.to" class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50">
              <ColorIcon :name="c.color" :size="42" />
              <span class="min-w-0 flex-1"><span class="block text-[15px] font-semibold">{{ c.label }}</span><span class="block text-sm text-slate-500">{{ c.hint }}</span></span>
              <span v-if="c.action" class="shrink-0 rounded-xl bg-emerald-50 px-3 py-1.5 text-sm font-bold text-emerald-800">{{ c.action }}</span>
              <Icon v-else name="chevron" :size="16" class="text-slate-400" />
            </RouterLink>
          </div>
        </section>

        <!-- Le bénéfice du mois (pour qui voit les finances) -->
        <RouterLink v-if="stats?.profit" to="/gestion/statistiques" class="card flex items-center gap-3 p-4 hover:border-emerald-600">
          <ColorIcon name="stats" :size="46" />
          <span class="min-w-0 flex-1">
            <span class="block text-sm text-slate-500">Mon bénéfice du mois</span>
            <span class="block text-2xl font-extrabold" :class="stats.profit.profit < 0 ? 'text-red-700' : 'text-emerald-800'">{{ money(stats.profit.profit) }}</span>
            <span v-if="stats.profit.per_delivered !== null" class="block text-sm text-slate-500">{{ money(stats.profit.per_delivered) }} gagnés par commande livrée</span>
          </span>
          <Icon name="chevron" :size="16" class="text-slate-400" />
        </RouterLink>

        <!-- Guide de démarrage : disparaît quand l'essentiel est fait -->
        <section v-if="showGuide" class="card flex flex-wrap items-center gap-4 p-4 sm:p-5">
          <ColorIcon name="aide" :size="44" />
          <div class="min-w-0 flex-1">
            <p class="font-semibold">Bien démarrer : {{ guide.done }} sur {{ guide.total }} étapes faites</p>
            <p v-if="guideNext" class="text-sm text-slate-600">Prochaine étape : {{ guideNext.title }}</p>
            <div class="mt-2 h-2 max-w-xs overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-[#5ed39c] to-[#1c9262]" :style="{ width: `${(guide.done / guide.total) * 100}%` }" /></div>
          </div>
          <RouterLink :to="guideNext ? `/gestion/demarrage?etape=${guideNext.key}` : '/gestion/demarrage'" class="btn-brand w-full justify-center sm:w-auto">{{ guide.done ? 'Continuer le guide' : 'Commencer le guide' }}</RouterLink>
        </section>

        <button type="button" class="flex w-full items-center justify-center gap-1 py-1 text-sm font-semibold text-emerald-700" :aria-expanded="more" @click="toggleMore">
          {{ more ? 'Voir moins' : 'Tout voir : semaine, conseils' }}<Icon name="chevron" :size="15" :class="more ? '-rotate-90' : 'rotate-90'" />
        </button>

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
        <p v-if="!pending.length" class="mt-3 flex items-center gap-2 text-sm text-emerald-800"><ColorIcon name="livre" :size="24" :tile="false" />Tout est à jour.</p>
        <ul class="mt-2 divide-y divide-slate-100">
          <li v-for="t in todo" :key="t.key">
            <RouterLink :to="t.to" class="-mx-2 flex items-center gap-3 rounded-xl px-2 py-3 hover:bg-slate-50">
              <ColorIcon :name="todoColor(t)" :size="38" :class="t.count || t.hint ? '' : 'opacity-50'" />
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
      <h3 class="flex items-center gap-2 font-semibold"><ColorIcon name="aide" :size="30" />Conseils du jour</h3>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li v-for="(tip, i) in stats.tips" :key="i">{{ tip }}</li>
      </ol>
      <p class="text-xs text-slate-500">Tirés de vos chiffres : ventes, stock, confirmations et livraisons. Ils sont aussi dans le rapport du soir.</p>
    </section>

  </div>
</template>
