<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'

const data = ref(null)
const error = ref('')
const saved = ref(null)

onMounted(async () => {
  try {
    data.value = (await http.get('/console/plans')).data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function save(plan) {
  error.value = ''
  saved.value = null
  try {
    const { data: res } = await http.put(`/console/plans/${plan.id}`, {
      name: plan.name, monthly_price: Number(plan.monthly_price), yearly_price: plan.yearly_price === '' || plan.yearly_price === null ? null : Number(plan.yearly_price),
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
    <p class="text-sm text-slate-600">Prix en FCFA (multiples de 5). Limite vide : illimitée. Les changements s'appliquent aussitôt à tous les espaces marchands de l'offre.</p>
    <Alert v-if="error">{{ error }}</Alert>
    <div v-if="data" class="grid gap-4 lg:grid-cols-2">
      <section v-for="plan in data.data" :key="plan.id" class="card space-y-3 p-4 text-sm">
        <div class="flex items-center gap-2">
          <input v-model="plan.name" class="input w-40 font-semibold" />
          <code class="text-xs text-slate-500">{{ plan.code }}</code>
          <label class="ml-auto flex items-center gap-1"><input v-model="plan.is_public" type="checkbox" class="accent-emerald-700" /> Proposée</label>
        </div>
        <div class="grid grid-cols-2 gap-2">
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
