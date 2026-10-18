<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { cachedGet, offline, shrinkPhoto } from '../../lib/courierOffline'
import { formatDateTime, money } from '../../lib/format'

const cash = ref(null)
const earnings = ref(null)
const declaring = ref(false)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const notice = ref('')
const form = reactive({ courier_id: null, amount: 0, method: 'cash', reference: '', note: '', photo: null })
const METHODS = [['cash', 'Espèces à la caisse'], ['wave', 'Wave'], ['orange_money', 'Orange Money'], ['mtn_momo', 'MTN MoMo'], ['moov_money', 'Moov Money']]
const STATUS = { pending: ['En attente de la boutique', 'bg-amber-100 text-amber-900'], confirmed: ['Confirmé', 'bg-emerald-100 text-emerald-800'], rejected: ['Refusé', 'bg-red-100 text-red-800'] }

async function load() {
  const [c, e] = await Promise.all([cachedGet('/courier/cash', 'cash'), cachedGet('/courier/earnings', 'earnings')])
  cash.value = c
  earnings.value = e
}
onMounted(load)

// Boutiques à qui il doit des espèces (plusieurs s'il livre pour plusieurs boutiques)
const owed = computed(() => (cash.value?.stores ?? []).filter((s) => s.held > 0))
const pending = computed(() => (cash.value?.declarations ?? []).some((d) => d.status === 'pending'))

function startDeclare() {
  const first = owed.value[0]
  Object.assign(form, { courier_id: first?.courier_id ?? null, amount: first?.held ?? 0, method: 'cash', reference: '', note: '', photo: null })
  errors.value = {}
  error.value = ''
  declaring.value = true
}
function pickStore(s) {
  form.courier_id = s.courier_id
  form.amount = s.held
}

async function declare() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const body = new FormData()
    Object.entries({ courier_id: form.courier_id, amount: form.amount, method: form.method, reference: form.reference, note: form.note })
      .forEach(([k, v]) => v !== null && v !== '' && body.append(k, v))
    if (form.photo) body.append('photo', await shrinkPhoto(form.photo), 'recu.jpg')
    await http.post('/courier/remittances', body)
    declaring.value = false
    notice.value = 'Versement déclaré ✓ La boutique est prévenue et va le confirmer.'
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="cash && earnings" class="space-y-4">
    <section class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-sm text-slate-500">Espèces à verser à la boutique</p>
      <p class="text-2xl font-bold">{{ money(cash.held) }}</p>
      <button v-if="owed.length && !declaring" class="btn-brand mt-3 w-full" :disabled="!offline.online" @click="startDeclare">💸 J'ai versé l'argent</button>
      <p v-if="owed.length && !offline.online" class="mt-1 text-xs text-slate-500">Il faut du réseau pour déclarer un versement.</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="(c, i) in cash.collections" :key="i" class="flex justify-between py-1.5"><span><template v-if="c.store">{{ c.store }} · </template>{{ c.reference }} · {{ formatDateTime(c.collected_at) }}</span><span>{{ money(c.amount) }}</span></li>
      </ul>
    </section>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <form v-if="declaring" class="space-y-3 rounded-xl bg-white p-4 shadow-sm" @submit.prevent="declare">
      <h2 class="font-semibold">Déclarer un versement</h2>
      <p class="text-sm text-slate-600">La boutique reçoit votre déclaration et la confirme quand elle a bien l'argent.</p>
      <Alert v-if="pending" tone="warning">Un versement attend déjà la confirmation de la boutique.</Alert>
      <Alert v-if="error">{{ error }}</Alert>
      <div v-if="owed.length > 1">
        <p class="label">Boutique</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="s in owed" :key="s.courier_id" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="form.courier_id === s.courier_id ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-slate-200'" @click="pickStore(s)">
            {{ s.store }} · {{ money(s.held) }}
          </button>
        </div>
      </div>
      <div>
        <p class="label">Versé par</p>
        <div class="grid grid-cols-2 gap-2">
          <button v-for="[value, label] in METHODS" :key="value" type="button" class="rounded-lg border px-2 py-2 text-sm" :class="form.method === value ? 'border-emerald-600 bg-emerald-50 font-medium' : 'border-slate-200'" @click="form.method = value">{{ label }}</button>
        </div>
      </div>
      <div>
        <label for="rd-amount" class="label">Montant versé</label>
        <input id="rd-amount" v-model.number="form.amount" type="number" min="1" class="input text-lg" required />
        <p v-if="errors.amount" class="mt-1 text-sm text-red-600">{{ errors.amount }}</p>
      </div>
      <div v-if="form.method !== 'cash'">
        <label for="rd-ref" class="label">Référence de l'envoi</label>
        <input id="rd-ref" v-model="form.reference" class="input" maxlength="80" placeholder="Dans le SMS de confirmation" required />
        <p v-if="errors.reference" class="mt-1 text-sm text-red-600">{{ errors.reference }}</p>
      </div>
      <div>
        <label for="rd-photo" class="label">Photo du reçu ou de l'écran <span class="font-normal text-slate-400">(facultatif)</span></label>
        <input id="rd-photo" type="file" accept="image/*" class="text-sm" @change="form.photo = $event.target.files[0]" />
      </div>
      <input v-model="form.note" class="input" maxlength="255" placeholder="Note (facultatif)" />
      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="btn-secondary" @click="declaring = false">Annuler</button>
        <button class="btn-brand" :disabled="busy || pending">{{ busy ? 'Envoi…' : 'Déclarer' }}</button>
      </div>
    </form>

    <section v-if="cash.declarations?.length" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-1 text-sm font-semibold">Mes versements déclarés</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="d in cash.declarations" :key="d.id" class="py-2">
          <span class="flex items-center gap-2">
            <span class="flex-1">{{ money(d.amount) }} · {{ d.method_label }}<template v-if="d.store"> · {{ d.store }}</template></span>
            <span class="rounded-full px-2 py-0.5 text-xs" :class="STATUS[d.status][1]">{{ STATUS[d.status][0] }}</span>
          </span>
          <span class="block text-xs text-slate-500">
            {{ formatDateTime(d.created_at) }}<template v-if="d.reference"> · réf. {{ d.reference }}</template>
            <template v-if="d.status === 'confirmed' && d.difference"> · {{ d.difference < 0 ? 'manque' : 'excédent' }} {{ money(Math.abs(d.difference)) }}</template>
            <template v-if="d.status === 'rejected' && d.reject_reason"> · {{ d.reject_reason }}</template>
          </span>
        </li>
      </ul>
    </section>

    <section class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-sm text-slate-500">Ma paie à recevoir</p>
      <p class="text-2xl font-bold text-emerald-700">{{ money(earnings.unpaid) }}</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="(l, i) in earnings.lines" :key="i" class="flex justify-between py-1.5">
          <span>{{ l.type_label }}<template v-if="l.reference"> · {{ l.reference }}</template><template v-if="l.store"> · {{ l.store }}</template></span>
          <span :class="{ 'text-red-600': l.amount < 0 }">{{ money(l.amount) }}</span>
        </li>
      </ul>
    </section>
    <section v-if="cash.remittances.length" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-1 text-sm font-semibold">Mes derniers versements</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="(r, i) in cash.remittances" :key="i" class="flex justify-between py-1.5">
          <span>{{ formatDateTime(r.received_at) }}</span>
          <span>{{ money(r.received_amount) }}<span v-if="r.difference" class="ml-1 text-amber-700">({{ r.difference > 0 ? '+' : '' }}{{ money(r.difference) }})</span></span>
        </li>
      </ul>
    </section>
  </div>
</template>
