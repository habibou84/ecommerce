<script setup>
import { computed, ref } from 'vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'
import { money } from '../lib/format'

// « Qui livre ? » : mes livreurs et les entreprises de livraison, avec recherche, les plus récents en tête
const props = defineProps({
  options: { type: Object, default: null }, // réponse de /orders/delivery-options
  modelValue: { type: String, default: '' }, // '' | 'courier:ID' | 'carrier:ID'
  placeLabel: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'close'])

const search = ref('')
const filter = ref('all') // all | courier | carrier

const initials = (name) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase()
const place = computed(() => props.placeLabel.split(',')[0])

const cheapest = computed(() => {
  const priced = (props.options?.carriers ?? []).filter((c) => c.covered && c.fee !== null)
  return priced.length > 1 ? priced.reduce((a, b) => (b.fee < a.fee ? b : a)).id : null
})

const entries = computed(() => [
  ...(props.options?.couriers ?? []).map((c) => ({
    key: `courier:${c.id}`, type: 'courier', name: c.name, price: null, faded: !c.is_available, disabled: false,
    sub: ['Mon livreur', c.is_available ? 'Disponible' : 'Indisponible', c.covers_zone ? `couvre ${place.value || 'le quartier'}` : '', c.active_missions ? `${c.active_missions} course(s) en cours` : ''].filter(Boolean).join(' · '),
  })),
  ...(props.options?.carriers ?? []).map((c) => ({
    key: `carrier:${c.id}`, type: 'carrier', name: c.name, price: c.covered ? c.fee : null, faded: !c.covered, disabled: !c.covered && !!props.placeLabel,
    cheapest: c.id === cheapest.value,
    sub: [
      c.is_api ? 'Entreprise · Delivery App' : `Entreprise · ${c.zones_count ? `${c.zones_count} zone(s)` : 'sans grille de tarifs'}`,
      props.placeLabel ? (c.covered ? `livre ${place.value}${c.zone ? ` · zone ${c.zone}` : ''}` : `ne livre pas ${place.value}`) : '',
      c.day_message || '',
    ].filter(Boolean).join(' · '),
  })),
])

const normalize = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const visible = computed(() => entries.value.filter((e) => (filter.value === 'all' || e.type === filter.value) && (!search.value.trim() || normalize(e.name).includes(normalize(search.value.trim())))))
const recent = computed(() => (search.value.trim() || filter.value !== 'all' ? [] : (props.options?.recent ?? []).map((k) => visible.value.find((e) => e.key === k)).filter(Boolean)))
const sections = computed(() => [
  { title: 'Récemment utilisés', items: recent.value },
  { title: 'Mes livreurs', items: visible.value.filter((e) => e.type === 'courier' && !recent.value.includes(e)) },
  { title: `Entreprises de livraison${props.placeLabel ? ` · pour ${place.value}` : ''}`, items: visible.value.filter((e) => e.type === 'carrier' && !recent.value.includes(e)) },
].filter((s) => s.items.length))
const count = (type) => entries.value.filter((e) => e.type === type).length

function pick(key) {
  emit('update:modelValue', key)
  emit('close')
}
</script>

<template>
  <Modal title="Qui livre ?" @close="emit('close')">
    <div class="space-y-3">
      <div class="relative">
        <Icon name="search" :size="20" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
        <input v-model="search" type="search" class="input-lg pl-12" placeholder="Rechercher un livreur ou une entreprise…" aria-label="Rechercher" autocomplete="jm-no-autofill" data-lpignore="true" data-1p-ignore />
      </div>
      <div class="flex gap-2 overflow-x-auto text-sm font-semibold">
        <button v-for="f in [['all', 'Tous'], ['courier', `Mes livreurs (${count('courier')})`], ['carrier', `Entreprises (${count('carrier')})`]]" :key="f[0]" type="button" class="shrink-0 rounded-full px-4 py-2" :class="filter === f[0] ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'" @click="filter = f[0]">{{ f[1] }}</button>
      </div>

      <p v-if="!options" class="py-4 text-center text-sm text-slate-500">Chargement…</p>
      <p v-else-if="!sections.length" class="py-4 text-center text-sm text-slate-500">{{ entries.length ? 'Aucun résultat.' : 'Aucun livreur ni entreprise de livraison : ajoutez-les dans Livraisons.' }}</p>
      <template v-for="section in sections" :key="section.title">
        <p class="pt-1 text-xs font-bold uppercase tracking-wide text-slate-500">{{ section.title }}</p>
        <button
          v-for="e in section.items"
          :key="e.key"
          type="button"
          class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left"
          :class="[modelValue === e.key ? 'bg-emerald-50 ring-2 ring-emerald-600' : 'hover:bg-slate-50', e.faded ? 'opacity-50' : '']"
          :disabled="e.disabled"
          @click="pick(e.key)"
        >
          <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl text-sm font-bold" :class="e.type === 'courier' ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800'">{{ initials(e.name) }}</span>
          <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-center gap-2 text-base font-semibold">{{ e.name }}<span v-if="e.cheapest" class="rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">Le moins cher</span></span>
            <span class="block text-sm text-slate-500">{{ e.sub }}</span>
          </span>
          <span v-if="e.price !== null" class="text-base font-bold tabular-nums">{{ money(e.price) }}</span>
        </button>
      </template>

      <button type="button" class="flex w-full items-center gap-3 rounded-xl border border-dashed border-slate-300 px-3 py-3 text-left text-slate-600" :class="{ 'ring-2 ring-emerald-600': !modelValue }" @click="pick('')">
        <Icon name="clock" :size="22" />
        <span><span class="block font-semibold">Décider plus tard</span><span class="block text-sm">À la confirmation, ou selon vos règles automatiques</span></span>
      </button>
    </div>
  </Modal>
</template>
