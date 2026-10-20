<script setup>
import Icon from '../../../components/Icon.vue'
import NetworkMark from '../../../components/NetworkMark.vue'
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Réseaux sociaux : comptes connectés, publications des produits et ce que rapporte chaque réseau
const route = useRoute()
const router = useRouter()
const data = ref(null)
const days = ref(30)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const pages = ref(null) // pages Facebook à choisir après la connexion
const pagesKey = ref('')

const LABELS = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok' }
const STATUS = {
  published: ['Publié', 'bg-emerald-100 text-emerald-800'],
  failed: ['Échec', 'bg-red-100 text-red-800'],
  scheduled: ['Programmé', 'bg-sky-100 text-sky-800'],
  queued: ['En cours de publication', 'bg-sky-100 text-sky-800'],
  pending: ['En cours', 'bg-slate-100 text-slate-700'],
}

async function load() {
  try {
    data.value = (await http.get('/social', { params: { days: days.value } })).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

onMounted(async () => {
  const { pages: key, connecte, erreur, tiktok } = route.query
  if (erreur) error.value = String(erreur)
  if (connecte) notice.value = `${LABELS[connecte] ?? 'Compte'} connecté ✓`
  // Retour de TikTok : le compte est enregistré une fois confirmé ici, par la personne qui a lancé la connexion
  if (tiktok) {
    try {
      await http.post(`/social/tiktok/${tiktok}`)
      notice.value = `${LABELS.tiktok ?? 'TikTok'} connecté ✓`
    } catch (e) {
      error.value = errorMessage(e)
    }
  }
  if (key) {
    pagesKey.value = String(key)
    try {
      pages.value = (await http.get(`/social/pages/${key}`)).data.data
    } catch (e) {
      error.value = errorMessage(e)
    }
  }
  if (key || connecte || erreur || tiktok) router.replace({ query: {} })
  await load()
})

const account = (network) => data.value?.accounts.find((a) => a.network === network)
const networks = computed(() => Object.fromEntries((data.value?.stats.networks ?? []).map((n) => [n.network, n])))

async function connect(provider) {
  busy.value = true
  error.value = ''
  try {
    window.location.assign((await http.post(`/social/connect/${provider}`)).data.url)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

async function choose(page) {
  busy.value = true
  try {
    await http.post(`/social/pages/${pagesKey.value}`, { page_id: page.id })
    notice.value = page.instagram ? `Page ${page.name} et Instagram @${page.instagram.name} connectés ✓` : `Page ${page.name} connectée ✓ (pas de compte Instagram professionnel lié à cette page)`
    pages.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function disconnect(network) {
  if (!confirm(`Déconnecter ${LABELS[network]} ? Les publications programmées sur ce réseau échoueront.`)) return
  await http.delete(`/social/accounts/${network}`)
  await load()
}

async function retry(post) {
  try {
    await http.post(`/social/posts/${post.id}/retry`)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function cancel(post) {
  if (!confirm('Annuler cette publication programmée ?')) return
  await http.delete(`/social/posts/${post.id}`)
  await load()
}
</script>

<template>
  <div class="space-y-4">
    <MarketingTabs />
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <section v-if="data" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Comptes connectés</h3>
      <div class="grid gap-3 sm:grid-cols-3">
        <div v-for="a in data.accounts" :key="a.network" class="rounded-lg border p-3" :class="a.connected ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200'">
          <p class="flex items-center gap-1.5 font-medium"><NetworkMark :network="a.network" />{{ a.label }}</p>
          <p v-if="a.connected" class="text-slate-600">{{ a.network === 'instagram' ? '@' : '' }}{{ a.name }}</p>
          <p v-else class="text-slate-500">Pas connecté</p>
          <p v-if="a.error" class="mt-1 text-xs text-red-700">Dernière erreur : {{ a.error }}</p>
          <button v-if="a.connected" class="mt-2 text-xs text-red-600 underline" @click="disconnect(a.network)">Déconnecter</button>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-brand" :disabled="busy || !data.available.meta" @click="connect('meta')">
          {{ account('facebook')?.connected ? 'Changer de page Facebook' : 'Connecter Facebook et Instagram' }}
        </button>
        <button class="btn-secondary" :disabled="busy || !data.available.tiktok" @click="connect('tiktok')">
          {{ account('tiktok')?.connected ? 'Reconnecter TikTok' : 'Connecter TikTok' }}
        </button>
      </div>
      <p class="text-slate-500">
        Instagram se connecte avec la page Facebook : le compte Instagram doit être professionnel et lié à la page.
        Une fois connectés, les messages Messenger et Instagram arrivent dans <RouterLink to="/gestion/messages" class="underline">Messages</RouterLink>.
      </p>
      <p v-if="!data.available.meta || !data.available.tiktok" class="text-amber-800">
        {{ !data.available.meta ? 'La connexion Facebook / Instagram' : 'La connexion TikTok' }} n'est pas encore activée sur la plateforme.
      </p>
    </section>

    <section v-if="data" class="card space-y-3 p-4 text-sm">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="font-semibold">Ce que rapporte chaque réseau</h3>
        <select v-model.number="days" class="input ml-auto w-auto py-1" @change="load">
          <option :value="7">7 derniers jours</option>
          <option :value="30">30 derniers jours</option>
          <option :value="90">90 derniers jours</option>
        </select>
      </div>
      <div class="grid gap-3 sm:grid-cols-3">
        <div v-for="n in ['facebook', 'instagram', 'tiktok']" :key="n" class="rounded-lg border border-slate-200 p-3">
          <p class="flex items-center gap-1.5 font-medium"><NetworkMark :network="n" />{{ LABELS[n] }}</p>
          <p class="mt-1 text-2xl font-bold">{{ money(networks[n]?.revenue ?? 0) }}</p>
          <p class="text-slate-500">{{ networks[n]?.orders ?? 0 }} commande(s) · {{ networks[n]?.clicks ?? 0 }} clic(s) · {{ networks[n]?.posts ?? 0 }} publication(s)</p>
        </div>
      </div>
      <p class="text-slate-500">Commandes venues des liens publiés depuis l'application, des publicités Facebook et TikTok (suivi publicitaire), et des commandes saisies avec le canal du réseau.</p>
    </section>

    <section v-if="data" class="card p-4 text-sm">
      <h3 class="mb-1 font-semibold">Publications</h3>
      <p class="mb-2 text-slate-500">Pour publier un produit : ouvrez sa fiche dans Produits, puis « Publier sur les réseaux ».</p>
      <p v-if="!data.stats.posts.length" class="py-4 text-slate-500">Aucune publication sur la période.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="p in data.stats.posts" :key="p.id" class="space-y-1 py-3">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">{{ p.product ?? 'Produit supprimé' }}</span>
            <span class="text-slate-500">· {{ p.status === 'scheduled' ? 'programmé le ' + formatDateTime(p.scheduled_at) : formatDateTime(p.published_at ?? p.scheduled_at) }}</span>
            <button v-if="['failed', 'partial'].includes(p.status)" class="btn-secondary ml-auto px-2 py-1 text-xs" @click="retry(p)">Réessayer</button>
            <button v-if="p.status === 'scheduled'" class="btn-ghost ml-auto px-2 py-1 text-xs text-red-600" @click="cancel(p)">Annuler</button>
          </div>
          <div class="flex flex-wrap gap-2">
            <span v-for="n in p.networks" :key="n.network" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs">
              <NetworkMark :network="n.network" />
              <span class="rounded-full px-1.5" :class="STATUS[n.status]?.[1]">{{ STATUS[n.status]?.[0] ?? n.status }}</span>
              {{ n.clicks }} clic(s) · {{ n.orders }} cde · {{ money(n.revenue) }}
              <a v-if="n.url" :href="n.url" target="_blank" rel="noopener" class="text-emerald-700 underline">voir</a>
              <span v-if="n.error" class="text-red-700" :title="n.error"><Icon name="alert" :size="14" /></span>
            </span>
          </div>
        </li>
      </ul>
    </section>

    <Modal v-if="pages" title="Quelle page Facebook ?" @close="pages = null">
      <ul class="space-y-2 text-sm">
        <li v-for="p in pages" :key="p.id">
          <button class="w-full rounded-lg border border-slate-200 p-3 text-left hover:border-emerald-600" :disabled="busy" @click="choose(p)">
            <span class="flex items-center gap-1.5 font-medium"><NetworkMark network="facebook" />{{ p.name }}</span>
            <span class="block text-slate-500">{{ p.instagram ? `Instagram @${p.instagram.name}` : 'Pas de compte Instagram professionnel lié' }}</span>
          </button>
        </li>
      </ul>
    </Modal>
  </div>
</template>
