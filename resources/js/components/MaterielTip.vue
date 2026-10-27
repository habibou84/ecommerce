<script setup>
import { onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import http from '../lib/http'
import { money } from '../lib/format'
import { useAuthStore } from '../stores/auth'

// Suggestion discrète de matériel au bon endroit (étiquettes, live, caisse) ; fermée, elle ne revient plus sur cet appareil
const props = defineProps({ category: { type: String, required: true }, text: { type: String, required: true } })
const auth = useAuthStore()
const KEY = `materiel-tip-${props.category}`
const product = ref(null)

onMounted(async () => {
  let closed = false
  try { closed = localStorage.getItem(KEY) === '1' } catch {}
  if (closed || !auth.profile?.materiel || !auth.can('billing.manage')) return
  try {
    product.value = (await http.get('/materiel/suggest', { params: { category: props.category } })).data.data
  } catch {
    // suggestion seulement
  }
})

function close() {
  product.value = null
  try { localStorage.setItem(KEY, '1') } catch {}
}
</script>

<template>
  <div v-if="product" class="flex items-center gap-3 rounded-2xl bg-emerald-50 p-3 text-sm ring-1 ring-emerald-100">
    <img v-if="product.image" :src="product.image" alt="" class="h-12 w-12 shrink-0 rounded-xl object-cover" />
    <span v-else class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white text-2xl">🛍️</span>
    <span class="min-w-0 flex-1">
      <span class="block text-emerald-900">{{ text }}</span>
      <RouterLink to="/gestion/materiel" class="font-semibold text-emerald-800 underline">{{ product.name }} · {{ money(product.price) }}</RouterLink>
    </span>
    <button type="button" class="shrink-0 text-emerald-700" aria-label="Fermer" @click="close"><Icon name="close" :size="16" /></button>
  </div>
</template>
