# Kiểm kê module — HR Helidirect (bản Supabase) · đặc tả đảo ngược

> **Ngày:** 2026-10-20 · **Mục đích:** đặc tả chính xác từng module của app một-file hiện tại để dựng lại bằng Laravel (API) + Vite (SPA) + MySQL.
> **Nguồn đã đọc toàn bộ:** `index.html` (2305 dòng, một `<script type="module">` dùng `@supabase/supabase-js@2.117.3`) và `selftest.html` (1044 dòng).
> **Vị trí file:** hai file gốc nằm ở `public/legacy/index.html` và `public/legacy/selftest.html` (nội dung giữ nguyên so với commit `0993664`). Mọi số dòng `index.html:NNN` / `selftest.html:NNN` dưới đây khớp với các file đó.

**Quy ước**

- `index.html:NNN` / `selftest.html:NNN` = trích dẫn dòng.
- **[UI]** = ràng buộc chỉ có ở phía client. **[DB]** = selftest chứng minh ràng buộc nằm ở Postgres (trigger, check, FK, RLS). **[Suy luận]** = đoán từ cách dùng, chưa có bằng chứng; xem thêm §8 *Unknown / needs DB dump*.
- Toàn bộ chuỗi giao diện gốc là tiếng Việt; phần mô tả kỹ thuật viết bằng tiếng Anh.

---

## 0. Tổng quan runtime (runtime overview)

| Item | Detail | Ref |
|---|---|---|
| Backend | Supabase project `hzsybxvvcexayducvfll`, anon key hard-coded in page | index.html:215-216 |
| Auth | Email magic link (`signInWithOtp`, `emailRedirectTo` = current URL without hash). Domain restricted to `@helidirect.com` **by the DB** (signup error `Database error saving new user` / `not allowed` / `42501` → friendly message); rate-limit errors mapped to a separate message | index.html:343-355 |
| Session boot | `onAuthStateChange → boot(session)`; also `boot()` called once at end of script (so boot may run twice). No session → show `#login`. Else show `#app`, set `state.me = session.user.email`, `loadAll()` then `render()` | index.html:2287-2295, 2302 |
| Sign out | `sb.auth.signOut()` then `location.reload()` | index.html:356 |
| Refresh | Header button reloads all data and re-renders | index.html:357 |
| Header link | "Kiểm tra hệ thống" → `./selftest.html` | index.html:193 |
| Data load | `loadAll()` fires 18 parallel selects; **if any one errors, nothing is assigned and `render()` is not called** (toast "Lỗi tải dữ liệu") | index.html:361-392 |
| Role resolution | `me = profiles.find(email === state.me)`; `state.roleKey = me.role`; `state.myEmp = me.employee_id`; `state.role = roles.find(key===me.role).base \|\| me.role`. If no profile row is visible, defaults stay `role='staff'`, `roleKey='staff'`, `myEmp=null` | index.html:238-242, 388-390 |
| External libs | SheetJS `xlsx-0.20.3` (lazy, CDN) · `mammoth@1.13.0` (docx text) · `pdfjs-dist@6.4.299` (pdf text, first 8 pages) | index.html:1055, 653, 657-661 |
| Date helpers | `ymd/today/addDays/dayDiff` use **local (browser) date**, not UTC (fix — see §6) | index.html:224-228 |
| Formatting | `d()` → `toLocaleDateString('vi-VN')` (dd/mm/yyyy); `money()` → `toLocaleString('vi-VN')` (dots as thousand separator) | index.html:222-223 |
| Test hooks on `window` | `__hdrcDrawerLive`, `__hdrcMail`, `__hdrcHR`, `__hdrc`, `__hdrcReload`, `__hdrcState`, `__hdrcUI` | index.html:607, 735, 1774, 2284, 2297-2300 |

### 0.1 `loadAll()` — exact queries (index.html:362-381)

| # | Query | state key |
|---|---|---|
| 1 | `stages.select('*').order('sort_order')` | `stages` |
| 2 | `jobs.select('*').order('title')` | `jobs` |
| 3 | `candidates.select('*').order('stage_changed_at', desc)` | `candidates` |
| 4 | `employees.select('*').order('full_name')` | `employees` |
| 5 | `contracts.select('*').order('end_date', {nullsFirst:false})` (ascending, nulls last) | `contracts` |
| 6 | `onboarding_items.select('*').order('sort_order')` | `obItems` |
| 7 | `onboarding_templates.select('*, onboarding_template_items(*)').order('name')` | `obTemplates` |
| 8 | `probation_reviews.select('*').order('review_date', desc)` | `reviews` |
| 9 | `email_templates.select('*').order('name')` | `templates` |
| 10 | `offers.select('*').order('created_at', desc)` | `offers` |
| 11 | `email_log.select('*').order('created_at', desc).limit(300)` | `emails` |
| 12 | `profiles.select('*').order('email')` | `profiles` |
| 13 | `leave_types.select('*').order('sort_order')` | `leaveTypes` |
| 14 | `leave_requests.select('*').order('start_date', desc).limit(500)` | `leaves` |
| 15 | `leave_balances.select('*')` | `balances` |
| 16 | `notifications.select('*').eq('status','open').order('due_date')` | `notifs` |
| 17 | `roles.select('*').order('sort')` | `roles` |
| 18 | `role_perms.select('*')` | `rolePerms` |

**Rebuild implication:** every role must be able to call every list endpoint and receive an *empty list*, not a 403, otherwise the whole SPA fails to render. All row scoping is done server-side (RLS today).

---

## 1. Mô hình dữ liệu (data model)

24 application tables are referenced (22 business tables listed by the DB permission-trigger test at selftest.html:906-909, plus `notifications` and `selftest_runs`), plus Supabase `auth.users` and two storage buckets.

> **Laravel collision:** the new scaffold already contains `database/migrations/0001_01_01_000002_create_jobs_table.php` (Laravel queue `jobs`, `job_batches`, `failed_jobs`). The HR `jobs` table (job openings) must be renamed (e.g. `job_openings`) or the queue table renamed via `config/queue.php`.

### 1.1 `stages` (pipeline stages, lookup)

| Column | Type | Null | Notes | Ref |
|---|---|---|---|---|
| `key` | text **PK** | no | Referenced by `candidates.stage` (FK — selftest says "bị chặn: khoá ngoại") | selftest.html:174-178 |
| `label` | text | no | Shown on kanban columns; Excel import matches key **or** label case-insensitively | index.html:526, 1244 |
| `sort_order` | int | — | Column order | index.html:363 |

- Exactly **7 rows** expected (kanban test). selftest.html:437-444
- Known keys: `new` (default stage, selftest.html:141; index.html:566, 1098), `phone` (selftest.html:150), `screening` (label "Screening", selftest.html:512-514), `hired` (used in dashboards/job counts, index.html:697, 1799). Remaining 3 keys/labels are **unknown** (§8). Label of `new` is probably "New" (Excel sample row, index.html:1102).
- No UI to edit stages. Module mapping: `stages → settings` (index.html:293). Included in backups (selftest.html:992).

### 1.2 `jobs` (job openings / JD)

| Column | Type | Null | Default | Notes | Ref |
|---|---|---|---|---|---|
| `id` | uuid PK | no | gen | | |
| `title` | text | no [UI+Excel req] | | "Tên vị trí *" | index.html:672, 693, 1109 |
| `department` | text | yes | | | 673 |
| `headcount` | int | yes | **1** (UI `toJob`: `headcount ?? 1`; Excel `def:1`) | | 674, 686, 1111 |
| `location` | text | yes | | | 675 |
| `employment_type` | text enum | yes | | `Full-time` \| `Part-time` \| `Thực tập` \| `Thời vụ` (Excel enforces, form select) | 676, 1062, 1113 |
| `hiring_manager` | text (free text name, **not FK**) | yes | | | 677 |
| `salary_range` | text (free text, e.g. "15-20tr") | yes | | | 678 |
| `target_date` | date | yes | | Hiring deadline; overdue → warn tag | 679, 706 |
| `is_open` | bool | no? | **true** (form default `'true'`, Excel `def:true`) | Form uses string `'true'/'false'`, converted in `toJob` | 680, 686, 692, 1117 |
| `jd_summary` | text | yes | | | 681 |
| `jd_responsibilities` | text, newline-separated bullets | yes | | | 682 |
| `jd_requirements` | text, newline-separated bullets | yes | | | 683 |
| `jd_benefits` | text, newline-separated bullets | yes | | | 684 |

- FK in: `candidates.job_id` **ON DELETE SET NULL** [DB] (selftest.html:184-194); `offers.job_id` (on-delete unknown).
- Excel de-dup key: `lower(trim(title)) + ' | ' + lower(trim(department))` (index.html:1106; selftest.html:580-588).
- Seed in selftest inserts `{title}` only (selftest.html:185) → `headcount`, `is_open` must have DB defaults or be nullable.

### 1.3 `candidates`

| Column | Type | Null | Default | Notes | Ref |
|---|---|---|---|---|---|
| `id` | uuid PK | | | Also storage folder name in bucket `cvs` | 575 |
| `full_name` | text | no [UI req] | | | 485, 570 |
| `email` | text | yes | | Excel de-dup key (lowercased) | 486, 1088 |
| `phone` | text | yes | | Excel normalises VN mobile numbers | 487, 1093 |
| `current_title` | text | yes | | | 488 |
| `current_company` | text | yes | | | 489 |
| `years_experience` | numeric(≥1 decimal) | yes | | step 0.1; values like 3.5, 2.8 | 490, selftest.html:140 |
| `source` | text (free) | yes | | e.g. "TopCV"; dashboard groups by it | 491, 1809 |
| `job_id` | uuid FK→jobs | yes | | **ON DELETE SET NULL** [DB] | 492, selftest.html:184-194 |
| `stage` | text FK→stages.key | no | **'new'** [DB] | | 493, selftest.html:141, 174-178 |
| `status` | text CHECK | no | **'active'** [Suy luận: inserts never set it but list filters on `'active'`] | `active` \| `rejected` \| `withdrawn`[Suy luận: label "đã rút" for any non-active non-rejected] ; invalid value rejected [DB] | 515, 559, selftest.html:179-183 |
| `skills` | text[] | yes | `[]` | Form edits as comma string `skills_text` | 494-497, selftest.html:140 |
| `cv_text` | text | yes | | Extracted CV text, **truncated client-side to 20 000 chars** | 571 |
| `cv_path` | text | yes | | Storage path in `cvs`: `{candidate_id}/{original file name}` | 575-578 |
| `cv_filename` | text | yes | | Original file name | 578, 599 |
| `stage_changed_at` | timestamptz | no | now() | **DB trigger** updates it only when `stage` changes; other edits must not touch it [DB] | selftest.html:155-166 |
| `created_at` | timestamptz | no | now() | Used for time-to-hire | 1805 |

DB triggers on `candidates` (selftest group B):
- AFTER INSERT → insert `candidate_events(type='created')` (selftest.html:144-148).
- AFTER UPDATE OF stage → insert `candidate_events(type='stage_change', from_stage=OLD.stage, to_stage=NEW.stage)` and set `stage_changed_at` (selftest.html:149-159).
- AFTER UPDATE OF status → insert `candidate_events(type='status_change', body=NEW.status)` (selftest.html:167-173).
- FK `offers.candidate_id` **ON DELETE SET NULL** (selftest.html:696-705).

### 1.4 `candidate_events` (activity history)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | |
| `candidate_id` | uuid FK→candidates | ON DELETE [Suy luận: CASCADE] | 586, 613 |
| `type` | text enum | `created` \| `stage_change` \| `status_change` \| `note` | 625-627 |
| `body` | text | note text; for `status_change` = new status code | 613, selftest.html:170 |
| `from_stage` | text (stage key) null | `stage_change` only; rendered `'-'` when null | 627 |
| `to_stage` | text (stage key) | `stage_change` only | 627 |
| `created_at` | timestamptz default now() | shown `toLocaleString('vi-VN')` | 628 |

- UI writes only `type='note'` rows (guarded by `candidates.comment`), index.html:610-616.
- Rendering: `note → "Ghi chú" + body (pre-wrap)`, `created → "Tạo hồ sơ"`, `status_change → "Đổi trạng thái: {body}"`, anything else → `"Chuyển vòng: {from_stage||'-'} → {to_stage}"` (shows raw keys, not labels). index.html:624-630
- No author column is read or written (unknown if DB records actor).

### 1.5 `employees`

| Column | Type | Null | Default | Notes | Ref |
|---|---|---|---|---|---|
| `id` | uuid PK | | | Also storage folder in `hr-docs` | 2063 |
| `full_name` | text | no [UI+Excel req] | | | 746, 771, 1069 |
| `employee_code` | text | yes | | Keep leading zeros ("001") | 747, 1070, selftest.html:549 |
| `title` | text | yes | | | 748 |
| `level` | text | yes | | Form options `Director/Manager/Leader/Staff/Intern`, but **any value is stored/kept** (Excel is free text) | 749, 1072 |
| `department` | text | yes | | | 750 |
| `business_unit` | text | yes | | "Ngành hàng / BU" | 751 |
| `email` | text | yes | | Excel de-dup key (lowercased); used as recipient in compose | 752, 1066, 1640 |
| `phone` | text | yes | | | 753 |
| `manager_id` | uuid FK→employees (self) | yes | | Drawer select excludes the employee itself [UI]; DB may or may not block self-reference (selftest only warns) | 754, 790-791, selftest.html:332-339 |
| `start_date` | date | yes | form default today | | 755, 770 |
| `employment_type` | text enum | yes | | same 4 values as jobs | 756, 1079 |
| `status` | text CHECK | no | form default `'probation'`; Excel `def:'probation'` | `probation` (Thử việc) \| `active` (Chính thức) \| `left` (Đã nghỉ); invalid rejected [DB] | 757, 761, 1080, selftest.html:261-265 |
| `location` | text | yes | | | 758 |
| `note` | text | yes | | | 759 |
| `updated_at` | timestamptz | | | **Auto-updated on every UPDATE** [DB trigger] | selftest.html:254-260 |
| `created_at` | timestamptz | | | [Suy luận] | |

ON DELETE of an employee — **CASCADE** [DB] to: `contracts`, `onboarding_items`, `probation_reviews` (selftest.html:316-326), `employee_private`, `employee_documents`, `leave_requests`, `leave_balances` (selftest.html:810-820). Deleting a subordinate never deletes the manager (selftest.html:327-331). Effect on `employees.manager_id` of subordinates when the *manager* is deleted, on `profiles.employee_id`, and on `email_log.employee_id`: **unknown** (§8).

### 1.6 `employee_private` (sensitive personal record, HR only)

PK/unique: `employee_id` (upsert without `onConflict` → must be PK) index.html:2053; selftest.html:792.

| Column | Type | Spec label | Ref |
|---|---|---|---|
| `employee_id` | uuid PK, FK→employees CASCADE | — | 2052 |
| `dob` | date | Ngày sinh | 2012 |
| `gender` | text enum | Giới tính: `Nam` \| `Nữ` \| `Khác` | 2013 |
| `id_number` | text | Số CCCD/CMND | 2014 |
| `id_issued_on` | date | Ngày cấp | 2015 |
| `id_issued_by` | text | Nơi cấp | 2016 |
| `tax_code` | text (keep as string, e.g. "8123456789") | Mã số thuế cá nhân | 2017, selftest.html:795 |
| `social_insurance_no` | text | Số sổ BHXH | 2018 |
| `dependents` | int | Số người phụ thuộc | 2019, selftest.html:795 |
| `bank_name` | text | Ngân hàng | 2020 |
| `bank_account` | text | Số tài khoản | 2021 |
| `permanent_address` | text | Hộ khẩu thường trú | 2022 |
| `current_address` | text | Chỗ ở hiện tại | 2023 |
| `emergency_contact` | text | Liên hệ khẩn cấp | 2024 |

