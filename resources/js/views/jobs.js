/* Vị trí & JD — chuyển từ viewJobs/openJob/jdText/printJD của app cũ (index.html:671-742, 1591-1617). */
import { del, get, patch, post, qs } from '../core/api.js';
import { dayDiff } from '../core/dates.js';
import { $, closeAll, confirmBox, esc, fmtDate, nl, openDrawer, openModal, showFieldErrors, submitting, toast } from '../core/dom.js';
import { wireXlsx, xlsxBar } from '../core/excel.js';
import { fieldsHTML, readFields } from '../core/forms.js';
import { bulletList, printDoc, todayVN } from '../core/print.js';
import { perm } from '../core/session.js';

const EMPLOYMENT = [['', '—'], ['Full-time', 'Full-time'], ['Part-time', 'Part-time'], ['Thực tập', 'Thực tập'], ['Thời vụ', 'Thời vụ']];

export const JOB_SPEC = [
  { k: 'title', label: 'Tên vị trí *' },
  { k: 'department', label: 'Phòng ban' },
  { k: 'headcount', label: 'Số lượng cần tuyển', type: 'number' },
  { k: 'location', label: 'Nơi làm việc' },
  { k: 'employment_type', label: 'Loại hình', type: 'select', opts: EMPLOYMENT },
  { k: 'hiring_manager', label: 'Quản lý tuyển dụng' },
  { k: 'salary_range', label: 'Dải lương' },
  { k: 'target_date', label: 'Hạn tuyển xong', type: 'date' },
  { k: 'is_open', label: 'Trạng thái', type: 'select', opts: [['true', 'Đang tuyển'], ['false', 'Đã đóng']] },
  { k: 'jd_summary', label: 'Mô tả chung', type: 'textarea', wide: true, rows: 3 },
  { k: 'jd_responsibilities', label: 'Trách nhiệm chính (mỗi dòng 1 ý)', type: 'textarea', wide: true, rows: 6 },
  { k: 'jd_requirements', label: 'Yêu cầu (mỗi dòng 1 ý)', type: 'textarea', wide: true, rows: 6 },
  { k: 'jd_benefits', label: 'Quyền lợi (mỗi dòng 1 ý)', type: 'textarea', wide: true, rows: 4 },
];

/** Giá trị form → payload API. */
const toJob = (o) => ({ ...o, is_open: o.is_open !== 'false', headcount: o.headcount ?? 1 });
const toForm = (j) => ({ ...j, is_open: String(j.is_open ?? true) });

export function jdText(j) {
  const sec = (t, v) => (v ? `\n${t}\n${v}\n` : '');
  return `${j.title}${j.department ? ' — ' + j.department : ''}\n`
    + [j.location && 'Nơi làm việc: ' + j.location, j.employment_type && 'Loại hình: ' + j.employment_type,
      j.salary_range && 'Mức lương: ' + j.salary_range, j.headcount && 'Số lượng: ' + j.headcount].filter(Boolean).join('\n')
    + sec('\nMÔ TẢ', j.jd_summary) + sec('TRÁCH NHIỆM CHÍNH', j.jd_responsibilities)
    + sec('YÊU CẦU', j.jd_requirements) + sec('QUYỀN LỢI', j.jd_benefits);
}

