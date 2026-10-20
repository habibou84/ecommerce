// Pas de suggestions du navigateur (adresses, contacts enregistrés) sur un formulaire : elles cachent l'écran sans
// servir à rien ici. Chrome ignore « off » sur les champs qu'il croit reconnaître (adresse, téléphone, nom), mais
// renonce devant une valeur qu'il ne connaît pas ; les gestionnaires de mots de passe ont leurs propres marqueurs.
const FIELDS = 'input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=hidden]), textarea'

function apply(root) {
  if (root.tagName === 'FORM') root.setAttribute('autocomplete', 'off')
  root.querySelectorAll(FIELDS).forEach((el) => {
    if (el.dataset.noAutofill) return
    el.dataset.noAutofill = '1'
    el.setAttribute('autocomplete', 'jm-no-autofill')
    el.setAttribute('data-lpignore', 'true')
    el.setAttribute('data-1p-ignore', '')
    el.setAttribute('data-form-type', 'other')
  })
}

export const noAutofill = {
  mounted(el) {
    apply(el)
    // Champs ajoutés ensuite (autre numéro, notes, articles…)
    el._noAutofill = new MutationObserver(() => apply(el))
    el._noAutofill.observe(el, { childList: true, subtree: true })
  },
  unmounted(el) {
    el._noAutofill?.disconnect()
  },
}