RLS: policies reference `is_hr` (selftest.html:723).

### 1.7 `employee_documents` (scanned files, HR only)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | |
| `employee_id` | uuid FK CASCADE | | 2067 |
| `doc_type` | text enum | `cccd` CCCD/CMND · `hop_dong` Hợp đồng đã ký · `bang_cap` Bằng cấp / chứng chỉ · `so_yeu` Sơ yếu lý lịch · `kham_suc_khoe` Giấy khám sức khoẻ · `bhxh` Giấy tờ BHXH · `khac` Khác | 2026-2027 |
| `file_path` | text | `hr-docs` object path `{employee_id}/{epoch_ms}-{sanitised name}` | 2063, 2067 |
| `file_name` | text | original name | 2067 |
| `expires_on` | date null | "Hạn hiệu lực (nếu có)" | 2042, 2068 |
| `uploaded_at` | timestamptz default now() | ordering desc | 2032, 2047 |

RLS references `is_hr` (selftest.html:723).

### 1.8 `contracts` (labour contracts, HR only)

| Column | Type | Null | Notes | Ref |
|---|---|---|---|---|
| `id` | uuid PK | | | |
| `employee_id` | uuid FK→employees CASCADE | no [UI req] | | 967, 769 |
| `contract_no` | text | yes | e.g. "HD-2026-001" | 968 |
| `type` | text CHECK | no | `thu_viec` Thử việc · `xd_12` Xác định 12 tháng · `xd_24` Xác định 24 tháng · `khong_xd` Không xác định thời hạn · `thoi_vu` Thời vụ / dịch vụ ; form default `thu_viec` | 965, 969, 768 |
| `status` | text CHECK | no | `draft` Nháp · `active` Hiệu lực · `expired` Hết hạn · `terminated` Đã chấm dứt ; form default `active`; invalid rejected [DB] | 970, 768, selftest.html:273-277 |
| `signed_date` | date | yes | | 971 |
| `start_date` | date | yes | form default today | 972, 768 |
| `end_date` | date | yes | **null = indefinite** | 973 |
| `base_salary` | numeric (VND, integer in practice) | yes | | 974 |
| `note` | text | yes | | 975 |

RLS references `is_hr` (selftest.html:723). `staff.contracts.view = false` in matrix (selftest.html:877-883).

### 1.9 `onboarding_templates` and `onboarding_template_items`

`onboarding_templates`: `id` uuid PK, `name` text (ordered by name). index.html:369, 922
`onboarding_template_items`: `id`, `template_id` FK→onboarding_templates [Suy luận from PostgREST embed], `title` text, `owner_role` text (free text, e.g. "HR", "IT" — values unknown), `day_offset` int (may be negative; displayed `+N`/`-N`), `sort_order` int. index.html:936-939, 957-959

- Seeded: at least one template, the first having **≥5 items** [DB seed] (selftest.html:278-284).
- **No CRUD UI** for templates; "Mẫu checklist" is read-only (index.html:954-962).

### 1.10 `onboarding_items` (per-employee checklist)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | |
| `employee_id` | uuid FK CASCADE | | 937 |
| `title` | text | copied from template item | 937 |
| `owner_role` | text | copied | 937 |
| `due_date` | date | `addDays(base, day_offset)` | 938 |
| `sort_order` | int | copied | 938 |
| `done` | bool default false | | 927 |
| `done_at` | timestamptz null | set to now on check, null on uncheck | 927 |

### 1.11 `probation_reviews`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | |
| `employee_id` | uuid FK CASCADE, required [UI] | | 1020, 1033 |
| `review_date` | date | default today in form | 1021, 1031 |
| `reviewer` | text (free text name) | | 1022 |
| `result` | text CHECK null | `pass` "Đạt — ký chính thức" · `extend` "Gia hạn thử việc" · `fail` "Không đạt"; empty = no conclusion; invalid rejected [DB] | 1015, 1023, selftest.html:311-315 |
| `scores` | jsonb | `{chuyen_mon, tien_do, thai_do, phoi_hop, tuan_thu}` → number; only filled criteria stored | 1008-1014, 1034-1039 |
| `total` | numeric(?,1) | mean of filled scores, rounded to 1 decimal; null if none | 1040, selftest.html:303-310 |
| `strengths` | text | | 1025 |
| `improvements` | text | | 1026 |
| `note` | text | | 1027 |
| `confirmed_by` | uuid (auth user id) | set on "Xác nhận hoàn tất" | 858, selftest.html:895-899 |
| `confirmed_at` | timestamptz | | 858 |

### 1.12 `email_templates`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | 1766 |
| `key` | text UNIQUE [Suy luận] | seeded: `offer_letter`, `interview_invite`, `rejection` (others possible) | selftest.html:612-619 |
| `name` | text | dropdown label | 1647 |
| `subject` | text with `{{vars}}` | | 1643 |
| `body` | text with `{{vars}}` | `offer_letter.body` must contain `{{luong}}` and `{{ngay_bat_dau}}` | selftest.html:617 |

UI: edit only (name/subject/body); no create/delete. index.html:1757-1773

### 1.13 `offers`

| Column | Type | Null | Default | Notes | Ref |
|---|---|---|---|---|---|
| `id` | uuid PK | | | | |
| `candidate_id` | uuid FK→candidates | yes | | **ON DELETE SET NULL** [DB] | 1465, selftest.html:696-705 |
| `job_id` | uuid FK→jobs | yes | | on-delete unknown | 1465 |
| `candidate_name` | text | no [UI] | | | 1441, 1477 |
| `candidate_email` | text | yes | | | 1442 |
| `position_title` | text | no [UI] | | | 1443, 1477 |
| `department` | text | yes | | | 1444 |
| `salary_gross` | numeric (VND) | yes | | | 1445 |
| `allowance` | text (free) | yes | | | 1446 |
| `start_date` | date | yes | form: today+14 | | 1447, 1468 |
| `probation_months` | numeric (step 0.5) | yes | **2** [DB default] | | 1448, selftest.html:633 |
| `probation_salary_pct` | int | yes | **85** [DB default] | strict `=== 85` in selftest → integer | 1449, selftest.html:633 |
| `work_location` | text | yes | form: job.location or "TP. Hồ Chí Minh" | | 1450, 1469 |
| `work_time` | text | yes | form: "Thứ 2 - Thứ 6, 8h30 - 17h30" | | 1451, 1469 |
| `manager_name` | text | yes | form: job.hiring_manager | | 1452, 1470 |
| `signer_name` | text | yes | | | 1453 |
| `signer_title` | text | yes | form: "Giám đốc" | | 1454, 1470 |
| `expires_on` | date | yes | form: today+7 | response deadline | 1455, 1471 |
| `status` | text CHECK | no | **'draft'** [DB] | `draft` · `sent` · `accepted` · `declined` · `expired`; invalid rejected [DB] | 1456, 1459, selftest.html:632, 647-651 |
| `note` | text | yes | | internal | 1457 |
| `sent_at` | timestamptz | yes | | set when auto-moving draft→sent | 1701, 1717 |
| `created_at` | timestamptz | | now() | ordering | 372 |

RLS: references `is_hr` (selftest.html:723) **and** read policy `offers_read` calls `has_perm('offers', …)` (selftest.html:941).

### 1.14 `email_log`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | 1750 |
| `candidate_id` | uuid FK null | `candidate.id \|\| offer.candidate_id` | 1677 |
| `employee_id` | uuid FK null | | 1678 |
| `offer_id` | uuid FK null | drawer lists emails by offer | 1678, 1493 |
| `template_key` | text | selected template key | 1678 |
| `to_email` | text not null | | 1679 |
| `cc_email` | text null | | 1679 |
| `subject` | text | | 1680 |
| `body` | text | | 1680 |
| `channel` | text CHECK | `gmail` \| `resend` (anything else rejected [DB], e.g. "bo-cau") | 1699, 1715, selftest.html:662-666 |
| `status` | text | `draft` (label only, never written) · `opened_in_gmail` · `sent` · `failed` | 1747 |
| `error` | text null | Resend failure reason | 1681, 1715 |
| `created_at` | timestamptz default now() | | 1745 |

RLS references `is_hr` (selftest.html:723) and `email_log_read` calls `has_perm('mail', …)` (selftest.html:941).

### 1.15 `profiles` (app user ↔ auth user)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK = `auth.users.id` [Suy luận] | updates by id | 1999, 2004 |
| `email` | text | matched against session email | 388 |
| `role` | text FK→roles.key [Suy luận] | **default `staff` on first login** (UI note) | 1993 |
| `employee_id` | uuid FK→employees null | links account to employee record; required for own leave requests | 2004, 1888 |

Row is presumably created by a DB trigger on `auth.users` insert, which is also where the `@helidirect.com` domain restriction raises (§8).

### 1.16 `roles`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `key` | text PK | `[a-z0-9_]` after normalisation; unique checked client-side | 2128-2130 |
| `label` | text | | 2131 |
| `base` | text CHECK | `admin` Quản trị · `hr` Nhân sự · `manager` Quản lý · `staff` Nhân viên | 421, 2125 |
| `sort` | int | default 50 | 2127, 2131 |
| `is_system` | bool | system roles `admin`,`hr`,`manager`,`staff` exist with `is_system=true` [DB seed]; system roles cannot be deleted [UI] | 2113, 2119, selftest.html:860-866 |

### 1.17 `role_perms` (permission matrix)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `role_key` | text FK→roles.key | | 2134 |
| `module` | text | one of 12 `PERM_MODULES` keys | 411-416 |
| `action` | text | one of 6 `PERM_ACTIONS` keys | 417-420 |
| `allowed` | bool | | 2134 |

- **UNIQUE (`role_key`,`module`,`action`)** — upsert `onConflict: 'role_key,module,action'` (index.html:2177).
- Each system role has exactly **72 rows** (12×6); admin has all 72 `allowed=true` [DB seed] (selftest.html:867-876).
- New custom role → 72 rows inserted with `allowed=false` (index.html:2133-2135).

### 1.18 `leave_types`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `key` | text PK | seeded at least `annual`, `sick`, `unpaid`, `maternity` | selftest.html:744-751 |
| `label` | text | | 1893 |
| `default_days` | numeric | `annual` = **12** | 1867, selftest.html:749 |
| `sort_order` | int | | 375 |

### 1.19 `leave_requests`

| Column | Type | Null | Default | Notes | Ref |
|---|---|---|---|---|---|
| `id` | uuid PK | | | | |
| `employee_id` | uuid FK→employees CASCADE | no | | | 1945 |
| `type_key` | text FK→leave_types.key [DB] | no | form `annual` | | 1936, selftest.html:770-774 |
| `start_date` | date | no | today | | 1937 |
| `end_date` | date | no | today | **CHECK end_date ≥ start_date** [DB] | 1938, selftest.html:765-769 |
| `days` | numeric (0.5 step) | no? | auto `workDays()` | | 1939, 1950 |
| `reason` | text | yes | | | 1940 |
| `status` | text | no | **'pending'** [DB] | `pending` Chờ duyệt · `approved` Đã duyệt · `rejected` Từ chối · `cancelled` Đã huỷ | 1844, selftest.html:759-764 |
| `approver_name` | text | yes | | **email of the decider** (not a FK) | 1925 |
| `decided_at` | timestamptz | yes | | | 1925 |
| `decision_note` | text | yes | | rejection reason | 1925 |

RLS policy text contains `my_employee_id` (selftest.html:724).

### 1.20 `leave_balances`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `employee_id` | uuid FK CASCADE | | 1972 |
| `year` | int | calendar year | 1972 |
| `type_key` | text | UI only ever writes `'annual'` | 1972 |
| `days_total` | numeric (0.5 step) | | 1972 |

Upsert **without** `onConflict` (index.html:1974; selftest.html:783) ⇒ the primary key must be (`employee_id`,`year`,`type_key`) [Suy luận — otherwise each save duplicates rows].

### 1.21 `notifications` (reminders, generated by RPC)

| Column | Type | Notes | Ref |
|---|---|---|---|
| `id` | uuid PK | | 1819 |
| `kind` | text | `contract_expiring` Hợp đồng · `probation_due` Thử việc · `onboarding_overdue` Onboarding · `offer_expiring` Offer · `leave_pending` Nghỉ phép | 1778 |
| `title` | text | contains the employee's name (selftest asserts title includes the `[SELFTEST]` employee name) | 1817, selftest.html:836 |
| `body` | text null | | 1817 |
| `due_date` | date null | past → warn tag, else soon tag | 1818 |
| `status` | text | `open` \| `done` | 378, 1838 |
| `ref_id` | uuid | id of the source record (e.g. contract id) | selftest.html:834 |

Uniqueness: at most one row per (`kind`,`ref_id`) [DB behaviour, selftest.html:837-840]. Read policy `notif_read` calls `has_perm('reports', …)` (selftest.html:941).

### 1.22 `backups`

| Column | Type | Notes | Ref |
|---|---|---|---|
| `slot` | int [Suy luận: 1..31 = day of month] | "giữ 31 bản gần nhất (mỗi ngày trong tháng một bản)" | 2187, 2194 |
| `taken_at` | timestamptz | | 2187 |
| `row_counts` | jsonb `{table: count}` | at least `employees`, `candidates`, `contracts` | 2202, selftest.html:986 |
| `data` | jsonb `{table: row[]}` | must contain arrays for `employees, candidates, jobs, stages, contracts, leave_requests, profiles` | selftest.html:989-995 |
| `size_kb` | numeric [Suy luận: returned by RPC, maybe a column] | | 2210 |

### 1.23 `selftest_runs` (selftest only)

`run_by` text (email), `passed` int, `failed` int, `warned` int, `duration_s` numeric(1 dec), `results` jsonb array of `{g: group, n: name, s: 'pass'|'fail'|'warn', d: detail≤400 chars}`; plus [Suy luận] `id`, `created_at`. selftest.html:1030-1037

### 1.24 Storage buckets

| Bucket | Path convention | Upload | Download | Delete | Limits | Public? |
|---|---|---|---|---|---|---|
| `cvs` | `{candidate_id}/{original file.name}` (**not sanitised**), `upsert: true` (re-upload overwrites) | After candidate insert, from "Tải CV lên" flow (index.html:574-579). Accepted picker types `.pdf,.docx,.txt` (index.html:213) | Signed URL, **300 s**, opened in new tab (index.html:617-622) | `purgeFiles('candidates', id)` lists folder (limit 200) and removes all (index.html:312-321) — but **no candidate-delete button exists in the UI**, so it only matters for future delete | No size check | Must **not** be public (selftest.html:126-130) |
| `hr-docs` | `{employee_id}/{Date.now()}-{name with [^\w.\-]+ → '_'}` | HR "Chọn file & tải lên" (index.html:2057-2074), guarded `employees.create` | Signed URL 300 s (index.html:2075-2079) | Single doc delete removes object after DB row delete (index.html:2087-2091); employee delete purges whole folder (index.html:807 → 328 → 313-321) | **15 MB max** (index.html:2062); any MIME type | Must **not** be public (selftest.html:805-809) |

Storage policies (who may read/write which prefix) live in Supabase and are **unknown** (§8).

---
## 2. RPC & Edge functions

### 2.1 Postgres RPCs

