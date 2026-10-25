<script setup>
import { ref, watch } from 'vue'
import http from '../../lib/http'
import { formatDateTime } from '../../lib/format'

// Retrouver une personne (numéro, nom, e-mail) et ses espaces marchands, pour le support. Lecture seule.
const q = ref('')
const results = ref(null)
let timer
watch(q, (value) => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    results.value = value.trim().length < 3 ? null : (await http.get('/console/users', { params: { q: value } })).data.data
  }, 300)
})
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <h2 class="text-xl font-semibold">Utilisateurs</h2>
    <input v-model="q" type="search" class="input" placeholder="Numéro, nom ou e-mail (3 caractères au moins)" autofocus />
    <p v-if="results && !results.length" class="text-sm text-slate-500">Personne ne correspond.</p>
    <section v-if="results?.length" class="card divide-y divide-slate-100 text-sm">
      <div v-for="u in results" :key="u.id" class="p-4">
        <p class="font-semibold">{{ u.name }}
          <span v-if="u.super_admin" class="ml-1 rounded bg-amber-100 px-1.5 text-xs text-amber-900">Console</span>
          <span v-if="!u.active" class="ml-1 rounded bg-red-100 px-1.5 text-xs text-red-800">Suspendu</span>
        </p>
        <p class="text-slate-600">{{ u.phone }}<template v-if="u.email"> · {{ u.email }}</template></p>
        <p class="text-xs text-slate-500">{{ u.last_login_at ? `Dernière connexion le ${formatDateTime(u.last_login_at)}` : 'Jamais connecté' }}</p>
        <div v-if="u.stores.length" class="mt-2 flex flex-wrap gap-2">
          <RouterLink v-for="s in u.stores" :key="s.id" :to="`/console/boutiques/${s.id}`" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-200">{{ s.name }}{{ s.owner ? ' · propriétaire' : '' }}</RouterLink>
        </div>
      </div>
    </section>
  </div>
</template>
