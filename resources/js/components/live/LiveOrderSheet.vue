<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money, phoneDisplay } from '../../lib/format'
import { useAuthStore } from '../../stores/auth'

/**
 * Une ligne de l'onglet Commandes du live, ouverte sans quitter le live : la commande (client, articles, total,
 * adresse, état) et l'action utile en premier ; une pièce gardée sans commande : choisir le quartier ou annuler.
 */
const props = defineProps({
  reservation: { type: Object, required: true },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'place', 'cancel', 'changed'])
const auth = useAuthStore()

const order = ref(null)
const loading = ref(false)
const working = ref(false)
const error = ref('')
const r = computed(() => props.reservation)
const name = computed(() => r.value.customer_name || phoneDisplay(r.value.customer_phone) || 'Cliente')
const phone = computed(() => order.value?.customer_phone || r.value.customer_phone || '')
const waLink = computed(() => phone.value ? `https://wa.me/${phone.value.replace(/\D/g, '')}` : null)

async function load() {
  if (!r.value.order) return
  loading.value = true
  try {
    order.value = (await http.get(`/orders/${r.value.order.id}`)).data.data
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Client joint qui confirme : la commande passe « confirmée » sans quitter le live
async function confirmOrder() {
  working.value = true
  error.value = ''
  try {
    await http.post(`/orders/${order.value.id}/confirmations`, { outcome: 'confirmed', channel: 'call' })
    await load()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    working.value = false
  }
}
const proof = computed(() => order.value?.upfront?.payment_proof_at && order.value.upfront.due > 0)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="max-h-[88vh] w-full overflow-y-auto rounded-t-[24px] bg-white px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))] text-slate-900" role="dialog" aria-modal="true" :aria-label="`Commande de ${name}`">
      <div class="mx-auto mb-2 h-1.5 w-10 rounded-full bg-slate-300" />
      <div class="flex items-center gap-2">
        <b class="min-w-0 flex-1 truncate text-[17px]">{{ name }}<template v-if="r.order"> · {{ r.order.reference }}</template></b>
        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>

      <!-- Commande créée -->
      <template v-if="r.order">
        <p v-if="loading && !order" class="py-6 text-center text-sm text-slate-500">Chargement…</p>
        <template v-if="order">
          <span class="mt-1 inline-block rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-black text-amber-900">{{ order.status_label }}</span>
          <div class="mt-2 divide-y divide-slate-100 text-[14px]">
            <p v-for="i in order.items" :key="i.id" class="flex justify-between gap-2 py-1.5">
              <span class="min-w-0 truncate">{{ i.title }}<template v-if="i.variant_title"> · {{ i.variant_title }}</template><template v-if="i.quantity > 1"> × {{ i.quantity }}</template></span>
              <b class="shrink-0">{{ money(i.total) }}</b>
            </p>
            <p v-if="order.shipping_fee" class="flex justify-between py-1.5"><span>{{ order.expedition ? `🚚 Expédition ${order.expedition.city}` : '🛵 Livraison' }}</span><b>{{ money(order.shipping_fee) }}</b></p>
            <p class="flex justify-between py-1.5 text-[15px]"><b>Total</b><b>{{ money(order.total) }}</b></p>
            <p v-if="order.shipping_address || order.shipping_place_label" class="py-1.5 text-slate-700">📍 {{ [order.shipping_place_label, order.shipping_address].filter(Boolean).join(' · ') }}</p>
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2">
            <RouterLink v-if="proof" :to="`/gestion/commandes/${order.id}`" class="col-span-2 rounded-xl bg-emerald-700 py-3 text-center text-[15px] font-black text-white">💳 Voir le reçu et valider</RouterLink>
            <button v-else-if="order.status === 'pending_confirmation' && auth.can('orders.confirm')" type="button" class="col-span-2 rounded-xl bg-emerald-700 py-3 text-[15px] font-black text-white disabled:opacity-60" :disabled="working" @click="confirmOrder">✓ Client a confirmé</button>
            <a v-if="phone" :href="`tel:${phone}`" class="rounded-xl bg-slate-100 py-2.5 text-center text-sm font-black">📞 Appeler</a>
            <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="rounded-xl bg-[#25d366] py-2.5 text-center text-sm font-black text-white">WhatsApp</a>
          </div>
          <RouterLink :to="`/gestion/commandes/${order.id}`" class="mt-3 block text-center text-sm font-extrabold text-emerald-700 underline">Ouvrir la commande complète ›</RouterLink>
        </template>
      </template>

      <!-- Pièce gardée, ou liste d'attente -->
      <template v-else>
        <p class="mt-2 text-[14px] text-slate-700">
          <b>{{ r.code }}</b> · {{ money(r.unit_price) }} ·
          {{ r.status === 'reserved' ? 'gardée : on attend son quartier' : 'liste d\'attente : servie si une pièce se libère' }}
        </p>
        <div class="mt-3 grid grid-cols-2 gap-2">
          <button v-if="r.status === 'reserved'" type="button" class="col-span-2 rounded-xl bg-slate-900 py-3 text-[15px] font-black text-white" @click="emit('place', r)">📍 Choisir le quartier</button>
          <a v-if="phone" :href="`tel:${phone}`" class="rounded-xl bg-slate-100 py-2.5 text-center text-sm font-black">📞 Appeler</a>
          <a v-if="waLink" :href="waLink" target="_blank" rel="noopener" class="rounded-xl bg-[#25d366] py-2.5 text-center text-sm font-black text-white">WhatsApp</a>
          <button type="button" class="col-span-2 rounded-xl border-2 border-red-200 py-2.5 text-sm font-black text-red-700 disabled:opacity-60" :disabled="busy" @click="emit('cancel', r)">Annuler la réservation</button>
        </div>
      </template>
      <p v-if="error" class="mt-2 text-sm font-bold text-red-700">{{ error }}</p>
    </div>
  </div>
</template>
