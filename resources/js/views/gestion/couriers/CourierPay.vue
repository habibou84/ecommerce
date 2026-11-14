<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

// Paie des livreurs : ce que la boutique doit à chaque livreur (courses, primes, avances), relevés et règles de paie.
// L'argent que les livreurs ont encaissé pour la boutique est dans Caisse → Argent chez les livreurs.
const route = useRoute()
const data = ref(null)
const error = ref('')
const errors = ref({})
const modal = ref(null) // 'pay' | 'rule'
const pay = ref(null) // { courier, data, unpaid, payouts }
const adjust = reactive({ type: 'bonus', amount: '', note: '' })
const payForm = reactive({ method: 'wave', reference: '' })
const rule = ref(null)

async function load() {
  try {
    data.value = (await http.get('/couriers')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(async () => {
  await load()
  // Lien direct vers la paie d'un livreur (bouton « Paie » de Mes livreurs)
  const id = Number(route.query.livreur)
  const courier = id && data.value?.data.find((c) => c.id === id)
  if (courier) await openPay(courier)
})

// Livreurs à payer : les désactivés seulement s'il leur reste quelque chose
const payable = () => (data.value?.data ?? []).filter((c) => c.status !== 'invited' && (c.status !== 'inactive' || c.unpaid))
const totalUnpaid = () => payable().reduce((sum, c) => sum + (c.unpaid ?? 0), 0)

async function openPay(courier) {
  error.value = ''
  try {
    const { data: res } = await http.get(`/couriers/${courier.id}/earnings`)
    pay.value = { courier, ...res }
    Object.assign(adjust, { type: 'bonus', amount: '', note: '' })
    modal.value = 'pay'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function addAdjustment() {
  try {
    await http.post(`/couriers/${pay.value.courier.id}/earnings`, { ...adjust, amount: Number(adjust.amount) })
    await openPay(pay.value.courier)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function createPayout() {
  try {
    await http.post(`/couriers/${pay.value.courier.id}/payouts`)
    await openPay(pay.value.courier)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
async function markPaid(payout) {
  try {
    await http.post(`/courier-payouts/${payout.id}/pay`, { method: payForm.method, reference: payForm.reference || null })
    await openPay(pay.value.courier)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

function openRule(r) {
  rule.value = r ? { ...r, zone_amounts: { ...(r.zone_amounts ?? {}) } } : { name: '', type: 'per_delivery', amount: 1000, expedition_amount: null, failed_attempt_amount: 0, zone_amounts: {} }
  errors.value = {}
  modal.value = 'rule'
}
async function saveRule() {
  try {
    if (rule.value.id) await http.put(`/courier-pay-rules/${rule.value.id}`, rule.value)
    else await http.post('/courier-pay-rules', rule.value)
    modal.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
  }
}
async function deleteRule() {
  if (!confirm('Supprimer cette règle ?')) return
  await http.delete(`/courier-pay-rules/${rule.value.id}`)
  modal.value = null
  await load()
}
const ruleLabel = (r) => (r.type === 'per_zone' ? `${money(r.amount)} (selon la zone)` : `${money(r.amount)} par livraison`) + (r.expedition_amount != null ? `, ${money(r.expedition_amount)} par expédition` : '') + (r.failed_attempt_amount ? `, ${money(r.failed_attempt_amount)} par tentative` : '')
</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <Alert v-if="error && !modal">{{ error }}</Alert>

    <template v-if="data">
      <section class="card p-4 text-sm">
        <div class="mb-2 flex items-center gap-3">
          <h3 class="font-semibold">À payer aux livreurs</h3>
          <strong class="ml-auto text-base">{{ money(totalUnpaid()) }}</strong>
        </div>
        <p v-if="!payable().length" class="text-slate-500">Aucun livreur pour l'instant. Ajoutez-en dans <RouterLink to="/gestion/livraisons/livreurs" class="text-emerald-700 underline">Mes livreurs</RouterLink>.</p>
        <ul class="divide-y divide-slate-100">
          <li v-for="c in payable()" :key="c.id" class="flex items-center gap-3 py-2" :class="{ 'opacity-50': c.status === 'inactive' }">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ c.name }}</span>
              <span class="block text-slate-500">{{ c.pay_rule?.name ?? 'Sans règle de paie' }}</span>
            </span>
            <strong>{{ money(c.unpaid ?? 0) }}</strong>
            <button class="btn-secondary px-3 py-1" @click="openPay(c)">Payer</button>
          </li>
        </ul>
        <p class="mt-3 text-xs text-slate-500">
          L'argent que vos livreurs ont encaissé pour vous (paiements des clients) se règle dans
          <RouterLink to="/gestion/caisse/livreurs" class="text-emerald-700 underline">Caisse → Argent chez les livreurs</RouterLink>.
        </p>
      </section>

      <section class="card p-4 text-sm">
        <div class="mb-2 flex items-center">
          <h3 class="font-semibold">Règles de paie</h3>
          <button class="btn-secondary ml-auto px-3 py-1" @click="openRule(null)">+ Règle</button>
        </div>
        <p v-if="!data.pay_rules.length" class="text-slate-500">Exemple : 1 000 F par livraison réussie, 300 F par tentative échouée.</p>
        <ul class="divide-y divide-slate-100">
          <li v-for="r in data.pay_rules" :key="r.id"><button class="w-full py-2 text-left hover:text-emerald-700" @click="openRule(r)"><strong>{{ r.name }}</strong> · {{ ruleLabel(r) }}</button></li>
        </ul>
        <p class="mt-2 text-xs text-slate-500">La règle de chaque livreur se choisit sur sa fiche, dans Mes livreurs.</p>
      </section>
    </template>

    <Modal v-if="modal === 'pay'" :title="`Paie de ${pay.courier.name}`" wide @close="modal = null">
      <div class="space-y-4 text-sm">
        <Alert v-if="error">{{ error }}</Alert>
        <div class="flex items-center gap-3">
          <p class="text-lg">À payer : <strong>{{ money(pay.unpaid) }}</strong></p>
          <button class="btn-brand ml-auto" :disabled="!pay.data.length" @click="createPayout">Préparer le relevé</button>
        </div>
        <ul class="max-h-60 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
          <li v-for="e in pay.data" :key="e.id" class="flex justify-between px-3 py-1.5">
            <span>{{ e.type_label }}<template v-if="e.reference"> · {{ e.reference }}</template><template v-if="e.note"> · {{ e.note }}</template> <span class="text-xs text-slate-400">{{ formatDateTime(e.created_at) }}</span></span>
            <span :class="{ 'text-red-600': e.amount < 0 }">{{ money(e.amount) }}</span>
          </li>
          <li v-if="!pay.data.length" class="px-3 py-2 text-slate-500">Rien à payer.</li>
        </ul>
        <form class="flex flex-wrap gap-2" @submit.prevent="addAdjustment">
          <select v-model="adjust.type" class="input w-auto"><option value="bonus">Prime</option><option value="advance">Avance</option><option value="adjustment">Ajustement</option></select>
          <input v-model="adjust.amount" type="number" class="input w-32" placeholder="Montant" required />
          <input v-model="adjust.note" class="input min-w-0 flex-1" placeholder="Note" />
          <button class="btn-secondary">Ajouter</button>
        </form>
        <div v-if="pay.payouts.length">
          <h4 class="mb-1 font-semibold">Relevés</h4>
          <ul class="divide-y divide-slate-100">
            <li v-for="p in pay.payouts" :key="p.id" class="flex flex-wrap items-center gap-2 py-2">
              <span class="flex-1">Jusqu'au {{ new Date(p.period_end).toLocaleDateString('fr-FR') }} · {{ p.lines_count }} ligne(s)</span>
              <strong>{{ money(p.total) }}</strong>
              <template v-if="p.status === 'draft'">
                <select v-model="payForm.method" class="input w-auto py-1"><option value="wave">Wave</option><option value="orange_money">Orange Money</option><option value="mtn_momo">MTN</option><option value="moov_money">Moov</option><option value="cash">Espèces</option></select>
                <button class="btn-brand px-2 py-1" @click="markPaid(p)">Marquer payé</button>
              </template>
              <span v-else class="rounded px-2 py-0.5 text-xs" :class="p.status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100'">{{ p.status === 'paid' ? `Payé (${p.method})` : 'Annulé' }}</span>
            </li>
          </ul>
        </div>
      </div>
    </Modal>

    <Modal v-if="modal === 'rule'" :title="rule.id ? rule.name : 'Nouvelle règle de paie'" @close="modal = null">
      <form class="space-y-4" @submit.prevent="saveRule">
        <Field id="ru-name" label="Nom" :error="errors.name"><input id="ru-name" v-model="rule.name" class="input" required /></Field>
        <Field id="ru-type" label="Calcul">
          <select id="ru-type" v-model="rule.type" class="input"><option value="per_delivery">Montant fixe par livraison</option><option value="per_zone">Selon la zone de livraison</option></select>
        </Field>
        <Field id="ru-amount" :label="rule.type === 'per_zone' ? 'Montant par défaut' : 'Par livraison réussie'" :error="errors.amount">
          <input id="ru-amount" v-model.number="rule.amount" type="number" min="0" class="input" required />
        </Field>
        <div v-if="rule.type === 'per_zone'" class="space-y-2">
          <div v-for="z in data.zones" :key="z.id" class="flex items-center gap-2 text-sm">
            <span class="flex-1">{{ z.name }}</span>
            <input v-model.number="rule.zone_amounts[z.id]" type="number" min="0" class="input w-32" :placeholder="String(rule.amount)" />
          </div>
        </div>
        <Field id="ru-expedition" label="Par expédition (colis déposé à la gare)" hint="Vide : le montant par défaut. Le prix convenu avec le client ne change pas cette paie.">
          <input id="ru-expedition" v-model.number="rule.expedition_amount" type="number" min="0" class="input" :placeholder="String(rule.amount)" />
        </Field>
        <Field id="ru-failed" label="Par tentative échouée"><input id="ru-failed" v-model.number="rule.failed_attempt_amount" type="number" min="0" class="input" /></Field>
        <div class="flex gap-2">
          <button v-if="rule.id" type="button" class="btn-ghost text-red-600" @click="deleteRule">Supprimer</button>
          <button class="btn-brand ml-auto">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
