/* Phân quyền: người dùng (mời, khoá, đổi vai trò), vai trò & ma trận quyền, nhật ký thao tác. */
import { del, get, patch, post, put, qs } from '../core/api.js';
import { $, closeAll, confirmBox, esc, fmtDateTime, openDrawer, openModal, setMsg, showFieldErrors, submitting, toast } from '../core/dom.js';
import { navigate } from '../core/router.js';
import { isAdmin, perm, session } from '../core/session.js';

const STATUS = {
  invited: ['Đã mời', 'soon'],
  active: ['Hoạt động', 'ok'],
  locked: ['Đã khoá', 'warn'],
};
const statusTag = (s) => `<span class="tag ${STATUS[s]?.[1] || ''}">${esc(STATUS[s]?.[0] || s)}</span>`;

const TABS = [['users', 'Người dùng'], ['roles', 'Vai trò & quyền'], ['audit', 'Nhật ký']];

export function renderSettings(view, toolbar, { path, params }) {
  const tab = path.split('/')[2] || 'users';
  $('subbar').innerHTML = `<div class="subnav">${TABS.map(([k, l]) =>
    `<button data-tab="${k}" class="${k === tab ? 'on' : ''}">${l}</button>`).join('')}</div>`;
  view.innerHTML = '<div id="tabBody"></div>';
  $('subbar').querySelectorAll('[data-tab]').forEach((b) => { b.onclick = () => navigate(`/settings/${b.dataset.tab}`); });
  ({ users: tabUsers, roles: tabRoles, audit: tabAudit })[tab]?.($('tabBody'), toolbar, params);
}

/* =================== NGƯỜI DÙNG =================== */
let rolesCache = [];
async function loadRoles() {
  rolesCache = (await get('/api/roles')).data;
  return rolesCache;
}
const roleLabel = (k) => rolesCache.find((r) => r.key === k)?.label || k;
const assignable = () => rolesCache.filter((r) => isAdmin() || r.base !== 'admin');

async function tabUsers(host, toolbar) {
  const st = { q: '', status: '' };
  toolbar.innerHTML = `<input type="search" id="uq" placeholder="Tìm tên, email...">
    <select id="ust"><option value="">Mọi trạng thái</option>
      ${Object.entries(STATUS).map(([k, [l]]) => `<option value="${k}">${l}</option>`).join('')}</select>
    <span class="spacer"></span>
    ${perm('settings', 'create') ? '<button class="btn primary" id="bInvite">+ Mời người dùng</button>' : ''}`;

  await loadRoles();
  let users = [];
  const draw = () => {
    const q = st.q.toLowerCase();
    const rows = users.filter((u) => (!st.status || u.status === st.status)
      && (!q || u.name.toLowerCase().includes(q) || u.email.includes(q)));
    host.innerHTML = rows.length ? `<div class="tbl-wrap"><table class="grid"><thead><tr>
      <th>Họ tên</th><th>Email</th><th>Vai trò</th><th>Trạng thái</th><th>2 bước</th><th>Đăng nhập gần nhất</th></tr></thead>
      <tbody>${rows.map((u) => `<tr data-id="${esc(u.id)}"><td><b>${esc(u.name)}</b>${u.id === session.me.id ? ' <span class="tag">bạn</span>' : ''}</td>
        <td>${esc(u.email)}</td><td>${esc(roleLabel(u.role_key))}</td><td>${statusTag(u.status)}</td>
        <td>${u.two_factor ? '<span class="tag ok">Bật</span>' : '<span class="meta">—</span>'}</td>
        <td class="meta">${esc(fmtDateTime(u.last_login_at) || (u.status === 'invited' ? 'Mời ' + fmtDateTime(u.invited_at) : '—'))}</td></tr>`).join('')}
      </tbody></table></div>` : '<div class="empty">Không có người dùng phù hợp.</div>';
    host.querySelectorAll('tr[data-id]').forEach((tr) => {
      tr.onclick = () => openUser(users.find((u) => u.id === tr.dataset.id), reload);
    });
  };
  const reload = async () => { users = (await get('/api/users')).data; draw(); };

  $('uq').oninput = (e) => { st.q = e.target.value; draw(); };
  $('ust').onchange = (e) => { st.status = e.target.value; draw(); };
  if ($('bInvite')) $('bInvite').onclick = () => openInvite(reload);
  await reload();
}

