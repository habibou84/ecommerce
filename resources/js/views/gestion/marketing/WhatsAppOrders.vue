<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import LiveItemsPanel from '../../../components/LiveItemsPanel.vue'
import { credits, loadCredits, openRecharge } from '../../../lib/credits'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { livesChanged } from '../../../lib/fullscreen'

/**
 * Commandes WhatsApp sans live : les clients envoient le code (A1, A2…) ou « LISTE » sur WhatsApp et la commande
 * s'enregistre, comme pendant un live, sans TikTok. Préparer (articles, durée), ouvert (pièces, commandes, fermer),
 * bilan. Les commandes de chaque jour sont retirées du solde le soir.
 */
const props = defineProps({ id: { type: [String, Number], default: null } })
const router = useRouter()
const session = ref(null)
const past = ref([])
const billing = computed(() => credits.data)
const error = ref('')
const busy = ref(false)
const panel = ref(false)
const hours = ref(168)
const copied = ref(false)
const DURATIONS = [[24, '24 h'], [72, '3 jours'], [168, '7 jours'], [null, 'Jusqu\'à fermer']]
let timer

async function load() {
  error.value = ''
  try {
    let id = props.id
    const list = (await http.get('/lives')).data.data
    past.value = list.filter((l) => l.status === 'ended')
    // Sans numéro : les commandes WhatsApp ouvertes, sinon en préparation
    if (!id) id = (list.find((l) => l.channel === 'whatsapp' && l.status === 'live') ?? list.find((l) => l.channel === 'whatsapp' && l.status === 'draft'))?.id
    session.value = id ? (await http.get(`/lives/${id}`)).data.data : null
    await loadCredits()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(() => {
  load()
  timer = setInterval(() => session.value?.status === 'live' && document.visibilityState === 'visible' && refresh(), 20000)
})
onUnmounted(() => clearInterval(timer))
watch(() => props.id, load)

async function refresh() {
  try {
    session.value = (await http.get(`/lives/${session.value.id}`)).data.data
  } catch {
    // affiché tel quel
  }
}

function saved(state) {
  panel.value = false
  session.value = state
  if (String(props.id) !== String(state.id)) router.replace(`/gestion/live/commandes-whatsapp/${state.id}`)
}

async function open() {
  if (billing.value?.paid && billing.value.next === 'pay') {
    openRecharge(`Il vous faut au moins ${billing.value.min_orders} commandes dans votre solde pour ouvrir les commandes WhatsApp.`)
    return
  }
  busy.value = true
  error.value = ''
  try {
    session.value = (await http.post(`/lives/${session.value.id}/start`, hours.value ? { open_hours: hours.value } : {})).data.data
    livesChanged()
  } catch (e) {
    if (e.response?.data?.errors?.payment) {
      openRecharge(e.response.data.errors.payment[0])
    } else {
      error.value = errorMessage(e)
    }
  } finally {
    busy.value = false
  }
}

async function close() {
  if (!confirm('Fermer les commandes WhatsApp ? Les clients ne pourront plus commander avec les codes ; les commandes déjà enregistrées restent.')) return
  busy.value = true
  try {
    session.value = (await http.post(`/lives/${session.value.id}/end`)).data.data
    livesChanged()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function reopen() {
  busy.value = true
  try {
    const { data } = await http.post(`/lives/${session.value.id}/duplicate`)
    router.push(`/gestion/live/commandes-whatsapp/${data.data.id}`)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Une carte par article (même code pour toutes ses tailles)
const products = computed(() => {
  const groups = {}
  for (const i of session.value?.items ?? []) {
    const g = (groups[i.code] ??= { code: i.code, title: i.product, image: i.image, price: i.price, quantity: 0, remaining: 0, waiting: 0 })
    g.quantity += i.quantity
    g.remaining += i.remaining
    g.waiting += i.waiting
    g.price = Math.min(g.price, i.price)
  }
  return Object.values(groups)
})
// Commandes du jour (retirées du solde chaque soir), sinon toutes celles de ces commandes WhatsApp
const todayOrders = computed(() => session.value?.billing?.orders ?? session.value?.summary?.orders ?? 0)
const recent = computed(() => (session.value?.reservations ?? []).filter((r) => r.status !== 'cancelled').slice(0, 8))
const pieces = computed(() => products.value.reduce((s, p) => s + p.quantity - p.remaining, 0))
const dayLabel = (d) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric' })

// Liste à partager (statut, groupe) : les codes, les prix, et comment commander
async function share() {
  const text = `🛍️ Commandes ouvertes sur WhatsApp !\n\n${products.value.filter((p) => p.remaining > 0).map((p) => `${p.code} · ${p.title} · ${money(p.price)}`).join('\n')}\n\nEnvoie le code de l'article (ex. ${products.value[0]?.code ?? 'A1'})${session.value?.whatsapp_phone ? ` au ${session.value.whatsapp_phone}` : ''} 👇`
  try {
    if (navigator.share) await navigator.share({ text })
    else {
      await navigator.clipboard.writeText(text)
      copied.value = true
      setTimeout(() => { copied.value = false }, 2500)
    }
  } catch {
    // partage annulé
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl space-y-3 pb-8">
    <RouterLink to="/gestion/live" class="back-link inline-flex items-center gap-1 text-sm text-emerald-700 hover:underline">← Live</RouterLink>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- ═══ Préparer ═══ -->
    <template v-if="!session || session.status === 'draft'">
      <section class="rounded-[24px] bg-emerald-700 px-4 py-5 text-white">
        <h2 class="text-[24px] font-black leading-tight">💬 Commandes WhatsApp</h2>
        <p class="mt-1 text-[15px] font-semibold text-emerald-50">Sans live : vos clients envoient le code (A1, A2…) ou « LISTE » sur WhatsApp, et la commande s'enregistre toute seule.</p>
      </section>
      <section class="card p-4">
        <div class="flex items-center justify-between gap-2">
          <h3 class="font-extrabold">1 · Vos articles</h3>
          <button type="button" class="text-sm font-bold text-emerald-700" @click="panel = true">{{ products.length ? 'Modifier' : 'Choisir ▸' }}</button>
        </div>
        <p v-if="!products.length" class="mt-1 text-sm text-slate-500">Dans votre catalogue, un live passé ou un nouvel article. Chacun reçoit son code.</p>
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          <li v-for="p in products" :key="p.code" class="flex items-center gap-3 py-2">
            <img v-if="p.image" :src="p.image" alt="" class="h-10 w-10 rounded-lg object-cover">
            <span class="rounded-lg bg-red-600 px-2 py-0.5 text-xs font-black text-white">{{ p.code }}</span>
            <span class="min-w-0 flex-1"><b class="block truncate">{{ p.title }}</b><span class="text-slate-500">{{ money(p.price) }} · {{ p.quantity }} pièce{{ p.quantity > 1 ? 's' : '' }}</span></span>
          </li>
        </ul>
      </section>
      <section class="card p-4">
        <h3 class="font-extrabold">2 · Ouvert pendant</h3>
        <div class="mt-2 grid grid-cols-4 gap-2">
          <button v-for="[h, label] in DURATIONS" :key="label" type="button" class="rounded-xl py-2 text-sm font-bold" :class="hours === h ? 'bg-emerald-700 text-white' : 'border border-field'" @click="hours = h">{{ label }}</button>
        </div>
      </section>
      <section v-if="billing?.paid" class="card bg-slate-50 p-4 text-sm text-slate-600">
        <h3 class="font-extrabold text-slate-900">3 · Mon solde</h3>
        <p class="mt-1">Chaque commande prise par WhatsApp compte pour 1, retirée du solde chaque soir.</p>
        <p v-if="billing.month_until" class="mt-1 font-semibold text-emerald-800">Illimité en cours : rien à compter.</p>
        <p v-else class="mt-1">Solde : <b>{{ billing.orders }} commandes</b> · <RouterLink to="/gestion/solde" class="font-bold text-emerald-700 underline">Recharger</RouterLink></p>
      </section>
      <button type="button" class="btn-brand w-full py-3 text-base" :disabled="busy || !session || !products.length" @click="open">💬 Ouvrir les commandes</button>
    </template>

    <!-- ═══ Ouvert ═══ -->
    <template v-else-if="session.status === 'live'">
      <section class="rounded-[24px] p-5 text-white" :class="session.paused ? 'bg-orange-600' : 'bg-emerald-700'">
        <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-black" :class="session.paused ? 'text-orange-700' : 'text-emerald-700'">● {{ session.paused ? 'EN PAUSE' : 'OUVERT' }}</span>
        <p class="mt-2 text-3xl font-black">{{ money(session.summary?.amount ?? 0) }}</p>
        <p class="text-sm text-white/90">
          {{ todayOrders }} commande{{ todayOrders > 1 ? 's' : '' }}{{ session.billing?.per_day ? ' aujourd\'hui' : '' }} ·
          {{ session.closes_at ? `ouvert jusqu'au ${formatDateTime(session.closes_at)}` : 'ouvert jusqu\'à ce que vous fermiez' }}
        </p>
      </section>
      <section v-if="session.paused" class="card space-y-2 border-orange-300 bg-orange-50 p-4 text-sm text-orange-900">
        <b class="block">Solde vide : les nouveaux codes ne sont plus pris.</b>
        <p>Les commandes déjà enregistrées restent. Rechargez pour reprendre.</p>
        <button type="button" class="btn-brand w-full" @click="openRecharge()">Recharger</button>
      </section>
      <section class="card p-4">
        <h3 class="font-extrabold">Articles</h3>
        <ul class="mt-2 divide-y divide-slate-100 text-sm">
          <li v-for="p in products" :key="p.code" class="flex items-center gap-3 py-2">
            <span class="rounded-lg bg-red-600 px-2 py-0.5 text-xs font-black text-white">{{ p.code }}</span>
            <span class="min-w-0 flex-1">
              <b class="block truncate">{{ p.title }}</b>
              <span class="mt-1 block h-1.5 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full" :class="p.remaining ? 'bg-emerald-500' : 'bg-red-400'" :style="{ width: `${((p.quantity - p.remaining) / (p.quantity || 1)) * 100}%` }" /></span>
              <span class="text-xs" :class="p.remaining ? 'text-slate-500' : 'font-bold text-red-600'">{{ p.remaining ? `${p.quantity - p.remaining} / ${p.quantity} vendue(s)` : `Épuisé${p.waiting ? ` · ${p.waiting} en attente` : ''}` }}</span>
            </span>
          </li>
        </ul>
      </section>
      <section class="card p-4">
        <h3 class="font-extrabold">Dernières commandes</h3>
        <p v-if="!recent.length" class="mt-1 text-sm text-slate-500">Rien pour l'instant. Partagez la liste dans votre statut ou vos groupes.</p>
        <ul class="mt-2 space-y-1.5 text-sm">
          <li v-for="r in recent" :key="r.id">
            <b>{{ r.customer_name || r.customer_phone || 'Client' }}</b> · {{ r.code }}
            <RouterLink v-if="r.order" :to="`/gestion/commandes/${r.order.id}`" class="text-emerald-700 underline">{{ r.order.reference }}</RouterLink>
            <span v-else-if="r.status === 'waiting'" class="text-slate-500">(liste d'attente)</span>
            <span v-else class="text-slate-500">(quartier attendu)</span>
          </li>
        </ul>
      </section>
      <section v-if="session.billing?.per_day && session.billing.days?.length" class="card p-4 text-sm">
        <h3 class="font-extrabold">🧾 Retiré du solde chaque soir</h3>
        <p v-for="d in session.billing.days" :key="d.day" class="mt-1">{{ dayLabel(d.day) }} : {{ d.orders }} commande{{ d.orders > 1 ? 's' : '' }}{{ d.used ? ` · −${d.used} au solde` : '' }}</p>
      </section>
      <p v-if="copied" class="text-center text-sm font-bold text-emerald-700">Liste copiée : collez-la dans votre statut ou vos groupes.</p>
      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="btn-secondary" @click="share">Partager la liste</button>
        <button type="button" class="btn-secondary text-red-700" :disabled="busy" @click="close">Fermer les commandes</button>
      </div>
    </template>

    <!-- ═══ Bilan ═══ -->
    <template v-else>
      <section class="rounded-[24px] bg-brand-grad p-5 text-white">
        <span class="inline-flex rounded-full bg-white/20 px-3 py-1 text-xs font-bold">FERMÉ · {{ formatDateTime(session.ended_at) }}</span>
        <p class="mt-1 text-sm font-semibold text-white/90">{{ session.title }}</p>
        <p class="mt-2 text-4xl font-extrabold">{{ money(session.summary?.amount ?? 0) }}</p>
        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ session.summary?.orders ?? 0 }}</b>commandes</span>
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ pieces }}</b>pièces</span>
          <span class="rounded-xl bg-white/15 p-2"><b class="block text-lg">{{ session.summary?.sold_out ?? 0 }} / {{ products.length }}</b>épuisés</span>
        </div>
      </section>
      <section v-if="session.billing?.days?.length" class="card p-4 text-sm">
        <h3 class="font-extrabold">🧾 Jours comptés</h3>
        <p v-for="d in session.billing.days" :key="d.day" class="mt-1">{{ dayLabel(d.day) }} : {{ d.orders }} commande{{ d.orders > 1 ? 's' : '' }}{{ d.used ? ` · −${d.used} au solde` : '' }}</p>
      </section>
      <section class="card p-4">
        <h3 class="font-extrabold">Et maintenant ?</h3>
        <RouterLink to="/gestion/commandes?status=to_ship" class="mt-2 block rounded-xl border border-slate-200 p-3 text-sm"><b class="block">📦 Faire livrer les commandes</b><span class="text-slate-500">{{ session.summary?.orders ?? 0 }} commande(s)</span></RouterLink>
        <button type="button" class="mt-2 block w-full rounded-xl border border-slate-200 p-3 text-left text-sm" :disabled="busy" @click="reopen"><b>💬 Rouvrir avec ces articles</b></button>
      </section>
    </template>

    <LiveItemsPanel v-if="panel" :draft="session?.status === 'draft' ? session : null" :past="past" channel="whatsapp" @saved="saved" @close="panel = false" />
  </div>
</template>
