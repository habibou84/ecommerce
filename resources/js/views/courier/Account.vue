<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Modal from '../../components/Modal.vue'
import { cachedGet, forgetAll, offline } from '../../lib/courierOffline'
import { phoneDisplay } from '../../lib/format'
import { unregisterDevice } from '../../lib/push'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const me = ref(null)
const confirming = ref(false)
const leaving = ref(false)

onMounted(async () => {
  try {
    me.value = await cachedGet('/courier/me', 'me')
  } catch {
    // le profil vient du téléphone
  }
})

// Sortie du compte : toujours confirmée ; les étapes pas encore envoyées seraient perdues
async function logout() {
  leaving.value = true
  await unregisterDevice()
  await auth.logout().catch(() => {})
  await forgetAll()
  router.replace('/livreur/connexion')
}
</script>

<template>
  <div class="space-y-4">
    <section class="rounded-xl bg-white p-4 shadow-sm">
      <p class="text-lg font-semibold">{{ me?.name ?? auth.profile?.courier?.name }}</p>
      <p class="text-sm text-slate-500">{{ phoneDisplay(auth.user?.phone) }}</p>
      <p v-if="me" class="mt-2 text-sm">{{ me.is_available ? '● Disponible' : '○ Indisponible' }} <span class="text-slate-500">· à changer en haut de l'écran</span></p>
      <p v-if="me?.tracking" class="mt-1 text-sm text-slate-600">📍 Votre position est partagée avec la boutique quand vous êtes disponible et que l'application est ouverte.</p>
    </section>

    <section v-if="me?.stores?.length" class="rounded-xl bg-white p-4 shadow-sm">
      <p class="mb-2 text-sm font-semibold">{{ me.stores.length > 1 ? 'Mes boutiques' : 'Ma boutique' }}</p>
      <ul class="divide-y divide-slate-100 text-sm">
        <li v-for="s in me.stores" :key="s.name" class="flex items-center gap-2 py-2">
          <span class="flex-1">{{ s.name }}</span>
          <a v-if="s.phone" :href="`tel:${s.phone}`" class="btn-secondary px-3 py-1.5 text-sm">📞 Appeler</a>
        </li>
      </ul>
    </section>

    <section class="rounded-xl bg-white p-4 shadow-sm text-sm text-slate-600">
      <p class="font-semibold text-slate-800">Installer l'application</p>
      <p class="mt-1">Dans le menu du navigateur, choisissez « Ajouter à l'écran d'accueil » : l'application s'ouvre ensuite comme les autres, même sans réseau.</p>
    </section>

    <button class="w-full rounded-xl border border-red-200 bg-white py-3 text-sm font-medium text-red-600" @click="confirming = true">Se déconnecter</button>

    <Modal v-if="confirming" title="Se déconnecter ?" @close="confirming = false">
      <div class="space-y-4 text-sm">
        <p v-if="offline.pending" class="rounded-lg bg-amber-100 p-3 text-amber-900">⚠ {{ offline.pending }} étape(s) pas encore envoyée(s) à la boutique seront perdues. Attendez le retour du réseau avant de sortir.</p>
        <p>Vous devrez saisir à nouveau votre numéro et votre mot de passe pour revenir.</p>
        <div class="grid grid-cols-2 gap-2">
          <button class="btn-secondary" @click="confirming = false">Rester</button>
          <button class="btn-danger" :disabled="leaving" @click="logout">Se déconnecter</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
