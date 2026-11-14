<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import ColorIcon from '../../../components/ColorIcon.vue'
import Alert from '../../../components/Alert.vue'
import LiveDeliveryFees from '../../../components/LiveDeliveryFees.vue'
import LiveItemsPanel from '../../../components/LiveItemsPanel.vue'
import LivePin from '../../../components/LivePin.vue'
import { credits, loadCredits, openRecharge } from '../../../lib/credits'
import EndLiveSheet from '../../../components/EndLiveSheet.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import { connectWhatsappDirect } from '../../../lib/whatsappConnect'
import { useAuthStore } from '../../../stores/auth'
import { livesChanged, livesVersion } from '../../../lib/fullscreen'

/**
 * Menu Live › « Préparer un live » : 5 étapes qui se cochent (WhatsApp, prix de livraison, articles, photo à
 * épingler, commencer).
 * Le live en préparation est repris tel quel (étapes déjà faites cochées) ; un live en cours passe devant.
 */
const router = useRouter()
const auth = useAuthStore()
const VIDEO = '/videos/live/comment-ca-marche-v'

const lives = ref(null)
const whatsapp = ref(null)
const number = ref({ phone: null, noCard: false }) // numéro WhatsApp relié
const fees = ref(null) // prix de livraison (GET delivery-rates)
const draft = ref(null) // état complet du live en préparation (GET lives/{id})
const error = ref('')
const busy = ref(false)
const panel = ref(null) // 'fees' | 'items' | 'pin'
const video = ref(false)
// Solde (commandes et IA) : peut-on ouvrir les commandes du live ?
const billing = computed(() => credits.data)

const current = computed(() => lives.value?.find((l) => l.status === 'live' && l.channel !== 'whatsapp'))
// Commandes WhatsApp sans live ouvertes en ce moment
const whatsappOpen = computed(() => lives.value?.find((l) => l.status === 'live' && l.channel === 'whatsapp'))
const past = computed(() => (lives.value ?? []).filter((l) => l.status === 'ended'))

