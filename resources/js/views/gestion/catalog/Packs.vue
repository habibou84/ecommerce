<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

// Packs : pack fixe (articles choisis, tailles comprises, un prix) ou pack au choix (X articles pour un prix)
const auth = useAuthStore()
const packs = ref([])
const offers = ref([])
const collections = ref([])
const error = ref('')
const editing = ref(null) // { kind: 'fixed' | 'choice', … }
const errors = ref({})
const saving = ref(false)
const search = ref('')
const results = ref([])

async function load() {
  try {
    const { data } = await http.get('/packs')
    packs.value = data.data.packs
    offers.value = data.data.offers
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(async () => {
  await load()
  collections.value = (await http.get('/collections')).data.data
})

async function open(kind, item = null) {
  errors.value = {}
  search.value = ''
  results.value = []
  if (kind === 'fixed' && item) {
    const { data } = await http.get(`/packs/${item.id}`)
    const prices = await pricesOf(data.data.items.map((i) => i.product_id))
    editing.value = { kind, id: item.id, title: data.data.title, price: data.data.price, items: data.data.items.map((i) => ({ ...i, price: prices[i.product_id] ?? 0 })) }
    return
  }
  if (kind === 'choice' && item) {
    editing.value = { kind, id: item.id, title: item.title, quantity: item.quantity, price: item.price, mode: item.collection_id ? 'collection' : 'products',
      collection_id: item.collection_id, products: item.product_ids.map((id, n) => ({ id, title: item.products[n] })), is_active: item.is_active }
    return
  }
  editing.value = kind === 'fixed'
    ? { kind, title: '', price: '', items: [] }
    : { kind, title: '', quantity: 3, price: '', mode: 'collection', collection_id: collections.value[0]?.id ?? null, products: [], is_active: true }
}

// Prix d'un article (le moins cher) pour calculer l'économie du pack
async function pricesOf(ids) {
  const out = {}
  await Promise.all(ids.map(async (id) => {
    try {
      out[id] = (await http.get(`/products/${id}`)).data.data.price_min ?? 0
    } catch {
      out[id] = 0
    }
  }))
  return out
}

let timer
function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!search.value.trim()) return (results.value = [])
    const { data } = await http.get('/products', { params: { search: search.value, per_page: 10 } })
    const taken = editing.value.kind === 'fixed' ? editing.value.items.map((i) => i.product_id) : editing.value.products.map((p) => p.id)
    results.value = data.data.filter((p) => !p.is_bundle && !taken.includes(p.id))
  }, 250)
}

function add(product) {
  if (editing.value.kind === 'fixed') editing.value.items.push({ product_id: product.id, title: product.title, quantity: 1, price: product.price_min ?? 0, has_variants: product.has_variants })
  else editing.value.products.push({ id: product.id, title: product.title })
  results.value = results.value.filter((p) => p.id !== product.id)
  search.value = ''
  results.value = []
}

const separately = computed(() => (editing.value?.items ?? []).reduce((sum, i) => sum + Number(i.price) * Number(i.quantity || 1), 0))
const saving_ = computed(() => (editing.value?.kind === 'fixed' && Number(editing.value.price) > 0 ? separately.value - Number(editing.value.price) : 0))

async function save() {
  saving.value = true
  errors.value = {}
  error.value = ''
  try {
    const e = editing.value
    if (e.kind === 'fixed') {
      const payload = { title: e.title, price: Number(e.price), items: e.items.map((i) => ({ product_id: i.product_id, quantity: Number(i.quantity) || 1 })) }
      if (e.id) await http.put(`/packs/${e.id}`, payload)
      else await http.post('/packs', payload)
    } else {
      const payload = { title: e.title, quantity: Number(e.quantity), price: Number(e.price), is_active: e.is_active,
        collection_id: e.mode === 'collection' ? e.collection_id : null, product_ids: e.mode === 'products' ? e.products.map((p) => p.id) : [] }
      if (e.id) await http.put(`/pack-offers/${e.id}`, payload)
      else await http.post('/pack-offers', payload)
    }
    editing.value = null
    await load()
  } catch (err) {
    errors.value = fieldErrors(err)
    if (!Object.keys(errors.value).length) error.value = errorMessage(err)
  } finally {
    saving.value = false
  }
}

