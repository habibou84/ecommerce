<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../components/Alert.vue'
import Icon from '../../components/Icon.vue'
import http from '../../lib/http'
import { daysUntil, formatDate, money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()

const stats = ref(null)
onMounted(async () => {
  stats.value = (await http.get('/dashboard')).data.data
})

const welcome = computed(() => route.query.bienvenue === '1')
const subscription = computed(() => auth.store?.subscription)
// L'abonnement ne concerne que le propriétaire
const trialDays = computed(() =>
  auth.can('billing.manage') && subscription.value?.status === 'trialing' ? daysUntil(subscription.value.trial_ends_at) : null,
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
  if (s.unread_messages !== undefined) items.push({ key: 'messages', icon: 'messages', label: 'Messages non lus', count: s.unread_messages, to: '/gestion/messages' })
  if (s.stock) items.push({ key: 'stock', icon: 'box', label: 'Articles épuisés', count: s.stock.out, to: '/gestion/stock', hint: s.stock.low ? `et ${s.stock.low} en stock bas` : '', urgent: s.stock.out > 0 })
  return items
})
const pending = computed(() => todo.value.filter((t) => t.count > 0 || t.hint))

// Premiers pas, selon les droits de la personne
const steps = computed(() => [
  ...(auth.can('store.manage')
    ? [
        { title: 'Compléter les informations de la boutique', text: 'Slogan, numéro WhatsApp des clients, adresse.', to: '/gestion/parametres', done: !!auth.store?.tagline },
        { title: 'Vérifier vos frais de livraison', text: 'Une zone « Abidjan » à 1 500 F est créée par défaut : adaptez-la par quartier.', to: '/gestion/livraisons/zones' },
      ]
    : []),
  ...(auth.can('products.manage')
    ? [{ title: 'Ajouter vos produits et leurs variantes', text: 'Tailles, couleurs, photos, stock. Ou importez un fichier Excel.', to: '/gestion/catalogue/nouveau' }]
    : []),
  ...(auth.can('orders.manage')
    ? [{ title: 'Saisir votre première commande', text: 'Collez le message WhatsApp du client : tout se remplit.', to: '/gestion/commandes/nouvelle', done: weekTotal.value > 0 || (stats.value?.orders?.month_delivered ?? 0) > 0 }]
    : []),
  ...(auth.can('team.manage') ? [{ title: 'Inviter votre équipe', text: 'Gérant, agent de confirmation, préparateur, caissier.', to: '/gestion/equipe' }] : []),
  ...(auth.can('store.manage')
    ? [{ title: 'Ouvrir votre boutique en ligne', text: 'Choisissez un thème, ajoutez votre logo et ouvrez : vos clients commandent et paient à la livraison.', to: '/gestion/boutique-en-ligne', done: !!auth.store?.storefront_enabled }]
    : []),
])

// « Pour bien démarrer » : repliable ; masqué pour de bon quand tout est fait ou quand on le demande (gardé sur cet appareil)
const startKey = computed(() => `jm-demarrer:${auth.store?.id}`)
function remembered(key) {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}
function remember(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // stockage refusé : le réglage dure le temps de la page
  }
}
const startState = ref(remembered(startKey.value)) // null | 'closed' | 'hidden'
const startOpen = computed(() => (startState.value ? startState.value === 'open' : weekTotal.value === 0))
const showStart = computed(() => steps.value.length && startState.value !== 'hidden' && !steps.value.every((s) => s.done))
const doneCount = computed(() => steps.value.filter((s) => s.done).length)
function setStart(value) {
  startState.value = value
  remember(startKey.value, value)
}
</script>

