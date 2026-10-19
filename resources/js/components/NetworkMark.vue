<script setup>
// Pastille du réseau (Facebook, Instagram, TikTok, Messenger, WhatsApp) : initiales sur la couleur du réseau
defineProps({ network: { type: String, required: true } })
const MARKS = {
  facebook: ['f', 'bg-[#1877f2]'],
  messenger: ['m', 'bg-[#0866ff]'],
  instagram: ['ig', 'bg-[#d62976]'],
  tiktok: ['tt', 'bg-slate-900'],
  whatsapp: ['w', 'bg-[#128c4a]'],
}
</script>

<template>
  <span class="inline-grid h-5 w-5 shrink-0 place-items-center rounded-md align-[-4px] text-[10px] font-bold lowercase leading-none text-white" :class="(MARKS[network] ?? MARKS.whatsapp)[1]" aria-hidden="true">{{ (MARKS[network] ?? MARKS.whatsapp)[0] }}</span>
</template>
