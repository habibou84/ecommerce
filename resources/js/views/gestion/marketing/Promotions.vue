<script setup>
import Icon from '../../../components/Icon.vue'
import { computed, onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const list = ref(null)
const freeShipping = ref('')
const error = ref('')
const saved = ref(false)
const editing = ref(null)
const errors = ref({})
const busy = ref(false)
const products = ref([])
const collections = ref([])
const search = ref('')

const STATUS = { running: ['En cours', 'bg-emerald-100 text-emerald-800'], scheduled: ['Programmée', 'bg-sky-100 text-sky-800'], ended: ['Terminée', 'bg-slate-100 text-slate-600'], disabled: ['Désactivée', 'bg-slate-100 text-slate-600'] }

const flash = computed(() => list.value?.filter((d) => d.kind === 'flash') ?? [])
const codes = computed(() => list.value?.filter((d) => d.kind === 'code') ?? [])

async function load() {
  try {
    const { data } = await http.get('/promotions')
    list.value = data.data
    freeShipping.value = data.free_shipping_above ?? ''
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function saveFreeShipping() {
  error.value = ''
  saved.value = false
  try {
    await http.patch('/promotions/settings', { free_shipping_above: Number(freeShipping.value) || null })
    saved.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}

// Champ date-heure local ↔ ISO
const toLocal = (iso) => (iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '')
const toIso = (local) => (local ? new Date(local).toISOString() : null)

async function open(kind, d = null) {
  errors.value = {}
  search.value = ''
  editing.value = d
    ? { ...d, starts_at: toLocal(d.starts_at), ends_at: toLocal(d.ends_at), target_ids: [...d.target_ids] }
    : { kind, code: '', title: '', type: 'percentage', value: kind === 'flash' ? 20 : 10, applies_to: kind === 'flash' ? 'products' : 'all', target_ids: [], min_subtotal: '', usage_limit: '', per_customer_limit: kind === 'code' ? 1 : '', starts_at: '', ends_at: kind === 'flash' ? toLocal(new Date(Date.now() + 2 * 86400000).toISOString()) : '', is_active: true }
  if (!collections.value.length) collections.value = (await http.get('/collections')).data.data
  await findProducts()
}

async function findProducts() {
  const { data } = await http.get('/products', { params: { search: search.value || undefined, per_page: 30 } })
  products.value = data.data
}

const targetOptions = computed(() => (editing.value?.applies_to === 'collections' ? collections.value : products.value))
function toggleTarget(id) {
  const ids = editing.value.target_ids
  const i = ids.indexOf(id)
  i === -1 ? ids.push(id) : ids.splice(i, 1)
}

async function save() {
  busy.value = true
  errors.value = {}
  const d = editing.value
  const payload = {
    code: d.kind === 'code' ? d.code : null,
    title: d.title || null,
    type: d.type,
    value: d.type === 'free_shipping' ? null : Number(d.value),
    applies_to: d.applies_to,
    target_ids: d.applies_to === 'all' ? [] : d.target_ids,
    min_subtotal: d.min_subtotal === '' ? null : Number(d.min_subtotal),
    usage_limit: d.usage_limit === '' ? null : Number(d.usage_limit),
    per_customer_limit: d.per_customer_limit === '' || d.per_customer_limit === null ? null : Number(d.per_customer_limit),
    starts_at: toIso(d.starts_at),
    ends_at: toIso(d.ends_at),
    is_active: d.is_active,
  }
  try {
    d.id ? await http.put(`/promotions/${d.id}`, payload) : await http.post('/promotions', payload)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) errors.value = { form: errorMessage(e) }
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!window.confirm('Supprimer cette promotion ?')) return
  const { data } = await http.delete(`/promotions/${editing.value.id}`)
  editing.value = null
  error.value = ''
  await load()
  if (data.message?.includes('désactivée')) window.alert(data.message)
}

const copy = (text) => navigator.clipboard?.writeText(text)
</script>

<template>
  <div class="space-y-5">
    <MarketingTabs />
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card space-y-3 p-4">
      <h3 class="font-semibold">Livraison offerte</h3>
      <p class="text-sm text-slate-600">Offrez la livraison dès un montant d'achat : le panier affiche « Plus que … pour la livraison offerte ». Laissez vide pour désactiver.</p>
      <div class="flex flex-wrap items-end gap-3">
        <Field id="free-shipping" label="Dès (F)"><input id="free-shipping" v-model="freeShipping" type="number" min="0" step="500" class="input w-40" placeholder="ex. 30000" /></Field>
        <button class="btn-brand" @click="saveFreeShipping">Enregistrer</button>
        <span v-if="saved" class="text-sm text-emerald-700">Enregistré ✓</span>
      </div>
    </section>

    <section class="card p-4">
      <div class="mb-3 flex items-center">
        <div>
          <h3 class="font-semibold"><Icon name="bolt" :size="18" class="mr-1 inline-block align-[-3px]" />Ventes flash</h3>
          <p class="text-sm text-slate-500">Prix barré et compte à rebours sur les produits visés, sans code.</p>
        </div>
        <button class="btn-secondary ml-auto px-3 py-1.5" @click="open('flash')">+ Vente flash</button>
      </div>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="d in flash" :key="d.id">
          <button class="flex w-full flex-wrap items-center gap-2 py-2 text-left hover:text-emerald-700" @click="open('flash', d)">
            <span class="font-medium">{{ d.title }}</span>
            <span class="text-slate-500">{{ d.summary }} · {{ d.applies_to === 'all' ? 'tout le catalogue' : d.targets.join(', ') }}</span>
            <span class="rounded px-2 py-0.5 text-xs" :class="STATUS[d.status][1]">{{ STATUS[d.status][0] }}</span>
            <span class="ml-auto text-xs text-slate-500">jusqu'au {{ formatDateTime(d.ends_at) }} · {{ d.orders }} commande(s) · {{ money(d.revenue) }}</span>
          </button>
        </li>
        <li v-if="list && !flash.length" class="py-3 text-slate-500">Aucune vente flash.</li>
      </ul>
    </section>

    <section class="card p-4">
      <div class="mb-3 flex items-center">
        <div>
          <h3 class="font-semibold"><Icon name="tag" :size="18" class="mr-1 inline-block align-[-3px]" />Codes promo</h3>
          <p class="text-sm text-slate-500">Le client saisit le code au panier ; vous pouvez aussi l'appliquer à une commande saisie à la main.</p>
        </div>
        <button class="btn-secondary ml-auto px-3 py-1.5" @click="open('code')">+ Code promo</button>
      </div>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="d in codes" :key="d.id">
          <button class="flex w-full flex-wrap items-center gap-2 py-2 text-left hover:text-emerald-700" @click="open('code', d)">
            <code class="rounded bg-slate-100 px-2 py-0.5 font-semibold">{{ d.code }}</code>
            <span class="text-slate-500">{{ d.summary }}<template v-if="d.min_subtotal"> dès {{ money(d.min_subtotal) }}</template></span>
            <span class="rounded px-2 py-0.5 text-xs" :class="STATUS[d.status][1]">{{ STATUS[d.status][0] }}</span>
            <span class="ml-auto text-xs text-slate-500">{{ d.used_count }}{{ d.usage_limit ? ` / ${d.usage_limit}` : '' }} utilisation(s) · {{ money(d.discounted) }} offerts · {{ money(d.revenue) }} de ventes livrées</span>
          </button>
        </li>
        <li v-if="list && !codes.length" class="py-3 text-slate-500">Aucun code promo.</li>
      </ul>
    </section>

    <p class="text-sm text-slate-500">Prix par quantité (« 3 pour 25 000 F ») : sur la fiche de chaque produit, dans le catalogue.</p>

    <Modal v-if="editing" :title="editing.id ? 'Modifier la promotion' : editing.kind === 'flash' ? 'Nouvelle vente flash' : 'Nouveau code promo'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="errors.form">{{ errors.form }}</Alert>
        <Field v-if="editing.kind === 'code'" id="pr-code" label="Code" :error="errors.code" hint="Lettres et chiffres, ex. BIENVENUE, TABASKI10">
          <div class="flex gap-2">
            <input id="pr-code" v-model="editing.code" class="input uppercase" maxlength="40" required />
            <button v-if="editing.id" type="button" class="btn-ghost" @click="copy(editing.code)">Copier</button>
          </div>
        </Field>
        <Field id="pr-title" :label="editing.kind === 'flash' ? 'Nom (affiché à vous seulement)' : 'Description (facultatif)'" :error="errors.title">
          <input id="pr-title" v-model="editing.title" class="input" maxlength="120" :required="editing.kind === 'flash'" placeholder="ex. Tabaski" />
        </Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="pr-type" label="Remise" :error="errors.type">
            <select id="pr-type" v-model="editing.type" class="input">
              <option value="percentage">Pourcentage</option>
              <option value="fixed_amount">Montant{{ editing.kind === 'flash' ? ' par article' : '' }}</option>
              <option v-if="editing.kind === 'code'" value="free_shipping">Livraison offerte</option>
            </select>
          </Field>
          <Field v-if="editing.type !== 'free_shipping'" id="pr-value" :label="editing.type === 'percentage' ? 'Pourcentage (%)' : 'Montant (F)'" :error="errors.value">
            <input id="pr-value" v-model="editing.value" type="number" min="1" :max="editing.type === 'percentage' ? 100 : undefined" class="input" required />
          </Field>
        </div>
        <Field id="pr-applies" label="S'applique à" :error="errors.target_ids">
          <select id="pr-applies" v-model="editing.applies_to" class="input" @change="editing.target_ids = []">
            <option value="all">Tout le catalogue</option>
            <option value="products">Des produits</option>
            <option value="collections">Des collections</option>
          </select>
        </Field>
        <div v-if="editing.applies_to !== 'all'" class="space-y-2">
          <input v-if="editing.applies_to === 'products'" v-model="search" class="input" placeholder="Chercher un produit…" @input="findProducts" />
          <ul class="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
            <li v-for="t in targetOptions" :key="t.id">
              <label class="flex cursor-pointer items-center gap-2 px-3 py-2">
                <input type="checkbox" :checked="editing.target_ids.includes(t.id)" class="h-4 w-4 accent-emerald-700" @change="toggleTarget(t.id)" />
                {{ t.title }}
              </label>
            </li>
          </ul>
          <p class="text-xs text-slate-500">{{ editing.target_ids.length }} sélectionné(s)</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <Field id="pr-start" label="Début" :error="errors.starts_at" hint="Vide : tout de suite"><input id="pr-start" v-model="editing.starts_at" type="datetime-local" class="input" /></Field>
          <Field id="pr-end" label="Fin" :error="errors.ends_at" :hint="editing.kind === 'flash' ? 'Compte à rebours jusqu\'à cette date' : 'Vide : sans fin'">
            <input id="pr-end" v-model="editing.ends_at" type="datetime-local" class="input" :required="editing.kind === 'flash'" />
          </Field>
        </div>
        <div v-if="editing.kind === 'code'" class="grid grid-cols-3 gap-3">
          <Field id="pr-min" label="Achat minimum (F)" :error="errors.min_subtotal"><input id="pr-min" v-model="editing.min_subtotal" type="number" min="0" class="input" /></Field>
          <Field id="pr-limit" label="Utilisations max." :error="errors.usage_limit"><input id="pr-limit" v-model="editing.usage_limit" type="number" min="1" class="input" /></Field>
          <Field id="pr-per" label="Par client" :error="errors.per_customer_limit"><input id="pr-per" v-model="editing.per_customer_limit" type="number" min="1" class="input" /></Field>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Active</label>
        <div class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="remove">Supprimer</button>
          <button class="btn-brand ml-auto" :disabled="busy">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
