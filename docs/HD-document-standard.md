# HeliDirect — Chuẩn trình bày văn bản hành chính & nhân sự

Rút từ hai mẫu gốc: `HD_JD_Senior_BusinessIntelligence_Jul26.docx` và
`HD_OfferLetter_PhiNguyen_SeniorBI_Jul26.docx`. Áp dụng cho mọi văn bản HC-NS từ 07/2026.

## 1. Khổ giấy & lề
| Hạng mục | Giá trị |
|---|---|
| Khổ giấy | Letter — 8.5 × 11 in |
| Lề trái / phải | 0.97 in (24.6 mm) |
| Lề trên / dưới | 0.83 in (21 mm) |

## 2. Chữ
| Vai trò | Cỡ | Đậm | Màu |
|---|---|---|---|
| Tên văn bản (H1, IN HOA) | 22 pt | có | `#000000` |
| Dòng phụ (chức danh / người nhận) | 11 pt | có | `#000000` |
| Dòng meta (đơn vị, phiên bản, ngày) | 9.5 pt | không | `#444444` |
| Tiêu đề mục — đánh số `00`, `01`, `02`… | 11 pt | có | `#000000` |
| Thân / diễn giải | 10 pt | không | `#444444` |
| Gạch đầu dòng (ký hiệu `—` + 2 space) | 10 pt | không | `#000000` |
| Bảng | 9.5 pt | tiêu đề cột đậm | `#000000` |
| Phần tiếng Anh song ngữ | 8.5–9 pt | không | `#666666` |
| Số văn bản (`Số / No.`) | 9 pt | có | `#000000` |

Font: **Averta**. Thứ tự dự phòng khi máy không có: `Avenir Next` → `Segoe UI` → `Inter` → font hệ thống.
Giãn dòng 1.6.

## 3. Màu
| Dùng cho | Mã |
|---|---|
| Chữ nhấn, tiêu đề, gạch đầu dòng | `#000000` |
| Thân văn bản | `#444444` |
| Phần phụ / tiếng Anh | `#666666` |
| Đường kẻ, viền bảng | `#CCCCCC` |
| Nền tiêu đề cột bảng | `#F5F5F5` |

## 4. Header / Footer
- **Header** (mọi trang): `HELIDIRECT LLC.` canh trái (9 pt đậm, giãn chữ 0.6px) — tên văn bản canh phải (8.5 pt `#888`) — gạch dưới `1px #CCCCCC`.
- **Footer** (mọi trang): `Confidential — HeliDirect LLC.` (8 pt `#888`), gạch trên `1px #CCCCCC`.

## 5. Bố cục chuẩn
1. Số văn bản (nếu có): `Số / No.: JO……/HD-HRD`
2. Tên văn bản — IN HOA, 22 pt
3. Dòng phụ: chức danh | khối, hoặc tên người nhận
4. Dòng meta: `HeliDirect LLC. | Phiên bản | Ngày | Tài liệu nội bộ`
5. Các mục đánh số `00 → 0n`, mục `00` luôn là bảng *Thông tin*
6. Bảng 2 cột cho thông tin, bảng 3 cột cho chỉ tiêu / trách nhiệm
7. Khối ký cuối: *Người lập — Người phê duyệt — Người nhận việc*

## 6. Song ngữ
Văn bản gửi ra ngoài (offer letter, thư mời) trình bày tiếng Việt trước (10 pt `#000`),
tiếng Anh ngay dưới (8.5–9 pt `#666`). Văn bản nội bộ (JD, quy trình) chỉ cần tiếng Việt.

## 7. Áp dụng trong app
Chuẩn này đã được mã hoá trong `index.html` — hằng số `HD_DOC_CSS` và hàm `printDoc()`.
Mọi văn bản in từ app (`printLetter`, `printJD`) đều dùng chung một khung này.
Khi thêm loại văn bản mới, gọi `printDoc({ docName, docNo, title, subtitle, meta, bodyHTML })`
thay vì tự viết CSS.
