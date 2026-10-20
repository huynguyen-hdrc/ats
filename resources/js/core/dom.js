/* Tiện ích DOM dùng chung — giữ cách làm của app cũ: template string + esc(). */

export const $ = (id) => document.getElementById(id);
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
export const nl = (s) => esc(s).replace(/\n/g, '<br>');

export const fmtDate = (s) => (s ? new Date(s).toLocaleDateString('vi-VN') : '');
export const fmtDateTime = (s) => (s ? new Date(s).toLocaleString('vi-VN') : '');

export function toast(msg, ms = 2800) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.add('on');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => t.classList.remove('on'), ms);
}

/* ---------- overlay: modal + drawer (một scrim chung) ---------- */
export function closeAll() {
  ['drawer', 'modal', 'scrim'].forEach((id) => $(id)?.classList.remove('on'));
  if ($('mBody')) $('mBody').innerHTML = '';
  if ($('dBody')) $('dBody').innerHTML = '';
}
export function openModal(title, html) {
  $('mTitle').textContent = title;
  $('mBody').innerHTML = html;
  $('modal').classList.add('on');
  $('scrim').classList.add('on');
  $('mBody').querySelector('input,select,textarea')?.focus();
}
export function openDrawer(title, html) {
  $('dName').textContent = title;
  $('dBody').innerHTML = html;
  $('drawer').classList.add('on');
  $('scrim').classList.add('on');
}

/** Hộp xác nhận ngay trong trang. Trả về Promise<boolean>. */
export function confirmBox(title, message, { ok = 'Đồng ý', danger = false } = {}) {
  return new Promise((resolve) => {
    openModal(title, `<p>${esc(message)}</p>
      <div class="actions"><button class="btn" id="cfNo">Huỷ</button>
      <button class="btn ${danger ? 'danger' : 'primary'}" id="cfYes">${esc(ok)}</button></div>`);
    $('cfNo').onclick = () => { closeAll(); resolve(false); };
    $('cfYes').onclick = () => { closeAll(); resolve(true); };
  });
}

/* ---------- form ---------- */

/** Chạy một thao tác gửi form: khoá nút (chống bấm hai lần), hiện lỗi từng trường. */
export async function submitting(button, fn) {
  if (button.disabled) return;
  button.disabled = true;
  try { return await fn(); } finally { button.disabled = false; }
}

/** Hiện lỗi validation của Laravel dưới các ô input có id = prefix + tên trường. */
export function showFieldErrors(root, err, prefix = '') {
  root.querySelectorAll('.err-text').forEach((n) => n.remove());
  Object.entries(err?.errors || {}).forEach(([field, msgs]) => {
    const input = root.querySelector(`#${CSS.escape(prefix + field)}`);
    if (!input) return;
    const p = document.createElement('div');
    p.className = 'err-text';
    p.textContent = msgs[0];
    input.closest('.field')?.appendChild(p);
  });
}

export function setMsg(el, text, kind = '') {
  if (!el) return;
  el.className = 'msg' + (kind ? ' ' + kind : '');
  el.textContent = text || '';
}
