<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
import DeliveryTabs from '../../components/DeliveryTabs.vue'
import Field from '../../components/Field.vue'
import Modal from '../../components/Modal.vue'
import PlacePicker from '../../components/PlacePicker.vue'
import http, { errorMessage, fieldErrors } from '../../lib/http'
import { money } from '../../lib/format'

const zones = ref([])
const editing = ref(null)
const picked = ref(null)
const errors = ref({})
const error = ref('')
const busy = ref(false)

async function load() {
  zones.value = (await http.get('/delivery-zones')).data.data
}
onMounted(load)

// Jours et heures de livraison : la première date possible proposée à chaque commande en dépend
const WEEKDAYS = [[1, 'Lun'], [2, 'Mar'], [3, 'Mer'], [4, 'Jeu'], [5, 'Ven'], [6, 'Sam'], [7, 'Dim']]
const CUTOFFS = Array.from({ length: 33 }, (_, i) => `${String(6 + Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`)
const calendar = ref(null)
const newClosedDate = ref('')
const calendarSaved = ref('')
onMounted(async () => {
  calendar.value = (await http.get('/delivery-calendar')).data.data
})
async function saveCalendar() {
  error.value = ''
  try {
    calendar.value = (await http.put('/delivery-calendar', {
      cutoff: calendar.value.cutoff, closed_weekdays: calendar.value.closed_weekdays, closed_dates: calendar.value.closed_dates,
    })).data.data
    calendarSaved.value = `Enregistré. Une commande saisie maintenant serait livrée ${new Date(`${calendar.value.earliest}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })}.`
  } catch (e) {
    error.value = errorMessage(e)
  }
}
function toggleWeekday(day) {
  const list = calendar.value.closed_weekdays
  calendar.value.closed_weekdays = list.includes(day) ? list.filter((d) => d !== day) : [...list, day]
  saveCalendar()
}
function addClosedDate() {
  if (newClosedDate.value && !calendar.value.closed_dates.includes(newClosedDate.value)) {
    calendar.value.closed_dates = [...calendar.value.closed_dates, newClosedDate.value].sort()
    saveCalendar()
  }
  newClosedDate.value = ''
}
function removeClosedDate(date) {
  calendar.value.closed_dates = calendar.value.closed_dates.filter((d) => d !== date)
  saveCalendar()
}

function open(zone) {
  errors.value = {}
  error.value = ''
  editing.value = zone ? { ...zone, places: [...zone.places] } : { name: '', fee: 1500, free_above: null, eta_label: 'Livré sous 24 h', is_active: true, places: [] }
}

function addPlace(place) {
  if (place && !editing.value.places.some((p) => p.id === place.id)) editing.value.places.push({ id: place.id, label: place.label })
  picked.value = null
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    const payload = { ...editing.value, free_above: editing.value.free_above || null, place_ids: editing.value.places.map((p) => p.id) }
    if (editing.value.id) await http.put(`/delivery-zones/${editing.value.id}`, payload)
    else await http.post('/delivery-zones', payload)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function destroy() {
  if (!confirm(`Supprimer la zone « ${editing.value.name} » ?`)) return
  await http.delete(`/delivery-zones/${editing.value.id}`)
  editing.value = null
  await load()
}
</script>

<template>
  <div class="space-y-4">
    <DeliveryTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">
        Frais de livraison facturés aux clients selon le quartier. Un quartier prend la zone qui le contient, sinon celle de sa commune, sinon celle de sa ville.
      </p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Zone</button>
    </div>

    <!-- Jours et heures de livraison -->
    <section v-if="calendar" class="card space-y-4 p-4 sm:p-5">
      <div>
        <h3 class="font-semibold">Jours et heures de livraison</h3>
        <p class="text-sm text-slate-600">Chaque commande reçoit d'office la première date possible : aujourd'hui avant l'heure limite, sinon le jour suivant, en sautant les jours sans livraison. Une entreprise de livraison qui a ses propres horaires (Delivery App) les fait passer en premier.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <label for="cutoff" class="font-medium">Livrée le jour même si commandée avant</label>
        <select id="cutoff" v-model="calendar.cutoff" class="input w-auto" @change="saveCalendar">
          <option v-for="t in CUTOFFS" :key="t" :value="t">{{ t.replace(':', ' h ') }}</option>
        </select>
      </div>
      <div>
        <p class="mb-1.5 text-sm font-medium">Jours sans livraison</p>
        <div class="flex flex-wrap gap-2">
          <button v-for="[day, label] in WEEKDAYS" :key="day" type="button" class="rounded-full border px-3 py-1.5 text-sm" :class="calendar.closed_weekdays.includes(day) ? 'border-red-300 bg-red-50 font-semibold text-red-800 line-through' : 'border-slate-300'" :aria-pressed="calendar.closed_weekdays.includes(day)" @click="toggleWeekday(day)">{{ label }}</button>
        </div>
      </div>
      <div>
        <p class="mb-1.5 text-sm font-medium">Dates sans livraison (jours fériés, fermeture)</p>
        <div class="flex flex-wrap items-center gap-2">
          <span v-for="d in calendar.closed_dates" :key="d" class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-sm">
            {{ new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) }}
            <button type="button" class="text-slate-500 hover:text-red-600" :aria-label="`Retirer le ${d}`" @click="removeClosedDate(d)">✕</button>
          </span>
          <input v-model="newClosedDate" type="date" class="input w-auto" aria-label="Date sans livraison" @change="addClosedDate" />
        </div>
      </div>
      <p v-if="calendarSaved" class="text-sm text-emerald-800">{{ calendarSaved }}</p>
    </section>

    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="z in zones" :key="z.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" :class="{ 'opacity-60': !z.is_active }" @click="open(z)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ z.name }}</span>
              <span class="block truncate text-slate-500">{{ z.places.map((p) => p.label).join(' · ') || 'Aucun lieu' }}</span>
            </span>
            <span class="text-right">
              <span class="block font-semibold">{{ money(z.fee) }}</span>
              <span v-if="z.free_above" class="block text-xs text-slate-500">offerte dès {{ money(z.free_above) }}</span>
            </span>
          </button>
        </li>
        <li v-if="!zones.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucune zone : les frais seront à saisir sur chaque commande.</li>
      </ul>
    </div>

    <Modal v-if="editing" :title="editing.id ? editing.name : 'Nouvelle zone'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="z-name" label="Nom" :error="errors.name"><input id="z-name" v-model="editing.name" class="input" placeholder="Abidjan nord" required /></Field>
        <div class="grid grid-cols-2 gap-3">
          <Field id="z-fee" label="Frais (F)" :error="errors.fee"><input id="z-fee" v-model.number="editing.fee" class="input" type="number" min="0" required /></Field>
          <Field id="z-free" label="Offerte dès (F)"><input id="z-free" v-model.number="editing.free_above" class="input" type="number" min="0" placeholder="—" /></Field>
        </div>
        <Field id="z-eta" label="Délai annoncé"><input id="z-eta" v-model="editing.eta_label" class="input" maxlength="60" /></Field>
        <div>
          <p class="label">Quartiers, communes ou villes</p>
          <PlacePicker id="z-place" :model-value="picked" @update:model-value="addPlace" />
          <div class="mt-2 flex flex-wrap gap-1.5">
            <span v-for="(p, i) in editing.places" :key="p.id" class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-2 py-1 text-sm">
              {{ p.label }} <button type="button" class="text-slate-400 hover:text-red-600" @click="editing.places.splice(i, 1)">✕</button>
            </span>
          </div>
          <p class="mt-1 text-xs text-slate-500">Un lieu ne peut appartenir qu'à une zone : l'ajouter ici le retire de l'autre.</p>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Active</label>
        <div class="flex gap-2">
          <button v-if="editing.id" type="button" class="btn-ghost text-red-600" @click="destroy">Supprimer</button>
          <button class="btn-brand ml-auto" :disabled="busy">Enregistrer</button>
        </div>
      </form>
    </Modal>
  </div>
</template>
