<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import Modal from '../../../components/Modal.vue'
import StockTabs from '../../../components/StockTabs.vue'
import http, { errorMessage, fieldErrors } from '../../../lib/http'

const locations = ref([])
const editing = ref(null)
const errors = ref({})
const error = ref('')
const busy = ref(false)

async function load() {
  locations.value = (await http.get('/stock/locations')).data.data
}
onMounted(load)

function open(location) {
  errors.value = {}
  error.value = ''
  editing.value = location
    ? { ...location }
    : { name: '', type: 'warehouse', address: '', landmark: '', phone: '', is_pickup_point: false, fulfills_online_orders: true, priority: locations.value.length, is_active: true }
}

async function save() {
  busy.value = true
  errors.value = {}
  try {
    if (editing.value.id) await http.put(`/stock/locations/${editing.value.id}`, editing.value)
    else await http.post('/stock/locations', editing.value)
    editing.value = null
    await load()
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-4">
    <StockTabs />
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Où se trouve votre marchandise : boutique, entrepôt, domicile… Le stock est suivi pour chacun.</p>
      <button class="btn-brand shrink-0" @click="open(null)">+ Emplacement</button>
    </div>
    <div class="card">
      <ul class="divide-y divide-slate-100">
        <li v-for="l in locations" :key="l.id">
          <button class="flex w-full items-center gap-3 px-4 py-3 text-left text-sm hover:bg-slate-50" :class="{ 'opacity-60': !l.is_active }" @click="open(l)">
            <span class="min-w-0 flex-1">
              <span class="block font-medium">{{ l.name }}</span>
              <span class="block text-slate-500">{{ l.type_label }}{{ l.address ? ` · ${l.address}` : '' }}{{ l.is_pickup_point ? ' · retrait possible' : '' }}</span>
            </span>
            <span class="text-right">
              <span class="block font-medium">{{ l.units }} article(s)</span>
              <span v-if="!l.is_active" class="block text-xs text-slate-500">Désactivé</span>
            </span>
          </button>
        </li>
      </ul>
    </div>

    <Modal v-if="editing" :title="editing.id ? editing.name : 'Nouvel emplacement'" @close="editing = null">
      <form class="space-y-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <Field id="l-name" label="Nom" :error="errors.name">
          <input id="l-name" v-model="editing.name" class="input" maxlength="80" placeholder="Entrepôt Yopougon" required />
        </Field>
        <Field id="l-type" label="Type">
          <select id="l-type" v-model="editing.type" class="input">
            <option value="shop">Boutique</option>
            <option value="warehouse">Entrepôt</option>
            <option value="home">Domicile</option>
          </select>
        </Field>
        <Field id="l-address" label="Adresse">
          <input id="l-address" v-model="editing.address" class="input" maxlength="500" />
        </Field>
        <Field id="l-landmark" label="Point de repère">
          <input id="l-landmark" v-model="editing.landmark" class="input" maxlength="200" />
        </Field>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.is_pickup_point" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Les clients peuvent y retirer leurs commandes</label>
        <label class="flex items-center gap-2 text-sm"><input v-model="editing.fulfills_online_orders" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Prépare les commandes en ligne</label>
        <label v-if="editing.id" class="flex items-center gap-2 text-sm"><input v-model="editing.is_active" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Actif</label>
        <p v-if="errors.is_active" class="text-sm text-red-600">{{ errors.is_active }}</p>
        <button class="btn-brand w-full" :disabled="busy">Enregistrer</button>
      </form>
    </Modal>
  </div>
</template>