// « Que veux-tu faire ? » : faire un live, ou des commandes WhatsApp sans live (choix gardé pour la visite)
const mode = ref((() => {
  try {
    return sessionStorage.getItem('jm.live-mode') === 'whatsapp' ? 'whatsapp' : 'live'
  } catch {
    return 'live'
  }
})())
watch(mode, (v) => {
  try {
    sessionStorage.setItem('jm.live-mode', v)
  } catch {
    // sans stockage : « Faire un live » à la prochaine visite
  }
})
// Commandes WhatsApp ouvertes : les étapes du live seulement si on choisit de préparer un live
const preparing = ref(false)
const showSteps = computed(() => (whatsappOpen.value ? preparing.value : mode.value === 'live'))
async function closeWhatsapp() {
  if (!window.confirm('Fermer les commandes WhatsApp ? Les clients ne pourront plus commander avec les codes ; les commandes déjà enregistrées restent.')) return
  busy.value = true
  try {
    await http.post(`/lives/${whatsappOpen.value.id}/end`)
    livesChanged()
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function load() {
  error.value = ''
  try {
    const { data } = await http.get('/lives')
    lives.value = data.data
    whatsapp.value = data.meta.whatsapp
    number.value = { phone: data.meta.whatsapp_phone, noCard: !!data.meta.whatsapp_no_card }
    const latest = data.data.find((l) => l.status === 'draft' && l.channel !== 'whatsapp')
    draft.value = latest ? (await http.get(`/lives/${latest.id}`)).data.data : null
    await loadCredits()
    if (auth.can('store.manage')) fees.value = (await http.get('/delivery-rates').catch(() => null))?.data.data ?? null
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)
const until = (iso) => new Date(iso).toLocaleString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' })
const startLabel = computed(() => {
  const label = '● J\'ouvre les commandes du live'
  return label
})
const billingLine = computed(() => {
  const b = billing.value
  if (!b?.paid) return ''
  if (b.next === 'covered') return `Illimité jusqu'au ${until(b.month_until)}`
  if (b.next === 'pay') return `Solde : ${b.orders} commandes · recharge pour ouvrir les commandes`
  return `Solde : ${b.orders} commandes`
})
// Live lancé ou terminé ailleurs (fenêtre du live, autre copie de cette page) : la carte se met à jour
watch(livesVersion, load)

// Retour de la connexion WhatsApp : petit message avec le numéro relié
const toast = ref('')
onMounted(() => {
  if (sessionStorage.getItem('jm.whatsapp-connected') !== '1') return
  // Retiré un peu plus tard : la page peut être montée deux fois (accueil glissant et fenêtre)
  setTimeout(() => sessionStorage.removeItem('jm.whatsapp-connected'), 1500)
  const stop = setInterval(() => {
    if (lives.value === null) return
    clearInterval(stop)
    if (!number.value.phone) return
    toast.value = number.value.phone
    setTimeout(() => (toast.value = ''), 4000)
  }, 200)
})

// Étapes : WhatsApp (ou « Continuer sans »), articles, photo publiée ; l'étape en cours est la première non faite
const skipKey = 'jm.live-skip-whatsapp'
const skipped = ref((() => {
  try {
    return localStorage.getItem(skipKey) === '1'
  } catch {
    return false
  }
})())
function skipWhatsapp() {
  skipped.value = true
  try {
    localStorage.setItem(skipKey, '1')
  } catch {
    // Sans stockage : redemandé à la prochaine visite
  }
}
// Prix de livraison : revus une fois (sur cet appareil), puis l'étape reste cochée
const feesKey = 'jm.live-fees-ok'
const feesOk = ref((() => {
  try {
    return localStorage.getItem(feesKey) === '1'
  } catch {
    return false
  }
})())
function feesDone() {
  feesOk.value = true
  try {
    localStorage.setItem(feesKey, '1')
  } catch {
    // Sans stockage : redemandé à la prochaine visite
  }
  panel.value = null
  load()
}
const special = computed(() => (fees.value?.places ?? []).filter((p) => p.learned).length)
const feesLine = computed(() => {
  if (!fees.value) return ''
  const parts = [fees.value.base_fee != null ? `${money(fees.value.base_fee)} partout` : 'Prix à fixer']
  if (special.value) parts.push(`${special.value} à part`)
  if (fees.value.free_shipping_above) parts.push(`offerte dès ${money(fees.value.free_shipping_above)}`)
  return parts.join(' · ')
})
const noPhoto = computed(() => new Set((draft.value?.items ?? []).filter((i) => !i.image).map((i) => i.code)).size)
const done = computed(() => ({
  wa: !!whatsapp.value || skipped.value || !auth.can('store.manage'),
  fees: feesOk.value || !auth.can('store.manage'),
  // Un article sans photo ne passe pas en live : l'étape attend ses photos
  items: !!draft.value?.items?.length && !noPhoto.value,
  pin: !!draft.value?.pin_ready,
}))
const step = computed(() => (!done.value.wa ? 1 : !done.value.fees ? 2 : !done.value.items ? 3 : !done.value.pin ? 4 : 5))
const products = computed(() => {
  const codes = [...new Set((draft.value?.items ?? []).map((i) => i.code))]
  return { count: codes.length, first: codes[0], last: codes[codes.length - 1], pieces: (draft.value?.items ?? []).reduce((s, i) => s + i.quantity, 0) }
})

const connectWhatsapp = () => connectWhatsappDirect(router, '/gestion/live')

function saved(state) {
  draft.value = state
  panel.value = null
  load()
}

async function pinReady() {
  busy.value = true
  try {
    draft.value = (await http.post(`/lives/${draft.value.id}/pin-ready`)).data.data
    panel.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

// Terminer le live en cours depuis sa carte : les clientes ne réservent plus, celles qui ont une pièce gardée peuvent
// encore donner leur quartier
const ending = ref(false)
function endLive() {
  ending.value = true
}
async function confirmEnd(hours) {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/lives/${current.value.id}/end`, hours ? { keep_hours: hours } : {})
    ending.value = false
    livesChanged()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    const id = draft.value.id
    // Solde trop bas : recharge (pack ou illimité), payée par PayDunya
    if (billing.value?.paid && billing.value.next === 'pay') {
      openRecharge(`Il vous faut au moins ${billing.value.min_orders} commandes dans votre solde pour ouvrir les commandes du live.`)
      busy.value = false
      return
    }
    await http.post(`/lives/${id}/start`)
    livesChanged()
    // Directement dans la fenêtre du live, en plein écran
    await router.push(`/gestion/live/${id}`)
    busy.value = false
  } catch (e) {
    if (e.response?.data?.errors?.payment) {
      openRecharge(e.response.data.errors.payment[0])
    } else {
      error.value = errorMessage(e)
    }
    busy.value = false
  }
}

const card = (n) => [
  'flex items-center gap-3 rounded-[20px] px-3.5 py-3',
  n < step.value ? 'border-[2.5px] border-emerald-500 bg-emerald-50' : n === step.value ? 'border-[3px] border-ocre-600 bg-white' : 'border border-slate-200 bg-white',
]
// Étapes à venir : texte lisible (pas pâli), seul le numéro est gris
const bubble = (n) => ['grid h-10 w-10 shrink-0 place-items-center rounded-full text-[18px] font-black', n < step.value ? 'bg-emerald-700 text-white' : n === step.value ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-700']
</script>

<template>
  <div class="space-y-3 pb-8">
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="toast" class="fixed inset-x-3 top-3 z-50 mx-auto max-w-md rounded-2xl bg-slate-900 px-4 py-3 text-[15px] font-extrabold text-white shadow-2xl" role="status">
      ✓ WhatsApp connecté<small class="block font-semibold opacity-85">{{ toast }}</small>
    </div>

    <!-- Live en cours : on y retourne d'abord -->
    <section v-if="current" class="rounded-[24px] bg-live-grad p-5 text-white">
      <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold"><span class="h-2 w-2 animate-pulse rounded-full bg-white" />COMMANDES OUVERTES</span>
      <h2 class="mt-2 text-xl font-extrabold">{{ current.title }}</h2>
      <p class="mt-1 text-3xl font-extrabold">{{ money(current.summary?.amount ?? 0) }}</p>
      <RouterLink :to="`/gestion/live/${current.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-ocre-700">Revenir aux commandes du live</RouterLink>
      <button type="button" class="mt-2 block w-full rounded-2xl border-2 border-white/70 py-2.5 text-center text-[15px] font-extrabold text-white disabled:opacity-60" :disabled="busy" @click="endLive">⏹ Terminer le live</button>
    </section>

    <template v-else-if="lives">
      <!-- Commandes WhatsApp ouvertes : le montant, voir les commandes, fermer ; un live peut encore être préparé -->
      <template v-if="whatsappOpen">
        <section class="space-y-1.5 rounded-[20px] bg-emerald-700 p-4 text-white">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-0.5 text-xs font-extrabold text-emerald-700"><span class="h-2 w-2 animate-pulse rounded-full bg-emerald-600" />COMMANDES WHATSAPP OUVERTES</span>
          <b class="block font-display text-[28px] font-extrabold leading-tight tabular-nums">{{ money(whatsappOpen.summary?.amount ?? 0) }}</b>
          <span class="block text-sm opacity-90">{{ whatsappOpen.summary?.orders ?? 0 }} commande{{ (whatsappOpen.summary?.orders ?? 0) > 1 ? 's' : '' }}{{ whatsappOpen.closes_at ? ` · jusqu'au ${new Date(whatsappOpen.closes_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })}` : '' }}</span>
          <div class="grid grid-cols-2 gap-2 pt-1">
            <RouterLink :to="`/gestion/live/commandes-whatsapp/${whatsappOpen.id}`" class="rounded-xl bg-white py-2.5 text-center text-sm font-extrabold text-emerald-700">Voir les commandes</RouterLink>
            <button type="button" class="rounded-xl bg-white/20 py-2.5 text-sm font-extrabold" :disabled="busy" @click="closeWhatsapp">Fermer</button>
          </div>
        </section>
        <template v-if="!preparing">
          <p class="px-0.5 pt-1 font-display text-base font-extrabold">Ou lancer un live</p>
          <button type="button" class="flex w-full items-center gap-3 rounded-2xl border-2 border-emerald-200 bg-white p-3 text-left" @click="preparing = true">
            <ColorIcon name="live" :size="34" />
            <span class="min-w-0 flex-1"><b class="block font-display text-base font-extrabold">Faire un live</b><span class="text-xs text-slate-500">Ferme les commandes WhatsApp</span></span>
            <span class="text-sm font-extrabold text-emerald-700">Préparer ▸</span>
          </button>
        </template>
      </template>

      <!-- Que voulez-vous faire ? : faire un live, ou des commandes WhatsApp sans live -->
      <template v-else>
        <p class="px-0.5 font-display text-base font-extrabold">Que voulez-vous faire ?</p>
        <div class="grid grid-cols-2 gap-2">
          <button type="button" class="grid min-h-[118px] content-start gap-1 rounded-[18px] bg-live-grad p-3 text-left text-white" :class="mode === 'live' ? 'ring-[3px] ring-slate-900 ring-offset-2' : ''" :aria-pressed="mode === 'live'" @click="mode = 'live'">
            <ColorIcon name="live" :size="34" />
            <b class="font-display text-base font-extrabold leading-tight">Faire un live</b>
            <span class="text-[13px] leading-snug">Sur TikTok, vous montrez vos articles</span>
          </button>
          <button type="button" class="grid min-h-[118px] content-start gap-1 rounded-[18px] border-2 border-emerald-200 bg-white p-3 text-left" :class="mode === 'whatsapp' ? 'ring-[3px] ring-slate-900 ring-offset-2' : ''" :aria-pressed="mode === 'whatsapp'" @click="mode = 'whatsapp'">
            <ColorIcon name="whatsapp" :size="34" />
            <b class="font-display text-base font-extrabold leading-tight">Commandes WhatsApp</b>
            <span class="text-[13px] leading-snug text-slate-500">Sans live, avec les codes</span>
          </button>
        </div>

        <!-- Commandes WhatsApp sans live : court, en grand -->
        <section v-if="mode === 'whatsapp'" class="space-y-2 rounded-[18px] border-2 border-emerald-200 bg-white p-4">
          <h3 class="font-display text-base font-extrabold">Commandes WhatsApp</h3>
          <p class="text-base leading-snug">Vos clients envoient le code <span class="rounded-md bg-red-600 px-1.5 text-sm font-extrabold text-white">A1</span> sur WhatsApp.</p>
          <p class="text-base leading-snug">La commande s'enregistre toute seule.</p>
          <RouterLink to="/gestion/live/commandes-whatsapp" class="btn-brand w-full justify-center py-3 text-base font-extrabold">Ouvrir les commandes WhatsApp</RouterLink>
          <p class="text-[13px] text-slate-500">Lancer un live les ferme.</p>
        </section>
      </template>

      <template v-if="showSteps">
      <div class="flex items-center gap-3 rounded-[18px] border border-slate-200 bg-white p-3.5">
        <span class="min-w-0 flex-1"><b class="block font-display text-lg font-extrabold leading-tight">Votre live en 5 étapes</b><span class="text-sm text-slate-600">Jibiat Marchand enregistre les commandes.</span></span>
        <button type="button" class="shrink-0 rounded-full bg-live-soft px-3 py-2 text-[13px] font-extrabold text-live" aria-label="Voir la vidéo (1 min 30)" @click="video = true"><span aria-hidden="true">▶ 1 min 30</span></button>
      </div>

      <!-- 1 · WhatsApp -->
      <div :class="card(1)">
        <span :class="bubble(1)">{{ step > 1 ? '✓' : 1 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">{{ whatsapp ? 'WhatsApp connecté' : step > 1 ? 'Sans WhatsApp' : 'Connectez votre numéro WhatsApp sur Meta' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ whatsapp ? 'Les commandes arrivent toutes seules' : 'Pour recevoir les commandes' }}</span>
          <template v-if="step === 1">
            <button type="button" class="mt-2 block w-full rounded-xl bg-whatsapp py-3 text-[16px] font-black text-white" @click="connectWhatsapp">Connecter WhatsApp</button>
            <button type="button" class="mt-2 block w-full text-center text-[13px] font-bold text-slate-500 underline" @click="skipWhatsapp">Continuer sans WhatsApp</button>
          </template>
          <button v-else-if="!whatsapp && auth.can('store.manage')" type="button" class="mt-1 text-[13px] font-bold text-emerald-700 underline" @click="connectWhatsapp">Connecter WhatsApp</button>
          <div v-if="number.phone" class="mt-2 flex items-center gap-2 rounded-xl bg-white/80 px-3 py-2 ring-1 ring-emerald-200">
            <div class="min-w-0 flex-1">
              <b class="block whitespace-nowrap text-[15.5px] text-emerald-800">📱 {{ number.phone }}</b>
              <small class="block text-[12.5px] font-bold text-emerald-700">{{ number.noCard ? 'Mode sans carte · réponses gratuites' : 'Numéro relié' }}</small>
            </div>
            <button v-if="auth.can('store.manage')" type="button" class="shrink-0 text-[13px] font-black text-emerald-700 underline" @click="connectWhatsapp">Changer</button>
          </div>
        </div>
      </div>

      <!-- 2 · Prix de livraison -->
      <div v-if="auth.can('store.manage')" :class="card(2)">
        <span :class="bubble(2)">{{ step > 2 ? '✓' : 2 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">Prix de livraison</b>
          <span class="text-[13.5px] text-slate-500">{{ feesLine || 'Ce que paie la cliente' }}</span>
        </div>
        <button v-if="step >= 2" type="button" class="shrink-0 text-[14px] font-black" :class="done.fees ? 'text-emerald-700' : 'text-ocre-700'" @click="panel = 'fees'">{{ done.fees ? 'Modifier' : 'Voir ▸' }}</button>
      </div>

      <!-- 3 · Articles -->
      <div :class="card(3)">
        <span :class="bubble(3)">{{ step > 3 ? '✓' : 3 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">{{ done.items ? `${products.count} article(s) · ${products.first} à ${products.last}` : 'Choisissez vos articles' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ done.items ? `${products.pieces} pièces` : noPhoto ? `📷 ${noPhoto} article(s) sans photo` : 'Dans votre catalogue, un live passé ou un nouvel article' }}</span>
        </div>
        <button v-if="step >= 3" type="button" class="shrink-0 text-[14px] font-black" :class="done.items ? 'text-emerald-700' : 'text-ocre-700'" @click="panel = 'items'">{{ done.items ? 'Modifier' : 'Choisir ▸' }}</button>
      </div>

      <!-- 4 · Carte à épingler (fond vert) -->
      <div :class="card(4)">
        <span :class="bubble(4)">{{ step > 4 ? '✓' : 4 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">{{ done.pin ? 'Carte prête' : 'La carte à épingler' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ done.pin ? 'En fond vert sur le live ✓' : 'À mettre en fond vert sur votre live' }}</span>
        </div>
        <button v-if="step >= 4" type="button" class="shrink-0 text-[14px] font-black" :class="done.pin ? 'text-emerald-700' : 'text-ocre-700'" @click="panel = 'pin'">{{ done.pin ? 'Revoir' : 'Ouvrir ▸' }}</button>
      </div>

      <!-- 5 · Commencer -->
      <div :class="card(5)">
        <span :class="bubble(5)">5</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">Lancez votre live sur TikTok</b>
          <span class="text-[13.5px] text-slate-500">Puis ouvrez les commandes du live ici</span>
        </div>
      </div>
      <template v-if="step === 5">
        <p v-if="billingLine" class="text-center text-[14.5px] font-extrabold" :class="billing.next === 'pay' ? 'text-slate-700' : 'text-emerald-700'">{{ billingLine }}</p>
        <button type="button" class="block w-full rounded-2xl bg-live py-4 text-[18px] font-black text-white" :disabled="busy" @click="start">{{ startLabel }}</button>
        <RouterLink :to="`/gestion/live/${draft.id}?onglet=support&support=tv`" class="block text-center text-[13px] font-bold text-slate-500 underline">📺 Afficher sur une télé ou TikTok LIVE Studio</RouterLink>
      </template>

      </template>

      <!-- Solde sur une ligne : seulement quand les commandes sont comptées -->
      <div v-if="billing?.paid" class="flex items-center gap-3 rounded-2xl bg-white p-3">
        <span aria-hidden="true">🧾</span>
        <RouterLink to="/gestion/solde" class="min-w-0 flex-1">
          <b class="block font-display text-base font-extrabold tabular-nums">{{ billing.month_until ? 'Illimité' : `${billing.orders} commandes` }} · ✨ {{ billing.ai }} IA</b>
          <span class="text-[13px] text-slate-500">1 commande {{ mode === 'whatsapp' || whatsappOpen ? 'WhatsApp' : 'du live' }} = 1 du solde</span>
        </RouterLink>
        <button type="button" class="shrink-0 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-extrabold text-emerald-700" @click="openRecharge()">Recharger</button>
      </div>

      <RouterLink to="/gestion/live/passes" class="block pt-2 text-center text-sm font-semibold text-emerald-700">Voir les lives passés ▸</RouterLink>
    </template>

    <!-- Étape 2 : prix de livraison -->
    <LiveDeliveryFees v-if="panel === 'fees'" @done="feesDone" @close="panel = null" />

    <!-- Étape 3 : fenêtre des articles -->
    <LiveItemsPanel v-if="panel === 'items'" :draft="draft" :past="past" @saved="saved" @close="panel = null" />

    <!-- Étape 4 : la photo et les consignes -->
    <div v-if="panel === 'pin' && draft" class="fixed inset-0 z-50 flex flex-col bg-white" role="dialog" aria-modal="true" aria-label="La carte à épingler">
      <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-[18px] font-black">4 · La carte à épingler</h2>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg" aria-label="Fermer" @click="panel = null">✕</button>
      </header>
      <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3">
        <LivePin :live="draft" />
        <section class="space-y-3 rounded-2xl bg-orange-50 p-4">
          <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ocre-600 text-sm text-white">1</b><p class="text-[15px]"><b>Télécharge</b> la carte (bouton ci-dessus).</p></div>
          <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ocre-600 text-sm text-white">2</b><p class="text-[15px]">Dans votre live TikTok, ouvrez <b>Fond vert</b> et choisissez la carte dans votre galerie : les clientes voient vos articles et leurs codes pendant tout le live.</p></div>
        </section>
      </div>
      <footer class="border-t border-slate-200 p-3">
        <button type="button" class="w-full rounded-2xl bg-emerald-700 py-3.5 text-[16px] font-black text-white" :disabled="busy" @click="pinReady">C'est fait ✓</button>
      </footer>
    </div>

    <Modal v-if="video" title="Le live en vidéo" @close="video = false">
      <video class="mx-auto max-h-[75vh] w-full rounded-2xl bg-black" controls autoplay playsinline :poster="`${VIDEO}.jpg?v=4`" :src="`${VIDEO}.mp4?v=4`" />
    </Modal>
    <EndLiveSheet v-if="ending" :busy="busy" @close="ending = false" @confirm="confirmEnd" />
  </div>
</template>
