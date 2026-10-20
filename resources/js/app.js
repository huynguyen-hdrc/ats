/* Điểm vào của giao diện: nạp phiên, khai báo route, chặn truy cập theo trạng thái đăng nhập. */
import { onTwoFactorRequired, onUnauthorized } from './core/api.js';
import { $, closeAll, toast } from './core/dom.js';
import { fallback, navigate, route, startRouter } from './core/router.js';
import { isLoggedIn, loadSession, session } from './core/session.js';
import { allowedViews, frame, renderPending, unmountShell, VIEWS } from './shell.js';
import { renderAccount } from './views/account.js';
import { renderJobs } from './views/jobs.js';
import { renderPipeline } from './views/pipeline.js';
import { renderForgot, renderInvite, renderLogin, renderReset, renderTwoFactorChallenge } from './views/auth.js';
import { renderSettings } from './views/settings.js';
import { runTwoFactorSetup } from './views/twofactor.js';

// Overlay dùng chung (modal, drawer, toast) — đặt ngoài #root để không bị vẽ lại.
document.body.insertAdjacentHTML('beforeend', `
  <div class="scrim" id="scrim"></div>
  <div class="drawer" id="drawer"><header style="display:flex;align-items:center;padding:12px 16px">
    <strong id="dName" style="flex:1"></strong><button class="btn ghost" id="dClose" aria-label="Đóng">✕</button></header>
    <div class="body" id="dBody"></div></div>
  <div class="modal" id="modal" role="dialog" aria-modal="true"><h2 id="mTitle"></h2><div id="mBody"></div></div>
  <div class="toast" id="toast" role="status"></div>`);
$('scrim').onclick = closeAll;
$('dClose').onclick = closeAll;
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

const GUEST_ONLY = new Set(['/login', '/forgot-password', '/reset-password', '/accept-invite', '/two-factor-challenge']);

/** Bọc route cần đăng nhập. */
const authed = (render) => (ctx) => {
  if (!isLoggedIn()) {
    sessionStorage.setItem('hr.next', location.pathname + location.search);
    return navigate('/login', { replace: true });
  }
  if (session.twoFactorRequired && ctx.path !== '/setup-two-factor') return navigate('/setup-two-factor', { replace: true });
  return render(ctx);
};
const guest = (render) => (ctx) => {
  unmountShell();
  if (isLoggedIn() && ctx.path !== '/reset-password' && ctx.path !== '/accept-invite') return navigate('/', { replace: true });
  return render(ctx);
};

// ---- khách ----
route('/login', guest(renderLogin));
route('/two-factor-challenge', guest(renderTwoFactorChallenge));
route('/forgot-password', guest(renderForgot));
route('/reset-password', guest(renderReset));
route('/accept-invite', guest(renderInvite));

// ---- bắt buộc bật 2FA (admin) ----
route('/setup-two-factor', authed(() => {
  const { view } = frame(null);
  $('nav').innerHTML = '';
  runTwoFactorSetup(view, {
    intro: 'Tài khoản quản trị phải bật xác thực 2 bước trước khi dùng hệ thống.',
    onDone: () => navigate('/', { replace: true }),
  }).catch((e) => toast(e.message, 6000));
}));

route('/account', authed(() => renderAccount(frame(null).view)));

// ---- các tab ----
for (const v of VIEWS) {
  const render = authed((ctx) => {
    if (!allowedViews().includes(v)) return navigate(allowedViews()[0].path, { replace: true });
    const { view, toolbar } = frame(v.key);
    const screens = { settings: renderSettings, jobs: renderJobs, pipeline: renderPipeline };
    if (!v.ready || !screens[v.key]) return renderPending(view, v);
    return Promise.resolve(screens[v.key](view, toolbar, ctx)).catch((e) => toast(e.message, 6000));
  });
  route(v.path, render);
  if (v.key === 'settings') route('/settings/*', render);
}

fallback(authed(() => navigate('/', { replace: true })));

// Hết phiên giữa chừng → về đăng nhập; admin chưa bật 2FA → màn hình bật.
onUnauthorized(() => {
  if (GUEST_ONLY.has(location.pathname)) return;
  session.me = null;
  toast('Phiên đăng nhập đã hết. Đăng nhập lại.', 4000);
  navigate('/login?expired=1', { replace: true });
});
onTwoFactorRequired(() => {
  session.twoFactorRequired = true;
  navigate('/setup-two-factor', { replace: true });
});

loadSession()
  .catch(() => toast('Không kết nối được máy chủ. Thử tải lại trang.', 8000))
  .finally(startRouter);