function roleOptions(selected) {
  return assignable().map((r) => `<option value="${esc(r.key)}" ${r.key === selected ? 'selected' : ''}>${esc(r.label)}</option>`).join('');
}

function openInvite(reload) {
  openModal('Mời người dùng', `<form id="fInv" novalidate>
    <div class="row2">
      <div class="field"><label for="name">Họ tên *</label><input id="name" autocomplete="off"></div>
      <div class="field"><label for="email">Email công ty *</label><input id="email" type="email" autocomplete="off" placeholder="ten@helidirect.com"></div>
      <div class="field"><label for="role_key">Vai trò *</label><select id="role_key">${roleOptions('staff')}</select></div>
    </div>
    <p class="meta">Người được mời nhận email có link đặt mật khẩu, hạn 72 giờ. Liên kết với hồ sơ nhân sự
      sẽ làm được khi module Hồ sơ nhân sự chuyển sang hệ thống mới.</p>
    <div class="actions"><button type="button" class="btn" id="bCancel">Huỷ</button><button class="btn primary" id="bSend">Gửi lời mời</button></div>
    <div class="msg" id="mInv"></div></form>`);
  $('bCancel').onclick = closeAll;
  $('fInv').onsubmit = (e) => {
    e.preventDefault();
    submitting($('bSend'), async () => {
      try {
        const r = await post('/api/users', { name: $('name').value.trim(), email: $('email').value.trim(), role_key: $('role_key').value });
        closeAll();
        toast(r.invite_sent ? `Đã gửi lời mời tới ${r.data.email}` : `Đã tạo tài khoản nhưng chưa gửi được email (SMTP chưa cấu hình). Bấm "Gửi lại lời mời" sau.`, 7000);
        reload();
      } catch (err) {
        showFieldErrors($('fInv'), err);
        if (!Object.keys(err.errors).length) setMsg($('mInv'), err.message, 'err');
      }
    });
  };
}

function openUser(u, reload) {
  const self = u.id === session.me.id;
  const lockedOut = u.base === 'admin' && !isAdmin(); // HR không đụng được tài khoản admin
  const canEdit = perm('settings', 'edit') && !lockedOut;
  openDrawer(u.name, `<div class="meta" style="margin-bottom:12px">${esc(u.email)} · ${statusTag(u.status)}
      ${u.two_factor ? ' <span class="tag ok">2 bước</span>' : ''}</div>
    <form id="fUser" novalidate>
      <div class="field"><label for="uname">Họ tên</label><input id="uname" value="${esc(u.name)}" ${canEdit ? '' : 'disabled'}></div>
      <div class="field"><label for="urole">Vai trò</label>
        <select id="urole" ${canEdit && !self ? '' : 'disabled'}>${lockedOut ? `<option>${esc(roleLabel(u.role_key))}</option>` : roleOptions(u.role_key)}</select>
        ${self ? '<div class="meta">Không tự đổi vai trò của chính mình.</div>' : ''}</div>
      ${canEdit ? '<div class="actions" style="justify-content:flex-start"><button class="btn primary" id="bSave">Lưu</button></div>' : ''}
      <div class="msg" id="mUser"></div>
    </form>
    <h4 style="margin:18px 0 6px">Tài khoản</h4>
    <div class="meta">Tạo lúc ${esc(fmtDateTime(u.created_at))}${u.invited_at ? ' · Mời lúc ' + esc(fmtDateTime(u.invited_at)) : ''}
      · Đăng nhập gần nhất ${esc(fmtDateTime(u.last_login_at) || '—')}</div>
    <div class="row-actions" style="margin-top:10px">
      ${canEdit && u.status === 'invited' ? '<button class="btn" id="bResend">Gửi lại lời mời</button>' : ''}
      ${canEdit && !self && u.status !== 'locked' ? '<button class="btn danger" id="bLock">Khoá tài khoản</button>' : ''}
      ${canEdit && u.status === 'locked' ? '<button class="btn" id="bUnlock">Mở khoá</button>' : ''}
      ${isAdmin() && u.two_factor ? '<button class="btn" id="bReset2fa">Đặt lại 2 bước</button>' : ''}
      <button class="btn ghost" id="bAudit">Xem nhật ký</button>
    </div>`);

  const act = async (fn, okMsg) => {
    try { await fn(); toast(okMsg); closeAll(); reload(); } catch (e) { toast(e.message, 6000); }
  };
  if ($('fUser') && canEdit) {
    $('fUser').onsubmit = (e) => {
      e.preventDefault();
      submitting($('bSave'), async () => {
        const body = { name: $('uname').value.trim() };
        if (!self) body.role_key = $('urole').value;
        try { await patch(`/api/users/${u.id}`, body); toast('Đã lưu'); closeAll(); reload(); } catch (err) {
          showFieldErrors($('fUser'), err);
          setMsg($('mUser'), err.message, 'err');
        }
      });
    };
  }
  if ($('bResend')) $('bResend').onclick = () => act(() => post(`/api/users/${u.id}/resend-invite`), 'Đã gửi lại lời mời');
  if ($('bUnlock')) $('bUnlock').onclick = () => act(() => post(`/api/users/${u.id}/unlock`), 'Đã mở khoá');
  if ($('bLock')) {
    $('bLock').onclick = async () => {
      if (await confirmBox('Khoá tài khoản', `Khoá ${u.name}? Mọi phiên đăng nhập của người này bị huỷ ngay.`, { ok: 'Khoá', danger: true })) {
        act(() => post(`/api/users/${u.id}/lock`), 'Đã khoá tài khoản');
      }
    };
  }
  if ($('bReset2fa')) {
    $('bReset2fa').onclick = async () => {
      if (await confirmBox('Đặt lại xác thực 2 bước', `Xoá 2 bước của ${u.name} (khi họ mất điện thoại)? Họ sẽ phải bật lại khi đăng nhập.`, { ok: 'Đặt lại', danger: true })) {
        act(() => post(`/api/users/${u.id}/reset-two-factor`), 'Đã đặt lại 2 bước');
      }
    };
  }
  $('bAudit').onclick = () => { closeAll(); navigate(`/settings/audit?user_id=${encodeURIComponent(u.id)}`); };
}

