<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import LiveDeliveryFees from '../../../components/LiveDeliveryFees.vue'
import LiveItemsPanel from '../../../components/LiveItemsPanel.vue'
import LivePin from '../../../components/LivePin.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { money } from '../../../lib/format'
import { connectWhatsappDirect } from '../../../lib/whatsappConnect'
import { useAuthStore } from '../../../stores/auth'

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
const pageUrl = ref('')
const draft = ref(null) // état complet du live en préparation (GET lives/{id})
const error = ref('')
const busy = ref(false)
const panel = ref(null) // 'fees' | 'items' | 'pin'
const video = ref(false)
const copied = ref(false)

const current = computed(() => lives.value?.find((l) => l.status === 'live'))
const past = computed(() => (lives.value ?? []).filter((l) => l.status === 'ended'))

async function load() {
  error.value = ''
  try {
    const { data } = await http.get('/lives')
    lives.value = data.data
    whatsapp.value = data.meta.whatsapp
    number.value = { phone: data.meta.whatsapp_phone, noCard: !!data.meta.whatsapp_no_card }
    pageUrl.value = data.meta.page_url
    const latest = data.data.find((l) => l.status === 'draft')
    draft.value = latest ? (await http.get(`/lives/${latest.id}`)).data.data : null
    if (auth.can('store.manage')) fees.value = (await http.get('/delivery-rates').catch(() => null))?.data.data ?? null
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

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

async function copyLink() {
  try {
    await navigator.clipboard.writeText(pageUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copie ce lien :', pageUrl.value)
  }
}

async function start() {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/lives/${draft.value.id}/start`)
    router.push(`/gestion/live/${draft.value.id}`)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

const card = (n) => [
  'flex items-center gap-3 rounded-[20px] px-3.5 py-3',
  n < step.value ? 'border-[2.5px] border-emerald-500 bg-emerald-50' : n === step.value ? 'border-[3px] border-ocre-600 bg-white' : 'bg-white opacity-50',
]
const bubble = (n) => ['grid h-10 w-10 shrink-0 place-items-center rounded-full text-[18px] font-black text-white', n < step.value ? 'bg-emerald-500' : 'bg-slate-900']
</script>

<template>
  <div class="space-y-3 pb-8">
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="toast" class="fixed inset-x-3 top-3 z-50 mx-auto max-w-md rounded-2xl bg-slate-900 px-4 py-3 text-[15px] font-extrabold text-white shadow-2xl" role="status">
      ✓ WhatsApp connecté<small class="block font-semibold opacity-85">{{ toast }}</small>
    </div>

    <!-- Live en cours : on y retourne d'abord -->
    <section v-if="current" class="rounded-[24px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 p-5 text-white">
      <span class="inline-flex items-center gap-2 rounded-full bg-white/20 px-3 py-1 text-xs font-bold"><span class="h-2 w-2 animate-pulse rounded-full bg-white" />EN DIRECT</span>
      <h2 class="mt-2 text-xl font-extrabold">{{ current.title }}</h2>
      <p class="mt-1 text-3xl font-extrabold">{{ money(current.summary?.amount ?? 0) }}</p>
      <RouterLink :to="`/gestion/live/${current.id}`" class="mt-4 block rounded-2xl bg-white py-3 text-center text-base font-extrabold text-ocre-700">Revenir au live</RouterLink>
    </section>

    <template v-else-if="lives">
      <div class="rounded-[24px] bg-gradient-to-br from-ocre-800 via-ocre-600 to-orange-400 px-4 py-5 text-center text-white">
        <h2 class="text-[26px] font-black leading-tight">Ton live en 5 étapes</h2>
        <p class="mt-1 text-[16px] font-bold text-orange-50">Les clientes envoient le code, la commande se crée seule.</p>
        <button type="button" class="mt-3 rounded-full bg-white/20 px-4 py-1.5 text-sm font-bold" @click="video = true">🎬 Voir la vidéo (1 min 30)</button>
      </div>

      <!-- 1 · WhatsApp -->
      <div :class="card(1)">
        <span :class="bubble(1)">{{ step > 1 ? '✓' : 1 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">{{ whatsapp ? 'WhatsApp connecté' : step > 1 ? 'Sans WhatsApp' : 'Connecte ton WhatsApp' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ whatsapp ? 'Les commandes arrivent toutes seules' : 'Pour recevoir les commandes' }}</span>
          <template v-if="step === 1">
            <button type="button" class="mt-2 block w-full rounded-xl bg-[#25d366] py-3 text-[16px] font-black text-white" @click="connectWhatsapp">Connecter WhatsApp</button>
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
          <b class="block text-[18px] leading-tight">{{ done.items ? `${products.count} article(s) · ${products.first} à ${products.last}` : 'Choisis tes articles' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ done.items ? `${products.pieces} pièces` : noPhoto ? `📷 ${noPhoto} article(s) sans photo` : 'Dans ton catalogue, un live passé ou un nouvel article' }}</span>
        </div>
        <button v-if="step >= 3" type="button" class="shrink-0 text-[14px] font-black" :class="done.items ? 'text-emerald-700' : 'text-ocre-700'" @click="panel = 'items'">{{ done.items ? 'Modifier' : 'Choisir ▸' }}</button>
      </div>

      <!-- 4 · Photo à épingler -->
      <div :class="card(4)">
        <span :class="bubble(4)">{{ step > 4 ? '✓' : 4 }}</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">{{ done.pin ? 'Photo épinglée' : 'La photo à épingler' }}</b>
          <span class="text-[13.5px] text-slate-500">{{ done.pin ? 'Lien dans la bio ✓' : 'À publier et épingler sur TikTok' }}</span>
        </div>
        <button v-if="step >= 4" type="button" class="shrink-0 text-[14px] font-black" :class="done.pin ? 'text-emerald-700' : 'text-ocre-700'" @click="panel = 'pin'">{{ done.pin ? 'Revoir' : 'Ouvrir ▸' }}</button>
      </div>

      <!-- 5 · Commencer -->
      <div :class="card(5)">
        <span :class="bubble(5)">5</span>
        <div class="min-w-0 flex-1">
          <b class="block text-[18px] leading-tight">Commence ton live</b>
          <span class="text-[13.5px] text-slate-500">Lance-le sur TikTok, puis touche le bouton ici</span>
        </div>
      </div>
      <template v-if="step === 5">
        <button type="button" class="block w-full rounded-2xl bg-rose-600 py-4 text-[18px] font-black text-white" :disabled="busy" @click="start">● Je commence mon live</button>
        <RouterLink :to="`/gestion/live/${draft.id}?onglet=support&support=tv`" class="block text-center text-[13px] font-bold text-slate-500 underline">📺 Afficher sur une télé ou TikTok LIVE Studio</RouterLink>
      </template>

      <RouterLink to="/gestion/live/passes" class="block pt-2 text-center text-sm font-semibold text-emerald-700">Voir les lives passés ▸</RouterLink>
    </template>

    <!-- Étape 2 : prix de livraison -->
    <LiveDeliveryFees v-if="panel === 'fees'" @done="feesDone" @close="panel = null" />

    <!-- Étape 3 : fenêtre des articles -->
    <LiveItemsPanel v-if="panel === 'items'" :draft="draft" :past="past" @saved="saved" @close="panel = null" />

    <!-- Étape 4 : la photo et les consignes -->
    <div v-if="panel === 'pin' && draft" class="fixed inset-0 z-50 flex flex-col bg-white" role="dialog" aria-modal="true" aria-label="La photo à épingler">
      <header class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
        <h2 class="text-[18px] font-black">4 · La photo à épingler</h2>
        <button type="button" class="grid h-9 w-9 place-items-center rounded-full bg-slate-100 text-lg" aria-label="Fermer" @click="panel = null">✕</button>
      </header>
      <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-3">
        <LivePin :live="draft" />
        <section class="space-y-3 rounded-2xl bg-orange-50 p-4">
          <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ocre-600 text-sm text-white">1</b><p class="text-[15px]">Publie la photo sur TikTok et <b>épingle-la</b> sur ton profil.</p></div>
          <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ocre-600 text-sm text-white">2</b>
            <div class="min-w-0 flex-1 space-y-2"><p class="text-[15px]">Mets le <b>lien de ton live dans ta bio</b> :</p>
              <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"><span class="min-w-0 flex-1 truncate">{{ pageUrl }}</span><button type="button" class="shrink-0 font-black text-ocre-700" @click="copyLink">{{ copied ? 'Copié ✓' : 'Copier' }}</button></div>
            </div>
          </div>
          <div class="flex gap-3"><b class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-ocre-600 text-sm text-white">3</b><p class="text-[15px]">Pendant le live, épingle ton <b>numéro WhatsApp</b> en commentaire.</p></div>
        </section>
      </div>
      <footer class="border-t border-slate-200 p-3">
        <button type="button" class="w-full rounded-2xl bg-emerald-600 py-3.5 text-[16px] font-black text-white" :disabled="busy" @click="pinReady">C'est fait ✓</button>
      </footer>
    </div>

    <Modal v-if="video" title="Le live en vidéo" @close="video = false">
      <video class="mx-auto max-h-[75vh] w-full rounded-2xl bg-black" controls autoplay playsinline :poster="`${VIDEO}.jpg?v=2`" :src="`${VIDEO}.mp4?v=2`" />
    </Modal>
  </div>
</template>