| RPC | Args | Returns (as consumed) | Called by | Behaviour required |
|---|---|---|---|---|
| `refresh_notifications` | none | **integer** = number of currently open notifications | Dashboard "Quét nhắc việc ngay" (index.html:1790-1794); selftest K (selftest.html:823-850) | Idempotent upsert of reminders: creates exactly one `notifications` row per (`kind`,`ref_id`); re-running does not duplicate; when the source condition clears (e.g. contract set to `terminated`) the row flips to `status='done'`. Also runs daily via cron job **`hdrc-daily-reminders`** at 08:00 (selftest.html:851-856; UI text index.html:1820) |
| `make_backup` | none | `{slot, size_kb, row_counts: {table: n}}` | Settings "Chụp bản sao lưu ngay" (index.html:2205-2212); selftest L (selftest.html:982-988) | Snapshot all tables into `backups` (rotating 31 slots); anon must be rejected (selftest.html:977-981). Daily cron **`hdrc-daily-backup`** at 02:10 (selftest.html:996-1001; UI text index.html:2194) |
| `my_role` | none | base role text (`admin`\|`hr`\|`manager`\|`staff`) — **base**, not custom key | selftest only (selftest.html:715-719, 889-894) | |
| `my_role_key` | none | the profile's role key (may be custom) | selftest only (selftest.html:915) | |
| `is_hr` | none | boolean (true for base admin/hr) | selftest only (selftest.html:716-717); used in RLS | |
| `my_employee_id` | none | uuid [Suy luận] | referenced inside `leave_requests` RLS (selftest.html:724) | |
| `has_perm` | `p_module text, p_action text` | boolean | selftest (selftest.html:884-888, 914-936); used inside read policies | Base `admin` → always true; otherwise `role_perms.allowed` for the caller's role key. Anon call must fail (≥400) |
| `policy_report` | none | rows `{tbl, cmd, qual, with_check, …}` | selftest H | Introspection of `pg_policies` |
| `read_policy_report` | none | rows `{policy_name, expr}` | selftest L3 (selftest.html:937-948) | |
| `perm_triggers_report` | none | rows `{table_name, …}` | selftest L3 (selftest.html:903-913) | |
| `view_perm_matrix` | none | rows `{role_key, module, allowed}` (the `view` action) | selftest L3 (selftest.html:949-961) | |
| `cron_jobs_report` | none | rows `{jobname, active, …}` | selftest K, L | |

`*_report` RPCs are Postgres-introspection helpers for the test suite; in Laravel they become test assertions on policies/middleware, not endpoints.

### 2.2 Edge function `parse-cv`

**Request** (index.html:641): `POST functions/v1/parse-cv`, JSON `{ text: string, filename?: string }`, requires a logged-in JWT.

**Client pre-processing** (index.html:633-668): `.txt` → `file.text()`; `.docx` → mammoth `extractRawText`; `.pdf` → pdf.js, pages 1..min(8, numPages), items joined by space, page by `\n`; other extensions → `''`. Empty text → toast and open the manual form with only the file attached.

**Response consumed:**

```jsonc
{
  "candidate": {            // keys matching CAND_SPEC prefill the form (index.html:644, 565-566)
    "full_name": "Tran Minh Hoang",
    "email": "hoang.tran@gmail.com",
    "phone": "0912345678",          // digits only, spaces stripped ("0987 654 321" → "0987654321")
    "years_experience": 5,          // number (2.8 allowed)
    "skills": ["SQL", "Python", "Power BI"],  // array; fromCand() turns it into skills_text
    "current_title": "...", "current_company": "...", "source": "..."   // optional [Suy luận]
  },
  "engine": "ai" | "<anything else = rules>",   // toast says "AI" vs "quy tắc" (index.html:645)
  "not_a_cv": true | false,                    // true → warning toast "giống mô tả công việc" (index.html:643)
  "build": "string"                             // parser build id, only shown by selftest (selftest.html:220)
}
```

**Behaviour expected by selftest (group C):**

| Input | Expected | Ref |
|---|---|---|
| No auth header | HTTP **401** | selftest.html:121-125 |
| `text: 'abc'` (too short) with auth | HTTP **400** | selftest.html:239-243 |
| English CV (name on line 2 after "CURRICULUM VITAE") | `full_name='Tran Minh Hoang'`, `email`, `phone='0912345678'`, `years_experience=5` | selftest.html:205-215 |
| Vietnamese (no diacritics) uppercase name | `full_name='NGUYEN NGOC PHUONG TRANG'` (case kept), `years_experience=2.8` from "2.8 nam kinh nghiem", `phone='0987654321'` | selftest.html:216-225 |
| Job description text | `candidate.full_name` empty/falsy (and presumably `not_a_cv=true`) | selftest.html:226-231 |
| Skill extraction | whole-word only: must not emit `r`, `go`, `git`, `java` from "Google", "digital", etc. | selftest.html:232-238 |

After parse, UI opens "Thêm ứng viên" prefilled; on save the candidate is inserted with `cv_text = text.slice(0, 20000)`, then the file is uploaded and `cv_path/cv_filename` set (index.html:565-582). Parse errors → toast and manual form, still with file+text (index.html:646-647).

### 2.3 Edge function `send-email` (Resend)

| Mode | Request | Response | Ref |
|---|---|---|---|
| Probe | `{ probe: true }` | `{ configured: boolean, from?: string }`. Cached per page load in `state.resend` (`false` if not configured or on error) | index.html:1620-1627; selftest.html:672-678 |
| Send | `{ to, cc, subject, text }` (`cc` is `''` when empty; plain text only) | success `{ sent: true }`; failure `{ sent: false?, error: string }` or transport error | index.html:1711-1716 |

Server-side validation expected by selftest: no auth → **401** (selftest.html:667-671); invalid `to` → **400** `{error}` (selftest.html:679-686); empty `subject` → 400; `text` too short (`'ngan'`, 4 chars) → 400, while 20 chars is acceptable (selftest.html:687-695). If `RESEND_API_KEY` is not set, `configured=false` and the UI hides "Gửi ngay" (by design, selftest warns only).

UI flow: "Gửi ngay ({from})" button only shown when probe returns configured (index.html:1704-1707). On click: client validation (§4.8), button disabled, invoke, then **always** insert `email_log` (`channel='resend'`, `status='sent'|'failed'`, `error`), and on success auto-advance an offer from `draft` to `sent` + `sent_at` (index.html:1708-1719).

### 2.4 Gmail deep link (no backend)

`https://mail.google.com/mail/?view=cm&fs=1&authuser={me}&to=…&cc=…&su=…&body=…` opened in new tab; then `email_log` insert with `channel='gmail'`, `status='opened_in_gmail'`; offer `draft → sent` (+`sent_at`) (index.html:1689-1703). Log insert failure is only a toast; the offer update result is not checked.

---

## 3. Phân quyền (permissions)

### 3.1 Role model

- **Base roles** (`ROLE_BASES`, index.html:421): `admin` Quản trị, `hr` Nhân sự, `manager` Quản lý, `staff` Nhân viên. `ROLE_LABEL` display: admin "Quản trị (toàn quyền)", hr "Nhân sự (HR)", manager "Quản lý", staff "Nhân viên" (index.html:1981).
- **Role** = row in `roles` with a `base`. A profile's `role` is a role key; the effective *base* (`state.role`) decides **which tabs are visible** and **DB data scope**; the *role key* (`state.roleKey`) selects the action matrix (index.html:388-390, 2104-2105, 2157-2158).
- `perm(module, action)`: **base `admin` → always true**; else `role_perms[role_key=roleKey, module, action].allowed`, missing row = false (index.html:422-426). A custom role whose base is `admin` therefore also gets full action rights.
- `isHR()` = base ∈ {admin, hr} (index.html:410). `can(roles)` = base ∈ roles (index.html:409).
- `guard(module, action)` = `perm()` or toast `Vai trò "{label}" không có quyền {action label lowercased} ở nhóm "{module label}"` (index.html:295-301).
- `guardTable(table, action)` maps table → module via `TABLE_MODULE` (unknown table → `settings`) (index.html:287-294, 302).

**PERM_MODULES** (index.html:411-416):

| key | label |
|---|---|
| candidates | Ứng viên |
| jobs | Vị trí & JD |
| offers | Offer letter |
| employees | Hồ sơ nhân sự |
| contracts | Hợp đồng |
| leave | Nghỉ phép |
| onboarding | Onboarding |
| probation | Thử việc |
| mail | Thư & Email |
| reports | Báo cáo |
| backups | Sao lưu |
| settings | Phân quyền |

**PERM_ACTIONS** (index.html:417-420): `view` Xem · `create` Tạo mới · `edit` Sửa · `delete` Xoá · `approve` Duyệt / xác nhận · `comment` Ghi chú / bình luận.

**TABLE_MODULE** (index.html:287-294): candidates, candidate_events → candidates · jobs → jobs · offers → offers · employees, employee_private, employee_documents → employees · contracts → contracts · leave_requests, leave_balances → leave · onboarding_items, onboarding_templates → onboarding · probation_reviews → probation · email_templates, email_log → mail · backups → backups · roles, role_perms, profiles, stages → settings.

### 3.2 Tab visibility by base role (`VIEWS`, index.html:397-408)

| View key | Label | admin | hr | manager | staff |
|---|---|:-:|:-:|:-:|:-:|
| dash | Tổng quan | ✓ | ✓ | ✓ | ✓ |
| pipeline | Tuyển dụng | ✓ | ✓ | ✓ | – |
| jobs | Vị trí & JD | ✓ | ✓ | ✓ | ✓ |
| newhire | Nhân sự mới | ✓ | ✓ | ✓ | ✓ |
| people | Hồ sơ nhân sự | ✓ | ✓ | ✓ | ✓ |
| org | Sơ đồ tổ chức | ✓ | ✓ | ✓ | ✓ |
| leave | Nghỉ phép | ✓ | ✓ | ✓ | ✓ |
| mail | Thư & Email | ✓ | ✓ | ✓ | – |
| settings | Phân quyền | ✓ | ✓ | – | – |

Tab visibility ignores the matrix (a custom staff-based role with `candidates.view` still has no Pipeline tab). If the current view is not allowed, the first allowed view is chosen (index.html:430). Default `state.view='pipeline'` (index.html:238). The settings toolbar states the intended policy: "HR/Quản trị thấy toàn bộ · Quản lý thấy tuyển dụng, đội của mình · Nhân viên chỉ thấy danh bạ, onboarding và nghỉ phép của mình" (index.html:1983).

### 3.3 Every UI permission check

**Visibility checks (`perm()` / `isHR()` in templates):**

| Where | Check | Effect | Ref |
|---|---|---|---|
| Pipeline toolbar | `perm('candidates','create')` | show "+ Ứng viên" | 506 |
| Jobs toolbar | `perm('jobs','create')` | show "+ Vị trí" | 690 |
| People toolbar | `isHR() && perm('contracts','create')` | show "+ Hợp đồng" | 765 |
| People toolbar | `perm('employees','create')` | show "+ Nhân sự" | 766 |
| People view | `isHR()` | append contracts section (list + 30-day warning) | 783-786 |
| Employee drawer | `perm('employees','delete')` | show "Xoá" | 797 |
| Employee drawer | `isHR()` | show "Hồ sơ & giấy tờ" | 798 |
| New hires toolbar | `perm('probation','create')` | show "+ Đánh giá" | 864 |
| New hire card | `complete && last.result==='pass' && perm('probation','approve')` | show "Xác nhận hoàn tất" | 874 |
| Dashboard reminder row | `isHR()` | show "Đã xử lý" | 1819 |
| Leave view | `isHR()` | approve list = all pending; list = all requests; show "Hạn mức phép"; row click opens edit for any request | 1860-1861, 1873, 1911 |
| Leave form | `isHR()` | employee picker; status select when editing | 1933-1941 |
| Settings | `isHR()` | role panel and backup panel rendered | 2099, 2185 |
| Role editor | `r.key === 'admin'` | matrix locked/disabled | 2155-2170 |
| Role list | `r.is_system` | no delete button | 2119 |

**Action guards (block with toast before calling the DB):**

| Action | Guard | Ref |
|---|---|---|
| Any `upsert(table, payload, id)` | `guardTable(table, id ? 'edit' : 'create')` | 305 |
| Any `removeRow(table, id)` | `guardTable(table, 'delete')` | 323 |
| Excel import | `guardTable(table,'create') && guardTable(table,'edit')` | 1372 |
| Add candidate (modal save) | `guard('candidates','create')` | 568 |
| Candidate note | `guard('candidates','comment')` | 612 |
| Confirm probation | `guard('probation','approve')` **and** then `upsert('employees',{status:'active'})` ⇒ also needs `employees.edit` | 849, 859 |
| Tick onboarding item | `guard('onboarding','edit')` (reverts checkbox) | 926 |
| Apply onboarding template | `guard('onboarding','create')` | 933 |
| Clear checklist | `guard('onboarding','delete')` | 947 |
| Save offer | `guard('offers', id ? 'edit' : 'create')` | 1475 |
| Gmail / Resend send | `guard('mail','create')` | 1683 |
| Approve/reject leave | `guard('leave','approve')` | 1918 |
| Save leave balances | `guard('leave','edit')` | 1970 |
| Save private record | `guard('employees','edit')` | 2051 |
| Upload HR document | `guard('employees','create')` | 2058 |
| Delete HR document | `guard('employees','delete')` | 2081 |

**Writes with NO matrix guard in the UI** (the rebuild must enforce server-side):

| Write | Only protection in UI | Ref |
|---|---|---|
| Kanban drag → `candidates.update({stage})` | Pipeline tab visible to base manager+ | 548 |
| `candidates.update({cv_path, cv_filename})` after insert | preceded by `candidates.create` guard | 578 |
| `probation_reviews.update({confirmed_by, confirmed_at})` | `probation.approve` (not `probation.edit`); result not checked | 858 |
| `offers.update({status:'sent', sent_at})` after mail | `mail.create` only | 1701, 1717 |
| `email_log.insert` | `mail.create` | 1699, 1715 |
| `notifications.update({status:'done'})` | `isHR()`; result not checked | 1837-1840 |
| `profiles.update({role})`, `profiles.update({employee_id})` | Settings tab = base admin/hr | 1998-2007 |
| `roles.insert`, `role_perms.insert` (72 rows), `roles.delete`, `role_perms.upsert` | `isHR()` | 2122-2146, 2173-2180 |
| `rpc('make_backup')`, `backups.select('*')` download | `isHR()` | 2205-2223 |
| `rpc('refresh_notifications')` | Dashboard (all roles) | 1790-1794 |
| Storage `cvs.upload`, `hr-docs.upload/remove`, `purgeFiles` | follow the guarded parent action | 576, 2064, 2089, 316-319 |

Buttons shown to everyone that rely on the guard at save time: "Tải CV lên" (512), candidate drawer Save/Loại/Mở lại/Soạn email/Tạo offer (589-593), job drawer Xoá/Lưu (725-728), contract drawer Xoá/Lưu (1002), review "Xoá đánh giá" (1044), offer drawer Xoá/Sửa/Gửi email (1506-1511), "+ Offer" and "Mẫu thư" on Mail (1726-1727), "+ Đơn nghỉ" (1874), Excel buttons (1393-1399).

### 3.4 Data-scope rules visible in the UI (client-side filtering)

| Rule | Ref |
|---|---|
| Leave list: HR sees all; others see own (`employee_id === myEmp`) **plus direct reports** (`employees.manager_id === myEmp`, one level only) | 1853-1856, 1861 |
| Leave approval queue: HR → all `pending`; others → `pending` of direct reports | 1860 |
| Leave row click: HR → edit any; non-HR → only own **and** `pending` ("Đơn đã được xử lý — liên hệ HR nếu cần sửa") | 1908-1915 |
| Leave form non-HR: `employee_id` forced to `myEmp`, `status` forced to `'pending'` (editing re-submits for approval) | 1945-1946 |
| No linked employee (`myEmp` null): leave summary replaced by a warning; save fails "Chưa gắn hồ sơ nhân sự cho tài khoản này" | 1880-1888, 1947 |
| Contracts section & HR documents only for `isHR()` | 783, 798 |
| Employee drawer contract list shows whatever `contracts` rows RLS returns for the user | 792, 801 |
| Settings notes: new users default **staff**; link account→employee to file leave and "see own contract"; salary & contracts & ID scans HR/Admin only | 1993-1995 |

### 3.5 DB-level permission expectations (from selftest) — test matrix

