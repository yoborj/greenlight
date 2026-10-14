window.themeMainRan = true;
document.addEventListener('DOMContentLoaded', function () {
  var b = document.querySelector('.menu-toggle'), n = document.querySelector('nav');
  if (b) b.addEventListener('click', function () { n.classList.toggle('open'); b.setAttribute('aria-expanded', n.classList.contains('open')); });
  window.themeDomReady = true;
});
