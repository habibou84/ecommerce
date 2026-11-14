<script setup>
import { ref } from 'vue'
import Modal from './Modal.vue'

// « Terminer le live » : garder les commandes actives 24 h ou 48 h (les codes marchent encore, commandes comptées dans
// ce live), ou les arrêter maintenant
defineProps({ busy: { type: Boolean, default: false } })
const emit = defineEmits(['close', 'confirm'])
const choice = ref(24)

const until = (hours) => {
  const d = new Date(Date.now() + hours * 3600 * 1000)
  const day = hours === 24 ? 'demain' : d.toLocaleDateString('fr-FR', { weekday: 'long' })
  return `Jusqu'à ${day} ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }).replace(':', ' h ')}`
}
const OPTIONS = [
  { value: 24, title: 'Garder les commandes actives 24 h' },
  { value: 48, title: 'Garder les commandes actives 48 h' },
  { value: null, title: 'Arrêter les commandes maintenant', hint: 'Les codes ne marchent plus' },
]
</script>

<template>
  <Modal title="Terminer le live" @close="emit('close')">
    <p class="text-[15px] text-slate-600">Vous pouvez garder les commandes actives encore un peu, pour ceux qui regardent le replay.</p>
    <div class="mt-3 space-y-2.5">
      <button v-for="o in OPTIONS" :key="String(o.value)" type="button" class="block w-full rounded-2xl border-2 p-3.5 text-left"
              :class="choice === o.value ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 bg-white'" @click="choice = o.value">
        <b class="block text-[16px]">{{ o.title }}</b>
        <span class="text-[13.5px] text-slate-600">{{ o.value ? until(o.value) : o.hint }}</span>
        <span v-if="o.value" class="ml-1.5 inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-[12px] font-extrabold text-emerald-800">Comptées dans ce live</span>
      </button>
    </div>
    <p class="mt-3 text-[13px] text-slate-500">Les clients peuvent encore écrire les codes (A1, A2…) sur WhatsApp ou commander par le lien du live. Ceux qui ont une pièce gardée peuvent toujours donner leur quartier.</p>
    <button type="button" class="mt-4 block w-full rounded-2xl bg-slate-900 py-3 text-base font-extrabold text-white disabled:opacity-60" :disabled="busy" @click="emit('confirm', choice)">Valider</button>
    <button type="button" class="mt-2 block w-full rounded-2xl bg-slate-100 py-3 font-bold" @click="emit('close')">Annuler, je reste en live</button>
  </Modal>
</template>
