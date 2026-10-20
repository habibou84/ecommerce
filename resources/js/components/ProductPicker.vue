<script setup>
import { ref } from 'vue'
import Icon from './Icon.vue'
import http from '../lib/http'
import { money } from '../lib/format'

// Ajout d'un article à une commande : recherche du produit, puis choix de la variante
const emit = defineEmits(['add'])

const search = ref('')
const results = ref([])
const choosing = ref(null) // produit à variantes dont on choisit la variante
let timer

function find() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    if (!search.value.trim()) return (results.value = [])
    results.value = (await http.get('/products', { params: { search: search.value, status: 'active', per_page: 8 } })).data.data
  }, 200)
}

async function pick(product) {
  const { data } = await http.get(`/products/${product.id}`)
  const variants = data.data.variants.filter((v) => v.is_active)
  if (variants.length === 1) {
    add(data.data, variants[0])
  } else {
    choosing.value = { ...data.data, variants }
  }
}

function add(product, variant) {
  emit('add', {
    variant_id: variant.id,
    title: product.title,
    variant_title: product.has_variants ? variant.title : null,
    unit_price: variant.price,
    quantity: 1,
    available: product.track_inventory ? variant.available : null,
    thumb: product.images[0]?.urls.thumb ?? null,
  })
  choosing.value = null
  search.value = ''
  results.value = []
}
</script>

<template>
  <div class="space-y-2">
    <div class="relative">
      <Icon name="search" :size="18" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
      <input v-model="search" type="search" class="input-lg pl-12" placeholder="Tapez le nom ou le code de l'article" @input="find" />
    </div>
    <ul v-if="results.length && !choosing" class="divide-y divide-slate-100 rounded-lg border border-slate-200">
      <li v-for="p in results" :key="p.id">
        <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-emerald-50" @click="pick(p)">
          <img v-if="p.thumb" :src="p.thumb" alt="" class="h-10 w-10 rounded-lg object-cover" />
          <span v-else class="grid h-10 w-10 place-items-center rounded-lg bg-slate-100 text-slate-400"><Icon name="image" :size="18" /></span>
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ p.title }}</span>
            <span class="block text-xs" :class="p.track_inventory && p.available <= 0 ? 'text-red-600' : 'text-slate-500'">
              <template v-if="p.track_inventory">{{ p.available }} en stock</template><template v-else>Stock non suivi</template><template v-if="p.has_variants"> · {{ p.variants_count }} variantes</template>
            </span>
          </span>
          <span class="font-medium tabular-nums">{{ money(p.price_min) }}</span>
        </button>
      </li>
    </ul>
    <div v-if="choosing" class="rounded-lg border border-emerald-200 bg-emerald-50/50 p-3">
      <p class="mb-2 text-sm font-medium">{{ choosing.title }} : quelle variante ?</p>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="v in choosing.variants"
          :key="v.id"
          type="button"
          class="rounded-lg border bg-white px-3 py-1.5 text-sm hover:border-emerald-600"
          :class="choosing.track_inventory && v.available <= 0 ? 'border-slate-200 text-slate-400' : 'border-slate-300'"
          @click="add(choosing, v)"
        >
          {{ v.title }} · {{ money(v.price) }}
          <span v-if="choosing.track_inventory" class="text-xs" :class="v.available <= 0 ? 'text-red-600' : 'text-slate-500'">({{ v.available }})</span>
        </button>
        <button type="button" class="btn-ghost px-2 py-1 text-sm" @click="choosing = null">Annuler</button>
      </div>
    </div>
  </div>
</template>