async function removeOffer() {
  if (!confirm(`Supprimer le pack au choix « ${editing.value.title} » ?`)) return
  await http.delete(`/pack-offers/${editing.value.id}`)
  editing.value = null
  await load()
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Vendez plusieurs articles ensemble : un pack fixe (« robe + sac ») ou un pack au choix (« 3 robes au choix pour 25 000 F »).</p>
      <div v-if="auth.can('products.manage')" class="flex shrink-0 gap-2">
        <button class="btn-brand" @click="open('fixed')">+ Pack fixe</button>
        <button class="btn-secondary" @click="open('choice')">+ Pack au choix</button>
      </div>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card">
      <h2 class="border-b border-slate-100 px-4 py-3 font-semibold">Packs fixes</h2>
      <p v-if="!packs.length" class="p-4 text-sm text-slate-500">Aucun pack pour l'instant. Un pack sort du stock chaque article qu'il contient, avec la taille choisie par le client.</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="p in packs" :key="p.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="open('fixed', p)">
            <img v-if="p.image" :src="p.image" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover" />
            <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-amber-50 text-xl">🎁</span>
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ p.title }}</span>
              <span class="block truncate text-sm text-slate-500">{{ p.contents }}{{ p.choices ? ' · tailles au choix' : '' }}</span>
            </span>
            <span class="text-right text-sm">
              <b>{{ money(p.price) }}</b>
              <s v-if="p.compare_at" class="block text-slate-400">{{ money(p.compare_at) }}</s>
            </span>
          </button>
        </li>
      </ul>
    </section>

    <section class="card">
      <h2 class="border-b border-slate-100 px-4 py-3 font-semibold">Packs au choix</h2>
      <p v-if="!offers.length" class="p-4 text-sm text-slate-500">Aucun pack au choix. Le prix s'applique tout seul dès que le client a assez d'articles : boutique, saisie de commande, live et WhatsApp.</p>
      <ul v-else class="divide-y divide-slate-100">
        <li v-for="o in offers" :key="o.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50" @click="open('choice', o)">
            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-lg bg-emerald-50 text-lg font-extrabold text-emerald-800">{{ o.quantity }}</span>
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ o.title }}</span>
              <span class="block truncate text-sm text-slate-500">{{ o.quantity }} au choix parmi {{ o.collection ? `« ${o.collection} »` : `${o.products.length} articles` }}</span>
            </span>
            <span class="text-right text-sm"><b>{{ money(o.price) }}</b><span v-if="!o.is_active" class="block text-xs text-slate-500">En pause</span></span>
          </button>
        </li>
      </ul>
    </section>

    <p class="text-sm text-slate-500">Vente en gros (« 6 pour 30 000 F », par lot seulement) : sur la fiche de l'article, bloc « Prix de gros ». <RouterLink to="/gestion/catalogue" class="font-medium text-emerald-700 underline">Mes produits</RouterLink></p>

    <Modal v-if="editing" :title="editing.id ? editing.title : (editing.kind === 'fixed' ? 'Nouveau pack fixe' : 'Nouveau pack au choix')" wide @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <fieldset :disabled="!auth.can('products.manage')" class="space-y-4">
          <Field id="p-title" label="Nom du pack" :error="errors.title">
            <input id="p-title" v-model="editing.title" class="input" maxlength="160" required :placeholder="editing.kind === 'fixed' ? 'Ex. Pack robe + sac' : 'Ex. Pack 3 robes'" />
          </Field>

          <template v-if="editing.kind === 'fixed'">
            <div>
              <p class="label">Articles du pack</p>
              <input v-model="search" class="input" placeholder="Ajouter un article : tapez son nom" @input="find" />
              <ul v-if="results.length" class="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200">
                <li v-for="p in results" :key="p.id">
                  <button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-emerald-50" @click="add(p)">+ {{ p.title }} <span class="text-slate-500">· {{ money(p.price_min ?? 0) }}</span></button>
                </li>
              </ul>
              <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                <li v-for="(i, index) in editing.items" :key="i.product_id" class="flex items-center gap-2 px-3 py-2 text-sm">
                  <input v-model="i.quantity" type="number" min="1" max="50" class="input w-16 py-1" aria-label="Quantité" />
                  <span class="min-w-0 flex-1 truncate">× {{ i.title }}<span v-if="i.has_variants" class="text-slate-500"> · taille choisie par le client</span></span>
                  <span class="text-slate-500">{{ money(i.price) }}</span>
                  <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" aria-label="Retirer" @click="editing.items.splice(index, 1)">✕</button>
                </li>
                <li v-if="!editing.items.length" class="px-3 py-2 text-sm text-slate-500">Ajoutez au moins 2 articles.</li>
              </ul>
              <p v-if="errors.items" class="mt-1 text-sm text-red-700">{{ errors.items }}</p>
            </div>
            <Field id="p-price" label="Prix du pack (F)" :error="errors.price">
              <input id="p-price" v-model="editing.price" type="number" min="1" class="input" required />
            </Field>
            <p v-if="saving_ > 0" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">✅ Le client économise {{ money(saving_) }} ({{ money(separately) }} séparément)</p>
            <p class="text-sm text-slate-500">📸 Photo du pack : un montage des photos des articles est fait tout seul. Vous pouvez mettre votre propre photo ensuite sur la fiche du pack.</p>
          </template>

          <template v-else>
            <div class="flex flex-wrap items-end gap-3">
              <Field id="o-qty" label="Nombre d'articles" :error="errors.quantity">
                <input id="o-qty" v-model="editing.quantity" type="number" min="2" max="50" class="input w-28" required />
              </Field>
              <Field id="o-price" label="Pour (F)" :error="errors.price">
                <input id="o-price" v-model="editing.price" type="number" min="1" class="input w-36" required />
              </Field>
            </div>
            <div class="space-y-2">
              <p class="label">Parmi</p>
              <label class="flex items-center gap-2 text-sm"><input v-model="editing.mode" type="radio" value="collection" /> Une collection</label>
              <select v-if="editing.mode === 'collection'" v-model="editing.collection_id" class="input">
                <option v-for="c in collections" :key="c.id" :value="c.id">{{ c.title }} ({{ c.products_count }})</option>
              </select>
              <label class="flex items-center gap-2 text-sm"><input v-model="editing.mode" type="radio" value="products" /> Des articles que je choisis</label>
              <template v-if="editing.mode === 'products'">
                <input v-model="search" class="input" placeholder="Ajouter un article : tapez son nom" @input="find" />
                <ul v-if="results.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                  <li v-for="p in results" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-emerald-50" @click="add(p)">+ {{ p.title }}</button></li>
                </ul>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
                  <li v-for="(p, index) in editing.products" :key="p.id" class="flex items-center gap-2 px-3 py-2 text-sm">
                    <span class="min-w-0 flex-1 truncate">{{ p.title }}</span>
                    <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" aria-label="Retirer" @click="editing.products.splice(index, 1)">✕</button>
                  </li>
                  <li v-if="!editing.products.length" class="px-3 py-2 text-sm text-slate-500">Aucun article.</li>
                </ul>
                <p v-if="errors.product_ids" class="text-sm text-red-700">{{ errors.product_ids }}</p>
              </template>
            </div>
            <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Actif</label>
            <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">Le prix du pack s'applique tout seul dès que le client a {{ editing.quantity || 'X' }} articles de la sélection : en boutique, en saisie de commande, en live et sur WhatsApp.</p>
          </template>
        </fieldset>
        <div class="flex items-center gap-2">
          <button v-if="editing.kind === 'choice' && editing.id && auth.can('products.manage')" type="button" class="btn-ghost text-red-600" @click="removeOffer">Supprimer</button>
          <button v-if="auth.can('products.manage')" class="btn-brand ml-auto" :disabled="saving">{{ saving ? 'Enregistrement…' : (editing.id ? 'Enregistrer' : 'Créer le pack') }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
