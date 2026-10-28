<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'

const data = ref(null)
const form = reactive({ meta_pixel_id: '', tiktok_pixel_id: '', ga4_id: '', meta_capi_token: '' })
const errors = ref({})
const error = ref('')
const saved = ref(false)

function fill(d) {
  data.value = d
  Object.assign(form, { meta_pixel_id: d.meta_pixel_id ?? '', tiktok_pixel_id: d.tiktok_pixel_id ?? '', ga4_id: d.ga4_id ?? '', meta_capi_token: '' })
}

const route = useRoute()
const router = useRouter()

onMounted(async () => {
  const { meta: key, erreur } = route.query
  if (erreur) error.value = String(erreur)
  if (key || erreur) router.replace({ query: {} })
  try {
    fill((await http.get('/marketing/tracking')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
  // Retour de Facebook : choix du compte Business, du pixel et du catalogue
  if (key) {
    metaKey.value = String(key)
    try {
      setup.value = { businesses: (await http.get(`/marketing/tracking/meta/${key}`)).data.data.businesses, business: '', assets: null, pixel: 'new', catalog: 'new' }
      if (setup.value.businesses.length === 1) await chooseBusiness(setup.value.businesses[0].id)
    } catch (e) {
      error.value = errorMessage(e)
    }
  }
})

// Pixel et catalogue Meta branchés en un clic
const metaKey = ref('')
const setup = ref(null)
const busy = ref(false)
const warnings = ref([])
const connected = ref(false)

async function connectMeta() {
  busy.value = true
  error.value = ''
  try {
    window.location.assign((await http.post('/marketing/tracking/meta/connect')).data.url)
  } catch (e) {
    error.value = errorMessage(e)
    busy.value = false
  }
}

async function chooseBusiness(id) {
  busy.value = true
  setup.value.business = id
  setup.value.assets = null
  try {
    const assets = (await http.get(`/marketing/tracking/meta/${metaKey.value}`, { params: { business: id } })).data.data.assets
    // Le pixel déjà saisi, sinon le seul existant, sinon un nouveau ; idem pour le catalogue
    const pick = (list, current) => list.find((x) => x.id === current)?.id ?? (list.length === 1 ? list[0].id : 'new')
    Object.assign(setup.value, { assets, pixel: pick(assets.pixels, data.value?.meta_pixel_id), catalog: pick(assets.catalogs, null) })
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function finishMeta() {
  busy.value = true
  error.value = ''
  try {
    const res = (await http.post(`/marketing/tracking/meta/${metaKey.value}`, { business_id: setup.value.business, pixel_id: setup.value.pixel, catalog_id: setup.value.catalog })).data
    fill(res.data)
    warnings.value = res.warnings
    connected.value = true
    setup.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function disconnectMeta() {
  if (!confirm('Déconnecter Facebook ? Le pixel reste sur la boutique ; le catalogue continue d\'être relu par Meta, mais les achats ne seront plus envoyés par le serveur (sauf jeton collé à la main).')) return
  fill((await http.delete('/marketing/tracking/meta')).data.data)
}

const copied = ref(false)
async function copyFeed() {
  await navigator.clipboard?.writeText(data.value.catalog_feed_url).catch(() => {})
  copied.value = true
}

async function renewFeed() {
  if (!confirm('Créer un nouveau lien ? L\'ancien ne marchera plus : remplacez-le dans le gestionnaire de catalogue de Meta.')) return
  fill((await http.post('/marketing/catalog-feed/renew')).data.data)
}

async function save(extra = {}) {
  errors.value = {}
  error.value = ''
  saved.value = false
  try {
    fill((await http.put('/marketing/tracking', {
      meta_pixel_id: form.meta_pixel_id.trim() || null,
      tiktok_pixel_id: form.tiktok_pixel_id.trim() || null,
      ga4_id: form.ga4_id.trim() || null,
      meta_capi_token: form.meta_capi_token.trim() || null,
      ...extra,
    })).data.data)
    saved.value = true
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-5">
    <MarketingTabs />
    <p class="text-sm text-slate-600">
      Mesurez les ventes de vos publicités : la boutique envoie les visites de produits, ajouts au panier, débuts de commande et achats
      à Facebook / Instagram, TikTok et Google Analytics. Vos publicités s'optimisent sur les vrais acheteurs.
    </p>
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="saved" tone="success">Réglages enregistrés.</Alert>
    <Alert v-if="connected" tone="success">Pixel et catalogue branchés ✓ Meta relira votre catalogue toutes les heures.</Alert>
    <Alert v-for="w in warnings" :key="w" tone="warning">{{ w }}</Alert>

    <section v-if="data?.meta_available" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Brancher Facebook et Instagram en un clic</h3>
      <template v-if="data.meta_connection">
        <p class="text-slate-700">
          Connecté au compte Business <strong>{{ data.meta_connection.business }}</strong> : pixel <span class="font-mono">{{ data.meta_pixel_id }}</span>,
          catalogue <span class="font-mono">{{ data.meta_connection.catalog_id }}</span> (relu toutes les heures).
        </p>
        <p :class="data.conversions_ready ? 'text-emerald-700' : 'text-red-700'">
          {{ data.conversions_ready ? 'Achats envoyés aussi par le serveur ✓' : 'Accès expiré : les achats ne sont plus envoyés par le serveur. Reconnectez Facebook.' }}
          <span v-if="data.meta_connection.expires_at && !data.meta_connection.expired" class="text-slate-500">
            (accès valable jusqu'au {{ new Date(data.meta_connection.expires_at).toLocaleDateString('fr-FR') }})
          </span>
        </p>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="btn-secondary" :disabled="busy" @click="connectMeta">Reconnecter Facebook</button>
          <button type="button" class="text-red-600 underline" @click="disconnectMeta">Déconnecter</button>
        </div>
      </template>
      <template v-else>
        <p class="text-slate-600">
          Connectez-vous avec Facebook : nous créons ou choisissons votre pixel et votre catalogue, ajoutons vos produits au catalogue
          (mis à jour toutes les heures) et envoyons les achats par le serveur. Rien à copier-coller.
        </p>
        <button type="button" class="btn-brand" :disabled="busy" @click="connectMeta">Se connecter avec Facebook</button>
      </template>
    </section>

    <Modal v-if="setup" title="Brancher le pixel et le catalogue" @close="setup = null">
      <div class="space-y-4 text-sm">
        <Field id="meta-business" label="Compte Business">
          <select id="meta-business" class="input" :value="setup.business" :disabled="busy" @change="chooseBusiness($event.target.value)">
            <option value="" disabled>Choisissez…</option>
            <option v-for="b in setup.businesses" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </Field>
        <template v-if="setup.assets">
          <Field id="meta-pixel-choice" label="Pixel">
            <select id="meta-pixel-choice" v-model="setup.pixel" class="input">
              <option value="new">Créer un nouveau pixel</option>
              <option v-for="p in setup.assets.pixels" :key="p.id" :value="p.id">{{ p.name }} ({{ p.id }})</option>
            </select>
          </Field>
          <Field id="meta-catalog-choice" label="Catalogue">
            <select id="meta-catalog-choice" v-model="setup.catalog" class="input">
              <option value="new">Créer un nouveau catalogue</option>
              <option v-for="c in setup.assets.catalogs" :key="c.id" :value="c.id">{{ c.name }}{{ c.products !== null ? ` (${c.products} article(s))` : '' }}</option>
            </select>
          </Field>
          <p class="text-slate-500">Vos produits seront ajoutés à ce catalogue et relus toutes les heures ; le pixel sera relié au catalogue.</p>
          <button type="button" class="btn-brand w-full" :disabled="busy" @click="finishMeta">{{ busy ? 'Branchement…' : 'Brancher' }}</button>
        </template>
        <p v-else-if="busy" class="text-slate-500">Chargement des pixels et catalogues…</p>
      </div>
    </Modal>

    <form v-if="data" class="space-y-5" @submit.prevent="save()">
      <section class="card space-y-4 p-4">
        <h3 class="font-semibold">Meta (Facebook et Instagram)</h3>
        <Field id="meta-pixel" label="Identifiant du pixel (Gestionnaire d'événements › Sources de données)" :error="errors.meta_pixel_id">
          <input id="meta-pixel" v-model="form.meta_pixel_id" class="input font-mono" inputmode="numeric" placeholder="123456789012345" />
        </Field>
        <Field id="meta-capi" label="Jeton d'accès de l'API Conversions (facultatif, recommandé)" :error="errors.meta_capi_token"
               :hint="data.has_capi_token ? 'Jeton enregistré. Laissez vide pour le garder.' : 'Les achats sont aussi envoyés par notre serveur : comptés même quand le navigateur bloque le pixel.'">
          <input id="meta-capi" v-model="form.meta_capi_token" class="input font-mono" type="password" autocomplete="off" />
        </Field>
        <p v-if="data.has_capi_token" class="flex items-center gap-3 text-sm text-slate-600">
          <span>{{ data.conversions_sent }} achat(s) envoyé(s) par le serveur ces 30 derniers jours.</span>
          <button type="button" class="text-red-600 underline" @click="save({ remove_capi_token: true })">Retirer le jeton</button>
        </p>
      </section>
      <section class="card space-y-4 p-4">
        <h3 class="font-semibold">TikTok</h3>
        <Field id="tiktok-pixel" label="Identifiant du pixel TikTok (TikTok Ads Manager › Événements)" :error="errors.tiktok_pixel_id">
          <input id="tiktok-pixel" v-model="form.tiktok_pixel_id" class="input font-mono uppercase" placeholder="C4ABCDEF123456789" />
        </Field>
      </section>
      <section class="card space-y-4 p-4">
        <h3 class="font-semibold">Google Analytics 4</h3>
        <Field id="ga4" label="Identifiant de mesure" :error="errors.ga4_id">
          <input id="ga4" v-model="form.ga4_id" class="input font-mono uppercase" placeholder="G-XXXXXXXXXX" />
        </Field>
      </section>
      <button class="btn-brand">Enregistrer</button>
    </form>

    <section v-if="data?.catalog_feed_url" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Catalogue Facebook, Instagram et WhatsApp</h3>
      <p class="text-slate-600">
        Vos produits en vente ({{ data.catalog_items }} article(s) avec photo), avec leurs prix, promotions et disponibilité, dans le format de Meta.
        Dans le gestionnaire de catalogue de Meta (Commerce Manager) : Catalogue › Sources de données › Ajouter des articles › Flux de données › « Utiliser une URL », collez ce lien et choisissez une mise à jour toutes les heures.
        Le même lien marche pour Google Merchant Center.
      </p>
      <div class="flex items-center gap-2">
        <code class="min-w-0 flex-1 truncate rounded bg-slate-100 px-2 py-1">{{ data.catalog_feed_url }}</code>
        <button type="button" class="btn-secondary px-3 py-1" @click="copyFeed">{{ copied ? 'Copié ✓' : 'Copier' }}</button>
      </div>
      <p class="text-xs text-slate-500">Le lien est secret. <button type="button" class="text-red-600 underline" @click="renewFeed">Créer un nouveau lien</button></p>
    </section>
  </div>
</template>
