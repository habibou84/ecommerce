<script setup>
import { boot } from '../lib/boot'

// Logo servi tel quel depuis public/ (pas importé par Vite)
const LOGO = '/brand/logo-256.png'

defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: '' } })
</script>

<template>
  <div class="flex min-h-screen flex-col items-center px-4 py-8 sm:justify-center">
    <a :href="boot.zone === 'store' ? '/' : boot.platformUrl" class="mb-6 flex items-center gap-2 font-semibold">
      <span v-if="boot.store" class="grid h-9 w-9 place-items-center rounded-lg bg-emerald-700 text-white">
        {{ boot.store.name.charAt(0).toUpperCase() }}
      </span>
      <img v-else :src="LOGO" alt="" class="h-10 w-10" width="40" height="40">
      <span v-if="boot.store">{{ boot.store.name }}</span>
      <span v-else>Jibiat <span class="text-emerald-700">Marchand</span></span>
    </a>
    <div class="card w-full max-w-md p-6 sm:p-8">
      <h1 class="text-xl font-semibold">{{ title }}</h1>
      <p v-if="subtitle" class="mt-1 text-sm text-slate-600">{{ subtitle }}</p>
      <div class="mt-6">
        <slot />
      </div>
    </div>
    <div class="mt-6 text-center text-sm text-slate-600">
      <slot name="footer" />
    </div>
  </div>
</template>
