<script setup>
import { onMounted, ref } from 'vue'
import Field from '../Field.vue'
import Modal from '../Modal.vue'
import http, { errorMessage } from '../../lib/http'

/**
 * Retour de la connexion Facebook : choix du compte Business, du pixel et du catalogue (existants ou à créer),
 * puis branchement. Utilisé par « Suivi publicitaire » et par le menu Boost.
 *
 * @prop metaKey       clé de la connexion en attente (?meta=…)
 * @prop currentPixel  pixel déjà saisi sur la boutique, proposé d'abord
 */
const props = defineProps({ metaKey: { type: String, required: true }, currentPixel: { type: String, default: null } })
const emit = defineEmits(['done', 'close'])
const setup = ref(null)
const busy = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    setup.value = { businesses: (await http.get(`/marketing/tracking/meta/${props.metaKey}`)).data.data.businesses, business: '', assets: null, pixel: 'new', catalog: 'new' }
    if (setup.value.businesses.length === 1) await chooseBusiness(setup.value.businesses[0].id)
  } catch (e) {
    error.value = errorMessage(e)
  }
})

async function chooseBusiness(id) {
  busy.value = true
  error.value = ''
  setup.value.business = id
  setup.value.assets = null
  try {
    const assets = (await http.get(`/marketing/tracking/meta/${props.metaKey}`, { params: { business: id } })).data.data.assets
    // Le pixel déjà saisi, sinon le seul existant, sinon un nouveau ; idem pour le catalogue
    const pick = (list, current) => list.find((x) => x.id === current)?.id ?? (list.length === 1 ? list[0].id : 'new')
    Object.assign(setup.value, { assets, pixel: pick(assets.pixels, props.currentPixel), catalog: pick(assets.catalogs, null) })
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}

async function finish() {
  busy.value = true
  error.value = ''
  try {
    emit('done', (await http.post(`/marketing/tracking/meta/${props.metaKey}`, { business_id: setup.value.business, pixel_id: setup.value.pixel, catalog_id: setup.value.catalog })).data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal title="Brancher le pixel et le catalogue" @close="emit('close')">
    <div class="space-y-4 text-sm">
      <p v-if="error" class="rounded-xl bg-red-50 p-3 font-bold text-red-700">{{ error }}</p>
      <p v-if="!setup && !error" class="text-slate-500">Chargement…</p>
      <template v-if="setup">
        <Field id="meta-business" label="Compte Business">
          <select id="meta-business" class="input" :value="setup.business" :disabled="busy" @change="chooseBusiness($event.target.value)">
            <option value="" disabled>Choisissez…</option>
            <option v-for="b in setup.businesses" :key="b.id" :value="b.id">{{ b.name }}</option>
          </select>
        </Field>
        <template v-if="setup.assets">
          <Field id="meta-pixel-choice" label="Pixel">
            <select id="meta-pixel-choice" v-model="setup.pixel" class="input">
              <option value="new">Créer un nouveau pixel</option>
              <option v-for="p in setup.assets.pixels" :key="p.id" :value="p.id">{{ p.name }} ({{ p.id }})</option>
            </select>
          </Field>
          <Field id="meta-catalog-choice" label="Catalogue">
            <select id="meta-catalog-choice" v-model="setup.catalog" class="input">
              <option value="new">Créer un nouveau catalogue</option>
              <option v-for="c in setup.assets.catalogs" :key="c.id" :value="c.id">{{ c.name }}{{ c.products !== null ? ` (${c.products} article(s))` : '' }}</option>
            </select>
          </Field>
          <p class="text-slate-500">Tes produits seront ajoutés à ce catalogue et relus toutes les heures ; le pixel sera relié au catalogue.</p>
          <button type="button" class="btn-brand w-full" :disabled="busy" @click="finish">{{ busy ? 'Branchement…' : 'Brancher' }}</button>
        </template>
        <p v-else-if="busy" class="text-slate-500">Chargement des pixels et catalogues…</p>
      </template>
    </div>
  </Modal>
</template>
