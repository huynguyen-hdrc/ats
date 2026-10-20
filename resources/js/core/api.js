/*
 * Gọi API cùng domain bằng phiên cookie. Laravel đặt cookie XSRF-TOKEN trên mọi response của nhóm web;
 * mỗi request ghi gửi lại nó qua header X-XSRF-TOKEN.
 */

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Lỗi ${status}`);
    this.status = status;
    this.body = body;
    this.code = body?.code;
    this.errors = body?.errors || {};
  }
  /** Lỗi đầu tiên của một trường, hoặc thông báo chung. */
  first(field) {
    return (this.errors[field] || [])[0] || '';
  }
}

const hooks = { unauthorized: null, twoFactorRequired: null };
export const onUnauthorized = (fn) => { hooks.unauthorized = fn; };
export const onTwoFactorRequired = (fn) => { hooks.twoFactorRequired = fn; };

function xsrf() {
  const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

async function send(method, url, data) {
  const opt = {
    method,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
  };
  if (data instanceof FormData) opt.body = data;
  else if (data !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = JSON.stringify(data);
  }
  return fetch(url, opt);
}

async function parse(res) {
  if (res.status === 204) return null;
  const text = await res.text();
  if (!text) return null;
  try { return JSON.parse(text); } catch { return { message: text.slice(0, 300) }; }
}

export async function api(method, url, data, { quiet401 = false } = {}) {
  let res = await send(method, url, data);
  // 419: token CSRF hết hạn (phiên cũ). Lấy cookie mới rồi thử lại đúng một lần.
  if (res.status === 419) {
    await fetch('/login', { credentials: 'same-origin', headers: { Accept: 'text/html' } });
    res = await send(method, url, data);
  }
  const body = await parse(res);
  if (!res.ok) {
    const err = new ApiError(res.status, body);
    if (res.status === 419) err.message = 'Phiên làm việc đã hết hạn. Tải lại trang rồi thử lại.';
    if (res.status === 401 && !quiet401 && hooks.unauthorized) hooks.unauthorized(err);
    if (res.status === 403 && err.code === 'two_factor_required' && hooks.twoFactorRequired) hooks.twoFactorRequired(err);
    if (res.status >= 500 && !body?.message) err.message = 'Máy chủ gặp lỗi. Thử lại sau ít phút.';
    throw err;
  }
  return body;
}

export const get = (url, opts) => api('GET', url, undefined, opts);
export const post = (url, data, opts) => api('POST', url, data ?? {}, opts);
export const put = (url, data) => api('PUT', url, data ?? {});
export const patch = (url, data) => api('PATCH', url, data ?? {});
export const del = (url) => api('DELETE', url);

/** Ghép query string, bỏ giá trị rỗng. */
export function qs(params) {
  const p = new URLSearchParams();
  Object.entries(params || {}).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); });
  const s = p.toString();
  return s ? `?${s}` : '';
}