| # | Subject | Expectation | Ref |
|---|---|---|---|
| P1 | anon | `GET candidates, employees, contracts, probation_reviews, onboarding_items, jobs` → 401 or empty array | selftest.html:109-115 |
| P2 | anon | `POST employees` → ≥400 | selftest.html:116-120 |
| P3 | anon | `parse-cv`, `send-email` → 401 | selftest.html:121-125, 667-671 |
| P4 | anon | public URL of bucket `cvs` / `hr-docs` → ≥400 | selftest.html:126-130, 805-809 |
| P5 | anon | `rpc/has_perm`, `rpc/make_backup` → ≥400 | selftest.html:884-888, 977-981 |
| P6 | anon | `GET role_perms`, `GET backups` → 401 or empty | selftest.html:962-967, 971-976 |
| P7 | test runner | current profile role ∈ {admin, hr}; `is_hr()` = true | selftest.html:709-719 |
| P8 | RLS text | `contracts`, `employee_private`, `employee_documents`, `offers`, `email_log` policies mention `is_hr`; `candidates` mentions `my_role`; `leave_requests` mentions `my_employee_id`; none of these has a `cmd=ALL, qual='true'` policy | selftest.html:720-733 |
| P9 | RLS coverage | `employees, contracts, leave_requests, employee_private, notifications, profiles` all have policies | selftest.html:734-740 |
| P10 | seeds | roles `admin, hr, manager, staff` exist with `is_system=true` | selftest.html:860-866 |
| P11 | seeds | each system role has 72 `role_perms` rows; admin has 72 allowed | selftest.html:867-876 |
| P12 | seeds | `staff / contracts / view = false` | selftest.html:877-883 |
| P13 | functions | `my_role()` returns a base role | selftest.html:889-894 |
| P14 | triggers | a permission-enforcing trigger exists on each of: candidates, candidate_events, jobs, offers, employees, employee_private, employee_documents, contracts, onboarding_items, onboarding_templates, onboarding_template_items, probation_reviews, leave_requests, leave_balances, leave_types, email_templates, email_log, backups, roles, role_perms, stages, profiles (22) | selftest.html:903-913 |
| P15 | has_perm | equals `role_perms.allowed` for the caller's role key, except base admin → always true | selftest.html:914-929 |
| P16 | has_perm | returns boolean for `('leave','approve')` | selftest.html:930-936 |
| P17 | read policies | `employees_read→has_perm('employees'`, `jobs_read→'jobs'`, `candidates_read→'candidates'`, `candidate_events_read→'candidates'`, `offers_read→'offers'`, `email_log_read→'mail'`, `notif_read→'reports'` | selftest.html:937-948 |
| P18 | view matrix | `view` allowed for **all four** base roles on `employees` and `jobs`; admin/hr/manager have `candidates.view` | selftest.html:949-961 |

Commit `0993664` adds context: "7 chính sách đọc RLS gọi has_perm(module,'view'); cấp trước staff.jobs.view để không ai mất quyền".

**Combined effective read access (best reconstruction):** row visible ⇔ RLS base-role rule (e.g. `is_hr()` for contracts/private/documents/offers/email_log) **AND**, for the 7 tables in P17, `has_perm(module,'view')`. Write enforcement is by per-table triggers (P14) that presumably call `has_perm(module, create|edit|delete)` — exact action mapping unknown (§8).

---
## 4. Module / màn hình (views & sub-features)

### 4.0 Shared UI mechanics

- **Form engine** `fieldsHTML(spec, values, prefix)` / `readFields(spec, prefix)` (index.html:245-275). Field types: `text` (default), `number` (with optional `step`), `date`, `select` (`opts: [[value, label], …]`), `textarea` (`rows`, `hint` as placeholder). `wide:true` spans both grid columns. Element id = `{prefix}_{key}`; drawers use prefix `f`, modals `m`, private record `pv`.
- `readFields`: trims; empty string → `null`; `number` → `Number(v)`; missing element → warn and `null` (index.html:265-275).
- **Select with unknown stored value**: adds a first `<option>` with that value labelled `"{val} — giá trị đang lưu (ngoài danh sách)"`, selected, class `odd-val`, so saving does not overwrite it (index.html:250-256; selftest.html:415-428).
- `formModal(title, spec, values, onSave)`: modal with Huỷ/Lưu; Save button disabled during `onSave` (anti double-submit) (index.html:276-285).
- `upsert(table, payload, id)`: guard → update by id or insert → on error toast "Lỗi lưu: …" → `loadAll()+render()` (index.html:304-309).
- `removeRow(table, id, label)`: guard → `confirm('Xoá {label}? Không khôi phục được.')` → `delete().eq('id').select('id')`; **zero rows returned ⇒ "Không xoá được — thiếu quyền hoặc bản ghi đã bị xoá"** (RLS silently filters) → `purgeFiles` → close, reload (index.html:322-330).
- Overlays: one right drawer, one centred modal, scrim; Escape or scrim click closes all and clears their HTML (index.html:333-340).
- Search: one box per view bound to `state.q` (reset on tab change); re-render keeps focus and caret (index.html:456-471). Matching = case-insensitive substring over the concatenation of listed fields (`hit`, index.html:472-476).
- Nav badge on "Tổng quan" = count of open notifications (index.html:432).

### 4.1 `dash` — Tổng quan (dashboard & reminders) — all roles

Toolbar: caption + "Quét nhắc việc ngay" → `rpc('refresh_notifications')` → reload (index.html:1788-1794).

Reminder panel (index.html:1813-1820): if open notifications exist, list first **20** with: kind tag (`NOTIF_LABEL`), title (bold), body, due-date tag (past → warn, else soon), and for HR a "Đã xử lý" button → `notifications.update({status:'done'})` → reload (index.html:1837-1840). Else "Không có việc nào quá hạn. Hệ thống tự quét mỗi sáng 8h."

KPI tiles (index.html:1795-1830) — computed client-side from loaded rows:

| Tile | Formula |
|---|---|
| Nhân sự đang làm | employees with `status != 'left'` |
| Đang thử việc | of those, `status = 'probation'` |
| Vị trí đang tuyển | jobs `is_open` |
| Ứng viên đang xử lý | candidates `status = 'active'` |
| Offer chờ phản hồi | offers `status = 'sent'` |
| HĐ hết hạn ≤30 ngày (red if >0) | contracts `status='active'` and `0 ≤ dayDiff(end_date) ≤ 30` |
| Đơn nghỉ chờ duyệt (red if >0) | leave_requests `status='pending'` |
| Thời gian tuyển TB | mean over candidates with `stage='hired'` of `round((stage_changed_at − created_at)/1 day)`, negatives dropped; `–` if none |

Bar panels: employees by `department` (null → "Chưa gán", desc); active candidates by stage (stage order); active candidates by `source` (null → "Chưa rõ", top 8 desc); offer acceptance: Đồng ý = accepted, Từ chối = decided − accepted (decided = accepted+declined), Chờ phản hồi = sent (index.html:1805-1809, 1831-1836). Bar width = value / max.

### 4.2 `pipeline` — Tuyển dụng (kanban) — admin/hr/manager

**Toolbar** (index.html:500-512): job filter select ("Tất cả vị trí" + jobs), search (name, email, phone, current_title, current_company, skills), checkbox "Hiện ứng viên đã loại / rút" (default off), "Tải CV lên", "+ Ứng viên" (perm), Excel bar `candidates`.

**Board** (index.html:514-528): rows = candidates where (`showClosed` or `status='active'`) and (no job filter or `job_id` matches) and search hit. A summary strip shows per-stage counts with greyscale shading (stage i of n: grey `245→0`, white text when >45%) (index.html:479-483, 522-523). One column per stage in `sort_order`; candidates whose `stage` key is not in `stages` are silently not rendered.

**Card** (index.html:554-564): name; "current_title · current_company" or "Chưa rõ vị trí hiện tại"; tags: job title, "{years} năm", days in stage (`daysAgo(stage_changed_at)`: ≤0 → "hôm nay", else "N ngày"), and for non-active "đã loại" (rejected) / "đã rút" (other).

**Drag & drop** (index.html:530-552): drop on another column → optimistic local update (`stage`, `stage_changed_at=now`) + pop animation → `candidates.update({stage})`; on error revert + toast; success toast "{name} → {stage label}". No permission guard (see §3.3). Click card → candidate drawer.

**CAND_SPEC** (verbatim, index.html:484-495):

| key | label | type | options |
|---|---|---|---|
| full_name | Họ tên * | text | |
| email | Email | text | |
| phone | Điện thoại | text | |
| current_title | Chức danh hiện tại | text | |
| current_company | Công ty hiện tại | text | |
| years_experience | Số năm kinh nghiệm | number (step 0.1) | |
| source | Nguồn | text | |
| job_id | Vị trí ứng tuyển | select | `['', '— chưa gán —']` + all jobs `[id, title]` |
| stage | Vòng | select | all stages `[key, label]` |
| skills_text | Kỹ năng (cách nhau bằng dấu phẩy) | text, wide | → `skills[]` by split(',') trim, drop empty (`toCand`) |

**Add candidate** (index.html:565-582): defaults `stage='new'`, `job_id=current filter`; requires `full_name`; optional `cv_text` (≤20 000 chars) and file → insert, then upload `cvs/{id}/{file.name}` (upsert) and update `cv_path, cv_filename` (upload failure → toast, record kept).

**Candidate drawer** (index.html:583-623):
1. Loads `candidate_events` for the candidate (desc).
2. Form (CAND_SPEC). Buttons: "Loại ứng viên" (if active) / "Mở lại" (otherwise) → toggles `status` active↔**rejected** only (never sets `withdrawn`); "Soạn email" → compose with the **live, unsaved** form values; "Tạo offer" → offer form prefilled from live values; "Lưu" → update.
3. Note box → insert `candidate_events{type:'note', body}` (guard `candidates.comment`).
4. History list; "Mở CV ({filename})" → signed URL 300 s.
- There is **no delete-candidate action** in the UI.

### 4.3 `jobs` — Vị trí & JD — all roles

Toolbar: search (title, department, location, hiring_manager), "+ Vị trí" (perm), Excel bar `jobs` (index.html:688-693).

Cards (index.html:695-710): title; "department · location · employment_type" or "Chưa có thông tin"; tags: "Đang tuyển"/"Đã đóng"; **"{hired}/{headcount ?? 1} đã tuyển"** where hired = candidates of this job with `stage='hired'` (any status); **"{n} ứng viên"** = candidates of this job with `status='active'`; "hạn {target_date}" (warn if past, else soon); "có JD" if `jd_summary || jd_requirements` else "thiếu JD".

**JOB_SPEC** (verbatim, index.html:671-685):

| key | label | type | options |
|---|---|---|---|
| title | Tên vị trí * | text | |
| department | Phòng ban | text | |
| headcount | Số lượng cần tuyển | number | |
| location | Nơi làm việc | text | |
| employment_type | Loại hình | select | `''`—, Full-time, Part-time, Thực tập, Thời vụ |
| hiring_manager | Quản lý tuyển dụng | text | |
| salary_range | Dải lương | text | |
| target_date | Hạn tuyển xong | date | |
| is_open | Trạng thái | select | `'true'` Đang tuyển, `'false'` Đã đóng |
| jd_summary | Mô tả chung | textarea wide rows 3 | |
| jd_responsibilities | Trách nhiệm chính (mỗi dòng 1 ý) | textarea wide rows 6 | |
| jd_requirements | Yêu cầu (mỗi dòng 1 ý) | textarea wide rows 6 | |
| jd_benefits | Quyền lợi (mỗi dòng 1 ý) | textarea wide rows 4 | |

Add: defaults `{is_open:'true', headcount:1}`, requires title (index.html:692-693).

**Job drawer** (index.html:720-742): form; "Xoá" (removeRow); "Sao chép JD" → `jdText(live)` to clipboard, fallback modal textarea; "In JD / PDF" → `printJD(live)`; "Lưu". Lists all candidates of the job with stage label and non-active status. "live" = current form values merged over the record (fix, see §6).

`jdText` format (index.html:712-719): `"{title}[ — {department}]\n"` + lines `Nơi làm việc: …`, `Loại hình: …`, `Mức lương: …`, `Số lượng: …` (only non-empty) + sections `\nMÔ TẢ`, `TRÁCH NHIỆM CHÍNH`, `YÊU CẦU`, `QUYỀN LỢI` each `"\n{TITLE}\n{text}\n"` when non-empty.

`printJD` (index.html:1591-1617): see §4.9 print standard; doc name "Mô tả công việc — {title}", title "Mô tả công việc", subtitle "title | department", meta "HeliDirect LLC. | Phiên bản v1 — {today vi-VN} | Tài liệu nội bộ"; section 00 "Thông tin vị trí" table (Chức danh, Phòng ban, Nơi làm việc, Loại hình, Số lượng cần tuyển (default 1), Dải lương); 01 "Mục tiêu vị trí" (jd_summary, newlines → `<br>`); 02 "Trách nhiệm chính", 03 "Yêu cầu", 04 "Quyền lợi" as bullet lists (each line, leading `-–—•`/spaces stripped, empty dropped); signature table "Người lập / Người phê duyệt / Người nhận việc".

### 4.4 `newhire` — Nhân sự mới (onboarding + probation) — all roles

Toolbar: search (full_name, title, department), "Mẫu checklist" (read-only template viewer), "+ Đánh giá" (perm `probation.create`), Excel bar `onboarding` (export only) (index.html:861-867).

Cards = employees with `status='probation'` (index.html:868-897). Per card:
- `obStat(emp)` (index.html:838-843): items = employee's onboarding items; `done` count; `late` = not done and `due_date` < today; `complete` = items>0 and all done.
- `probDue(emp)` (index.html:832-837): `end_date` of the employee's latest (by `start_date` desc) contract with `type='thu_viec'` and non-null `end_date`; otherwise `start_date + 60 days`; otherwise null.
- `lastReview(emp)` (index.html:844-847): review with max `review_date` (string compare).
- Progress bar (done/items %), tags: "✓ Onboarding xong" / "☐ Onboarding d/n" / "☐ Chưa có checklist"; "⚠ N quá hạn"; once complete: "⏱ Thử việc · hạn {probDue}" (warn if past) and last review "{total}/5 · {result label}" or "chưa đánh giá".
- Buttons: Checklist (onboarding drawer), Đánh giá (new review for this employee), Hồ sơ (employee drawer), **Xác nhận hoàn tất** only if `canConfirm` (complete ∧ last.result='pass' ∧ `perm('probation','approve')`).

Below: "Lịch sử đánh giá" table of **all** reviews (employee, date, reviewer, total/5, result label, confirmed tag with date or "chưa"); row click → edit review (index.html:898-909).

**Confirm probation** (index.html:848-860), status transition `employees.status: probation → active`:
1. guard `probation.approve`; 2. checklist complete else toast "Checklist onboarding chưa xong"; 3. a last review exists else "Chưa có đánh giá thử việc"; 4. `last.result === 'pass'` else "Đánh giá chưa đạt — chưa chuyển nhóm được"; 5. `confirm()`; 6. `probation_reviews.update({confirmed_by: auth.user.id, confirmed_at: now})` for that review (error ignored); 7. `upsert('employees', {status:'active'})` (needs `employees.edit`). Employee then leaves the New-hires view and appears only in People.

**Onboarding drawer** (index.html:911-953):
- With items: checklist sorted by `sort_order`; each shows title, owner_role, "hạn {due}", "quá hạn" if late. Checkbox → guard `onboarding.edit` → `update({done, done_at: done ? now : null})`; revert on error. "Xoá checklist" → guard `onboarding.delete` → confirm → delete all items of the employee.
- Without items: choose template (select of all templates), "Mốc ngày bắt đầu" (default employee `start_date` or today) → "Áp dụng mẫu": guard `onboarding.create`; rows = template items sorted by `sort_order` → `{employee_id, title, owner_role, due_date: addDays(base, day_offset), sort_order}`; empty template → "Mẫu này chưa có việc nào"; bulk insert.

