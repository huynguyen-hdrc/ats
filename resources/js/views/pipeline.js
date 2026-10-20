/* Tuyển dụng (kanban) — chuyển từ viewPipeline/candCard/openAddCandidate/openCandidate của app cũ (index.html:478-668). */
import { del, get, patch, post } from '../core/api.js';
import { CV_MAX_BYTES, extractText, pickFile } from '../core/cvtext.js';
import { $, closeAll, confirmBox, esc, fmtDateTime, openDrawer, openModal, showFieldErrors, submitting, toast } from '../core/dom.js';
import { wireXlsx, xlsxBar } from '../core/excel.js';
import { fieldsHTML, readFields } from '../core/forms.js';
import { perm } from '../core/session.js';

const LATER = 'Chức năng này sẽ có khi module Thư & Email chuyển sang hệ thống mới.';

/** Trạng thái của màn hình (một màn hình kanban tại một thời điểm). */
const st = { stages: [], jobs: [], candidates: [], q: '', job: '', showClosed: false, drag: null, reload: null };

const stageLabel = (key) => st.stages.find((s) => s.key === key)?.label || key || '-';
const statusLabel = (s) => (s === 'rejected' ? 'đã loại' : 'đã rút');

export const CAND_SPEC = () => [
  { k: 'full_name', label: 'Họ tên *' },
  { k: 'email', label: 'Email' },
  { k: 'phone', label: 'Điện thoại' },
  { k: 'current_title', label: 'Chức danh hiện tại' },
  { k: 'current_company', label: 'Công ty hiện tại' },
  { k: 'years_experience', label: 'Số năm kinh nghiệm', type: 'number', step: '0.1' },
  { k: 'source', label: 'Nguồn' },
  { k: 'job_id', label: 'Vị trí ứng tuyển', type: 'select', opts: [['', '— chưa gán —'], ...st.jobs.map((j) => [j.id, j.title])] },
  { k: 'stage', label: 'Vòng', type: 'select', opts: st.stages.map((s) => [s.key, s.label]) },
  { k: 'skills_text', label: 'Kỹ năng (cách nhau bằng dấu phẩy)', wide: true },
];
/** Giá trị form → payload API (kỹ năng: chuỗi phẩy → mảng). */
const toCand = (o) => {
  const { skills_text, ...r } = o;
  return { ...r, skills: skills_text ? skills_text.split(',').map((s) => s.trim()).filter(Boolean) : [] };
};
const fromCand = (c) => ({ ...c, skills_text: (c.skills || []).join(', ') });

function daysAgo(ts) {
  const n = Math.floor((Date.now() - new Date(ts)) / 86400000);
  return n <= 0 ? 'hôm nay' : n + ' ngày';
}
function shade(i, n) {
  const p = n > 1 ? i / (n - 1) : 0;
  const g = Math.round(245 + (0 - 245) * p);
  return { bg: `rgb(${g},${g},${g})`, fg: p > 0.45 ? '#fff' : '#000' };
}
const jobTitle = (c) => c.job?.title || st.jobs.find((j) => j.id === c.job_id)?.title || '';

