<script setup>
import { computed, ref } from 'vue'

// Lien des lives (toujours le même, {boutique}/live) : à quoi il sert, comment le donner aux clients, copie en une touche
const props = defineProps({
  url: { type: String, required: true },
  // Pendant le live : explications repliées, seuls le lien et les boutons restent visibles
  compact: { type: Boolean, default: false },
})
const copied = ref('')
const message = computed(() => `🔴 Réservez les articles du live ici 👉 ${props.url} : choisissez vos articles par leur code (A1, A2…) et touchez « Je prends ». Vous pouvez aussi nous envoyer les codes sur WhatsApp, plusieurs à la fois : « A1 + A3 ».`)

async function copy(text, what) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = what
    setTimeout(() => (copied.value = ''), 2000)
  } catch {
    window.prompt('Copiez ce texte :', text)
  }
}
</script>

<template>
  <section class="card space-y-3 p-4 text-sm">
    <div>
      <h3 class="font-semibold">Le lien de vos lives</h3>
      <p class="mt-1 text-slate-600">Toujours le même lien : il affiche le live en cours. Vos clients y réservent en deux touches, sans vous écrire.</p>
    </div>
    <div class="flex gap-2">
      <input :value="url" readonly class="input min-w-0 flex-1" aria-label="Lien de vos lives" @focus="$event.target.select()" />
      <button type="button" class="btn-brand shrink-0" @click="copy(url, 'link')">{{ copied === 'link' ? 'Copié ✓' : 'Copier le lien' }}</button>
    </div>
    <details :open="!compact" class="rounded-lg bg-slate-50 p-3">
      <summary class="cursor-pointer font-medium">Comment l'utiliser ?</summary>
      <ol class="mt-2 list-decimal space-y-1.5 pl-5 text-slate-700">
        <li><strong>Avant le live :</strong> collez le lien dans la bio de votre compte TikTok, Instagram ou Facebook (une seule fois, il ne change pas).</li>
        <li><strong>Pendant le live :</strong> épinglez le message ci-dessous en commentaire et dites : « Touchez le lien, choisissez le code de l'article et appuyez sur Je prends ».</li>
        <li><strong>Le client</strong> choisit ses articles et le nombre de pièces, donne son nom, son numéro et son quartier : c'est réservé à son nom.</li>
        <li><strong>Entre deux lives,</strong> la page indique simplement qu'il n'y a pas de live en ce moment.</li>
      </ol>
      <p class="mt-3 text-xs text-slate-500">Message à épingler en commentaire</p>
      <p class="mt-1 rounded-lg bg-white p-2 [overflow-wrap:anywhere]">{{ message }}</p>
    </details>
    <div class="flex flex-wrap gap-2">
      <button type="button" class="btn-secondary" @click="copy(message, 'message')">{{ copied === 'message' ? 'Message copié ✓' : 'Copier le message à épingler' }}</button>
      <a :href="url" target="_blank" rel="noopener" class="btn-ghost">Voir la page comme un client</a>
    </div>
  </section>
</template>
