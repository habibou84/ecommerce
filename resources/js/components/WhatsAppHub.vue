<script setup>
import { computed } from 'vue'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'

/**
 * Cœur du menu WhatsApp, le même sur ordinateur (accueil WhatsApp) et sur téléphone (onglet WhatsApp de l'accueil) :
 * ce qui attend (messages non lus, récaps sans réponse, paniers à confirmer), puis les grandes entrées du menu.
 * `data` : GET /whatsapp/sales (null pour qui ne gère pas WhatsApp : seulement les conversations).
 */
const props = defineProps({
  data: { type: Object, default: null },
  unread: { type: Number, default: 0 },
  canManage: { type: Boolean, default: true },
  canMessages: { type: Boolean, default: true },
})

const pl = (n, one, many) => `${n} ${n > 1 ? many : one}`
const unreadCount = computed(() => props.data?.todo?.unread ?? props.unread)
const todo = computed(() => {
  const t = props.data?.todo ?? {}
  return [
    props.canMessages && unreadCount.value > 0 && { key: 'unread', icon: 'whatsapp', text: pl(unreadCount.value, 'message non lu', 'messages non lus'), action: 'Ouvrir', to: '/gestion/messages' },
    t.recaps > 0 && { key: 'recaps', icon: 'clock', text: pl(t.recaps, 'récap sans réponse', 'récaps sans réponse'), action: 'Voir', to: '/gestion/whatsapp/ventes#commandes' },
    t.carts > 0 && { key: 'carts', icon: 'orders', text: pl(t.carts, 'panier du catalogue à confirmer', 'paniers du catalogue à confirmer'), action: 'Confirmer', to: '/gestion/confirmation' },
  ].filter(Boolean)
})
const tiles = computed(() => [
  props.canMessages && { to: '/gestion/messages', icon: 'telephone', label: 'Conversations', hint: unreadCount.value ? pl(unreadCount.value, 'non lu', 'non lus') : 'Vos clients sur WhatsApp' },
  props.canManage && { to: '/gestion/whatsapp/ventes', icon: 'panier', label: 'Assistant des ventes', hint: 'Récap, catalogue, modèles', main: true },
  props.canManage && { to: '/gestion/whatsapp/reponses', icon: 'aide', label: 'Assistant des réponses', hint: 'Réponses, prix, messages' },
  props.canManage && { to: '/gestion/marketing/relances', icon: 'partage', label: 'Relances', hint: 'Nouveautés, offres' },
].filter(Boolean))
</script>

<template>
  <div class="space-y-3">
    <div v-if="todo.length" class="rounded-2xl border border-ocre-200 bg-ocre-50 px-3 py-2">
      <p class="text-[11px] font-black uppercase tracking-wide text-ocre-800">À faire</p>
      <RouterLink v-for="t in todo" :key="t.key" :to="t.to" class="flex items-center gap-2 border-t border-ocre-100 py-2 text-[14px] font-bold first-of-type:border-0">
        <Icon :name="t.icon" :size="16" class="shrink-0 text-ocre-700" /><span class="min-w-0 flex-1">{{ t.text }}</span><span class="shrink-0 text-xs font-black text-ocre-700">{{ t.action }} ▸</span>
      </RouterLink>
    </div>

    <div class="grid grid-cols-2 gap-2.5 lg:grid-cols-4">
      <RouterLink v-for="t in tiles" :key="t.to" :to="t.to" class="flex min-h-[112px] flex-col gap-1.5 rounded-[20px] p-3.5 transition active:scale-[.98]"
                  :class="t.main ? 'bg-brand-grad text-white' : 'card hover:border-emerald-200'">
        <ColorIcon :name="t.icon" :size="40" :tile="!t.main" :class="t.main ? 'rounded-xl bg-white/90 p-1' : ''" />
        <b class="font-display text-[15.5px] font-extrabold leading-tight">{{ t.label }}</b>
        <span class="-mt-1 text-[13px]" :class="t.main ? 'text-emerald-50' : 'text-slate-500'">{{ t.hint }}</span>
      </RouterLink>
    </div>

    <RouterLink v-if="canManage" to="/gestion/textes-messages" class="card flex items-center gap-3 px-3.5 py-3">
      <ColorIcon name="cloche" :size="36" />
      <span class="min-w-0 flex-1"><b class="block text-[15px]">Textes des messages</b><span class="text-[13px] text-slate-500">Confirmation, livraison, relance… avec vos mots</span></span>
      <Icon name="chevron" :size="16" class="text-slate-400" />
    </RouterLink>
  </div>
</template>