**Template viewer** (index.html:954-962): per template, ordered list "title — owner_role (ngày ±offset)".

**Probation review form** (index.html:1016-1047) — spec (verbatim):

| key | label | type | options |
|---|---|---|---|
| employee_id | Nhân sự * | select | `['', '— chọn —']` + all employees `[id, full_name]` |
| review_date | Ngày đánh giá | date | default today |
| reviewer | Người đánh giá | text | |
| result | Kết luận | select | `['', '— chưa kết luận —']`, pass "Đạt — ký chính thức", extend "Gia hạn thử việc", fail "Không đạt" |
| s_chuyen_mon | Chuyên môn / chất lượng công việc (1-5) | number step 1 | |
| s_tien_do | Tiến độ & khối lượng hoàn thành (1-5) | number step 1 | |
| s_thai_do | Thái độ, tinh thần trách nhiệm (1-5) | number step 1 | |
| s_phoi_hop | Phối hợp & giao tiếp (1-5) | number step 1 | |
| s_tuan_thu | Tuân thủ nội quy, giờ giấc (1-5) | number step 1 | |
| strengths | Điểm mạnh | textarea wide rows 3 | |
| improvements | Cần cải thiện | textarea wide rows 3 | |
| note | Ghi chú khác | textarea wide rows 2 | |

Save: requires employee; `scores` = filled `s_*` values keyed by criterion; `total = round(mean×10)/10` or null; **no 1–5 range validation** in UI (DB unknown). Edit mode adds "Xoá đánh giá" (removeRow). `CRITERIA` keys/labels index.html:1008-1014.

### 4.5 `people` — Hồ sơ nhân sự (directory, contracts, HR records) — all roles

Toolbar: search (full_name, title, department, email, phone, employee_code), "+ Hợp đồng" (HR ∧ perm), "+ Nhân sự" (perm), Excel bar `employees` (index.html:763-771).

Table (index.html:773-782): Họ tên (+ employee_code), Chức danh, Phòng ban, Email, Điện thoại, Quản lý (manager name), Trạng thái tag (active ok / left warn / probation soon). Shows **all statuses** including probation and left.

**EMP_SPEC** (verbatim, index.html:745-760):

| key | label | type | options |
|---|---|---|---|
| full_name | Họ tên * | text | |
| employee_code | Mã nhân viên | text | |
| title | Chức danh | text | |
| level | Cấp bậc | select | `''`—, Director, Manager, Leader, Staff, Intern (+ stored odd value) |
| department | Phòng ban | text | |
| business_unit | Ngành hàng / BU | text | |
| email | Email công ty | text | |
| phone | Điện thoại | text | |
| manager_id | Quản lý trực tiếp | select | `['', '— không có —']` + employees `[id, "full_name (title)"]`; in the drawer the employee itself is removed |
| start_date | Ngày vào làm | date | |
| employment_type | Loại hình | select | `''`—, Full-time, Part-time, Thực tập, Thời vụ |
| status | Trạng thái | select | probation Thử việc, active Chính thức, left Đã nghỉ |
| location | Nơi làm việc | text | |
| note | Ghi chú | textarea wide rows 3 | |

Add: defaults `status='probation'`, `start_date=today`; requires full_name (index.html:770-771).

**Employee drawer** (index.html:788-809): form; "Xoá" (perm delete; cascades + purges `hr-docs/{id}/`); "Hồ sơ & giấy tờ" (HR); "Lưu". Read-only sections: contracts "• {type label} — {start} → {end | 'không thời hạn'} — {salary}đ"; onboarding progress bar "d/n việc xong" or "Chưa áp dụng mẫu onboarding"; reviews "• {date} — {total}/5 — {result label | 'chưa kết luận'}".

**Contracts section (HR only)** (index.html:977-997): "Hợp đồng lao động (N)"; amber panel "Sắp hết hạn trong 30 ngày" listing contracts with `status='active'` and `0 ≤ dayDiff(end_date) ≤ 30` ("… hết hạn {date} (còn N ngày)"); table: Nhân sự, Số HĐ, Loại, Hiệu lực, Hết hạn (tag warn if past, soon if ≤30 days — **regardless of status**; "không xác định" if null), Lương CB, Trạng thái. Row → contract drawer (form + Xoá + Lưu; index.html:998-1005).

**CON_SPEC** (verbatim, index.html:966-976):

| key | label | type | options |
|---|---|---|---|
| employee_id | Nhân sự * | select | `['', '— chọn —']` + employees |
| contract_no | Số hợp đồng | text | |
| type | Loại hợp đồng | select | thu_viec Thử việc, xd_12 Xác định 12 tháng, xd_24 Xác định 24 tháng, khong_xd Không xác định thời hạn, thoi_vu Thời vụ / dịch vụ |
| status | Trạng thái | select | draft Nháp, active Hiệu lực, expired Hết hạn, terminated Đã chấm dứt |
| signed_date | Ngày ký | date | |
| start_date | Hiệu lực từ | date | |
| end_date | Hết hạn (trống = không xác định) | date | |
| base_salary | Lương cơ bản (VND) | number | |
| note | Ghi chú | textarea wide rows 3 | |

Add contract defaults: `type='thu_viec'`, `status='active'`, `start_date=today`; requires employee (index.html:768-769). Contract status is never changed automatically by the app (no auto-expire); `expired` is manual (DB job unknown).

**HR records drawer "Hồ sơ & giấy tờ"** (index.html:2028-2094):
1. Parallel load `employee_private` (maybeSingle) and `employee_documents` (desc by uploaded_at). **If the private read errors, the form is not opened** (prevents overwriting with blanks).
2. Private form (PRIVATE_SPEC, prefix `pv`) → "Lưu hồ sơ" → guard `employees.edit` → `upsert({...fields, employee_id})`.
3. Documents: type select (DOC_TYPES), optional expiry date, "Chọn file & tải lên" → guard `employees.create` → size ≤15 MB → upload `hr-docs/{empId}/{Date.now()}-{sanitised}` → insert row. Each doc: type tag, file name, upload date, "· hạn {expires_on}", "Mở" (signed URL 300 s), "Xoá" (guard delete → confirm → delete row with `.select('id')` zero-row check → remove storage object; storage failure → toast).

**PRIVATE_SPEC** (verbatim, index.html:2011-2025):

| key | label | type | options |
|---|---|---|---|
| dob | Ngày sinh | date | |
| gender | Giới tính | select | `''`—, Nam, Nữ, Khác |
| id_number | Số CCCD/CMND | text | |
| id_issued_on | Ngày cấp | date | |
| id_issued_by | Nơi cấp | text | |
| tax_code | Mã số thuế cá nhân | text | |
| social_insurance_no | Số sổ BHXH | text | |
| dependents | Số người phụ thuộc | number | |
| bank_name | Ngân hàng | text | |
| bank_account | Số tài khoản | text | |
| permanent_address | Hộ khẩu thường trú | text wide | |
| current_address | Chỗ ở hiện tại | text wide | |
| emergency_contact | Liên hệ khẩn cấp | text wide | |

**DOC_TYPES** (index.html:2026-2027): cccd CCCD/CMND · hop_dong Hợp đồng đã ký · bang_cap Bằng cấp / chứng chỉ · so_yeu Sơ yếu lý lịch · kham_suc_khoe Giấy khám sức khoẻ · bhxh Giấy tờ BHXH · khac Khác.

### 4.6 `org` — Sơ đồ tổ chức — all roles

(index.html:812-829) Toolbar caption + Excel bar `employees`. Uses employees with `status != 'left'`. Roots = `manager_id` null; **orphans** = manager_id points to someone not in the active set → also rendered as roots. Recursive `<ul>` tree; node shows name and "title · level · department · business_unit". Cycle guard: a node seen twice renders "vòng lặp quản lý — kiểm tra lại" and stops. Employees inside a pure cycle with no root are not rendered at all; if nothing renders: "Chưa có nhân sự, hoặc mọi người đều có quản lý (vòng lặp)…".

### 4.7 `leave` — Nghỉ phép — all roles

Toolbar: search (employee name, reason), "Hạn mức phép" (HR), "+ Đơn nghỉ", Excel bar `leave` (export only) (index.html:1872-1877).

**Balance summary** (only if account linked to an employee) (index.html:1864-1871, 1878-1887) for the current calendar year and type `annual`:
- `total` = `leave_balances.days_total` for (myEmp, year, 'annual') else `leave_types[annual].default_days` else 0.
- `used` = Σ `days` of **own approved** requests of type `annual` whose `start_date` year = current year (a request spanning New Year counts wholly in its start year).
- Tiles: "Phép năm còn lại ({year})" = total − used; "Đã dùng" = used; "Đơn của tôi chờ duyệt" = own pending count.

**Approval queue** "Chờ bạn duyệt (N)" (index.html:1889-1896): columns Nhân sự, Loại, Từ, Đến, Số ngày, Lý do, Duyệt / Từ chối.

**decideLeave(id, status)** (index.html:1917-1930): guard `leave.approve`; for reject, `prompt('Lý do từ chối (không bắt buộc):')` — Cancel aborts; `update({status, decided_at: now, approver_name: state.me (email), decision_note})` **`.eq('status','pending')`** `.select('id')`; zero rows → "Đơn này đã được người khác xử lý" (optimistic concurrency).

**List** "Đơn nghỉ (N)": Nhân sự ("(chưa gán nhân sự)" if unknown), Loại (label), Từ, Đến, Ngày, Trạng thái tag (approved ok / rejected warn / else soon), Người duyệt (index.html:1897-1905).

**Leave form** (index.html:1931-1958) — spec (verbatim):

| key | label | type | options / condition |
|---|---|---|---|
| employee_id | Nhân sự * | select | **HR only**; `['', '— chọn —']` + employees with status ≠ left |
| type_key | Loại nghỉ | select | `leave_types [key, label]` |
| start_date | Từ ngày * | date | |
| end_date | Đến ngày * | date | |
| days | Số ngày (tự tính, sửa được) | number step 0.5 | |
| reason | Lý do | textarea wide rows 3 | |
| status | Trạng thái | select | **HR and editing only**; pending/approved/rejected/cancelled |

Defaults (new): `employee_id=myEmp`, `type_key='annual'`, start=end=today, `days=1`, `status='pending'`. On date change `days` recalculates with `workDays()` when end ≥ start. Save: non-HR → `employee_id=myEmp`, `status='pending'`; validations "Chưa gắn hồ sơ nhân sự cho tài khoản này", "Chọn ngày bắt đầu và kết thúc", "Ngày kết thúc phải sau ngày bắt đầu" (end < start); `days` falsy → `workDays(start,end)`. Note: HR changing `status` through this form does **not** set `decided_at/approver_name` and is guarded by `leave.edit`, not `leave.approve`.

**`workDays(a, b)`** (index.html:1845-1851): inclusive count of days from a to b whose `getDay()` ∉ {0 (Sun), 6 (Sat)}. No public holidays, no half days. Tests: Mon–Fri = 5, Mon–Sun = 5, Sat–Sun = 0 (selftest.html:752-758).

**Balance editor** (HR) (index.html:1959-1978): table of non-left employees with numeric input (step 0.5) = existing `days_total` or **hard-coded 12**; "Lưu" → guard `leave.edit` → upsert **one row per listed employee** `{employee_id, year: current, type_key:'annual', days_total}` (creates rows for everyone).

Status transitions: `pending → approved | rejected` (decideLeave); non-HR edit of own pending request re-saves as `pending`; HR may set any of the 4 statuses via form; `cancelled` only via HR form.

### 4.8 `mail` — Thư & Email (offers, compose, templates) — admin/hr/manager

Toolbar: search, "Mẫu thư", "+ Offer" (index.html:1724-1730).

Sections (index.html:1731-1755):
- **Offer letter (N)** filtered by candidate_name, position_title, candidate_email: Ứng viên (+email), Vị trí, Lương, Bắt đầu, Hạn phản hồi (warn tag if past **and** status `sent`), Trạng thái. Row → offer drawer.
- **Lịch sử email (N)** filtered by to_email, subject: Thời gian, Người nhận, Tiêu đề, Kênh, Trạng thái label (`draft` Nháp, `opened_in_gmail` Đã mở Gmail, `sent` Đã gửi, `failed` Lỗi). Row → read-only modal with body.

**OFFER_SPEC** (verbatim, index.html:1440-1458):

| key | label | type | options |
|---|---|---|---|
| candidate_name | Tên ứng viên * | text (req) | |
| candidate_email | Email ứng viên | text | |
| position_title | Vị trí * | text | |
| department | Phòng ban | text | |
| salary_gross | Lương chính thức (gross, VNĐ) | number | |
| allowance | Phụ cấp | text | |
| start_date | Ngày bắt đầu | date | |
| probation_months | Thử việc (tháng) | number step 0.5 | |
| probation_salary_pct | Lương thử việc (%) | number | |
| work_location | Nơi làm việc | text | |
| work_time | Thời gian làm việc | text | |
| manager_name | Quản lý trực tiếp | text | |
| signer_name | Người ký | text | |
| signer_title | Chức vụ người ký | text | |
| expires_on | Hạn phản hồi | date | |
| status | Trạng thái | select | draft Nháp, sent Đã gửi, accepted Ứng viên đồng ý, declined Ứng viên từ chối, expired Hết hạn |
| note | Ghi chú nội bộ | textarea wide rows 2 | |

`OFFER_STATUS` short labels (index.html:1459): draft Nháp, sent Đã gửi, accepted Đồng ý, declined Từ chối, expired Hết hạn.

**Offer form** (index.html:1461-1483): new-offer defaults (from candidate + its job when opened from a candidate): `candidate_id`, `job_id`, `candidate_name=full_name`, `candidate_email=email`, `position_title = job.title || cand.current_title`, `department = job.department`, `salary_gross=null`, `start_date=today+14`, `probation_months=2`, `probation_salary_pct=85`, `work_location = job.location || 'TP. Hồ Chí Minh'`, `work_time='Thứ 2 - Thứ 6, 8h30 - 17h30'`, `manager_name = job.hiring_manager`, `signer_name=''`, `signer_title='Giám đốc'`, `expires_on=today+7`, `status='draft'`. Save: guard; payload = form + original `candidate_id/job_id` (not editable); requires `candidate_name` and `position_title`; insert/update `.select().single()`; then open the offer drawer.

**Offer drawer** (index.html:1490-1518): tags (status, salary, start, expiry warn if past); amber panel "Còn thiếu thông tin: {missing vars}" (shown as "…" in letter); amber panel "Thư đang dùng giá trị mặc định: …" when `probation_months` or `probation_salary_pct` is null/empty (`offerDefaulted`, index.html:1435-1439); rendered letter preview (pre-wrap); buttons Xoá, Sửa, In / Lưu PDF, Gửi email; "Email đã soạn (N)" = email_log rows with this `offer_id`.

**Template rendering** (index.html:1408-1432):
- `TPL_VARS`: `cong_ty, ung_vien, vi_tri, phong_ban, phong_ban_clause, luong, phu_cap, ngay_bat_dau, thu_viec, luong_thu_viec, noi_lam_viec, thoi_gian_lam_viec, quan_ly, nguoi_ky, chuc_vu_nguoi_ky, han_phan_hoi, thoi_gian_phong_van, ket_qua_thu_viec`.
- Syntax `{{ name }}` (regex `/\{\{\s*([a-z_]+)\s*\}\}/gi`). `undefined`/`null`/`''` → replaced by `…` and reported in `missing` (deduplicated). Numbers (incl. 0) are rendered.
- `offerVars(o)`:

