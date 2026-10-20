/*
 * Thanh Excel dùng chung: "Tải mẫu" · "Nhập" · "Xuất".
 * Trình duyệt chỉ đọc/ghi file .xlsx bằng SheetJS (tải lười, chunk riêng); chuyển đổi, kiểm tra,
 * chống trùng và ghi dữ liệu do backend làm (/api/excel/{entity}/…).
 */
import { get, post } from './api.js';
import { $, closeAll, esc, openModal, submitting, toast } from './dom.js';
import { perm } from './session.js';

let xlsxPromise = null;
function loadXLSX() {
  // Lỗi mạng: xoá cache để lần bấm sau thử lại.
  xlsxPromise ??= import('../vendor/xlsx-0.20.3.mjs').catch((e) => { xlsxPromise = null; throw e; });
  return xlsxPromise;
}

/** HTML các nút; module = nhóm quyền, importable = có cho nhập không. */
export function xlsxBar(entity, { module, importable = true }) {
  const canImport = importable && perm(module, 'create') && perm(module, 'edit');
  return `<span class="xlsx-bar" data-entity="${esc(entity)}">
    ${canImport ? '<button class="btn sm" data-x="template">Tải mẫu</button><button class="btn sm" data-x="import">Nhập</button>' : ''}
    <button class="btn sm" data-x="export">Xuất</button></span>`;
}

export function wireXlsx(root, onImported) {
  root.querySelectorAll('.xlsx-bar').forEach((bar) => {
    const entity = bar.dataset.entity;
    bar.querySelectorAll('[data-x]').forEach((b) => {
      b.onclick = () => ({ export: doExport, template: doTemplate, import: doImport })[b.dataset.x](entity, onImported)
        .catch((e) => toast(e.message || 'Không tải được thư viện Excel. Kiểm tra mạng rồi thử lại.', 6000));
    });
  });
}

const colWidths = (headers) => headers.map((h) => ({ wch: Math.min(38, Math.max(14, String(h).length + 6)) }));

async function doExport(entity) {
  const [XLSX, data] = await Promise.all([loadXLSX(), get(`/api/excel/${entity}/export`)]);
  const ws = XLSX.utils.aoa_to_sheet([data.headers, ...data.rows]);
  ws['!cols'] = colWidths(data.headers);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, data.sheet);
  XLSX.writeFile(wb, data.filename);
  toast(`Đã xuất ${data.rows.length} dòng`);
}

async function doTemplate(entity) {
  const [XLSX, t] = await Promise.all([loadXLSX(), get(`/api/excel/${entity}/template`)]);
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet([t.headers, t.sample]);
  ws['!cols'] = colWidths(t.headers);
  XLSX.utils.book_append_sheet(wb, ws, 'Nhap lieu');
  const guide = XLSX.utils.aoa_to_sheet(t.guide.map((l) => [l]));
  guide['!cols'] = [{ wch: 90 }];
  XLSX.utils.book_append_sheet(wb, guide, 'Huong dan');
  XLSX.writeFile(wb, t.filename);
}

/** Ngày từ ô kiểu ngày → yyyy-mm-dd theo lịch máy (kiểm tra Date an toàn qua realm). */
function cellValue(v) {
  if (Object.prototype.toString.call(v) === '[object Date]') {
    if (Number.isNaN(v.getTime())) return String(v);
    return new Date(v.getTime() - v.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  }
  return v;
}

function pickFile() {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.xlsx,.xls,.csv';
    input.onchange = () => resolve(input.files[0] || null);
    input.click();
  });
}

async function doImport(entity, onImported) {
  const file = await pickFile();
  if (!file) return;
  const XLSX = await loadXLSX();
  const wb = XLSX.read(await file.arrayBuffer(), { cellDates: true });
  const ws = wb.Sheets[wb.SheetNames[0]];
  const raw = XLSX.utils.sheet_to_json(ws, { header: 1, raw: true, defval: null });
  const shown = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' });
  if (!raw.length) return toast('File trống', 4000);
  const payload = {
    headers: raw[0].map((h) => (h == null ? '' : String(h))),
    rows: raw.slice(1).map((r, i) => r.map((v, j) => ({ v: cellValue(v), s: shown[i + 1]?.[j] ?? '' }))),
  };

  const p = await post(`/api/excel/${entity}/preview`, payload);
  const sample = p.sample.rows.length ? `<div class="tbl-wrap" style="margin:10px 0"><table class="grid"><thead><tr>
      ${p.sample.headers.map((h) => `<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>
      ${p.sample.rows.map((r) => `<tr>${r.map((c) => `<td>${esc(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>` : '';
  openModal(`Nhập ${file.name}`, `
    <p><b>${p.valid}</b> dòng hợp lệ · <b>${p.error_count}</b> dòng lỗi${p.duplicates
      ? ` · <b>${p.duplicates}</b> dòng trùng theo ${esc(p.key_label)} sẽ được cập nhật thay vì tạo mới` : ''}</p>
    ${p.errors.length ? `<div class="panel" style="max-height:220px;overflow:auto"><h4>Lỗi cần sửa</h4>
      ${p.errors.map((e) => `<div class="meta">Dòng ${e.line}: ${esc(e.message)}</div>`).join('')}
      ${p.error_count > p.errors.length ? `<div class="meta">… và ${p.error_count - p.errors.length} dòng khác</div>` : ''}</div>
      <p class="meta">Còn dòng lỗi thì chưa nhập được. Sửa file rồi nhập lại.</p>` : ''}
    ${sample}
    <div class="actions"><button class="btn" id="xCancel">Huỷ</button>
      ${!p.error_count && p.valid ? `<button class="btn primary" id="xGo">Nhập ${p.valid} dòng</button>` : ''}</div>`);
  $('xCancel').onclick = closeAll;
  if ($('xGo')) {
    $('xGo').onclick = () => submitting($('xGo'), async () => {
      try {
        const r = await post(`/api/excel/${entity}/import`, payload);
        closeAll();
        toast(r.message, 5000);
        onImported?.();
      } catch (e) { toast(e.message, 7000); }
    });
  }
}
