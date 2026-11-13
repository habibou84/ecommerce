<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Field from './Field.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { formatDateTime, money } from '../lib/format'
import { PAYMENT_METHODS } from '../lib/orders'
import { useAuthStore } from '../stores/auth'

// Tiroir-caisse : ouverture (fond de caisse), dépenses, retraits et apports, clôture avec comptage
const emit = defineEmits(['changed'])
const auth = useAuthStore()
const canHold = computed(() => auth.can('cash.manage'))

const register = ref(null)
const error = ref('')
const openingFloat = ref(0)

const moveForm = reactive({ open: false, type: 'expense', category: 'stock', channel: '', amount: null, method: 'cash', occurredOn: '', note: '', error: '' })
const closeForm = reactive({ open: false, counted: null, note: '', error: '', result: null })

const TYPES = { expense: 'Dépense', withdrawal: 'Argent sorti', deposit: 'Monnaie ajoutée' }

async function load() {
  try {
    register.value = (await http.get('/cash-register')).data.data
    openingFloat.value = register.value.suggested_float
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function refresh() {
  await load()
  emit('changed')
}

async function openRegister() {
  error.value = ''
  try {
    await http.post('/cash-register/open', { opening_float: openingFloat.value || 0 })
    await refresh()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function startMove(type) {
  Object.assign(moveForm, { open: true, type, category: 'stock', channel: '', amount: null, method: 'cash', occurredOn: '', note: '', error: '' })
}

async function saveMove() {
  moveForm.error = ''
  try {
    await http.post('/cash-register/movements', {
      type: moveForm.type,
      category: moveForm.type === 'expense' ? moveForm.category : null,
      amount: moveForm.amount,
      method: moveForm.type === 'expense' ? moveForm.method : 'cash',
      channel: moveForm.type === 'expense' && moveForm.category === 'marketing' ? moveForm.channel || null : null,
      occurred_on: moveForm.type === 'expense' && moveForm.method !== 'cash' ? moveForm.occurredOn || null : null,
      note: moveForm.note || null,
    })
    moveForm.open = false
    await refresh()
  } catch (e) {
    moveForm.error = errorMessage(e)
  }
}

function startClose() {
  Object.assign(closeForm, { open: true, counted: null, note: '', error: '', result: null })
}

const preview = computed(() => (closeForm.counted === null || closeForm.counted === '' ? null : Number(closeForm.counted) - register.value.session.expected_cash))

async function saveClose() {
  closeForm.error = ''
  try {
    closeForm.result = (await http.post('/cash-register/close', { counted_cash: closeForm.counted, note: closeForm.note || null })).data.data
    await refresh()
  } catch (e) {
    closeForm.error = errorMessage(e)
  }
}

function gap(difference) {
  if (!difference) return 'Juste'
  return `${difference < 0 ? 'Manque' : 'Excédent'} ${money(Math.abs(difference))}`
}
</script>

<template>
  <section v-if="register" class="space-y-3">
    <h2 class="text-lg font-semibold">Tiroir-caisse du jour</h2>
    <Alert v-if="error">{{ error }}</Alert>

    <!-- Tiroir fermé -->
    <div v-if="!register.session" class="card space-y-3 p-4">
      <p class="text-sm text-slate-600">Le tiroir est fermé. Ouvrez-le avec le fond de caisse (la monnaie que vous mettez dans le tiroir au départ) avant d'encaisser des espèces.</p>
      <form v-if="canHold" class="flex items-end gap-2" @submit.prevent="openRegister">
        <Field id="cd-float" label="Fond de caisse (monnaie de départ)" class="flex-1"><input id="cd-float" v-model.number="openingFloat" type="number" min="0" class="input" /></Field>
        <button class="btn-brand">Ouvrir la caisse</button>
      </form>
      <RouterLink v-if="canHold" to="/gestion/caisse/vente" class="block text-sm text-emerald-800 underline">Vendre sans espèces (Wave, Orange Money…)</RouterLink>
    </div>

    <!-- Tiroir ouvert -->
    <div v-else class="card space-y-4 p-4">
      <p class="flex items-center gap-2 text-sm text-slate-600">
        <span class="h-2 w-2 shrink-0 rounded-full bg-emerald-600" aria-hidden="true"></span>
        <span><strong class="text-emerald-800">Ouverte</strong> {{ formatDateTime(register.session.opened_at) }}<template v-if="register.session.opened_by"> par {{ register.session.opened_by }}</template></span>
      </p>
      <div>
        <p class="text-sm text-slate-600">Espèces qui doivent être dans le tiroir</p>
        <p class="whitespace-nowrap text-3xl font-bold tracking-tight">{{ money(register.session.expected_cash) }}</p>
        <p class="text-xs text-slate-600">Départ de la journée (fond de caisse) : {{ money(register.session.opening_float) }}</p>
      </div>

      <template v-if="canHold">
        <RouterLink to="/gestion/caisse/vente" class="btn-brand flex min-h-12 w-full items-center justify-center gap-2 text-base">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h18v12H3zM3 11h18M7 15h3M16 4H8l-1 3h10z" /></svg>
          Vente au comptoir
        </RouterLink>
        <div class="grid grid-cols-2 gap-2">
          <button class="btn-secondary" @click="startMove('expense')">Noter une dépense</button>
          <button class="btn-secondary" @click="startMove('withdrawal')">Sortir ou ajouter</button>
        </div>
      </template>

      <div v-if="register.movements.length" class="border-t border-slate-100 pt-3">
        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-600">Aujourd'hui dans le tiroir</p>
        <ul class="divide-y divide-slate-100 text-sm">
          <li v-for="m in register.movements" :key="m.id" class="flex items-center gap-2 py-2">
            <span class="min-w-0 flex-1">
              <span class="block truncate">{{ TYPES[m.type] }}<template v-if="m.category_label"> · {{ m.category_label }}</template><template v-if="m.channel_label"> {{ m.channel_label }}</template></span>
              <span class="block truncate text-xs text-slate-600"><template v-if="m.note">{{ m.note }} · </template>{{ formatDateTime(m.occurred_at) }}<template v-if="m.method !== 'cash'"> · {{ m.method }}</template></span>
            </span>
            <span class="whitespace-nowrap font-semibold" :class="m.type === 'deposit' ? 'text-emerald-800' : 'text-red-700'">{{ m.type === 'deposit' ? '+' : '−' }}{{ money(m.amount) }}</span>
          </li>
        </ul>
      </div>

      <div v-if="canHold" class="space-y-1.5 border-t border-slate-100 pt-4">
        <button class="btn-secondary w-full" @click="startClose">Clôturer la journée</button>
        <p class="text-center text-xs text-slate-600">Le soir : comptez les espèces du tiroir et comparez-les au montant attendu.</p>
      </div>
    </div>

    <details v-if="register.closings.length" class="card p-4 text-sm">
      <summary class="cursor-pointer font-medium">Voir les dernières clôtures</summary>
      <ul class="mt-2 divide-y divide-slate-100">
        <li v-for="c in register.closings" :key="c.id" class="flex flex-wrap items-center gap-2 py-1.5">
          <span class="flex-1">{{ formatDateTime(c.closed_at) }}<template v-if="c.closed_by"> · {{ c.closed_by }}</template></span>
          <span class="whitespace-nowrap">{{ money(c.counted_cash) }} / {{ money(c.expected_cash) }}</span>
          <span class="rounded px-2 py-0.5 text-xs" :class="!c.difference ? 'bg-emerald-100 text-emerald-800' : c.difference < 0 ? 'bg-red-100 text-red-800' : 'bg-sky-100 text-sky-800'">{{ gap(c.difference) }}</span>
          <p v-if="c.note" class="w-full text-xs text-slate-500">{{ c.note }}</p>
        </li>
      </ul>
    </details>

    <Modal v-if="moveForm.open" :title="moveForm.type === 'expense' ? 'Noter une dépense' : 'Sortir ou ajouter de l\'argent'" @close="moveForm.open = false">
      <form class="space-y-4" @submit.prevent="saveMove">
        <Alert v-if="moveForm.error">{{ moveForm.error }}</Alert>
        <div v-if="moveForm.type !== 'expense'" class="flex rounded-lg bg-slate-100 p-1 text-sm" role="group">
          <button type="button" class="flex-1 rounded-md py-1" :class="moveForm.type === 'withdrawal' ? 'bg-white shadow-sm' : ''" @click="moveForm.type = 'withdrawal'">Sortir de l'argent (banque, prélèvement)</button>
          <button type="button" class="flex-1 rounded-md py-1" :class="moveForm.type === 'deposit' ? 'bg-white shadow-sm' : ''" @click="moveForm.type = 'deposit'">Ajouter de la monnaie</button>
        </div>
        <Field v-if="moveForm.type === 'expense'" id="mv-cat" label="Catégorie">
          <select id="mv-cat" v-model="moveForm.category" class="input"><option v-for="c in register.categories" :key="c.value" :value="c.value">{{ c.label }}</option></select>
        </Field>
        <Field v-if="moveForm.type === 'expense' && moveForm.category === 'marketing'" id="mv-channel" label="Publicité pour" hint="Pour savoir combien vous coûte chaque commande livrée de ce canal (Statistiques).">
          <select id="mv-channel" v-model="moveForm.channel" class="input"><option value="">Plusieurs canaux / autre</option><option v-for="c in register.channels" :key="c.value" :value="c.value">{{ c.label }}</option></select>
        </Field>
        <Field id="mv-amount" label="Montant"><input id="mv-amount" v-model.number="moveForm.amount" type="number" min="1" class="input text-lg" required /></Field>
        <Field v-if="moveForm.type === 'expense'" id="mv-method" label="Payé en">
          <select id="mv-method" v-model="moveForm.method" class="input"><option v-for="m in PAYMENT_METHODS" :key="m.value" :value="m.value">{{ m.label }}</option></select>
        </Field>
        <Field v-if="moveForm.type === 'expense' && moveForm.method !== 'cash'" id="mv-date" label="Date de la dépense" hint="Laissez vide pour aujourd'hui.">
          <input id="mv-date" v-model="moveForm.occurredOn" type="date" class="input" />
        </Field>
        <Field id="mv-note" label="Note"><input id="mv-note" v-model="moveForm.note" class="input" maxlength="255" /></Field>
        <button class="btn-brand w-full">Enregistrer</button>
      </form>
    </Modal>

    <Modal v-if="closeForm.open" title="Clôturer la journée" @close="closeForm.open = false">
      <div v-if="closeForm.result" class="space-y-3 text-sm">
        <Alert :tone="closeForm.result.difference ? 'warning' : 'success'">
          Journée clôturée : {{ money(closeForm.result.counted_cash) }} comptés pour {{ money(closeForm.result.expected_cash) }} attendus. {{ gap(closeForm.result.difference) }}.
        </Alert>
        <button class="btn-brand w-full" @click="closeForm.open = false">Fermer</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="saveClose">
        <Alert v-if="closeForm.error">{{ closeForm.error }}</Alert>
        <p class="text-sm">Espèces attendues : <strong>{{ money(register.session.expected_cash) }}</strong></p>
        <Field id="cl-counted" label="Espèces comptées dans le tiroir"><input id="cl-counted" v-model.number="closeForm.counted" type="number" min="0" class="input text-lg" required /></Field>
        <p v-if="preview !== null" class="text-sm font-medium" :class="preview < 0 ? 'text-red-700' : preview > 0 ? 'text-sky-800' : 'text-emerald-800'">{{ gap(preview) }}</p>
        <Field id="cl-note" label="Note (explication d'un écart)"><input id="cl-note" v-model="closeForm.note" class="input" maxlength="255" /></Field>
        <button class="btn-brand w-full">Clôturer la journée</button>
      </form>
    </Modal>
  </section>
</template>