| var | value |
|---|---|
| cong_ty | `"HeliDirect"` (constant `COMPANY`) |
| ung_vien | candidate_name |
| vi_tri | position_title |
| phong_ban | department |
| phong_ban_clause | `", phòng {department}"` or `''` |
| luong | `money(salary_gross)` e.g. "20.000.000", `''` if null |
| phu_cap | allowance or "theo chính sách công ty" |
| ngay_bat_dau | `d(start_date)` dd/mm/yyyy or `''` |
| thu_viec | probation_months ?? 2 |
| luong_thu_viec | probation_salary_pct ?? 85 |
| noi_lam_viec | work_location |
| thoi_gian_lam_viec | work_time or "Thứ 2 - Thứ 6, 8h30 - 17h30" |
| quan_ly | manager_name |
| nguoi_ky | signer_name |
| chuc_vu_nguoi_ky | signer_title |
| han_phan_hoi | `d(expires_on)` or `''` |
| thoi_gian_phong_van | `''` (always missing) |
| ket_qua_thu_viec | `''` (always missing) |

- Non-offer compose vars (candidate/employee context, index.html:1631-1638): `cong_ty`, `ung_vien = full_name || candidate_name`, `vi_tri = job.title || current_title || title`, `phong_ban = job.department || department`, `phong_ban_clause`, `noi_lam_viec = job.location`, `quan_ly = job.hiring_manager`, `nguoi_ky = local-part of the user's email`, `chuc_vu_nguoi_ky=''`, `luong=''`, `ngay_bat_dau=''`, `thu_viec=2`, `luong_thu_viec=85`, `thoi_gian_lam_viec` default, `phu_cap=''`, `han_phan_hoi=''`, `thoi_gian_phong_van=''`, `ket_qua_thu_viec=''`.

**Compose email modal** (index.html:1628-1721):
1. Default template: `templateKey` param, else `interview_invite` if a candidate context, else `offer_letter`. Recipient: `offer.candidate_email || candidate.email || employee.email`.
2. Fields: template select (all templates; change re-renders subject/body, discarding edits), Gửi tới *, CC, Tiêu đề, Nội dung (textarea 14 rows), warning line: missing vars + offer defaults.
3. Validation (`validate`): guard `mail.create`; `to` must match `/^[^@\s]+@[^@\s.]+\.[^@\s]+$/` ("Email người nhận chưa hợp lệ"); subject required ("Chưa có tiêu đề"). CC is not validated.
4. "Mở trong Gmail" (always) and "Gửi ngay ({from})" (only if Resend configured) — see §2.3/§2.4.
5. Log row fields: `candidate_id = candidate.id || offer.candidate_id`, `employee_id`, `offer_id`, `template_key`, `to_email`, `cc_email || null`, `subject`, `body`, `channel`, `status`, `error`.
6. **Offer status transition:** after either channel succeeds, if `offer.status === 'draft'` → `status='sent'`, `sent_at=now`. All other transitions (accepted/declined/expired) are manual via the form. No automatic expiry in the UI (an `offer_expiring` reminder exists, §4.10).

Note: "employee" compose context is supported by the function but **never invoked** from any view.

**Template list** (index.html:1757-1773): panels per template (name, subject, "Sửa"); legend of variables; edit form spec: `name` Tên mẫu, `subject` Tiêu đề (wide), `body` Nội dung (textarea rows 16 wide) → `upsert('email_templates')` (guard `mail.edit`).

### 4.9 Print standard (offer letter & JD)

`HD_DOC_CSS` + `printDoc()` (index.html:1519-1577): opens a popup (blocked → toast), writes a Letter-size page: margins 21mm/24.6mm; Averta stack; body 10pt #444; header bar "HELIDIRECT LLC." + doc name; optional "Số / No.: {docNo}"; H1 22pt uppercase; subtitle 11pt bold; meta 9.5pt; footer fixed "Confidential — HeliDirect LLC."; non-print button "In / Lưu thành PDF"; auto `print()` after 500 ms. Source standard: `docs/HD-document-standard.md` (index.html:8).

`printLetter(o)` (index.html:1579-1589): doc name "Thư mời nhận việc / Job Offer — {position_title}", docNo **"JO……………………/HD-HRD"** (placeholder, no numbering scheme), title "Thư mời nhận việc  I  Job Offer", subtitle candidate_name, meta "HeliDirect LLC. | TP. Hồ Chí Minh, ngày {today} | Tài liệu nội bộ", body = rendered `offer_letter` template in `<pre>`.

### 4.10 Notifications (reminders)

Rendering only (index.html:1778, 1813-1820, 1837-1840); generation is server-side in `refresh_notifications` (§2.1). Kinds and their **inferred** triggers (only `contract_expiring` is proven by selftest):

| kind | Label | Inferred source condition | `ref_id` | Inferred `due_date` | Proof |
|---|---|---|---|---|---|
| contract_expiring | Hợp đồng | contract `status='active'`, `end_date` within N days (UI uses 30) | contract.id | end_date | selftest.html:829-850 (10 days ahead → 1 row; terminated → `done`) |
| probation_due | Thử việc | employee `status='probation'` with probation end (cf. `probDue`) approaching/past | employee.id? | probation end | [Suy luận] |
| onboarding_overdue | Onboarding | onboarding item not done and `due_date < today` | item.id or employee.id? | item due | [Suy luận] |
| offer_expiring | Offer | offer `status='sent'`, `expires_on` near/past | offer.id | expires_on | [Suy luận] |
| leave_pending | Nghỉ phép | leave request `status='pending'` | leave_request.id | start_date? | [Suy luận] |

### 4.11 `settings` — Phân quyền (users, roles, backups) — admin/hr

**Accounts table** (index.html:1982-2008): every profile: email ("bạn" tag for self), role select (all `roles`, label) → `profiles.update({role})`; employee select ("— chưa gắn —" + all employees) → `profiles.update({employee_id|null})`. Notes panel (3 bullets, index.html:1992-1995).

**Roles panel** (HR, index.html:2098-2147): table Vai trò (+ "hệ thống" tag), Mã, Nhóm quyền gốc, Đang dùng (count of profiles), Quyền bật (`allowed` count / 72), buttons "Sửa quyền", "Xoá" (non-system only).
- "+ Vai trò mới" form spec (verbatim):

| key | label | type | options |
|---|---|---|---|
| label | Tên vai trò * | text | |
| key | Mã (không dấu, không khoảng trắng) * | text | normalised `lower().replace(/[^a-z0-9_]/g,'')` |
| base | Nhóm quyền gốc * | select | ROLE_BASES (default staff) |
| sort | Thứ tự hiển thị | number | default 50 |

  Validation: label & key required; key unique. Insert role, then insert 72 `role_perms` rows `allowed=false` (error ignored).
- Delete: blocked if any profile uses it ("Còn tài khoản đang dùng vai trò này"); confirm; `roles.delete().eq('key')` (role_perms cleanup relies on DB FK — unknown).
- **Permission matrix drawer** (index.html:2149-2181): rows = 12 modules, columns = 6 actions, checkboxes; `admin` locked/disabled ("Quản trị luôn có toàn quyền, không chỉnh được"); buttons "Bỏ chọn hết", "Chọn hết", "Lưu quyền" → upsert all 72 rows `onConflict role_key,module,action`.

**Backup panel** (HR, index.html:2184-2224): loads latest 10 `backups(slot, taken_at, row_counts)`; race guard (skips insert if the view changed or panel already present). Text: daily at 02:10, keep 31. Buttons: "Chụp bản sao lưu ngay" (`rpc('make_backup')`, toast "Đã sao lưu {Σrow_counts} dòng ({size_kb} KB)", re-render) and "Tải bản mới nhất (.json)" (disabled when none) → `backups.select('*')` latest → download `hdrc-backup-{YYYY-MM-DD of taken_at in UTC}.json` (`JSON.stringify(row, null, 1)`). Table: Thời điểm, Tổng số dòng, Nhân sự, Ứng viên, Hợp đồng.

### 4.12 Song ngữ VI / EN (i18n)

(index.html:2226-2282)
- Source language is Vietnamese; English is applied by **DOM text replacement** using `I18N_EN` (593 entries; 562 keys ≥5 chars).
- `trText(s)`: exact match on trimmed text (or whitespace-normalised) → replace; else, only if the string contains Vietnamese diacritics, replace every dictionary key (≥5 chars, longest first) occurring as a substring.
- `applyLang(root)`: walks text nodes except under `SCRIPT/STYLE/TEXTAREA/INPUT` (so user-entered values are never altered); also translates `placeholder` and `title` attributes. A `MutationObserver` on the whole document translates every added node (incl. toasts).
- Toggle button shows target language ("EN"/"VI"); choice persisted in `localStorage['hdrc_lang']`; switching back to VI **reloads the page**. `<html lang>` updated.
- Consequence to preserve or consciously drop: displayed user data in table cells (e.g. a department called "Nhân sự") is also translated on screen in EN mode.
- Rebuild recommendation: replace with keyed i18n (vue-i18n / Laravel lang) and carry the 593 pairs as the seed EN catalogue.

---
## 5. Xuất / nhập Excel (Excel import/export)

Library: SheetJS 0.20.3, lazy-loaded; load failure resets the cache so the next click retries (index.html:1051-1061). Toolbar buttons per entity: "Tải mẫu", "Nhập" (hidden via `display:none` when not importable), "Xuất" (index.html:1393-1404).

| Entity key | Where the bar appears | Table | Importable | De-dup key (`keyOf`) | File stem |
|---|---|---|---|---|---|
| employees | People (766), Org (813) | employees | yes | `lower(trim(email))` | danh-ba-nhan-su |
| candidates | Pipeline (507) | candidates | yes | `lower(trim(email))` | ung-vien |
| jobs | Jobs (690) | jobs | yes | `lower(title) + ' \| ' + lower(department)` (only if title) | vi-tri-tuyen-dung |
| contracts | **no toolbar uses it** (defined only) | contracts | yes | none → always insert | hop-dong |
| onboarding | New hires (864) | — | no (export only) | — | onboarding |
| leave | Leave (1874) | — | no (export only) | — | don-nghi-phep |
| probation | **no toolbar uses it** (defined only) | — | no | — | danh-gia-thu-viec |

Rows with an empty key are always inserted. index.html:1063-1179

### 5.1 Column definitions (verbatim from `SHEETS`)

Flags: `req` required · `text` keep-as-text (leading zeros) · `num` number · `date` · `bool` (Có/Không) · `enum` exact list · `map` label→code · `lookup` name→id · `stage` key-or-label · `list` comma list · `def` insert default.

**employees** (index.html:1064-1085)

| Header | key | flags |
|---|---|---|
| Họ tên * | full_name | req |
| Mã nhân viên | employee_code | text |
| Chức danh | title | |
| Cấp bậc | level | (free text) |
| Phòng ban | department | |
| Ngành hàng / BU | business_unit | |
| Email | email | |
| Điện thoại | phone | text (+ VN phone repair) |
| Quản lý trực tiếp (họ tên) | manager_id | lookup employee (name, tie-break `employee_code`) |
| Ngày vào làm (yyyy-mm-dd) | start_date | date |
| Loại hình | employment_type | enum Full-time / Part-time / Thực tập / Thời vụ |
| Trạng thái | status | map Thử việc→probation, Chính thức→active, Đã nghỉ→left; def `probation` |
| Nơi làm việc | location | |
| Ghi chú | note | |

Sample row: `Nguyễn Văn A, NV001, Content Executive, Staff, Marketing, HEL, a.nguyen@helidirect.com, 0901234567, '', 2026-10-01, Full-time, Thử việc, HCM, ''`.

**candidates** (index.html:1086-1103)

| Header | key | flags |
|---|---|---|
| Họ tên * | full_name | req |
| Email | email | |
| Điện thoại | phone | text |
| Chức danh hiện tại | current_title | |
| Công ty hiện tại | current_company | |
| Số năm kinh nghiệm | years_experience | num |
| Vị trí ứng tuyển (tên) | job_id | lookup job (title, tie-break `department`) |
| Vòng | stage | stage (key or label, case-insensitive); def `new` |
| Nguồn | source | |
| Kỹ năng (phẩy) | skills | list |

Sample: `Trần Thị B, b.tran@gmail.com, 0912345678, Data Analyst, ACB, 2.8, Data Analyst, New, TopCV, "SQL, Excel, Power BI"`. (Status, cv fields are not in Excel.)

**jobs** (index.html:1104-1124)

| Header | key | flags |
|---|---|---|
| Tên vị trí * | title | req |
| Phòng ban | department | |
| Số lượng | headcount | num; def 1 |
| Nơi làm việc | location | |
| Loại hình | employment_type | enum |
| Quản lý tuyển dụng | hiring_manager | |
| Dải lương | salary_range | |
| Hạn tuyển xong (yyyy-mm-dd) | target_date | date |
| Đang tuyển (Có/Không) | is_open | bool; def true |
| Mô tả chung | jd_summary | |
| Trách nhiệm chính | jd_responsibilities | |
| Yêu cầu | jd_requirements | |
| Quyền lợi | jd_benefits | |

**contracts** (index.html:1125-1140)

| Header | key | flags |
|---|---|---|
| Nhân sự (họ tên) * | employee_id | req, lookup employee |
| Số hợp đồng | contract_no | |
| Loại hợp đồng | type | map Thử việc→thu_viec, Xác định 12 tháng→xd_12, Xác định 24 tháng→xd_24, Không xác định thời hạn→khong_xd, Thời vụ / dịch vụ→thoi_vu; def thu_viec |
| Trạng thái | status | map Nháp→draft, Hiệu lực→active, Hết hạn→expired, Đã chấm dứt→terminated; def active |
| Ngày ký (yyyy-mm-dd) | signed_date | date |
| Hiệu lực từ (yyyy-mm-dd) | start_date | date |
| Hết hạn (yyyy-mm-dd) | end_date | date |
| Lương cơ bản | base_salary | num |
| Ghi chú | note | |

**onboarding** (export only, index.html:1141-1151): Nhân sự (lookup employee) · Việc cần làm (title) · Phụ trách (owner_role) · Hạn (due_date, date) · Đã xong (Có/Không) (done, bool).

**leave** (export only, index.html:1152-1165): Nhân sự (lookup) · Loại nghỉ (type_key — raw key, not label) · Từ ngày · Đến ngày (date) · Số ngày (num) · Trạng thái (map Chờ duyệt/Đã duyệt/Từ chối/Đã huỷ) · Lý do · Người duyệt (approver_name).

**probation** (export only, index.html:1166-1178): Nhân sự (lookup) · Ngày đánh giá (date) · Người đánh giá · Điểm trung bình (total, num) · Kết luận (map Đạt — ký chính thức/Gia hạn thử việc/Không đạt) · Điểm mạnh · Cần cải thiện.

### 5.2 Export (index.html:1304-1315, 1224-1233)

- One sheet named `label.slice(0,28)`; headers = column header with `" *"` removed; file `{stem}-{today()}.xlsx`; column width = clamp(len+6, 14, 38).
- `cellOut`: lookup → name (`empName`/`jobName`); list → `join(', ')`; bool → "Có"/"Không"; stage → stage label (fallback key); map → reverse label (unknown → `''`); else raw value. Dates are written as the stored ISO string.
- Exports **whatever is in client state** (i.e. what RLS returned; email log limit 300, leave limit 500).

### 5.3 Template download (index.html:1316-1336)

Workbook `mau-{stem}.xlsx`: sheet `Nhap lieu` = headers + sample row; sheet `Huong dan` = instructions ("Cột có dấu * là bắt buộc.", "Ngày ghi dạng yyyy-mm-dd hoặc dd/mm/yyyy.", "Dòng mẫu … xoá đi trước khi nhập.", "Giá trị cho phép:") + per column allowed values (map labels, enum list, "Có / Không", stage labels, "phải trùng tên đã có trong app").

### 5.4 Import pipeline (index.html:1337-1392, 1181-1303)

