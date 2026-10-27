<template>
  <!-- Icône en couleur (dégradé doux), posée ou non sur une tuile claire de la même teinte -->
  <svg :width="size" :height="size" viewBox="0 0 48 48" fill="none" aria-hidden="true" class="shrink-0">
    <defs>
      <linearGradient :id="`${id}b`" x1="0" y1="0" x2="1" y2="1"><stop offset="0" :stop-color="hue.g1" /><stop offset="1" :stop-color="hue.g2" /></linearGradient>
      <linearGradient :id="`${id}a`" x1="0" y1="0" x2="1" y2="1"><stop offset="0" :stop-color="accent.g1" /><stop offset="1" :stop-color="accent.g2" /></linearGradient>
      <linearGradient v-if="tile" :id="`${id}t`" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" /><stop offset="1" :stop-color="hue.tile" /></linearGradient>
    </defs>
    <rect v-if="tile" width="48" height="48" rx="14" :fill="`url(#${id}t)`" />
    <g :transform="tile ? 'translate(7.2 7.2) scale(.7)' : undefined" stroke-linecap="round" stroke-linejoin="round">
      <path :d="join(icon.body)" :fill="`url(#${id}b)`" />
      <path v-if="icon.accentP?.length" :d="join(icon.accentP)" :fill="`url(#${id}a)`" stroke="#fff" stroke-width="1.6" />
      <path v-if="icon.line?.length" :d="join(icon.line)" :stroke="icon.lineOut ? hue.g2 : '#fff'" :stroke-width="icon.lineOut ? 3.2 : 2.8" opacity=".95" />
      <path v-if="icon.accentLine" :d="join(icon.accentLine)" :stroke="icon.accentP?.length ? '#fff' : accent.g2" stroke-width="3" />
    </g>
  </svg>
</template>

<script setup>
import { computed } from 'vue'
import { HUES, ICONS } from './colorIcons'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 48 },
  tile: { type: Boolean, default: true },
})

// Identifiant propre à chaque icône : les dégradés de deux icônes ne se mélangent pas
const id = `ci${Math.random().toString(36).slice(2, 9)}`

const icon = computed(() => ICONS[props.name] || ICONS.colis)
const hue = computed(() => HUES[icon.value.hue])
const accent = computed(() => HUES[icon.value.accent])
const join = (list) => (list || []).join(' ')
</script>
