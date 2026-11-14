<script setup>
import { computed, ref } from 'vue'
import ColorIcon from './ColorIcon.vue'
import Icon from './Icon.vue'
import ToggleSwitch from './ToggleSwitch.vue'
import http, { errorMessage } from '../lib/http'

/**
 * Catalogue WhatsApp, en trois étapes qui se cochent : catalogue relié à Meta (flux mis à jour toutes les heures),
 * catalogue rattaché au numéro WhatsApp (gestionnaire WhatsApp › Catalogue), puis montré sur le numéro avec le panier
 * (réglage du numéro chez Meta). Après un panier : commune demandée, puis récapitulatif avec « Je confirme ».
 */
const props = defineProps({ data: { type: Object, required: true } })
const emit = defineEmits(['update'])
const copied = ref(false)
const busy = ref('')
const error = ref('')

const c = computed(() => props.data.catalog)
// null : pas encore de catalogue chez Meta, pas de numéro officiel, ou Meta ne répond pas
const number = computed(() => c.value.number)
const steps = computed(() => [
  { key: 'meta', done: !!c.value.meta, label: 'Catalogue relié à Meta', hint: c.value.meta ? `Compte ${c.value.meta.business} · ${c.value.items} article(s), mis à jour toutes les heures` : `${c.value.items} article(s) prêt(s) avec photo` },
  { key: 'linked', done: !!number.value?.catalog, label: 'Catalogue rattaché à votre numéro WhatsApp', hint: number.value?.catalog ? `« ${number.value.catalog.name ?? number.value.catalog.id} »` : 'Dans le gestionnaire WhatsApp de Meta › Catalogue : choisissez ce catalogue (un seul par compte)' },
  { key: 'visible', done: !!(number.value?.visible && number.value?.cart), label: 'Visible dans WhatsApp, panier activé', hint: number.value?.visible ? 'Icône boutique et panier dans vos conversations' : 'Vos clients voient vos articles et envoient leur panier' },
])
const current = computed(() => steps.value.find((s) => !s.done)?.key ?? null)

async function copy() {
  try {
    await navigator.clipboard.writeText(c.value.feed_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copiez ce lien :', c.value.feed_url)
  }
}
async function save(key, url, body) {
  busy.value = key
  error.value = ''
  try {
    emit('update', (await http.put(url, body)).data.data)
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <section class="card space-y-4 p-4 sm:p-5">
    <div class="flex items-start gap-3">
      <ColorIcon name="produit" :size="44" />
      <div class="min-w-0 flex-1">
        <h2 class="text-[17px] font-extrabold">Catalogue WhatsApp</h2>
        <p class="text-sm text-slate-600">Vos clients remplissent un panier dans WhatsApp ; il devient une commande « À confirmer ». {{ c.orders_last_30_days }} panier(s) en 30 jours.</p>
      </div>
    </div>

    <ol class="space-y-2">
      <li v-for="(s, i) in steps" :key="s.key" class="flex items-start gap-3 rounded-xl p-3" :class="s.done ? 'bg-emerald-50' : s.key === current ? 'border-2 border-ocre-600 bg-white' : 'bg-slate-50'">
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-sm font-black" :class="s.done ? 'bg-emerald-700 text-white' : s.key === current ? 'bg-slate-900 text-white' : 'bg-slate-200 text-slate-700'">
          <Icon v-if="s.done" name="check" :size="16" /><template v-else>{{ i + 1 }}</template>
        </span>
        <span class="min-w-0 flex-1">
          <b class="block text-[15px]">{{ s.label }}</b>
          <span class="block text-sm text-slate-600">{{ s.hint }}</span>
          <span v-if="s.key === 'meta' && s.key === current" class="mt-2 flex flex-wrap items-center gap-3">
            <RouterLink to="/gestion/marketing/suivi" class="btn-brand">Brancher avec Facebook</RouterLink>
            <button type="button" class="text-sm font-medium text-emerald-700 underline" @click="copy">{{ copied ? 'Lien copié ✓' : 'Copier le lien du catalogue' }}</button>
          </span>
          <span v-else-if="s.key === 'linked' && s.key === current" class="mt-1 block text-sm text-slate-600">
            <a href="https://business.facebook.com/wa/manage/" target="_blank" rel="noopener" class="font-semibold text-emerald-700 underline">Ouvrir le gestionnaire WhatsApp</a>
            · puis revenez ici : l'étape se coche toute seule.
          </span>
          <span v-else-if="s.key === 'visible' && number?.catalog" class="mt-2 block">
            <span class="flex items-center gap-3 text-sm font-semibold"><ToggleSwitch :model-value="!!number.visible" :disabled="busy === 'visible'" label="Montrer le catalogue dans WhatsApp" @update:model-value="save('visible', '/whatsapp/catalog/visible', { visible: $event })" />{{ number.visible ? 'Montré dans WhatsApp' : 'Montrer dans WhatsApp' }}</span>
          </span>
        </span>
      </li>
    </ol>
    <p v-if="c.meta && !number" class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600">Les étapes 2 et 3 se vérifient avec votre numéro WhatsApp officiel connecté (en haut de l'accueil WhatsApp).</p>

    <div class="flex items-center gap-3 rounded-xl bg-slate-50 p-3">
      <span class="min-w-0 flex-1 text-sm"><b class="block text-[15px]">Après un panier</b>Demander la commune, puis envoyer le récap avec « Je confirme »</span>
      <ToggleSwitch :model-value="c.cart_flow" :disabled="busy === 'flow'" label="Après un panier, demander la commune puis envoyer le récap" @update:model-value="save('flow', '/whatsapp/cart-flow', { enabled: $event })" />
    </div>
    <p v-if="error" class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-800" role="alert">{{ error }}</p>
  </section>
</template>