export async function renderPipeline(view, toolbar) {
  Object.assign(st, { q: '', job: '', showClosed: false, drag: null });
  const canCreate = perm('candidates', 'create');
  toolbar.innerHTML = `
    <select id="jobFilter" aria-label="Lọc theo vị trí"><option value="">Tất cả vị trí</option></select>
    <input type="search" id="pq" placeholder="Tìm tên, email, skill, công ty...">
    <label class="check"><input type="checkbox" id="showClosed"> Hiện ứng viên đã loại / rút</label>
    <span class="spacer"></span>
    ${canCreate ? '<button class="btn" id="btnUpload">Tải CV lên</button><button class="btn primary" id="btnAddCand">+ Ứng viên</button>' : ''}
    ${xlsxBar('candidates', { module: 'candidates' })}`;

  $('jobFilter').onchange = (e) => { st.job = e.target.value; draw(view); };
  $('pq').oninput = (e) => { st.q = e.target.value.trim().toLowerCase(); draw(view); };
  $('showClosed').onchange = (e) => { st.showClosed = e.target.checked; draw(view); };
  if ($('btnAddCand')) $('btnAddCand').onclick = () => openAddCandidate();
  if ($('btnUpload')) $('btnUpload').onclick = () => uploadAndParse();

  st.reload = async () => {
    const [stages, cands, jobs] = await Promise.all([
      get('/api/stages'), get('/api/candidates'),
      get('/api/jobs').catch(() => ({ data: [] })), // không có quyền xem vị trí: bỏ bộ lọc, vẫn xem được ứng viên
    ]);
    const sel = $('jobFilter');
    if (!sel) return; // đã chuyển sang tab khác trong lúc chờ
    st.stages = stages.data;
    st.candidates = cands.data;
    st.jobs = jobs.data;
    sel.innerHTML = '<option value="">Tất cả vị trí</option>' + st.jobs.map((j) =>
      `<option value="${esc(j.id)}" ${st.job === j.id ? 'selected' : ''}>${esc(j.title)}</option>`).join('');
    draw(view);
  };
  wireXlsx(toolbar, () => st.reload());
  await st.reload();
}

function visible() {
  const hit = (c) => !st.q || [c.full_name, c.email, c.phone, c.current_title, c.current_company, (c.skills || []).join(' ')]
    .join(' ').toLowerCase().includes(st.q);
  return st.candidates.filter((c) => (st.showClosed || c.status === 'active') && (!st.job || c.job_id === st.job) && hit(c));
}

