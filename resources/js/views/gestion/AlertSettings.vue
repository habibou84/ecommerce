<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Alert from '../../components/Alert.vue'
import http, { errorMessage } from '../../lib/http'

// Alertes sur les commandes qui attendent : chacune activée ou non, avec son délai ; enregistré à chaque changement
const settings = ref(null)
const error = ref('')
const saved = ref('')
const HOURS = Array.from({ length: 37 }, (_, i) => `${String(5 + Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`)
const DELAYS = [15, 30, 45, 60, 90, 120, 180, 240]
const delayLabel = (m) => (m < 60 ? `${m} min` : `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60}` : ''}`)
const hourLabel = (t) => t.replace(':', ' h ')

const ALERTS = [
  { key: 'morning', title: 'Le point du matin', text: 'Au début des alertes : commandes à livrer aujourd\'hui, sans livreur, en retard, date à fixer.' },
  { key: 'confirm', title: 'Commandes à confirmer qui attendent', text: 'Une commande attend d\'être confirmée depuis plus de', delay: true },
  { key: 'callback', title: 'Rappels de clients', text: 'À l\'heure où vous aviez prévu de rappeler un client.' },
  { key: 'unassigned', title: 'Commandes confirmées sans livreur', text: 'Le jour de la livraison, une commande confirmée n\'est confiée à personne depuis plus de', delay: true, escalate: true },
  { key: 'cutoff', title: 'Heure limite du jour', text: 'À votre heure limite, les commandes du jour pas encore parties chez un livreur.' },
  { key: 'pickup', title: 'Colis pas ramassés', text: 'La course est demandée mais personne n\'est venu chercher le colis depuis plus de', delay: true, escalate: true },
  { key: 'unhandled', title: 'Problèmes de livraison sans suite', text: 'Un échec ou une décision demandée par le livreur reste sans action depuis plus de', delay: true, escalate: true },
  { key: 'stalled', title: 'Livraisons qui traînent', text: 'Colis encore en route (ni livrés ni en échec) à', time: true },
]

// Argent et colis chez les livreurs : délai en heures ou en jours
const OUTSIDE = [
  { key: 'courier_cash', field: 'hours', title: 'Argent gardé par un livreur', text: 'Un livreur n\'a pas versé l\'argent encaissé depuis plus de', options: [6, 12, 24, 48, 72], unit: 'h',
    note: 'Sur l\'accueil : en orange, puis en rouge à deux fois ce délai ; le propriétaire est alors prévenu.' },
  { key: 'carrier_payout', field: 'days', title: 'Reversement en retard', text: 'Une entreprise de livraison n\'a pas reversé des courses livrées depuis plus de', options: [2, 3, 5, 7, 10, 14, 30], unit: 'j',
    note: 'Sur l\'accueil : en orange, puis en rouge à deux fois ce délai ; le propriétaire est alors prévenu.' },
  { key: 'returns', field: 'hours', title: 'Colis pas rapporté', text: 'Après une livraison ratée ou un retour, le colis est encore chez le livreur depuis plus de', options: [24, 48, 72, 96], unit: 'h',
    note: 'Toujours pas rapporté après trois fois ce délai : le propriétaire est prévenu.' },
]
const outsideLabel = (value, unit) => (unit === 'j' ? `${value} jours` : value >= 48 && value % 24 === 0 ? `${value / 24} jours` : `${value} h`)

onMounted(async () => {
  try {
    settings.value = (await http.get('/alert-settings')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function save() {
  error.value = ''
  saved.value = ''
  try {
    const { cutoff_time: _cutoff, ...payload } = settings.value
    settings.value = (await http.put('/alert-settings', payload)).data.data
    saved.value = 'Enregistré.'
  } catch (e) {
    error.value = errorMessage(e)
  }
}
function toggle(key) {
  settings.value[key].on = !settings.value[key].on
  save()
}
</script>

<template>
  <div class="mx-auto max-w-3xl space-y-4">
    <div>
      <h2 class="text-lg font-semibold">Alertes sur les commandes, l'argent et les colis</h2>
      <p class="text-sm text-slate-600">
        Elles arrivent dans la cloche et sur le téléphone des personnes qui gèrent les commandes et les livraisons. Une seule alerte regroupée pour plusieurs commandes ; un appui ouvre la liste déjà filtrée.
      </p>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <template v-if="settings">
      <section class="card space-y-3 p-4 sm:p-5">
        <h3 class="font-semibold">Heures des alertes</h3>
        <p class="text-sm text-slate-600">Pas d'alerte en dehors de ces heures : ce qui attend est repris dans le point du matin.</p>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <label for="alerts-from" class="font-medium">De</label>
          <select id="alerts-from" v-model="settings.hours.from" class="input w-auto" @change="save">
            <option v-for="t in HOURS" :key="t" :value="t">{{ hourLabel(t) }}</option>
          </select>
          <label for="alerts-to" class="font-medium">à</label>
          <select id="alerts-to" v-model="settings.hours.to" class="input w-auto" @change="save">
            <option v-for="t in HOURS" :key="t" :value="t">{{ hourLabel(t) }}</option>
          </select>
        </div>
      </section>

      <section class="card divide-y divide-slate-100">
        <div v-for="a in ALERTS" :key="a.key" class="flex items-start gap-3 p-4 sm:px-5">
          <div class="min-w-0 flex-1">
            <p class="font-medium" :class="{ 'text-slate-400': !settings[a.key].on }">{{ a.title }}</p>
            <p class="mt-0.5 text-sm text-slate-600">
              {{ a.text }}
              <select v-if="a.delay" v-model.number="settings[a.key].minutes" class="input ml-1 inline-block w-auto py-1 text-sm" :aria-label="`Délai : ${a.title}`" :disabled="!settings[a.key].on" @change="save">
                <option v-for="m in DELAYS" :key="m" :value="m">{{ delayLabel(m) }}</option>
              </select>
              <select v-if="a.time" v-model="settings.stalled.time" class="input ml-1 inline-block w-auto py-1 text-sm" aria-label="Heure de fin des livraisons" :disabled="!settings.stalled.on" @change="save">
                <option v-for="t in HOURS" :key="t" :value="t">{{ hourLabel(t) }}</option>
              </select>
              <template v-if="a.key === 'cutoff'"> Aujourd'hui : {{ hourLabel(settings.cutoff_time) }} (<RouterLink to="/gestion/livraisons/zones" class="text-emerald-700 underline">changer</RouterLink>).</template>
            </p>
            <p v-if="a.escalate && settings[a.key].on" class="mt-0.5 text-xs text-slate-500">Toujours en attente après trois fois ce délai : le propriétaire est prévenu.</p>
          </div>
          <button type="button" role="switch" :aria-checked="settings[a.key].on" :aria-label="a.title" class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition" :class="settings[a.key].on ? 'bg-emerald-600' : 'bg-slate-300'" @click="toggle(a.key)">
            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition" :class="settings[a.key].on ? 'left-[22px]' : 'left-0.5'" />
          </button>
        </div>
      </section>
      <h3 class="pt-2 font-semibold">Argent et colis chez les livreurs</h3>
      <p class="-mt-2 text-sm text-slate-600">Pour les personnes qui gèrent les livreurs et les entreprises de livraison. Les mêmes délais servent à l'accueil.</p>
      <section class="card divide-y divide-slate-100">
        <div v-for="a in OUTSIDE" :key="a.key" class="flex items-start gap-3 p-4 sm:px-5">
          <div class="min-w-0 flex-1">
            <p class="font-medium" :class="{ 'text-slate-400': !settings[a.key].on }">{{ a.title }}</p>
            <p class="mt-0.5 text-sm text-slate-600">
              {{ a.text }}
              <select v-model.number="settings[a.key][a.field]" class="input ml-1 inline-block w-auto py-1 text-sm" :aria-label="`Délai : ${a.title}`" :disabled="!settings[a.key].on" @change="save">
                <option v-for="v in a.options" :key="v" :value="v">{{ outsideLabel(v, a.unit) }}</option>
              </select>
            </p>
            <p v-if="settings[a.key].on" class="mt-0.5 text-xs text-slate-500">{{ a.note }}</p>
          </div>
          <button type="button" role="switch" :aria-checked="settings[a.key].on" :aria-label="a.title" class="relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition" :class="settings[a.key].on ? 'bg-emerald-600' : 'bg-slate-300'" @click="toggle(a.key)">
            <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition" :class="settings[a.key].on ? 'left-[22px]' : 'left-0.5'" />
          </button>
        </div>
      </section>
      <p v-if="saved" class="text-sm text-emerald-800">{{ saved }}</p>
    </template>
  </div>
</template>
