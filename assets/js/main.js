// Mobile menu
const burger = document.querySelector('.burger');
const menu = document.querySelector('.menu');
burger.addEventListener('click', () => {
  const open = menu.classList.toggle('open');
  burger.setAttribute('aria-expanded', open);
});
menu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => menu.classList.remove('open')));

// Falling sakura petals in hero
const box = document.querySelector('.petals');
for (let i = 0; i < 14; i++) {
  const p = document.createElement('i');
  p.style.left = Math.random() * 100 + '%';
  p.style.animationDuration = 8 + Math.random() * 8 + 's';
  p.style.animationDelay = -Math.random() * 12 + 's';
  p.style.transform = `scale(${0.6 + Math.random()})`;
  box.appendChild(p);
}
