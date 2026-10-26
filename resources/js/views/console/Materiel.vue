<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

// Matériel vendu aux e-commerçants : commandes, produits (stock ou partenaire), réglages (paiement, livraison)
const data = ref(null)
const error = ref('')
const tab = ref('orders')
const filter = ref('open')
const NEXT = { pending: ['confirmed', 'Confirmer'], confirmed: ['preparing', 'En préparation'], preparing: ['shipping', 'En livraison'], shipping: ['delivered', 'Livrée'] }
const TONE = { pending: 'bg-amber-100 text-amber-900', confirmed: 'bg-sky-100 text-sky-800', preparing: 'bg-sky-100 text-sky-800', shipping: 'bg-violet-100 text-violet-800', delivered: 'bg-emerald-100 text-emerald-800', cancelled: 'bg-slate-100 text-slate-500' }

async function load() {
  try {
    data.value = (await http.get('/console/materiel', { params: { status: filter.value || undefined } })).data.data
    settings.value ??= JSON.parse(JSON.stringify(data.value.settings))
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function call(method, url, payload) {
  error.value = ''
  try {
    data.value = (await http[method](url, payload)).data.data
    return true
  } catch (e) {
    error.value = errorMessage(e)
    return false
  }
}

// Commandes
function step(o, status) {
  if (status === 'cancelled' && !window.confirm(`Annuler la commande ${o.number} ? Le stock revient et l'e-commerçant est prévenu.`)) return
  call('post', `/console/materiel/orders/${o.id}/status`, { status })
}
const partners = (o) => [...new Map(o.items.filter((l) => l.supplier === 'partner' && l.partner_phone).map((l) => [l.partner_phone, l])).values()]
function partnerLink(o, p) {
  const lines = o.items.filter((l) => l.partner_phone === p.partner_phone).map((l) => `${l.quantity} × ${l.name}`).join(', ')
  const text = `Bonjour ${p.partner_name}, commande Jibiat Marchand ${o.number} : ${lines}. Livraison : ${o.delivery.label}${o.address ? `, ${o.address}` : ''}. Contact : ${o.contact_name}, ${o.contact_phone}.`
  return `https://wa.me/${String(p.partner_phone).replace(/\D/g, '').replace(/^0/, '225')}?text=${encodeURIComponent(text)}`
}

// Produits
const product = ref(null)
const productErrors = ref({})
const imageFile = ref(null)
const EMPTY = { name: '', category: 'impression', description: '', price: '', cost_price: '', stock: '', supplier: 'own', partner_name: '', partner_phone: '', commission_type: 'percent', commission_value: '', is_active: true, sort: 0 }
function editProduct(p = null) {
  productErrors.value = {}
  imageFile.value = null
  product.value = p ? { ...EMPTY, ...p, cost_price: p.cost_price ?? '', stock: p.stock ?? '', commission_type: p.commission_type ?? 'percent', commission_value: p.commission_value ?? '' } : { ...EMPTY }
}
const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))
async function saveProduct() {
  const p = product.value
  const payload = { ...p, price: num(p.price), cost_price: num(p.cost_price), stock: num(p.stock), commission_value: num(p.commission_value), sort: num(p.sort) ?? 0 }
  productErrors.value = {}
  try {
    const res = (await http[p.id ? 'put' : 'post'](p.id ? `/console/materiel/products/${p.id}` : '/console/materiel/products', payload)).data.data
    const saved = p.id ? res.products.find((x) => x.id === p.id) : res.products.reduce((a, b) => (b.id > a.id ? b : a))
    if (imageFile.value) {
      const form = new FormData()
      form.append('image', imageFile.value)
      data.value = (await http.post(`/console/materiel/products/${saved.id}/image`, form)).data.data
    } else {
      data.value = res
    }
    product.value = null
  } catch (e) {
    productErrors.value = fieldErrors(e)
    if (!Object.keys(productErrors.value).length) error.value = errorMessage(e)
  }
}

