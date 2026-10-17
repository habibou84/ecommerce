<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import Pagination from '../../../components/Pagination.vue'
import http, { errorMessage } from '../../../lib/http'
import { formatDateTime } from '../../../lib/format'

// Avis clients : laissés par le lien envoyé après la livraison, publiés sur la fiche produit après validation
const reviews = ref([])
const meta = ref(null)
const counts = ref({})
const status = ref('pending')
const error = ref('')

async function load(page = 1) {
  const { data } = await http.get('/reviews', { params: { status: status.value || undefined, page } })
  reviews.value = data.data
  meta.value = data.meta
  counts.value = data.counts
}
onMounted(load)

async function act(review, action) {
  error.value = ''
  try {
    if (action === 'delete') {
      if (!confirm('Supprimer cet avis ?')) return
      await http.delete(`/reviews/${review.id}`)
    } else {
      await http.post(`/reviews/${review.id}/${action}`)
    }
    await load(meta.value?.current_page ?? 1)
  } catch (e) {
    error.value = errorMessage(e)
  }
}

const FILTERS = [{ value: 'pending', label: 'À valider' }, { value: 'published', label: 'Publiés' }, { value: 'hidden', label: 'Masqués' }, { value: '', label: 'Tous' }]
</script>

<template>
  <div class="space-y-4">
    <MarketingTabs />
    <p class="text-sm text-slate-600">
      Après la livraison, le client reçoit un lien pour noter les articles (message WhatsApp automatique 24 h après, ou bouton « Demander un avis » sur la commande).
      Un avis n'apparaît sur la fiche produit qu'une fois publié.
    </p>
    <div class="flex flex-wrap gap-1">
      <button v-for="f in FILTERS" :key="f.value" class="rounded-full px-3 py-1 text-sm" :class="status === f.value ? 'bg-emerald-700 text-white' : 'bg-slate-100 text-slate-700'" @click="status = f.value; load()">
        {{ f.label }}<template v-if="f.value && counts[f.value]"> ({{ counts[f.value] }})</template>
      </button>
    </div>
    <Alert v-if="error">{{ error }}</Alert>

    <ul class="space-y-3">
      <li v-for="r in reviews" :key="r.id" class="card space-y-2 p-4 text-sm">
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-base"><span class="text-amber-500">{{ '★'.repeat(r.rating) }}</span><span class="text-slate-300">{{ '★'.repeat(5 - r.rating) }}</span></span>
          <span class="font-medium">{{ r.product?.title ?? 'Produit supprimé' }}</span>
          <span class="ml-auto rounded bg-slate-100 px-2 py-0.5 text-xs">{{ r.status_label }}</span>
        </div>
        <p v-if="r.comment" class="whitespace-pre-line text-slate-700">{{ r.comment }}</p>
        <p class="text-xs text-slate-500">
          {{ r.author_name }} · {{ formatDateTime(r.created_at) }}
          <template v-if="r.order"> · <RouterLink :to="`/gestion/commandes/${r.order.id}`" class="hover:underline">commande {{ r.order.reference }}</RouterLink></template>
        </p>
        <div class="flex flex-wrap gap-2">
          <button v-if="r.status !== 'published'" class="btn-brand px-3 py-1.5" @click="act(r, 'publish')">Publier</button>
          <button v-if="r.status !== 'hidden'" class="btn-secondary px-3 py-1.5" @click="act(r, 'hide')">Masquer</button>
          <button class="btn-ghost px-3 py-1.5 text-red-600" @click="act(r, 'delete')">Supprimer</button>
        </div>
      </li>
      <li v-if="!reviews.length" class="card p-6 text-center text-sm text-slate-500">Aucun avis {{ status === 'pending' ? 'à valider' : 'ici' }}.</li>
    </ul>
    <Pagination :meta="meta" @page="load" />
  </div>
</template>
