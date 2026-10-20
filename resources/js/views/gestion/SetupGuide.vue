<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import Alert from '../../components/Alert.vue'
import Icon from '../../components/Icon.vue'
import { useAuthStore } from '../../stores/auth'
import http, { errorMessage } from '../../lib/http'
import { guideLeave } from '../../lib/guide'

// Guide de démarrage : une étape à la fois (pourquoi, quoi faire, bouton vers le bon écran), avancement calculé sur
// les données de la boutique ; on peut passer une étape et la reprendre plus tard
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const guide = ref(null)
const current = ref(null)
const error = ref('')
const busy = ref(false)

const steps = computed(() => guide.value?.steps ?? [])
const step = computed(() => steps.value.find((s) => s.key === current.value) ?? null)
const index = computed(() => steps.value.findIndex((s) => s.key === current.value))
const phases = computed(() => Object.entries(guide.value?.phases ?? {}).map(([key, label]) => ({ key, label, steps: steps.value.filter((s) => s.phase === key) })).filter((p) => p.steps.length))
const finished = computed(() => guide.value && guide.value.done === guide.value.total)
const percent = computed(() => (guide.value?.total ? Math.round((guide.value.done / guide.value.total) * 100) : 0))

async function load() {
  try {
    guide.value = (await http.get('/setup-guide')).data.data
    current.value = (route.query.etape && steps.value.some((s) => s.key === route.query.etape) ? route.query.etape : null) ?? current.value ?? guide.value.next ?? steps.value[0]?.key
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

function select(key) {
  current.value = key
  router.replace({ query: { etape: key } })
  window.scrollTo({ top: 0, behavior: 'smooth' })
}
function go(offset) {
  const next = steps.value[index.value + offset]
  if (next) select(next.key)
}
async function setSkipped(skipped) {
  busy.value = true
  try {
    const key = step.value.key
    guide.value = (await http.put(`/setup-guide/${key}`, { skipped })).data.data
    // Étape passée : on enchaîne avec la suivante à faire
    if (skipped && guide.value.next) select(guide.value.next)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
function start() {
  guideLeave(step.value)
  router.push(step.value.to)
}
const stateOf = (s) => (s.done ? 'done' : s.skipped ? 'skipped' : 'todo')
</script>

<template>
  <div class="mx-auto max-w-5xl space-y-4">
    <section class="card p-4 sm:p-5">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold sm:text-xl">Bien démarrer avec Jibiat Marchand</h2>
          <p class="text-sm text-slate-600">Quelques réglages, une étape à la fois, pour que {{ auth.store?.name }} tourne au mieux. Vous pouvez passer une étape et y revenir quand vous voulez.</p>
        </div>
        <p v-if="guide" class="text-sm font-semibold tabular-nums text-emerald-800">{{ guide.done }} sur {{ guide.total }} étapes essentielles faites</p>
      </div>
      <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full bg-emerald-600 transition-all" :style="{ width: `${percent}%` }" />
      </div>
    </section>

    <Alert v-if="error">{{ error }}</Alert>

    <Alert v-if="finished" tone="success">
      Bravo, l'essentiel est en place. Les étapes facultatives ci-dessous vous aident à aller plus loin.
    </Alert>

    <div v-if="guide" class="grid gap-4 lg:grid-cols-[18rem_1fr]">
      <!-- Étape en cours -->
      <section v-if="step" class="card order-1 p-5 sm:p-6 lg:order-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Étape {{ index + 1 }} · {{ guide.phases[step.phase] }}</p>
        <div class="mt-1 flex flex-wrap items-center gap-2">
          <h3 class="text-xl font-semibold">{{ step.title }}</h3>
          <span v-if="step.done" class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800"><Icon name="check" :size="12" />Fait</span>
          <span v-else-if="step.skipped" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600">Passée</span>
          <span v-if="step.optional" class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-800">Facultatif</span>
        </div>

        <div class="mt-4 rounded-xl bg-emerald-50 p-4 text-emerald-900">
          <p class="flex items-center gap-1.5 text-sm font-semibold"><Icon name="lightbulb" :size="16" />Pourquoi c'est utile</p>
          <p class="mt-1 text-[15px]">{{ step.why }}</p>
        </div>

        <p class="mt-5 text-sm font-semibold text-slate-800">Ce qu'il faut faire</p>
        <ul class="mt-2 space-y-2">
          <li v-for="(item, i) in step.todo" :key="i" class="flex items-start gap-2.5 text-[15px] text-slate-700">
            <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ i + 1 }}</span>{{ item }}
          </li>
        </ul>

        <div class="mt-6 flex flex-wrap items-center gap-2">
          <button type="button" class="btn-brand" @click="start">{{ step.done ? 'Voir ou modifier' : step.action }}<Icon name="chevron" :size="16" /></button>
          <button v-if="!step.done && !step.skipped" type="button" class="btn-ghost" :disabled="busy" @click="setSkipped(true)">Passer pour l'instant</button>
          <button v-if="step.skipped" type="button" class="btn-ghost" :disabled="busy" @click="setSkipped(false)">Reprendre cette étape</button>
        </div>
        <p v-if="!step.done" class="mt-2 text-xs text-slate-500">L'étape se coche toute seule une fois le réglage fait.</p>

        <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-4">
          <button type="button" class="btn-secondary px-3 py-1.5" :disabled="index <= 0" @click="go(-1)">← Précédente</button>
          <button type="button" class="btn-secondary px-3 py-1.5" :disabled="index >= steps.length - 1" @click="go(1)">Suivante →</button>
        </div>
      </section>

      <!-- Toutes les étapes, par thème -->
      <nav class="card order-2 p-3 lg:order-1" aria-label="Étapes du guide">
        <div v-for="p in phases" :key="p.key" class="mb-2 last:mb-0">
          <p class="px-2 pt-2 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ p.label }}</p>
          <button v-for="s in p.steps" :key="s.key" type="button" class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left text-sm"
            :class="s.key === current ? 'bg-emerald-50 font-semibold text-emerald-900' : 'hover:bg-slate-50'" :aria-current="s.key === current ? 'step' : undefined" @click="select(s.key)">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold"
              :class="{ done: 'bg-emerald-600 text-white', skipped: 'border border-dashed border-slate-400 text-slate-400', todo: 'border border-slate-300 text-slate-500' }[stateOf(s)]">
              <Icon v-if="s.done" name="check" :size="13" /><template v-else>{{ steps.indexOf(s) + 1 }}</template>
            </span>
            <span class="min-w-0 flex-1" :class="{ 'text-slate-400 line-through decoration-slate-300': s.skipped }">{{ s.title }}</span>
            <span v-if="s.optional && !s.done" class="text-[11px] text-slate-400">facultatif</span>
          </button>
        </div>
      </nav>
    </div>

    <p class="text-center text-sm text-slate-500"><RouterLink to="/gestion" class="underline">Retour à l'accueil</RouterLink></p>
  </div>
</template>
