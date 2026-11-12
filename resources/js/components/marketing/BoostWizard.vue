<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Assistant Boost : la pub Facebook et Instagram créée depuis l'application en 4 écrans. 1. Que booster (un produit,
 * plusieurs en carrousel, le catalogue) ; 2. budget par jour et durée ; 3. public (lieux livrés, âge, femmes ou
 * hommes) ; 4. aperçu, texte, récapitulatif et lancement. La pub est payée à Meta par la carte du marchand.
 *
 * @prop products  meilleures ventes (id, title, image, price, sold)
 * @prop from      boost à relancer (mêmes réglages), ou null
 * @prop productId produit conseillé à booster tout de suite, ou null
 */
const props = defineProps({
  products: { type: Array, default: () => [] },
  from: { type: Object, default: null },
  productId: { type: Number, default: null },
})
const emit = defineEmits(['close', 'launched'])

const setup = ref(null)
const error = ref('')
const busy = ref(false)
const step = ref(0)
const STEPS = ['Produit', 'Budget', 'Public', 'Lancer']

const kind = ref(props.from?.kind ?? 'product')
const one = ref(props.from?.kind === 'product' ? props.from.product_ids[0] : props.productId ?? props.products[0]?.id ?? null)
const many = ref(props.from?.kind === 'products' ? [...props.from.product_ids] : props.products.slice(0, 3).map((p) => p.id))
const collection = ref(props.from?.collection_id ?? null)
const budget = ref(props.from?.daily_budget ?? 2500)
const days = ref(props.from?.days ?? 7)
const zones = ref(props.from?.zones ?? [])
const ages = [[18, 24], [18, 45], [25, 45], [18, 65]]
const age = ref(props.from ? [props.from.age_min, props.from.age_max] : [18, 45])
const gender = ref(props.from?.gender ?? 'all')
const adAccount = ref(props.from?.ad_account_id ?? '')
const page = ref(props.from?.page_id ?? '')
const message = ref(props.from?.message ?? '')
const audience = ref(null)

// Produits proposés : meilleures ventes, plus ceux trouvés par la recherche
const extra = ref([])
const search = ref('')
let timer
function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!search.value.trim()) return (extra.value = [])
    const rows = (await http.get('/products', { params: { search: search.value, status: 'active', per_page: 8 } })).data.data
    extra.value = rows.map((p) => ({ id: p.id, title: p.title, image: p.thumb ?? null, price: p.price_min ?? null, sold: 0 }))
  }, 250)
}
const known = ref([...props.products, ...(props.from?.products ?? []).filter((p) => !props.products.some((k) => k.id === p.id))])
const list = computed(() => [...known.value, ...extra.value.filter((p) => !known.value.some((k) => k.id === p.id))])
function remember(p) {
  if (!known.value.some((k) => k.id === p.id)) known.value = [...known.value, p]
}
function pickOne(p) {
  remember(p)
  one.value = p.id
}
function toggle(p) {
  remember(p)
  const at = many.value.indexOf(p.id)
  if (at >= 0) many.value = many.value.filter((id) => id !== p.id)
  else if (many.value.length < 10) many.value = [...many.value, p.id]
}
const chosen = computed(() => (kind.value === 'product' ? known.value.filter((p) => p.id === one.value) : many.value.map((id) => known.value.find((p) => p.id === id)).filter(Boolean)))

