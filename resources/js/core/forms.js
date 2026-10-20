/*
 * Bộ dựng form từ spec, chuyển từ fieldsHTML/readFields của app cũ.
 * spec: [{ k, label, type?: text|number|date|select|textarea, opts?: [[value,label]], wide?, rows?, hint?, step? }]
 */
import { esc } from './dom.js';

export function fieldsHTML(spec, values = {}, prefix = 'f') {
  return spec.map((f) => {
    const id = `${prefix}_${f.k}`;
    const val = values[f.k] ?? '';
    let input;
    if (f.type === 'select') {
      // Giá trị đang lưu không có trong danh sách (vd nhập từ Excel): thêm hẳn một lựa chọn cho nó,
      // nếu không select sẽ hiện mục đầu tiên và ghi đè mất dữ liệu khi lưu.
      const known = f.opts.some((o) => String(val) === String(o[0]));
      const extra = !known && val !== '' && val != null
        ? `<option value="${esc(val)}" selected>${esc(val)} — giá trị đang lưu (ngoài danh sách)</option>` : '';
      input = `<select id="${id}"${extra ? ' class="odd-val"' : ''}>${extra}${f.opts.map((o) =>
        `<option value="${esc(o[0])}" ${known && String(val) === String(o[0]) ? 'selected' : ''}>${esc(o[1])}</option>`).join('')}</select>`;
    } else if (f.type === 'textarea') {
      input = `<textarea id="${id}" rows="${f.rows || 4}" placeholder="${esc(f.hint || '')}">${esc(val)}</textarea>`;
    } else {
      input = `<input id="${id}" type="${f.type || 'text'}" ${f.step ? `step="${f.step}"` : ''} value="${esc(val)}" placeholder="${esc(f.hint || '')}">`;
    }
    return `<div class="field"${f.wide ? ' style="grid-column:1/-1"' : ''}><label for="${id}">${esc(f.label)}</label>${input}</div>`;
  }).join('');
}

/** Đọc giá trị form: chuỗi rỗng → null, number → Number. */
export function readFields(spec, prefix = 'f', root = document) {
  const o = {};
  for (const f of spec) {
    const el = root.querySelector(`#${CSS.escape(`${prefix}_${f.k}`)}`);
    if (!el) { o[f.k] = null; continue; }
    const v = el.value.trim();
    o[f.k] = v === '' ? null : f.type === 'number' ? Number(v) : v;
  }
  return o;
}
