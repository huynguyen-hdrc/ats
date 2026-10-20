/* Màn hình khi chưa đăng nhập: đăng nhập, mã 2FA, quên mật khẩu, đặt lại mật khẩu, nhận lời mời. */
import { post } from '../core/api.js';
import { $, esc, setMsg, showFieldErrors, submitting } from '../core/dom.js';
import { navigate } from '../core/router.js';
import { loadSession } from '../core/session.js';

const PW_HINT = 'Tối thiểu 10 ký tự. Tránh mật khẩu phổ biến hoặc đã dùng ở nơi khác.';

function card(inner) {
  $('root').innerHTML = `<div class="auth-card">
    <h1>HELIDIRECT</h1>
    <div class="brandsub" style="margin:0 0 18px;padding:0;border:0">Human Resource Management</div>
    ${inner}
  </div>`;
}

async function afterLogin() {
  await loadSession();
  const next = sessionStorage.getItem('hr.next') || '/';
  sessionStorage.removeItem('hr.next');
  navigate(next.startsWith('/') && !next.startsWith('//') ? next : '/', { replace: true });
}

export function renderLogin({ params }) {
  card(`<p>Đăng nhập bằng email công ty và mật khẩu.</p>
    <form id="fLogin" novalidate>
      <div class="field"><label for="email">Email</label>
        <input id="email" type="email" autocomplete="username" required placeholder="ban@helidirect.com" value="${esc(params.email || '')}"></div>
      <div class="field"><label for="password">Mật khẩu</label>
        <input id="password" type="password" autocomplete="current-password" required></div>
      <label class="check-row"><input type="checkbox" id="remember"> Ghi nhớ đăng nhập trên máy này (30 ngày)</label>
      <button class="btn primary" style="width:100%" id="bLogin">Đăng nhập</button>
      <div class="msg" id="mLogin"></div>
    </form>
    <div class="links"><a href="/forgot-password" data-link>Quên mật khẩu?</a><a href="/legacy/">Bản cũ</a></div>`);
  if (params.reset === '1') setMsg($('mLogin'), 'Đã đặt mật khẩu mới. Đăng nhập lại.', 'ok');
  if (params.invited === '1') setMsg($('mLogin'), 'Tài khoản đã kích hoạt. Đăng nhập để bắt đầu.', 'ok');
  if (params.expired === '1') setMsg($('mLogin'), 'Phiên đăng nhập đã hết. Đăng nhập lại.', 'err');
  $(params.email ? 'password' : 'email').focus();

  $('fLogin').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bLogin'), async () => {
      setMsg($('mLogin'), '');
      try {
        const r = await post('/login', {
          email: $('email').value.trim(), password: $('password').value, remember: $('remember').checked,
        });
        if (r?.two_factor) return navigate('/two-factor-challenge', { replace: true });
        await afterLogin();
      } catch (err) {
        setMsg($('mLogin'), err.first('email') || err.first('password') || err.message, 'err');
        $('password').value = '';
        $('password').focus();
      }
    });
  };
}

export function renderTwoFactorChallenge() {
  card(`<p>Nhập mã 6 số trong ứng dụng xác thực (Google Authenticator, Microsoft Authenticator…).</p>
    <form id="f2fa" novalidate>
      <div class="field" id="wCode"><label for="code">Mã xác thực</label>
        <input id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*"></div>
      <div class="field hide" id="wRec"><label for="recovery_code">Mã khôi phục</label>
        <input id="recovery_code" autocomplete="off" placeholder="xxxxxxxxxx-xxxxxxxxxx"></div>
      <button class="btn primary" style="width:100%" id="b2fa">Xác nhận</button>
      <div class="msg" id="m2fa"></div>
    </form>
    <div class="links"><a href="#" id="toggleRec">Dùng mã khôi phục</a><a href="/login" data-link>Quay lại</a></div>`);
  let useRec = false;
  $('code').focus();
  $('toggleRec').onclick = (e) => {
    e.preventDefault();
    useRec = !useRec;
    $('wCode').classList.toggle('hide', useRec);
    $('wRec').classList.toggle('hide', !useRec);
    $('toggleRec').textContent = useRec ? 'Dùng mã 6 số' : 'Dùng mã khôi phục';
    $(useRec ? 'recovery_code' : 'code').focus();
  };
  $('f2fa').onsubmit = (e) => {
    e.preventDefault();
    submitting($('b2fa'), async () => {
      try {
        await post('/two-factor-challenge', useRec
          ? { recovery_code: $('recovery_code').value.trim() }
          : { code: $('code').value.trim() });
        await afterLogin();
      } catch (err) {
        if (err.status === 422 && !Object.keys(err.errors).length) err.message = 'Mã không đúng.';
        // Phiên chờ 2FA đã mất (quá lâu, hoặc tải lại trang): đăng nhập lại từ đầu.
        if (err.status === 422 && /expired|login/i.test(err.message)) return navigate('/login?expired=1', { replace: true });
        setMsg($('m2fa'), err.first('code') || err.first('recovery_code') || err.message, 'err');
      }
    });
  };
}