// Réglages
const settings = ref(null)
const saved = ref(false)
function addDelivery() {
  settings.value.deliveries.push({ label: '', fee: 0, note: '', address: true })
}
async function saveSettings() {
  saved.value = false
  if (await call('put', '/console/materiel/settings', settings.value)) {
    settings.value = JSON.parse(JSON.stringify(data.value.settings))
    saved.value = true
  }
}
const waveMissing = computed(() => settings.value?.wave && !data.value?.methods.wave)
</script>

<template>
  <div v-if="data" class="space-y-4">
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-semibold">Matériel</h1>
      <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="data.settings.enabled ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ data.settings.enabled ? 'Proposé aux marchands' : 'Fermé' }}</span>
    </div>
    <div class="grid gap-3 sm:grid-cols-4">
      <div class="card p-3"><p class="text-xs text-slate-500">Commandes en cours</p><p class="text-xl font-bold">{{ data.totals.open }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Paiements Wave à vérifier</p><p class="text-xl font-bold" :class="data.totals.to_check ? 'text-amber-700' : ''">{{ data.totals.to_check }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Ventes livrées</p><p class="text-xl font-bold">{{ money(data.totals.sales) }}</p></div>
      <div class="card p-3"><p class="text-xs text-slate-500">Ce que ça vous a rapporté</p><p class="text-xl font-bold text-emerald-700">{{ money(data.totals.earnings) }}</p></div>
    </div>
    <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-sm sm:w-fit">
      <button v-for="[key, label] in [['orders', 'Commandes'], ['products', 'Produits'], ['settings', 'Réglages']]" :key="key" class="rounded-md px-4 py-1.5" :class="tab === key ? 'bg-white font-semibold shadow-sm' : ''" @click="tab = key">{{ label }}</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Commandes -->
    <section v-if="tab === 'orders'" class="space-y-3">
      <select v-model="filter" class="input w-auto" @change="load">
        <option value="open">En cours</option><option value="">Toutes</option>
        <option v-for="(label, key) in data.statuses" :key="key" :value="key">{{ label }}</option>
      </select>
      <p v-if="!data.orders.length" class="card p-6 text-center text-sm text-slate-500">Aucune commande.</p>
      <article v-for="o in data.orders" :key="o.id" class="card space-y-2 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <b>{{ o.number }}</b>
          <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="TONE[o.status]">{{ o.status_label }}</span>
          <RouterLink v-if="o.store" :to="`/console/boutiques/${o.store.id}`" class="text-emerald-700 hover:underline">{{ o.store.name }}</RouterLink>
          <span class="text-slate-500">{{ formatDateTime(o.created_at) }}</span>
          <span class="ml-auto text-base font-bold">{{ money(o.total) }}</span>
        </div>
        <ul class="text-slate-700">
          <li v-for="(l, i) in o.items" :key="i">{{ l.quantity }} × {{ l.name }} · {{ money(l.total) }}
            <span class="text-xs text-slate-500">({{ l.supplier === 'partner' ? `partenaire ${l.partner_name}` : 'mon stock' }}<template v-if="l.earning !== null"> · rapporte {{ money(l.earning) }}</template>)</span>
          </li>
        </ul>
        <p class="text-slate-600">{{ o.delivery.label }} ({{ o.delivery.fee ? money(o.delivery.fee) : 'gratuit' }})<template v-if="o.address"> · {{ o.address }}</template> · {{ o.contact_name }}, <a :href="`https://wa.me/${o.contact_whatsapp}`" target="_blank" rel="noopener" class="text-emerald-700 underline">{{ o.contact_phone }}</a></p>
        <p v-if="o.notes" class="text-slate-600">« {{ o.notes }} »</p>
        <p>{{ o.payment_label }}<template v-if="o.payer_phone"> (numéro {{ o.payer_phone }})</template> ·
          <b :class="o.payment_status === 'paid' ? 'text-emerald-700' : o.payment_status === 'claimed' ? 'text-amber-700' : ''">{{ o.payment_status_label }}</b>
        </p>
        <div class="flex flex-wrap gap-2 pt-1">
          <button v-if="NEXT[o.status]" class="btn-brand" @click="step(o, NEXT[o.status][0])">{{ NEXT[o.status][1] }}</button>
          <button v-if="o.payment_status !== 'paid' && o.status !== 'cancelled'" class="btn-secondary" @click="call('post', `/console/materiel/orders/${o.id}/paid`, { paid: true })">Paiement reçu</button>
          <button v-else-if="o.payment_status === 'paid' && o.status !== 'delivered'" class="btn-ghost" @click="call('post', `/console/materiel/orders/${o.id}/paid`, { paid: false })">Annuler « payée »</button>
          <a v-for="p in partners(o)" :key="p.partner_phone" :href="partnerLink(o, p)" target="_blank" rel="noopener" class="btn-secondary">Envoyer à {{ p.partner_name }} (WhatsApp)</a>
          <button v-if="o.status !== 'delivered' && o.status !== 'cancelled'" class="btn-ghost text-red-600" @click="step(o, 'cancelled')">Annuler</button>
        </div>
      </article>
    </section>

    <!-- Produits -->
    <section v-if="tab === 'products'" class="space-y-3">
      <button class="btn-brand" @click="editProduct()">Ajouter un produit</button>
      <p v-if="!data.products.length" class="card p-6 text-center text-sm text-slate-500">Aucun produit. Ajoutez par exemple une imprimante d'étiquettes ou un pack live.</p>
      <div class="grid gap-3 md:grid-cols-2">
        <article v-for="p in data.products" :key="p.id" class="card flex gap-3 p-3 text-sm" :class="p.is_active ? '' : 'opacity-60'">
          <img v-if="p.image" :src="p.image" alt="" class="h-20 w-20 shrink-0 rounded-lg object-cover" />
          <span v-else class="grid h-20 w-20 shrink-0 place-items-center rounded-lg bg-slate-100 text-3xl">📦</span>
          <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ p.name }} <span v-if="!p.is_active" class="text-xs font-normal text-slate-500">(masqué)</span></p>
            <p class="text-slate-500">{{ p.category_label }} · {{ money(p.price) }}</p>
            <p class="text-slate-600">{{ p.supplier === 'partner' ? `Partenaire ${p.partner_name} · commission ${p.commission_type === 'percent' ? `${p.commission_value} %` : money(p.commission_value)}` : `Mon stock${p.stock !== null ? ` · ${p.stock} en stock` : ''}` }}</p>
            <p v-if="p.earning_per_unit !== null" class="text-emerald-700">Rapporte {{ money(p.earning_per_unit) }} par pièce</p>
          </div>
          <button class="btn-ghost self-start" @click="editProduct(p)">Modifier</button>
        </article>
      </div>
    </section>

    <!-- Réglages -->
    <section v-if="tab === 'settings' && settings" class="card max-w-2xl space-y-4 p-4 text-sm">
      <label class="flex items-center gap-2 font-semibold"><input v-model="settings.enabled" type="checkbox" class="accent-emerald-700" /> Proposer le matériel aux e-commerçants</label>
      <fieldset class="space-y-1">
        <legend class="font-semibold">Paiement</legend>
        <label class="flex items-center gap-2"><input v-model="settings.wave" type="checkbox" class="accent-emerald-700" /> Wave à la commande (lien de paiement de la plateforme, vous confirmez la réception)</label>
        <p v-if="waveMissing" class="text-amber-800">Réglez d'abord le lien ou le QR code Wave dans Paiements : sans lui, Wave n'est pas proposé.</p>
        <label class="flex items-center gap-2"><input v-model="settings.cod" type="checkbox" class="accent-emerald-700" /> Paiement à la livraison</label>
      </fieldset>
      <fieldset class="space-y-2">
        <legend class="font-semibold">Options de livraison</legend>
        <div v-for="(d, i) in settings.deliveries" :key="i" class="grid gap-2 rounded-xl p-3 ring-1 ring-slate-200 sm:grid-cols-[1fr_7rem]">
          <input v-model="d.label" class="input" placeholder="ex. Livraison à Abidjan" />
          <input v-model.number="d.fee" type="number" min="0" step="5" class="input" placeholder="Prix" />
          <input v-model="d.note" class="input sm:col-span-2" placeholder="Précision (facultatif) : délai, zones…" />
          <div class="flex items-center gap-3 sm:col-span-2">
            <label class="flex items-center gap-2"><input v-model="d.address" type="checkbox" class="accent-emerald-700" /> Demander l'adresse</label>
            <button type="button" class="ml-auto text-red-600" @click="settings.deliveries.splice(i, 1)">Retirer</button>
          </div>
        </div>
        <button type="button" class="btn-secondary" @click="addDelivery">Ajouter une option</button>
      </fieldset>
      <div class="flex items-center gap-2"><button class="btn-brand" @click="saveSettings">Enregistrer</button><span v-if="saved" class="text-emerald-700">Enregistré ✓</span></div>
    </section>

    <Modal v-if="product" :title="product.id ? 'Modifier le produit' : 'Nouveau produit'" @close="product = null">
      <form class="space-y-3 text-sm" @submit.prevent="saveProduct">
        <Field id="p-name" label="Nom" :error="productErrors.name"><input id="p-name" v-model="product.name" class="input" required /></Field>
        <div class="grid gap-3 sm:grid-cols-2">
          <Field id="p-cat" label="Catégorie"><select id="p-cat" v-model="product.category" class="input"><option v-for="(label, key) in data.categories" :key="key" :value="key">{{ label }}</option></select></Field>
          <Field id="p-price" label="Prix de vente (F)" :error="productErrors.price"><input id="p-price" v-model="product.price" type="number" min="0" class="input" required /></Field>
        </div>
        <Field id="p-desc" label="Description"><textarea id="p-desc" v-model="product.description" rows="3" class="input" /></Field>
        <Field id="p-img" label="Photo"><input id="p-img" type="file" accept="image/*" class="input" @change="imageFile = $event.target.files[0]" /></Field>
        <fieldset class="space-y-2">
          <legend class="font-semibold">Qui fournit ?</legend>
          <label class="flex items-center gap-2"><input v-model="product.supplier" type="radio" value="own" class="accent-emerald-700" /> Moi (mon stock)</label>
          <label class="flex items-center gap-2"><input v-model="product.supplier" type="radio" value="partner" class="accent-emerald-700" /> Un partenaire (commission)</label>
        </fieldset>
        <div v-if="product.supplier === 'own'" class="grid gap-3 sm:grid-cols-2">
          <Field id="p-cost" label="Prix d'achat (pour la marge)"><input id="p-cost" v-model="product.cost_price" type="number" min="0" class="input" placeholder="facultatif" /></Field>
          <Field id="p-stock" label="Stock"><input id="p-stock" v-model="product.stock" type="number" min="0" class="input" placeholder="non suivi" /></Field>
        </div>
        <div v-else class="grid gap-3 sm:grid-cols-2">
          <Field id="p-pname" label="Nom du partenaire" :error="productErrors.partner_name"><input id="p-pname" v-model="product.partner_name" class="input" /></Field>
          <Field id="p-pphone" label="WhatsApp du partenaire"><input id="p-pphone" v-model="product.partner_phone" class="input" inputmode="tel" /></Field>
          <Field id="p-ctype" label="Commission"><select id="p-ctype" v-model="product.commission_type" class="input"><option value="percent">Pourcentage du prix</option><option value="amount">Montant par pièce</option></select></Field>
          <Field id="p-cval" :label="product.commission_type === 'percent' ? 'Commission (%)' : 'Commission (F)'" :error="productErrors.commission_value"><input id="p-cval" v-model="product.commission_value" type="number" min="0" class="input" /></Field>
          <Field id="p-pstock" label="Stock (facultatif)"><input id="p-pstock" v-model="product.stock" type="number" min="0" class="input" placeholder="non suivi" /></Field>
        </div>
        <label class="flex items-center gap-2"><input v-model="product.is_active" type="checkbox" class="accent-emerald-700" /> Proposé aux e-commerçants</label>
        <button class="btn-brand w-full justify-center">Enregistrer</button>
      </form>
    </Modal>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
