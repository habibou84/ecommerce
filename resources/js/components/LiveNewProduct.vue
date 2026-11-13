<script setup>
import { computed, onUnmounted, ref } from 'vue'
import http, { errorMessage, fieldErrors } from '../lib/http'
import { combinations } from '../lib/format'
import { aiExhausted, countAiAction } from '../lib/aiQuota'
import { useAuthStore } from '../stores/auth'
import AiMeter from './AiMeter.vue'

/**
 * « Choisissez vos articles » › « Nouvel article » : un article qui n'est pas encore au catalogue. La photo d'abord
 * (obligatoire : les clientes commandent sur la photo), l'assistant IA propose le nom, la description et les tailles
 * ou couleurs, la marchande met le prix et les pièces. L'article est créé au catalogue puis ajouté au live.
 */
const emit = defineEmits(['created', 'cancel'])
const auth = useAuthStore()
const ai = computed(() => auth.profile?.ai ?? { enabled: false, used: 0, limit: 0 })
const aiReady = computed(() => !!ai.value.enabled && !aiExhausted(ai.value))

const file = ref(null)
const preview = ref('')
const form = ref({ title: '', description: '', price: '', quantity: 1, tags: [], collection_ids: [] })
const options = ref([]) // [{ name: 'Taille', text: 'S, M, L' }]
const error = ref('')
const errors = ref({})
const drafting = ref(false)
const drafted = ref('')
const saving = ref(false)

function pick(event) {
  const chosen = event.target.files?.[0]
  event.target.value = ''
  if (!chosen) return
  if (preview.value) URL.revokeObjectURL(preview.value)
  file.value = chosen
  preview.value = URL.createObjectURL(chosen)
  error.value = ''
  if (aiReady.value && !form.value.title) draft()
}
onUnmounted(() => preview.value && URL.revokeObjectURL(preview.value))

async function draft() {
  if (!file.value) return
  drafting.value = true
  drafted.value = ''
  error.value = ''
  try {
    const body = new FormData()
    body.append('photos[]', file.value)
    const d = (await http.post('/products/draft', body)).data.data
    Object.assign(form.value, { title: d.title, description: d.description, tags: d.tags ?? [], collection_ids: d.collection_ids ?? [] })
    options.value = (d.options ?? []).map((o) => ({ name: o.name, text: o.values.join(', ') }))
    drafted.value = `L'IA a rempli la fiche : vérifie le nom${options.value.length ? ' et les tailles / couleurs' : ''}, puis mets le prix. ${d.to_check ?? ''}`.trim()
    countAiAction(auth.profile?.ai)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    drafting.value = false
  }
}

const activeOptions = computed(() => options.value
  .map((o) => ({ name: o.name.trim(), values: [...new Set(o.text.split(',').map((v) => v.trim()).filter(Boolean))] }))
  .filter((o) => o.name && o.values.length))
const combos = computed(() => combinations(activeOptions.value.map((o) => o.values)))
const tooMany = computed(() => combos.value.length > 40)
const addOption = () => options.value.push({ name: options.value.length ? 'Couleur' : 'Taille', text: '' })

