// Clavier du téléphone ouvert : la barre d'onglets du bas s'efface pour laisser la place de taper (classe
// « keyboard-open » sur <html>, voir app.css), et revient dès que le clavier se ferme.
// On regarde le vrai clavier (la partie visible de l'écran rétrécit), pas seulement le champ sélectionné :
// fermé avec la flèche retour d'Android, le clavier disparaît mais le champ reste sélectionné, et la barre
// restait cachée jusqu'à ce qu'on touche ailleurs.
const TEXT_FIELDS = 'input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=button]):not([type=submit]):not([type=range]):not([type=color]), textarea, [contenteditable=true]'
// Au-delà de cette perte de hauteur, c'est le clavier (une barre d'adresse qui se replie fait bien moins)
const KEYBOARD_MIN = 150
let started = false
let timer
let tallest = 0

const touch = () => window.matchMedia('(pointer: coarse)').matches
const typing = () => !!document.activeElement?.matches?.(TEXT_FIELDS)
const visibleHeight = () => window.visualViewport?.height ?? window.innerHeight

function update() {
  const height = visibleHeight()
  // Hors saisie : hauteur de référence de l'écran (elle suit aussi la rotation du téléphone)
  tallest = typing() ? Math.max(tallest, height) : height
  const open = touch() && typing() && (window.visualViewport ? tallest - height > KEYBOARD_MIN : true)
  document.documentElement.classList.toggle('keyboard-open', open)
}

// Petit délai : passer d'un champ à l'autre ne fait pas clignoter la barre
function later() {
  clearTimeout(timer)
  timer = setTimeout(update, 150)
}

export function watchKeyboard() {
  if (started) return
  started = true
  tallest = visibleHeight()
  document.addEventListener('focusin', later)
  document.addEventListener('focusout', later)
  window.visualViewport?.addEventListener('resize', update)
  window.addEventListener('resize', update)
}
