/* Khung ứng dụng sau khi đăng nhập: header, thanh tab theo vai trò gốc, toolbar, vùng nội dung. */
import { post } from './core/api.js';
import { $, closeAll, esc } from './core/dom.js';
import { navigate } from './core/router.js';
import { base, session } from './core/session.js';

const HR = ['admin', 'hr'];
const MGR = ['admin', 'hr', 'manager'];
const ALL = ['admin', 'hr', 'manager', 'staff'];

/**
 * Các tab, giữ đúng thứ tự & phạm vi vai trò của app cũ (VIEWS). `ready` = đã chuyển sang hệ thống mới.
 * Phạm vi theo vai trò gốc chỉ là hiển thị; backend vẫn kiểm từng API.
 */
export const VIEWS = [
  { key: 'dash', path: '/', label: 'Tổng quan', roles: ALL, ready: false },
  { key: 'pipeline', path: '/pipeline', label: 'Tuyển dụng', roles: MGR, ready: true },
  { key: 'jobs', path: '/jobs', label: 'Vị trí & JD', roles: ALL, ready: true },
  { key: 'newhire', path: '/newhire', label: 'Nhân sự mới', roles: ALL, ready: false },
  { key: 'people', path: '/people', label: 'Hồ sơ nhân sự', roles: ALL, ready: false },
  { key: 'org', path: '/org', label: 'Sơ đồ tổ chức', roles: ALL, ready: false },
  { key: 'leave', path: '/leave', label: 'Nghỉ phép', roles: ALL, ready: false },
  { key: 'mail', path: '/mail', label: 'Thư & Email', roles: MGR, ready: false },
  { key: 'settings', path: '/settings', label: 'Phân quyền', roles: HR, ready: true },
];

export const allowedViews = () => VIEWS.filter((v) => v.roles.includes(base()));

let mounted = false;

export function mountShell() {
  if (mounted && $('app')) return;
  $('root').innerHTML = `<div id="app">
    <header>
      <span class="logo">HELIDIRECT</span><span class="brandsub">Human Resource Management</span>
      <span class="spacer"></span>
      <span class="who" id="who"></span>
      <a class="btn" href="/account" data-link>Tài khoản</a>
      <button class="btn ghost" id="btnOut">Thoát</button>
    </header>
    <nav id="nav"></nav>
    <div id="subbar"></div>
    <div class="toolbar" id="toolbar"></div>
    <main id="view"></main>
  </div>`;
  mounted = true;
  $('btnOut').onclick = async () => {
    try { await post('/logout'); } finally { location.href = '/login'; }
  };
}

export function unmountShell() { mounted = false; }

/** Vẽ khung + tab đang chọn, trả về { view, toolbar } để màn hình con vẽ vào. */
export function frame(activeKey) {
  mountShell();
  closeAll();
  $('who').textContent = `${session.me.name} · ${session.role?.label || ''}`;
  const nav = $('nav');
  nav.innerHTML = allowedViews().map((v) =>
    `<button data-path="${v.path}" class="${v.key === activeKey ? 'on' : ''}">${esc(v.label)}</button>`).join('');
  nav.querySelectorAll('button').forEach((b) => { b.onclick = () => navigate(b.dataset.path); });
  moveNavInd();
  const view = $('view');
  view.innerHTML = '';
  $('toolbar').innerHTML = '';
  $('subbar').innerHTML = '';
  view.classList.remove('vin'); void view.offsetWidth; view.classList.add('vin');
  return { view, toolbar: $('toolbar') };
}

function moveNavInd() {
  const nav = $('nav');
  let ind = nav.querySelector('.nav-ind');
  if (!ind) { ind = document.createElement('i'); ind.className = 'nav-ind'; nav.appendChild(ind); }
  const b = nav.querySelector('button.on');
  if (!b) { ind.style.opacity = '0'; return; }
  ind.style.opacity = '1';
  ind.style.width = b.offsetWidth + 'px';
  ind.style.transform = `translateX(${b.offsetLeft}px)`;
}
window.addEventListener('resize', () => $('nav') && moveNavInd());

/** Module chưa chuyển: báo rõ và dẫn sang bản cũ (vẫn chạy trên Supabase). */
export function renderPending(view, v) {
  view.innerHTML = `<div class="notice"><h3>${esc(v.label)} đang được chuyển sang hệ thống mới</h3>
    <p class="meta">Trong thời gian chuyển đổi, module này vẫn dùng ở bản cũ. Dữ liệu ở bản cũ sẽ được chuyển
    toàn bộ sang đây vào ngày chuyển hẳn.</p>
    <a class="btn" href="/legacy/">Mở bản cũ</a></div>`;
}
