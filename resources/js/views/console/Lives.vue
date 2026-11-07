<script setup>
import { onMounted, reactive, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

// Lives payants (à part des abonnements) : prix, lives offerts au départ, actions IA offertes, interrupteur ; ventes du mois
const data = ref(null)
const form = reactive({ paid: false, day_price: 500, month_price: 7000, free_lives: 10, free_ai: 20 })
const error = ref('')
const saved = ref(false)
const busy = ref(false)

async function load() {
  try {
    data.value = (await http.get('/console/lives')).data.data
    Object.assign(form, { paid: data.value.paid, day_price: data.value.day_price, month_price: data.value.month_price, free_lives: data.value.free_lives, free_ai: data.value.free_ai })
  } catch (e) {
    error.value = errorMessage(e)
  }
}
onMounted(load)

async function save() {
  if (form.paid && !data.value.paid && !window.confirm('Allumer les lives payants ? Chaque boutique garde ses lives offerts, puis paie 24 h ou 30 jours.')) return
  busy.value = true
  error.value = ''
  try {
    data.value = (await http.put('/console/lives', { ...form })).data.data
    saved.value = true
    setTimeout(() => (saved.value = false), 2000)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl space-y-4">
    <Alert v-if="error">{{ error }}</Alert>
    <template v-if="data">
      <Alert v-if="!data.provider_ready" tone="warning">PayDunya n'est pas prêt : ses clés ne sont pas sur le serveur. Les lives ne peuvent pas encore être payés.</Alert>
      <section class="card space-y-3 p-4">
        <label class="flex items-center gap-3 text-[15px] font-bold">
          <input v-model="form.paid" type="checkbox" class="h-5 w-5" />
          Lives payants
          <span class="text-sm font-normal text-slate-500">(éteint : les lives restent gratuits pour tous)</span>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-semibold">Lives pendant 24 h (F)<input v-model.number="form.day_price" type="number" min="100" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Lives illimités 30 jours (F)<input v-model.number="form.month_price" type="number" min="100" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Lives offerts au départ<input v-model.number="form.free_lives" type="number" min="0" class="input mt-1" /></label>
          <label class="text-sm font-semibold">Actions IA offertes au 1er live<input v-model.number="form.free_ai" type="number" min="0" class="input mt-1" /></label>
        </div>
        <p class="text-sm text-slate-500">Paiement par PayDunya (Orange Money, MTN, Moov, Wave, carte), au moment de démarrer. Lives offerts à une boutique : sur sa fiche.</p>
        <button type="button" class="btn-brand" :disabled="busy" @click="save">{{ saved ? '✓ Enregistré' : 'Enregistrer' }}</button>
      </section>
      <section class="card p-4">
        <h3 class="font-bold">Ce mois-ci</h3>
        <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
          <div class="rounded-xl bg-slate-50 p-3">Pass 24 h<b class="block text-lg">{{ data.month.day.count }}</b>{{ money(data.month.day.total) }}</div>
          <div class="rounded-xl bg-slate-50 p-3">30 jours<b class="block text-lg">{{ data.month.month.count }}</b>{{ money(data.month.month.total) }}</div>
          <div class="rounded-xl bg-slate-50 p-3">Lives offerts<b class="block text-lg">{{ data.month.offered }}</b>&nbsp;</div>
        </div>
      </section>
    </template>
  </div>
</template>
