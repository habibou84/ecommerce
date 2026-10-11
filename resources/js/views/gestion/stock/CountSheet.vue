<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Alert from '../../../components/Alert.vue'
import StatusBadge from '../../../components/StatusBadge.vue'
import http, { errorMessage } from '../../../lib/http'

const props = defineProps({ id: { type: String, required: true } })
const router = useRouter()

const count = ref(null)
const search = ref('')
const error = ref('')
const saving = ref(false)
const dirty = new Map() // variant_id → quantité comptée, en attente d'enregistrement
let timer

onMounted(async () => {
  count.value = (await http.get(`/stock/counts/${props.id}`)).data.data
})

const open = computed(() => count.value?.status === 'in_progress')
const items = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (count.value?.items ?? []).filter((i) => !q || i.variant.toLowerCase().includes(q) || (i.sku ?? '').toLowerCase().includes(q))
})
const counted = computed(() => (count.value?.items ?? []).filter((i) => i.counted !== null && i.counted !== '').length)

// Enregistrement au fil du comptage (une perte de réseau ne fait pas tout perdre)
function onInput(item) {
  dirty.set(item.variant_id, item.counted === '' ? null : Number(item.counted))
  clearTimeout(timer)
  timer = setTimeout(flush, 800)
}

async function flush() {
  if (!dirty.size) return
  const items = [...dirty].map(([variant_id, counted]) => ({ variant_id, counted }))
  dirty.clear()
  try {
    await http.put(`/stock/counts/${props.id}/items`, { items })
  } catch (e) {
    items.forEach((i) => dirty.set(i.variant_id, i.counted))
    error.value = errorMessage(e)
  }
}

async function finish(action) {
  const message = action === 'complete'
    ? `Valider l'inventaire ? ${counted.value} article(s) compté(s) : leur stock sera remplacé par la quantité comptée.`
    : 'Annuler cet inventaire ? Rien ne sera modifié.'
  if (!confirm(message)) return
  saving.value = true
  try {
    await flush()
    await http.post(`/stock/counts/${props.id}/${action}`)
    router.push('/gestion/stock/inventaires')
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

const gap = (item) => (item.counted === null || item.counted === '' ? null : Number(item.counted) - item.expected)
</script>

<template>
  <div v-if="count" class="space-y-4 pb-20">
    <RouterLink to="/gestion/stock/inventaires" class="text-sm text-slate-500 hover:text-emerald-700">← Inventaires</RouterLink>
    <div class="flex flex-wrap items-center gap-2">
      <h2 class="text-lg font-semibold">Inventaire · {{ count.location }}</h2>
      <StatusBadge :status="count.status" :label="count.status_label" />
      <span class="text-sm text-slate-500">{{ counted }} / {{ count.items.length }} compté(s)</span>
    </div>
    <Alert v-if="error">{{ error }}</Alert>
    <input v-model="search" type="search" class="input" placeholder="Chercher un article (nom ou SKU)" />

    <div class="card divide-y divide-slate-100">
      <div v-for="item in items" :key="item.variant_id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
        <span class="min-w-0 flex-1">
          <span class="block">{{ item.variant }}</span>
          <span class="block text-xs text-slate-400">Attendu : {{ item.expected }}{{ item.sku ? ` · ${item.sku}` : '' }}</span>
        </span>
        <span v-if="gap(item) !== null && gap(item) !== 0" class="text-xs font-medium" :class="gap(item) < 0 ? 'text-red-600' : 'text-emerald-700'">
          {{ gap(item) > 0 ? '+' : '' }}{{ gap(item) }}
        </span>
        <input v-model="item.counted" type="number" min="0" inputmode="numeric" class="input w-24 text-right" :disabled="!open" placeholder="—" @input="onInput(item)" />
      </div>
      <p v-if="!items.length" class="px-4 py-6 text-center text-sm text-slate-500">Aucun article.</p>
    </div>

    <div v-if="open" class="fixed inset-x-0 bottom-0 z-10 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
      <div class="mx-auto flex max-w-5xl gap-2">
        <button class="btn-ghost text-red-600" :disabled="saving" @click="finish('cancel')">Annuler</button>
        <button class="btn-brand ml-auto" :disabled="saving || !counted" @click="finish('complete')">Valider l'inventaire</button>
      </div>
    </div>
  </div>
</template>