1. Pick `.xlsx/.xls/.csv`; read with `cellDates:true`; first sheet only. Two AOAs: `raw:true` (typed values) and `raw:false` (displayed strings) (1348-1352).
2. **Header match** `normH`: lowercase, remove `(...)`, remove `*` and `:`, collapse spaces, trim — so "Ngày vào làm (yyyy-mm-dd)" ≡ "ngày vào làm". Column order is free; unknown columns ignored (1196, 1285-1287).
3. Missing **required** column → whole file rejected: single error at line 1 "thiếu cột bắt buộc: …" (1288-1290).
4. Fully blank rows skipped. Per row, per present column `cellIn` (1234-1266):
   - empty cell: required → error `thiếu "{header}"`; else **omitted** (does not overwrite existing value; DB/`def` default on insert).
   - lookup employee/job → `pickOne` (exact case-insensitive name; `"Name (tie)"` or `"Name [tie]"` filters by `employee_code` / `department`; 0 hits → `không tìm thấy nhân sự "…"`; >1 hits → `có N nhân sự trùng tên "…" — ghi rõ dạng "… (code)". Đang có: a / b`) (1267-1282).
   - stage → key or label case-insensitive, else `vòng "…" không hợp lệ`.
   - map → accepts the code itself or a label (case-insensitive), else `"{header}" chỉ nhận: label1 / label2 …`.
   - enum → case-insensitive, stores canonical spelling, else same message.
   - bool → `/^(có|co|x|yes|true|1|đang tuyển)$/i` → true; any other non-empty → **false**.
   - date → `xlDate`, invalid → `ngày "…" sai định dạng, cần yyyy-mm-dd`.
   - num → `xlNum`, invalid → `"{header}" phải là số, đang là "…"`.
   - list → split by comma, trim, drop empty.
   - text → `xlText` (below); default → `String(v)`.
   - A row with any error is skipped entirely and reported with its **sheet line number** (data row n → line n+2).
5. Preview modal: "{valid} dòng hợp lệ · {errors} dòng lỗi · {dup} dòng trùng theo {keyLabel} sẽ được cập nhật thay vì tạo mới"; first 50 errors; preview of first 5 rows × first 5 columns (1360-1369).
6. "Nhập N dòng": guard create **and** edit; button disabled; insert defaults = `def` values. For each row **sequentially**: key match → `update(row)` (only the columns present); else `insert({...defaults, ...row}).select('id')` and remember key → id (so duplicates **within the same file** become updates). On the first DB error: stop, toast "Dừng ở dòng "{full_name|title}": {err} — X dòng đã thêm, Y đã cập nhật. Xoá các dòng này khỏi file trước khi nhập lại.", reload (non-transactional) (1371-1391).

### 5.5 Value converters

**`xlDate(v)`** (1197-1211): null/'' → `null`; JS Date (realm-safe check via `Object.prototype.toString`) → local calendar date (`getTime − tzOffset`) as `YYYY-MM-DD`; invalid Date → error; number → Excel serial (epoch 1899-12-30 UTC); string `d[/-.]m[/-.]yyyy` → **day-first** `yyyy-mm-dd`; `yyyy-m-d` → zero-padded; anything else → error. Month/day ranges are not validated (e.g. 31/02/2026 passes to DB).

**`xlNum(v)`** (1212-1222): null/'' → null; finite number → itself; string without digits → error; strip `vnd/vnđ/đ` words and `₫ $`; any remaining letter → error ("15 triệu" rejected); remove spaces; remove `.` or `,` followed by exactly 3 digits at a word boundary (thousand separators); first remaining `,` → `.` (decimal). Examples: `15.000.000 → 15000000`, `2,8 → 2.8`, `abc → error` (selftest.html:468-475).

**`xlText(col, raw, shown)`** (1183-1195) — leading-zero handling:
1. If raw is all digits and the displayed string (stripped of spaces `.()-`) starts with `0` + digit → use the displayed string (keeps "001", "0901234567" from number-formatted cells).
2. For `phone` only: keep digits and `+`; `84` + 9 digits → `0` + 9 digits; `+84` + 9 digits → `0…`; 9 digits starting with 3/5/7/8/9 → prefix `0` (mobile that lost its zero); otherwise digits (or original if no digits). Landlines like `0281234567` unchanged.

---

## 6. Edge cases & known fixes (encoded in code comments / commits)

| # | Fix / rule | Where | Notes for rebuild |
|---|---|---|---|
| E1 | "Ngày theo múi giờ máy người dùng, không theo UTC: trước 07:00 giờ VN, UTC vẫn là 'hôm qua'" — `today/addDays/dayDiff` use local calendar dates | index.html:224-228 | Server must compute "today" in **Asia/Ho_Chi_Minh**; never `toISOString().slice(0,10)` |
| E2 | Select with a stored value outside the options keeps it as an extra selected option instead of silently writing the first option | index.html:250-256 | Same for Vue selects (level imported from Excel) |
| E3 | `readFields` warns and returns null for a missing input | index.html:269 | |
| E4 | Double-submit: Save disabled while saving (form modal, import, Resend send, backup) | index.html:281-283, 1373, 1710, 2206 | Also make create endpoints idempotent where possible |
| E5 | "DB vẫn là hàng rào cuối" — UI permission guard is advisory; DB enforces | index.html:286 | Laravel policies must be authoritative |
| E6 | Deleting a record does not delete its Storage files → purge folder `{bucket}/{id}/` after delete | index.html:310-321, 328 | Delete files in the same service action (after commit) |
| E7 | Delete with `.select('id')`; zero rows ⇒ "thiếu quyền hoặc bản ghi đã bị xoá" (RLS hides failures) | index.html:325-327, 2084-2086 | Return 403/404 explicitly |
| E8 | Drawer actions (Print JD, Copy JD, Compose, Create offer) use **live unsaved form values**, not the record loaded when the drawer opened ("nếu không sửa chưa lưu sẽ bị bỏ qua") | index.html:606-609, 733-736 | Pass current form state |
| E9 | Modal fields use prefix `m_`, drawer fields `f_` — fixes "modal form read stale drawer fields so every save became the last opened record" (commit `e5dba99`) | index.html:277, 283, 588 | Isolate component state |
| E10 | Offer letter silently defaulted probation 2 months / 85% → now an explicit warning panel and compose warning ("Phải nói ra, nếu không thư sẽ mâu thuẫn với dữ liệu đã lưu") | index.html:1433-1439, 1503-1504, 1664-1665 | |
| E11 | Excel number-formatted cells lose leading zeros (0901234567 → 901234567); prefer displayed string, repair VN mobile | index.html:1183-1195, 1351-1352 | |
| E12 | Date cells may come from another JS realm (iframe) → `toString` instead of `instanceof` | index.html:1199-1200 | n/a server-side |
| E13 | `"abc"` must not become 0; `"15 triệu"` must error, not guess | index.html:1216-1218 | |
| E14 | Empty Excel cell does not overwrite existing data; DB default applies on insert | index.html:1239, 1295 | |
| E15 | Name lookup: duplicates are an error, not "first match"; `Tên (phân biệt)` syntax | index.html:1267-1282 | |
| E16 | Import update writes only the columns present in the file; same-file duplicates become updates | index.html:1381, 1387 | |
| E17 | Job de-dup key = title + department (two departments may share a title) | index.html:1106 | |
| E18 | Org chart cycle guard ("vòng lặp quản lý — kiểm tra lại"); manager select excludes self | index.html:816-818, 790-791 | Ideally reject cycles server-side |
| E19 | Leave: non-HR editing a request resets it to `pending` ("sửa đơn là phải duyệt lại") | index.html:1946 | |
| E20 | Leave approval: Cancel on the reject prompt aborts; update conditioned on `status='pending'` to avoid double decisions | index.html:1921-1928 | Use `WHERE status='pending'` + affected-rows check |
| E21 | Private record: if the read fails, do not open the form ("chưa mở form để tránh ghi đè") | index.html:2034 | |
| E22 | Deleting an HR document also removes the stored file ("nếu không file rác nằm lại mãi") | index.html:2087-2091 | |
| E23 | Backup panel async insert guarded against tab switch / duplicate render | index.html:2186-2190 | |
| E24 | Test hooks placed at end of file (previous TDZ crash on `offerDefaulted`) | index.html:2296-2300; commit `0993664` | |
| E25 | Search input keeps focus/caret after re-render | index.html:459-470; commit `666a531` | |
| E26 | i18n never touches `INPUT/TEXTAREA` values ("i18n data corruption" fix, commit `b04648c`) | index.html:2251 | |
| E27 | Kanban drag: optimistic update with rollback on error | index.html:543-550 | |
| E28 | Onboarding checkbox reverted when guard fails or update errors | index.html:926-928 | |
| E29 | Login error mapping (domain not allowed / rate limit) | index.html:348-354 | |
| E30 | CV text truncated to 20 000 chars; PDF only first 8 pages | index.html:571, 661 | |

**Known quirks NOT fixed (decide consciously in the rebuild):**

1. `boot()` runs twice on load (auth listener + explicit call) — double `loadAll` (index.html:2287, 2302).
2. `loadAll` is all-or-nothing (index.html:382-383).
3. Kanban drag has no permission guard (index.html:548).
4. `confirmProbation` ignores the error of the `probation_reviews` update and needs `employees.edit` as well as `probation.approve` (index.html:858-859).
5. `offers` auto-`sent` update and `notifications` "Đã xử lý" ignore errors (index.html:1701, 1717, 1838).
6. Contract table expiry tags ignore `status` (index.html:987-988) while the warning panel uses `status='active'` (index.html:979).
7. Leave balance "used" attributes a request to the year of `start_date`; balance editor defaults to a hard-coded 12 not `leave_types.default_days` (index.html:1869, 1965).
8. `workDays` / year extraction use `new Date('YYYY-MM-DD')` (UTC) with local `getDay()`/`getFullYear()` — correct in UTC+7, off-by-one in negative-offset zones (index.html:1846-1848, 1869).
9. CV storage path uses the raw file name (no sanitisation) and `upsert:true` (index.html:575-576), unlike `hr-docs`.
10. Candidates whose stage key is missing from `stages` are invisible on the board (index.html:519, 524-527).
11. Backup download file name uses the UTC date of `taken_at` (index.html:2220).
12. Excel `bool` columns map any unrecognised text to `false` (index.html:1223, 1260).
13. EN mode translates displayed user data that happens to match dictionary keys (index.html:2235-2244).
14. `composeEmail({employee})` path exists but no view calls it (index.html:1628-1640).
15. The UI never sets candidate `status='withdrawn'` although the label exists (index.html:559, 604).
16. selftest "Loại hợp đồng sai bị chặn" actually tests an invalid contract **status**, not type (selftest.html:273-277).

---

## 7. Danh mục selftest (selftest catalogue)

Runner: logged-in user (must be admin/hr), real database, temporary rows prefixed `[SELFTEST]`, cleaned at the end; results stored in `selftest_runs`. Build stamp `20261014-0345` (selftest.html:44). Result states: pass / fail / warn (warn = known-acceptable). Total **119** test rows (A loop = 6, E tab loop = 9). Suggested Pest file per group.

### A. Bảo mật & phân quyền (RLS) — `tests/Feature/Security/AnonymousAccessTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| A1-A6 | Khách chưa đăng nhập không đọc được bảng {candidates, employees, contracts, probation_reviews, onboarding_items, jobs} | unauthenticated GET → 401 or empty | selftest.html:109-115 |
| A7 | Khách chưa đăng nhập không ghi được dữ liệu | unauthenticated POST employees → ≥400 | 116-120 |
| A8 | Edge function parse-cv bắt buộc đăng nhập | 401 | 121-125 |
| A9 | File CV không truy cập được nếu không có link ký | public URL → ≥400 | 126-130 |

### B. Tuyển dụng — `tests/Feature/Recruiting/CandidateLifecycleTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| B1 | Tạo vị trí tuyển dụng | insert job (headcount 2, is_open) | 135-138 |
| B2 | Tạo ứng viên gắn vào vị trí | insert candidate with skills array, years 3.5 → default stage `new` | 139-143 |
| B3 | Hệ thống tự ghi sự kiện "tạo hồ sơ" | `candidate_events` has `created` | 144-148 |
| B4 | Chuyển vòng ghi lại từ đâu → đến đâu | stage new→phone ⇒ exactly one `stage_change` from `new` to `phone` | 149-154 |
| B5 | Ngày đổi vòng tự cập nhật | `stage_changed_at > created_at` | 155-159 |
| B6 | Sửa thông tin khác KHÔNG làm đổi ngày vào vòng | phone update keeps `stage_changed_at` | 160-166 |
| B7 | Đổi trạng thái loại/rút được ghi nhận | status→rejected ⇒ one `status_change` with body `rejected` | 167-173 |
| B8 | Không nhập được vòng không tồn tại | FK error | 174-178 |
| B9 | Không nhập được trạng thái sai | CHECK error | 179-183 |
| B10 | Xoá vị trí không làm mất ứng viên | job delete ⇒ candidate kept, `job_id` null | 184-194 |
| B11 | Ghi chú lưu được và đọc lại đúng | note round-trip | 195-201 |

### C. Đọc CV tự động — `tests/Feature/Recruiting/ParseCvTest.php` (mock the LLM for determinism; keep the rules engine tests)

| # | Test | Checks | Ref |
|---|---|---|---|
| C1 | CV tiếng Anh | name, email, phone, years=5 | 205-215 |
| C2 | CV tiếng Việt | uppercase name kept, years 2.8, phone without spaces | 216-225 |
| C3 | File mô tả công việc KHÔNG bị nhận nhầm | `full_name` empty | 226-231 |
| C4 | Kỹ năng không bắt nhầm chuỗi con | no `r, go, git, java` | 232-238 |
| C5 | Nội dung rỗng/quá ngắn bị từ chối | `text:'abc'` → 400 | 239-243 |

### D. Nhân sự · hợp đồng · onboarding · thử việc — `tests/Feature/People/*`

| # | Test | Checks | Ref |
|---|---|---|---|
| D1 | Tạo nhân sự và gán quản lý trực tiếp | manager_id stored | 248-253 |
| D2 | Sửa nhân sự cập nhật dấu thời gian | `updated_at` increases | 254-260 |
| D3 | Trạng thái nhân sự sai bị chặn | status CHECK | 261-265 |
| D4 | Tạo hợp đồng và tính được số ngày còn hạn | end = today+20 ⇒ 0 < left ≤ 30 | 266-272 |
| D5 | Loại hợp đồng sai bị chặn | (actually invalid status) CHECK | 273-277 |
| D6 | Mẫu onboarding có sẵn và đủ bước | ≥1 template; first has ≥5 items | 278-284 |
| D7 | Áp mẫu onboarding tính đúng hạn theo ngày vào làm | due = base + day_offset of first item | 285-296 |
| D8 | Tick hoàn thành lưu được và ghi giờ | `done` + `done_at` | 297-302 |
| D9 | Đánh giá thử việc: điểm trung bình đúng | scores {4,5,4,4,5} ⇒ total 4.4; jsonb intact | 303-310 |
| D10 | Kết luận thử việc sai bị chặn | result CHECK | 311-315 |
| D11 | Xoá nhân sự dọn sạch hợp đồng, onboarding, đánh giá | cascade 3 tables | 316-326 |
| D12 | Quản lý còn lại không bị xoá lây | manager survives | 327-331 |
| D13 | Nhân sự tự làm quản lý của chính mình | self manager rejected (warn if DB allows) | 332-339 |

### E. Giao diện — `tests/Browser/*` (Dusk/Playwright) or Vitest component tests

