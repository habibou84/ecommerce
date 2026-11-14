<script setup>
// Étiquette d'état (commande ou course) : couleur, icône et texte ensemble. L'état se lit sans la couleur
// (daltonisme, écran au soleil) ; le danger (retour, perte, refus) est une pastille pleine.
import { computed } from 'vue'
import Icon from './Icon.vue'
import { STATUS_ICONS, STATUS_TONES } from '../lib/orders'
import { SHIPMENT_ICONS, SHIPMENT_TONES } from '../lib/shipping'

const props = defineProps({
  status: { type: String, required: true },
  label: { type: String, required: true },
  kind: { type: String, default: 'order' }, // order | shipment
  size: { type: String, default: 'sm' }, // sm | md
})
const tone = computed(() => (props.kind === 'shipment' ? SHIPMENT_TONES : STATUS_TONES)[props.status] ?? 'bg-slate-100 text-slate-700')
const icon = computed(() => (props.kind === 'shipment' ? SHIPMENT_ICONS : STATUS_ICONS)[props.status] ?? 'more')
</script>

<template>
  <span class="inline-flex max-w-full items-center gap-1 whitespace-nowrap rounded-full font-semibold" :class="[tone, size === 'md' ? 'px-2.5 py-0.5 text-sm' : 'px-2 py-0.5 text-xs']">
    <Icon :name="icon" :size="size === 'md' ? 15 : 13" />{{ label }}
  </span>
</template>
