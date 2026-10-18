<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
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

onMounted(async () => {
  try {
    fill((await http.get('/marketing/tracking')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

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
