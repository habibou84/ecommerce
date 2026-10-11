<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { money } from '../../../lib/format'

const data = ref(null)
const sim = ref(null)
const report = ref(null)
const error = ref('')
const errors = ref({})
const saved = ref('')
const editing = ref(null)
const routes = ref({})
const settings = reactive({ online_payment: 'off', deposit_percent: 30 })

async function load() {
  try {
    data.value = (await http.get('/payments/settings')).data.data
    Object.assign(settings, { online_payment: data.value.online_payment, deposit_percent: data.value.deposit_percent ?? 30 })
    routes.value = Object.fromEntries(Object.keys(data.value.methods).map((m) => [m, [...(data.value.routes[m] ?? [])]]))
    const [s, r] = await Promise.all([http.get('/payments/simulator'), http.get('/payments/report')])
    sim.value = s.data.data
    report.value = r.data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

const provider = computed(() => data.value?.providers.find((p) => p.code === editing.value?.provider))
const accountName = (id) => data.value?.accounts.find((a) => a.id === id)?.name
const accountsFor = (method) => data.value?.accounts.filter((a) => a.methods.includes(method) && a.status === 'active') ?? []

async function saveSettings() {
  error.value = ''
  try {
    await http.patch('/payments/settings', settings)
    saved.value = 'settings'
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function openAccount(account) {
  errors.value = {}
  editing.value = account
    ? { id: account.id, provider: account.provider, label: account.label, mode: account.mode, fee_percent: account.fee_percent, fee_fixed: account.fee_fixed, status: account.status, credentials: {}, hint: account.credentials_hint, webhook_url: account.webhook_url }
    : { provider: 'wave', label: '', mode: 'live', fee_percent: null, fee_fixed: 0, credentials: {} }
}

async function saveAccount() {
  errors.value = {}
  try {
    const payload = { ...editing.value }
    delete payload.hint
    delete payload.webhook_url
    if (payload.id) {
      delete payload.provider
      await http.patch(`/payments/accounts/${payload.id}`, payload)
    } else {
      await http.post('/payments/accounts', payload)
    }
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  }
}

async function removeAccount() {
  if (!confirm('Supprimer ce compte de paiement ?')) return
  await http.delete(`/payments/accounts/${editing.value.id}`)
  editing.value = null
  await load()
}

function setRoute(method, index, id) {
  const list = routes.value[method].slice(0, index)
  if (id) list.push(Number(id))
  routes.value[method] = [...new Set(list)]
}

async function saveRoutes() {
  error.value = ''
  try {
    await http.put('/payments/routes', { routes: routes.value })
    saved.value = 'routes'
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function applyBest() {
  for (const row of sim.value.methods) {
    if (row.best_account_id) routes.value[row.method] = [row.best_account_id, ...routes.value[row.method].filter((id) => id !== row.best_account_id)]
  }
  await saveRoutes()
}

const copy = (text) => navigator.clipboard?.writeText(text)
</script>

<template>
  <div v-if="data" class="space-y-5">
    <p class="text-sm text-slate-600">
      Vos clients paient sur <strong>vos</strong> comptes Wave, CinetPay ou PayDunya : l'argent vous arrive directement. Le paiement à la livraison reste toujours possible.
    </p>
    <Alert v-if="error">{{ error }}</Alert>

    <section class="card space-y-3 p-4">
      <h3 class="font-semibold">Paiement en ligne sur la boutique</h3>
      <div class="grid gap-2 sm:grid-cols-2">
        <label v-for="[value, label, help] in [['off', 'Désactivé', 'Paiement à la livraison uniquement'], ['optional', 'Au choix du client', 'À la livraison ou en ligne'], ['deposit', 'Acompte exigé', 'Une partie en ligne, le reste à la livraison'], ['required', 'Paiement en ligne exigé', 'Tout en ligne à la commande']]" :key="value"
               class="flex cursor-pointer gap-2 rounded-lg border p-3 text-sm" :class="settings.online_payment === value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200'">
          <input v-model="settings.online_payment" type="radio" :value="value" class="mt-0.5 accent-emerald-700" />
          <span><span class="block font-medium">{{ label }}</span><span class="text-slate-500">{{ help }}</span></span>
        </label>
      </div>
      <Field v-if="settings.online_payment === 'deposit'" id="deposit" label="Acompte (% du total)">
        <input id="deposit" v-model.number="settings.deposit_percent" type="number" min="5" max="90" class="input w-32" />
      </Field>
      <p v-if="settings.online_payment !== 'off' && !data.available_methods.length" class="text-sm text-amber-800">Ajoutez un compte de paiement ci-dessous : sans compte, la boutique reste en paiement à la livraison.</p>
      <div class="flex items-center gap-3">
        <span v-if="saved === 'settings'" class="text-sm text-emerald-700">Enregistré ✓</span>
        <button class="btn-brand ml-auto" @click="saveSettings">Enregistrer</button>
      </div>
    </section>

    <section class="card p-4">
      <div class="mb-3 flex items-center">
        <h3 class="font-semibold">Comptes de paiement</h3>
        <button class="btn-secondary ml-auto px-3 py-1.5" @click="openAccount(null)">+ Compte</button>
      </div>
      <p v-if="!data.accounts.length" class="text-sm text-slate-500">Aucun compte. Wave en direct coûte en général moins cher ; CinetPay et PayDunya acceptent aussi Orange Money, MTN, Moov et les cartes.</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="a in data.accounts" :key="a.id">
          <button class="flex w-full flex-wrap items-center gap-2 py-2 text-left hover:text-emerald-700" @click="openAccount(a)">
            <span class="font-medium">{{ a.name }}</span>
            <span v-if="a.mode === 'test'" class="rounded bg-amber-100 px-1.5 text-xs text-amber-900">test</span>
            <span v-if="a.status !== 'active'" class="rounded bg-slate-100 px-1.5 text-xs">désactivé</span>
            <span class="text-slate-500">{{ a.methods.map((m) => data.methods[m]).join(', ') }}</span>
            <span class="ml-auto text-slate-500">{{ a.fee_percent !== null ? `${a.fee_percent} %` : 'taux non saisi' }}{{ a.fee_fixed ? ` + ${money(a.fee_fixed)}` : '' }}</span>
          </button>
          <p v-if="a.last_error" class="pb-2 text-xs text-amber-800">Dernière erreur : {{ a.last_error }}</p>
        </li>
      </ul>
    </section>

    <section v-if="data.accounts.length" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Quel prestataire pour quel moyen de paiement ?</h3>
      <p class="text-slate-500">Si le premier ne répond pas, le paiement passe au suivant.</p>
      <div v-for="(label, method) in data.methods" :key="method" class="flex flex-wrap items-center gap-2">
        <span class="w-32 font-medium">{{ label }}</span>
        <select v-for="(id, index) in [...routes[method], null].slice(0, accountsFor(method).length)" :key="index" :value="id ?? ''" class="input w-auto py-1.5"
                :aria-label="`${label} : ${index === 0 ? 'prestataire principal' : 'secours'}`" @change="setRoute(method, index, $event.target.value)">
          <option value="">{{ index === 0 ? 'Non proposé' : 'Pas de secours' }}</option>
          <option v-for="a in accountsFor(method)" :key="a.id" :value="a.id">{{ index === 0 ? '' : 'puis ' }}{{ a.name }}</option>
        </select>
      </div>
      <div class="flex items-center gap-3">
        <span v-if="saved === 'routes'" class="text-emerald-700">Enregistré ✓</span>
        <button class="btn-brand ml-auto" @click="saveRoutes">Enregistrer</button>
      </div>
    </section>

    <section v-if="sim && data.accounts.length" class="card space-y-3 p-4 text-sm">
      <h3 class="font-semibold">Simulateur de frais</h3>
      <p class="text-slate-600">
        Ventes moyennes : <strong>{{ money(sim.monthly_volume) }}</strong> par mois ({{ sim.monthly_orders }} commandes, 3 derniers mois).
        {{ sim.based_on_payments ? 'Répartition d\'après vos paiements reçus.' : 'Répartition supposée égale entre les moyens de paiement.' }}
      </p>
      <Alert v-if="sim.missing_rates" tone="warning">Saisissez le taux de chaque compte (souvent négocié) pour une estimation juste.</Alert>
      <table class="w-full">
        <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Moyen</th><th>Volume / mois</th><th>Coût par prestataire</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="row in sim.methods.filter((r) => r.options.length)" :key="row.method">
            <td class="py-1.5 font-medium">{{ row.label }}</td>
            <td>{{ money(row.monthly_volume) }}</td>
            <td>
              <span v-for="o in row.options" :key="o.account_id" class="mr-3" :class="{ 'font-semibold text-emerald-700': o.account_id === row.best_account_id }">
                {{ o.name }} : {{ money(o.monthly_cost) }}{{ o.account_id === row.current_account_id ? ' (actuel)' : '' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>
      <div class="flex flex-wrap items-center gap-3">
        <p>Frais estimés : <strong>{{ money(sim.current_monthly_cost) }}</strong> / mois avec votre réglage, <strong class="text-emerald-700">{{ money(sim.best_monthly_cost) }}</strong> au mieux.</p>
        <button v-if="sim.best_monthly_cost < sim.current_monthly_cost" class="btn-secondary ml-auto" @click="applyBest">Choisir la combinaison la moins chère</button>
      </div>
    </section>

    <section v-if="report?.some((r) => r.count)" class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Frais réels (90 derniers jours)</h3>
      <table class="w-full">
        <thead class="text-left text-xs text-slate-500"><tr><th class="py-1">Compte</th><th class="text-right">Paiements</th><th class="text-right">Encaissé</th><th class="text-right">Frais</th><th class="text-right">Net</th><th class="text-right">Taux</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in report" :key="r.account_id">
            <td class="py-1.5">{{ r.name }}</td><td class="text-right">{{ r.count }}</td><td class="text-right">{{ money(r.amount) }}</td>
            <td class="text-right">{{ money(r.fees) }}</td><td class="text-right">{{ money(r.net) }}</td><td class="text-right">{{ r.effective_rate !== null ? `${r.effective_rate} %` : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <Modal v-if="editing" :title="editing.id ? accountName(editing.id) : 'Nouveau compte de paiement'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="saveAccount">
        <Field v-if="!editing.id" id="pa-provider" label="Prestataire">
          <select id="pa-provider" v-model="editing.provider" class="input" @change="editing.credentials = {}">
            <option v-for="p in data.providers" :key="p.code" :value="p.code">{{ p.name }}</option>
          </select>
        </Field>
        <Field v-for="(label, key) in provider?.fields" :id="`pa-${key}`" :key="key" :label="label" :error="errors[`credentials.${key}`]"
               :hint="editing.id && editing.hint?.[key] ? `Enregistré (${editing.hint[key]}) : laissez vide pour le garder` : ''">
          <input :id="`pa-${key}`" v-model="editing.credentials[key]" class="input font-mono" autocomplete="off" :required="!editing.id" />
        </Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="pa-fee" label="Frais (%)" :error="errors.fee_percent" hint="Votre taux, souvent négocié">
            <input id="pa-fee" v-model="editing.fee_percent" type="number" step="0.01" min="0" max="20" class="input" />
          </Field>
          <Field id="pa-fixed" label="Frais fixes (F)"><input id="pa-fixed" v-model.number="editing.fee_fixed" type="number" min="0" class="input" /></Field>
        </div>
        <div class="flex gap-4 text-sm">
          <label class="flex items-center gap-2"><input v-model="editing.mode" type="radio" value="live" class="accent-emerald-700" /> Réel</label>
          <label class="flex items-center gap-2"><input v-model="editing.mode" type="radio" value="test" class="accent-emerald-700" /> Test (bac à sable)</label>
        </div>
        <div v-if="editing.webhook_url" class="rounded bg-slate-50 p-2 text-xs">
          Adresse de notification à déclarer chez le prestataire :
          <code class="block break-all py-1">{{ editing.webhook_url }}</code>
          <button type="button" class="underline" @click="copy(editing.webhook_url)">Copier</button>
        </div>
        <div class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="removeAccount">Supprimer</button>
          <button class="btn-brand ml-auto">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
