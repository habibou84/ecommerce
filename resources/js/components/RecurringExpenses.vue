<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Field from './Field.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { formatDate, money } from '../lib/format'
import { PAYMENT_METHODS } from '../lib/orders'
import { useAuthStore } from '../stores/auth'

// Dépenses qui reviennent chaque mois (loyer, salaires…) : enregistrées automatiquement le jour choisi
const auth = useAuthStore()
const canEdit = computed(() => auth.can('cash.manage'))
const METHODS = PAYMENT_METHODS.filter((m) => m.value !== 'cash')

const items = ref([])
const options = ref({ categories: [], channels: [] })
const error = ref('')
const form = reactive({ open: false, id: null, category: 'rent', channel: '', amount: null, method: 'bank', day_of_month: 1, note: '', is_active: true, error: '' })

async function load() {
  try {
    const [list, register] = await Promise.all([http.get('/recurring-expenses'), http.get('/cash-register')])
    items.value = list.data.data
    options.value = { categories: register.data.data.categories.filter((c) => c.value !== 'stock'), channels: register.data.data.channels }
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function edit(item = null) {
  Object.assign(form, item
    ? { open: true, id: item.id, category: item.category, channel: item.channel ?? '', amount: item.amount, method: item.method, day_of_month: item.day_of_month, note: item.note ?? '', is_active: item.is_active, error: '' }
    : { open: true, id: null, category: 'rent', channel: '', amount: null, method: 'bank', day_of_month: 1, note: '', is_active: true, error: '' })
}

async function save() {
  form.error = ''
  const payload = { category: form.category, channel: form.category === 'marketing' ? form.channel || null : null, amount: form.amount, method: form.method, day_of_month: form.day_of_month, note: form.note || null, is_active: form.is_active }
  try {
    await (form.id ? http.put(`/recurring-expenses/${form.id}`, payload) : http.post('/recurring-expenses', payload))
    form.open = false
    await load()
  } catch (e) {
    form.error = errorMessage(e)
  }
}

async function remove() {
  if (!confirm('Supprimer cette dépense mensuelle ? Les dépenses déjà enregistrées restent.')) return
  try {
    await http.delete(`/recurring-expenses/${form.id}`)
    form.open = false
    await load()
  } catch (e) {
    form.error = errorMessage(e)
  }
}

const total = computed(() => items.value.filter((i) => i.is_active).reduce((sum, i) => sum + i.amount, 0))
</script>

<template>
  <section class="card space-y-3 p-4">
    <div class="flex items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold">Dépenses mensuelles</h2>
        <p class="text-sm text-slate-500">Loyer, salaires, internet, publicité… notés automatiquement chaque mois dans vos dépenses et votre bénéfice.</p>
      </div>
      <button v-if="canEdit" class="btn-secondary shrink-0" @click="edit()">Ajouter</button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <ul v-if="items.length" class="divide-y divide-slate-100 text-sm">
      <li v-for="i in items" :key="i.id" class="flex items-center gap-2 py-2" :class="i.is_active ? '' : 'opacity-60'">
        <div class="min-w-0 flex-1">
          <p class="font-medium">{{ i.category_label }}<template v-if="i.channel_label"> {{ i.channel_label }}</template><template v-if="i.note"> · {{ i.note }}</template></p>
          <p class="text-xs text-slate-500">Le {{ i.day_of_month }} de chaque mois<template v-if="i.next_date"> · prochaine fois le {{ formatDate(i.next_date) }}</template><template v-else> · en pause</template></p>
        </div>
        <span class="font-medium">{{ money(i.amount) }}</span>
        <button v-if="canEdit" class="text-sm text-emerald-700" @click="edit(i)">Modifier</button>
      </li>
      <li class="flex justify-between py-2 font-semibold"><span>Total par mois</span><span>{{ money(total) }}</span></li>
    </ul>
    <p v-else class="text-sm text-slate-500">Aucune dépense mensuelle.</p>

    <Modal v-if="form.open" :title="form.id ? 'Dépense mensuelle' : 'Nouvelle dépense mensuelle'" @close="form.open = false">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="form.error">{{ form.error }}</Alert>
        <Field id="re-cat" label="Catégorie">
          <select id="re-cat" v-model="form.category" class="input"><option v-for="c in options.categories" :key="c.value" :value="c.value">{{ c.label }}</option></select>
        </Field>
        <Field v-if="form.category === 'marketing'" id="re-channel" label="Publicité pour">
          <select id="re-channel" v-model="form.channel" class="input"><option value="">Plusieurs canaux / autre</option><option v-for="c in options.channels" :key="c.value" :value="c.value">{{ c.label }}</option></select>
        </Field>
        <Field id="re-amount" label="Montant par mois"><input id="re-amount" v-model.number="form.amount" type="number" min="1" class="input text-lg" required /></Field>
        <Field id="re-method" label="Payé en" hint="Les espèces se notent dans la caisse le jour du paiement.">
          <select id="re-method" v-model="form.method" class="input"><option v-for="m in METHODS" :key="m.value" :value="m.value">{{ m.label }}</option></select>
        </Field>
        <Field id="re-day" label="Jour du mois" hint="De 1 à 28.">
          <input id="re-day" v-model.number="form.day_of_month" type="number" min="1" max="28" class="input" required />
        </Field>
        <Field id="re-note" label="Note"><input id="re-note" v-model="form.note" class="input" maxlength="255" placeholder="Ex. Loyer du magasin" /></Field>
        <label v-if="form.id" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" /> Active</label>
        <div class="flex gap-2">
          <button v-if="form.id" type="button" class="btn-secondary text-red-700" @click="remove">Supprimer</button>
          <button class="btn-brand flex-1">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </section>
</template>
