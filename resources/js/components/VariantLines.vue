<script setup>
import { ref } from 'vue'
import http from '../lib/http'

// Lignes d'articles (réception, transfert) : recherche d'une variante puis quantité
const props = defineProps({
  lines: { type: Array, required: true }, // [{ variant_id, title, quantity, unit_cost?, available? }]
  showCost: { type: Boolean, default: false },
  locationId: { type: Number, default: null },
})

const search = ref('')
const results = ref([])
let timer

function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!search.value.trim()) return (results.value = [])
    const { data } = await http.get('/stock/variants', { params: { search: search.value, location_id: props.locationId ?? undefined } })
    results.value = data.data.filter((v) => !props.lines.some((l) => l.variant_id === v.id))
  }, 250)
}

function add(variant) {
  props.lines.push({ variant_id: variant.id, title: variant.title, quantity: 1, unit_cost: variant.cost_price ?? '', available: variant.available })
  results.value = []
  search.value = ''
}
</script>

<template>
  <div class="space-y-2">
    <input v-model="search" class="input" placeholder="Ajouter un article : nom, SKU ou code-barres" @input="find" />
    <ul v-if="results.length" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
      <li v-for="v in results" :key="v.id">
        <button type="button" class="flex w-full justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-emerald-50" @click="add(v)">
          <span>+ {{ v.title }} <span class="text-slate-400">{{ v.sku }}</span></span>
          <span class="text-slate-500">{{ v.available }} dispo.</span>
        </button>
      </li>
    </ul>
    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
      <li v-for="(line, index) in lines" :key="line.variant_id" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
        <span class="min-w-0 flex-1">{{ line.title }}<span v-if="line.available !== undefined && !showCost" class="text-slate-400"> · {{ line.available }} dispo.</span></span>
        <label class="flex items-center gap-1 text-slate-500">Qté <input v-model.number="line.quantity" type="number" min="1" class="input w-20 py-1.5" required /></label>
        <label v-if="showCost" class="flex items-center gap-1 text-slate-500">Coût/u <input v-model="line.unit_cost" type="number" min="0" class="input w-28 py-1.5" /></label>
        <button type="button" class="btn-ghost px-1.5 py-0.5 text-red-600" @click="lines.splice(index, 1)">✕</button>
      </li>
      <li v-if="!lines.length" class="px-3 py-2 text-sm text-slate-500">Aucun article.</li>
    </ul>
  </div>
</template>
