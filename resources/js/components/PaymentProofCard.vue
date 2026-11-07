<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

/**
 * Reçu de paiement envoyé par la cliente sur WhatsApp (expédition payée avant l'envoi) : la photo en grand, le
 * montant attendu, puis « Paiement reçu » ou « Je ne le vois pas ».
 */
const props = defineProps({
  order: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])

const image = ref(null)
const missing = ref(false)
const big = ref(false)
const busy = ref(false)
const error = ref('')

async function loadImage() {
  missing.value = false
  try {
    const { data } = await http.get(`/orders/${props.order.id}/payment-proof`, { responseType: 'blob' })
    if (image.value) URL.revokeObjectURL(image.value)
    image.value = URL.createObjectURL(data)
  } catch {
    missing.value = true
  }
}
watch(() => props.order.expedition?.payment_proof_at, (at) => at && loadImage(), { immediate: true })
onBeforeUnmount(() => image.value && URL.revokeObjectURL(image.value))

async function run(call) {
  busy.value = true
  error.value = ''
  try {
    await call()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
// Reçu vérifié dans Wave : le paiement est enregistré, la cliente est prévenue et reçoit ses photos
const accept = () => run(() => http.post(`/orders/${props.order.id}/payments`, { amount: props.order.expedition.prepay_due, method: 'wave', reference: 'Reçu WhatsApp' }))
const reject = () => window.confirm('Le paiement n\'est pas dans votre Wave ? La cliente sera invitée à vérifier et à renvoyer son reçu.')
  && run(() => http.post(`/orders/${props.order.id}/payment-proof/reject`))
</script>

<template>
  <section class="space-y-3 rounded-2xl bg-emerald-50 p-4 ring-[3px] ring-emerald-400">
    <p class="text-[17px] font-black text-emerald-950">📸 {{ order.customer_name || 'La cliente' }} a envoyé son reçu</p>
    <button v-if="image" type="button" class="block w-full overflow-hidden rounded-xl bg-white" @click="big = true">
      <img :src="image" alt="Reçu de paiement" class="mx-auto max-h-52 object-contain" />
      <span class="block py-1 text-center text-xs font-bold text-sky-700">Toucher pour agrandir</span>
    </button>
    <p v-else-if="missing" class="rounded-xl bg-white p-3 text-sm text-slate-600">
      La photo n'a pas pu être chargée.
      <RouterLink v-if="order.expedition.payment_proof_conversation_id" :to="`/gestion/messages/${order.expedition.payment_proof_conversation_id}`" class="font-bold underline">Voir la conversation</RouterLink>
    </p>
    <div class="grid grid-cols-2 gap-2 text-xs text-slate-600">
      <div class="rounded-xl bg-white p-2.5">Attendu<b class="block text-xl text-slate-900">{{ money(order.expedition.prepay_due) }}</b></div>
      <div class="rounded-xl bg-white p-2.5">À vérifier dans<b class="block text-xl text-slate-900">votre Wave</b></div>
    </div>
    <p v-if="error" class="text-sm font-bold text-red-700">{{ error }}</p>
    <template v-if="canManage">
      <button type="button" class="block w-full rounded-2xl bg-emerald-700 py-3.5 text-[17px] font-black text-white disabled:opacity-60" :disabled="busy" @click="accept">✓ Paiement reçu</button>
      <button type="button" class="block w-full rounded-2xl border-2 border-red-200 bg-white py-3 text-[15px] font-black text-red-700 disabled:opacity-60" :disabled="busy" @click="reject">✗ Je ne le vois pas</button>
    </template>

    <div v-if="big" class="fixed inset-0 z-[60] grid place-items-center bg-slate-950/90 p-3" role="dialog" aria-modal="true" aria-label="Reçu de paiement" @click="big = false">
      <img :src="image" alt="Reçu de paiement" class="max-h-full max-w-full rounded-xl" />
    </div>
  </section>
</template>
