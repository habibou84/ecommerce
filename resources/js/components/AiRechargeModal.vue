<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import Modal from './Modal.vue'
import WavePay from './WavePay.vue'
import { aiRecharge } from '../lib/aiQuota'
import { money } from '../lib/format'
import http, { errorMessage } from '../lib/http'
import { useAuthStore } from '../stores/auth'

/**
 * Fenêtre de recharge de l'assistant IA, ouverte depuis n'importe quel écran : le message en rouge, puis le même
 * achat que dans Réglages › Abonnement (moyen de paiement, Wave avec « J'ai payé »). Sans le droit de payer :
 * le message et qui peut recharger.
 */
const auth = useAuthStore()
const canBuy = computed(() => auth.can('billing.manage'))
const ai = computed(() => auth.profile?.ai ?? null)
const billing = ref(null)
const choice = reactive({ method: 'wave', payer_phone: '' })
const busy = ref(false)
const claiming = ref(false)
const error = ref('')
const wave = ref(null)
const done = ref('')

onMounted(async () => {
  if (!canBuy.value) return
  try {
    billing.value = (await http.get('/billing')).data.data
    choice.method = Object.keys(billing.value.methods)[0] ?? 'wave'
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const pack = computed(() => billing.value?.ai_pack ?? ai.value?.pack ?? { actions: 100, price: 1000 })
const message = computed(() => aiRecharge.message || (ai.value?.limit
  ? `Vos ${ai.value.limit} actions de l'assistant IA du mois sont utilisées.`
  : 'Plus d\'actions de l\'assistant IA.'))

async function buy() {
  if (billing.value.manual_payment && !choice.payer_phone.trim()) {
    error.value = 'Indiquez le numéro Wave qui va payer.'
    return
  }
  busy.value = true
  error.value = ''
  try {
    const { data: res } = await http.post('/billing/ai-pack', { method: choice.method, payer_phone: choice.payer_phone })
    if (!billing.value.manual_payment) {
      window.location.assign(res.redirect)
      return
    }
    wave.value = { invoice: res.data, link: res.redirect, phone: choice.payer_phone }
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function claim() {
  claiming.value = true
  error.value = ''
  try {
    await http.post(`/billing/invoices/${wave.value.invoice.number}/paid`)
    done.value = `Merci ! Nous vérifions votre paiement de ${money(wave.value.invoice.amount)}. Dès qu'il est confirmé, les ${pack.value.actions} actions sont ajoutées et vous êtes prévenu.`
    wave.value = null
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    claiming.value = false
  }
}

function close() {
  aiRecharge.open = false
  aiRecharge.message = ''
  auth.fetch().catch(() => {})
}
</script>

<template>
  <Modal :title="wave ? 'Payer avec Wave' : 'Recharger l\'assistant IA'" @close="close">
    <WavePay v-if="wave" :invoice="wave.invoice" :link="wave.link" :phone="wave.phone" :qr-url="billing?.wave_qr_url" :claiming="claiming" @claim="claim" />
    <div v-else class="space-y-4 text-sm">
      <Alert v-if="done" tone="success">{{ done }}</Alert>
      <template v-else>
        <p class="rounded-xl border border-red-200 bg-red-50 p-3 font-semibold text-red-800">{{ message }}</p>
        <Alert v-if="error">{{ error }}</Alert>

        <template v-if="canBuy">
          <div class="rounded-xl bg-emerald-50 p-4 text-center ring-1 ring-emerald-200">
            <p class="text-2xl font-extrabold text-emerald-900">{{ pack.actions }} actions</p>
            <p class="text-lg font-bold">{{ money(pack.price) }}</p>
            <p class="mt-1 text-xs text-slate-600">Utilisées après celles de votre offre. Elles ne périment pas.</p>
          </div>
          <template v-if="billing">
            <label v-if="billing.manual_payment" class="block text-sm">Numéro Wave qui va payer
              <input v-model="choice.payer_phone" type="tel" class="input mt-1" placeholder="07 00 00 00 00" />
            </label>
            <select v-else v-model="choice.method" class="input" aria-label="Moyen de paiement de la recharge">
              <option v-for="(label, key) in billing.methods" :key="key" :value="key">{{ label }}</option>
            </select>
            <button type="button" class="btn-brand w-full py-3 text-base" :disabled="busy" @click="buy">{{ busy ? 'Un instant…' : `Recharger pour ${money(pack.price)}` }}</button>
          </template>
          <p v-else-if="!error" class="text-center text-slate-500">Chargement…</p>
        </template>
        <p v-else class="rounded-xl bg-slate-50 p-3 text-slate-700">Demandez au propriétaire ou au gérant de la boutique de recharger l'assistant (Réglages › Abonnement).</p>
      </template>
    </div>
  </Modal>
</template>
