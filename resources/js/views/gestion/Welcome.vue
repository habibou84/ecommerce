<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import WelcomeArt from '../../components/WelcomeArt.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { aiExhausted, countAiAction } from '../../lib/aiQuota'
import { connectWhatsappDirect } from '../../lib/whatsappConnect'
import { useAuthStore } from '../../stores/auth'

/**
 * Assistant du premier accès, plein écran : accueil (« Bienvenue, Awa ! Votre boutique est créée. ») puis trois
 * étapes essentielles, chacune remplie sur place : tarifs de livraison, premier produit (photo, l'IA remplit la
 * fiche), WhatsApp relié à Meta. Chaque étape peut attendre (« Plus tard ») ; tout reste ensuite dans le guide
 * « Bien démarrer ». L'activité de la boutique n'est pas demandée : elle se déduit de ses produits.
 */
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const firstName = computed(() => (auth.user?.name ?? '').trim().split(/\s+/)[0] || '')
const shopUrl = computed(() => auth.store?.url ?? '')
const shopHost = computed(() => shopUrl.value.replace(/^https?:\/\//, '').replace(/\/$/, ''))

const step = ref(Math.min(4, Math.max(0, Number(route.query.etape) || 0)))
watch(step, (n) => {
  router.replace({ query: n ? { etape: n } : {} })
  window.scrollTo({ top: 0 })
  if (n === 4) finish()
})
const error = ref('')
const busy = ref(false)
const done = ref({ delivery: false, product: false, whatsapp: false })

try {
  sessionStorage.setItem('jm-welcome-seen', '1')
} catch {
  // Sans stockage : l'accueil peut revenir depuis le tableau de bord
}

// ── Étape 1 : tarifs de livraison ──
const rates = ref(null)
const base = ref(1500)
const QUICK = [1000, 1500, 2000, 2500]
const openCommunes = ref(false)
const changed = ref({}) // { [place_id]: prix }
const ship = ref(false)
const gare = ref(1000)
const communes = computed(() => (rates.value?.places ?? []).map((p) => ({
  ...p,
  price: changed.value[p.id] ?? (p.learned ? p.fee : Number(base.value) || 0),
})))
function bump(place, delta) {
  changed.value = { ...changed.value, [place.id]: Math.max(0, place.price + delta) }
}

// ── Étape 2 : premier produit ──
const ai = computed(() => auth.profile?.ai ?? { enabled: false, used: 0, limit: 0 })
const aiReady = computed(() => !!ai.value.enabled && !aiExhausted(ai.value))
const file = ref(null)
const preview = ref('')
const product = ref({ title: '', description: '', price: '', quantity: 1, tags: [], collection_ids: [] })
const productErrors = ref({})
const drafting = ref(false)
const drafted = ref(false)
const created = ref(null)
function pick(event) {
  const chosen = event.target.files?.[0]
  event.target.value = ''
  if (!chosen) return
  if (preview.value) URL.revokeObjectURL(preview.value)
  file.value = chosen
  preview.value = URL.createObjectURL(chosen)
  if (aiReady.value) draft()
}
onUnmounted(() => preview.value && URL.revokeObjectURL(preview.value))
async function draft() {
  drafting.value = true
  error.value = ''
  try {
    const body = new FormData()
    body.append('photos[]', file.value)
    const d = (await http.post('/products/draft', body)).data.data
    Object.assign(product.value, { title: d.title ?? product.value.title, description: d.description ?? '', tags: d.tags ?? [], collection_ids: d.collection_ids ?? [] })
    drafted.value = true
    countAiAction(auth.profile?.ai)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    drafting.value = false
  }
}
const productStarted = computed(() => !!file.value || !!product.value.title.trim() || product.value.price !== '')

// ── Étape 3 : WhatsApp relié à Meta ──
const whatsapp = ref(null)
const connecting = ref(false)
const waAccount = computed(() => (whatsapp.value?.account?.status === 'active' ? whatsapp.value.account : null))
async function connect() {
  connecting.value = true
  await connectWhatsappDirect(router, '/gestion/bienvenue?etape=3')
}

async function load() {
  try {
    const [r, g, w] = await Promise.all([
      http.get('/delivery-rates'),
      http.get('/setup-guide'),
      http.get('/whatsapp').catch(() => ({ data: { data: null } })),
    ])
    rates.value = r.data.data
    base.value = rates.value.base_fee ?? 1500
    ship.value = rates.value.expedition?.fee != null
    gare.value = rates.value.expedition?.fee ?? 1000
    done.value = g.data.data.welcome?.steps ?? done.value
    whatsapp.value = w.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function saveDelivery() {
  await http.put('/delivery-rates', { base_fee: Math.max(0, Number(base.value) || 0), expedition_fee: ship.value ? gare.value : null })
  for (const [placeId, fee] of Object.entries(changed.value)) {
    await http.post('/delivery-rates/remember', { place_id: Number(placeId), fee })
  }
  changed.value = {}
  done.value.delivery = true
}

async function saveProduct() {
  productErrors.value = {}
  if (!productStarted.value) return true // rien de saisi : l'étape attend
  if (!file.value) {
    error.value = 'Ajoutez la photo du produit : vos clients commandent sur la photo.'
    return false
  }
  if (!product.value.title.trim()) productErrors.value.title = 'Indiquez le nom du produit.'
  if (product.value.price === '' || Number(product.value.price) < 0) productErrors.value.price = 'Indiquez le prix.'
  if (Object.keys(productErrors.value).length) return false
  const { data } = await http.post('/products', {
    title: product.value.title.trim(), description: product.value.description, status: 'active',
    tags: product.value.tags, collection_ids: product.value.collection_ids, options: [],
    variants: [{ options: [], price: Number(product.value.price), on_hand: Math.max(0, Number(product.value.quantity) || 0) }],
  })
  const body = new FormData()
  body.append('image', file.value)
  await http.post(`/products/${data.data.id}/images`, body)
  created.value = { title: data.data.title, price: Number(product.value.price) }
  file.value = null
  product.value = { title: '', description: '', price: '', quantity: 1, tags: [], collection_ids: [] }
  done.value.product = true
  return true
}

async function next() {
  if (step.value === 4) return router.push('/gestion')
  busy.value = true
  error.value = ''
  try {
    if (step.value === 1) await saveDelivery()
    if (step.value === 2 && !(await saveProduct())) return
    step.value++
  } catch (e) {
    productErrors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const skip = () => { error.value = ''; step.value++ }
const back = () => { error.value = ''; step.value = Math.max(0, step.value - 1) }

// Terminé ou remis à plus tard : l'accueil n'est plus proposé, les étapes restent dans « Bien démarrer »
async function finish() {
  try {
    done.value = (await http.post('/setup-guide/welcome')).data.data.steps ?? done.value
  } catch {
    // Sans réponse : l'accueil sera proposé une autre fois
  }
}
async function later() {
  await finish()
  router.push('/gestion')
}

const cta = computed(() => (step.value === 0 ? 'C\'est parti' : step.value === 4 ? 'Ouvrir mon tableau de bord' : step.value === 3 ? 'Terminer' : 'Continuer'))
const money = (n) => `${Number(n || 0).toLocaleString('fr-FR')} F`
const recap = computed(() => [
  {
    step: 1, label: 'Tarifs de livraison', done: done.value.delivery,
    value: done.value.delivery ? `${rates.value?.home?.name ?? 'En ville'} ${money(base.value)}${ship.value ? ` · gare ${money(gare.value)}` : ''}` : 'Pas encore réglés',
  },
  { step: 2, label: 'Premier produit', done: done.value.product, value: created.value ? `${created.value.title} · ${money(created.value.price)}` : done.value.product ? 'Ajouté au catalogue' : 'Pas encore ajouté' },
  { step: 3, label: 'WhatsApp', done: done.value.whatsapp || !!waAccount.value, value: done.value.whatsapp || waAccount.value ? 'Relié à Meta' : 'Pas encore relié' },
])

function share() {
  const text = `Bonjour ! Découvrez ma boutique ${auth.store?.name ?? ''} : ${shopUrl.value}`
  window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank', 'noopener')
}
</script>

<template>
  <div class="flex min-h-dvh flex-col bg-slate-50">
    <!-- En-tête des étapes : retour, avancement, plus tard -->
    <header v-if="step > 0 && step < 4" class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 pb-3 pt-3">
      <div class="mx-auto flex max-w-md items-center justify-between gap-2">
        <button type="button" class="flex size-11 items-center justify-center rounded-xl bg-slate-100" aria-label="Étape précédente" @click="back">
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
        </button>
        <span class="text-sm font-bold text-slate-600">Étape {{ step }} sur 3</span>
        <button type="button" class="h-11 px-3 text-sm font-semibold text-emerald-700" @click="skip">Plus tard</button>
      </div>
      <div class="mx-auto mt-2 grid max-w-md grid-cols-3 gap-1.5">
        <div v-for="n in 3" :key="n" class="h-1.5 rounded-full" :class="n <= step ? 'bg-emerald-600' : 'bg-slate-200'" />
      </div>
    </header>

    <main class="mx-auto w-full max-w-md flex-1 space-y-5 px-5 pb-6 pt-5">
      <Alert v-if="error">{{ error }}</Alert>

      <!-- 0. Accueil -->
      <template v-if="step === 0">
        <section class="relative overflow-hidden rounded-3xl border border-emerald-100 bg-white pb-4 pl-1.5 pr-4 pt-4">
          <WelcomeArt name="hello-bg" class="absolute inset-0 size-full" />
          <div class="relative flex items-end gap-1">
            <WelcomeArt name="hello" class="h-48 w-36 shrink-0" />
            <div class="mb-16 flex-1 rounded-2xl rounded-bl-sm border-2 border-emerald-200 bg-emerald-50 p-3.5">
              <h1 class="text-2xl font-extrabold leading-tight text-night">Bienvenue{{ firstName ? `, ${firstName}` : '' }} !</h1>
              <p class="mt-1 text-base font-medium text-emerald-800">Votre boutique est créée.</p>
            </div>
          </div>
          <a v-if="shopUrl" :href="shopUrl" target="_blank" rel="noopener" class="relative ml-2.5 mt-1 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-2 text-sm font-bold text-emerald-800">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7" /><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7" /></svg>
            {{ shopHost }}
          </a>
        </section>

        <div>
          <h2 class="text-lg font-extrabold">Plus que 3 étapes pour vendre</h2>
          <p class="explain-detail">Environ 5 minutes. Vous pouvez en garder une pour plus tard.</p>
        </div>
        <ol class="space-y-2.5">
          <li class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
              <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h11v9H3z" /><path d="M14 10h4l3 3v3h-7" /><circle cx="7" cy="18" r="2" /><circle cx="17" cy="18" r="2" /></svg>
            </span>
            <span class="flex-1"><b class="block text-base">Vos tarifs de livraison</b><span class="explain-detail">Le bon prix s'ajoute à chaque commande</span></span>
            <span class="text-xs font-bold text-slate-600">1 min</span>
          </li>
          <li class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-ocre-50 text-ocre-700">
              <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z" /><circle cx="7.5" cy="7.5" r="1.5" /></svg>
            </span>
            <span class="flex-1"><b class="block text-base">Votre premier produit</b><span class="explain-detail">Une photo et un prix, l'IA fait le reste</span></span>
            <span class="text-xs font-bold text-slate-600">2 min</span>
          </li>
          <li class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-3.5">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700">
              <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.8 7L3 21l2-6A8 8 0 1 1 21 12z" /></svg>
            </span>
            <span class="flex-1"><b class="block text-base">Votre WhatsApp relié à Meta</b><span class="explain-detail">Confirmations et reçus envoyés tout seuls</span></span>
            <span class="text-xs font-bold text-slate-600">3 min</span>
          </li>
        </ol>
      </template>

      <!-- 1. Tarifs de livraison -->
      <template v-else-if="step === 1">
        <WelcomeArt name="delivery" class="block w-full rounded-3xl bg-emerald-50" />
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Tarifs de livraison</p>
          <h1 class="mt-1 text-2xl font-extrabold leading-tight">Combien coûte la livraison dans {{ rates?.home?.name ?? 'votre ville' }} ?</h1>
          <p class="explain-detail mt-1">Votre prix habituel. Il s'ajoute tout seul à chaque commande.</p>
        </div>
        <label class="flex items-center justify-center gap-2 rounded-2xl border-2 border-emerald-600 bg-white p-4">
          <span class="sr-only">Prix de livraison habituel</span>
          <input v-model.number="base" class="w-40 bg-transparent text-center text-3xl font-black outline-none" type="number" min="0" step="100" inputmode="numeric" />
          <span class="text-lg font-bold text-slate-600">F CFA</span>
        </label>
        <div class="grid grid-cols-4 gap-2">
          <button v-for="q in QUICK" :key="q" type="button" class="h-12 rounded-xl border-2 text-sm font-bold" :class="Number(base) === q ? 'border-emerald-600 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-white'" :aria-pressed="Number(base) === q" @click="base = q">{{ q.toLocaleString('fr-FR') }}</button>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div v-if="communes.length" class="flex items-center gap-3 p-4">
            <span class="flex-1"><b class="block text-base">Certaines communes coûtent plus cher</b><span class="explain-detail">Les communes éloignées, en un geste.</span></span>
            <button type="button" role="switch" :aria-checked="openCommunes" aria-label="Prix différents selon la commune" class="flex h-7.5 w-12.5 shrink-0 items-center rounded-full p-1" :class="openCommunes ? 'justify-end bg-emerald-600' : 'justify-start bg-slate-400'" @click="openCommunes = !openCommunes"><span class="size-5.5 rounded-full bg-white shadow" /></button>
          </div>
          <ul v-if="openCommunes" class="border-t border-slate-200">
            <li v-for="c in communes" :key="c.id" class="flex items-center gap-2 border-b border-slate-100 py-2 pl-4 pr-3">
              <span class="flex-1 text-base font-semibold">{{ c.name }}</span>
              <button type="button" class="size-11 rounded-xl border border-slate-200 bg-slate-50 text-2xl font-bold" :aria-label="`Baisser le prix de ${c.name}`" @click="bump(c, -500)">−</button>
              <span class="w-20 text-center text-base font-extrabold tabular-nums">{{ money(c.price) }}</span>
              <button type="button" class="size-11 rounded-xl border border-slate-200 bg-slate-50 text-2xl font-bold" :aria-label="`Augmenter le prix de ${c.name}`" @click="bump(c, 500)">+</button>
            </li>
          </ul>
          <div class="flex items-center gap-3 border-t border-slate-200 p-4">
            <span class="flex-1"><b class="block text-base">J'envoie aussi dans d'autres villes</b><span class="explain-detail">Bouaké, San Pedro… en car, depuis la gare.</span></span>
            <button type="button" role="switch" :aria-checked="ship" aria-label="Expéditions vers d'autres villes" class="flex h-7.5 w-12.5 shrink-0 items-center rounded-full p-1" :class="ship ? 'justify-end bg-emerald-600' : 'justify-start bg-slate-400'" @click="ship = !ship"><span class="size-5.5 rounded-full bg-white shadow" /></button>
          </div>
          <div v-if="ship" class="flex items-center gap-2 border-t border-slate-200 py-2 pl-4 pr-3">
            <span class="flex-1 text-base font-semibold">Jusqu'à la gare</span>
            <button type="button" class="size-11 rounded-xl border border-slate-200 bg-slate-50 text-2xl font-bold" aria-label="Baisser le prix jusqu'à la gare" @click="gare = Math.max(0, gare - 500)">−</button>
            <span class="w-20 text-center text-base font-extrabold tabular-nums">{{ money(gare) }}</span>
            <button type="button" class="size-11 rounded-xl border border-slate-200 bg-slate-50 text-2xl font-bold" aria-label="Augmenter le prix jusqu'à la gare" @click="gare += 500">+</button>
          </div>
        </div>
        <p class="rounded-xl bg-ocre-50 p-3 text-sm text-ocre-800">Pas besoin de tout remplir : quand vous changez un prix dans une commande, l'application propose de le retenir.</p>
      </template>

      <!-- 2. Premier produit -->
      <template v-else-if="step === 2">
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-ocre-700">Premier produit</p>
          <h1 class="mt-1 text-2xl font-extrabold leading-tight">Montrez-nous ce que vous vendez</h1>
          <p class="explain-detail mt-1">{{ ai.enabled ? 'Une photo suffit : l\'IA écrit le nom et la description.' : 'Une photo, un nom et un prix suffisent.' }}</p>
        </div>
        <div v-if="created" class="flex items-center gap-3 rounded-2xl border-2 border-emerald-200 bg-emerald-50 p-3.5">
          <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg></span>
          <span class="text-base font-semibold text-emerald-900">« {{ created.title }} » est dans votre catalogue.</span>
        </div>
        <p v-else-if="done.product" class="rounded-xl bg-emerald-50 p-3 text-base font-semibold text-emerald-900">Vous avez déjà des produits. Ajoutez-en un autre ou continuez.</p>

        <label v-if="!preview" class="flex cursor-pointer flex-col items-center gap-3 rounded-3xl border-2 border-dashed border-ocre-100 bg-ocre-50 px-4 py-5 text-center">
          <input type="file" accept="image/*" capture="environment" class="sr-only" @change="pick" />
          <WelcomeArt name="camera" class="h-28 w-48" />
          <span class="flex h-13 w-full items-center justify-center rounded-xl bg-ocre-600 text-base font-bold text-white">Prendre une photo</span>
        </label>
        <label v-if="!preview" class="flex h-12 w-full cursor-pointer items-center justify-center rounded-xl border-2 border-ocre-100 bg-white text-sm font-bold text-ocre-800">
          <input type="file" accept="image/*" class="sr-only" @change="pick" />
          Choisir dans la galerie
        </label>
        <div v-else class="flex items-center gap-3.5">
          <label class="relative size-30 shrink-0 cursor-pointer overflow-hidden rounded-2xl bg-ocre-50">
            <input type="file" accept="image/*" class="sr-only" @change="pick" />
            <img :src="preview" alt="Photo du produit" class="size-full object-cover" />
            <span class="absolute bottom-1.5 left-1.5 rounded-full bg-night/85 px-2.5 py-1 text-xs font-semibold text-white">Changer</span>
          </label>
          <div class="flex-1 space-y-2">
            <span v-if="drafting" class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">L'IA regarde la photo…</span>
            <span v-else-if="drafted" class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">
              <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z" /></svg>
              Rempli par l'IA
            </span>
            <p class="explain-detail">{{ drafted ? 'Vérifiez le nom et indiquez votre prix.' : 'Indiquez le nom et le prix.' }}</p>
          </div>
        </div>

        <label class="block">
          <span class="label">Nom du produit</span>
          <input v-model="product.title" class="input" maxlength="255" placeholder="Ex. Robe longue en wax" />
          <span v-if="productErrors.title" class="mt-1 block text-sm text-red-700">{{ productErrors.title }}</span>
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="label">Prix de vente (F)</span>
            <input v-model="product.price" class="input" type="number" min="0" inputmode="numeric" placeholder="0" />
            <span v-if="productErrors.price" class="mt-1 block text-sm text-red-700">{{ productErrors.price }}</span>
          </label>
          <div>
            <span class="label">En stock</span>
            <div class="flex h-12 items-center justify-between rounded-xl border border-slate-300 bg-white px-1">
              <button type="button" class="size-10 rounded-lg bg-slate-50 text-2xl font-bold" aria-label="Retirer une pièce" @click="product.quantity = Math.max(0, product.quantity - 1)">−</button>
              <span class="text-base font-extrabold tabular-nums">{{ product.quantity }}</span>
              <button type="button" class="size-10 rounded-lg bg-slate-50 text-2xl font-bold" aria-label="Ajouter une pièce" @click="product.quantity++">+</button>
            </div>
          </div>
        </div>
        <p class="explain-detail">Tailles et couleurs : ajoutez-les ensuite depuis le catalogue.</p>
        <p class="rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">D'après vos produits, l'application reconnaît votre activité et adapte ses conseils.</p>
      </template>

      <!-- 3. WhatsApp relié à Meta -->
      <template v-else-if="step === 3">
        <WelcomeArt name="whatsapp" class="block w-full rounded-3xl bg-emerald-50" />
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">WhatsApp</p>
          <h1 class="mt-1 text-2xl font-extrabold leading-tight">Reliez votre WhatsApp à Meta</h1>
          <p class="explain-detail mt-1">Vos clients reçoivent tout sans que vous ayez à écrire.</p>
        </div>
        <div v-if="waAccount" class="space-y-3 rounded-2xl border-2 border-emerald-200 bg-emerald-50 p-4">
          <div class="flex items-center gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg></span>
            <span><b class="block text-base text-night">WhatsApp relié</b><span class="text-sm text-emerald-800">{{ waAccount.display_phone }}<template v-if="waAccount.verified_name"> · {{ waAccount.verified_name }}</template></span></span>
          </div>
          <p class="text-sm text-emerald-900">Confirmations, suivi de livraison et reçus partent maintenant tout seuls.</p>
        </div>
        <template v-else>
          <ul class="space-y-2.5 rounded-2xl border border-slate-200 bg-white p-4">
            <li v-for="b in ['La confirmation de commande part toute seule', 'Le client suit sa livraison et reçoit son reçu', 'Vos produits dans le catalogue WhatsApp']" :key="b" class="flex items-start gap-2.5 text-base">
              <span class="mt-0.5 flex size-5.5 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-white"><svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg></span>
              {{ b }}
            </li>
          </ul>
          <p class="rounded-xl bg-slate-100 p-3 text-sm text-slate-700"><b class="text-slate-900">Il vous faut</b> votre numéro WhatsApp Business et votre compte Facebook. Choisissez ensuite votre compte, puis votre numéro.</p>
          <button type="button" class="h-13 w-full rounded-2xl bg-[#1877f2] text-base font-bold text-white" :disabled="connecting" @click="connect">{{ connecting ? 'Ouverture de Facebook…' : 'Continuer avec Facebook' }}</button>
          <RouterLink to="/gestion/whatsapp" class="block text-center text-sm font-semibold text-emerald-700 underline">Pas encore prêt ? Relier par QR code en attendant</RouterLink>
        </template>
      </template>

      <!-- 4. C'est prêt -->
      <template v-else>
        <WelcomeArt name="done" class="block w-full rounded-3xl bg-emerald-50" />
        <div class="text-center">
          <h1 class="text-2xl font-extrabold leading-tight">{{ auth.store?.name }} est prête à vendre</h1>
          <p class="explain-detail mt-1">Touchez une ligne pour la changer.</p>
        </div>
        <ul class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <li v-for="r in recap" :key="r.step" class="border-b border-slate-100 last:border-0">
            <button type="button" class="flex min-h-15 w-full items-center gap-3 px-4 py-2.5 text-left" @click="step = r.step">
              <span class="size-3 shrink-0 rounded-full" :class="r.done ? 'bg-emerald-600' : 'border-2 border-ocre-600 bg-white'" />
              <span class="flex-1"><span class="block text-xs text-slate-600">{{ r.label }}</span><b class="block text-base">{{ r.value }}</b></span>
              <span v-if="r.done" class="text-sm font-semibold text-emerald-700">Modifier</span>
              <span v-else class="rounded-full bg-ocre-50 px-2.5 py-1 text-xs font-bold text-ocre-800">À faire</span>
            </button>
          </li>
        </ul>
        <div v-if="shopUrl" class="space-y-2.5 rounded-2xl border-2 border-emerald-200 bg-white p-4">
          <span class="block text-sm text-slate-600">Votre boutique</span>
          <b class="block text-base text-night">{{ shopHost }}</b>
          <button type="button" class="btn-brand w-full" @click="share">Partager le lien sur WhatsApp</button>
        </div>
        <p class="explain-detail text-center">Ensuite, dans « Bien démarrer » : vos jours de livraison, vos livreurs et la boutique en ligne.</p>
      </template>
    </main>

    <footer class="sticky bottom-0 border-t border-slate-200 bg-white px-5 pb-5 pt-3">
      <div class="mx-auto max-w-md space-y-1.5">
        <button type="button" class="h-13 w-full rounded-2xl bg-emerald-700 text-base font-bold text-white" :disabled="busy" @click="next">{{ busy ? 'Enregistrement…' : cta }}</button>
        <button v-if="step === 0" type="button" class="h-11 w-full text-sm font-semibold text-slate-600" @click="later">Plus tard</button>
      </div>
    </footer>
  </div>
</template>
