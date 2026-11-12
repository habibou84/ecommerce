<script setup>
/**
 * Télécommande du show sur l'écran du live : réactions, moments à lancer (vus par les écrans dans les 2 secondes),
 * article mystère (annonce, indices, révélation), défi, dernière chance, puis les réglages (ambiance, thème, sons,
 * modes). Utilisée dans le cockpit du live (onglet Moments) et sur la page « Écran du live ».
 */
import { computed, ref } from 'vue'
import http, { errorMessage } from '../lib/http'
import { launch } from '../lib/liveShow'

const props = defineProps({
  id: { type: [Number, String], required: true },
  settings: { type: Object, default: null }, // display.live.show.settings
  state: { type: Object, default: null }, // display.live.show.state : défi, dernière chance, mystère
  hasMystery: { type: Boolean, default: false }, // un article mystère attend d'être révélé
  dark: { type: Boolean, default: false },
  noReactions: { type: Boolean, default: false }, // le cockpit les a déjà en bas
})
const emit = defineEmits(['changed', 'launched'])

const THEMES = [['fete', 'Fête'], ['wax', 'Wax'], ['neon', 'Néon'], ['luxe', 'Luxe']]
// Ambiances : d'abord les cinq morceaux de Jibiat Marchand (elles s'enchaînent), puis les musiques composées par
// l'écran, dont les noms ne promettent pas un genre musical précis. Chacune a ses sons des moments.
const AMBIANCES = [['aqui', '🎶 C\'est à qui est à'], ['style', '🎶 Le style au rendez-vous'], ['lagune', '🎶 Soleil sur la lagune'], ['gravity', '🎶 Concrete Gravity'], ['noon', '🎶 High Noon in Plateau'], ['techno', '⚡ Électro'], ['coupe', '🎉 Fête'], ['zouglou', '🥁 Percussions'], ['afro', '🌴 Afro'], ['douce', '🌙 Douce']]
const REACTIONS = ['❤️', '🔥', '😂', '👏', '🎁']
const MOMENTS = [['podium', '🥇', 'Les 3 premiers'], ['map', '🗺️', 'Carte du live'], ['how', '💬', 'Comment commander'], ['countdown', '⏱', '3-2-1'], ['party', '🎉', 'Fête'], ['match', '🏆', 'Quartiers'], ['wheel', '🎡', 'Roue'], ['goal', '🎯', 'Objectif'], ['last', '⏳', 'Dernière chance']]
const REWARDS = [['delivery', 'Livraison offerte'], ['gift', 'Cadeau surprise'], ['draw', 'Tirage au sort']]
const MYSTERY_MODES = [['box', '🎁 Boîte'], ['zoom', '🔍 Gros plan'], ['blur', '🌫️ Flou']]

const busy = ref(false)
const error = ref('')
const goalInput = ref(null)
const defiGoal = ref(5)
const reward = ref('delivery')
const winner = ref(null)
const local = ref(null) // réglages juste enregistrés, en attendant la prochaine lecture
const showSettings = computed(() => local.value ?? props.settings ?? { theme: 'fete', ambiance: 'aqui', sound: true, music: false, light: false, camera: false, goal: null, mystery_mode: 'zoom' })
const mystery = computed(() => props.state?.mystery ?? null)

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

async function moment(type, extra = {}) {
  winner.value = null
  await run(async () => {
    const body = type === 'goal' ? { goal: Number(goalInput.value) || showSettings.value.goal || 30 } : type === 'defi' ? { goal: Number(defiGoal.value) || 5, reward: reward.value } : type === 'last' ? { seconds: 120 } : type === 'announce' ? { minutes: 5 } : extra
    const res = await launch(props.id, type, body)
    if (type === 'wheel') winner.value = res.team
    emit('launched', type)
  })
}

const saveShow = (patch) => run(async () => {
  const res = (await http.put(`/lives/${props.id}/show`, patch)).data.data
  local.value = res?.theme ? res : { ...showSettings.value, ...patch }
})
const chip = (on) => (on ? 'bg-violet-700 text-white' : props.dark ? 'bg-white/10 text-white' : 'bg-white')
</script>