async function save() {
  errors.value = {}
  error.value = ''
  if (!file.value) return (error.value = 'Prends d\'abord la photo de l\'article.')
  if (!form.value.title.trim()) return (errors.value = { title: 'Indique le nom de l\'article.' })
  if (form.value.price === '' || Number(form.value.price) < 0) return (errors.value = { price: 'Indique le prix.' })
  if (tooMany.value) return (error.value = 'Trop de tailles et de couleurs : 40 variantes au plus.')
  saving.value = true
  const variant = (combo) => ({ options: combo, price: Number(form.value.price), on_hand: Math.max(0, Number(form.value.quantity) || 0) })
  try {
    const { data } = await http.post('/products', {
      title: form.value.title.trim(), description: form.value.description, status: 'active',
      tags: form.value.tags, collection_ids: form.value.collection_ids,
      options: activeOptions.value,
      variants: activeOptions.value.length ? combos.value.map(variant) : [variant([])],
    })
    const body = new FormData()
    body.append('image', file.value)
    await http.post(`/products/${data.data.id}/images`, body)
    emit('created', { id: data.data.id, title: data.data.title, quantity: Math.max(1, Number(form.value.quantity) || 1) })
  } catch (e) {
    errors.value = fieldErrors(e)
    error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="space-y-3">
    <p v-if="error" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

    <label class="relative flex cursor-pointer flex-col items-center justify-center overflow-hidden rounded-2xl border-2 border-dashed text-center"
           :class="preview ? 'border-emerald-300' : 'border-field bg-slate-50 py-8'">
      <input type="file" accept="image/*" class="sr-only" @change="pick" />
      <template v-if="preview">
        <img :src="preview" alt="Photo de l'article" class="max-h-64 w-full object-contain" />
        <span class="absolute bottom-2 right-2 rounded-lg bg-white/90 px-2 py-1 text-xs font-bold text-slate-700 shadow">📷 Changer</span>
      </template>
      <template v-else>
        <span class="text-4xl" aria-hidden="true">📷</span>
        <b class="mt-1 text-[16px]">Prendre la photo</b>
        <span class="text-xs text-slate-500">Obligatoire : vos clientes commandent sur la photo</span>
      </template>
    </label>

    <AiMeter v-if="ai.enabled && !aiReady" />
    <button v-if="file && ai.enabled" type="button" class="w-full rounded-xl bg-violet-50 py-2.5 text-sm font-bold text-violet-700 disabled:opacity-60" :disabled="drafting || !aiReady" @click="draft">
      {{ drafting ? '✨ L\'IA regarde la photo…' : '✨ Remplir avec l\'IA' }}
    </button>
    <p v-if="drafted" class="rounded-xl bg-violet-50 p-3 text-sm text-violet-800">{{ drafted }}</p>

    <label class="block text-sm font-semibold">Nom
      <input v-model="form.title" class="input mt-1" placeholder="Ex. Robe wax évasée" />
      <span v-if="errors.title" class="text-xs text-red-600">{{ errors.title }}</span>
    </label>
    <div class="grid grid-cols-2 gap-3">
      <label class="block text-sm font-semibold">Prix (F)
        <input v-model="form.price" type="number" min="0" inputmode="numeric" class="input mt-1" placeholder="15000" />
        <span v-if="errors.price || errors['variants.0.price']" class="text-xs text-red-600">{{ errors.price || errors['variants.0.price'] }}</span>
      </label>
      <label class="block text-sm font-semibold">{{ activeOptions.length ? 'Pièces par variante' : 'Pièces' }}
        <input v-model="form.quantity" type="number" min="0" inputmode="numeric" class="input mt-1" />
      </label>
    </div>

    <div class="space-y-2">
      <div v-for="(o, index) in options" :key="index" class="flex items-end gap-2">
        <label class="w-28 shrink-0 text-sm font-semibold">Option<input v-model="o.name" class="input mt-1" /></label>
        <label class="min-w-0 flex-1 text-sm font-semibold">Valeurs (S, M, L…)<input v-model="o.text" class="input mt-1" placeholder="séparées par des virgules" /></label>
        <button type="button" class="mb-2 px-1 text-red-600" aria-label="Retirer l'option" @click="options.splice(index, 1)">✕</button>
      </div>
      <button v-if="options.length < 3" type="button" class="text-sm font-semibold text-emerald-700" @click="addOption">＋ Tailles ou couleurs</button>
      <p v-if="activeOptions.length" class="text-xs text-slate-500">{{ combos.length }} variante(s), toutes sous le même code dans le live.</p>
    </div>

    <div class="flex gap-2 pt-1">
      <button type="button" class="btn-secondary flex-1 justify-center" @click="emit('cancel')">Annuler</button>
      <button type="button" class="btn-brand flex-[2] justify-center" :disabled="saving || drafting || !file" @click="save">{{ saving ? 'Création…' : 'Créer et ajouter au live' }}</button>
    </div>
  </div>
</template>
