<script setup>
import { ref } from 'vue'
import ColorIcon from './ColorIcon.vue'

/**
 * Catalogue WhatsApp : les produits vus dans WhatsApp, paniers reçus comme commandes « À confirmer ». Le catalogue
 * Meta se branche en un clic dans Marketing › Suivi ; le lien du flux reste là pour le faire à la main.
 */
const props = defineProps({ data: { type: Object, required: true } })
const copied = ref(false)

async function copy() {
  try {
    await navigator.clipboard.writeText(props.data.catalog.feed_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    window.prompt('Copiez ce lien :', props.data.catalog.feed_url)
  }
}
</script>

<template>
  <section class="card space-y-4 p-4 sm:p-5">
    <div class="flex items-start gap-3">
      <ColorIcon name="produit" :size="44" />
      <div class="min-w-0 flex-1">
        <h3 class="text-[17px] font-extrabold">Vos produits dans WhatsApp</h3>
        <p class="text-sm text-slate-600">Vos clients remplissent un panier dans WhatsApp ; il arrive dans Messages et devient une commande « À confirmer ».</p>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <div class="rounded-xl bg-slate-50 p-3"><p class="text-2xl font-bold">{{ data.catalog.items }}</p><p class="text-xs text-slate-500">article(s) en vente, avec photo</p></div>
      <div class="rounded-xl bg-slate-50 p-3"><p class="text-2xl font-bold">{{ data.catalog.orders_last_30_days }}</p><p class="text-xs text-slate-500">panier(s) reçu(s) en 30 jours</p></div>
      <div class="col-span-2 rounded-xl bg-slate-50 p-3 sm:col-span-1">
        <p class="text-sm font-semibold" :class="data.catalog.meta ? 'text-emerald-700' : 'text-ocre-700'">{{ data.catalog.meta ? '✓ Relié à Meta' : 'Pas encore relié à Meta' }}</p>
        <p class="text-xs text-slate-500">{{ data.catalog.meta ? `compte ${data.catalog.meta.business}, mis à jour toutes les heures` : 'prix, stock et photos se mettront à jour tout seuls' }}</p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <RouterLink to="/gestion/marketing/suivi" :class="data.catalog.meta ? 'btn-secondary' : 'btn-brand'">{{ data.catalog.meta ? 'Voir le branchement Meta' : 'Brancher avec Facebook' }}</RouterLink>
      <button type="button" class="text-sm font-medium text-emerald-700 underline" @click="copy">{{ copied ? 'Lien copié ✓' : 'Copier le lien du catalogue' }}</button>
    </div>

    <details class="text-sm">
      <summary class="cursor-pointer font-medium text-emerald-700">Afficher le catalogue dans WhatsApp</summary>
      <ol class="mt-2 list-decimal space-y-1 pl-5 text-slate-600">
        <li>Branchez le catalogue avec Facebook (bouton ci-dessus), ou collez le lien dans le Gestionnaire de catalogue de Meta.</li>
        <li>Reliez ce catalogue à votre WhatsApp : Gestionnaire WhatsApp (numéro officiel) ou application WhatsApp Business › Outils professionnels › Catalogue.</li>
        <li>Avec le WhatsApp relié par QR code, WhatsApp transmet parfois moins de détails : vérifiez les articles avant de confirmer.</li>
      </ol>
    </details>
  </section>
</template>
