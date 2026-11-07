import { ref } from 'vue'

/**
 * Un écran en plein écran (le live en direct) : la fenêtre qui l'a ouvert est cachée derrière, pour qu'elle ne
 * s'ouvre pas en dessous.
 */
export const fullscreenOn = ref(false)
