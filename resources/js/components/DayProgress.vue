<script setup>
// Carte d'avancement des commandes : total, livré, barre (livré, en route, échec) et « Faire le point ».
// La même sur l'accueil (page Commandes) et dans la liste des commandes.
import { computed, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import PointSheet from './PointSheet.vue'
import { money } from '../lib/format'
import { canPoint, loadPoint } from '../lib/point'
import { useAuthStore } from '../stores/auth'

const props = defineProps({ progress: { type: Object, default: null }, title: { type: String, default: 'Commandes du jour' } })
const emit = defineEmits(['changed'])
const auth = useAuthStore()

const showPoint = computed(() => canPoint(auth))
const pointTotal = ref(null)
const pointOpen = ref(false)
async function loadPointTotal() {
  if (showPoint.value) pointTotal.value = (await loadPoint(auth).catch(() => null))?.total ?? null
}
onMounted(loadPointTotal)
function changed() {
  loadPointTotal()
  emit('changed')
}
const pct = (n) => (props.progress?.amount ? Math.min(100, Math.round((n / props.progress.amount) * 100)) : 0)
</script>

<template>
  <section class="card p-4 lg:p-5">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0"><p class="text-sm font-semibold text-slate-500">{{ title }}</p><p class="truncate font-display text-2xl font-extrabold tracking-tight lg:text-3xl">{{ progress ? money(progress.amount) : '…' }}</p></div>
      <div class="min-w-0 text-right"><p class="text-sm font-semibold text-slate-500">Livré</p><p class="truncate font-display text-2xl font-extrabold tracking-tight text-emerald-700 lg:text-3xl">{{ progress ? money(progress.delivered_amount) : '…' }}</p></div>
    </div>
    <template v-if="progress">
      <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-slate-200" role="img" :aria-label="`${pct(progress.delivered_amount)} % livré`">
        <i class="block h-full bg-emerald-700" :style="{ width: `${pct(progress.delivered_amount)}%` }" />
        <i class="block h-full bg-sky-400" :style="{ width: `${pct(progress.on_the_way_amount)}%` }" />
        <i class="block h-full bg-red-500" :style="{ width: `${pct(progress.failed_amount)}%` }" />
      </div>
      <p class="mt-1.5 flex flex-wrap justify-between gap-x-3 text-sm font-semibold">
        <span class="text-emerald-800">{{ pct(progress.delivered_amount) }} % livré · {{ progress.delivered_count }} sur {{ progress.count }}</span>
        <span class="text-slate-600">{{ progress.on_the_way_count }} en route<template v-if="progress.failed_count"> · <span class="text-red-700">{{ progress.failed_count }} échec{{ progress.failed_count > 1 ? 's' : '' }}</span></template></span>
      </p>
    </template>
    <button v-if="showPoint" type="button" class="btn-brand mt-3 w-full py-3 text-base lg:w-auto lg:px-6" @click="pointOpen = true">
      <Icon name="cash" :size="18" class="shrink-0" /><span class="truncate">Faire le point<span v-if="pointTotal" class="font-semibold opacity-90"> · {{ money(pointTotal) }}<span class="hidden sm:inline"> à récupérer</span></span></span>
    </button>
    <PointSheet v-if="pointOpen" @close="pointOpen = false" @changed="changed" />
  </section>
</template>
