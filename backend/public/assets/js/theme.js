// Selector de tema (claro/oscuro/sistema) compartido por las páginas de apphub que usan
// assets/css/app.css: dashboard.html, pending.html, quiebre.html, selector-app.html,
// selector-proyecto.html, superuser.html. login.html y launcher.html NO importan este archivo
// -- tienen su propia identidad visual ("Sala de Control", igual que kpis-sso) con su propia
// copia de esta misma lógica en su <script> inline, porque no usan app.css.
//
// Pedido 2026-08-14 del usuario: el toggle anterior (una sola opción que alternaba claro/
// oscuro + un link de reset escondido) generaba dudas de si "automático" estaba realmente
// puesto. Acá van 3 opciones explícitas siempre visibles -- la marca .active nunca es ambigua.

const STORAGE_KEY = 'apphub_theme_override';

export function fijarTema(modo) {
  if (modo === 'auto') {
    document.documentElement.removeAttribute('data-theme');
    localStorage.removeItem(STORAGE_KEY);
  } else {
    document.documentElement.setAttribute('data-theme', modo);
    localStorage.setItem(STORAGE_KEY, modo);
  }
  pintarSelectorTema();
}

export function pintarSelectorTema() {
  const activo = document.documentElement.getAttribute('data-theme') || 'auto';
  document.querySelectorAll('.tema-opcion').forEach((btn) => {
    btn.classList.toggle('active', btn.dataset.tema === activo);
  });
}

// Conecta los 3 botones (si la página los tiene) y pinta el estado inicial. La aplicación del
// override guardado a <html> pasa por el <script> inline de <head> de cada página (tiene que
// correr ANTES de pintar para no mostrar el tema equivocado un instante -- un módulo importado
// acá siempre llega tarde para eso). Esta función solo maneja los clicks y el estado visual del
// selector, nunca la carga inicial del atributo.
export function initSelectorTema() {
  document.querySelectorAll('.tema-opcion').forEach((btn) => {
    btn.addEventListener('click', () => fijarTema(btn.dataset.tema));
  });
  pintarSelectorTema();
}