function draw(view) {
  const by = Object.fromEntries(st.stages.map((s) => [s.key, []]));
  // Ứng viên có vòng không còn trong danh sách vòng thì không hiện (giống app cũ).
  visible().forEach((c) => by[c.stage]?.push(c));
  const canMove = perm('candidates', 'edit');

  view.innerHTML = `
    <div class="summary">${st.stages.map((s, i) => {
      const { bg, fg } = shade(i, st.stages.length);
      return `<div class="sum" style="background:${bg};color:${fg}"><b>${by[s.key].length}</b><span>${esc(s.label)}</span></div>`;
    }).join('')}</div>
    <div class="board">${st.stages.map((s) => `
      <div class="col" data-stage="${esc(s.key)}">
        <h3><span>${esc(s.label)}</span><span>${by[s.key].length}</span></h3>
        ${by[s.key].map((c) => candCard(c, canMove)).join('') || '<div class="empty">Chưa có ứng viên</div>'}
      </div>`).join('')}</div>`;

  view.querySelectorAll('.card').forEach((el) => {
    el.addEventListener('dragstart', (e) => { st.drag = el.dataset.id; el.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', el.dataset.id); });
    el.addEventListener('dragend', () => { st.drag = null; el.classList.remove('dragging'); });
    el.addEventListener('click', () => openCandidate(el.dataset.id));
    el.addEventListener('keydown', (e) => { if (e.key === 'Enter') openCandidate(el.dataset.id); });
  });
  if (!canMove) return;
  view.querySelectorAll('.col').forEach((col) => {
    col.addEventListener('dragover', (e) => { if (!st.drag) return; e.preventDefault(); col.classList.add('over'); });
    col.addEventListener('dragleave', () => col.classList.remove('over'));
    col.addEventListener('drop', (e) => { e.preventDefault(); col.classList.remove('over'); moveTo(view, st.drag, col.dataset.stage); });
  });
}

/** Thả thẻ sang cột khác: cập nhật ngay trên màn hình, lỗi thì trả về chỗ cũ. */
async function moveTo(view, id, stage) {
  const c = st.candidates.find((x) => x.id === id);
  if (!id || !c || c.stage === stage) return;
  const prev = { stage: c.stage, stage_changed_at: c.stage_changed_at };
  Object.assign(c, { stage, stage_changed_at: new Date().toISOString() });
  draw(view);
  requestAnimationFrame(() => {
    const moved = view.querySelector(`.card[data-id="${CSS.escape(id)}"]`);
    if (moved) { moved.classList.add('landed'); setTimeout(() => moved.classList.remove('landed'), 400); }
  });
  try {
    const r = await patch(`/api/candidates/${id}/stage`, { stage });
    Object.assign(c, r.data);
    toast(c.full_name + ' → ' + stageLabel(stage));
  } catch (err) {
    Object.assign(c, prev);
    draw(view);
    toast('Không đổi được vòng: ' + err.message, 6000);
  }
}

function candCard(c, canMove) {
  const t = [];
  const jt = jobTitle(c);
  if (jt) t.push(`<span class="tag">${esc(jt)}</span>`);
  if (c.years_experience != null) t.push(`<span class="tag">${esc(c.years_experience)} năm</span>`);
  t.push(`<span class="tag">${esc(daysAgo(c.stage_changed_at))}</span>`);
  if (c.status !== 'active') t.push(`<span class="tag warn">${statusLabel(c.status)}</span>`);
  return `<div class="card" draggable="${canMove ? 'true' : 'false'}" data-id="${esc(c.id)}" tabindex="0">
    <div class="nm">${esc(c.full_name)}</div>
    <div class="sub">${esc([c.current_title, c.current_company].filter(Boolean).join(' · ') || 'Chưa rõ vị trí hiện tại')}</div>
    <div class="tags">${t.join('')}</div></div>`;
}

/* ---------- thêm ứng viên (tay hoặc từ CV đã đọc) ---------- */

function openAddCandidate(prefill = {}, pending = null) {
  const clean = Object.fromEntries(Object.entries(prefill).filter(([, v]) => v != null && v !== ''));
  const v = fromCand({ stage: 'new', job_id: st.job || '', ...clean });
  const spec = CAND_SPEC();
  openModal('Thêm ứng viên', `<form id="fCand" novalidate><div class="row2">${fieldsHTML(spec, v, 'm')}</div>
    ${pending?.file ? `<p class="meta">File CV đính kèm: ${esc(pending.file.name)}</p>` : ''}
    <div class="actions"><button type="button" class="btn" id="fCancel">Huỷ</button><button class="btn primary" id="fSave">Lưu</button></div></form>`);
  $('fCancel').onclick = closeAll;
  const form = $('fCand');
  form.onsubmit = (e) => {
    e.preventDefault();
    submitting($('fSave'), async () => {
      const payload = toCand(readFields(spec, 'm', form));
      if (pending?.text) payload.cv_text = pending.text.slice(0, 20000);
      let created;
      try {
        created = (await post('/api/candidates', payload)).data;
      } catch (err) {
        showFieldErrors(form, err, 'm_');
        if (!Object.keys(err.errors).length) toast('Lỗi lưu: ' + err.message, 6000);
        return;
      }
      if (pending?.file) {
        try { await uploadCv(created.id, pending.file); } catch (err) { toast('Lưu hồ sơ OK nhưng upload CV lỗi: ' + err.message, 6000); }
      }
      closeAll();
      await st.reload?.();
      toast('Đã thêm ' + created.full_name);
    });
  };
}

function uploadCv(id, file) {
  const fd = new FormData();
  fd.append('file', file);
  return post(`/api/candidates/${id}/cv`, fd);
}

/** "Tải CV lên": đọc chữ trong file → máy chủ tách thông tin → mở form thêm ứng viên đã điền sẵn. */
async function uploadAndParse() {
  const file = await pickFile();
  if (!file) return;
  if (file.size > CV_MAX_BYTES) return toast('File CV tối đa 15 MB.', 5000);
  toast('Đang đọc ' + file.name + '...', 9000);
  let text = '';
  try { text = await extractText(file); } catch (err) { console.error(err); }
  if (!text.trim()) {
    toast('Không đọc được nội dung CV, nhập tay giúp bạn nhé', 5000);
    return openAddCandidate({}, { file });
  }
  let fields = {};
  try {
    const data = await post('/api/candidates/parse-cv', { text, filename: file.name });
    fields = data?.candidate || {};
    if (data?.not_a_cv) toast('File này giống mô tả công việc hơn là CV — kiểm tra lại giúp bạn nhé', 6000);
    else toast('Đã rút thông tin bằng ' + (data?.engine === 'ai' ? 'AI' : 'quy tắc') + ', kiểm tra lại trước khi lưu', 5000);
  } catch (err) {
    toast('Parse lỗi (' + err.message + '), điền tay giúp bạn nhé', 6000);
  }
  openAddCandidate(fields, { file, text });
}

/* ---------- hồ sơ ứng viên (drawer) ---------- */

function evHTML(e) {
  const label = e.type === 'note' ? 'Ghi chú' : e.type === 'created' ? 'Tạo hồ sơ'
    : e.type === 'status_change' ? 'Đổi trạng thái: ' + (e.body === 'active' ? 'đang xử lý' : statusLabel(e.body))
      : 'Chuyển vòng: ' + (e.from_stage ? stageLabel(e.from_stage) : '-') + ' → ' + stageLabel(e.to_stage);
  return `<div class="ev"><div class="when">${esc(fmtDateTime(e.created_at))}${e.author ? ' · ' + esc(e.author) : ''}</div>
    <div>${esc(label)}</div>${e.type === 'note' ? `<div style="white-space:pre-wrap">${esc(e.body)}</div>` : ''}</div>`;
}
const eventsHTML = (events) => events.map(evHTML).join('') || '<div class="empty">Chưa có hoạt động</div>';

async function openCandidate(id) {
  const known = st.candidates.find((x) => x.id === id);
  openDrawer(known?.full_name || 'Ứng viên', '<p class="empty">Đang tải...</p>');
  let res;
  try { res = await get(`/api/candidates/${id}`); } catch (err) { toast(err.message, 6000); return closeAll(); }
  const c = res.data;
  const canEdit = perm('candidates', 'edit');
  const spec = CAND_SPEC();
  $('dName').textContent = c.full_name;
  $('dBody').innerHTML = `<form id="fCandD" novalidate><div class="row2">${fieldsHTML(spec, fromCand(c))}</div>
    <div class="actions">
      ${perm('candidates', 'delete') ? '<button type="button" class="btn danger" id="dDel">Xoá</button>' : ''}
      ${canEdit ? `<button type="button" class="btn danger" id="dReject">${c.status === 'active' ? 'Loại ứng viên' : 'Mở lại'}</button>` : ''}
      <button type="button" class="btn" id="dMail">Soạn email</button>
      <button type="button" class="btn" id="dOffer">Tạo offer</button>
      ${canEdit ? '<button class="btn primary" id="dSave">Lưu</button>' : ''}</div></form>
    <hr style="border:none;border-top:1px solid var(--line);margin:18px 0">
    ${perm('candidates', 'comment') ? `<div class="field"><label for="dNote">Thêm ghi chú</label>
      <textarea id="dNote" rows="3" placeholder="Nhận xét phỏng vấn, mức lương mong muốn..."></textarea></div>
      <div class="actions"><button type="button" class="btn" id="dAddNote">Lưu ghi chú</button></div>` : ''}
    <h4 style="margin:18px 0 10px">Lịch sử</h4>
    <div id="dEvents">${eventsHTML(res.events)}</div>
    <div style="margin-top:14px" class="actions" id="dCvBar">
      ${c.cv_path ? `<button type="button" class="btn" id="dCv">Mở CV (${esc(c.cv_filename || 'file')})</button>` : '<span class="meta" style="margin-right:auto">Chưa có file CV</span>'}
      ${canEdit && c.cv_path ? '<button type="button" class="btn danger" id="dCvDel">Xoá CV</button>' : ''}
      ${canEdit ? `<button type="button" class="btn" id="dCvUp">${c.cv_path ? 'Thay CV' : 'Tải CV lên'}</button>` : ''}
    </div>`;

  const form = $('fCandD');
  if (!canEdit) form.querySelectorAll('input,select,textarea').forEach((el) => { el.disabled = true; });
  const reopen = async () => { await st.reload?.(); openCandidate(id); };

  form.onsubmit = (e) => {
    e.preventDefault();
    if (!canEdit) return;
    submitting($('dSave'), async () => {
      try {
        await patch(`/api/candidates/${id}`, toCand(readFields(spec, 'f', form)));
        toast('Đã lưu');
        await reopen();
      } catch (err) { showFieldErrors(form, err, 'f_'); if (!Object.keys(err.errors).length) toast('Lỗi lưu: ' + err.message, 6000); }
    });
  };
  if ($('dReject')) {
    $('dReject').onclick = () => submitting($('dReject'), async () => {
      try { await patch(`/api/candidates/${id}/status`, { status: c.status === 'active' ? 'rejected' : 'active' }); await reopen(); } catch (err) { toast(err.message, 6000); }
    });
  }
  if ($('dDel')) {
    $('dDel').onclick = async () => {
      if (!(await confirmBox('Xoá ứng viên', `Xoá ứng viên ${c.full_name}? Lịch sử và file CV bị xoá theo, không khôi phục được.`, { ok: 'Xoá', danger: true }))) return;
      try { await del(`/api/candidates/${id}`); closeAll(); toast('Đã xoá'); st.reload?.(); } catch (err) { toast(err.message, 6000); }
    };
  }
  $('dMail').onclick = () => toast(LATER, 5000);
  $('dOffer').onclick = () => toast(LATER, 5000);
  if ($('dAddNote')) {
    $('dAddNote').onclick = () => submitting($('dAddNote'), async () => {
      const body = $('dNote').value.trim();
      if (!body) return;
      try {
        const r = await post(`/api/candidates/${id}/notes`, { body });
        $('dNote').value = '';
        $('dEvents').innerHTML = eventsHTML(r.events);
        toast('Đã thêm ghi chú');
      } catch (err) { toast('Lỗi: ' + err.message, 6000); }
    });
  }
  if ($('dCv')) $('dCv').onclick = () => downloadCv(id, c.cv_filename).catch((err) => toast('Lỗi: ' + err.message, 6000));
  if ($('dCvDel')) {
    $('dCvDel').onclick = async () => {
      if (!(await confirmBox('Xoá CV', `Xoá file ${c.cv_filename || 'CV'} của ${c.full_name}?`, { ok: 'Xoá', danger: true }))) return;
      try { await del(`/api/candidates/${id}/cv`); toast('Đã xoá CV'); await reopen(); } catch (err) { toast(err.message, 6000); }
    };
  }
  if ($('dCvUp')) {
    $('dCvUp').onclick = async () => {
      const file = await pickFile();
      if (!file) return;
      if (file.size > CV_MAX_BYTES) return toast('File CV tối đa 15 MB.', 5000);
      try { await uploadCv(id, file); toast('Đã tải CV lên'); await reopen(); } catch (err) { toast(err.first('file') || err.message, 6000); }
    };
  }
}

/** Tải file qua API có kiểm quyền (không có link công khai). */
async function downloadCv(id, filename) {
  const res = await fetch(`/api/candidates/${id}/cv`, { credentials: 'same-origin', headers: { Accept: 'application/octet-stream, application/json' } });
  if (!res.ok) {
    let msg = `Lỗi ${res.status}`;
    try { msg = (await res.json()).message || msg; } catch { /* không phải JSON */ }
    throw new Error(msg);
  }
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url;
  a.download = filename || 'cv';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
