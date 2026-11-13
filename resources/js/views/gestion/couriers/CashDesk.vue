<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import CashTabs from '../../../components/CashTabs.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime, money } from '../../../lib/format'

const data = ref(null)
const error = ref('')
const current = ref(null)
const collections = ref([])
const form = reactive({ received_amount: 0, method: 'cash', note: '' })
const result = ref(null)

async function load() {
  try {
    data.value = (await http.get('/cash')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

// Du plus urgent au moins urgent : plafond dépassé, puis le plus ancien ; ceux qui n'ont rien à verser à la fin
const rows = computed(() => {
  const list = [...(data.value?.data ?? [])]
  const rank = (r) => (!r.held ? 3 : r.over_limit ? 0 : 1)
  return list.sort((a, b) => rank(a) - rank(b) || String(a.oldest_at ?? '9').localeCompare(String(b.oldest_at ?? '9')) || b.held - a.held)
})
const totalHeld = computed(() => rows.value.reduce((sum, r) => sum + r.held, 0))
const toCollectCount = computed(() => rows.value.filter((r) => r.held > 0).length)
const overLimitCount = computed(() => rows.value.filter((r) => r.over_limit).length)

async function open(row) {
  Object.assign(form, { received_amount: row.held, method: 'cash', note: '' })
  collections.value = []
  current.value = row
  result.value = null
  error.value = ''
  collections.value = (await http.get(`/cash/couriers/${row.courier_id}`)).data.data
}

// Versements déclarés par les livreurs
const deciding = ref(null) // { declaration, mode: 'confirm' | 'reject' }
const decision = reactive({ received_amount: 0, reason: '' })
function decide(declaration, mode) {
  Object.assign(decision, { received_amount: declaration.amount, reason: '' })
  error.value = ''
  result.value = null
  deciding.value = { declaration, mode }
}
async function submitDecision() {
  const { declaration, mode } = deciding.value
  try {
    if (mode === 'confirm') result.value = (await http.post(`/cash/declarations/${declaration.id}/confirm`, { received_amount: decision.received_amount })).data.data
    else await http.post(`/cash/declarations/${declaration.id}/reject`, { reason: decision.reason })
    if (mode === 'reject') deciding.value = null
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}

async function remit() {
  try {
    result.value = (await http.post(`/cash/couriers/${current.value.courier_id}/remittances`, form)).data.data
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <CashTabs />
    <section class="card p-4">
      <p class="text-sm font-medium text-slate-600">À récupérer chez vos livreurs (espèces)</p>
      <p class="whitespace-nowrap text-3xl font-bold tracking-tight">{{ money(totalHeld) }}</p>
      <p class="mt-1 text-sm text-slate-600">
        {{ toCollectCount }} livreur{{ toCollectCount > 1 ? 's ont' : ' a' }} des espèces à verser. Le paiement mobile money du client va directement sur votre compte marchand.
      </p>
      <div v-if="overLimitCount || data?.declarations?.length" class="mt-2.5 flex flex-wrap gap-1.5">
        <span v-if="overLimitCount" class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800">{{ overLimitCount }} plafond(s) dépassé(s)</span>
        <span v-if="data?.declarations?.length" class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-900">{{ data.declarations.length }} versement(s) à confirmer</span>
      </div>
    </section>
    <Alert v-if="error && !current && !deciding">{{ error }}</Alert>

    <section v-if="data?.declarations?.length" class="card border-amber-300 p-4 text-sm">
      <h3 class="mb-1 text-base font-semibold">À confirmer</h3>
      <p class="mb-2 text-slate-600">Vos livreurs disent avoir versé cet argent. Vérifiez votre compte ou le tiroir, puis confirmez le montant réellement reçu.</p>
      <ul class="divide-y divide-slate-100">
        <li v-for="d in data.declarations" :key="d.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ d.courier }} · {{ money(d.amount) }} <span class="font-normal text-slate-500">· {{ d.method_label }}</span></span>
            <span class="block text-slate-500">
              {{ formatDateTime(d.created_at) }}<template v-if="d.reference"> · réf. <strong>{{ d.reference }}</strong></template> · il avait {{ money(d.held) }} à verser
              <template v-if="d.note"> · « {{ d.note }} »</template>
              <a v-if="d.proof_url" :href="d.proof_url" target="_blank" class="text-emerald-700 hover:underline"> · voir le reçu</a>
            </span>
          </span>
          <button class="btn-brand px-3 py-1.5" @click="decide(d, 'confirm')">Confirmer</button>
          <button class="btn-ghost px-3 py-1.5 text-red-600" @click="decide(d, 'reject')">Refuser</button>
        </li>
      </ul>
    </section>

    <section v-if="data" class="space-y-2.5">
      <div>
        <h3 class="text-lg font-semibold">Livreurs</h3>
        <p class="text-sm text-slate-600">Du plus urgent au moins urgent.</p>
      </div>
      <article
        v-for="row in rows"
        :key="row.courier_id"
        class="card flex items-center gap-3 p-3.5 text-sm"
        :class="row.over_limit ? 'border-red-300' : !row.held ? 'bg-slate-100' : ''"
      >
        <span class="min-w-0 flex-1">
          <span class="block font-semibold" :class="row.held ? '' : 'text-slate-600'">{{ row.name }}</span>
          <span class="block text-slate-600">
            <template v-if="row.held">{{ row.collections_count }} livraison(s)<template v-if="row.oldest_at"> depuis le {{ formatDateTime(row.oldest_at) }}</template></template>
            <template v-else>Rien à verser</template>
          </span>
          <span v-if="row.over_limit" class="mt-1 inline-block rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800">Plafond dépassé</span>
        </span>
        <span class="shrink-0 text-right text-lg font-bold" :class="row.held ? '' : 'text-slate-600'">{{ money(row.held) }}</span>
        <button class="px-4 py-2.5" :class="row.over_limit ? 'btn-brand' : 'btn-secondary'" :disabled="!row.held" @click="open(row)">Recevoir</button>
      </article>
      <p v-if="!rows.length" class="card px-4 py-6 text-sm text-slate-600">Aucun livreur.</p>
    </section>

    <section v-if="data?.remittances.length" class="card p-4 text-sm">
      <h3 class="mb-2 text-base font-semibold">Derniers versements reçus</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="r in data.remittances" :key="r.id" class="flex flex-wrap items-center gap-3 py-2">
          <span class="flex-1">{{ r.courier }} · {{ formatDateTime(r.received_at) }} · reçu par {{ r.received_by }}</span>
          <span>{{ money(r.received_amount) }} / {{ money(r.expected_amount) }}</span>
          <span v-if="r.difference" class="rounded px-2 py-0.5 text-xs" :class="r.difference < 0 ? 'bg-red-100 text-red-800' : 'bg-sky-100 text-sky-800'">
            {{ r.difference < 0 ? 'Manque' : 'Excédent' }} {{ money(Math.abs(r.difference)) }}
          </span>
        </li>
      </ul>
    </section>

    <Modal v-if="deciding" :title="deciding.mode === 'confirm' ? `Versement de ${deciding.declaration.courier}` : 'Refuser le versement'" @close="deciding = null">
      <div v-if="result" class="space-y-3 text-sm">
        <Alert :tone="result.difference ? 'warning' : 'success'">
          Reçu {{ money(result.received_amount) }} pour {{ money(result.expected_amount) }} attendus.
          <template v-if="result.difference"> {{ result.difference < 0 ? 'Manque' : 'Excédent' }} : {{ money(Math.abs(result.difference)) }}.</template>
          Le livreur est prévenu.
        </Alert>
        <button class="btn-brand w-full" @click="deciding = null">Fermer</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="submitDecision">
        <Alert v-if="error">{{ error }}</Alert>
        <template v-if="deciding.mode === 'confirm'">
          <p class="text-sm">Déclaré : <strong>{{ money(deciding.declaration.amount) }}</strong> ({{ deciding.declaration.method_label }}) · il avait {{ money(deciding.declaration.held) }} à verser</p>
          <Field id="dc-amount" label="Montant réellement reçu"><input id="dc-amount" v-model.number="decision.received_amount" type="number" min="0" class="input text-lg" required /></Field>
          <button class="btn-brand w-full">Confirmer la réception</button>
        </template>
        <template v-else>
          <Field id="dc-reason" label="Motif (envoyé au livreur)"><input id="dc-reason" v-model="decision.reason" class="input" maxlength="255" placeholder="Ex. : rien reçu sur Wave" required /></Field>
          <button class="btn-danger w-full">Refuser</button>
        </template>
      </form>
    </Modal>

    <Modal v-if="current" :title="`Versement de ${current.name}`" @close="current = null">
      <div v-if="result" class="space-y-3 text-sm">
        <Alert :tone="result.difference ? 'warning' : 'success'">
          Reçu {{ money(result.received_amount) }} pour {{ money(result.expected_amount) }} attendus.
          <template v-if="result.difference"> {{ result.difference < 0 ? 'Manque' : 'Excédent' }} : {{ money(Math.abs(result.difference)) }}.</template>
        </Alert>
        <button class="btn-brand w-full" @click="current = null">Fermer</button>
      </div>
      <form v-else class="space-y-4" @submit.prevent="remit">
        <Alert v-if="error">{{ error }}</Alert>
        <ul class="max-h-48 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200 text-sm">
          <li v-for="c in collections" :key="c.id" class="flex justify-between px-3 py-1.5"><span>{{ c.reference }} · {{ c.customer }}</span><span>{{ money(c.amount) }}</span></li>
        </ul>
        <p class="text-sm">Attendu : <strong>{{ money(current.held) }}</strong></p>
        <Field id="rm-amount" label="Montant reçu"><input id="rm-amount" v-model.number="form.received_amount" type="number" min="0" class="input text-lg" required /></Field>
        <Field id="rm-method" label="Reçu en">
          <select id="rm-method" v-model="form.method" class="input"><option value="cash">Espèces</option><option value="wave">Wave</option><option value="orange_money">Orange Money</option><option value="mtn_momo">MTN MoMo</option><option value="moov_money">Moov Money</option></select>
        </Field>
        <Field id="rm-note" label="Note"><input id="rm-note" v-model="form.note" class="input" maxlength="255" /></Field>
        <button class="btn-brand w-full">Enregistrer le versement</button>
      </form>
    </Modal>
  </div>
</template>
