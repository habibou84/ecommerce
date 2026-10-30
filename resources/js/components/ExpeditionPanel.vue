<script setup>
import { computed, reactive, ref } from 'vue'
import Alert from './Alert.vue'
import http, { download, errorMessage, fieldErrors } from '../lib/http'
import { formatDateTime, money } from '../lib/format'

// Expédition vers une autre ville, dans la fiche de la commande : paiement avant de partir, dépôt à la gare (noté
// par le livreur dans son application ou ici par le vendeur), message au client, colis récupéré.
const props = defineProps({
  order: { type: Object, required: true },
  canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])

const e = computed(() => props.order.expedition)
const open = ref(false)
const busy = ref(false)
const error = ref('')
const errors = ref({})
const form = reactive({ company: '', other: '', fee: '', ticket: '', receipt: null })
// Message au client : envoyé tout seul, ou en attente du modèle chez Meta (le bouton manuel reste en attendant)
const MESSAGE = {
  sent: ['✓ Client prévenu automatiquement sur WhatsApp.', 'text-emerald-700'],
  approved: ['Le client sera prévenu automatiquement sur WhatsApp au dépôt.', 'text-emerald-700'],
  pending: ['Message automatique en vérification chez Meta (en général quelques minutes). En attendant, il part seul si le client vous a écrit depuis moins de 24 h ; sinon, prévenez-le avec le bouton.', 'text-amber-800'],
  rejected: ['Meta a refusé le message automatique d\'expédition : prévenez le client avec le bouton.', 'text-amber-800'],
  manual: ['Pour prévenir le client automatiquement, reconnectez votre WhatsApp avec Facebook (page WhatsApp).', 'text-slate-500'],
}
const messageInfo = computed(() => MESSAGE[e.value.message_state] ?? null)
const canDrop = computed(() => props.canManage && !e.value.dropped_at && ['confirmed', 'processing', 'shipped'].includes(props.order.status))

async function save() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    const body = new FormData()
    body.append('company', form.company === 'other' ? form.other : form.company)
    body.append('fee', form.fee === '' ? '' : String(Number(form.fee)))
    if (form.ticket) body.append('ticket', form.ticket)
    if (form.receipt) body.append('receipt', form.receipt)
    await http.post(`/orders/${props.order.id}/expedition`, body)
    open.value = false
    emit('changed')
  } catch (err) {
    errors.value = fieldErrors(err)
    error.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}

async function collected() {
  busy.value = true
  try {
    await http.post(`/orders/${props.order.id}/expedition/collected`)
    emit('changed')
  } catch (err) {
    error.value = errorMessage(err)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="card space-y-2 border-sky-200 p-4 text-sm">
    <h3 class="font-semibold">📦 Expédition vers {{ e.city }}</h3>
    <p class="text-slate-600">Frais de la compagnie : {{ e.payer_label?.toLowerCase() }}</p>

    <Alert v-if="e.prepay_due > 0" tone="warning">Paiement avant expédition : le client doit encore payer {{ money(e.prepay_due) }} avant que le colis parte à la gare.</Alert>

    <template v-if="e.dropped_at">
      <div class="rounded-lg bg-sky-50 p-3">
        <p class="font-semibold text-sky-900">Déposée à la gare · {{ e.company }}</p>
        <p v-if="e.ticket">Ticket n° {{ e.ticket }}</p>
        <p>Frais d'expédition : {{ money(e.fee) }}<template v-if="e.advanced"> · avancés par le livreur (ajoutés à sa paie)</template></p>
        <p class="text-xs text-slate-500">{{ formatDateTime(e.dropped_at) }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="e.receipt" type="button" class="btn-secondary px-3 py-1.5" @click="download(`/orders/${order.id}/expedition/receipt`, 'recu-expedition.jpg')">Voir le reçu</button>
        <a v-if="e.whatsapp_url" :href="e.whatsapp_url" target="_blank" class="btn-secondary px-3 py-1.5">{{ e.message_state === 'sent' ? 'Renvoyer moi-même sur WhatsApp' : 'Prévenir le client sur WhatsApp' }}</a>
      </div>
      <p v-if="messageInfo" class="text-xs" :class="messageInfo[1]">{{ messageInfo[0] }}</p>
      <button v-if="canManage && order.status === 'shipped'" type="button" class="btn-brand w-full" :disabled="busy" @click="collected">Colis récupéré par le client</button>
    </template>

    <template v-else-if="canDrop">
      <button v-if="!open" type="button" class="btn-secondary w-full" @click="open = true">Noter le dépôt à la gare</button>
      <form v-else class="space-y-2" @submit.prevent="save">
        <p class="text-xs text-slate-500">À remplir si le colis n'est pas parti avec un livreur de l'application.</p>
        <label class="block font-medium">Compagnie de transport
          <select v-model="form.company" class="input mt-1" required>
            <option value="" disabled>Choisir</option>
            <option v-for="c in e.companies" :key="c" :value="c">{{ c }}</option>
            <option value="other">Autre…</option>
          </select>
        </label>
        <input v-if="form.company === 'other'" v-model="form.other" class="input" placeholder="Nom de la compagnie" maxlength="60" required />
        <p v-if="errors.company" class="text-red-600">{{ errors.company }}</p>
        <div class="grid grid-cols-2 gap-2">
          <label class="block font-medium">Frais payés (F)
            <input v-model="form.fee" class="input mt-1" type="number" min="0" inputmode="numeric" required />
          </label>
          <label class="block font-medium">N° de ticket
            <input v-model="form.ticket" class="input mt-1" maxlength="60" />
          </label>
        </div>
        <label class="block font-medium">Photo du reçu
          <input class="mt-1 block w-full text-sm" type="file" accept="image/*" @change="form.receipt = $event.target.files[0] ?? null" />
        </label>
        <div class="flex gap-2">
          <button class="btn-brand flex-1" :disabled="busy">{{ busy ? 'Enregistrement…' : 'Enregistrer et prévenir le client' }}</button>
          <button type="button" class="btn-ghost" @click="open = false">Annuler</button>
        </div>
      </form>
    </template>
    <p v-else-if="!e.dropped_at" class="text-slate-500">Le livreur notera la compagnie, les frais et le ticket en déposant le colis.</p>
    <p v-if="!e.dropped_at && messageInfo && e.message_state !== 'sent'" class="text-xs" :class="messageInfo[1]">{{ messageInfo[0] }}</p>

    <Alert v-if="error">{{ error }}</Alert>
  </section>
</template>
