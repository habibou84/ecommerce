<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Relances WhatsApp des anciens clients : à qui, quel message, combien ça coûte, puis ce que ça a rapporté
const campaigns = ref([])
const meta = ref({ whatsapp_ready: false, message_price: 15, store_url: '' })
const error = ref('')
const AUDIENCES = [
  { value: 'inactive', label: 'Clients qui n\'ont pas commandé depuis…' },
  { value: 'product', label: 'Clients qui ont acheté un produit' },
  { value: 'all', label: 'Tous les clients' },
]

const form = reactive({ open: false, id: null, title: '', message: '', link: '', audience: { type: 'inactive', days: 60, product_id: null, include_buyers: false }, error: '', saving: false })
const preview = ref(null)
const productSearch = ref('')
const products = ref([])
const productLabel = ref('')

async function load() {
  try {
    const { data } = await http.get('/campaigns')
    campaigns.value = data.data
    meta.value = data.meta
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function edit(c = null) {
  Object.assign(form, c
    ? { open: true, id: c.id, title: c.title, message: c.message, link: c.link ?? '', audience: { days: 60, product_id: null, include_buyers: false, ...c.audience }, error: '', saving: false }
    : { open: true, id: null, title: '', message: '', link: '', audience: { type: 'inactive', days: 60, product_id: null, include_buyers: false }, error: '', saving: false })
  productLabel.value = ''
  products.value = []
  refreshPreview()
}

let timer = null
watch(() => ({ ...form.audience }), () => {
  clearTimeout(timer)
  timer = setTimeout(refreshPreview, 300)
})

async function refreshPreview() {
  if (!form.open || (form.audience.type === 'product' && !form.audience.product_id)) {
    preview.value = null
    return
  }
  try {
    preview.value = (await http.post('/campaigns/preview', { audience: form.audience })).data.data
  } catch (e) {
    preview.value = null
  }
}

async function searchProducts() {
  if (productSearch.value.trim().length < 2) return
  products.value = (await http.get('/products', { params: { search: productSearch.value, per_page: 8 } })).data.data
}

function pickProduct(p) {
  form.audience.product_id = p.id
  productLabel.value = p.title
  products.value = []
}

const payload = () => ({ title: form.title, message: form.message, link: form.link || null, audience: form.audience })

async function save(andSend = false) {
  form.error = ''
  form.saving = true
  try {
    const saved = (await (form.id ? http.put(`/campaigns/${form.id}`, payload()) : http.post('/campaigns', payload()))).data.data
    form.id = saved.id
    if (andSend) {
      if (!confirm(`Envoyer ce message à ${preview.value?.recipients ?? 0} client(s) ? Coût estimé facturé par Meta : environ ${money(preview.value?.estimated_cost ?? 0)}.`)) return
      await http.post(`/campaigns/${saved.id}/send`)
    }
    form.open = false
    await load()
  } catch (e) {
    form.error = errorMessage(e)
  } finally {
    form.saving = false
  }
}

async function remove() {
  if (!confirm('Supprimer ce brouillon ?')) return
  await http.delete(`/campaigns/${form.id}`)
  form.open = false
  await load()
}

const example = computed(() => `Bonjour Awa, ${'votre boutique'} a du nouveau pour vous : ${form.message || '…'} Découvrez-le ici : ${form.link || meta.value.store_url} . Répondez STOP pour ne plus recevoir nos offres.`)
const audienceText = (a) => a.type === 'inactive' ? `Sans commande depuis ${a.days} jours` : a.type === 'product' ? 'Acheteurs d\'un produit' : 'Tous les clients'
</script>

<template>
  <div class="space-y-4">
    <MarketingTabs />
    <div class="flex flex-wrap items-start justify-between gap-2">
      <p class="max-w-2xl text-sm text-slate-600">
        Envoyez un message WhatsApp à vos anciens clients : nouvelle collection, promotion, retour d'un article.
        Seuls les clients qui ont accepté vos offres reçoivent le message, au plus une fois par semaine, et chacun peut répondre STOP.
        Meta facture chaque message (environ {{ money(meta.message_price) }}).
      </p>
      <button class="btn-brand" @click="edit()">Nouvelle relance</button>
    </div>
    <Alert v-if="!meta.whatsapp_ready" tone="warning">
      Pour envoyer une relance, connectez votre WhatsApp puis créez le modèle « Nouveautés » (Réglages › WhatsApp › Modèles) et attendez que Meta l'accepte.
    </Alert>
    <Alert v-if="error">{{ error }}</Alert>

    <ul class="space-y-3">
      <li v-for="c in campaigns" :key="c.id" class="card space-y-2 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-semibold">{{ c.title }}</span>
          <span class="rounded px-2 py-0.5 text-xs" :class="c.status === 'sent' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'">{{ c.status === 'sent' ? 'Envoyée' : 'Brouillon' }}</span>
          <span class="text-slate-500">{{ audienceText(c.audience) }}</span>
          <button v-if="c.status === 'draft'" class="ml-auto text-emerald-700" @click="edit(c)">Modifier / envoyer</button>
        </div>
        <p class="text-slate-700">{{ c.message }}</p>
        <div v-if="c.results" class="grid grid-cols-2 gap-2 sm:grid-cols-5">
          <div><p class="text-xs text-slate-500">Envoyés</p><p class="font-medium">{{ c.recipients }}</p></div>
          <div><p class="text-xs text-slate-500">Reçus / lus</p><p class="font-medium">{{ c.results.delivered }} / {{ c.results.read }}</p></div>
          <div><p class="text-xs text-slate-500">Commandes (7 j)</p><p class="font-medium">{{ c.results.orders }}</p></div>
          <div><p class="text-xs text-slate-500">Montant commandé</p><p class="font-medium">{{ money(c.results.orders_amount) }}</p></div>
          <div><p class="text-xs text-slate-500">Coût estimé</p><p class="font-medium">{{ money(c.estimated_cost) }}</p></div>
        </div>
        <p v-if="c.sent_at" class="text-xs text-slate-500">Envoyée le {{ formatDateTime(c.sent_at) }}<template v-if="c.results?.failed"> · {{ c.results.failed }} échec(s)</template></p>
      </li>
      <li v-if="!campaigns.length" class="text-sm text-slate-500">Aucune relance pour l'instant.</li>
    </ul>

    <Modal v-if="form.open" :title="form.id ? 'Relance' : 'Nouvelle relance'" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="save(false)">
        <Alert v-if="form.error">{{ form.error }}</Alert>
        <Field id="cp-title" label="Nom (pour vous)"><input id="cp-title" v-model="form.title" class="input" maxlength="120" required placeholder="Ex. Nouvelle collection wax" /></Field>

        <Field id="cp-aud" label="À qui">
          <select id="cp-aud" v-model="form.audience.type" class="input"><option v-for="a in AUDIENCES" :key="a.value" :value="a.value">{{ a.label }}</option></select>
        </Field>
        <Field v-if="form.audience.type === 'inactive'" id="cp-days" label="Nombre de jours sans commande">
          <input id="cp-days" v-model.number="form.audience.days" type="number" min="7" max="730" class="input" />
        </Field>
        <div v-if="form.audience.type === 'product'" class="space-y-2">
          <p v-if="productLabel" class="text-sm">Produit : <strong>{{ productLabel }}</strong></p>
          <div class="flex gap-2">
            <input v-model="productSearch" class="input" placeholder="Chercher le produit…" @keydown.enter.prevent="searchProducts" />
            <button type="button" class="btn-secondary" @click="searchProducts">Chercher</button>
          </div>
          <ul v-if="products.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
            <li v-for="p in products" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-slate-50" @click="pickProduct(p)">{{ p.title }}</button></li>
          </ul>
        </div>
        <label class="flex items-start gap-2 text-sm">
          <input v-model="form.audience.include_buyers" type="checkbox" class="mt-0.5" />
          <span>Inclure les clients déjà livrés qui n'ont pas coché « recevoir les nouveautés » : je confirme qu'ils sont d'accord pour recevoir mes offres sur WhatsApp (règle de Meta).</span>
        </label>

        <div v-if="preview" class="rounded-lg bg-slate-50 p-3 text-sm">
          <p><strong>{{ preview.recipients }}</strong> client(s) recevront le message · coût estimé <strong>{{ money(preview.estimated_cost) }}</strong></p>
          <p v-if="preview.sample.length" class="text-xs text-slate-500">Par exemple : {{ preview.sample.join(', ') }}</p>
          <p class="mt-1 text-xs text-slate-500">{{ preview.consented }} client(s) ont accepté vos offres<template v-if="preview.buyers_without_consent"> ; {{ preview.buyers_without_consent }} client(s) livrés ne l'ont pas encore fait</template>.</p>
        </div>

        <Field id="cp-msg" label="Votre message" hint="Une phrase ou deux, sans retour à la ligne (règle de Meta).">
          <textarea id="cp-msg" v-model="form.message" rows="3" maxlength="300" class="input" required placeholder="la nouvelle collection de robes wax est arrivée, -10 % jusqu'à dimanche."></textarea>
        </Field>
        <Field id="cp-link" label="Lien (facultatif)" hint="Laissez vide pour l'accueil de votre boutique.">
          <input id="cp-link" v-model="form.link" type="url" class="input" placeholder="https://…" />
        </Field>
        <p class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-slate-700">{{ example }}</p>

        <div class="flex flex-wrap gap-2">
          <button v-if="form.id" type="button" class="btn-secondary text-red-700" @click="remove">Supprimer</button>
          <button class="btn-secondary flex-1" :disabled="form.saving">Enregistrer le brouillon</button>
          <button type="button" class="btn-brand flex-1" :disabled="form.saving || !meta.whatsapp_ready || !preview?.recipients" @click="save(true)">Envoyer maintenant</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
