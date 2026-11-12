<script setup>
import { computed, onMounted, ref } from 'vue'
import http, { errorMessage } from '../../lib/http'
import { money } from '../../lib/format'

/**
 * Menu Boost (étape 1) : la connexion Facebook et ce qu'il manque, la vérification du pixel, les meilleures ventes à
 * booster (lien suivi, texte et photo prêts, puis la création de la pub chez Meta) et les commandes venues des
 * boosts. Utilisé dans l'onglet « Boost » de l'accueil du téléphone et sur la page Boost.
 */
const data = ref(null)
const error = ref('')
const picked = ref(null)
const copied = ref('')

onMounted(async () => {
  try {
    data.value = (await http.get('/marketing/boost')).data.data
  } catch (e) {
    error.value = errorMessage(e)
  }
})

const c = computed(() => data.value?.connection ?? {})
// Voyants de la connexion : ce qui est prêt, ce qui manque (et comment le régler)
const lights = computed(() => [
  { ok: c.value.connected && !c.value.expired, label: 'Compte Facebook', info: c.value.connected ? (c.value.expired ? 'connexion expirée : reconnecte-toi' : c.value.business) : 'pas encore relié' },
  { ok: !!c.value.catalog_id, label: 'Catalogue', info: c.value.catalog_id ? (c.value.catalog_items !== null ? `${c.value.catalog_items} produit${c.value.catalog_items > 1 ? 's' : ''}, mis à jour toutes les heures` : 'relié') : 'à relier' },
  { ok: !!c.value.pixel_id, label: 'Pixel', info: c.value.pixel_id ? 'installé sur la boutique' : 'à installer' },
  { ok: c.value.conversions_ready, label: 'Ventes envoyées à Meta', info: c.value.conversions_ready ? `${c.value.conversions_sent} cette semaine, par le serveur` : 'en attente de la connexion' },
])
const ready = computed(() => lights.value.every((l) => l.ok))

async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    const area = document.createElement('textarea')
    area.value = text
    document.body.appendChild(area)
    area.select()
    document.execCommand('copy')
    area.remove()
  }
  copied.value = key
  setTimeout(() => (copied.value === key) && (copied.value = ''), 2500)
}
</script>

