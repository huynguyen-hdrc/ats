/*
 * CHUẨN VĂN BẢN HÀNH CHÍNH - NHÂN SỰ HELIDIRECT (docs/HD-document-standard.md), chuyển nguyên từ app cũ.
 * Khổ Letter · lề 21mm/24.6mm · Averta · thân 10pt #444 · tiêu đề 22pt IN HOA · kẻ #CCC
 * Header "HELIDIRECT LLC." + tên văn bản · footer "Confidential".
 */
import { esc, toast } from './dom.js';

const HD_DOC_CSS = `
@page { size: letter; margin: 21mm 24.6mm 21mm 24.6mm; }
* { box-sizing: border-box; }
body { font-family: Averta, "Avenir Next", "Segoe UI", Inter, -apple-system, system-ui, sans-serif;
  font-size: 10pt; line-height: 1.6; color: #444444; margin: 0; }
.hd-top { display: flex; justify-content: space-between; align-items: baseline;
  font-size: 8.5pt; color: #888; border-bottom: 1px solid #CCCCCC; padding-bottom: 6px; margin-bottom: 22px; }
.hd-top b { color: #000; letter-spacing: .6px; font-size: 9pt; }
.hd-no { font-size: 9pt; font-weight: 700; color: #000; margin-bottom: 10px; }
h1.hd-title { font-size: 22pt; font-weight: 700; color: #000; letter-spacing: .4px;
  text-transform: uppercase; margin: 0 0 6px; line-height: 1.15; }
.hd-sub { font-size: 11pt; font-weight: 700; color: #000; margin-bottom: 4px; }
.hd-meta { font-size: 9.5pt; color: #444444; margin-bottom: 20px; }
h2.hd-sec { font-size: 11pt; font-weight: 700; color: #000; margin: 22px 0 8px; }
h2.hd-sec span { display: inline-block; min-width: 26px; color: #000; }
.hd-body { white-space: pre-wrap; font: inherit; margin: 0; }
.hd-body b, .hd-strong { color: #000; }
ul.hd-list { list-style: none; padding: 0; margin: 0 0 10px; }
ul.hd-list li { color: #000; margin-bottom: 5px; padding-left: 18px; text-indent: -18px; }
ul.hd-list li::before { content: "—  "; color: #000; }
table.hd-tb { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin: 8px 0 14px; }
table.hd-tb th, table.hd-tb td { border: 1px solid #CCCCCC; padding: 6px 9px; vertical-align: top; text-align: left; }
table.hd-tb th { background: #F5F5F5; color: #000; font-weight: 700; }
.hd-en { font-size: 8.5pt; color: #666; }
.hd-sign { margin-top: 44px; display: flex; justify-content: space-between; }
.hd-sign div { width: 45%; }
.hd-rule { border: 0; border-top: 1px solid #CCCCCC; margin: 18px 0; }
.hd-foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8pt; color: #888;
  border-top: 1px solid #CCCCCC; padding-top: 5px; }
@media print { .noprint { display: none !important; } }
.noprint { margin-top: 34px; text-align: center; }
.noprint button { padding: 9px 20px; font: 13px/1 Inter, system-ui, sans-serif; cursor: pointer;
  border: 1px solid #000; background: #000; color: #fff; border-radius: 6px; }`;

/** Mở cửa sổ in theo chuẩn HD. bodyHTML phải đã được escape bởi người gọi. */
export function printDoc({ docName, docNo, title, subtitle, meta, bodyHTML }) {
  const w = window.open('', '_blank', 'width=900,height=1000');
  if (!w) return toast('Trình duyệt chặn cửa sổ in — cho phép popup rồi thử lại', 6000);
  w.document.write(`<!doctype html><html lang="vi"><head><meta charset="utf-8">
    <title>${esc(title)}</title><style>${HD_DOC_CSS}</style></head><body>
    <div class="hd-top"><b>HELIDIRECT LLC.</b><span>${esc(docName || '')}</span></div>
    ${docNo ? `<div class="hd-no">Số / No.: ${esc(docNo)}</div>` : ''}
    <h1 class="hd-title">${esc(title)}</h1>
    ${subtitle ? `<div class="hd-sub">${esc(subtitle)}</div>` : ''}
    ${meta ? `<div class="hd-meta">${esc(meta)}</div>` : ''}
    ${bodyHTML}
    <div class="hd-foot">Confidential — HeliDirect LLC.</div>
    <div class="noprint"><button onclick="window.print()">In / Lưu thành PDF</button></div>
    </body></html>`);
  w.document.close();
  setTimeout(() => { try { w.focus(); w.print(); } catch { /* người dùng tự bấm nút */ } }, 500);
}

/** Mỗi dòng → một gạch đầu dòng; bỏ ký hiệu đầu dòng có sẵn và dòng trống. */
export const bulletList = (t) => String(t || '').split('\n')
  .map((x) => x.replace(/^[-–—•\s]+/, '').trim()).filter(Boolean)
  .map((x) => `<li>${esc(x)}</li>`).join('');

export const todayVN = () => new Date().toLocaleDateString('vi-VN');
