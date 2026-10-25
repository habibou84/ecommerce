<script setup>
import { computed, ref, watch } from 'vue'
import { LIVE_TRAINING, loadChecks, saveChecks, trainingPoster, trainingVideo } from '../lib/liveTraining'

// « Se former au live » : programme en 4 parties, vidéo courte, l'essentiel en 3 points et une mini check-list par module
const props = defineProps({ url: { type: String, default: '' } })
const emit = defineEmits(['prepare'])

const ready = LIVE_TRAINING.flatMap((p) => p.modules).filter((m) => m.seconds)
const open = ref(null)
const checks = ref([])
const copied = ref(false)
const current = computed(() => ready.find((m) => m.n === open.value) ?? null)

watch(open, (n) => { checks.value = n ? loadChecks(`m${n}`) : [] })
watch(checks, (v) => { if (open.value) saveChecks(`m${open.value}`, v) }, { deep: true })

const duration = (s) => (s < 90 ? `${Math.round(s / 10) * 10} s` : `${Math.round(s / 60)} min`)

async function copyLink() {
  try {
    await navigator.clipboard.writeText(props.url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copiez ce lien :', props.url)
  }
}
</script>

<template>
  <section class="card space-y-4 p-4 text-sm">
    <div>
      <h3 class="font-semibold">🎓 Se former au live</h3>
      <p class="mt-1 text-slate-600">Des vidéos d'une minute pour préparer, animer et réussir vos lives. Commencez par les modules disponibles, les autres arrivent.</p>
    </div>

    <div v-if="current" class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3">
      <div class="flex items-start justify-between gap-2">
        <p class="font-semibold">Module {{ current.n }} · {{ current.title }}</p>
        <button type="button" class="btn-ghost shrink-0 px-2 py-1 text-xs" @click="open = null">Fermer</button>
      </div>
      <video :key="current.n" :src="trainingVideo(current.n)" :poster="trainingPoster(current.n)" controls playsinline preload="metadata"
             class="mx-auto aspect-[9/16] max-h-[70vh] w-full max-w-xs rounded-xl bg-black" />
      <div>
        <p class="font-medium">L'essentiel</p>
        <ul class="mt-1 list-disc space-y-1 pl-5 text-slate-700">
          <li v-for="p in current.points" :key="p">{{ p }}</li>
        </ul>
      </div>
      <div>
        <p class="font-medium">À cocher</p>
        <label v-for="c in current.checklist" :key="c" class="mt-1 flex items-start gap-2">
          <input v-model="checks" type="checkbox" :value="c" class="mt-0.5 h-4 w-4 accent-emerald-700" />
          <span :class="{ 'text-slate-400 line-through': checks.includes(c) }">{{ c }}</span>
        </label>
      </div>
      <button v-if="current.action?.prepare" type="button" class="btn-brand" @click="emit('prepare')">{{ current.action.label }}</button>
      <button v-else-if="current.action?.link && url" type="button" class="btn-brand" @click="copyLink">{{ copied ? 'Lien copié ✓' : current.action.label }}</button>
    </div>

    <div v-for="part in LIVE_TRAINING" :key="part.part">
      <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ part.part }}</p>
      <div class="mt-1.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <button v-for="m in part.modules" :key="m.n" type="button" :disabled="!m.seconds"
                class="flex min-w-0 items-center gap-3 rounded-xl border p-2 text-left"
                :class="m.seconds ? (open === m.n ? 'border-emerald-600 bg-emerald-50' : 'border-slate-200 hover:bg-slate-50') : 'border-dashed border-slate-200 text-slate-400'"
                @click="open = m.n">
          <img v-if="m.seconds" :src="trainingPoster(m.n)" alt="" class="h-16 w-9 shrink-0 rounded object-cover" loading="lazy" />
          <span v-else class="grid h-16 w-9 shrink-0 place-items-center rounded bg-slate-100 text-xs">{{ m.n }}</span>
          <span class="min-w-0 flex-1">
            <span class="block font-medium leading-snug [overflow-wrap:anywhere]">{{ m.n }}. {{ m.title }}</span>
            <span class="text-xs" :class="m.seconds ? 'text-emerald-700' : ''">{{ m.seconds ? `▶ ${duration(m.seconds)}` : 'Bientôt' }}</span>
          </span>
        </button>
      </div>
    </div>
  </section>
</template>
