<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'
import Modal from '../Modal.vue'
import BoostWizard from './BoostWizard.vue'
import BoostList from './BoostList.vue'
import MetaSetupSheet from './MetaSetupSheet.vue'
import { connectFacebookAds } from '../../lib/metaConnect'

/**
 * Menu Boost (étape 1) : la connexion Facebook et ce qu'il manque, la vérification du pixel, les meilleures ventes à
 * booster (lien suivi, texte et photo prêts, puis la création de la pub chez Meta) et les commandes venues des
 * boosts. Utilisé dans l'onglet « Boost » de l'accueil du téléphone et sur la page Boost.
 */
const data = ref(null)
const error = ref('')
const picked = ref(null)
const copied = ref('')
// Assistant (étape 2) : la pub créée et suivie depuis l'application
const auth = useAuthStore()
const assistant = computed(() => auth.profile?.features?.boost_ads === true)
const wizard = ref(null) // { from } quand l'assistant est ouvert
const listRef = ref(null)
const suggested = computed(() => data.value?.products.find((p) => p.id === data.value.suggested_id) ?? null)
function launched(boost) {
  wizard.value = null
  listRef.value?.add(boost)
}

// Connexion Facebook lancée d'ici : retour sur le menu Boost (?meta=… pour choisir le compte, le pixel, le catalogue)
const route = useRoute()
const router = useRouter()
const metaKey = ref('')
const connecting = ref(false)
const connected = ref(false)
async function load() {
  try {
    data.value = (await http.get('/marketing/boost')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(async () => {
  const { meta, erreur } = route.query
  if (erreur) error.value = String(erreur)
  if (meta) metaKey.value = String(meta)
  if (meta || erreur) router.replace({ query: {} })
  await load()
})
async function connectFacebook() {
  connecting.value = true
  error.value = ''
  try {
    await connectFacebookAds('boost')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    connecting.value = false
  }
}
function metaDone() {
  metaKey.value = ''
  connected.value = true
  load()
}

const c = computed(() => data.value?.connection ?? {})
// Voyants de la connexion : ce qui est prêt, ce qui manque (et comment le régler)
const lights = computed(() => [
  { ok: c.value.connected && !c.value.expired, label: 'Compte Facebook', info: c.value.connected ? (c.value.expired ? 'connexion expirée : reconnectez-vous' : c.value.business) : 'pas encore relié' },
  { ok: !!c.value.catalog_id, label: 'Catalogue', info: c.value.catalog_id ? (c.value.catalog_items !== null ? `${c.value.catalog_items} produit${c.value.catalog_items > 1 ? 's' : ''}, mis à jour toutes les heures` : 'relié') : 'à relier' },
  { ok: !!c.value.pixel_id, label: 'Pixel', info: c.value.pixel_id ? 'installé sur la boutique' : 'à installer' },
  { ok: c.value.conversions_ready, label: 'Ventes envoyées à Meta', info: c.value.conversions_ready ? `${c.value.conversions_sent} cette semaine, par le serveur` : 'en attente de la connexion' },
])
const ready = computed(() => lights.value.every((l) => l.ok))

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    const area = document.createElement('textarea')
    area.value = text
    document.body.appendChild(area)
    area.select()
    document.execCommand('copy')
    area.remove()
  }
  copied.value = key
  setTimeout(() => (copied.value === key) && (copied.value = ''), 2500)
}
</script>

<template>
  <div class="space-y-3">
    <p v-if="error" class="rounded-2xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ error }}</p>
    <p v-if="connected" class="rounded-2xl bg-emerald-50 p-3 text-sm font-bold text-emerald-800">✓ Facebook relié : pixel et catalogue branchés.</p>
    <p v-if="!data && !error" class="p-4 text-center text-sm text-slate-500">Chargement…</p>

    <template v-if="data">
      <!-- En tête : ce que fait le Boost -->
      <section class="rounded-[22px] bg-brand-grad p-4 text-white">
        <p class="text-lg font-black leading-snug">Boostez vos ventes sur Facebook et Instagram</p>
        <p class="mt-1 text-sm opacity-90">Vous voyez combien de commandes chaque pub vous apporte.</p>
      </section>

      <!-- Ma connexion Facebook -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <div class="flex items-center gap-2">
          <h3 class="text-base font-black">Ma connexion Facebook</h3>
          <span class="ml-auto rounded-full px-2 py-0.5 text-xs font-extrabold" :class="ready ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ ready ? 'Prête' : 'À compléter' }}</span>
        </div>
        <ul class="mt-2 space-y-1.5">
          <li v-for="l in lights" :key="l.label" class="flex items-center gap-2 text-sm">
            <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full text-xs font-black" :class="l.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ l.ok ? '✓' : '!' }}</span>
            <b class="font-bold">{{ l.label }}</b><span class="ml-auto truncate pl-2 text-right text-xs text-slate-500">{{ l.info }}</span>
          </li>
        </ul>
        <p v-if="c.error" class="mt-2 rounded-xl bg-amber-50 p-2.5 text-xs text-amber-800">⚠️ {{ c.error }}</p>
        <button v-if="!ready && data.available" type="button" class="mt-3 w-full rounded-xl bg-facebook py-3 text-base font-black text-white disabled:opacity-60" :disabled="connecting" @click="connectFacebook">{{ connecting ? 'Ouverture de Facebook…' : c.connected ? '🔄 Reconnecter Facebook' : '🔗 Connecter mon Facebook' }}</button>
        <RouterLink v-else-if="!ready" to="/gestion/marketing/suivi" class="btn-brand mt-3 w-full justify-center py-3 text-base font-black">Régler le pixel</RouterLink>
        <p class="mt-2 text-xs text-slate-500">La pub est payée à Meta, par la carte de votre compte publicitaire.</p>
      </section>

      <!-- Mes pixels vérifiés -->
      <section v-if="c.pixel_id" class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-base font-black">🔎 Ce que Meta reçoit <span class="font-normal text-slate-500">· 7 derniers jours</span></h3>
        <div v-if="data.pixel" class="mt-2 grid grid-cols-2 gap-1.5">
          <div v-for="e in data.pixel" :key="e.event" class="rounded-xl border border-slate-200 p-2 text-xs">
            <span>{{ e.count ? '✅' : '⚪' }}</span> <b>{{ e.label }}</b>
            <span class="block text-slate-500">{{ e.count }} cette semaine</span>
          </div>
        </div>
        <p v-else class="mt-2 text-xs text-slate-500">Vérification indisponible pour le moment.</p>
        <p v-if="data.pixel && !data.pixel.some((e) => e.count)" class="mt-2 rounded-xl bg-amber-50 p-2.5 text-xs text-amber-800">Rien reçu encore : ouvrez votre boutique, regardez un produit, revenez dans une heure.</p>
      </section>

      <!-- Assistant : créer la pub depuis l'application -->
      <section v-if="assistant" class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="font-display text-base font-extrabold">Nouveau boost</h3>
        <p class="mt-0.5 text-xs text-slate-500">Un produit, plusieurs ou votre catalogue, en 4 écrans.</p>
        <button type="button" class="mt-3 w-full rounded-xl bg-emerald-700 py-3 text-base font-black text-white" @click="wizard = { from: null }">Créer un boost</button>
      </section>

      <!-- Le produit à booster : il se vend, il a du stock, il a une bonne marge -->
      <section v-if="assistant && suggested" class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="font-display text-base font-extrabold">Le produit à booster</h3>
        <div class="mt-2 flex items-center gap-3">
          <img v-if="suggested.image" :src="suggested.image" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover">
          <span class="min-w-0 flex-1"><b class="block truncate text-sm">{{ suggested.title }}</b><span class="text-xs text-slate-500">{{ money(suggested.price) }}{{ suggested.margin ? ` · marge ${money(suggested.margin)}` : '' }}</span></span>
        </div>
        <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-bold">
          <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">⭐ {{ suggested.sold }} vendu{{ suggested.sold > 1 ? 's' : '' }} ce mois</span>
          <span v-if="suggested.stock !== null" class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">📦 {{ suggested.stock }} en stock</span>
          <span v-if="suggested.margin" class="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-800">💰 bonne marge</span>
        </div>
        <button type="button" class="mt-3 w-full rounded-xl bg-emerald-700 py-3 text-base font-black text-white" @click="wizard = { from: null, productId: suggested.id }">Booster ce produit</button>
      </section>

      <!-- Booster un produit (à la main, chez Meta) -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-base font-black">{{ assistant ? 'Ou le faire moi-même chez Meta' : 'Booster un produit' }}</h3>
        <p class="mt-0.5 text-xs text-slate-500">Touchez un produit : lien suivi, texte et photo prêts.</p>
        <div class="mt-2 grid grid-cols-3 gap-2">
          <button v-for="p in data.products" :key="p.id" type="button" class="overflow-hidden rounded-xl border-2 text-left" :class="picked?.id === p.id ? 'border-emerald-700' : 'border-slate-200'" @click="picked = picked?.id === p.id ? null : p">
            <img v-if="p.image" :src="p.image" alt="" class="aspect-square w-full object-cover" loading="lazy">
            <span v-else class="grid aspect-square w-full place-items-center bg-slate-100 text-2xl" aria-hidden="true">📷</span>
            <span class="block truncate px-1.5 pt-1 text-xs font-bold">{{ p.title }}</span>
            <span class="block px-1.5 pb-1.5 text-xs font-bold text-emerald-700">{{ p.sold ? `${p.sold} vendu${p.sold > 1 ? 's' : ''}` : 'nouveau' }}</span>
          </button>
        </div>
        <p v-if="!data.products.length" class="mt-2 text-sm text-slate-500">Ajoutez des produits à votre catalogue pour les booster.</p>

        <!-- Les 4 étapes pour booster le produit choisi -->
        <div v-if="picked" class="mt-3 space-y-2 rounded-2xl bg-emerald-50 p-3 text-sm">
          <p class="font-black">Booster « {{ picked.title }} »</p>
          <div class="flex items-center gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-black text-white">1</span>
            <span class="min-w-0 flex-1"><b>Le lien suivi</b><span class="block truncate text-xs text-slate-500">{{ picked.link }}</span></span>
            <button type="button" class="shrink-0 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-extrabold text-white" @click="copy(picked.link, 'link')">{{ copied === 'link' ? 'Copié ✓' : 'Copier' }}</button>
          </div>
          <div class="flex items-start gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-black text-white">2</span>
            <span class="min-w-0 flex-1"><b>Le texte de la pub</b><span class="block whitespace-pre-line text-xs text-slate-600">{{ picked.text }}</span></span>
            <button type="button" class="shrink-0 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-extrabold text-white" @click="copy(picked.text, 'text')">{{ copied === 'text' ? 'Copié ✓' : 'Copier' }}</button>
          </div>
          <div v-if="picked.image_large" class="flex items-center gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-black text-white">3</span>
            <span class="min-w-0 flex-1"><b>La photo</b><span class="block text-xs text-slate-500">à mettre dans la pub</span></span>
            <a :href="picked.image_large" download class="shrink-0 rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-extrabold text-white">Télécharger</a>
          </div>
          <div class="rounded-xl bg-white p-2.5">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-700 text-xs font-black text-white">4</span>
              <b class="min-w-0 flex-1">Créer la pub chez Meta</b>
            </div>
            <p class="mt-1 text-xs text-slate-600">Collez le lien et le texte, ajoutez la photo, choisissez votre budget.</p>
            <a href="https://business.facebook.com/latest/ad_center/" target="_blank" rel="noopener" class="mt-2 block rounded-xl bg-emerald-700 py-2.5 text-center text-sm font-black text-white">Ouvrir Meta ↗</a>
          </div>
          <p class="text-xs text-slate-600">Les commandes venues de ce lien s'affichent dans « Mes boosts ».</p>
        </div>
      </section>

      <!-- Mes boosts : commandes venues des liens suivis -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-base font-black">Mes boosts <span class="font-normal text-slate-500">· 30 derniers jours</span></h3>
        <BoostList v-if="assistant" ref="listRef" class="mt-2" @relaunch="(b) => (wizard = { from: b })" />
        <div class="mt-2 grid grid-cols-2 gap-1.5">
          <div class="rounded-xl bg-slate-50 p-2.5"><b class="block text-lg font-black">{{ data.results.orders }}</b><span class="text-xs text-slate-500">commande{{ data.results.orders > 1 ? 's' : '' }} venue{{ data.results.orders > 1 ? 's' : '' }} d'un boost</span></div>
          <div class="rounded-xl bg-slate-50 p-2.5"><b class="block text-lg font-black">{{ money(data.results.amount) }}</b><span class="text-xs text-slate-500">de ventes</span></div>
        </div>
        <ul v-if="data.results.products.length" class="mt-2 divide-y divide-slate-100 text-sm">
          <li v-for="r in data.results.products" :key="r.id" class="flex items-center gap-2 py-2"><span class="min-w-0 flex-1 truncate font-bold">{{ r.title }}</span><span class="text-slate-500">{{ r.orders }} cmd</span><b>{{ money(r.amount) }}</b></li>
        </ul>
        <p v-else-if="!data.results.orders" class="mt-2 text-xs text-slate-500">Aucune commande venue d'un boost pour l'instant.</p>
      </section>

      <!-- Prix -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-base font-black">Prix</h3>
        <p class="mt-1 text-sm text-slate-600">Connexion, pixel et liens suivis : <b>gratuits</b>. La pub : payée à Meta. Chaque commande venue d'un boost compte dans <RouterLink to="/gestion/solde" class="font-bold text-emerald-700 underline">votre solde</RouterLink>, comme une commande en live.</p>
      </section>
    </template>

    <MetaSetupSheet v-if="metaKey" :meta-key="metaKey" :current-pixel="data?.connection?.pixel_id ?? null" @done="metaDone" @close="metaKey = ''" />
    <Modal v-if="wizard" :title="wizard.from ? 'Relancer le boost' : 'Nouveau boost'" @close="wizard = null">
      <BoostWizard :products="data?.products ?? []" :from="wizard.from" :product-id="wizard.productId ?? null" @launched="launched" />
    </Modal>
  </div>
</template>
