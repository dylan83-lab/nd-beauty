/* ND Japanese Head Spa — pustaka bersama */
const ND = {
  base: (document.body.dataset.base || '').replace(/\/+$/, ''),
  csrf: document.querySelector('meta[name=csrf]')?.content || '',
  role: document.body.dataset.role || '',
};
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const rp = n => 'Rp' + Math.round(Number(n) || 0).toLocaleString('id-ID');
const HARI = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const BLN = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const todayStr = () => ymd(new Date());
const addDays = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return ymd(d); };
const url = p => ND.base + '/' + p.replace(/^\//, '');

/** Endpoint baca (GET). Selain ini dikirim sebagai POST dengan token CSRF. */
const READ_API = /^(slots|queue|poll|book\.catalog|booking\.mine|work\.(list|materials)|att\.(today|mine|list)|corr\.list|cust\.(search|get)|pos\.(catalog|queue|get|receipts|refund_list)|cash\.(status|accounts|list|sessions)|stock\.(list|card)|sched\.get|dash|report|crud\.list|settings\.get|svc\.detail|promo\.list)$/;
async function api(name, data, method) {
  method = method || (READ_API.test(name) ? 'GET' : 'POST');
  let u = url('api/' + name), opt = { method, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' };
  if (method === 'GET') { if (data) u += '?' + new URLSearchParams(Object.fromEntries(Object.entries(data).map(([k, v]) => [k, typeof v === 'object' ? JSON.stringify(v) : v]))); }
  else { opt.headers['Content-Type'] = 'application/json'; opt.headers['X-CSRF'] = ND.csrf; opt.body = JSON.stringify(data || {}); }
  let res, j;
  try { res = await fetch(u, opt); } catch (e) { const er = new Error('Koneksi terputus. Periksa internet Anda lalu coba lagi.'); er.network = true; throw er; }
  try { j = await res.json(); } catch (e) { j = { ok: false, error: 'Respons server tidak valid.' }; }
  if (!res.ok || j.ok === false) { const er = new Error(j.error || 'Terjadi kesalahan.'); er.status = res.status; er.data = j; throw er; }
  return j;
}

function toast(msg, type) {
  const t = document.createElement('div'); t.className = 'toast ' + (type || ''); t.textContent = msg;
  $('#toasts').appendChild(t); setTimeout(() => t.remove(), type === 'bad' ? 6000 : 3500);
}
const fail = e => toast(e.message || 'Terjadi kesalahan', 'bad');

/** Modal: kembalikan elemen; tutup dengan closeModal(). */
function modal(html, cls) {
  closeModal();
  const bg = document.createElement('div'); bg.className = 'modal-bg';
  bg.innerHTML = `<div class="modal ${cls || ''}" role="dialog" aria-modal="true">${html}</div>`;
  bg.addEventListener('mousedown', e => { if (e.target === bg) closeModal(); });
  $('#modal-root').appendChild(bg);
  const f = $('input,select,textarea', bg); if (f && window.innerWidth > 760) f.focus();
  return bg.firstElementChild;
}
function closeModal() { $('#modal-root').innerHTML = ''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function confirmBox(msg, okLabel) {
  return new Promise(res => {
    const m = modal(`<h3>Konfirmasi</h3><p>${esc(msg)}</p><div class="acts"><button class="btn ghost" data-x>Batal</button><button class="btn" data-ok>${esc(okLabel || 'Ya, lanjutkan')}</button></div>`);
    $('[data-x]', m).onclick = () => { closeModal(); res(false); };
    $('[data-ok]', m).onclick = () => { closeModal(); res(true); };
  });
}

/** Cegah klik ganda: nonaktifkan tombol selama proses. */
async function busy(btn, fn) {
  if (btn.disabled) return;
  const old = btn.innerHTML; btn.disabled = true;
  try { return await fn(); } finally { btn.disabled = false; btn.innerHTML = old; }
}

const badge = (cls, label) => `<span class="badge b-${esc(cls)}">${esc(label)}</span>`;

/** Segarkan otomatis saat ada perubahan data (reservasi, antrean, pembayaran). */
function livePoll(onChange, every) {
  let last = null, stop = false;
  const tick = async () => {
    if (stop) return;
    if (!document.hidden) {
      try {
        const r = await api('poll');
        if (r.pending !== undefined) { const d = $('#pendDot'); if (d) d.hidden = !r.pending; }
        if (last !== null && r.rev !== last) { last = r.rev; onChange && onChange(r); } else last = r.rev;
      } catch (e) { if (e.status === 401) stop = true; }
    }
    setTimeout(tick, every || 5000);
  };
  tick();
}

// tema & menu
$('#themeBtn')?.addEventListener('click', () => {
  const d = document.documentElement; const n = d.dataset.theme === 'dark' ? 'light' : 'dark'; d.dataset.theme = n;
  try { localStorage.setItem('nd-theme', n); } catch (e) { }
});
$('#menuBtn')?.addEventListener('click', () => $('#side').classList.toggle('open'));
document.addEventListener('click', e => { const s = $('#side'); if (s && s.classList.contains('open') && !s.contains(e.target) && e.target.id !== 'menuBtn' && !e.target.closest('#menuBtn')) s.classList.remove('open'); });
if (document.body.classList.contains('area-staff')) { livePoll(); }

// jam berjalan WIB di bilah atas
(function () {
  const c = $('#clock'); if (!c) return;
  const base = c.textContent;
  setInterval(() => { const d = new Date(); c.textContent = base.split(' · ')[0] + ' · ' + d.toLocaleTimeString('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).replace('.', ':') + ' WIB'; }, 15000);
  c.textContent = base + ' · ' + new Date().toLocaleTimeString('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).replace('.', ':') + ' WIB';
})();
