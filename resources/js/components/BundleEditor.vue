<script setup>
import { onMounted, ref } from 'vue'
import Alert from './Alert.vue'
import VariantLines from './VariantLines.vue'
import http, { errorMessage } from '../lib/http'
import { money } from '../lib/format'

// Lot (pack) : articles contenus ; vendre le lot sort chaque article de son stock
const props = defineProps({ productId: { type: [String, Number], required: true } })
const emit = defineEmits(['changed'])
const lines = ref([])
const isBundle = ref(false)
const open = ref(false)
const error = ref('')
const saved = ref(false)

async function load() {
  const data = (await http.get(`/products/${props.productId}/bundle`)).data.data
  isBundle.value = data.is_bundle
  open.value = data.is_bundle
  lines.value = data.components.map((c) => ({ variant_id: c.variant_id, title: `${c.title} (${money(c.price)})`, quantity: c.quantity }))
}
onMounted(load)

async function save() {
  error.value = ''
  saved.value = false
  try {
    await http.put(`/products/${props.productId}/bundle`, { components: open.value ? lines.value.map((l) => ({ variant_id: l.variant_id, quantity: Number(l.quantity) })) : [] })
    saved.value = true
    await load()
    emit('changed')
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <section class="card space-y-3 p-4 sm:p-6">
    <label class="flex items-center gap-2 font-semibold"><input v-model="open" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Ce produit est un lot (pack)</label>
    <p class="text-sm text-slate-500">Ex. « Robe + sac ». Le lot n'a pas de stock à lui : le vendre sort chaque article de son stock, et il est disponible tant que tous ses articles le sont.</p>
    <template v-if="open">
      <Alert v-if="error">{{ error }}</Alert>
      <Alert v-if="saved" tone="success">Contenu du lot enregistré.</Alert>
      <VariantLines :lines="lines" />
      <button type="button" class="btn-secondary" @click="save">Enregistrer le contenu du lot</button>
    </template>
    <button v-else-if="isBundle" type="button" class="btn-ghost text-red-600" @click="save">Ce n'est plus un lot</button>
  </section>
</template>
