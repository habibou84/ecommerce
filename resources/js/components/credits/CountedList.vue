<script setup>
import { COUNTED } from '../../lib/credits'

/** Ce qui est compté dans le solde : payant ou gratuit */
</script>

<template>
  <ul class="divide-y divide-slate-100 text-sm">
    <li v-for="[icon, label, paid] in COUNTED" :key="label" class="flex items-center gap-2 py-2">
      <span aria-hidden="true">{{ icon }}</span><span class="min-w-0 flex-1">{{ label }}</span>
      <b :class="paid ? 'text-ocre-700' : 'text-emerald-700'">{{ paid ? 'payant' : 'gratuit' }}</b>
    </li>
  </ul>
</template>
