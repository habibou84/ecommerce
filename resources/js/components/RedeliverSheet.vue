<script setup>
// « Relivrer » après une livraison ratée (ou « Changer la date » d'une course reportée) : le jour, le livreur,
// un mot pour lui, et prévenir la cliente de la nouvelle date par WhatsApp.
import { computed, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import DelivererPicker from './DelivererPicker.vue'
import Modal from './Modal.vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

const props = defineProps({
  order: { type: Object, required: true },
  options: { type: Object, default: null },
  mode: { type: String, default: 'redeliver' }, // 'redeliver' | 'date'
})
const emit = defineEmits(['close', 'done', 'changed'])

const iso = (d) => d.toLocaleDateString('sv-SE')
const today = new Date()
const DAYS = [0, 1, 2].map((n) => {
  const d = new Date(today)
  d.setDate(d.getDate() + n)
  return { value: iso(d), label: n === 0 ? 'Aujourd\'hui' : n === 1 ? 'Demain' : d.toLocaleDateString('fr-FR', { weekday: 'long' }).replace(/^./, (c) => c.toUpperCase()), sub: d.toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric' }) }
})

const form = reactive({ date: DAYS[1].value, courierKey: props.order.deliverer_key ?? '', note: '', notify: !!props.order.customer_phone })
const other = ref('')
function pickOther(e) {
  other.value = e.target.value
  if (other.value) form.date = other.value
}
const isOther = computed(() => !DAYS.some((d) => d.value === form.date))
const dayText = computed(() => {
  const d = DAYS.find((x) => x.value === form.date)
  if (d) return d.label.toLowerCase()
  return `le ${new Date(`${form.date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}`
})

// Changer de livreur : seulement entre livreurs de la boutique (une entreprise se choisit depuis la commande)
const own = computed(() => props.order.failure?.own_courier)
const picking = ref(false)
const error = ref('')
const courierName = computed(() => {
  if (!form.courierKey) return props.order.deliverer
  const [type, id] = form.courierKey.split(':')
  return (type === 'courier' ? props.options?.couriers : props.options?.carriers)?.find((c) => String(c.id) === id)?.name ?? props.order.deliverer
})
function chooseCourier(key) {
  picking.value = false
  if (!key) return
  if (!key.startsWith('courier:')) {
    error.value = 'Pour confier la relivraison à une entreprise de livraison, ouvrez la commande.'
    return
  }
  form.courierKey = key
}

const busy = ref(false)
const done = ref(null) // { sent, whatsapp_url }
async function submit() {
  busy.value = true
  error.value = ''
  const o = props.order
  try {
    if (props.mode === 'redeliver') {
      await http.post(`/shipments/${o.shipment_id}/decision`, { decision: 'redeliver', date: form.date, note: form.note || null })
      if (own.value && form.courierKey && form.courierKey !== o.deliverer_key) {
        await http.post(`/shipments/${o.shipment_id}/assign`, { courier_id: Number(form.courierKey.split(':')[1]) })
      }
    } else {
      await http.put(`/orders/${o.id}`, { scheduled_date: form.date })
    }
    const text = props.mode === 'redeliver' ? `${o.reference} sera relivrée ${dayText.value}${courierName.value ? ` par ${courierName.value}` : ''}.` : `${o.reference} : nouvelle date ${dayText.value}.`
    if (form.notify && o.customer_phone) {
      const { data } = await http.post(`/orders/${o.id}/new-date-message`)
      if (data.data.sent) {
        emit('done', `${text} Cliente prévenue par WhatsApp.`)
        return
      }
      // Message prêt à envoyer depuis le téléphone : la fenêtre reste ouverte avec le bouton, la liste se met à jour
      done.value = { summary: text, message: data.data.text, whatsapp_url: data.data.whatsapp_url }
      emit('changed')
      return
    }
    emit('done', text)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="mode === 'redeliver' ? `Relivrer ${order.customer_name || order.reference}` : `Nouvelle date pour ${order.customer_name || order.reference}`" @close="emit('close')">
    <!-- Fait, mais la cliente reste à prévenir depuis le téléphone (pas de WhatsApp de la boutique utilisable) -->
    <div v-if="done" class="space-y-3">
      <Alert tone="success">{{ done.summary }}</Alert>
      <p class="text-sm text-slate-600">Message prêt : envoyez-le depuis votre WhatsApp.</p>
      <p class="whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{{ done.message }}</p>
      <a :href="done.whatsapp_url" target="_blank" rel="noopener" class="btn-brand w-full py-3" @click="emit('close')">Envoyer le message à la cliente</a>
      <button type="button" class="btn-ghost w-full" @click="emit('close')">Plus tard</button>
    </div>

    <div v-else class="space-y-4">
      <p class="text-sm text-slate-500">{{ order.reference }} · {{ order.shipping_place_label || '' }} · {{ money(order.total) }}<template v-if="order.failure?.reason"> · « {{ order.failure.reason }} »</template></p>
      <Alert v-if="error">{{ error }}</Alert>

      <div>
        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Quel jour ?</p>
        <div class="grid grid-cols-4 gap-1.5">
          <button v-for="d in DAYS" :key="d.value" type="button" class="rounded-xl border px-1 py-2 text-center text-sm font-bold" :class="form.date === d.value ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-700'" @click="form.date = d.value">
            {{ d.label }}<span class="block text-xs font-medium text-slate-500">{{ d.sub }}</span>
          </button>
          <label class="relative rounded-xl border px-1 py-2 text-center text-sm font-bold" :class="isOther ? 'border-emerald-700 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-700'">
            📅<span class="block text-xs font-medium text-slate-500">{{ isOther ? new Date(`${form.date}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : 'Autre' }}</span>
            <input type="date" class="absolute inset-0 cursor-pointer opacity-0" :min="DAYS[0].value" :value="other" aria-label="Autre date" @change="pickOther" />
          </label>
        </div>
      </div>

      <div v-if="mode === 'redeliver' && order.deliverer">
        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Qui livre ?</p>
        <div class="flex min-h-11 items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 pl-3 pr-1">
          <span class="min-w-0 flex-1 truncate text-sm font-bold text-emerald-900">🚚 {{ courierName }}</span>
          <button v-if="own && options" type="button" class="h-9 shrink-0 rounded-lg bg-white px-3 text-sm font-bold text-emerald-800 shadow-sm" @click="picking = true">Changer</button>
        </div>
      </div>

      <div v-if="mode === 'redeliver'">
        <label for="rd-note" class="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">Un mot pour le livreur (facultatif)</label>
        <input id="rd-note" v-model="form.note" class="input" maxlength="500" placeholder="Appeler avant 10 h, sonner chez la voisine…" />
      </div>

      <label v-if="order.customer_phone" class="flex items-center gap-3 text-sm text-slate-700">
        <input v-model="form.notify" type="checkbox" class="h-5 w-5 shrink-0 accent-emerald-700" />Prévenir la cliente par WhatsApp de la nouvelle date
      </label>

      <button type="button" class="btn-brand w-full py-3 text-base" :disabled="busy" @click="submit">
        {{ busy ? 'Un instant…' : mode === 'redeliver' ? `Relivrer ${dayText}${courierName ? ` avec ${courierName.split(' ')[0]}` : ''}` : `Livrer ${dayText}` }}
      </button>
    </div>

    <DelivererPicker v-if="picking" :model-value="form.courierKey" :options="options" :place-label="order.shipping_place_label ?? ''" @update:model-value="chooseCourier" @close="picking = false" />
  </Modal>
</template>
