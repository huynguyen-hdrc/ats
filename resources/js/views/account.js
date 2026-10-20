/* Tài khoản của tôi: thông tin, đổi mật khẩu, xác thực 2 bước. */
import { get, post, put } from '../core/api.js';
import { $, esc, fmtDateTime, setMsg, showFieldErrors, submitting, toast } from '../core/dom.js';
import { isAdmin, session } from '../core/session.js';
import { disableTwoFactor, ensurePasswordConfirmed, recoveryCodesHTML, runTwoFactorSetup } from './twofactor.js';

export function renderAccount(view) {
  const me = session.me;
  view.innerHTML = `<div class="stack" style="max-width:720px">
    <div class="panel"><h4>${esc(me.name)}</h4>
      <div class="meta">${esc(me.email)} · Vai trò: <b>${esc(session.role?.label || me.role_key)}</b>
      · Lần đăng nhập gần nhất: ${esc(fmtDateTime(me.last_login_at) || '—')}</div></div>

    <form class="panel" id="fPass" novalidate><h4>Đổi mật khẩu</h4>
      <p class="meta">Đổi xong, mọi thiết bị khác sẽ bị đăng xuất.</p>
      <div class="field"><label for="current_password">Mật khẩu hiện tại</label><input id="current_password" type="password" autocomplete="current-password"></div>
      <div class="row2">
        <div class="field"><label for="password">Mật khẩu mới</label><input id="password" type="password" autocomplete="new-password"></div>
        <div class="field"><label for="password_confirmation">Nhập lại</label><input id="password_confirmation" type="password" autocomplete="new-password"></div>
      </div>
      <div class="meta">Tối thiểu 10 ký tự, không dùng mật khẩu đã bị lộ.</div>
      <div class="actions"><button class="btn primary" id="bPass">Đổi mật khẩu</button></div>
      <div class="msg" id="mPass"></div></form>

    <div class="panel"><h4>Xác thực 2 bước</h4><div id="tfa"></div></div>
  </div>`;

  $('fPass').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bPass'), async () => {
      setMsg($('mPass'), '');
      try {
        await put('/user/password', {
          current_password: $('current_password').value,
          password: $('password').value, password_confirmation: $('password_confirmation').value,
        });
        $('fPass').reset();
        showFieldErrors($('fPass'), null);
        setMsg($('mPass'), 'Đã đổi mật khẩu.', 'ok');
      } catch (err) {
        showFieldErrors($('fPass'), err);
        if (!Object.keys(err.errors).length) setMsg($('mPass'), err.message, 'err');
      }
    });
  };

  renderTwoFactorPanel($('tfa'));
}

function renderTwoFactorPanel(host) {
  const on = session.me.two_factor;
  host.innerHTML = on
    ? `<p class="meta"><span class="tag ok">Đang bật</span> Mỗi lần đăng nhập cần thêm mã 6 số từ điện thoại.</p>
       <div class="row-actions"><button class="btn" id="bCodes">Xem / tạo lại mã khôi phục</button>
       <button class="btn ${isAdmin() ? '' : 'danger'}" id="bOff">${isAdmin() ? 'Đổi sang điện thoại khác' : 'Tắt xác thực 2 bước'}</button></div>
       <div id="tfaBody" style="margin-top:12px"></div>`
    : `<p class="meta"><span class="tag">Đang tắt</span> Nên bật để tài khoản an toàn hơn${isAdmin() ? ' (bắt buộc với quản trị viên)' : ''}.</p>
       <button class="btn primary" id="bOn">Bật xác thực 2 bước</button><div id="tfaBody" style="margin-top:12px"></div>`;

  const body = () => $('tfaBody');
  const reload = () => renderTwoFactorPanel(host);

  if (!on) {
    $('bOn').onclick = () => runTwoFactorSetup(body(), { onDone: reload }).catch((e) => toast(e.message, 6000));
    return;
  }
  $('bCodes').onclick = async () => {
    try {
      if (!(await ensurePasswordConfirmed(body()))) return;
      const codes = await get('/user/two-factor-recovery-codes');
      body().innerHTML = `${recoveryCodesHTML(codes)}<button class="btn" id="bRegen">Tạo bộ mã mới (bộ cũ hết hiệu lực)</button>`;
      $('bRegen').onclick = async () => {
        await post('/user/two-factor-recovery-codes');
        body().innerHTML = recoveryCodesHTML(await get('/user/two-factor-recovery-codes'));
        toast('Đã tạo bộ mã khôi phục mới');
      };
    } catch (e) { toast(e.message, 6000); }
  };
  $('bOff').onclick = async () => {
    try {
      if (!(await disableTwoFactor(body()))) return;
      if (isAdmin()) {
        // Quản trị viên không được để trống 2FA: bật lại ngay với điện thoại mới.
        runTwoFactorSetup(body(), { onDone: reload, intro: 'Quét mã mới bằng điện thoại bạn muốn dùng.' });
      } else {
        toast('Đã tắt xác thực 2 bước');
        reload();
      }
    } catch (e) { toast(e.message, 6000); }
  };
}
