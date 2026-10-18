<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { seenDecision } from '../../lib/courierPrefs'
import Alert from '../../components/Alert.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { cachedGet, offline, shrinkPhoto } from '../../lib/courierOffline'
import { formatDateTime, money } from '../../lib/format'

const cash = ref(null)
const earnings = ref(null)
const me = ref(null)
// Onglets : espèces à verser, versements, paie
const tab = ref('cash')
const declaring = ref(false)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const notice = ref('')
const form = reactive({ courier_id: null, amount: 0, method: 'cash', reference: '', note: '', photo: null })
const METHODS = [['cash', 'Espèces à la caisse'], ['wave', 'Wave'], ['orange_money', 'Orange Money'], ['mtn_momo', 'MTN MoMo'], ['moov_money', 'Moov Money']]
const STATUS = { pending: ['En attente de la boutique', 'bg-amber-100 text-amber-900'], confirmed: ['Confirmé', 'bg-emerald-100 text-emerald-800'], rejected: ['Refusé', 'bg-red-100 text-red-800'] }

async function load() {
  const [c, e, m] = await Promise.all([cachedGet('/courier/cash', 'cash'), cachedGet('/courier/earnings', 'earnings'), cachedGet('/courier/me', 'me')])
  cash.value = c
  earnings.value = e
  me.value = m
  seenDecision(m.remittance_decided_at)
}

// Plafond d'espèces : jauge (au-delà, la boutique peut ne plus confier de mission)
const limitShare = computed(() => (me.value?.cash_limit ? Math.min(100, Math.round((cash.value.held * 100) / me.value.cash_limit)) : null))

// Résumé de la journée, à envoyer à la boutique par WhatsApp
const summary = computed(() => {
  const t = me.value?.today
  if (!t) return ''
  return [`Ma journée du ${new Date().toLocaleDateString('fr-FR')} (${me.value.name}) :`, `✅ ${t.delivered} livrée(s) · ❌ ${t.failed} échec(s)`,
    `💵 Encaissé : ${money(t.collected)} · Versé : ${money(t.remitted)}`, `💰 Reste à verser : ${money(cash.value?.held ?? 0)}`, `🧾 Ma paie du jour : ${money(t.earned)}`].join('\n')
})
const storePhone = computed(() => (me.value?.store?.phone ?? '').replace(/\D/g, ''))
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
    <!-- Un seul gros chiffre : ce qu'il reste à verser, avec le plafond -->
    <section class="rounded-xl bg-white p-4 text-center shadow-sm">
      <p class="text-sm text-slate-500">À verser à la boutique</p>
      <p class="text-4xl font-extrabold" :class="limitShare > 100 || (me?.cash_limit && cash.held > me.cash_limit) ? 'text-amber-700' : ''">{{ money(cash.held) }}</p>
      <template v-if="limitShare !== null">
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full" :class="cash.held > me.cash_limit ? 'bg-amber-500' : 'bg-emerald-600'" :style="{ width: limitShare + '%' }" /></div>
        <p class="mt-1 text-xs" :class="cash.held > me.cash_limit ? 'font-semibold text-amber-800' : 'text-slate-500'">
          {{ cash.held > me.cash_limit ? '⚠ Plafond dépassé : versez vite à la boutique' : `Plafond : ${money(me.cash_limit)}` }}
        </p>
      </template>
      <button v-if="owed.length && !declaring" class="btn-brand mt-3 w-full py-3" :disabled="!offline.online" @click="startDeclare(); tab = 'cash'">💸 J'ai versé l'argent</button>
      <p v-if="owed.length && !offline.online" class="mt-1 text-xs text-slate-500">Il faut du réseau pour déclarer un versement.</p>
    </section>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <!-- Ma journée : à montrer ou envoyer à la boutique le soir -->
    <section v-if="me?.today" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-2 text-sm font-semibold">Ma journée</p>
      <div class="grid grid-cols-3 gap-2 text-center text-sm">
        <div class="rounded-lg bg-emerald-50 p-2"><p class="text-xl font-bold">{{ me.today.delivered }}</p><p class="text-xs text-slate-500">livrée(s)</p></div>
        <div class="rounded-lg bg-red-50 p-2"><p class="text-xl font-bold">{{ me.today.failed }}</p><p class="text-xs text-slate-500">échec(s)</p></div>
        <div class="rounded-lg bg-slate-50 p-2"><p class="text-base font-bold">{{ money(me.today.earned) }}</p><p class="text-xs text-slate-500">ma paie</p></div>
      </div>
      <p class="mt-2 text-sm text-slate-600">Encaissé : <strong>{{ money(me.today.collected) }}</strong> · Versé : <strong>{{ money(me.today.remitted) }}</strong></p>
      <a v-if="storePhone" :href="`https://wa.me/${storePhone}?text=${encodeURIComponent(summary)}`" target="_blank" rel="noopener" class="btn-secondary mt-2 w-full text-sm">💬 Envoyer mon résumé à la boutique</a>
    </section>

    <nav class="grid grid-cols-3 gap-1 rounded-xl bg-slate-200 p-1 text-sm">
      <button v-for="[key, label] in [['cash', 'À verser'], ['remittances', 'Mes versements'], ['pay', 'Ma paie']]" :key="key"
              class="rounded-lg py-2" :class="tab === key ? 'bg-white font-semibold shadow-sm' : 'text-slate-600'" @click="tab = key">{{ label }}</button>
    </nav>

    <section v-if="tab === 'cash'" class="rounded-xl bg-white p-4 shadow-sm">
      <p v-if="!cash.collections.length" class="text-sm text-slate-500">Aucune espèce à verser.</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="(c, i) in cash.collections" :key="i" class="flex justify-between py-2"><span><template v-if="c.store">{{ c.store }} · </template>{{ c.reference }} · {{ formatDateTime(c.collected_at) }}</span><span class="font-medium">{{ money(c.amount) }}</span></li>
      </ul>
    </section>

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

    <section v-if="tab === 'remittances' && cash.declarations?.length" class="rounded-xl bg-white p-4 shadow-sm">
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

    <section v-if="tab === 'pay'" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-sm text-slate-500">Ma paie à recevoir</p>
      <p class="text-2xl font-bold text-emerald-700">{{ money(earnings.unpaid) }}</p>
      <ul class="mt-2 divide-y divide-slate-100 text-sm">
        <li v-for="(l, i) in earnings.lines" :key="i" class="flex justify-between py-1.5">
          <span>{{ l.type_label }}<template v-if="l.reference"> · {{ l.reference }}</template><template v-if="l.store"> · {{ l.store }}</template></span>
          <span :class="{ 'text-red-600': l.amount < 0 }">{{ money(l.amount) }}</span>
        </li>
      </ul>
    </section>
    <section v-if="tab === 'remittances'" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-1 text-sm font-semibold">Versements reçus par la boutique</p>
      <p v-if="!cash.remittances.length" class="text-sm text-slate-500">Aucun versement enregistré pour l'instant.</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="(r, i) in cash.remittances" :key="i" class="flex justify-between py-1.5">
          <span>{{ formatDateTime(r.received_at) }}</span>
          <span>{{ money(r.received_amount) }}<span v-if="r.difference" class="ml-1 text-amber-700">({{ r.difference > 0 ? '+' : '' }}{{ money(r.difference) }})</span></span>
        </li>
      </ul>
    </section>
  </div>
</template>
