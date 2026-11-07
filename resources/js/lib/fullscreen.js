import { ref } from 'vue'

/**
 * Un écran en plein écran (le live en direct) : la fenêtre qui l'a ouvert est cachée derrière, pour qu'elle ne
 * s'ouvre pas en dessous.
 */
export const fullscreenOn = ref(false)

/**
 * Un live lancé ou terminé : les pages qui montrent la carte du live (accueil glissant, fenêtre « Live ») se
 * rechargent tout de suite, sans rafraîchir la page.
 */
export const livesVersion = ref(0)
export const livesChanged = () => { livesVersion.value += 1 }
