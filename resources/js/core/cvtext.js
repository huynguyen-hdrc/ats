/*
 * Rút chữ từ file CV ngay trên trình duyệt, như extractText của app cũ (index.html:650-668):
 * .txt đọc thẳng · .docx dùng mammoth · .pdf dùng pdf.js, 8 trang đầu, chữ trong trang nối bằng dấu cách.
 * Hai thư viện tải lười (chunk riêng) — chỉ tải khi có người chọn file.
 */
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

export const CV_ACCEPT = '.pdf,.docx,.txt';
export const CV_MAX_BYTES = 15 * 1024 * 1024;

export async function extractText(file) {
  const n = file.name.toLowerCase();
  if (n.endsWith('.txt')) return file.text();
  if (n.endsWith('.docx')) {
    const mod = await import('mammoth');
    const mammoth = mod.default || mod;
    return (await mammoth.extractRawText({ arrayBuffer: await file.arrayBuffer() })).value;
  }
  if (n.endsWith('.pdf')) {
    const pdfjs = await import('pdfjs-dist');
    pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
    const doc = await pdfjs.getDocument({ data: await file.arrayBuffer() }).promise;
    let out = '';
    for (let p = 1; p <= Math.min(doc.numPages, 8); p++) {
      const tc = await (await doc.getPage(p)).getTextContent();
      out += tc.items.map((i) => i.str).join(' ') + '\n';
    }
    return out;
  }
  return '';
}

/** Hộp chọn file dùng một lần. Trả về File hoặc null. */
export function pickFile(accept = CV_ACCEPT) {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = accept;
    input.onchange = () => resolve(input.files[0] || null);
    input.click();
  });
}
