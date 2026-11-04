<script setup>
import { computed, ref, useAttrs } from 'vue'

/**
 * Champ mot de passe avec l'œil pour afficher ou masquer ce qu'on tape. S'utilise comme un <input> (v-model, id,
 * autocomplete, required…) ; les marges et largeurs (mt-…, max-w-…) vont sur le bloc, le reste sur le champ.
 */
defineOptions({ inheritAttrs: false })
const model = defineModel({ type: [String, Number], default: '' })
const attrs = useAttrs()
const shown = ref(false)

const LAYOUT = /^(m[tbxy]?|max-w|w)-/
const classes = computed(() => String(attrs.class ?? '').split(/\s+/).filter(Boolean))
const outer = computed(() => classes.value.filter((c) => LAYOUT.test(c)))
const inner = computed(() => classes.value.filter((c) => !LAYOUT.test(c)))
const rest = computed(() => Object.fromEntries(Object.entries(attrs).filter(([key]) => key !== 'class')))
</script>

<template>
  <div class="relative" :class="outer">
    <input v-bind="rest" v-model="model" :type="shown ? 'text' : 'password'" :class="[inner, 'pr-11']" />
    <button type="button" class="absolute inset-y-0 right-0 grid w-10 place-items-center text-slate-400 hover:text-slate-700" :aria-label="shown ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" :aria-pressed="shown" :title="shown ? 'Masquer' : 'Afficher'" @click="shown = !shown">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
        <circle cx="12" cy="12" r="3" />
        <path v-if="shown" d="M3 3l18 18" />
      </svg>
    </button>
  </div>
</template>
