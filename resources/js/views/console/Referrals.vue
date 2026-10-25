<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

// Parrainage par les entreprises de livraison : part de l'abonnement des boutiques qu'elles ont invitées, et
// versements. Chaque entreprise suit aussi ses chiffres dans Jibiat (Réglages › E-commerçants).
const data = ref(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const form = reactive({ rate: 20, months: 12 })
const paying = ref(null)
const payForm = reactive({ method: 'wave', reference: '', note: '' })

const STATUS = { trialing: 'Essai', active: 'Abonné', past_due: 'En retard', cancelled: 'Résilié', expired: 'Expiré', none: 'Sans abonnement' }

function apply(d) {
  data.value = d
  form.rate = Math.round(d.settings.rate * 1000) / 10
  form.months = d.settings.months
}

async function load() {
  try {
    apply((await http.get('/console/referrals')).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function saveSettings() {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    apply((await http.put('/console/referrals/settings', { rate: Number(form.rate) / 100, months: Number(form.months) })).data.data)
    notice.value = 'Réglages enregistrés : ils comptent pour les prochains paiements.'
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

function startPay(p) {
  paying.value = p
  Object.assign(payForm, { method: 'wave', reference: '', note: '' })
}

async function pay() {
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const name = paying.value.name
    const amount = paying.value.due
    apply((await http.post(`/console/referrals/${paying.value.partner}/pay`, payForm)).data.data)
    notice.value = `Versement de ${money(amount)} à ${name} enregistré.`
    paying.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <Alert v-if="notice" tone="success">{{ notice }}</Alert>

    <section class="card space-y-3 p-4 text-sm">
      <h2 class="text-base font-semibold">Part des entreprises de livraison</h2>
      <p class="text-slate-600">
        Une boutique créée avec le lien d'une entreprise de l'annuaire lui rapporte une part de chaque abonnement payé, pendant la durée
        choisie à partir du premier paiement. Le prix payé par le marchand ne change pas.
      </p>
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="saveSettings">
        <label class="block"><span class="label">Part (%)</span><input v-model="form.rate" type="number" min="0" max="50" step="0.5" class="input w-28" required /></label>
        <label class="block"><span class="label">Durée (mois)</span><input v-model="form.months" type="number" min="1" max="60" class="input w-28" required /></label>
        <button class="btn-brand" :disabled="busy">Enregistrer</button>
      </form>
    </section>

    <p v-if="data && !data.partners.length" class="card p-6 text-sm text-slate-500">Aucune boutique parrainée pour l'instant.</p>

    <article v-for="p in data?.partners ?? []" :key="p.partner" class="card space-y-3 p-4 text-sm">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="text-base font-semibold">{{ p.name }}</h3>
        <a v-if="p.url" :href="p.url" target="_blank" rel="noopener" class="text-slate-500 underline">{{ p.url.replace('https://', '') }}</a>
        <span v-if="p.phone" class="text-slate-500">· {{ p.phone }}</span>
      </div>
      <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded-lg bg-slate-50 p-2"><span class="block text-xs text-slate-500">Boutiques</span><strong>{{ p.stores.length }}</strong> <span class="text-xs text-slate-500">dont {{ p.paying }} abonnées</span></div>
        <div class="rounded-lg bg-slate-50 p-2"><span class="block text-xs text-slate-500">Gagné ce mois</span><strong>{{ money(p.earned_month) }}</strong></div>
        <div class="rounded-lg bg-slate-50 p-2"><span class="block text-xs text-slate-500">Déjà versé</span><strong>{{ money(p.paid_total) }}</strong></div>
        <div class="rounded-lg p-2" :class="p.due ? 'bg-amber-50' : 'bg-slate-50'"><span class="block text-xs text-slate-500">À verser</span><strong>{{ money(p.due) }}</strong></div>
      </div>
      <ul class="divide-y divide-slate-100">
        <li v-for="s in p.stores" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 py-1.5">
          <router-link :to="`/console/boutiques/${s.id}`" class="font-medium hover:underline">{{ s.name }}</router-link>
          <span class="text-slate-500">{{ STATUS[s.status] ?? s.status }} · {{ money(s.earned) }} pour l'entreprise</span>
        </li>
      </ul>
      <div v-if="p.payouts.length" class="text-xs text-slate-500">
        Derniers versements :
        <span v-for="(v, i) in p.payouts" :key="v.id">{{ i ? ' · ' : '' }}{{ money(v.amount) }} le {{ formatDateTime(v.paid_at) }}<template v-if="v.method"> ({{ v.method }})</template></span>
      </div>
      <form v-if="paying?.partner === p.partner" class="flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3" @submit.prevent="pay">
        <label class="block"><span class="label">Moyen</span>
          <select v-model="payForm.method" class="input w-auto"><option value="wave">Wave</option><option value="orange_money">Orange Money</option><option value="mtn_money">MTN MoMo</option><option value="virement">Virement</option><option value="especes">Espèces</option></select>
        </label>
        <label class="block flex-1"><span class="label">Référence (facultatif)</span><input v-model="payForm.reference" class="input" maxlength="120" /></label>
        <button class="btn-brand" :disabled="busy">Confirmer le versement de {{ money(p.due) }}</button>
        <button type="button" class="btn-ghost" @click="paying = null">Annuler</button>
      </form>
      <button v-else-if="p.due > 0" class="btn-secondary" @click="startPay(p)">Marquer {{ money(p.due) }} comme versés</button>
    </article>
  </div>
</template>