<template>
  <div class="space-y-3 rounded-2xl p-3" :class="dark ? 'bg-white/5 text-white' : 'border border-violet-200 bg-violet-50 text-violet-900'">
    <!-- Réactions : une pluie d'émojis sur l'écran -->
    <div v-if="!noReactions">
      <p class="text-xs font-black">❤️ Réactions sur l'écran</p>
      <div class="mt-1 grid grid-cols-5 gap-1.5">
        <button v-for="e in REACTIONS" :key="e" type="button" class="rounded-xl py-2 text-2xl disabled:opacity-60" :class="dark ? 'bg-white/10' : 'bg-white shadow-sm'" :disabled="busy" :aria-label="`Réaction ${e}`" @click="moment('react', { emoji: e })">{{ e }}</button>
      </div>
    </div>

    <!-- Article mystère -->
    <div class="rounded-xl p-2" :class="dark ? 'bg-violet-500/20' : 'bg-white'">
      <p class="text-xs font-black">🎁 Article mystère</p>
      <template v-if="mystery">
        <p class="mt-1 text-xs">En cours · indice {{ mystery.level }}/3 : laisse-les deviner dans les commentaires.</p>
        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
          <button type="button" class="rounded-lg bg-amber-400 py-2 text-sm font-extrabold text-slate-900 disabled:opacity-50" :disabled="busy || mystery.level >= 3" @click="moment('hint')">💡 Indice</button>
          <button type="button" class="rounded-lg bg-violet-600 py-2 text-sm font-extrabold text-white" :disabled="busy" @click="moment('reveal')">✨ Révéler</button>
        </div>
      </template>
      <template v-else-if="hasMystery">
        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
          <button type="button" class="rounded-lg bg-violet-600 py-2 text-sm font-extrabold text-white" :disabled="busy" @click="moment('mystery')">🎁 Lancer</button>
          <button type="button" class="rounded-lg py-2 text-sm font-extrabold" :class="dark ? 'bg-white/10' : 'bg-violet-50'" :disabled="busy || !!state?.announce_at" @click="moment('announce')">{{ state?.announce_at ? '📣 Annoncé ✓' : '📣 Annoncer (5 min)' }}</button>
        </div>
        <div class="mt-1.5 grid grid-cols-3 gap-1" role="radiogroup" aria-label="Comment le faire deviner">
          <button v-for="[value, label] in MYSTERY_MODES" :key="value" type="button" role="radio" :aria-checked="(showSettings.mystery_mode || 'zoom') === value" class="rounded-lg py-1.5 text-[11px] font-extrabold" :class="chip((showSettings.mystery_mode || 'zoom') === value)" :disabled="busy" @click="saveShow({ mystery_mode: value })">{{ label }}</button>
        </div>
      </template>
      <p v-else class="mt-1 text-xs" :class="dark ? 'text-slate-300' : 'text-violet-700'">Aucun article mystère : coche « 🎁 Mystère » sur un article (onglet Plan, ou à la préparation).</p>
    </div>

    <!-- Défi du live -->
    <div class="rounded-xl p-2" :class="dark ? 'bg-white/10' : 'bg-white'">
      <p class="text-xs font-black">🎯 Défi du live</p>
      <div v-if="state?.defi" class="mt-1 flex items-center gap-2 text-xs">
        <span class="min-w-0 flex-1">En cours : {{ state.defi.target }} commandes → {{ state.defi.reward }}</span>
        <button type="button" class="shrink-0 rounded-lg px-2 py-1 font-bold underline" :disabled="busy" @click="moment('defi_stop')">Arrêter</button>
      </div>
      <div v-else class="mt-1 flex flex-wrap items-center gap-1.5 text-xs font-bold">
        Encore <input v-model.number="defiGoal" type="number" min="1" max="100" class="input w-14 py-1 text-slate-900" aria-label="Commandes à atteindre"> commandes →
        <select v-model="reward" class="input w-auto py-1 text-slate-900" aria-label="Récompense"><option v-for="[value, label] in REWARDS" :key="value" :value="value">{{ label }}</option></select>
        <button type="button" class="rounded-lg bg-violet-600 px-3 py-1.5 text-white" :disabled="busy" @click="moment('defi')">Lancer</button>
      </div>
    </div>

    <!-- Autres moments -->
    <div class="grid grid-cols-3 gap-1.5">
      <button v-for="[type, icon, label] in MOMENTS" :key="type" type="button" class="rounded-xl px-1 py-2 text-center text-[11px] font-extrabold leading-tight disabled:opacity-60" :class="dark ? 'bg-white/10' : 'bg-white shadow-sm'" :disabled="busy || (type === 'last' && !!state?.last_until && new Date(state.last_until) > new Date())" @click="moment(type)">
        <span class="block text-xl" aria-hidden="true">{{ icon }}</span>{{ label }}
      </button>
    </div>
    <label class="flex items-center gap-2 text-xs font-bold">🎯 Objectif du live :
      <input v-model.number="goalInput" type="number" min="1" max="1000" class="input w-20 py-1 text-slate-900" :placeholder="String(showSettings.goal ?? 30)" aria-label="Objectif en commandes"> commandes</label>
    <p v-if="winner" class="rounded-xl px-3 py-2 text-sm" :class="dark ? 'bg-white/10' : 'bg-white'">🎁 Gagnante : <b>{{ winner.winner || 'Client' }}</b><template v-if="winner.phone"> · {{ winner.phone }}</template> ({{ winner.label }}). L'écran ne montre que la commune et le code : contacte-la pour son cadeau.</p>
    <p v-if="error" class="text-sm font-bold text-red-500">{{ error }}</p>

    <!-- Réglages -->
    <details class="rounded-xl p-2" :class="dark ? 'bg-white/5' : 'bg-white'" open>
      <summary class="cursor-pointer text-xs font-black">🎵 Musique, thème, modes</summary>
      <p class="mt-2 text-xs font-bold">🎵 Ambiance (musique et sons) :</p>
      <div class="mt-1 flex flex-wrap gap-1.5">
        <button v-for="[value, label] in AMBIANCES" :key="value" type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.ambiance === value)" :disabled="busy" @click="saveShow({ ambiance: value, music: true, sound: true })">{{ label }}</button>
      </div>
      <div class="mt-2 flex flex-wrap gap-1.5">
        <button v-for="[value, label] in THEMES" :key="value" type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.theme === value)" :disabled="busy" @click="saveShow({ theme: value })">{{ label }}</button>
        <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.sound)" :disabled="busy" @click="saveShow({ sound: !showSettings.sound })">🔊 Sons</button>
        <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.music)" :disabled="busy" @click="saveShow({ music: !showSettings.music })">🎵 Musique</button>
        <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.light)" :disabled="busy" @click="saveShow({ light: !showSettings.light })">🪶 Mode léger</button>
        <button type="button" class="rounded-full px-3 py-1 text-xs font-bold" :class="chip(showSettings.camera)" :disabled="busy" @click="saveShow({ camera: !showSettings.camera })">🎥 Mode caméra</button>
      </div>
      <p class="mt-2 text-[11px]" :class="dark ? 'text-slate-400' : 'text-violet-700'">Sur la télé, touchez une fois « 🔊 Activer le son du show » pour entendre les sons.</p>
    </details>
  </div>
</template>