/* =================== VAI TRÒ & QUYỀN =================== */
async function tabRoles(host, toolbar) {
  toolbar.innerHTML = `<span class="meta">HR/Quản trị thấy toàn bộ · Quản lý thấy tuyển dụng, đội của mình · Nhân viên chỉ thấy danh bạ, onboarding và nghỉ phép của mình.</span>
    <span class="spacer"></span>${perm('settings', 'create') ? '<button class="btn primary" id="bNewRole">+ Vai trò</button>' : ''}`;
  const { modules, actions, bases } = session.catalog;
  let roles = await loadRoles();
  let current = roles[0]?.key;

  const draw = () => {
    const r = roles.find((x) => x.key === current) || roles[0];
    const locked = r.base === 'admin' || !perm('settings', 'edit') || (!isAdmin() && r.key === session.me.role_key);
    host.innerHTML = `<div style="display:grid;grid-template-columns:minmax(200px,260px) minmax(0,1fr);gap:14px">
      <div class="stack" style="gap:6px">${roles.map((x) => `<button class="btn ${x.key === r.key ? 'primary' : ''}" data-role="${esc(x.key)}" style="text-align:left">
        ${esc(x.label)}<br><span style="font-weight:400;font-size:11.5px;opacity:.75">gốc: ${esc(bases[x.base])} · ${x.users_count} người</span></button>`).join('')}</div>
      <div class="panel" style="min-width:0"><h4>${esc(r.label)} <span class="meta">(${esc(r.key)})</span></h4>
        <p class="meta">${r.base === 'admin' ? 'Vai trò quản trị luôn có đủ mọi quyền.'
          : locked && !isAdmin() && r.key === session.me.role_key ? 'Đây là vai trò bạn đang giữ, chỉ quản trị viên được sửa.'
          : 'Đánh dấu hành động vai trò này được làm ở từng nhóm chức năng.'}</p>
        <div class="tbl-wrap"><table class="matrix"><thead><tr><th>Nhóm</th>${Object.values(actions).map((a) => `<th>${esc(a)}</th>`).join('')}</tr></thead>
        <tbody>${Object.entries(modules).map(([m, ml]) => `<tr><td>${esc(ml)}</td>${Object.keys(actions).map((a) =>
          `<td><input type="checkbox" data-m="${m}" data-a="${a}" ${r.perms[m][a] ? 'checked' : ''} ${locked ? 'disabled' : ''}
            aria-label="${esc(ml)} — ${esc(actions[a])}"></td>`).join('')}</tr>`).join('')}</tbody></table></div>
        <div class="actions" style="justify-content:space-between">
          ${!r.is_system && perm('settings', 'delete') ? '<button class="btn danger" id="bDelRole">Xoá vai trò</button>' : '<span></span>'}
          ${locked ? '' : '<button class="btn primary" id="bSavePerms">Lưu quyền</button>'}</div>
        <div class="msg" id="mPerms"></div></div></div>`;

    host.querySelectorAll('[data-role]').forEach((b) => { b.onclick = () => { current = b.dataset.role; draw(); }; });
    if ($('bSavePerms')) {
      $('bSavePerms').onclick = () => submitting($('bSavePerms'), async () => {
        const perms = {};
        host.querySelectorAll('input[data-m]').forEach((c) => { (perms[c.dataset.m] ||= {})[c.dataset.a] = c.checked; });
        try {
          const res = await put(`/api/roles/${encodeURIComponent(r.key)}/perms`, { perms });
          Object.assign(r, res.data);
          setMsg($('mPerms'), 'Đã lưu ma trận quyền.', 'ok');
        } catch (e) { setMsg($('mPerms'), e.message, 'err'); }
      });
    }
    if ($('bDelRole')) {
      $('bDelRole').onclick = async () => {
        if (!(await confirmBox('Xoá vai trò', `Xoá vai trò "${r.label}"?`, { ok: 'Xoá', danger: true }))) return;
        try { await del(`/api/roles/${encodeURIComponent(r.key)}`); roles = await loadRoles(); current = roles[0].key; draw(); toast('Đã xoá vai trò'); } catch (e) { toast(e.message, 6000); }
      };
    }
  };

  if ($('bNewRole')) {
    $('bNewRole').onclick = () => {
      openModal('Vai trò mới', `<form id="fRole" novalidate><div class="row2">
        <div class="field"><label for="label">Tên vai trò *</label><input id="label" placeholder="VD: Trưởng nhóm tuyển dụng"></div>
        <div class="field"><label for="key">Mã *</label><input id="key" placeholder="truong_nhom_td"></div>
        <div class="field"><label for="base">Vai trò gốc *</label><select id="base">${Object.entries(bases)
          .filter(([k]) => isAdmin() || k !== 'admin').map(([k, l]) => `<option value="${k}" ${k === 'staff' ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select></div></div>
        <p class="meta">Vai trò gốc quyết định các tab nhìn thấy và phạm vi dữ liệu; ma trận quyền quyết định từng thao tác. Vai trò mới bắt đầu không có quyền nào.</p>
        <div class="actions"><button type="button" class="btn" id="bCancel">Huỷ</button><button class="btn primary" id="bCreate">Tạo</button></div>
        <div class="msg" id="mRole"></div></form>`);
      // Gợi ý mã từ tên: bỏ dấu, chữ thường, gạch dưới.
      $('label').oninput = (e) => {
        if ($('key').dataset.touched) return;
        $('key').value = e.target.value.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/gi, 'd')
          .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'r_$1');
      };
      $('key').oninput = () => { $('key').dataset.touched = '1'; };
      $('bCancel').onclick = closeAll;
      $('fRole').onsubmit = (e) => {
        e.preventDefault();
        submitting($('bCreate'), async () => {
          try {
            const res = await post('/api/roles', { label: $('label').value.trim(), key: $('key').value.trim(), base: $('base').value });
            closeAll(); roles = await loadRoles(); current = res.data.key; draw(); toast('Đã tạo vai trò');
          } catch (err) { showFieldErrors($('fRole'), err); if (!Object.keys(err.errors).length) setMsg($('mRole'), err.message, 'err'); }
        });
      };
    };
  }
  draw();
}

/* =================== NHẬT KÝ =================== */
const ACTION_LABEL = {
  'auth.login': 'Đăng nhập', 'auth.login_failed': 'Đăng nhập sai', 'auth.lockout': 'Bị chặn đăng nhập',
  'auth.logout': 'Đăng xuất', 'auth.password_reset': 'Đặt lại mật khẩu', 'auth.password_changed': 'Đổi mật khẩu',
  'auth.invite_accepted': 'Kích hoạt tài khoản', 'user.invited': 'Mời người dùng', 'user.invite_resent': 'Gửi lại lời mời',
  'user.invite_failed': 'Gửi lời mời lỗi', 'user.locked': 'Khoá tài khoản', 'user.unlocked': 'Mở khoá tài khoản',
  'user.role_changed': 'Đổi vai trò', 'user.two_factor_reset': 'Đặt lại 2 bước', 'user.admin_created': 'Tạo quản trị viên',
  'user.admin_password_reset': 'Cấp lại mật khẩu quản trị', 'role.created': 'Tạo vai trò', 'role.updated': 'Sửa vai trò',
  'role.deleted': 'Xoá vai trò', 'role.perms_updated': 'Sửa ma trận quyền',
};

function metaText(l) {
  const m = l.meta || {};
  if (l.action === 'role.perms_updated') {
    return [m.granted?.length ? 'Cấp: ' + m.granted.join(', ') : '', m.revoked?.length ? 'Gỡ: ' + m.revoked.join(', ') : ''].filter(Boolean).join(' · ') || 'Không đổi';
  }
  if (l.action === 'user.role_changed') return `${m.from} → ${m.to}`;
  if (m.email) return m.email;
  return Object.keys(m).length ? JSON.stringify(m) : '';
}

async function tabAudit(host, toolbar, params) {
  const f = { action: '', from: '', to: '', user_id: params.user_id || '', page: 1 };
  toolbar.innerHTML = `<select id="aAct"><option value="">Mọi thao tác</option>
      <option value="auth.">Đăng nhập & mật khẩu</option><option value="user.">Người dùng</option><option value="role.">Vai trò & quyền</option>
      ${Object.entries(ACTION_LABEL).map(([k, l]) => `<option value="${k}">— ${esc(l)}</option>`).join('')}</select>
    <label class="check">Từ <input type="date" id="aFrom"></label><label class="check">đến <input type="date" id="aTo"></label>
    ${f.user_id ? '<button class="btn sm" id="aClearUser">Bỏ lọc người dùng</button>' : ''}`;

  const load = async () => {
    host.innerHTML = '<div class="empty">Đang tải…</div>';
    const r = await get('/api/audit-logs' + qs(f));
    host.innerHTML = r.data.length ? `<div class="tbl-wrap"><table class="grid"><thead><tr>
        <th>Thời điểm</th><th>Người thực hiện</th><th>Thao tác</th><th>Đối tượng</th><th>Chi tiết</th><th>IP</th></tr></thead>
      <tbody>${r.data.map((l) => `<tr><td class="meta" style="white-space:nowrap">${esc(fmtDateTime(l.at))}</td>
        <td>${l.user ? esc(l.user.name) + '<div class="meta">' + esc(l.user.email) + '</div>' : '<span class="meta">khách</span>'}</td>
        <td>${esc(ACTION_LABEL[l.action] || l.action)}</td>
        <td class="meta">${esc([l.subject_type, l.subject_id].filter(Boolean).join(' · '))}</td>
        <td class="meta" style="max-width:340px;word-break:break-word">${esc(metaText(l))}</td>
        <td class="meta">${esc(l.ip || '')}</td></tr>`).join('')}</tbody></table></div>
      <div class="pager">Trang ${r.current_page}/${r.last_page} · ${r.total} dòng
        <button class="btn sm" id="pPrev" ${r.current_page <= 1 ? 'disabled' : ''}>‹ Trước</button>
        <button class="btn sm" id="pNext" ${r.current_page >= r.last_page ? 'disabled' : ''}>Sau ›</button></div>`
      : '<div class="empty">Không có dòng nhật ký phù hợp.</div>';
    if ($('pPrev')) $('pPrev').onclick = () => { f.page--; load(); };
    if ($('pNext')) $('pNext').onclick = () => { f.page++; load(); };
  };
  const change = (k) => (e) => { f[k] = e.target.value; f.page = 1; load().catch((er) => toast(er.message, 6000)); };
  $('aAct').onchange = change('action');
  $('aFrom').onchange = change('from');
  $('aTo').onchange = change('to');
  if ($('aClearUser')) $('aClearUser').onclick = () => navigate('/settings/audit');
  await load();
}
