<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'

const data = ref(null)
const error = ref('')
const saved = ref(null)

// Gratuité pour tous : offre donnée, date de fin facultative, bandeau, délai donné à la fin
const free = ref(null)
const freeError = ref('')
const freeSaved = ref('')

onMounted(async () => {
  try {
    data.value = (await http.get('/console/plans')).data
    free.value = (await http.get('/console/free')).data.data
    free.value.plan ??= 'pro'
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function saveFree(enabled = free.value.enabled) {
  freeError.value = ''
  freeSaved.value = ''
  if (!enabled && free.value.active && !window.confirm(`Arrêter la gratuité ? Chaque espace marchand qui ne paie pas gardera l'offre ${free.value.plan} pendant ${free.value.grace_days} jours, puis devra choisir une offre.`)) return
  try {
    free.value = (await http.put('/console/free', {
      enabled, plan: free.value.plan, until: free.value.until || null, banner: free.value.banner || null, grace_days: Number(free.value.grace_days),
    })).data.data
    freeSaved.value = enabled ? 'Gratuité en place ✓' : 'Gratuité arrêtée ✓'
  } catch (e) {
    freeError.value = errorMessage(e)
  }
}

async function save(plan) {
  error.value = ''
  saved.value = null
  try {
    const { data: res } = await http.put(`/console/plans/${plan.id}`, {
      name: plan.name, monthly_price: Number(plan.monthly_price), yearly_price: plan.yearly_price === '' || plan.yearly_price === null ? null : Number(plan.yearly_price),
      weekly_price: plan.weekly_price === '' || plan.weekly_price === null ? null : Number(plan.weekly_price),
      limits: Object.fromEntries(Object.keys(data.value.limits).map((k) => [k, plan.limits[k] === '' || plan.limits[k] === null || plan.limits[k] === undefined ? null : Number(plan.limits[k])])),
      features: Object.fromEntries(Object.keys(data.value.features).map((k) => [k, !!plan.features?.[k]])),
      is_public: plan.is_public,
    })
    Object.assign(plan, res.data)
    saved.value = plan.id
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <section v-if="free" class="card space-y-3 p-4 text-sm" :class="free.active ? 'border-emerald-600 ring-1 ring-emerald-600' : ''">
      <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-base font-semibold">Jibiat Marchand gratuit pour tous</h2>
        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="free.active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600'">{{ free.active ? 'En cours' : 'Arrêtée' }}</span>
      </div>
      <p class="text-slate-600">
        Tous les espaces marchands ont l'offre choisie sans payer : pas de fin d'essai ni de relance. Un abonnement payé plus élevé est gardé.
        Les limites de l'offre restent appliquées ; l'assistant IA reste payant (recharges). À l'arrêt, chaque espace marchand qui ne paie pas garde l'offre pendant le délai, puis choisit la sienne.
      </p>
      <Alert v-if="freeError">{{ freeError }}</Alert>
      <div class="grid gap-3 sm:grid-cols-3">
        <label>Offre donnée
          <select v-model="free.plan" class="input"><option v-for="p in data?.data.filter((x) => x.code !== 'free')" :key="p.code" :value="p.code">{{ p.name }}</option></select>
        </label>
        <label>Jusqu'au (facultatif)<input v-model="free.until" type="date" class="input" /></label>
        <label>Délai à l'arrêt (jours)<input v-model="free.grace_days" type="number" min="1" max="90" class="input" /></label>
      </div>
      <label class="block">Bandeau affiché aux marchands (facultatif)<input v-model="free.banner" class="input" maxlength="160" placeholder="ex. Jibiat Marchand est gratuit en ce moment 🎉" /></label>
      <div class="flex flex-wrap items-center gap-2">
        <template v-if="free.active">
          <button class="btn-brand" @click="saveFree(true)">Enregistrer</button>
          <button class="btn-ghost text-red-600" @click="saveFree(false)">Arrêter la gratuité</button>
        </template>
        <button v-else class="btn-brand" :disabled="!free.plan" @click="saveFree(true)">Rendre gratuit pour tous</button>
        <span v-if="freeSaved" class="text-emerald-700">{{ freeSaved }}</span>
        <span class="ml-auto text-xs text-slate-500">{{ free.forever_count }} espace(s) marchand(s) « gratuit à vie »</span>
      </div>
    </section>

    <p class="text-sm text-slate-600">Prix en FCFA (multiples de 5). Limite vide : illimitée. Les changements s'appliquent aussitôt à tous les espaces marchands de l'offre.</p>
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="data" class="grid gap-4 lg:grid-cols-2">
      <section v-for="plan in data.data" :key="plan.id" class="card space-y-3 p-4 text-sm">
        <div class="flex items-center gap-2">
          <input v-model="plan.name" class="input w-40 font-semibold" />
          <code class="text-xs text-slate-500">{{ plan.code }}</code>
          <label class="ml-auto flex items-center gap-1"><input v-model="plan.is_public" type="checkbox" class="accent-emerald-700" /> Proposée</label>
        </div>
        <div class="grid grid-cols-3 gap-2">
          <label>Par semaine <input v-model="plan.weekly_price" type="number" min="0" step="5" class="input" placeholder="non" /></label>
          <label>Par mois <input v-model="plan.monthly_price" type="number" min="0" step="5" class="input" /></label>
          <label>Par an <input v-model="plan.yearly_price" type="number" min="0" step="5" class="input" /></label>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <label v-for="(label, key) in data.limits" :key="key" class="text-xs text-slate-600">{{ label }}<input v-model="plan.limits[key]" type="number" min="0" class="input" placeholder="illimité" /></label>
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-1">
          <label v-for="(label, key) in data.features" :key="key" class="flex items-center gap-1"><input :checked="plan.features?.[key]" type="checkbox" class="accent-emerald-700" @change="plan.features = { ...(Array.isArray(plan.features) ? {} : plan.features), [key]: $event.target.checked }" /> {{ label }}</label>
        </div>
        <div class="flex items-center gap-2"><button class="btn-brand" @click="save(plan)">Enregistrer</button><span v-if="saved === plan.id" class="text-emerald-700">Enregistré ✓</span></div>
      </section>
    </div>
  </div>
</template>
