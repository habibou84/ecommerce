// Clavier du téléphone ouvert (saisie dans un champ texte) : les barres fixées en bas de l'écran s'effacent pour
// laisser la place de taper (classe « keyboard-open » sur <html>, voir app.css), et reviennent quand il se ferme.
const TEXT_FIELDS = 'input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=button]):not([type=submit]):not([type=range]):not([type=color]), textarea, [contenteditable=true]'
let started = false
let timer

const touch = () => window.matchMedia('(pointer: coarse)').matches

function set(open) {
  clearTimeout(timer)
  // Petit délai à la fermeture : passer d'un champ à l'autre ne fait pas clignoter les barres
  timer = setTimeout(() => document.documentElement.classList.toggle('keyboard-open', open), open ? 0 : 150)
}

export function watchKeyboard() {
  if (started) return
  started = true
  document.addEventListener('focusin', (e) => touch() && e.target.matches?.(TEXT_FIELDS) && set(true))
  document.addEventListener('focusout', (e) => e.target.matches?.(TEXT_FIELDS) && set(false))
}
