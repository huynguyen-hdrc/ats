/*
 * Bật xác thực 2 bước (TOTP) qua các route của Fortify:
 * xác nhận mật khẩu → tạo secret → quét QR → nhập mã để xác nhận → lưu mã khôi phục.
 */
import { del, get, post } from '../core/api.js';
import { $, esc, setMsg, submitting } from '../core/dom.js';
import { loadSession } from '../core/session.js';

/** Đảm bảo vừa xác nhận mật khẩu (Fortify yêu cầu trước thao tác nhạy cảm). */
export async function ensurePasswordConfirmed(host) {
  const st = await get('/user/confirmed-password-status');
  if (st?.confirmed) return true;
  return new Promise((resolve) => {
    host.innerHTML = `<form id="fConfirm" class="panel" style="max-width:420px" novalidate>
      <h4>Xác nhận mật khẩu</h4>
      <p class="meta">Nhập lại mật khẩu để tiếp tục thao tác bảo mật này.</p>
      <div class="field"><label for="cpw">Mật khẩu</label><input id="cpw" type="password" autocomplete="current-password"></div>
      <div class="actions"><button class="btn primary" id="bConfirm">Tiếp tục</button></div>
      <div class="msg" id="mConfirm"></div></form>`;
    $('cpw').focus();
    $('fConfirm').onsubmit = (e) => {
      e.preventDefault();
      submitting($('bConfirm'), async () => {
        try { await post('/user/confirm-password', { password: $('cpw').value }); resolve(true); } catch (err) {
          setMsg($('mConfirm'), err.first('password') || err.message, 'err');
        }
      });
    };
  });
}

export function recoveryCodesHTML(codes) {
  return `<div class="codes">${codes.map((c) => `<span class="code">${esc(c)}</span>`).join('')}</div>
    <p class="meta">Mỗi mã dùng được một lần, khi bạn mất điện thoại. Lưu vào nơi an toàn (trình quản lý mật khẩu),
    không gửi qua chat hay email.</p>`;
}

/**
 * Chạy toàn bộ luồng bật 2FA trong phần tử host. onDone() được gọi sau khi user đã lưu mã khôi phục.
 */
export async function runTwoFactorSetup(host, { onDone, intro = '' } = {}) {
  host.innerHTML = '<div class="empty">Đang chuẩn bị…</div>';
  if (!(await ensurePasswordConfirmed(host))) return;

  await post('/user/two-factor-authentication');
  const [qr, key] = await Promise.all([get('/user/two-factor-qr-code'), get('/user/two-factor-secret-key')]);

  // QR do Fortify sinh (SVG từ chính máy chủ), không chứa dữ liệu người dùng nhập.
  host.innerHTML = `<form id="fSetup" class="panel" style="max-width:560px" novalidate>
    <h4>Bật xác thực 2 bước</h4>
    ${intro ? `<p class="meta">${esc(intro)}</p>` : ''}
    <ol class="meta" style="padding-left:18px;margin:6px 0 4px">
      <li>Mở ứng dụng xác thực trên điện thoại (Google Authenticator, Microsoft Authenticator, 1Password…).</li>
      <li>Quét mã QR, hoặc nhập khoá thủ công.</li>
      <li>Nhập mã 6 số ứng dụng hiện ra.</li>
    </ol>
    <div class="qr"><div class="svg">${qr.svg}</div>
      <div><div class="meta">Khoá thủ công</div><div class="code">${esc(key.secretKey)}</div></div></div>
    <div class="field" style="max-width:220px"><label for="otp">Mã 6 số</label>
      <input id="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></div>
    <div class="actions" style="justify-content:flex-start"><button class="btn primary" id="bSetup">Xác nhận & bật</button></div>
    <div class="msg" id="mSetup"></div></form>`;
  $('otp').focus();

  $('fSetup').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bSetup'), async () => {
      try {
        await post('/user/confirmed-two-factor-authentication', { code: $('otp').value.trim() });
      } catch (err) {
        return setMsg($('mSetup'), err.first('code') || 'Mã không đúng. Kiểm tra giờ trên điện thoại rồi thử mã mới.', 'err');
      }
      const codes = await get('/user/two-factor-recovery-codes');
      await loadSession();
      host.innerHTML = `<div class="panel" style="max-width:560px">
        <h4>Đã bật xác thực 2 bước</h4>
        <p class="meta">Đây là mã khôi phục của bạn. Chúng chỉ hiện đầy đủ lúc này.</p>
        ${recoveryCodesHTML(codes)}
        <label class="check-row"><input type="checkbox" id="saved"> Tôi đã lưu các mã này</label>
        <div class="actions" style="justify-content:flex-start"><button class="btn primary" id="bDone" disabled>Tiếp tục</button></div></div>`;
      $('saved').onchange = () => { $('bDone').disabled = !$('saved').checked; };
      $('bDone').onclick = () => onDone?.();
    });
  };
}

export async function disableTwoFactor(host) {
  if (!(await ensurePasswordConfirmed(host))) return false;
  await del('/user/two-factor-authentication');
  await loadSession();
  return true;
}
