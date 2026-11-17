import { onBeforeUnmount, ref, watchEffect } from 'vue'

// Titre précis de l'écran ouvert (« Commande #1002 », nom du produit, nom du client), affiché dans l'en-tête
// et dans la fenêtre du téléphone à la place du titre général de la route (« Commande », « Produit »…)
const current = ref(null)
export const pageTitle = () => current.value?.text || ''

export function usePageTitle(getter) {
  const owner = {}
  watchEffect(() => {
    const text = getter()
    current.value = text ? { owner, text } : current.value?.owner === owner ? null : current.value
  })
  onBeforeUnmount(() => {
    if (current.value?.owner === owner) current.value = null
  })
}
