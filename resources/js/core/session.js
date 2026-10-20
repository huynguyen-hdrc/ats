/* Phiên đăng nhập hiện tại: user, vai trò, ma trận quyền (nạp từ /api/me). */
import { get } from './api.js';

export const session = { me: null, perms: {}, role: null, twoFactorRequired: false, catalog: null };

export async function loadSession() {
  try {
    const r = await get('/api/me', { quiet401: true });
    Object.assign(session, { me: r.user, perms: r.perms, role: r.role, twoFactorRequired: r.two_factor_required, catalog: r.catalog });
    return true;
  } catch (e) {
    if (e.status === 401) {
      Object.assign(session, { me: null, perms: {}, role: null, twoFactorRequired: false });
      return false;
    }
    throw e;
  }
}

export const isLoggedIn = () => !!session.me;
/** Vai trò gốc: admin | hr | manager | staff. */
export const base = () => session.role?.base || 'staff';
export const isAdmin = () => base() === 'admin';
export const isHR = () => ['admin', 'hr'].includes(base());
/** Quyền module × hành động. Chỉ để ẩn/hiện nút — backend vẫn kiểm lại mọi thao tác. */
export const perm = (module, action) => isAdmin() || !!session.perms?.[module]?.[action];
