<script setup>
import { reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'

// ── Fichier CSV (Excel, Shopify) ──
const file = ref(null)
const report = ref(null)
const csvError = ref('')
const busy = ref(false)

function pick(event) {
  file.value = event.target.files[0] ?? null
  report.value = null
  csvError.value = ''
}

async function send(dryRun) {
  busy.value = true
  csvError.value = ''
  try {
    const body = new FormData()
    body.append('file', file.value)
    body.append('dry_run', dryRun ? '1' : '0')
    report.value = (await http.post('/catalog/import', body)).data.data
  } catch (e) {
    csvError.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function download() {
  const response = await http.get('/catalog/export', { responseType: 'blob' })
  const url = URL.createObjectURL(response.data)
  const link = Object.assign(document.createElement('a'), {
    href: url,
    download: response.headers['content-disposition']?.match(/filename="(.+)"/)?.[1] ?? 'catalogue.csv',
  })
  link.click()
  URL.revokeObjectURL(url)
}

// ── Reprise depuis une entreprise de livraison (Delivery App) ──
const delivery = reactive({ address: '', api_key: '' })
const deliveryErrors = ref({})
const deliveryError = ref('')
const preview = ref(null)
const deliveryResult = ref(null)
const deliveryBusy = ref(false)

async function deliveryCall(path) {
  deliveryBusy.value = true
  deliveryErrors.value = {}
  deliveryError.value = ''
  try {
    return (await http.post(path, delivery)).data.data
  } catch (e) {
    deliveryErrors.value = fieldErrors(e)
    if (!Object.keys(deliveryErrors.value).length) deliveryError.value = errorMessage(e)
  } finally {
    deliveryBusy.value = false
  }
}

async function loadPreview() {
  deliveryResult.value = null
  preview.value = await deliveryCall('/catalog/import/delivery-app/preview')
}

async function importDelivery() {
  deliveryResult.value = await deliveryCall('/catalog/import/delivery-app')
  if (deliveryResult.value) preview.value = null
}
</script>

<template>
  <div class="space-y-6">
    <RouterLink to="/gestion/catalogue" class="text-sm text-slate-500 hover:text-emerald-700">← Mes produits</RouterLink>

    <section class="card space-y-4 p-4 sm:p-6">
      <div>
        <h2 class="font-semibold">Fichier Excel (CSV)</h2>
        <p class="mt-1 text-sm text-slate-600">
          Une ligne par variante. Le plus simple : <button class="font-medium text-emerald-700 hover:underline" @click="download">exportez votre catalogue</button>,
          modifiez-le dans Excel, enregistrez au format CSV, puis importez-le. Les exports Shopify sont aussi acceptés. Les photos ne sont pas importées.
        </p>
      </div>
      <input type="file" accept=".csv,text/csv" class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium" @change="pick" />
      <Alert v-if="csvError">{{ csvError }}</Alert>

      <div v-if="report" class="space-y-2 rounded-lg bg-slate-50 p-4 text-sm">
        <p class="font-medium">{{ report.dry_run ? 'Vérification (rien n\'est encore enregistré)' : 'Import terminé' }}</p>
        <p>{{ report.products_created }} produit(s) {{ report.dry_run ? 'à créer' : 'créé(s)' }}, {{ report.products_updated }} {{ report.dry_run ? 'à mettre à jour' : 'mis à jour' }}, {{ report.variants }} variante(s).</p>
        <p v-for="w in report.warnings" :key="w" class="text-amber-800">{{ w }}</p>
        <ul v-if="report.errors.length" class="space-y-1 text-red-700">
          <li v-for="e in report.errors" :key="e.line + e.message">Ligne {{ e.line }} : {{ e.message }}</li>
        </ul>
      </div>

      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary" :disabled="!file || busy" @click="send(true)">Vérifier le fichier</button>
        <button class="btn-brand" :disabled="!file || busy || !report?.dry_run" @click="send(false)">
          {{ busy ? 'Un instant…' : report?.errors?.length ? 'Importer les lignes valides' : 'Importer' }}
        </button>
      </div>
    </section>

    <section class="card space-y-4 p-4 sm:p-6">
      <div>
        <h2 class="font-semibold">Depuis votre entreprise de livraison</h2>
        <p class="mt-1 text-sm text-slate-600">
          Vous utilisiez la boutique de votre entreprise de livraison (Jibiat) ? Reprenez vos produits : créez une clé API dans
          <em>Profil › Intégrations</em> de votre espace marchand chez elle, avec l'accès « Lire les produits et le stock ». La clé n'est pas conservée.
        </p>
      </div>
      <Alert v-if="deliveryError">{{ deliveryError }}</Alert>
      <div class="grid gap-4 sm:grid-cols-2">
        <Field id="d-address" label="Adresse de l'entreprise" :error="deliveryErrors.address">
          <input id="d-address" v-model="delivery.address" class="input" placeholder="entreprise.jibiat.com" autocapitalize="off" />
        </Field>
        <Field id="d-key" label="Clé API" :error="deliveryErrors.api_key">
          <input id="d-key" v-model="delivery.api_key" class="input" type="password" placeholder="lv_…" autocomplete="off" />
        </Field>
      </div>
      <button class="btn-secondary" :disabled="deliveryBusy || !delivery.address || !delivery.api_key" @click="loadPreview">Voir les produits</button>

      <div v-if="preview" class="space-y-3">
        <p class="text-sm">{{ preview.length }} produit(s) trouvé(s). Ceux dont le SKU existe déjà chez vous seront ignorés.</p>
        <ul class="max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="p in preview" :key="(p.sku ?? '') + p.name" class="flex gap-3 px-3 py-2">
            <span class="min-w-0 flex-1 truncate">{{ p.name }} <span class="text-slate-400">{{ p.sku }}</span></span>
            <span>{{ money(p.price) }}</span>
            <span class="w-24 text-right text-slate-500">{{ p.at_merchant }} chez vous</span>
          </li>
        </ul>
        <button class="btn-brand" :disabled="deliveryBusy || !preview.length" @click="importDelivery">Importer ces produits</button>
      </div>

      <Alert v-if="deliveryResult" tone="success">
        {{ deliveryResult.created }} produit(s) importé(s){{ deliveryResult.skipped ? `, ${deliveryResult.skipped} déjà présent(s)` : '' }}.
        <template v-if="deliveryResult.at_carrier">
          {{ deliveryResult.at_carrier }} article(s) se trouvent à l'entrepôt de l'entreprise : ils seront suivis quand vous la connecterez dans Livraisons › Entreprises de livraison.
        </template>
      </Alert>
    </section>
  </div>
</template>
