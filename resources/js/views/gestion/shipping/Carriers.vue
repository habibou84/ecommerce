<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Icon from '../../../components/Icon.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

const carriers = ref(null)
const locations = ref([])
const error = ref('')
const notice = ref('')
const modal = ref(null) // 'connect' | 'zones' | 'stock'
const kind = ref('delivery_app')
const form = reactive({ address: '', api_key: '', name: '', phone: '' })
const errors = ref({})
const busy = ref(false)
const current = ref(null)
const zones = ref(null)
const zoneFilter = ref('unmapped')
const stock = ref(null)

async function load() {
  try {
    carriers.value = (await http.get('/carriers')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Annuaire de Jibiat : entreprises de livraison avec leurs vrais chiffres, connexion en un clic
const route = useRoute()
const router = useRouter()
const directory = ref(null)
const directoryAvailable = ref(true)
const connecting = ref(null)
const percent = (rate) => (rate === null || rate === undefined ? '—' : `${Math.round(rate * 100)} %`)
const hours = (h) => (h === null || h === undefined ? '—' : `${Math.round(h)} h`)
const money = (amount) => (amount === null || amount === undefined ? '—' : `${new Intl.NumberFormat('fr-FR').format(amount)} F`)

async function loadDirectory() {
  try {
    const { data } = await http.get('/carriers/directory')
    directory.value = data.data
    directoryAvailable.value = data.available
  } catch {
    directory.value = []
    directoryAvailable.value = false
  }
}

async function connectFromDirectory(company) {
  connecting.value = company.slug
  error.value = ''
  try {
    const { data } = await http.post('/carriers/connect/start', { slug: company.slug })
    window.location.assign(data.data.url)
  } catch (e) {
    error.value = errorMessage(e)
    connecting.value = null
  }
}

// Retour de la page « Connecter » de l'entreprise : code à échanger, demande envoyée ou refus
async function finishReturn() {
  const { code, state, error: refused } = route.query
  if (!state) return
  const query = { ...route.query }
  delete query.code
  delete query.state
  delete query.error
  router.replace({ query })
  if (refused === 'request_pending') {
    notice.value = 'Demande envoyée : l\'entreprise de livraison vous contacte pour ouvrir votre compte. Une fois ouvert, revenez ici et touchez de nouveau « Connecter ».'
    return
  }
  if (refused || !code) {
    error.value = 'Connexion annulée.'
    return
  }
  busy.value = true
  try {
    const { data } = await http.post('/carriers/connect/finish', { state, code })
    notice.value = `${data.data.name} est connecté : vos commandes peuvent lui être confiées en un clic.`
    await load()
    if (data.data.unmapped_count) openZones(data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  await finishReturn()
  await load()
  loadDirectory()
  try {
    locations.value = (await http.get('/stock/locations')).data.data
  } catch {
    locations.value = []
  }
})

function openConnect() {
  Object.assign(form, { address: '', api_key: '', name: '', phone: '' })
  errors.value = {}
  error.value = ''
  modal.value = 'connect'
}

async function connect() {
  busy.value = true
  errors.value = {}
  error.value = ''
  try {
    const { data } = kind.value === 'delivery_app'
      ? await http.post('/carriers/delivery-app', { address: form.address, api_key: form.api_key })
      : await http.post('/carriers/manual', { name: form.name, phone: form.phone || null })
    modal.value = null
    await load()
    notice.value = `${data.data.name} est connecté.`
    if (data.data.unmapped_count) openZones(data.data)
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function save(carrier, patch) {
  try {
    const { data } = await http.patch(`/carriers/${carrier.id}`, patch)
    Object.assign(carrier, data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function sync(carrier) {
  busy.value = true
  try {
    const { data } = await http.post(`/carriers/${carrier.id}/sync`)
    Object.assign(carrier, data.data)
    notice.value = `${carrier.name} : zones à jour, ${data.updated} livraison(s) mise(s) à jour.`
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function disconnect(carrier) {
  if (!confirm(`Déconnecter ${carrier.name} ? Les livraisons en cours ne seront plus suivies.`)) return
  await http.delete(`/carriers/${carrier.id}`)
  await load()
}

async function openZones(carrier) {
  current.value = carrier
  zones.value = null
  modal.value = 'zones'
  zones.value = (await http.get(`/carriers/${carrier.id}/zones`)).data.data
}
// Grille de tarifs : prix payé par le client pour chaque zone (entreprise sans API : zones créées ici)
const newZone = ref({ name: '', fee: '' })
async function addZone() {
  busy.value = true
  error.value = ''
  try {
    await http.post(`/carriers/${current.value.id}/tariff-zones`, { name: newZone.value.name, fee: Number(newZone.value.fee) })
    newZone.value = { name: '', fee: '' }
    zones.value = (await http.get(`/carriers/${current.value.id}/zones`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
async function saveZone(zone, changes) {
  error.value = ''
  try {
    Object.assign(zone, (await http.patch(`/carrier-zones/${zone.id}`, changes)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function removeZone(zone) {
  if (!confirm(`Supprimer la zone « ${zone.label} » ? Ses quartiers ne seront plus couverts.`)) return
  await http.delete(`/carrier-zones/${zone.id}`)
  zones.value = (await http.get(`/carriers/${current.value.id}/zones`)).data.data
}
const shownPlaces = computed(() => (zones.value?.places ?? []).filter((p) => zoneFilter.value === 'all' || !p.carrier_zone_id))

async function saveZones() {
  busy.value = true
  try {
    await http.put(`/carriers/${current.value.id}/zones`, {
      mappings: zones.value.places.filter((p) => p.changed).map((p) => ({ place_id: p.place_id, carrier_zone_id: p.carrier_zone_id })),
    })
    modal.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function openStock(carrier) {
  current.value = carrier
  stock.value = null
  error.value = ''
  modal.value = 'stock'
  try {
    stock.value = (await http.get(`/carriers/${carrier.id}/stock`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function linkHub(hub) {
  await http.post(`/carriers/${current.value.id}/hubs`, { remote_hub_id: hub.remote_id })
  await openStock(current.value)
  locations.value = (await http.get('/stock/locations')).data.data
}

const copy = (text) => navigator.clipboard?.writeText(text)
</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Vos entreprises de livraison : les courses leur sont demandées en un clic depuis la commande et suivies automatiquement.</p>
      <button class="btn-brand shrink-0" @click="openConnect">+ Entreprise</button>
    </div>
    <Alert v-if="error && !modal">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <p v-if="carriers && !carriers.length" class="card p-6 text-sm text-slate-500">
      Aucune entreprise de livraison connectée. Choisissez-en une dans l'annuaire ci-dessous et touchez « Connecter » :
      les courses, statuts et reversements arrivent tout seuls. Votre entreprise n'y est pas ? Connectez-la avec une clé API (« + Entreprise »).
    </p>

    <article v-for="c in carriers ?? []" :key="c.id" class="card space-y-3 p-4 text-sm">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-base font-semibold">{{ c.name }}</h3>
        <span class="rounded-full px-2 py-0.5 text-xs" :class="c.status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'">{{ c.status === 'active' ? 'Connecté' : c.status }}</span>
        <span class="text-slate-500">{{ c.driver === 'delivery_app' ? `Delivery App · compte ${c.merchant_name ?? ''} · clé ${c.api_key_prefix}…` : `Sans API${c.contact_phone ? ` · ${c.contact_phone}` : ''}` }}</span>
      </div>
      <Alert v-if="c.last_error" tone="warning">{{ c.last_error }}</Alert>

      <div v-if="c.driver === 'delivery_app'" class="space-y-1 text-slate-600">
        <p>
          {{ c.zones_count }} zones ·
          <button class="underline" :class="{ 'text-amber-800': c.unmapped_count }" @click="openZones(c)">{{ c.unmapped_count ? `${c.unmapped_count} lieu(x) sans zone` : 'correspondance des zones' }}</button>
          · <button class="underline" @click="openStock(c)">stock chez l'entreprise</button>
        </p>
        <p v-if="c.webhook_auto">✓ Statuts reçus en direct (webhook créé automatiquement)<template v-if="c.last_synced_at"> · dernière synchronisation {{ formatDateTime(c.last_synced_at) }}</template></p>
        <div v-else class="rounded bg-amber-50 p-2 text-amber-900">
          Pour recevoir les statuts en direct, déclarez cette adresse dans Delivery App (Profil › Intégrations › Webhooks) puis collez le secret :
          <code class="block break-all py-1">{{ c.webhook_url }}</code>
          <button class="underline" @click="copy(c.webhook_url)">Copier</button>
          <input class="input mt-2" placeholder="Secret whsec_…" @change="save(c, { webhook_secret: $event.target.value })" />
          <span class="text-xs">En attendant, les statuts sont relus toutes les 10 minutes.</span>
        </div>
      </div>

      <div class="grid gap-3 sm:grid-cols-2">
        <Field :id="`payer-${c.id}`" label="Livraison payée par">
          <select :id="`payer-${c.id}`" :value="c.settings.fee_payer" class="input" @change="save(c, { fee_payer: $event.target.value })">
            <option value="store">Vous (le client paie le montant de la commande)</option>
            <option value="customer">Le client (tarif de l'entreprise)</option>
          </select>
        </Field>
        <Field :id="`origin-${c.id}`" label="Ramassage par défaut">
          <select :id="`origin-${c.id}`" :value="c.settings.pickup_location_id" class="input" @change="save(c, { pickup_location_id: $event.target.value ? Number($event.target.value) : null })">
            <option value="">Emplacement de la commande</option>
            <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </Field>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary px-3 py-1.5" @click="openZones(c)">Zones et tarifs</button>
        <button v-if="c.driver === 'delivery_app'" class="btn-secondary px-3 py-1.5" :disabled="busy" @click="sync(c)">Synchroniser</button>
        <button class="btn-ghost ml-auto px-3 py-1.5 text-red-600" @click="disconnect(c)">Déconnecter</button>
      </div>
    </article>

    <section class="space-y-3">
      <div>
        <h2 class="text-base font-semibold">Trouver une entreprise de livraison</h2>
        <p class="text-sm text-slate-600">Des entreprises qui livrent avec Jibiat, avec leurs vrais résultats des 90 derniers jours. Touchez « Connecter » : vous vous connectez chez elle (ou ouvrez votre compte), et c'est fait, sans clé à copier.</p>
      </div>
      <p v-if="directory === null" class="text-sm text-slate-500">Chargement de l'annuaire…</p>
      <p v-else-if="!directoryAvailable" class="card p-4 text-sm text-slate-500">L'annuaire ne répond pas pour le moment. Vous pouvez connecter votre entreprise avec une clé API (« + Entreprise »).</p>
      <p v-else-if="!directory.length" class="card p-4 text-sm text-slate-500">Aucune entreprise dans l'annuaire pour l'instant.</p>
      <div v-else class="grid gap-3 sm:grid-cols-2">
        <article v-for="d in directory" :key="d.slug" class="card flex flex-col gap-3 p-4 text-sm">
          <div class="flex items-center gap-3">
            <img v-if="d.logo_url" :src="d.logo_url" alt="" class="h-11 w-11 rounded-lg object-contain">
            <span v-else class="grid h-11 w-11 place-items-center rounded-lg bg-emerald-700 font-bold text-white" aria-hidden="true">{{ d.name.slice(0, 1).toUpperCase() }}</span>
            <div class="min-w-0">
              <h3 class="truncate text-base font-semibold">{{ d.name }}</h3>
              <p class="truncate text-slate-500">{{ d.cities.join(', ') || 'Côte d\'Ivoire' }}<template v-if="d.ships_inland"> · expédition vers l'intérieur</template></p>
            </div>
            <span v-if="d.near" class="ml-auto shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Près de vous</span>
          </div>
          <p v-if="d.pitch" class="text-slate-700">{{ d.pitch }}</p>
          <dl class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">Réussite</dt><dd class="font-semibold">{{ percent(d.stats.success_rate) }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">Délai moyen</dt><dd class="font-semibold">{{ hours(d.stats.average_delivery_hours) }}</dd></div>
            <div class="rounded-lg bg-slate-50 p-2"><dt class="text-xs text-slate-500">Dès</dt><dd class="font-semibold">{{ money(d.price_from) }}</dd></div>
          </dl>
          <p class="text-xs text-slate-500">{{ d.stats.deliveries_90d }} colis livrés en 90 jours<template v-if="d.stats.success_rate === null"> · chiffres affichés dès 20 courses</template><template v-if="d.accepts === 'request'"> · ouverture de compte sur demande</template></p>
          <span v-if="d.connected" class="mt-auto rounded-lg bg-emerald-50 py-2 text-center font-medium text-emerald-800">✓ Déjà connectée</span>
          <button v-else class="btn-brand mt-auto" :disabled="connecting === d.slug || busy" @click="connectFromDirectory(d)">{{ connecting === d.slug ? 'Ouverture…' : 'Connecter' }}</button>
        </article>
      </div>
    </section>

    <Modal v-if="modal === 'connect'" title="Connecter une entreprise de livraison" @close="modal = null">
      <div class="mb-4 grid grid-cols-2 gap-2 text-sm">
        <button class="rounded-lg border p-3" :class="kind === 'delivery_app' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="kind = 'delivery_app'">Utilise Delivery App</button>
        <button class="rounded-lg border p-3" :class="kind === 'manual' ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'" @click="kind = 'manual'">Autre (par WhatsApp)</button>
      </div>
      <form class="space-y-4" @submit.prevent="connect">
        <Alert v-if="error">{{ error }}</Alert>
        <template v-if="kind === 'delivery_app'">
          <Field id="c-address" label="Adresse de l'entreprise de livraison" :error="errors.address" hint="Par exemple rapide.jibiat.com">
            <input id="c-address" v-model="form.address" class="input" required placeholder="entreprise.jibiat.com" />
          </Field>
          <Field id="c-key" label="Clé API" :error="errors.api_key"
                 hint="Dans Delivery App : Profil › Intégrations › Nouvelle clé, avec les droits courses (lecture et création), webhooks, reversements et stock.">
            <input id="c-key" v-model="form.api_key" class="input font-mono" required placeholder="lv_…" autocomplete="off" />
          </Field>
        </template>
        <template v-else>
          <Field id="c-name" label="Nom" :error="errors.name">
            <input id="c-name" v-model="form.name" class="input" required maxlength="80" />
          </Field>
          <Field id="c-phone" label="Numéro WhatsApp" :error="errors.phone" hint="Les courses lui sont envoyées par WhatsApp ; vous mettez le statut à jour vous-même.">
            <input id="c-phone" v-model="form.phone" type="tel" class="input" />
          </Field>
        </template>
        <button class="btn-brand w-full" :disabled="busy">{{ busy ? 'Connexion…' : 'Connecter' }}</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'zones'" :title="`Zones et tarifs de ${current.name}`" wide @close="modal = null">
      <p v-if="!zones" class="text-sm text-slate-500">Chargement…</p>
      <template v-else>
        <Alert v-if="error" class="mb-3">{{ error }}</Alert>
        <h4 class="mb-1 text-sm font-semibold">Tarifs</h4>
        <p class="mb-2 text-sm text-slate-600">
          {{ current.driver === 'delivery_app'
            ? 'Le prix de chaque course est demandé à l\'entreprise au moment de la commande. Le tarif saisi ici sert seulement si elle ne répond pas.'
            : 'Prix payé par le client pour chaque zone. Il est repris tel quel dans le formulaire de commande quand vous choisissez cette entreprise.' }}
        </p>
        <ul class="mb-3 max-h-[30vh] divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="z in zones.zones" :key="z.id" class="flex items-center gap-2 px-3 py-2">
            <input v-if="current.driver !== 'delivery_app'" :value="z.name" class="input min-w-0 flex-1 py-1.5" :aria-label="`Nom de la zone ${z.label}`" @change="saveZone(z, { name: $event.target.value })" />
            <span v-else class="min-w-0 flex-1">{{ z.label }}</span>
            <input :value="z.fee" type="number" min="0" class="input w-28 py-1.5 text-right" placeholder="—" :aria-label="`Tarif de ${z.label}`" @change="saveZone(z, { fee: $event.target.value === '' ? null : Number($event.target.value) })" />
            <span class="text-slate-500">F</span>
            <button v-if="current.driver !== 'delivery_app'" type="button" class="btn-ghost px-2 text-red-600" :aria-label="`Supprimer ${z.label}`" @click="removeZone(z)"><Icon name="close" :size="16" /></button>
          </li>
          <li v-if="!zones.zones.length" class="px-3 py-3 text-slate-500">Pas encore de zone : sans grille, l'entreprise est proposée partout, aux frais de vos propres zones.</li>
        </ul>
        <form v-if="current.driver !== 'delivery_app'" class="mb-5 flex flex-wrap gap-2" @submit.prevent="addZone">
          <input v-model="newZone.name" class="input min-w-0 flex-1" placeholder="Zone (ex. Cocody, Yopougon…)" required maxlength="120" />
          <input v-model="newZone.fee" class="input w-32" type="number" min="0" placeholder="Tarif (F)" required />
          <button class="btn-secondary" :disabled="busy"><Icon name="plus" :size="16" />Ajouter</button>
        </form>
        <h4 class="mb-1 text-sm font-semibold">Quartiers couverts</h4>
        <p class="mb-3 text-sm text-slate-600">Pour chaque commune ou quartier de vos clients, la zone de l'entreprise de livraison. Sans zone : l'entreprise n'y livre pas.</p>
        <div class="mb-3 flex gap-2 text-sm">
          <button class="rounded-full px-3 py-1" :class="zoneFilter === 'unmapped' ? 'bg-emerald-700 text-white' : 'bg-slate-100'" @click="zoneFilter = 'unmapped'">Sans zone</button>
          <button class="rounded-full px-3 py-1" :class="zoneFilter === 'all' ? 'bg-emerald-700 text-white' : 'bg-slate-100'" @click="zoneFilter = 'all'">Tous</button>
        </div>
        <ul class="max-h-[50vh] divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="p in shownPlaces" :key="p.place_id" class="flex items-center gap-3 px-3 py-2">
            <span class="min-w-0 flex-1" :class="{ 'font-medium': p.type !== 'neighborhood' }">{{ p.label }}</span>
            <select v-model="p.carrier_zone_id" class="input w-56" :aria-label="`Zone pour ${p.label}`" @change="p.changed = true">
              <option :value="null">Non couvert</option>
              <option v-for="z in zones.zones" :key="z.id" :value="z.id">{{ z.label }}</option>
            </select>
          </li>
          <li v-if="!shownPlaces.length" class="px-3 py-4 text-slate-500">Tous les lieux ont une zone.</li>
        </ul>
        <button class="btn-brand mt-4 w-full" :disabled="busy" @click="saveZones">Enregistrer</button>
      </template>
    </Modal>

    <Modal v-if="modal === 'stock'" :title="`Stock chez ${current.name}`" wide @close="modal = null">
      <Alert v-if="error">{{ error }}</Alert>
      <p v-else-if="!stock" class="text-sm text-slate-500">Lecture du stock de l'entreprise…</p>
      <template v-else>
        <h4 class="mb-2 text-sm font-semibold">Entrepôts</h4>
        <ul class="mb-4 space-y-2 text-sm">
          <li v-for="h in stock.hubs" :key="h.remote_id" class="flex items-center gap-3">
            <span class="flex-1">{{ h.name }}</span>
            <span v-if="h.location_id" class="text-emerald-700">✓ Votre emplacement de stock</span>
            <button v-else class="btn-secondary px-2 py-1" @click="linkHub(h)">J'y dépose du stock</button>
          </li>
        </ul>
        <h4 class="mb-2 text-sm font-semibold">Produits (rapprochés par référence SKU)</h4>
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Chez l'entreprise</th><th>Votre variante</th><th class="text-right">Entreprise</th><th class="text-right">Vous</th></tr></thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="p in stock.products" :key="p.remote_id">
              <td class="py-1.5">{{ p.name }} <span class="text-slate-400">{{ p.sku }}</span></td>
              <td>{{ p.variant_label ?? '—' }}</td>
              <td class="text-right">{{ p.remote_on_hand }}</td>
              <td class="text-right" :class="{ 'font-semibold text-amber-800': p.difference }">{{ p.local_on_hand ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
        <p class="mt-3 text-xs text-slate-500">Le stock de l'entreprise fait foi : corrigez un écart par un ajustement sur l'emplacement de l'entrepôt.</p>
      </template>
    </Modal>
  </div>
</template>
