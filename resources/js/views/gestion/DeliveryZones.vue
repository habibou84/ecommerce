<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../components/Alert.vue'
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
    <RouterLink to="/gestion/parametres" class="text-sm text-slate-500 hover:text-emerald-700">← Paramètres</RouterLink>
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">
        Frais de livraison facturés aux clients selon le quartier. Un quartier prend la zone qui le contient, sinon celle de sa commune, sinon celle de sa ville.
      </p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Zone</button>
    </div>

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
