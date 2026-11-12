<script setup>
/**
 * Télécommande du show sur l'écran du live : moments à lancer (vus par les écrans dans les 2 secondes),
 * objectif, thème, sons, musique et mode léger. Utilisée dans le cockpit du live (onglet Télé) et sur la page
 * « Écran du live ».
 */
import { computed, ref } from 'vue'
import http, { errorMessage } from '../lib/http'

const props = defineProps({
  id: { type: [Number, String], required: true },
  settings: { type: Object, default: null }, // display.live.show.settings
})
const emit = defineEmits(['changed'])

const THEMES = [['fete', 'Fête'], ['wax', 'Wax'], ['neon', 'Néon'], ['luxe', 'Luxe']]
// Ambiances : d'abord les cinq morceaux de Jibiat Marchand (elles s'enchaînent), puis les musiques composées par
// l'écran, dont les noms ne promettent pas un genre musical précis. Chacune a ses sons des moments.
const AMBIANCES = [['aqui', '🎶 C\'est à qui est à'], ['style', '🎶 Le style au rendez-vous'], ['lagune', '🎶 Soleil sur la lagune'], ['gravity', '🎶 Concrete Gravity'], ['noon', '🎶 High Noon in Plateau'], ['techno', '⚡ Électro'], ['coupe', '🎉 Fête'], ['zouglou', '🥁 Percussions'], ['afro', '🌴 Afro'], ['douce', '🌙 Douce']]
const MOMENTS = [['countdown', '⏱', '3-2-1'], ['party', '🎉', 'Fête'], ['goal', '🎯', 'Objectif'], ['match', '🏆', 'Quartiers'], ['wheel', '🎡', 'Roue']]

const busy = ref(false)
const error = ref('')
const goalInput = ref(null)
const winner = ref(null)
const local = ref(null) // réglages juste enregistrés, en attendant la prochaine lecture
const showSettings = computed(() => local.value ?? props.settings ?? { theme: 'fete', ambiance: 'aqui', sound: true, music: false, light: false, goal: null })

async function run(request) {
  busy.value = true
  error.value = ''
  try {
    await request()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function moment(type) {
  winner.value = null
  await run(async () => {
    const body = type === 'goal' ? { type, goal: Number(goalInput.value) || showSettings.value.goal || 30 } : { type }
    const res = (await http.post(`/lives/${props.id}/show/moment`, body)).data.data
    if (type === 'wheel') winner.value = res.team
  })
}

const saveShow = (patch) => run(async () => {
  const res = (await http.put(`/lives/${props.id}/show`, patch)).data.data
  local.value = res?.theme ? res : { ...showSettings.value, ...patch }
})
</script>

<template>
  <div class="rounded-2xl border border-violet-200 bg-violet-50 p-3 text-violet-900">
    <p class="text-sm font-black">🎬 Le show sur l'écran</p>
    <div class="mt-2 grid grid-cols-5 gap-1.5">
      <button v-for="[type, icon, label] in MOMENTS" :key="type" type="button" class="rounded-xl bg-white px-1 py-2 text-center text-[11px] font-extrabold leading-tight shadow-sm disabled:opacity-60" :disabled="busy" @click="moment(type)">
        <span class="block text-xl" aria-hidden="true">{{ icon }}</span>{{ label }}
      </button>
    </div>
    <label class="mt-2 flex items-center gap-2 text-xs font-bold">🎯 Objectif du live :
      <input v-model.number="goalInput" type="number" min="1" max="1000" class="input w-20 py-1 text-slate-900" :placeholder="String(showSettings.goal ?? 30)" aria-label="Objectif en commandes"> commandes</label>
    <p v-if="winner" class="mt-2 rounded-xl bg-white px-3 py-2 text-sm">🎁 Gagnante : <b>{{ winner.winner || 'Client' }}</b><template v-if="winner.phone"> · {{ winner.phone }}</template> ({{ winner.label }}). L'écran ne montre que la commune et le code : contacte-la pour son cadeau.</p>
    <p class="mt-2 text-xs font-bold">🎵 Ambiance (musique et sons) :</p>
    <div class="mt-1 flex flex-wrap gap-1.5">
      <button v-for="[value, label] in AMBIANCES" :key="value" type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="showSettings.ambiance === value ? 'bg-violet-700 text-white' : 'bg-white'" :disabled="busy" @click="saveShow({ ambiance: value, music: true, sound: true })">{{ label }}</button>
    </div>
    <div class="mt-2 flex flex-wrap gap-1.5">
      <button v-for="[value, label] in THEMES" :key="value" type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="showSettings.theme === value ? 'bg-violet-700 text-white' : 'bg-white'" :disabled="busy" @click="saveShow({ theme: value })">{{ label }}</button>
      <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="showSettings.sound ? 'bg-violet-700 text-white' : 'bg-white'" :disabled="busy" @click="saveShow({ sound: !showSettings.sound })">🔊 Sons</button>
      <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="showSettings.music ? 'bg-violet-700 text-white' : 'bg-white'" :disabled="busy" @click="saveShow({ music: !showSettings.music })">🎵 Musique</button>
      <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="showSettings.light ? 'bg-violet-700 text-white' : 'bg-white'" :disabled="busy" @click="saveShow({ light: !showSettings.light })">🪶 Mode léger</button>
    </div>
    <p v-if="error" class="mt-2 text-sm font-bold text-red-600">{{ error }}</p>
    <p class="mt-2 text-[11px] text-violet-700">Sur la télé, touchez une fois « 🔊 Activer le son du show » pour entendre les sons.</p>
  </div>
</template>
