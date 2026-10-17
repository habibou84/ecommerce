<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import DeliveryTabs from '../../../components/DeliveryTabs.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'
import { useAuthStore } from '../../../stores/auth'

const auth = useAuthStore()
// Autres boutiques de l'adresse : le nouveau livreur peut y travailler aussi
const otherStores = () => (auth.profile?.stores ?? []).filter((s) => s.id !== auth.store?.id)

const data = ref(null)
const error = ref('')
const errors = ref({})
const busy = ref(false)
const modal = ref(null) // 'edit' | 'link' | 'pay' | 'rule'
const editing = ref(null)
const link = ref(null)
const pay = ref(null) // { courier, earnings, unpaid, payouts }
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
onMounted(load)

function open(courier) {
  errors.value = {}
  error.value = ''
  editing.value = courier
    ? { ...courier, pay_rule_id: courier.pay_rule?.id ?? null, zone_ids: courier.zones.map((z) => z.id) }
    : { name: '', phone: '', vehicle_type: 'moto', pay_rule_id: null, cash_limit: null, zone_ids: [], note: '', store_ids: [] }
  modal.value = 'edit'
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const payload = { ...editing.value, cash_limit: editing.value.cash_limit === '' ? null : editing.value.cash_limit }
    if (editing.value.id) {
      delete payload.phone
      await http.patch(`/couriers/${editing.value.id}`, payload)
      modal.value = null
    } else {
      const { data: res } = await http.post('/couriers', payload)
      link.value = res.invitation_url ? { name: res.data.name, phone: res.data.phone, url: res.invitation_url } : null
      modal.value = link.value ? 'link' : null
    }
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function reinvite(courier) {
  const { data: res } = await http.post(`/couriers/${courier.id}/invitation`)
  link.value = { name: courier.name, phone: courier.phone, url: res.invitation_url }
  modal.value = 'link'
}

async function toggleAvailable(courier) {
  await http.patch(`/couriers/${courier.id}`, { is_available: !courier.is_available })
  courier.is_available = !courier.is_available
}

async function deactivate(courier) {
  if (!confirm(`Désactiver ${courier.name} ? Il ne pourra plus se connecter à l'application.`)) return
  try {
    await http.post(`/couriers/${courier.id}/deactivate`)
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

const whatsappLink = (l) => `https://wa.me/${l.phone.replace('+', '')}?text=${encodeURIComponent(`Bonjour ${l.name}, voici votre accès à l'application livreur : ${l.url}`)}`

async function openPay(courier) {
  error.value = ''
  const { data: res } = await http.get(`/couriers/${courier.id}/earnings`)
  pay.value = { courier, ...res }
  Object.assign(adjust, { type: 'bonus', amount: '', note: '' })
  modal.value = 'pay'
}
async function addAdjustment() {
  try {
    await http.post(`/couriers/${pay.value.courier.id}/earnings`, { ...adjust, amount: Number(adjust.amount) })
    await openPay(pay.value.courier)
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
  await http.post(`/courier-payouts/${payout.id}/pay`, { method: payForm.method, reference: payForm.reference || null })
  await openPay(pay.value.courier)
  await load()
}

function openRule(r) {
  rule.value = r ? { ...r, zone_amounts: { ...(r.zone_amounts ?? {}) } } : { name: '', type: 'per_delivery', amount: 1000, failed_attempt_amount: 0, zone_amounts: {} }
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
const ruleLabel = (r) => (r.type === 'per_zone' ? `${money(r.amount)} (selon la zone)` : `${money(r.amount)} par livraison`) + (r.failed_attempt_amount ? `, ${money(r.failed_attempt_amount)} par tentative` : '')
</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Vos livreurs reçoivent leurs missions dans l'application <strong>/livreur</strong>, sur leur téléphone.</p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Livreur</button>
    </div>
    <Alert v-if="error && !modal">{{ error }}</Alert>

    <div v-if="data" class="card">
      <p v-if="!data.data.length" class="p-6 text-sm text-slate-500">Aucun livreur. Ajoutez-en un : il reçoit un lien pour créer son accès.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="c in data.data" :key="c.id" class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" :class="{ 'opacity-50': c.status === 'inactive' }">
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ c.name }} <span class="font-normal text-slate-500">· {{ c.phone_display }} · {{ data.vehicles[c.vehicle_type] }}</span></span>
            <span class="block text-slate-500">
              {{ c.zones.map((z) => z.name).join(', ') || 'Toutes zones' }} · {{ c.pay_rule?.name ?? 'sans règle de paie' }}
              <template v-if="c.active_missions"> · {{ c.active_missions }} mission(s) en cours</template>
            </span>
          </span>
          <span v-if="c.status === 'invited'" class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900">Invité</span>
          <span v-else-if="c.status === 'inactive'" class="rounded bg-slate-100 px-2 py-0.5 text-xs">Désactivé</span>
          <button v-else class="rounded-full px-2 py-0.5 text-xs" :class="c.is_available ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'" @click="toggleAvailable(c)">
            {{ c.is_available ? 'Disponible' : 'Indisponible' }}
          </button>
          <span class="w-28 text-right" :class="{ 'font-semibold text-amber-800': c.cash_limit && c.cash_held > c.cash_limit }">{{ money(c.cash_held) }} <span class="block text-xs font-normal text-slate-400">espèces</span></span>
          <span class="flex gap-1">
            <button v-if="c.status === 'invited'" class="btn-secondary px-2 py-1" @click="reinvite(c)">Lien</button>
            <button v-if="c.status !== 'invited'" class="btn-secondary px-2 py-1" @click="openPay(c)">Paie · {{ money(c.unpaid) }}</button>
            <button class="btn-ghost px-2 py-1" @click="open(c)">Modifier</button>
            <button v-if="c.status !== 'inactive'" class="btn-ghost px-2 py-1 text-red-600" @click="deactivate(c)">✕</button>
          </span>
        </li>
      </ul>
    </div>

    <section v-if="data" class="card p-4 text-sm">
      <div class="mb-2 flex items-center">
        <h3 class="font-semibold">Règles de paie</h3>
        <button class="btn-secondary ml-auto px-3 py-1" @click="openRule(null)">+ Règle</button>
      </div>
      <p v-if="!data.pay_rules.length" class="text-slate-500">Exemple : 1 000 F par livraison réussie, 300 F par tentative échouée.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="r in data.pay_rules" :key="r.id"><button class="w-full py-2 text-left hover:text-emerald-700" @click="openRule(r)"><strong>{{ r.name }}</strong> · {{ ruleLabel(r) }}</button></li>
      </ul>
    </section>

    <Modal v-if="modal === 'edit'" :title="editing.id ? editing.name : 'Nouveau livreur'" @close="modal = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="cr-name" label="Nom" :error="errors.name"><input id="cr-name" v-model="editing.name" class="input" required maxlength="80" /></Field>
        <Field v-if="!editing.id" id="cr-phone" label="Téléphone" :error="errors.phone"><input id="cr-phone" v-model="editing.phone" type="tel" class="input" required /></Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="cr-vehicle" label="Véhicule">
            <select id="cr-vehicle" v-model="editing.vehicle_type" class="input"><option v-for="(label, key) in data.vehicles" :key="key" :value="key">{{ label }}</option></select>
          </Field>
          <Field id="cr-limit" label="Plafond d'espèces" :error="errors.cash_limit" hint="Alerte au-delà">
            <input id="cr-limit" v-model="editing.cash_limit" type="number" min="0" class="input" placeholder="Aucun" />
          </Field>
        </div>
        <Field id="cr-rule" label="Règle de paie">
          <select id="cr-rule" v-model="editing.pay_rule_id" class="input"><option :value="null">Aucune</option><option v-for="r in data.pay_rules" :key="r.id" :value="r.id">{{ r.name }}</option></select>
        </Field>
        <fieldset>
          <legend class="label">Zones couvertes</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="z in data.zones" :key="z.id" class="flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm">
              <input v-model="editing.zone_ids" type="checkbox" :value="z.id" class="accent-emerald-700" /> {{ z.name }}
            </label>
          </div>
        </fieldset>
        <fieldset v-if="!editing.id && otherStores().length">
          <legend class="label">Travaille aussi pour</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="st in otherStores()" :key="st.id" class="flex items-center gap-1.5 rounded-full border border-slate-200 px-3 py-1 text-sm">
              <input v-model="editing.store_ids" type="checkbox" :value="st.id" class="accent-emerald-700" /> {{ st.name }}
            </label>
          </div>
          <p class="mt-1 text-xs text-slate-500">Une seule application pour toutes ses livraisons ; chaque boutique garde ses zones et sa paie pour lui.</p>
        </fieldset>
        <button class="btn-brand w-full" :disabled="busy">{{ editing.id ? 'Enregistrer' : 'Ajouter et créer le lien' }}</button>
      </form>
    </Modal>

    <Modal v-if="modal === 'link'" :title="`Accès de ${link.name}`" @close="modal = null">
      <div class="space-y-3 text-sm">
        <p>Envoyez ce lien à {{ link.name }} : il crée son mot de passe, confirme son numéro par SMS et arrive dans l'application livreur.</p>
        <code class="block break-all rounded bg-slate-50 p-2">{{ link.url }}</code>
        <a :href="whatsappLink(link)" target="_blank" class="btn-brand w-full">Envoyer par WhatsApp</a>
      </div>
    </Modal>

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
        <Field id="ru-failed" label="Par tentative échouée"><input id="ru-failed" v-model.number="rule.failed_attempt_amount" type="number" min="0" class="input" /></Field>
        <div class="flex gap-2">
          <button v-if="rule.id" type="button" class="btn-ghost text-red-600" @click="deleteRule">Supprimer</button>
          <button class="btn-brand ml-auto">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
