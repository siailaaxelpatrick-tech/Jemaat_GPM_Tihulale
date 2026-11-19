/* Jembatan localStorage <-> MySQL lewat WordPress REST API. Dimuat SEBELUM portal.js.
   - Pengunjung: hanya membaca data publik (tanpa data pribadi warga), tidak bisa menulis.
   - Staf: masuk lewat akun WordPress; data penuh dibaca/ditulis dengan nonce. Peran portal diambil dari daftar pengguna portal
     (dicocokkan lewat email/username akun WordPress). */
(function () {
  'use strict';
  const CFG = window.GPM_WP || {};
  const API = CFG.api, NONCE = CFG.nonce || '', USER = CFG.user || null, STAF = !!USER;
  const skip = k => !/^gpm_[a-z0-9_]+$/.test(k) || k === 'gpm_sid' || k === 'gpm_lock';
  const rawSet = Storage.prototype.setItem, rawDel = Storage.prototype.removeItem;
  let online = false, pending = {}, timer = null, ditolak = false;

  /* ---- penanda status: hanya untuk staf ---- */
  let badge = null;
  function status(teks, warna) {
    if (!STAF) return;
    const pasang = () => {
      if (!badge) {
        badge = document.createElement('div');
        badge.style.cssText = 'position:fixed;left:10px;bottom:10px;z-index:99999;font:12px system-ui,Arial,sans-serif;padding:4px 10px;border-radius:12px;color:#fff;opacity:.9;pointer-events:none';
        document.body.appendChild(badge);
      }
      badge.textContent = teks; badge.style.background = warna;
    };
    if (document.body) pasang(); else document.addEventListener('DOMContentLoaded', pasang);
  }
  function pesan(teks) {
    const tampil = () => {
      const d = document.createElement('div');
      d.setAttribute('role', 'alert');
      d.style.cssText = 'position:fixed;left:50%;top:16px;transform:translateX(-50%);z-index:99999;max-width:92vw;font:14px system-ui,Arial,sans-serif;padding:10px 16px;border-radius:10px;color:#fff;background:#c0392b;box-shadow:0 4px 16px rgba(0,0,0,.25)';
      d.textContent = teks; document.body.appendChild(d);
      setTimeout(() => d.remove(), 7000);
    };
    if (document.body) tampil(); else document.addEventListener('DOMContentLoaded', tampil);
  }

  function header(xhr) { if (NONCE) xhr.setRequestHeader('X-WP-Nonce', NONCE); }

  /* ---- kirim perubahan (hanya staf) ---- */
  function queue(items) { Object.assign(pending, items); clearTimeout(timer); timer = setTimeout(flush, 300); }
  function flush() {
    const items = pending; pending = {};
    if (!Object.keys(items).length) return;
    status('Database: menyimpan…', '#b8860b');
    fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE }, body: JSON.stringify({ items }) })
      .then(r => r.text().then(t => {
        let j; try { j = JSON.parse(t); } catch (e) { throw new Error('Server tidak membalas JSON'); }
        if (!r.ok || !j.ok) { const e = new Error(j.message || j.error || ('HTTP ' + r.status)); e.status = r.status; throw e; }
        return j;
      }))
      .then(j => {
        if (j.cermin_gagal && Object.keys(j.cermin_gagal).length) {
          console.warn('[db-sync] tersimpan, tetapi gagal disalin ke tabel terstruktur:', j.cermin_gagal);
          status('Database: tersimpan, tetapi tabel ' + Object.keys(j.cermin_gagal).join(', ') + ' gagal — ' + String(Object.values(j.cermin_gagal)[0]).slice(0, 140), '#c0392b');
        } else status('Database: tersimpan ✓', '#2e9e4f');
      })
      .catch(e => {
        console.warn('[db-sync] gagal menyimpan:', e.message || e);
        if (e.status === 401 || e.status === 403) { status('Sesi WordPress berakhir — muat ulang dan login lagi', '#c0392b'); return; }
        status('Database: GAGAL menyimpan — ' + (e.message || e), '#c0392b');
        Object.assign(pending, items); clearTimeout(timer); timer = setTimeout(flush, 5000);
      });
  }
  window.addEventListener('pagehide', () => { if (STAF && online) flush(); });

  /* ---- 1) ambil data (sinkron, supaya portal.js langsung membaca data terbaru) ---- */
  try {
    const x = new XMLHttpRequest();
    x.open('GET', API, false); x.withCredentials = true; header(x); x.send();
    if (x.status === 200) {
      const res = JSON.parse(x.responseText);
      if (res && res.ok) {
        online = true;
        const srv = res.data || {}, migrasi = {};
        Object.keys(srv).forEach(k => { if (!skip(k)) rawSet.call(localStorage, k, srv[k]); });
        if (STAF && res.auth) {
          // data lama di peramban yang belum ada di database dikirim sekali
          for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (!skip(k) && !(k in srv)) migrasi[k] = localStorage.getItem(k);
          }
        } else {
          // pengunjung/keluar: buang sisa data pribadi yang mungkin tertinggal di peramban ini
          Object.keys(localStorage).forEach(k => { if (!skip(k) && !(k in srv)) rawDel.call(localStorage, k); });
        }
        status('Database: terhubung ✓', '#2e9e4f');

        /* ---- 2) masuk otomatis: akun WordPress -> pengguna portal ---- */
        if (STAF && res.auth) {
          let list = []; try { list = JSON.parse(localStorage.getItem('gpm_users') || '[]'); } catch (e) {}
          if (!Array.isArray(list)) list = [];
          const em = String(USER.email || '').toLowerCase(), lg = String(USER.login || '').toLowerCase();
          let u = list.find(r => r && ((String(r.email || '').toLowerCase() === em && em) || String(r.username || '').toLowerCase() === lg));
          let ubah = false;
          if (!u && USER.admin) {
            u = { id: Date.now().toString(36) + Math.random().toString(36).slice(2, 7), nama: USER.name || lg,
              username: (lg.replace(/[^a-z0-9_.]/g, '').slice(0, 20) || 'admin'), email: em, role: 'admin', sektor: '',
              status: 'aktif', financeAccess: false, created: new Date().toISOString() };
            list.push(u); ubah = true;
          } else if (u && USER.admin && u.status !== 'aktif') { u.status = 'aktif'; ubah = true; }
          if (ubah) { rawSet.call(localStorage, 'gpm_users', JSON.stringify(list)); migrasi.gpm_users = localStorage.getItem('gpm_users'); }
          if (u && u.status === 'aktif') { const cur = sessionStorage.getItem('gpm_sid'), keep = USER.admin && cur && list.some(r => r && r.alias && r.id === cur && r.status === 'aktif'); if (!keep) rawSet.call(sessionStorage, 'gpm_sid', u.id); rawDel.call(localStorage, 'gpm_sid'); }
          else { rawDel.call(sessionStorage, 'gpm_sid'); ditolak = true; }
        } else { rawDel.call(sessionStorage, 'gpm_sid'); rawDel.call(localStorage, 'gpm_sid'); }
        if (STAF) queue(migrasi);
      }
    }
    if (!online) throw new Error('HTTP ' + x.status);
  } catch (e) {
    console.warn('[db-sync] server tidak tersedia, memakai localStorage saja:', e.message || e);
    status('Database: TIDAK terhubung', '#c0392b');
  }

  /* ---- 3) tulis setiap perubahan ke database (hanya staf) ---- */
  if (online && STAF) {
    Storage.prototype.setItem = function (k, v) {
      rawSet.call(this, k, v);
      if (this === localStorage && !skip(k)) queue({ [k]: String(v) });
    };
    Storage.prototype.removeItem = function (k) {
      rawDel.call(this, k);
      if (this === localStorage && !skip(k)) queue({ [k]: null });
    };
  }

  /* ---- 4) tombol Login/Keluar mengikuti sesi WordPress ---- */
  document.addEventListener('click', e => {
    const t = e.target;
    if (!t || !t.closest) return;
    if (t.closest('[data-auth]') && !sessionStorage.getItem('gpm_sid')) {
      e.preventDefault(); e.stopImmediatePropagation();
      if (STAF && ditolak) pesan('Akun WordPress Anda belum terdaftar di Pengguna portal. Hubungi Admin.');
      else if (CFG.sesi && !STAF) pesan('Akun WordPress Anda tidak memiliki akses ke dashboard portal. Hubungi Admin.');
      else if (CFG.loginUrl) location.href = CFG.loginUrl;
    }
    if (STAF && t.closest('#logout') && CFG.logoutUrl) setTimeout(() => { location.href = CFG.logoutUrl; }, 200);
  }, true);

  /* setelah login WordPress, buka dashboard otomatis */
  window.addEventListener('load', () => {
    if (location.hash === '#masuk' && sessionStorage.getItem('gpm_sid')) {
      history.replaceState(null, '', location.pathname + location.search);
      const b = document.getElementById('navLogin'); if (b) b.click();
    }
  });

  /* ---- 5) Admin: "Masuk sebagai" peran lain dengan satu akun ---- */
  window.addEventListener('load', () => {
    if (!(STAF && USER.admin && online)) return;
    let list = []; try { list = JSON.parse(localStorage.getItem('gpm_users') || '[]'); } catch (e) {}
    const al = (Array.isArray(list) ? list : []).filter(r => r && r.alias && r.status === 'aktif');
    if (!al.length) return;
    const em = String(USER.email || '').toLowerCase(), lg = String(USER.login || '').toLowerCase();
    const asli = list.find(r => r && !r.alias && ((r.email && String(r.email).toLowerCase() === em) || String(r.username || '').toLowerCase() === lg));
    const sel = document.createElement('select');
    sel.title = 'Masuk sebagai';
    sel.style.cssText = 'position:fixed;right:10px;bottom:10px;z-index:99999;font:13px system-ui,Arial,sans-serif;padding:6px 8px;border-radius:8px;border:1px solid #888;background:#fff;color:#222';
    const opsi = (v, t) => { const o = document.createElement('option'); o.value = v; o.textContent = t; sel.appendChild(o); };
    opsi('', 'Masuk sebagai…');
    if (asli) opsi(asli.id, 'Admin (akun asli)');
    al.forEach(r => opsi(r.id, r.nama.replace(/^.*\((.*)\)$/, '$1')));
    sel.value = sessionStorage.getItem('gpm_sid') || '';
    sel.addEventListener('change', () => {
      if (!sel.value) return;
      rawSet.call(sessionStorage, 'gpm_sid', sel.value);
      location.href = location.pathname + location.search + '#masuk'; location.reload();
    });
    document.body.appendChild(sel);
  });
})();