onMounted(async () => {
  try {
    setup.value = (await http.get('/marketing/boost/setup')).data.data
    const s = setup.value
    if (!adAccount.value) adAccount.value = s.ad_accounts.find((a) => a.usable)?.id ?? ''
    if (!page.value) page.value = s.pages[0]?.id ?? ''
    if (!props.from && !zones.value.length && s.zones.length) zones.value = [s.zones[0].key]
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const account = computed(() => setup.value?.ad_accounts.find((a) => a.id === adAccount.value) ?? null)
const catalogReady = computed(() => !!setup.value?.catalog_id && !!setup.value?.pixel_id)
// Ce qui empêche de lancer : dit simplement, avec où le régler
const blocker = computed(() => {
  const s = setup.value
  if (!s) return null
  if (!s.connected) return { text: 'Connecte d\'abord ton compte Facebook.', to: '/gestion/marketing/suivi', label: 'Connecter Facebook' }
  if (!s.ad_accounts.some((a) => a.usable)) return { text: 'Aucun compte publicitaire actif en FCFA, euros ou dollars.', href: 'https://business.facebook.com/settings/ad-accounts', label: 'Créer un compte publicitaire ↗' }
  if (account.value && !account.value.card) return { text: 'Ajoute une carte à ton compte publicitaire : la pub est payée à Meta.', href: 'https://business.facebook.com/billing_hub/payment_settings', label: 'Ajouter une carte ↗' }
  if (!s.pages.length) return { text: 'Il faut une page Facebook pour publier la pub.', href: 'https://www.facebook.com/pages/create', label: 'Créer une page ↗' }
  return null
})

const total = computed(() => budget.value * days.value)
const collectionName = computed(() => setup.value?.collections.find((c) => c.id === collection.value)?.name ?? 'Tout le catalogue')
const catalogCount = computed(() => (collection.value ? setup.value?.collections.find((c) => c.id === collection.value)?.products : setup.value?.catalog_products) ?? 0)

// Texte proposé selon ce qu'on booste (modifiable)
function proposeText() {
  if (kind.value === 'product' && chosen.value[0]) {
    const p = chosen.value[0]
    return `✨ ${p.title} est disponible !${p.price ? ` ${money(p.price)}.` : ''} Livraison rapide chez toi. Commande ici 👇`
  }
  if (kind.value === 'products') return '🛍️ Notre sélection du moment : fais défiler et choisis ! Livraison rapide chez toi 👇'
  return '🛍️ Découvre nos nouveautés et nos meilleures ventes. Livraison rapide chez toi 👇'
}
const edited = ref(!!props.from)
// Textes écrits par l'IA : 3 versions pour 1 action (le solde d'actions IA)
const versions = ref([])
const writing = ref(false)
async function writeWithAi() {
  writing.value = true
  error.value = ''
  try {
    const body = kind.value === 'catalog' ? { kind: 'catalog', collection_id: collection.value } : { kind: kind.value, product_ids: kind.value === 'product' ? [one.value] : many.value }
    versions.value = (await http.post('/marketing/boost/texts', body)).data.data
    if (versions.value[0]) {
      message.value = versions.value[0]
      edited.value = true
    }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    writing.value = false
  }
}
watch([kind, one], () => !edited.value && (message.value = proposeText()), { immediate: !props.from })

// Taille du public estimée par Meta
watch([zones, age, gender, adAccount, step], async () => {
  if (step.value !== 2 || !adAccount.value) return
  try {
    audience.value = (await http.post('/marketing/boost/audience', { ad_account_id: adAccount.value, zones: zones.value, age_min: age.value[0], age_max: age.value[1], gender: gender.value })).data.data
  } catch {
    audience.value = null
  }
}, { deep: true })
const people = (n) => (n >= 1e6 ? `${(n / 1e6).toLocaleString('fr-FR', { maximumFractionDigits: 1 })} million${n >= 2e6 ? 's' : ''}` : n.toLocaleString('fr-FR'))

const canNext = computed(() => {
  if (step.value === 0) return kind.value === 'product' ? !!one.value : kind.value === 'products' ? many.value.length >= 2 : catalogReady.value
  return true
})
function toggleZone(key) {
  zones.value = zones.value.includes(key) ? zones.value.filter((k) => k !== key) : [...zones.value, key]
}

async function launch() {
  busy.value = true
  error.value = ''
  try {
    const body = {
      kind: kind.value, daily_budget: budget.value, days: days.value, zones: zones.value, age_min: age.value[0], age_max: age.value[1],
      gender: gender.value, message: message.value, ad_account_id: adAccount.value, page_id: page.value,
      ...(kind.value === 'catalog' ? { collection_id: collection.value } : { product_ids: kind.value === 'product' ? [one.value] : many.value }),
    }
    emit('launched', (await http.post('/marketing/boosts', body)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const preview = computed(() => chosen.value[0]?.image ?? null)
</script>

<template>
  <div class="space-y-3">
    <!-- Étapes -->
    <div class="grid grid-cols-4 gap-1" role="list">
      <button v-for="(label, k) in STEPS" :key="label" type="button" role="listitem" class="grid gap-1 text-left text-xs font-bold" :class="k === step ? 'text-[#1877f2]' : 'text-slate-500'" :disabled="k > step" @click="step = k">
        <i class="block h-1.5 rounded-full" :class="k <= step ? 'bg-[#1877f2]' : 'bg-slate-200'" />{{ label }}
      </button>
    </div>

    <p v-if="error" class="rounded-xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ error }}</p>
    <p v-if="!setup && !error" class="p-4 text-center text-sm text-slate-500">Chargement…</p>

    <template v-if="setup">
      <div v-if="blocker" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
        <p class="font-bold">{{ blocker.text }}</p>
        <RouterLink v-if="blocker.to" :to="blocker.to" class="btn-brand mt-2 w-full justify-center">{{ blocker.label }}</RouterLink>
        <a v-else :href="blocker.href" target="_blank" rel="noopener" class="mt-2 block rounded-xl bg-[#1877f2] py-2.5 text-center text-sm font-black text-white">{{ blocker.label }}</a>
      </div>

      <!-- 1. Que booster ? -->
      <section v-if="step === 0" class="space-y-3">
        <h3 class="font-display text-base font-extrabold">Que veux-tu booster ?</h3>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="[k, icon, label, sub] in [['product', '🎯', 'Un produit', '1 photo'], ['products', '🎠', 'Plusieurs', '2 à 10'], ['catalog', '🗂️', 'Catalogue', 'tout ou partie']]" :key="k" type="button"
            class="grid justify-items-center gap-0.5 rounded-xl border-2 px-1 py-2.5 text-sm font-extrabold" :class="kind === k ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="kind = k">
            <span class="text-xl" aria-hidden="true">{{ icon }}</span>{{ label }}<span class="text-xs font-semibold text-slate-500">{{ sub }}</span>
          </button>
        </div>

        <template v-if="kind !== 'catalog'">
          <div class="flex items-center justify-between">
            <p class="text-sm font-bold">{{ kind === 'product' ? 'Quel produit ?' : 'Quels produits ?' }}</p>
            <span v-if="kind === 'products'" class="text-xs text-slate-500">{{ many.length }} sur 10 max</span>
          </div>
          <input v-model="search" type="search" class="input" placeholder="Chercher un produit" @input="find" />
          <div class="space-y-1.5">
            <button v-for="p in list" :key="p.id" type="button" class="flex w-full items-center gap-2.5 rounded-xl border-2 p-2 text-left"
              :class="(kind === 'product' ? one === p.id : many.includes(p.id)) ? 'border-[#1877f2] bg-[#e7f0fe]' : 'border-slate-200'"
              @click="kind === 'product' ? pickOne(p) : toggle(p)">
              <img v-if="p.image" :src="p.image" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" loading="lazy">
              <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-slate-100" aria-hidden="true">📷</span>
              <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ p.title }}</b><span class="text-xs text-slate-500">{{ [p.price ? money(p.price) : null, p.sold ? `${p.sold} vendu${p.sold > 1 ? 's' : ''}` : null].filter(Boolean).join(' · ') }}</span></span>
              <span v-if="kind === 'products'" class="grid h-6 w-6 shrink-0 place-items-center rounded-md border-2 text-xs font-black text-white" :class="many.includes(p.id) ? 'border-[#1877f2] bg-[#1877f2]' : 'border-slate-300'">✓</span>
            </button>
          </div>
          <p v-if="kind === 'products' && many.length < 2" class="text-xs text-amber-700">Choisis au moins 2 produits.</p>
        </template>

        <template v-else>
          <p class="text-sm font-bold">Quelle partie du catalogue ?</p>
          <div class="flex flex-wrap gap-1.5">
            <button type="button" class="rounded-full border-2 px-3 py-1.5 text-sm font-bold" :class="collection === null ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="collection = null">Tout · {{ setup.catalog_products }}</button>
            <button v-for="c in setup.collections" :key="c.id" type="button" class="rounded-full border-2 px-3 py-1.5 text-sm font-bold" :class="collection === c.id ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="collection = c.id">{{ c.name }} · {{ c.products }}</button>
          </div>
          <p v-if="catalogReady" class="text-xs text-emerald-700">✓ Catalogue et pixel reliés. Meta montre à chacun les produits en stock qui lui plaisent.</p>
          <p v-else class="rounded-xl bg-amber-50 p-2.5 text-sm text-amber-900">Branche d'abord le pixel et le catalogue. <RouterLink to="/gestion/marketing/suivi" class="font-bold underline">Les brancher</RouterLink></p>
        </template>
      </section>

      <!-- 2. Budget -->
      <section v-if="step === 1" class="space-y-3">
        <h3 class="font-display text-base font-extrabold">Combien par jour ?</h3>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="(b, k) in setup.budgets" :key="b" type="button" class="rounded-xl border-2 py-2.5 text-sm font-extrabold tabular-nums" :class="budget === b ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="budget = b">
            {{ money(b) }}<span class="block text-xs font-semibold text-slate-500">{{ ['tester', 'conseillé', 'plus'][k] }}</span>
          </button>
        </div>
        <h3 class="font-display text-base font-extrabold">Pendant combien de jours ?</h3>
        <div class="grid grid-cols-3 gap-2">
          <button v-for="d in setup.days" :key="d" type="button" class="rounded-xl border-2 py-2.5 text-sm font-extrabold" :class="days === d ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="days = d">{{ d }} jours</button>
        </div>
        <div class="rounded-xl bg-slate-50 p-3">
          <p class="text-xs text-slate-500">Total maximum payé à Meta</p>
          <p class="font-display text-2xl font-extrabold tabular-nums">{{ money(total) }}</p>
          <p class="text-xs text-slate-500">Pause ou arrêt à tout moment.</p>
        </div>
      </section>

      <!-- 3. Public -->
      <section v-if="step === 2" class="space-y-3">
        <h3 class="font-display text-base font-extrabold">Qui voit la pub ?</h3>
        <p class="text-xs text-slate-500">Là où tu livres. Rien de coché : toute la Côte d'Ivoire.</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="z in setup.zones" :key="z.key" type="button" class="rounded-full border-2 px-3 py-1.5 text-sm font-bold" :class="zones.includes(z.key) ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="toggleZone(z.key)">{{ z.name }}</button>
        </div>
        <p class="text-sm font-bold">Âge</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="a in ages" :key="a.join()" type="button" class="rounded-full border-2 px-3 py-1.5 text-sm font-bold" :class="age[0] === a[0] && age[1] === a[1] ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="age = a">{{ a[0] }} – {{ a[1] }} ans</button>
        </div>
        <p class="text-sm font-bold">Qui</p>
        <div class="flex flex-wrap gap-1.5">
          <button v-for="[g, label] in [['all', 'Tout le monde'], ['women', 'Femmes'], ['men', 'Hommes']]" :key="g" type="button" class="rounded-full border-2 px-3 py-1.5 text-sm font-bold" :class="gender === g ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="gender = g">{{ label }}</button>
        </div>
        <p v-if="audience" class="rounded-xl bg-slate-50 p-2.5 text-sm">👥 Public estimé par Meta : <b>{{ people(audience.lower) }} à {{ people(audience.upper) }}</b> personnes</p>
      </section>

      <!-- 4. Aperçu et lancement -->
      <section v-if="step === 3" class="space-y-3">
        <div class="overflow-hidden rounded-xl border border-slate-200">
          <div class="flex items-center gap-2 p-2.5"><span class="grid h-8 w-8 place-items-center rounded-full bg-emerald-700 text-xs font-black text-white">{{ (setup.pages.find((p) => p.id === page)?.name ?? '?').slice(0, 2).toUpperCase() }}</span><span><b class="block text-sm">{{ setup.pages.find((p) => p.id === page)?.name }}</b><span class="text-xs text-slate-500">Sponsorisé</span></span></div>
          <p class="whitespace-pre-line px-2.5 pb-2.5 text-sm">{{ message }}</p>
          <img v-if="kind === 'product' && preview" :src="preview" alt="" class="aspect-square w-full object-cover">
          <div v-else-if="kind === 'products'" class="flex gap-2 overflow-x-auto px-2.5 pb-2.5">
            <div v-for="p in chosen" :key="p.id" class="w-[60%] shrink-0 overflow-hidden rounded-lg border border-slate-200">
              <img v-if="p.image" :src="p.image" alt="" class="aspect-square w-full object-cover"><span v-else class="grid aspect-square place-items-center bg-slate-100">📷</span>
              <p class="truncate p-1.5 text-xs font-bold">{{ p.title }}</p>
            </div>
          </div>
          <p v-else class="bg-slate-50 p-3 text-xs text-slate-500">Chacun voit des produits différents de ton catalogue.</p>
        </div>
        <div class="flex items-center justify-between gap-2">
          <label class="text-sm font-bold" for="boost-text">Texte de la pub</label>
          <button type="button" class="rounded-lg bg-[#e7f0fe] px-2.5 py-1.5 text-xs font-extrabold text-[#1877f2]" :disabled="writing" @click="writeWithAi">{{ writing ? 'L\'IA écrit…' : '✨ Écrire avec l\'IA' }}</button>
        </div>
        <div v-if="versions.length" class="flex gap-1.5">
          <button v-for="(v, k) in versions" :key="k" type="button" class="flex-1 rounded-lg border-2 py-1.5 text-xs font-bold" :class="message === v ? 'border-[#1877f2] bg-[#e7f0fe] text-[#1877f2]' : 'border-slate-200'" @click="message = v; edited = true">Version {{ k + 1 }}</button>
        </div>
        <textarea id="boost-text" v-model="message" rows="3" maxlength="600" class="input" @input="edited = true" />
        <p v-if="versions.length" class="text-xs text-slate-500">3 versions pour 1 action IA. Touche une version, puis corrige si besoin.</p>

        <div v-if="setup.ad_accounts.filter((a) => a.usable).length > 1 || setup.pages.length > 1" class="grid gap-2 sm:grid-cols-2">
          <label class="text-sm font-bold">Compte publicitaire
            <select v-model="adAccount" class="input mt-1"><option v-for="a in setup.ad_accounts.filter((x) => x.usable)" :key="a.id" :value="a.id">{{ a.name }} ({{ a.currency }})</option></select>
          </label>
          <label class="text-sm font-bold">Page Facebook
            <select v-model="page" class="input mt-1"><option v-for="p in setup.pages" :key="p.id" :value="p.id">{{ p.name }}</option></select>
          </label>
        </div>

        <dl class="space-y-1.5 rounded-xl bg-slate-50 p-3 text-sm">
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Boost</dt><dd class="text-right font-bold">{{ kind === 'product' ? chosen[0]?.title : kind === 'products' ? `${many.length} produits (carrousel)` : `${collectionName} (${catalogCount} produits)` }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Budget</dt><dd class="text-right font-bold">{{ money(budget) }} / jour · {{ days }} jours</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Public</dt><dd class="text-right font-bold">{{ zones.length ? setup.zones.filter((z) => zones.includes(z.key)).map((z) => z.name.replace(' (toutes communes)', '')).join(', ') : 'Côte d\'Ivoire' }} · {{ age[0] }} – {{ age[1] }} ans</dd></div>
          <div class="flex justify-between gap-3 border-t border-slate-200 pt-1.5"><dt class="text-slate-500">Payé à Meta (ta carte)</dt><dd class="font-display text-base font-extrabold">{{ money(total) }} max</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Mon solde</dt><dd class="text-right font-bold">{{ setup.fee.counted ? `${setup.fee.weight} commande${setup.fee.weight > 1 ? 's' : ''} retirée${setup.fee.weight > 1 ? 's' : ''} par commande venue du boost` : 'rien de retiré' }}</dd></div>
        </dl>
      </section>

      <div class="flex gap-2">
        <button v-if="step > 0" type="button" class="btn-secondary px-4" @click="step--">◀</button>
        <button v-if="step < 3" type="button" class="flex-1 rounded-xl bg-[#1877f2] py-3 text-base font-black text-white disabled:opacity-50" :disabled="!canNext" @click="step++">Continuer ▶</button>
        <button v-else type="button" class="flex-1 rounded-xl bg-[#1877f2] py-3 text-base font-black text-white disabled:opacity-50" :disabled="busy || !!blocker || !message.trim()" @click="launch">{{ busy ? 'Envoi à Meta…' : '🚀 Lancer le boost' }}</button>
      </div>
    </template>
  </div>
</template>
