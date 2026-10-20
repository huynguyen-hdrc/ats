/*
 * Ngày theo múi giờ máy người dùng, không theo UTC: trước 07:00 giờ VN, UTC vẫn là "hôm qua"
 * (lỗi đã sửa ở app cũ, giữ nguyên cách làm).
 */
export const ymd = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
export const today = () => ymd(new Date());
export const addDays = (iso, n) => {
  const x = new Date(String(iso).slice(0, 10) + 'T00:00:00');
  x.setDate(x.getDate() + n);
  return ymd(x);
};
/** Số ngày từ hôm nay tới iso (âm = đã qua). */
export const dayDiff = (iso) => Math.round((Date.parse(String(iso).slice(0, 10) + 'T00:00:00Z') - Date.parse(today() + 'T00:00:00Z')) / 86400000);