<template>
  <div class="space-y-3">
    <p v-if="error" class="rounded-2xl bg-red-50 p-3 text-sm font-bold text-red-700">{{ error }}</p>
    <p v-else-if="!data" class="p-4 text-center text-sm text-slate-500">Chargement…</p>

    <template v-else>
      <!-- En tête : ce que fait le Boost -->
      <section class="rounded-[22px] bg-gradient-to-br from-[#1877f2] to-[#7b3fe4] p-4 text-white">
        <p class="text-[18px] font-black leading-snug">🚀 Booste tes ventes sur Facebook et Instagram</p>
        <p class="mt-1 text-[13px] opacity-90">Ton catalogue, ton pixel et un lien suivi pour chaque pub : tu vois combien de commandes chaque boost t'apporte.</p>
      </section>

      <!-- Ma connexion Facebook -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <div class="flex items-center gap-2">
          <h3 class="text-[15px] font-black">Ma connexion Facebook</h3>
          <span class="ml-auto rounded-full px-2 py-0.5 text-[11px] font-extrabold" :class="ready ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'">{{ ready ? 'Prête' : 'À compléter' }}</span>
        </div>
        <ul class="mt-2 space-y-1.5">
          <li v-for="l in lights" :key="l.label" class="flex items-center gap-2 text-[13.5px]">
            <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full text-[11px] font-black" :class="l.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">{{ l.ok ? '✓' : '!' }}</span>
            <b class="font-bold">{{ l.label }}</b><span class="ml-auto truncate pl-2 text-right text-[12px] text-slate-500">{{ l.info }}</span>
          </li>
        </ul>
        <p v-if="c.error" class="mt-2 rounded-xl bg-amber-50 p-2.5 text-[13px] text-amber-800">⚠️ {{ c.error }}</p>
        <RouterLink v-if="!ready" to="/gestion/marketing/suivi" class="btn-brand mt-3 w-full justify-center py-3 text-[15px] font-black">{{ data.available ? '🔗 Connecter mon Facebook' : 'Régler le pixel' }}</RouterLink>
        <p class="mt-2 text-[12px] text-slate-500">Pour lancer une pub, ton compte publicitaire Meta doit avoir un moyen de paiement : le budget est payé directement à Meta.</p>
      </section>

      <!-- Mes pixels vérifiés -->
      <section v-if="c.pixel_id" class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-[15px] font-black">🔎 Ce que Meta reçoit <span class="font-normal text-slate-500">· 7 derniers jours</span></h3>
        <div v-if="data.pixel" class="mt-2 grid grid-cols-2 gap-1.5">
          <div v-for="e in data.pixel" :key="e.event" class="rounded-xl border border-slate-200 p-2 text-[12.5px]">
            <span>{{ e.count ? '✅' : '⚪' }}</span> <b>{{ e.label }}</b>
            <span class="block text-slate-500">{{ e.count }} cette semaine</span>
          </div>
        </div>
        <p v-else class="mt-2 text-[13px] text-slate-500">Vérification indisponible pour le moment (connexion Facebook à refaire, ou Meta ne répond pas).</p>
        <p v-if="data.pixel && !data.pixel.some((e) => e.count)" class="mt-2 rounded-xl bg-amber-50 p-2.5 text-[13px] text-amber-800">Meta n'a encore rien reçu : ouvre ta boutique et regarde un produit, puis reviens dans une heure.</p>
      </section>

      <!-- Booster un produit -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-[15px] font-black">✨ Booster un produit</h3>
        <p class="mt-0.5 text-[12.5px] text-slate-500">Tes meilleures ventes des 30 derniers jours. Touche un produit : lien suivi, texte et photo prêts.</p>
        <div class="mt-2 grid grid-cols-3 gap-2">
          <button v-for="p in data.products" :key="p.id" type="button" class="overflow-hidden rounded-xl border-2 text-left" :class="picked?.id === p.id ? 'border-[#1877f2]' : 'border-slate-200'" @click="picked = picked?.id === p.id ? null : p">
            <img v-if="p.image" :src="p.image" alt="" class="aspect-square w-full object-cover" loading="lazy">
            <span v-else class="grid aspect-square w-full place-items-center bg-slate-100 text-2xl" aria-hidden="true">📷</span>
            <span class="block truncate px-1.5 pt-1 text-[11.5px] font-bold">{{ p.title }}</span>
            <span class="block px-1.5 pb-1.5 text-[10.5px] font-bold text-emerald-700">{{ p.sold ? `${p.sold} vendu${p.sold > 1 ? 's' : ''}` : 'nouveau' }}</span>
          </button>
        </div>
        <p v-if="!data.products.length" class="mt-2 text-sm text-slate-500">Ajoute des produits à ton catalogue pour les booster.</p>

        <!-- Les 4 étapes pour booster le produit choisi -->
        <div v-if="picked" class="mt-3 space-y-2 rounded-2xl bg-[#e7f0fe] p-3 text-[13.5px]">
          <p class="font-black">Booster « {{ picked.title }} »</p>
          <div class="flex items-center gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#1877f2] text-xs font-black text-white">1</span>
            <span class="min-w-0 flex-1"><b>Le lien suivi</b><span class="block truncate text-[11.5px] text-slate-500">{{ picked.link }}</span></span>
            <button type="button" class="shrink-0 rounded-lg bg-[#1877f2] px-3 py-1.5 text-xs font-extrabold text-white" @click="copy(picked.link, 'link')">{{ copied === 'link' ? 'Copié ✓' : 'Copier' }}</button>
          </div>
          <div class="flex items-start gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#1877f2] text-xs font-black text-white">2</span>
            <span class="min-w-0 flex-1"><b>Le texte de la pub</b><span class="block whitespace-pre-line text-[12px] text-slate-600">{{ picked.text }}</span></span>
            <button type="button" class="shrink-0 rounded-lg bg-[#1877f2] px-3 py-1.5 text-xs font-extrabold text-white" @click="copy(picked.text, 'text')">{{ copied === 'text' ? 'Copié ✓' : 'Copier' }}</button>
          </div>
          <div v-if="picked.image_large" class="flex items-center gap-2 rounded-xl bg-white p-2.5">
            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#1877f2] text-xs font-black text-white">3</span>
            <span class="min-w-0 flex-1"><b>La photo</b><span class="block text-[11.5px] text-slate-500">ou utilise ton catalogue directement chez Meta</span></span>
            <a :href="picked.image_large" download class="shrink-0 rounded-lg bg-[#1877f2] px-3 py-1.5 text-xs font-extrabold text-white">Télécharger</a>
          </div>
          <div class="rounded-xl bg-white p-2.5">
            <div class="flex items-center gap-2">
              <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[#1877f2] text-xs font-black text-white">4</span>
              <b class="min-w-0 flex-1">Créer la pub chez Meta</b>
            </div>
            <p class="mt-1 text-[12.5px] text-slate-600">Dans Meta, choisis « Créer une publicité », colle le lien et le texte, ajoute la photo, puis choisis ton budget. Pour recevoir des messages, choisis l'objectif « Messages WhatsApp ».</p>
            <a href="https://business.facebook.com/latest/ad_center/" target="_blank" rel="noopener" class="mt-2 block rounded-xl bg-[#1877f2] py-2.5 text-center text-sm font-black text-white">Ouvrir Meta ↗</a>
          </div>
          <p class="text-[12px] text-slate-600">Grâce au lien suivi, chaque commande venue de cette pub s'affiche dans « Mes boosts ».</p>
        </div>
      </section>

      <!-- Mes boosts : commandes venues des liens suivis -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-[15px] font-black">📊 Mes boosts <span class="font-normal text-slate-500">· 30 derniers jours</span></h3>
        <div class="mt-2 grid grid-cols-2 gap-1.5">
          <div class="rounded-xl bg-slate-50 p-2.5"><b class="block text-[18px] font-black">{{ data.results.orders }}</b><span class="text-[12px] text-slate-500">commande{{ data.results.orders > 1 ? 's' : '' }} venue{{ data.results.orders > 1 ? 's' : '' }} d'un boost</span></div>
          <div class="rounded-xl bg-slate-50 p-2.5"><b class="block text-[18px] font-black">{{ money(data.results.amount) }}</b><span class="text-[12px] text-slate-500">de ventes</span></div>
        </div>
        <ul v-if="data.results.products.length" class="mt-2 divide-y divide-slate-100 text-[13.5px]">
          <li v-for="r in data.results.products" :key="r.id" class="flex items-center gap-2 py-2"><span class="min-w-0 flex-1 truncate font-bold">{{ r.title }}</span><span class="text-slate-500">{{ r.orders }} cmd</span><b>{{ money(r.amount) }}</b></li>
        </ul>
        <p v-else class="mt-2 text-[13px] text-slate-500">Aucune commande venue d'un boost pour l'instant. Booste un produit avec son lien suivi pour la voir ici.</p>
      </section>

      <!-- Prix -->
      <section class="rounded-2xl bg-white p-4 shadow-soft">
        <h3 class="text-[15px] font-black">💳 Prix</h3>
        <p class="mt-1 text-[13px] text-slate-600">Connexion, catalogue, pixel et liens suivis : <b>gratuits</b>. L'assistant de boost arrive bientôt, payé seulement <b>à la commande</b> venue d'un boost : pas de vente, pas de frais.</p>
      </section>
    </template>
  </div>
</template>