export function renderForgot({ params }) {
  card(`<p>Nhập email công ty. Nếu email có tài khoản, hệ thống gửi link đặt lại mật khẩu (hạn 60 phút).</p>
    <form id="fForgot" novalidate>
      <div class="field"><label for="email">Email</label>
        <input id="email" type="email" autocomplete="username" value="${esc(params.email || '')}"></div>
      <button class="btn primary" style="width:100%" id="bForgot">Gửi link đặt lại</button>
      <div class="msg" id="mForgot"></div>
    </form>
    <div class="links"><a href="/login" data-link>Quay lại đăng nhập</a></div>`);
  $('email').focus();
  $('fForgot').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bForgot'), async () => {
      const email = $('email').value.trim();
      if (!email) return setMsg($('mForgot'), 'Nhập email.', 'err');
      try { await post('/forgot-password', { email }); } catch (err) {
        // Email không tồn tại cũng hiện cùng một thông báo, để không lộ ai có tài khoản.
        if (err.status === 429) return setMsg($('mForgot'), 'Bạn vừa yêu cầu. Đợi một phút rồi thử lại.', 'err');
        if (err.status !== 422) return setMsg($('mForgot'), err.message, 'err');
      }
      setMsg($('mForgot'), `Nếu ${email} có tài khoản, thư đặt lại mật khẩu đã được gửi. Kiểm tra cả mục Spam.`, 'ok');
    });
  };
}

function passwordForm({ intro, button, endpoint, params, done }) {
  if (!params.token || !params.email) {
    card(`<p>Link không đầy đủ. Mở lại link trong email, hoặc yêu cầu link mới.</p>
      <div class="links"><a href="/forgot-password" data-link>Yêu cầu link mới</a><a href="/login" data-link>Đăng nhập</a></div>`);
    return;
  }
  card(`<p>${intro}</p>
    <form id="fPw" novalidate>
      <div class="field"><label>Email</label><input id="email" type="email" value="${esc(params.email)}" disabled autocomplete="username"></div>
      <div class="field"><label for="password">Mật khẩu mới</label>
        <input id="password" type="password" autocomplete="new-password"><div class="meta">${PW_HINT}</div></div>
      <div class="field"><label for="password_confirmation">Nhập lại mật khẩu</label>
        <input id="password_confirmation" type="password" autocomplete="new-password"></div>
      <button class="btn primary" style="width:100%" id="bPw">${button}</button>
      <div class="msg" id="mPw"></div>
    </form>`);
  $('password').focus();
  $('fPw').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bPw'), async () => {
      setMsg($('mPw'), '');
      if ($('password').value !== $('password_confirmation').value) {
        return setMsg($('mPw'), 'Hai lần nhập mật khẩu không khớp.', 'err');
      }
      try {
        await post(endpoint, {
          token: params.token, email: params.email,
          password: $('password').value, password_confirmation: $('password_confirmation').value,
        });
        navigate(done, { replace: true });
      } catch (err) {
        showFieldErrors($('fPw'), err);
        setMsg($('mPw'), err.first('email') || (err.errors.password ? '' : err.message), 'err');
      }
    });
  };
}

export function renderReset({ params }) {
  passwordForm({
    intro: 'Đặt mật khẩu mới cho tài khoản của bạn. Mọi thiết bị khác sẽ bị đăng xuất.',
    button: 'Đặt mật khẩu mới', endpoint: '/reset-password', params,
    done: `/login?reset=1&email=${encodeURIComponent(params.email || '')}`,
  });
}

export function renderInvite({ params }) {
  passwordForm({
    intro: 'Chào mừng bạn đến với Helidirect HR. Đặt mật khẩu để kích hoạt tài khoản.',
    button: 'Kích hoạt tài khoản', endpoint: '/accept-invite', params,
    done: `/login?invited=1&email=${encodeURIComponent(params.email || '')}`,
  });
}
