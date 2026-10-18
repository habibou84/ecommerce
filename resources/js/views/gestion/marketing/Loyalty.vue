<script setup>
import { onMounted, ref } from 'vue'
import Alert from '../../../components/Alert.vue'
import Field from '../../../components/Field.vue'
import MarketingTabs from '../../../components/MarketingTabs.vue'
import http, { errorMessage } from '../../../lib/http'
import { money, phoneDisplay } from '../../../lib/format'

// Fidélité (points sur les commandes livrées) et parrainage (code du client)
const data = ref(null)
const form = ref(null)
const error = ref('')
const saved = ref(false)

async function load() {
  data.value = (await http.get('/loyalty')).data.data
  form.value = { ...data.value.settings }
}
onMounted(load)

async function save() {
  error.value = ''
  saved.value = false
  try {
    await http.put('/loyalty', form.value)
    saved.value = true
    await load()
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <div class="space-y-4">
    <MarketingTabs />
    <template v-if="data">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-slate-500">Clients avec des points</p><p class="text-2xl font-semibold">{{ data.stats.members }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Points en circulation</p><p class="text-2xl font-semibold">{{ data.stats.outstanding_points }}</p><p class="text-xs text-slate-500">soit {{ money(data.stats.outstanding_value) }} de réductions possibles</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Points utilisés</p><p class="text-2xl font-semibold">{{ data.stats.redeemed_points }}</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Clients parrainés</p><p class="text-2xl font-semibold">{{ data.stats.referrals }}</p></div>
      </div>

      <form class="card space-y-4 p-4" @submit.prevent="save">
        <Alert v-if="error">{{ error }}</Alert>
        <Alert v-if="saved" tone="success">Réglages enregistrés.</Alert>
        <section class="space-y-3">
          <label class="flex items-center gap-2 font-semibold"><input v-model="form.enabled" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Points de fidélité</label>
          <p class="text-sm text-slate-600">Le client gagne des points quand sa commande est livrée (articles, hors livraison) et les utilise en réduction sur une commande que vous saisissez. Il voit ses points sur sa page de suivi.</p>
          <div v-if="form.enabled" class="grid gap-3 sm:grid-cols-3">
            <Field id="l-rate" label="Points par 1 000 F d'achat"><input id="l-rate" v-model.number="form.points_per_1000" type="number" min="1" class="input" /></Field>
            <Field id="l-value" label="Valeur d'un point (F)"><input id="l-value" v-model.number="form.point_value" type="number" min="1" class="input" /></Field>
            <Field id="l-min" label="Minimum pour utiliser"><input id="l-min" v-model.number="form.min_points" type="number" min="1" class="input" /></Field>
          </div>
          <p v-if="form.enabled" class="text-sm text-emerald-800">Exemple : une commande de 20 000 F rapporte {{ 20 * form.points_per_1000 }} point(s), soit {{ money(20 * form.points_per_1000 * form.point_value) }} de réduction ({{ Math.round(form.points_per_1000 * form.point_value / 10) / 100 }} % redonnés au client).</p>
        </section>
        <section class="space-y-3 border-t border-slate-200 pt-4">
          <label class="flex items-center gap-2 font-semibold"><input v-model="form.referral_enabled" type="checkbox" class="h-4 w-4 accent-emerald-700" /> Parrainage</label>
          <p class="text-sm text-slate-600">Chaque client a un code à partager (sur sa page de suivi). Un nouveau client qui le donne à sa première commande a une réduction ; le parrain gagne des points quand cette commande est livrée.</p>
          <div v-if="form.referral_enabled" class="grid gap-3 sm:grid-cols-2">
            <Field id="l-rd" label="Réduction du filleul (F)"><input id="l-rd" v-model.number="form.referral_discount" type="number" min="0" class="input" /></Field>
            <Field id="l-rp" label="Points du parrain"><input id="l-rp" v-model.number="form.referral_points" type="number" min="0" class="input" /></Field>
          </div>
        </section>
        <button class="btn-brand">Enregistrer</button>
      </form>

      <section v-if="data.top.length" class="card p-4 text-sm">
        <h3 class="mb-2 font-semibold">Meilleurs clients</h3>
        <ul class="divide-y divide-slate-100">
          <li v-for="c in data.top" :key="c.id" class="flex justify-between py-2">
            <RouterLink :to="`/gestion/clients/${c.id}`" class="hover:text-emerald-700">{{ c.name || phoneDisplay(c.phone) }}</RouterLink>
            <span class="font-medium">{{ c.loyalty_points }} pts</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