export function printJD(j) {
  printDoc({
    docName: 'Mô tả công việc — ' + (j.title || ''),
    title: 'Mô tả công việc',
    subtitle: [j.title, j.department].filter(Boolean).join('  |  '),
    meta: `HeliDirect LLC.  |  Phiên bản v1 — ${todayVN()}  |  Tài liệu nội bộ`,
    bodyHTML: `
      <h2 class="hd-sec"><span>00</span>Thông tin vị trí</h2>
      <table class="hd-tb"><tbody>
        <tr><th style="width:32%">Chức danh</th><td>${esc(j.title)}</td></tr>
        <tr><th>Phòng ban</th><td>${esc(j.department)}</td></tr>
        <tr><th>Nơi làm việc</th><td>${esc(j.location)}</td></tr>
        <tr><th>Loại hình</th><td>${esc(j.employment_type)}</td></tr>
        <tr><th>Số lượng cần tuyển</th><td>${esc(j.headcount ?? 1)}</td></tr>
        <tr><th>Dải lương</th><td>${esc(j.salary_range || '')}</td></tr>
      </tbody></table>
      ${j.jd_summary ? `<h2 class="hd-sec"><span>01</span>Mục tiêu vị trí</h2><div>${nl(j.jd_summary)}</div>` : ''}
      ${j.jd_responsibilities ? `<h2 class="hd-sec"><span>02</span>Trách nhiệm chính</h2><ul class="hd-list">${bulletList(j.jd_responsibilities)}</ul>` : ''}
      ${j.jd_requirements ? `<h2 class="hd-sec"><span>03</span>Yêu cầu</h2><ul class="hd-list">${bulletList(j.jd_requirements)}</ul>` : ''}
      ${j.jd_benefits ? `<h2 class="hd-sec"><span>04</span>Quyền lợi</h2><ul class="hd-list">${bulletList(j.jd_benefits)}</ul>` : ''}
      <hr class="hd-rule">
      <table class="hd-tb"><thead><tr><th>Người lập</th><th>Người phê duyệt</th><th>Người nhận việc</th></tr></thead>
        <tbody><tr><td style="height:58px"></td><td></td><td></td></tr></tbody></table>`,
  });
}

export async function renderJobs(view, toolbar) {
  let jobs = [];
  let q = '';
  toolbar.innerHTML = `<input type="search" id="jq" placeholder="Tìm vị trí, phòng ban...">
    <span class="spacer"></span>
    ${perm('jobs', 'create') ? '<button class="btn primary" id="btnAddJob">+ Vị trí</button>' : ''}
    ${xlsxBar('jobs', { module: 'jobs' })}`;

  const hit = (j) => !q || ['title', 'department', 'location', 'hiring_manager']
    .some((k) => String(j[k] || '').toLowerCase().includes(q));

  const draw = () => {
    const rows = jobs.filter(hit);
    view.innerHTML = rows.length ? `<div class="cards">${rows.map((j) => `
      <div class="panel" data-id="${esc(j.id)}" style="cursor:pointer" tabindex="0">
        <h4>${esc(j.title)}</h4>
        <div class="meta">${esc([j.department, j.location, j.employment_type].filter(Boolean).join(' · ') || 'Chưa có thông tin')}</div>
        <div class="tags" style="margin-top:10px">
          <span class="tag ${j.is_open ? 'ok' : ''}">${j.is_open ? 'Đang tuyển' : 'Đã đóng'}</span>
          ${j.hired_count != null
            ? `<span class="tag">${j.hired_count}/${j.headcount ?? 1} đã tuyển</span><span class="tag">${j.candidates_count} ứng viên</span>`
            : `<span class="tag">cần ${j.headcount ?? 1}</span>`}
          ${j.target_date ? `<span class="tag ${dayDiff(j.target_date) < 0 ? 'warn' : 'soon'}">hạn ${esc(fmtDate(j.target_date))}</span>` : ''}
          ${j.jd_summary || j.jd_requirements ? '<span class="tag ok">có JD</span>' : '<span class="tag warn">thiếu JD</span>'}
        </div>
      </div>`).join('')}</div>`
      : `<div class="empty">${jobs.length ? 'Không có vị trí phù hợp.' : 'Chưa có vị trí nào. Bấm "+ Vị trí" để thêm.'}</div>`;
    view.querySelectorAll('.panel[data-id]').forEach((p) => {
      const open = () => openJob(jobs.find((j) => j.id === p.dataset.id), reload);
      p.onclick = open;
      p.onkeydown = (e) => { if (e.key === 'Enter') open(); };
    });
  };
  const reload = async () => { jobs = (await get('/api/jobs')).data; draw(); };

  $('jq').oninput = (e) => { q = e.target.value.trim().toLowerCase(); draw(); };
  if ($('btnAddJob')) $('btnAddJob').onclick = () => openNewJob(reload);
  wireXlsx(toolbar, reload);
  await reload();
}