<template>
  <div class="space-y-5">
    <Alert v-if="welcome" tone="success">
      Votre boutique <strong>{{ auth.store?.name }}</strong> est créée. Elle est à l'adresse
      <a :href="auth.store?.url" target="_blank" class="font-medium underline">{{ auth.store?.url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</a>.
    </Alert>

    <Alert v-if="trialDays !== null" tone="info">
      Essai gratuit de l'offre {{ subscription.plan }} : encore <strong>{{ trialDays }} jour{{ trialDays > 1 ? 's' : '' }}</strong>
      (jusqu'au {{ formatDate(subscription.trial_ends_at) }}).
    </Alert>

    <section class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold sm:text-2xl">Bonjour {{ auth.user?.name?.split(' ')[0] }}</h2>
        <p class="text-sm text-slate-500 first-letter:uppercase">{{ today }}</p>
      </div>
      <RouterLink v-if="auth.can('orders.manage')" to="/gestion/commandes/nouvelle" class="btn-brand hidden lg:inline-flex"><Icon name="plus" :size="18" />Nouvelle commande</RouterLink>
    </section>

    <!-- Chiffres du jour -->
    <section v-if="stats?.orders" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <div class="card p-4">
        <p class="text-sm text-slate-600">Ventes du jour</p>
        <p class="mt-1 text-xl font-semibold tabular-nums sm:text-3xl">{{ money(stats.orders.today_amount) }}</p>
        <p class="mt-1 flex items-start gap-1 text-xs sm:text-sm" :class="amountTrend.tone">
          <Icon v-if="amountTrend.icon" :name="amountTrend.icon" :size="16" class="mt-px" />{{ amountTrend.text }}
        </p>
      </div>
      <RouterLink to="/gestion/commandes" class="card p-4 transition hover:border-emerald-600">
        <p class="text-sm text-slate-600">Commandes du jour</p>
        <p class="mt-1 text-xl font-semibold tabular-nums sm:text-3xl">{{ stats.orders.today_count }}</p>
        <p class="mt-1 flex items-start gap-1 text-xs sm:text-sm" :class="countTrend.tone">
          <Icon v-if="countTrend.icon" :name="countTrend.icon" :size="16" class="mt-px" />{{ countTrend.text }}
        </p>
      </RouterLink>
      <div class="card col-span-2 p-4 sm:col-span-1">
        <p class="text-sm text-slate-600">Livré ce mois-ci</p>
        <p class="mt-1 text-xl font-semibold tabular-nums sm:text-3xl">{{ money(stats.orders.month_revenue) }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ stats.orders.month_delivered }} commande(s) livrée(s)</p>
      </div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
      <!-- Ventes des 7 derniers jours -->
      <section v-if="week.length" class="card p-4 sm:p-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="font-semibold">Ventes des 7 derniers jours</h3>
          <p class="text-sm text-slate-600">Total <strong class="tabular-nums text-slate-900">{{ money(weekTotal) }}</strong></p>
        </div>
        <div class="relative mt-6 flex h-44 items-end gap-2 border-b border-slate-200 sm:gap-3" @mouseleave="activeDay = null">
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
              class="block w-full max-w-12 rounded-t transition-colors"
              :class="d.amount ? (activeDay === i ? 'bg-emerald-800' : 'bg-emerald-600') : 'bg-slate-200'"
              :style="{ height: d.amount ? `${Math.max(4, (d.amount / weekMax) * 100)}%` : '2px' }"
            />
            <span
              v-if="activeDay === i"
              class="pointer-events-none absolute z-10 w-max rounded-lg bg-slate-900 px-2.5 py-1.5 text-left text-xs text-white shadow-lg"
              :class="i === 0 ? 'left-0' : i === week.length - 1 ? 'right-0' : 'left-1/2 -translate-x-1/2'"
              :style="{ bottom: `calc(${d.amount ? Math.max(4, (d.amount / weekMax) * 100) : 0}% + 6px)` }"
            >
              <span class="block font-medium first-letter:uppercase">{{ dayLabel(d.date, true) }}</span>
              <span class="block tabular-nums">{{ money(d.amount) }} · {{ d.count }} commande(s)</span>
            </span>
          </button>
        </div>
        <div class="mt-2 flex gap-2 sm:gap-3">
          <span v-for="(d, i) in week" :key="d.date" class="flex-1 text-center text-xs" :class="i === week.length - 1 ? 'font-semibold text-slate-900' : 'text-slate-500'">
            {{ i === week.length - 1 ? 'auj.' : dayLabel(d.date) }}
          </span>
        </div>
      </section>

      <!-- À faire maintenant -->
      <section v-if="todo.length" class="card p-4 sm:p-5">
        <h3 class="font-semibold">À faire maintenant</h3>
        <p v-if="!pending.length" class="mt-3 flex items-center gap-2 text-sm text-emerald-800">
          <Icon name="check" :size="18" />Tout est à jour.
        </p>
        <ul class="mt-2 divide-y divide-slate-100">
          <li v-for="t in todo" :key="t.key">
            <RouterLink :to="t.to" class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-3 hover:bg-slate-50">
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full" :class="t.urgent ? 'bg-amber-100 text-amber-800' : t.count ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-500'">
                <Icon :name="t.icon" :size="18" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium" :class="t.count ? 'text-slate-900' : 'text-slate-500'">{{ t.label }}</span>
                <span v-if="t.hint" class="block text-xs" :class="t.urgent ? 'text-amber-800' : 'text-slate-500'">{{ t.hint }}</span>
              </span>
              <span class="text-lg font-semibold tabular-nums" :class="t.count ? 'text-slate-900' : 'text-slate-400'">{{ t.count }}</span>
              <Icon name="chevron" :size="16" class="text-slate-400" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </div>

    <section v-if="stats?.tips?.length" class="card space-y-2 p-4 sm:p-5">
      <h3 class="flex items-center gap-2 font-semibold"><Icon name="lightbulb" :size="18" class="text-amber-600" />Conseils du jour</h3>
      <ol class="list-decimal space-y-1 pl-5 text-sm text-slate-700">
        <li v-for="(tip, i) in stats.tips" :key="i">{{ tip }}</li>
      </ol>
      <p class="text-xs text-slate-500">Tirés de vos chiffres : ventes, stock, confirmations et livraisons. Ils sont aussi dans le rapport du soir.</p>
    </section>

    <!-- Pour bien démarrer : repliable, disparaît quand tout est fait -->
    <section v-if="showStart" class="card">
      <button type="button" class="flex w-full items-center gap-3 p-4 text-left sm:p-5" :aria-expanded="startOpen" @click="setStart(startOpen ? 'closed' : 'open')">
        <span class="min-w-0 flex-1">
          <span class="block font-semibold">Pour bien démarrer</span>
          <span class="block text-sm text-slate-500">{{ doneCount }} sur {{ steps.length }} étape(s) faite(s)</span>
        </span>
        <span class="hidden h-2 w-24 overflow-hidden rounded-full bg-slate-100 sm:block"><span class="block h-full rounded-full bg-emerald-600" :style="{ width: `${(doneCount / steps.length) * 100}%` }" /></span>
        <Icon name="chevron" :size="18" class="text-slate-400 transition" :class="{ 'rotate-90': startOpen }" />
      </button>
      <div v-if="startOpen" class="border-t border-slate-100 p-4 sm:p-5">
        <ol class="space-y-3">
          <li v-for="(step, index) in steps" :key="step.title" class="flex items-start gap-4">
            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-semibold" :class="step.done ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">
              <Icon v-if="step.done" name="check" :size="16" /><template v-else>{{ index + 1 }}</template>
            </span>
            <div class="min-w-0 flex-1">
              <p class="font-medium">{{ step.title }}</p>
              <p class="text-sm text-slate-500">{{ step.text }}</p>
            </div>
            <RouterLink :to="step.to" class="btn-secondary shrink-0 px-3 py-1.5">{{ step.done ? 'Modifier' : 'Commencer' }}</RouterLink>
          </li>
        </ol>
        <button type="button" class="btn-ghost mt-3 px-2 text-sm" @click="setStart('hidden')">J'ai terminé, ne plus afficher</button>
      </div>
    </section>
  </div>
</template>
