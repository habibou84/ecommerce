<script setup>
import { nextTick, onMounted, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'

/**
 * Un message de l'onglet Messages du live, ouvert sans quitter le live : la conversation de la cliente, les
 * réponses rapides et le champ pour écrire.
 */
const props = defineProps({
  message: { type: Object, required: true },
  quick: { type: Array, default: () => [] },
  orderId: { type: Number, default: null },
})
const emit = defineEmits(['close', 'sent'])

const chat = ref(null)
const body = ref('')
const busy = ref(false)
const error = ref('')
const list = ref(null)

async function load() {
  try {
    chat.value = (await http.get(`/conversations/${props.message.conversation_id}`)).data.data
    await nextTick()
    list.value?.scrollTo({ top: list.value.scrollHeight })
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function send(text = body.value) {
  if (!text.trim()) return
  busy.value = true
  error.value = ''
  try {
    await http.post(`/conversations/${props.message.conversation_id}/messages`, { body: text.trim() })
    body.value = ''
    await load()
    emit('sent')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
const label = (m) => (m.type === 'image' ? '📷 Photo' : m.body)
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-end bg-slate-950/60" @click.self="emit('close')">
    <div class="flex max-h-[88vh] w-full flex-col rounded-t-[24px] bg-white px-4 pt-2.5 pb-[max(1rem,env(safe-area-inset-bottom))] text-slate-900" role="dialog" aria-modal="true" :aria-label="`Conversation avec ${message.name}`">
      <div class="mx-auto mb-2 h-1.5 w-10 shrink-0 rounded-full bg-slate-300" />
      <div class="flex shrink-0 items-center gap-2">
        <b class="min-w-0 flex-1 truncate text-[17px]">💬 {{ message.name }}</b>
        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-lg font-black" aria-label="Fermer" @click="emit('close')">✕</button>
      </div>
      <div ref="list" class="mt-2 min-h-24 flex-1 space-y-1.5 overflow-y-auto overscroll-contain">
        <p v-if="!chat && !error" class="py-6 text-center text-sm text-slate-500">Chargement…</p>
        <p v-for="m in chat?.messages ?? []" :key="m.id" class="max-w-[85%] rounded-xl px-2.5 py-1.5 text-[13.5px] break-words whitespace-pre-line"
           :class="m.direction === 'out' ? 'ml-auto bg-[#dcf8c6]' : 'bg-slate-100'">{{ label(m) }}</p>
      </div>
      <template v-if="chat?.can_reply !== false">
        <div class="mt-2 flex shrink-0 flex-wrap gap-1">
          <button v-for="q in quick" :key="q" type="button" class="rounded-lg border-[1.5px] border-slate-300 bg-white px-2 py-1 text-left text-[11.5px] font-bold" :disabled="busy" @click="send(q)">{{ q }}</button>
        </div>
        <form class="mt-2 flex shrink-0 gap-1.5" @submit.prevent="send()">
          <input v-model="body" class="input min-w-0 flex-1 py-2 text-sm" placeholder="Votre réponse…" maxlength="1000" aria-label="Votre réponse" />
          <button class="rounded-xl bg-slate-900 px-4 text-sm font-extrabold text-white disabled:opacity-50" :disabled="busy || !body.trim()">Envoyer</button>
        </form>
      </template>
      <p v-else class="mt-2 text-xs text-slate-500">Plus de 24 h sans message de la cliente : WhatsApp ne permet plus de lui écrire ici.</p>
      <p v-if="error" class="mt-1 text-sm font-bold text-red-700">{{ error }}</p>
      <div class="mt-2 flex shrink-0 justify-center gap-4 text-sm font-extrabold text-emerald-700 underline">
        <RouterLink v-if="orderId" :to="`/gestion/commandes/${orderId}`">Voir sa commande</RouterLink>
        <RouterLink :to="`/gestion/messages/${message.conversation_id}`">Ouvrir dans Messages ›</RouterLink>
      </div>
    </div>
  </div>
</template>
