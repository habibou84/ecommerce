<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import http, { errorMessage } from '../../lib/http'
import { formatDateTime, money } from '../../lib/format'

const props = defineProps({ id: { type: String, required: true } })
const store = ref(null)
const error = ref('')
const modal = ref(null) // suspend | trial | offer | support
const form = reactive({ reason: '', days: 14, plan: 'pro', months: 1, minutes: 30 })
const PLAN_NAMES = { starter: 'Starter', pro: 'Pro', business: 'Business' }
const busy = ref(false)
const ACTIONS = { suspend: 'Suspension', reactivate: 'Réactivation', extend_trial: 'Essai prolongé', offer: 'Mois offerts', support_session: 'Session de support', free_forever: 'Gratuit à vie', free_forever_removed: 'Gratuit à vie retiré', whatsapp_qr_on: 'WhatsApp par QR code autorisé', whatsapp_qr_off: 'WhatsApp par QR code coupé' }
const QR_STATUS = { none: 'pas relié', pending: 'en attente du téléphone', connected: 'relié', disconnected: 'déconnecté' }

// Liaison WhatsApp par QR code (non officielle) : autorisée boutique par boutique ; coupée, la liaison en cours est déconnectée
async function toggleQr() {
  const enabled = !store.value.whatsapp_qr.enabled
  if (!enabled && store.value.whatsapp_qr.status !== 'none'
    && !window.confirm('Couper le WhatsApp par QR code ? Le téléphone relié de cette boutique sera déconnecté.')) return
  busy.value = true
  error.value = ''
  try {
    store.value = (await http.put(`/console/stores/${props.id}/whatsapp-qr`, { enabled })).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function removeFree() {
  if (window.confirm('Retirer « gratuit à vie » ? L\'espace marchand revient à son abonnement (ou à la gratuité pour tous si elle est en cours).')) await act('free', { plan: null })
}

async function load() {
  try {
    store.value = (await http.get(`/console/stores/${props.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function open(name) {
  Object.assign(form, { reason: '', days: 14, plan: 'pro', months: 1, minutes: 30 })
  error.value = ''
  modal.value = name
}

async function act(path, payload = {}) {
  busy.value = true
  error.value = ''
  try {
    const { data } = await http.post(`/console/stores/${props.id}/${path}`, payload)
    if (data.redirect) {
      window.open(data.redirect, '_blank', 'noopener')
      await load()
    } else {
      store.value = data.data
    }
    modal.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function endSession(id) {
  await http.post(`/console/support-sessions/${id}/end`)
  await load()
}
</script>

<template>
  <div v-if="store" class="space-y-5">
    <RouterLink to="/console/boutiques" class="text-sm text-slate-500 hover:text-emerald-700">← Espaces marchands</RouterLink>
    <div class="flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-semibold">{{ store.name }}</h1>
      <span class="rounded bg-slate-100 px-2 py-0.5 text-sm">{{ store.status_label }}</span>
      <a :href="store.url" target="_blank" rel="noopener" class="text-sm text-emerald-700 hover:underline">{{ store.url.replace(/^https?:\/\//, '') }} ↗</a>
    </div>
    <Alert v-if="store.suspended_reason" tone="warning">Suspendue : {{ store.suspended_reason }}</Alert>
    <Alert v-if="error && !modal">{{ error }}</Alert>

    <div class="flex flex-wrap gap-2">
      <button class="btn-brand" @click="open('support')">🛟 Session de support</button>
      <button class="btn-secondary" @click="open('trial')">Prolonger l'essai</button>
      <button class="btn-secondary" @click="open('offer')">Offrir des mois</button>
      <button v-if="!store.free_forever" class="btn-secondary" @click="open('free')">Gratuit à vie</button>
      <button v-else class="btn-ghost" :disabled="busy" @click="removeFree">Retirer « gratuit à vie »</button>
      <button v-if="store.status !== 'suspended'" class="btn-ghost text-red-600" @click="open('suspend')">Suspendre</button>
      <button v-else class="btn-secondary" :disabled="busy" @click="act('reactivate')">Réactiver</button>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
      <section class="card space-y-1 p-4 text-sm">
        <h3 class="mb-1 font-semibold">Propriétaire</h3>
        <p>{{ store.owner.name }}</p>
        <p class="text-slate-600">{{ store.owner.phone }}<template v-if="store.owner.email"> · {{ store.owner.email }}</template></p>
        <p class="text-slate-500">{{ store.members }} membre(s) · créée le {{ formatDateTime(store.created_at) }}</p>
        <p class="pt-2">{{ store.orders_30d }} commandes et {{ money(store.revenue_30d) }} livrés sur 30 jours</p>
      </section>
      <section class="card space-y-1 p-4 text-sm">
        <h3 class="mb-1 font-semibold">Abonnement : {{ store.plan }}</h3>
        <p v-if="store.free" class="rounded-lg bg-emerald-50 px-2 py-1 font-medium text-emerald-800">
          🎁 {{ store.free.forever ? `Gratuit à vie (offre ${store.free.plan})` : `Gratuité pour tous (offre ${store.free.plan})` }}
        </p>
        <template v-if="store.subscription">
          <p v-if="store.subscription.status === 'trialing'">Essai jusqu'au {{ formatDateTime(store.subscription.trial_ends_at) }}</p>
          <p v-else>{{ store.subscription.status === 'past_due' ? 'En retard depuis' : 'Jusqu\'au' }} {{ formatDateTime(store.subscription.current_period_end) }} ({{ store.subscription.billing_cycle === 'yearly' ? 'annuel' : 'mensuel' }})</p>
          <p v-if="store.subscription.cancel_at_period_end" class="text-amber-800">Fin demandée à l'échéance</p>
        </template>
        <p v-else class="text-slate-500">Offre gratuite</p>
      </section>
      <section class="card space-y-2 p-4 text-sm">
        <h3 class="font-semibold">📱 WhatsApp par QR code</h3>
        <p class="text-slate-600">Liaison non officielle (comme WhatsApp Web) pour la boîte Messages et les lives. Coupée : la boutique ne voit que « Connecter WhatsApp avec Facebook ».</p>
        <p>{{ store.whatsapp_qr.enabled ? 'Autorisé' : 'Coupé' }} · {{ QR_STATUS[store.whatsapp_qr.status] ?? store.whatsapp_qr.status }}</p>
        <button type="button" :class="store.whatsapp_qr.enabled ? 'btn-ghost text-red-600' : 'btn-ghost'" :disabled="busy" @click="toggleQr">
          {{ store.whatsapp_qr.enabled ? 'Couper' : 'Autoriser' }}
        </button>
      </section>
      <section class="card space-y-1 p-4 text-sm">
        <h3 class="mb-1 font-semibold">Utilisation</h3>
        <p v-for="(u, key) in store.usage" :key="key" class="flex justify-between"><span>{{ u.label }}</span><span>{{ u.used }}{{ u.limit !== null ? ` / ${u.limit}` : '' }}</span></p>
      </section>
    </div>

    <div class="grid gap-5 lg:grid-cols-2">
      <section class="card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Sessions de support</h3>
        <ul class="divide-y divide-slate-100">
          <li v-for="s in store.support_sessions" :key="s.id" class="flex items-center gap-2 py-2">
            <span class="flex-1">{{ s.admin }} · {{ s.reason }}<span class="block text-xs text-slate-500">{{ formatDateTime(s.started_at) }} → {{ formatDateTime(s.ended_at ?? s.expires_at) }}</span></span>
            <button v-if="s.ongoing" class="btn-ghost text-red-600" @click="endSession(s.id)">Terminer</button>
          </li>
          <li v-if="!store.support_sessions.length" class="py-2 text-slate-500">Aucune.</li>
        </ul>
      </section>
      <section class="card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Factures</h3>
        <ul class="divide-y divide-slate-100">
          <li v-for="i in store.invoices" :key="i.id" class="flex justify-between py-2"><span>{{ i.number }} · {{ i.plan }} ({{ i.cycle_label }})<span class="block text-xs text-slate-500">{{ i.status_label }} · {{ formatDateTime(i.paid_at ?? i.created_at) }}</span></span><span>{{ money(i.amount) }}</span></li>
          <li v-if="!store.invoices.length" class="py-2 text-slate-500">Aucune.</li>
        </ul>
      </section>
    </div>

    <section class="card p-4 text-sm">
      <h3 class="mb-2 font-semibold">Journal de la console</h3>
      <ul class="divide-y divide-slate-100">
        <li v-for="(a, i) in store.actions" :key="i" class="py-1.5">{{ ACTIONS[a.action] ?? a.action }} par {{ a.admin }} · {{ formatDateTime(a.created_at) }}<span v-if="a.data?.reason" class="text-slate-500"> — {{ a.data.reason }}</span></li>
        <li v-if="!store.actions.length" class="py-2 text-slate-500">Aucune action.</li>
      </ul>
    </section>

    <Modal v-if="modal === 'support'" title="Session de support" @close="modal = null">
      <form class="space-y-4" @submit.prevent="act('support', { reason: form.reason, minutes: Number(form.minutes) })">
        <Alert v-if="error">{{ error }}</Alert>
        <p class="text-sm text-slate-600">Vous entrez dans l'espace marchand avec le rôle « support » (sans l'abonnement, l'équipe ni les comptes de paiement). Le propriétaire est prévenu et peut mettre fin à l'accès.</p>
        <Field id="sup-reason" label="Motif (visible dans l'espace marchand)"><input id="sup-reason" v-model="form.reason" class="input" minlength="5" maxlength="255" required placeholder="ex. Aide à la configuration des zones" /></Field>
        <Field id="sup-minutes" label="Durée (minutes)"><input id="sup-minutes" v-model="form.minutes" type="number" min="5" max="240" class="input w-32" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Ouvrir l'espace marchand</button>
      </form>
    </Modal>
    <Modal v-if="modal === 'trial'" title="Prolonger l'essai" @close="modal = null">
      <form class="space-y-4" @submit.prevent="act('trial', { days: Number(form.days) })">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="trial-days" label="Jours supplémentaires"><input id="trial-days" v-model="form.days" type="number" min="1" max="90" class="input w-32" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Prolonger</button>
      </form>
    </Modal>
    <Modal v-if="modal === 'offer'" title="Offrir des mois d'abonnement" @close="modal = null">
      <form class="space-y-4" @submit.prevent="act('offer', { plan: form.plan, months: Number(form.months) })">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="offer-plan" label="Offre"><select id="offer-plan" v-model="form.plan" class="input"><option value="starter">Starter</option><option value="pro">Pro</option><option value="business">Business</option></select></Field>
        <Field id="offer-months" label="Mois"><input id="offer-months" v-model="form.months" type="number" min="1" max="24" class="input w-32" /></Field>
        <button class="btn-brand w-full" :disabled="busy">Offrir</button>
      </form>
    </Modal>
    <Modal v-if="modal === 'free'" title="Gratuit à vie" @close="modal = null">
      <form class="space-y-4" @submit.prevent="act('free', { plan: form.plan })">
        <Alert v-if="error">{{ error }}</Alert>
        <p class="text-sm text-slate-600">L'espace marchand a cette offre sans payer et sans date de fin, même quand la gratuité pour tous est arrêtée. Les limites de l'offre s'appliquent.</p>
        <Field id="free-plan" label="Offre"><select id="free-plan" v-model="form.plan" class="input"><option v-for="(name, code) in PLAN_NAMES" :key="code" :value="code">{{ name }}</option></select></Field>
        <button class="btn-brand w-full" :disabled="busy">Offrir à vie</button>
      </form>
    </Modal>
    <Modal v-if="modal === 'suspend'" title="Suspendre l'espace marchand" @close="modal = null">
      <form class="space-y-4" @submit.prevent="act('suspend', { reason: form.reason })">
        <Alert v-if="error">{{ error }}</Alert>
        <p class="text-sm text-slate-600">Le site et la gestion deviennent inaccessibles ; les données sont conservées.</p>
        <Field id="susp-reason" label="Motif"><input id="susp-reason" v-model="form.reason" class="input" maxlength="255" required /></Field>
        <button class="btn-danger w-full" :disabled="busy">Suspendre</button>
      </form>
    </Modal>
  </div>
  <Alert v-else-if="error">{{ error }}</Alert>
</template>