| # | Test | Checks | Ref |
|---|---|---|---|
| E1 | App tải được và nhận phiên đăng nhập | app visible, nav ≥9 buttons within 30 s | 344-360 |
| E2 | Đủ 9 tab chức năng (tài khoản quản trị) | nav keys = dash, pipeline, jobs, newhire, people, org, leave, mail, settings | 362-372 |
| E3-E11 | Tab "{name}" hiển thị được, không lỗi (×9) | view HTML > 20 chars, no JS error | 373-387 |
| E12 | Vị trí vừa tạo hiện đúng trên tab Vị trí & JD | test job visible | 388-394 |
| E13 | In JD / Sao chép JD dùng nội dung đang sửa | live drawer value used | 395-414 |
| E14 | Ô chọn giữ nguyên giá trị lạ đang lưu | odd value "Senior" kept as first selected option | 415-428 |
| E15 | Offer để trống thử việc thì báo rõ là đang dùng mặc định | `offerDefaulted` 2 msgs for nulls, 0 when filled | 429-436 |
| E16 | Kanban hiện đủ 7 cột vòng tuyển dụng | 7 `.col` | 437-444 |

### F. Xuất / nhập Excel — `tests/Unit/Excel/*` + `tests/Feature/Excel/ImportTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| F1 | Cấu hình cột Excel hợp lệ cho mọi bảng | each sheet has cols, row source; importable ⇒ table + a required col | 449-458 |
| F2 | Đọc ngày kiểu Việt Nam và kiểu Excel | `01/11/2026`→2026-11-01; ISO; Date cell; garbage → error; '' → null | 459-467 |
| F3 | Đọc số có dấu chấm phân cách nghìn | 15.000.000; 2,8; number; 'abc' → error | 468-475 |
| F4 | File thiếu cột bắt buộc bị chặn ngay | "thiếu cột bắt buộc" | 476-481 |
| F5 | Dòng thiếu họ tên bị báo đúng số dòng | error at line 3; "Chính thức" → active | 482-491 |
| F6 | Giá trị ngoài danh sách cho phép bị báo lỗi kèm gợi ý | message lists "Thử việc…" | 492-497 |
| F7 | Tên quản lý không có trong app bị chặn | "không tìm thấy nhân sự" | 498-502 |
| F8 | Tên quản lý có thật được đổi đúng sang mã nội bộ | manager_id resolved | 503-510 |
| F9 | Vòng tuyển dụng nhận cả tiếng Việt lẫn mã | "Screening" → screening; skills split into 2 | 511-517 |
| F10 | Xuất ra Excel rồi nhập lại không sai lệch dữ liệu | round trip 9 fields + phone | 518-538 |
| F11 | Ô Excel định dạng SỐ không làm mất số 0 đầu | 901234567 fmt `0000000000` → "0901234567"; 1 fmt `000` → "001" | 539-551 |
| F12 | Số di động mất hẳn số 0 vẫn được vá lại | 901234567→0901234567; +84…→0…; landline kept | 552-558 |
| F13 | App cho bộ test nạp lại dữ liệu được | reload hook exists | 559-563 |
| F14 | Hai nhân sự trùng tên: báo lỗi thay vì chọn bừa | duplicate → error; "Name (code)" resolves | 564-579 |
| F15 | Vị trí trùng tên ở hai phòng ban không ghi đè nhau | key differs by department, case-insensitive | 580-588 |

### F2. Ngày tháng (múi giờ) — `tests/Unit/DatesTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| F2-1 | today() lấy ngày theo máy người dùng, không theo UTC | equals local date | 592-598 |
| F2-2 | dayDiff và addDays tính đúng quanh mốc tháng/năm | today=0, ±N, 2026-02-28+1=03-01, 2026-12-31+1=2027-01-01 | 599-607 |

### G. Offer letter & gửi email — `tests/Feature/Mail/*`

| # | Test | Checks | Ref |
|---|---|---|---|
| G1 | Có đủ mẫu thư dựng sẵn | keys offer_letter, interview_invite, rejection; offer body has `{{luong}}`,`{{ngay_bat_dau}}` | 612-619 |
| G2 | Điền biến vào mẫu thư đúng và báo biến còn thiếu | "…" + missing list | 620-626 |
| G3 | Tạo offer từ ứng viên, mặc định là Nháp | status draft, 2 months, 85% | 627-635 |
| G4 | Thư offer sinh ra đủ nội dung | no `{{`, name, "20.000.000", position, no missing | 636-646 |
| G5 | Trạng thái offer sai bị chặn | CHECK | 647-651 |
| G6 | Ghi lịch sử email và đọc lại được | email_log round trip by offer_id | 652-661 |
| G7 | Kênh gửi lạ bị chặn | channel CHECK | 662-666 |
| G8 | Chức năng gửi email bắt buộc đăng nhập | 401 | 667-671 |
| G9 | Gửi tự động (Resend) báo đúng trạng thái cấu hình | probe → `configured` boolean (warn if false) | 672-678 |
| G10 | Email người nhận sai định dạng bị từ chối | 400 + error | 679-686 |
| G11 | Thiếu tiêu đề hoặc nội dung quá ngắn bị từ chối | 400 / 400 | 687-695 |
| G12 | Xoá ứng viên không làm mất offer đã phát hành | offer kept, candidate_id null | 696-705 |

### H. Phân quyền theo vai trò — `tests/Feature/Auth/RoleFunctionsTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| H1 | Tài khoản đang dùng có hồ sơ phân quyền | profile exists, role admin/hr | 709-714 |
| H2 | Hàm kiểm tra vai trò hoạt động | my_role non-null, is_hr true | 715-719 |
| H3 | Dữ liệu nhạy cảm chỉ mở cho HR | policy fragments per table (P8) | 720-733 |
| H4 | Mọi bảng đều đã bật RLS | policies on 6 tables (P9) | 734-740 |

### I. Nghỉ phép — `tests/Feature/Leave/*`

| # | Test | Checks | Ref |
|---|---|---|---|
| I1 | Có sẵn các loại nghỉ theo luật lao động | annual, sick, unpaid, maternity; annual = 12 | 744-751 |
| I2 | Tính số ngày nghỉ bỏ qua thứ 7 và chủ nhật | 5 / 5 / 0 | 752-758 |
| I3 | Tạo đơn nghỉ, mặc định chờ duyệt | default `pending` | 759-764 |
| I4 | Ngày kết thúc trước ngày bắt đầu bị chặn | CHECK | 765-769 |
| I5 | Loại nghỉ không có trong danh mục bị chặn | FK | 770-774 |
| I6 | Duyệt đơn ghi lại người duyệt và thời điểm | approver_name + decided_at | 775-780 |
| I7 | Hạn mức phép lưu và cộng trừ đúng | upsert balance 14 → read 14 | 781-788 |

### J. Hồ sơ & giấy tờ nhân sự — `tests/Feature/People/PrivateRecordsTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| J1 | Lưu thông tin cá nhân nhạy cảm | upsert private; tax_code string, dependents int | 791-797 |
| J2 | Gắn giấy tờ vào hồ sơ nhân sự | insert document row | 798-804 |
| J3 | Kho file hồ sơ không mở công khai | `hr-docs` not public | 805-809 |
| J4 | Xoá nhân sự dọn sạch hồ sơ, giấy tờ và đơn nghỉ | cascade private, documents, leave_requests, leave_balances | 810-820 |

### K. Nhắc việc tự động — `tests/Feature/Notifications/RefreshTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| K1 | Quét nhắc việc chạy được | returns integer | 823-828 |
| K2 | Hợp đồng sắp hết hạn tự sinh nhắc việc | 1 row for contract ending in 10 days; title has name; idempotent; terminated ⇒ done | 829-850 |
| K3 | Lịch quét hằng ngày đã bật | cron `hdrc-daily-reminders` active (warn if unreadable) | 851-856 |

### L2. Vai trò & ma trận quyền — `tests/Feature/Auth/PermissionMatrixTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| L2-1 | Bảng vai trò có đủ 4 vai trò hệ thống | is_system | 860-866 |
| L2-2 | Ma trận quyền phủ đủ nhóm chức năng × thao tác | 72 rows each; admin all allowed | 867-876 |
| L2-3 | Nhân viên không có quyền xem lương / hợp đồng | staff contracts.view false | 877-883 |
| L2-4 | Khách chưa đăng nhập không gọi được has_perm | ≥400 | 884-888 |
| L2-5 | my_role() quy về nhóm quyền gốc | base role | 889-894 |
| L2-6 | Xác nhận thử việc có cột lưu người xác nhận | `confirmed_by`, `confirmed_at` exist | 895-899 |

### L3. Quyền cưỡng chế ở database — `tests/Feature/Auth/EnforcementTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| L3-1 | Đủ trigger kiểm soát quyền trên mọi bảng nghiệp vụ | 22 tables (P14) | 903-913 |
| L3-2 | has_perm khớp với ma trận quyền | first 6 matrix cells | 914-929 |
| L3-3 | Duyệt nghỉ phép bị chặn nếu vai trò không có quyền duyệt | has_perm(leave, approve) boolean | 930-936 |
| L3-4 | Chính sách ĐỌC … gọi has_perm | 7 read policies (P17) | 937-948 |
| L3-5 | Siết RLS không làm ai mất quyền xem so với trước | P18 | 949-961 |
| L3-6 | Tài khoản thường không đọc được bảng quyền qua anon | anon role_perms → 401/empty | 962-967 |

### L. Sao lưu dữ liệu — `tests/Feature/Backup/BackupTest.php`

| # | Test | Checks | Ref |
|---|---|---|---|
| L1 | Khách chưa đăng nhập không đọc được bản sao lưu | anon → 401/empty | 971-976 |
| L2 | Khách chưa đăng nhập không gọi được hàm sao lưu | anon RPC ≥400 | 977-981 |
| L3 | Chụp bản sao lưu ngay chạy được | size_kb > 0, row_counts has employees & candidates | 982-988 |
| L4 | Bản sao lưu chứa đủ dữ liệu để phục hồi | data has arrays for employees, candidates, jobs, stages, contracts, leave_requests, profiles | 989-995 |
| L5 | Lịch sao lưu hằng ngày đã bật | cron `hdrc-daily-backup` active | 996-1001 |

### M. Dọn dẹp — (Pest uses `RefreshDatabase`; keep only as a smoke script)

| # | Test | Checks | Ref |
|---|---|---|---|
| M1 | Xoá sạch mọi bản ghi [SELFTEST] | deletes tracked ids + email_log by subject; asserts none left in 5 tables | 1005-1020 |

**Tests that should be added in the rebuild** (gaps in the current suite): per-role negative tests (staff cannot read contracts/offers/private; manager sees only own team's leave; staff cannot approve leave); the 4 inferred notification kinds; leave approval concurrency (E20); import update vs insert and same-file duplicates (E16); file purge on delete (E6/E22); probation confirm guard chain (§4.4).

---

## 8. Unknown / needs DB dump

Items below are enforced or defined only in Supabase (Postgres schema, RLS, triggers, functions, cron, storage policies, edge-function source) and **cannot be derived from the two HTML files**. Request `pg_dump --schema-only` (public + storage policies), the seed data of lookup tables, `cron.job`, and the source of edge functions `parse-cv` and `send-email`.

1. **Remaining 3 pipeline stages** (keys, labels, order); only `new`, `phone`, `screening`, `hired` are known (§1.1).
2. **Default `role_perms` matrix** for `hr`, `manager`, `staff` (only: admin all; staff contracts.view=false; all roles employees.view & jobs.view; admin/hr/manager candidates.view).
3. **Exact RLS predicates** per table and per base role — e.g. what `manager` and `staff` can read on employees, onboarding_items, probation_reviews, leave_requests, profiles, notifications; whether staff can see their own contract (Settings note says yes, matrix says contracts.view=false).
4. **Write-enforcement triggers**: which action (`create/edit/delete/approve/comment`) each trigger checks per table, and how `candidate_events` notes vs. system events are distinguished; whether `leave_requests` status changes require `leave.approve` at DB level.
5. **`refresh_notifications` logic** for `probation_due`, `onboarding_overdue`, `offer_expiring`, `leave_pending`: thresholds (days ahead), `ref_id` target, `title/body` wording, `due_date` choice, auto-close rules; unique key; return value semantics (selftest only says "number of open").
6. **`make_backup` contents**: full table list, `slot` rotation formula, where `size_kb` comes from, whether `auth.users`/storage objects are included; restore procedure (none in UI).
7. **Profile provisioning**: trigger creating `profiles` on signup, the `@helidirect.com` restriction, default role `staff`, and whether email is synced.
8. **Cascade/null rules** not covered by tests: `employees.manager_id` when the manager is deleted, `profiles.employee_id`, `email_log.*_id`, `offers.job_id`, `candidate_events.candidate_id`, `role_perms.role_key` on role delete, `onboarding_template_items.template_id`.
9. **Whether `employees.manager_id = id` is rejected** (selftest D13 only warns).
10. **CHECK constraints** for `candidates.status` (is `withdrawn` allowed?), `email_log.status`, `notifications.status/kind`, `roles.base`, `employee_documents.doc_type`, `employee_private.gender`, `probation_reviews.scores` value range (1-5?), `leave_requests.days > 0`?, `leave_balances` PK.
11. **Column defaults/nullability** not visible: `jobs.is_open`, `jobs.headcount`, `candidates.status`, timestamps (`created_at/updated_at`) on every table, `uploaded_at`.
12. **`parse-cv` internals**: AI provider/model, prompt, rules engine, `build` format, full candidate field list returned, `not_a_cv` criteria, minimum text length for 400, max text length.
13. **`send-email` internals**: sender address (`from`), reply-to, minimum body length, CC handling, HTML vs text, rate limits.
14. **Storage policies** for `cvs` and `hr-docs`: who can upload/read/delete which prefix (HR only? managers for CVs?), max object size at bucket level, allowed MIME types.
15. **Stage-change trigger details**: does `stage_changed_at` also change on insert; who is recorded as actor.
16. **`selftest_runs`** schema beyond the inserted fields, and its RLS.
17. **`email_templates` seed bodies** (exact Vietnamese text of `offer_letter`, `interview_invite`, `rejection` and any others) and `onboarding_templates` seed items (titles, owner_role values, offsets).
18. **`leave_types` full list** (beyond annual/sick/unpaid/maternity), labels, default days, sort order.
19. Whether `contracts.status` is ever auto-set to `expired` by a job (UI never does it).
20. Whether `notifications` are scoped per user/role (the table has no visible owner column; read policy uses `has_perm('reports','view')`).

---

## 9. Ghi chú cho bản dựng lại (rebuild notes)

- **Table name clash:** Laravel's default `jobs` queue table vs HR `jobs` (see §1). Rename one.
- **Auth model change:** current = Supabase magic link; the new architecture (`docs/specs/2026-10-20-hr-revamp-design.md`) moves to email/password + Fortify session auth. `profiles.id` currently equals the Supabase auth uuid; `probation_reviews.confirmed_by` stores that uuid — map to the new `users.id` during migration.
- **Authorisation layering to reproduce:** (1) base role → tabs + data scope; (2) `role_perms` matrix → actions; base `admin` bypasses the matrix. Implement `has_perm` as a Gate (`Gate::before` for base admin) and data scope as query scopes per base role.
- **Text-typed references to keep:** `jobs.hiring_manager`, `offers.manager_name/signer_name`, `probation_reviews.reviewer`, `leave_requests.approver_name` (email) are free text today.
- **Server time zone:** all "today" computations (contract expiry, overdue, probation due, reminders, Excel dates) must use `Asia/Ho_Chi_Minh` (E1).
- **Vietnamese collation:** Excel name lookups use JS `toLowerCase()` exact match; MySQL `utf8mb4_unicode_ci` also ignores accents (`Nguyen` = `Nguyễn`) — use a binary/`_bin` or `utf8mb4_0900_as_ci` comparison if accent-sensitive matching must be preserved.
- **Arrays/JSON in MySQL:** `candidates.skills` (text[]) → JSON column; `probation_reviews.scores`, `backups.row_counts/data`, `selftest_runs.results` → JSON.