function openNewJob(reload) {
  openModal('Thêm vị trí', `<form id="fJob" novalidate><div class="row2">${fieldsHTML(JOB_SPEC, { is_open: 'true', headcount: 1 }, 'm')}</div>
    <div class="actions"><button type="button" class="btn" id="fCancel">Huỷ</button><button class="btn primary" id="fSave">Lưu</button></div></form>`);
  $('fCancel').onclick = closeAll;
  $('fJob').onsubmit = (e) => {
    e.preventDefault();
    submitting($('fSave'), async () => {
      try {
        await post('/api/jobs', toJob(readFields(JOB_SPEC, 'm')));
        closeAll(); toast('Đã thêm vị trí'); reload();
      } catch (err) { showFieldErrors($('fJob'), err, 'm_'); if (!Object.keys(err.errors).length) toast(err.message, 6000); }
    });
  };
}

function openJob(j, reload) {
  if (!j) return;
  const canEdit = perm('jobs', 'edit');
  openDrawer(j.title, `<form id="fJobD" novalidate><div class="row2">${fieldsHTML(JOB_SPEC, toForm(j))}</div>
    <div class="actions">
      ${perm('jobs', 'delete') ? '<button type="button" class="btn danger" id="jDel">Xoá</button>' : ''}
      <button type="button" class="btn" id="jCopy">Sao chép JD</button>
      <button type="button" class="btn" id="jPrint">In JD / PDF</button>
      ${canEdit ? '<button class="btn primary" id="jSave">Lưu</button>' : ''}</div></form>
    ${perm('candidates', 'view') ? '<h4 style="margin:18px 0 8px" id="jCandH">Ứng viên</h4><div id="jCands"><p class="empty">Đang tải...</p></div>' : ''}`);
  if (perm('candidates', 'view')) loadJobCandidates(j.id);
  const form = $('fJobD');
  if (!canEdit) form.querySelectorAll('input,select,textarea').forEach((el) => { el.disabled = true; });

  // Lấy bản đang hiển thị trên form, không lấy bản lúc mở drawer — để chỗ sửa chưa lưu vẫn được in/sao chép.
  const live = () => ({ ...j, ...toJob(readFields(JOB_SPEC, 'f', form)) });

  form.onsubmit = (e) => {
    e.preventDefault();
    if (!canEdit) return;
    submitting($('jSave'), async () => {
      try {
        const r = await patch(`/api/jobs/${j.id}`, toJob(readFields(JOB_SPEC, 'f', form)));
        toast('Đã lưu');
        await reload();
        openJob(r.data, reload);
      } catch (err) { showFieldErrors(form, err, 'f_'); if (!Object.keys(err.errors).length) toast(err.message, 6000); }
    });
  };
  if ($('jDel')) {
    $('jDel').onclick = async () => {
      if (!(await confirmBox('Xoá vị trí', `Xoá vị trí ${j.title}? Không khôi phục được. Ứng viên của vị trí vẫn được giữ.`, { ok: 'Xoá', danger: true }))) return;
      try { await del(`/api/jobs/${j.id}`); closeAll(); toast('Đã xoá'); reload(); } catch (e) { toast(e.message, 6000); }
    };
  }
  $('jPrint').onclick = () => printJD(live());
  $('jCopy').onclick = async () => {
    const cur = live();
    try { await navigator.clipboard.writeText(jdText(cur)); toast('Đã copy JD vào clipboard'); } catch {
      openModal('JD — ' + cur.title, `<textarea rows="20" style="width:100%">${esc(jdText(cur))}</textarea>`);
    }
  };
}

/** Ứng viên của vị trí: tên — vòng (và trạng thái nếu không còn xử lý), như drawer vị trí của app cũ. */
async function loadJobCandidates(jobId) {
  try {
    const [cands, stages] = await Promise.all([get(`/api/candidates${qs({ job_id: jobId })}`), get('/api/stages')]);
    const box = $('jCands');
    if (!box) return; // drawer đã đóng
    const label = (k) => stages.data.find((s) => s.key === k)?.label || k;
    $('jCandH').textContent = `Ứng viên (${cands.data.length})`;
    box.innerHTML = cands.data.map((c) => `<div class="meta">• ${esc(c.full_name)} — ${esc(label(c.stage))}${
      c.status !== 'active' ? ` (${c.status === 'rejected' ? 'đã loại' : 'đã rút'})` : ''}</div>`).join('')
      || '<div class="empty">Chưa có ứng viên</div>';
  } catch (e) {
    if ($('jCands')) $('jCands').innerHTML = `<div class="empty">${esc(e.message)}</div>`;
  }
}
