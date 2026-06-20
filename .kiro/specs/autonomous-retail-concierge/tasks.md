# Implementation Plan: Autonomous Retail Concierge

## Overview

Build the three-layer autonomous shopping assistant on top of the existing Nexus-Commerce platform:
1. **PHP REST API** — `AiAgentController` with six `/api/ai/*` endpoints secured by `X-Api-Key`
2. **Node.js AI Microservice** — Express + Vercel AI SDK + Groq, exposing `POST /api/chat` with four tools and streaming
3. **Chatbot Widget** — Self-contained IIFE JavaScript included via `<script>` tag, with streaming, cart-badge sync, and link rendering

Implementation follows the suggested phases: PHP foundation → PHP endpoints → PHP tests → Node.js setup → AI tools → Node.js tests → Frontend widget → Frontend tests.

---

## Tasks

- [ ] 1. PHP API Foundation — environment, controller scaffold, and route registrations
  - [ ] 1.1 Add `AI_AGENT_ORIGIN` and `AI_API_SECRET` to `commerce-core/.env` and `commerce-core/.env.example`
    - Append two new variables to both files following the existing key=value format
    - Use placeholder values in `.env.example` (e.g. `AI_AGENT_ORIGIN=http://localhost:3001`, `AI_API_SECRET=change-me`)
    - _Requirements: 7.1, 7.2, 7.5_

  - [ ] 1.2 Create `commerce-core/app/controllers/client/AiAgentController.php` with the CORS/auth guard and JSON response helper
    - Implement private `handleCorsAndAuth()`: reads `AI_AGENT_ORIGIN` and `AI_API_SECRET` via `EnvSetup`, emits CORS headers, short-circuits `OPTIONS` with `204`, rejects missing/wrong `X-Api-Key` with `401 {"message":"..."}`
    - Implement private `jsonResponse(int $status, mixed $data): never` that sets `Content-Type: application/json; charset=utf-8`, calls `http_response_code`, echoes `json_encode($data, JSON_UNESCAPED_UNICODE)`, then `exit`
    - Add six public method stubs: `products()`, `variants(int $id)`, `cartAdd()`, `promotionsOptimal()`, `checkoutApplyCoupon()`, `orderStatus(string $code)` — each calls `$this->handleCorsAndAuth()` then returns an empty `200` for now
    - Follow the no-namespace pattern used by the existing `ApiController.php`
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6_

  - [ ] 1.3 Register all six routes (plus preflight block) in `commerce-core/app/routes/client/client.php`
    - Add an early-exit preflight block: `if (str_starts_with($path, 'api/ai/') && $_SERVER['REQUEST_METHOD'] === 'OPTIONS')` → load `AiAgentController`, call `handleCorsAndAuth()`, exit
    - Register `GET api/ai/products` (exact match)
    - Register `GET api/ai/products/{id}/variants` via `preg_match('#^api/ai/products/(\d+)/variants$#')`
    - Register `POST api/ai/cart/add` (exact match)
    - Register `GET api/ai/promotions/optimal` (exact match)
    - Register `POST api/ai/checkout/apply-coupon` (exact match)
    - Register `GET api/ai/orders/{order_code}` via `preg_match('#^api/ai/orders/([A-Z0-9\-]+)$#i')`
    - Follow the existing `if/elseif` + `require_once` + `new AiAgentController()` pattern
    - _Requirements: 1.1, 2.1, 3.1, 4.1, 5.1, 6.1, 7.1–7.4_


- [ ] 2. PHP API Endpoints — implement the six business-logic methods
  - [ ] 2.1 Implement `GET /api/ai/products` in `AiAgentController::products()`
    - Validate query params: `gia_min`, `gia_max` — `ctype_digit` or empty; `danh_muc_id` — positive int; `limit` — int 1–50, default 20; `hang` — string; `q` — string
    - Return `400 {"message":"..."}` for any invalid param
    - Build SQL from `san_pham` joined with `danh_muc`, subqueries for `gia_thap_nhat`/`gia_cao_nhat` (MIN/MAX of `phien_ban_san_pham.gia_ban`), `tong_ton_kho` (SUM of active variant stock), `anh_dai_dien` (from `hinh_anh_san_pham WHERE la_anh_chinh = 1`)
    - Enforce `san_pham.trang_thai = 'CON_BAN'` and `EXISTS` subquery for variant with `trang_thai != 'NGUNG_BAN'`
    - Apply `danh_muc_id` via `id IN (SELECT id FROM danh_muc WHERE id = ? OR danh_muc_cha_id = ?)` sub-select
    - Apply `hang` as case-insensitive `LOWER(hang_san_xuat) = LOWER(?)`
    - Price filter applies to `gia_thap_nhat`, not `gia_hien_thi`
    - Default order: `diem_danh_gia DESC`
    - Return `200` with JSON array (empty array when no match)
    - _Requirements: 1.1–1.10_

  - [ ]* 2.2 Write property tests for `GET /api/ai/products` (Properties 1–6)
    - **Property 1: Product search response completeness** — generate arbitrary valid filter combos, assert all nine fields present in every item
    - **Property 2: Product search filter correctness** — for any `q`, assert every returned product satisfies the keyword match
    - **Property 3: Price range filter correctness** — for any `gia_min`/`gia_max`, assert `gia_thap_nhat` within range
    - **Property 4: Active-only product invariant** — assert all returned products have `trang_thai = 'CON_BAN'`
    - **Property 5: Result count bound** — for any `limit` 1–50, assert response length ≤ limit
    - **Property 6: Invalid parameter rejection** — generate non-integer strings for numeric params, assert `400` with `message` field
    - Use eris/eris or equivalent PHP PBT library; tag each: `// Feature: autonomous-retail-concierge, Property {N}: {text}`
    - _Requirements: 1.1–1.10_

  - [ ] 2.3 Implement `GET /api/ai/products/{id}/variants` in `AiAgentController::variants(int $id)`
    - Validate parent product exists with `trang_thai = 'CON_BAN'`; return `404 {"message":"..."}` if not
    - Use `PhienBanSanPham::layPhienBanTheoSanPham($id)` then filter to `trang_thai IN ('CON_HANG','CON_BAN')`
    - Decode `thuoc_tinh_bien_the` from JSON string to object in the response
    - Return `200` with JSON array (empty array when no available variants)
    - _Requirements: 2.1–2.4_

  - [ ]* 2.4 Write property test for `GET /api/ai/products/{id}/variants` (Property 7)
    - **Property 7: Variant response completeness and status filter** — for any active product ID, assert all nine variant fields are present and `trang_thai` is `'CON_HANG'` or `'CON_BAN'`
    - **Validates: Requirements 2.1, 2.2**
    - _Requirements: 2.1, 2.2_


  - [ ] 2.5 Implement `POST /api/ai/cart/add` in `AiAgentController::cartAdd()`
    - Read JSON body with `json_decode(file_get_contents('php://input'), true)`
    - Validate `phien_ban_id` (positive int) and `so_luong` (positive int); return `400` if missing
    - Validate at least one of `session_id` or `nguoi_dung_id` is present; return `400` if both absent
    - Load variant via `PhienBanSanPham::layPhienBanTheoId`; return `404` if not found or `trang_thai = 'NGUNG_BAN'`
    - Check stock via `PhienBanSanPham::kiemTraTonKho`; return `422 {"message":"..."}` if insufficient
    - Resolve cart: if `nguoi_dung_id` → `GioHang::layHoacTaoGioHangUser`; else → `GioHang::layHoacTaoGioHangGuest`
    - Add/increment via `ChiTietGio::themVaoGio`
    - Return `200 {"cart_item_count": ChiTietGio::demSanPham(...), "tong_tien": ChiTietGio::tinhTongTien(...)}`
    - _Requirements: 3.1–3.7_

  - [ ]* 2.6 Write property tests for `POST /api/ai/cart/add` (Properties 8–10)
    - **Property 8: Cart add round-trip** — for any valid phien_ban_id/so_luong, assert response includes `cart_item_count` and `tong_tien`
    - **Property 9: Cart duplicate prevention (quantity accumulation)** — add same variant twice with q1 and q2, assert cart has exactly one entry with quantity q1+q2
    - **Property 10: Insufficient stock rejection** — for so_luong > so_luong_ton, assert response status is `422`
    - **Validates: Requirements 3.1, 3.2, 3.3, 3.5**
    - _Requirements: 3.1–3.7_

  - [ ] 2.7 Implement `GET /api/ai/promotions/optimal` in `AiAgentController::promotionsOptimal()`
    - Validate `tong_tien` param: must be present and non-negative integer; return `400` if not
    - Query `ma_giam_gia` where `trang_thai = 'HOAT_DONG'` AND `don_toi_thieu <= tong_tien` AND date window valid AND usage limit not reached
    - Apply `MaGiamGia::tinhSoTienGiam` to each candidate; select the one with the highest `tien_giam`
    - Return `200 {"coupon": {ma_code, loai_giam, gia_tri_giam, giam_toi_da, don_toi_thieu, tien_giam, ngay_ket_thuc}, "message": null}` on success
    - Return `200 {"coupon": null, "message": "Không có mã giảm giá phù hợp với đơn hàng của bạn."}` when no coupon qualifies
    - _Requirements: 4.1–4.4_

  - [ ]* 2.8 Write property test for `GET /api/ai/promotions/optimal` (Property 11)
    - **Property 11: Optimal coupon selection** — for any tong_tien and any set of eligible coupons, the returned coupon's tien_giam ≥ tien_giam of all other eligible coupons
    - **Validates: Requirements 4.1, 4.2**
    - _Requirements: 4.1, 4.2_


  - [ ] 2.9 Implement `POST /api/ai/checkout/apply-coupon` in `AiAgentController::checkoutApplyCoupon()`
    - Read JSON body; validate `ma_code` (string) and `tong_tien` (non-negative number) are present; return `400` if absent
    - Call `MaGiamGia::timTheoMaCode($ma_code)`; return `404 {"message":"Mã giảm giá không tồn tại"}` if null
    - Call `MaGiamGia::kiemTraHopLe($voucher, $tong_tien)`; on failure call `MaGiamGia::layThongBaoLoiMaGiamGia` for the Vietnamese message and return `422`
    - Calculate `tien_giam` via `MaGiamGia::tinhSoTienGiam`
    - Return `200 {"ma_giam_gia_id": ..., "ma_code": ..., "tien_giam": ..., "tong_thanh_toan": tong_tien - tien_giam}`
    - _Requirements: 5.1–5.5_

  - [ ]* 2.10 Write property test for `POST /api/ai/checkout/apply-coupon` (Property 12)
    - **Property 12: Apply coupon calculation correctness** — for any valid ma_code and tong_tien, assert tien_giam equals MaGiamGia::tinhSoTienGiam result and tong_thanh_toan = tong_tien - tien_giam
    - **Validates: Requirements 5.2**
    - _Requirements: 5.2_

  - [ ] 2.11 Implement `GET /api/ai/orders/{order_code}` in `AiAgentController::orderStatus(string $code)`
    - Load `DonHang` by `ma_don_hang = $code`; return `404 {"message":"..."}` if not found
    - If `nguoi_dung_id` query param is supplied, verify `don_hang.nguoi_dung_id` matches; return `403 {"message":"..."}` on mismatch
    - Load line items via `DonHang::laySanPhamTrongDon`
    - Return `200` with all required order fields and `san_pham` array containing `ten_san_pham`, `ten_phien_ban`, `so_luong`, `gia_tai_thoi_diem_mua`
    - _Requirements: 6.1–6.4_

  - [ ]* 2.12 Write property tests for `GET /api/ai/orders/{order_code}` (Properties 13–14)
    - **Property 13: Order ownership access control** — for any order and any nguoi_dung_id ≠ owner, assert response is `403`
    - **Property 14: Order response completeness** — for any existing order code, assert all required fields are present including san_pham array items
    - **Validates: Requirements 6.1, 6.2**
    - _Requirements: 6.1, 6.2_

- [ ] 3. PHP API Tests — security guard and integration example tests
  - [ ] 3.1 Write PHPUnit example and integration tests for PHP API (Properties 15 + Req integration scenarios)
    - **Property 15: API key authentication invariant** — for any `/api/ai/*` endpoint, missing or wrong `X-Api-Key` must return `401`
    - **Validates: Requirements 7.5**
    - Example: default product ordering returns ≤ 20 results ordered by `diem_danh_gia DESC` — Req 1.8
    - Example: guest cart resolved when only `session_id` supplied; user cart resolved when `nguoi_dung_id` supplied — Req 3.7
    - Example: no eligible coupon → `{ coupon: null, message: "..." }` — Req 4.3
    - Example: apply coupon `404` (code not found) — Req 5.3
    - Example: apply coupon `422` for expired / usage-exhausted / below minimum — Req 5.4
    - Example: guest order lookup without `nguoi_dung_id` succeeds — Req 6.4
    - Example: CORS headers present on all responses; preflight returns `204` — Req 7.1–7.4
    - _Requirements: 1.8, 3.7, 4.3, 5.3, 5.4, 6.4, 7.1–7.5_

- [ ] 4. Checkpoint — PHP layer complete
  - Ensure all PHPUnit tests pass, ask the user if questions arise.


- [ ] 5. Node.js AI Microservice — project initialization
  - [ ] 5.1 Initialize Node.js project in `/ai-agent` — `package.json`, folder structure, `.env`
    - Create `ai-agent/package.json` with `"type": "module"`, pinned dependencies: `ai@4.3.16`, `@ai-sdk/groq@1.2.9`, `express@4.19.2`, `cors@2.8.5`, `dotenv@16.4.5`, `zod@3.23.8`; dev: `jest@29`, `supertest`, `@jest/globals`
    - Create `ai-agent/.env` with keys: `PORT=3001`, `PHP_API_BASE_URL=http://localhost:8080`, `PHP_API_SECRET=change-me`, `GROQ_API_KEY=gsk_...`, `ALLOWED_ORIGIN=http://localhost:8080`
    - Create directory stubs: `src/`, `src/routes/`, `src/tools/`
    - _Requirements: 8.1, 8.2_

  - [ ] 5.2 Create `ai-agent/src/index.js` — Express app entry point
    - Load `dotenv/config` at the top
    - Create Express app, mount `cors({ origin: process.env.ALLOWED_ORIGIN })`
    - Mount `express.json({ limit: '1mb' })`
    - Register `GET /health` → `200 { "status": "ok" }`
    - Import and mount chat router at `/api/chat` (stub import, wired in task 5.3)
    - Add global error handler: `(err, req, res, next) => res.status(500).json({ error: 'Internal server error' })`
    - Listen on `process.env.PORT ?? 3001`
    - _Requirements: 8.1, 8.3, 8.4, 8.5, 8.6_

  - [ ] 5.3 Create `ai-agent/src/routes/chat.js` — `POST /api/chat` handler with `streamText` and context injection
    - Define `SYSTEM_PROMPT` constant in Vietnamese (full text from design spec Section 5 "System Prompt Specification")
    - Validate `req.body.messages` is a non-empty array; return `400 { "error": "messages array required" }` otherwise
    - Extract `context = req.body.context ?? {}` (`session_id`, `nguoi_dung_id`)
    - Create context-bound tool wrappers: wrap each tool's `execute` with a closure that injects `context`
    - Call `streamText({ model: groq('llama-3.3-70b-versatile'), system: SYSTEM_PROMPT, messages, tools, maxSteps: 5 })`
    - Pipe response via `result.pipeDataStreamToResponse(res)`
    - Catch Groq errors → `502 { "error": "AI service unavailable" }`
    - _Requirements: 9.1–9.6, 14.1–14.6_


- [ ] 6. Node.js AI Tools — implement the four tool files
  - [ ] 6.1 Create shared `ai-agent/src/tools/callPhpApi.js` helper
    - Export `async function callPhpApi(method, path, { params, body } = {})` that sets `X-Api-Key: process.env.PHP_API_SECRET`
    - Appends `params` as URLSearchParams for GET requests
    - On non-2xx: returns `{ error: "HTTP {status}: {text}" }`
    - On network error: returns `{ error: "Network error: {message}" }`
    - _Requirements: 10.2, 11.2, 12.2, 13.2_

  - [ ] 6.2 Create `ai-agent/src/tools/searchProducts.js` — `search_and_reason_products` tool
    - Define Zod schema: `query` (string required), `gia_min` (number optional), `gia_max` (number optional), `danh_muc_id` (number optional), `hang` (string optional)
    - `execute`: call `callPhpApi('GET', '/api/ai/products', { params })` with all non-undefined params
    - On success: return raw JSON array so LLM can reason over products
    - On empty array: return `{ products: [], message: 'Không tìm thấy sản phẩm phù hợp.' }`
    - On error: return `{ error }` without throwing
    - _Requirements: 10.1–10.5_

  - [ ] 6.3 Create `ai-agent/src/tools/addToCart.js` — `add_to_cart_autonomously` tool
    - Define Zod schema: `phien_ban_id` (number int positive), `so_luong` (number int min 1)
    - `execute(params, context)`: call `callPhpApi('POST', '/api/ai/cart/add', { body: { phien_ban_id, so_luong, session_id: context.session_id, nguoi_dung_id: context.nguoi_dung_id } })`
    - On `422` response: return `{ error: <Vietnamese message from PHP> }`
    - On success: return `{ cart_item_count, tong_tien }`
    - On other error: return `{ error }` without throwing
    - _Requirements: 11.1–11.5_

  - [ ] 6.4 Create `ai-agent/src/tools/huntAndApplyPromotions.js` — `hunt_and_apply_promotions` tool
    - Define Zod schema: `tong_tien` (number min 0)
    - `execute`: Step 1 — call `callPhpApi('GET', '/api/ai/promotions/optimal', { params: { tong_tien } })`
    - If `coupon` is null: return `{ available: false, message: <Vietnamese message from PHP> }`
    - Step 2 — automatically call `callPhpApi('POST', '/api/ai/checkout/apply-coupon', { body: { ma_code: coupon.ma_code, tong_tien } })`
    - On success: return `{ ma_code, tien_giam, tong_thanh_toan }`
    - On any error in either step: return `{ error }` without throwing
    - _Requirements: 12.1–12.6_

  - [ ] 6.5 Create `ai-agent/src/tools/trackOrder.js` — `track_order_status` tool
    - Define Zod schema: `order_code` (string required)
    - `execute(params, context)`: build URL `/api/ai/orders/{order_code}`, append `nguoi_dung_id` as query param when present in context
    - On `404`: return `{ error: 'Không tìm thấy đơn hàng với mã này.' }`
    - On `403`: return `{ error: 'Đơn hàng này không thuộc về bạn.' }`
    - On success: return full order JSON
    - On other error: return `{ error }` without throwing
    - _Requirements: 13.1–13.6_


- [ ] 7. Node.js Tests — Jest property-based and integration tests
  - [ ] 7.1 Write Jest integration/example tests for server and tools
    - `GET /health` → `{ status: 'ok' }` — Req 8.3
    - `POST /api/chat` without `messages` → `400 { "error": "messages array required" }` — Req 9.5
    - `POST /api/chat` with valid messages returns streaming response (mock Groq) — Req 9.1, 9.3
    - `search_and_reason_products` calls PHP API with correct query params (mock `fetch`) — Req 10.2
    - `add_to_cart_autonomously` forwards `session_id` and `nguoi_dung_id` from context (mock `fetch`) — Req 11.2
    - `track_order_status` appends `nguoi_dung_id` query param when present (mock `fetch`) — Req 13.2
    - `hunt_and_apply_promotions` when no coupon available returns `{ available: false }` message — Req 12.5
    - Tool errors are returned as structured `{ error }` objects, not thrown — Req 10.5, 11.5, 12.6, 13.6
    - _Requirements: 8.3, 9.1, 9.5, 10.2, 11.2, 12.5, 13.2_

  - [ ]* 7.2 Write Jest property-based test for `hunt_and_apply_promotions` two-step orchestration (Property 16)
    - **Property 16: hunt_and_apply_promotions two-step orchestration** — for any tong_tien where PHP API returns a coupon, assert the tool calls both `GET /api/ai/promotions/optimal` AND `POST /api/ai/checkout/apply-coupon` within a single invocation and result contains `ma_code`, `tien_giam`, `tong_thanh_toan`
    - Use fast-check, min 100 runs
    - **Validates: Requirements 12.2, 12.3, 12.4**
    - _Requirements: 12.2, 12.3, 12.4_

- [ ] 8. Checkpoint — Node.js microservice complete
  - Ensure all Jest tests pass, ask the user if questions arise.


- [ ] 9. Frontend Widget — PHP context injection and widget implementation
  - [ ] 9.1 Inject session/user context variables into PHP layout view
    - Locate the PHP layout view file (e.g. `commerce-core/app/views/client/layout/header.php` or equivalent master layout)
    - Add a `<script>` block before the closing `</body>` tag:
      ```php
      window.__nexus_session_id = <?= json_encode(htmlspecialchars(session_id(), ENT_QUOTES, 'UTF-8')) ?>;
      window.__nexus_user_id = <?= json_encode($currentUserId ?? null) ?>;
      ```
    - Ensure `session_start()` has already been called before this point (it is — existing session middleware handles it)
    - _Requirements: 18.1, 18.2, 18.3_

  - [ ] 9.2 Create `commerce-core/public/js/chatbot-widget.js` — full IIFE widget
    - Wrap entire file in `(function() { ... })();`
    - **Config block**: read `window.__nexus_session_id`, `window.__nexus_user_id`; define `AI_AGENT_URL` constant (e.g. `'http://localhost:3001'`)
    - **DOM factory**: create FAB button (fixed bottom-right, high z-index), chat window (hidden by default), message list `<div>`, text `<input>`, send `<button>`, close button
    - **State**: `let messages = [], isStreaming = false, welcomeShown = false`
    - **`render()`**: re-render message list from `messages` array, call `renderLinks()` on AI message content
    - **`sendMessage()`**: append user message to `messages`, call `streamChat()`, disable send button
    - **`streamChat()`**: `fetch(AI_AGENT_URL + '/api/chat', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ messages, context: { session_id: __nexus_session_id, nguoi_dung_id: __nexus_user_id } }) })`, read `response.body.getReader()` with `TextDecoder`
    - **Stream parsing**: handle Vercel AI data-stream frames — type `"0"` (text delta) → append to current AI bubble; type `"9"` (tool result) → detect `add_to_cart_autonomously` and call `syncCartBadge(cart_item_count)`; type `"d"` → finalize
    - **`syncCartBadge(count)`**: update all `[data-cart-count]` elements to `count`, briefly add CSS class for highlight animation
    - **`renderLinks(text)`**: replace `/san-pham/{slug}` pattern with `<a href="/san-pham/{slug}">{slug}</a>` links
    - **Error handling**: on `fetch` failure or non-2xx, display Vietnamese error message in chat, re-enable send
    - **Welcome message**: on first open, push AI message "Xin chào! Tôi là trợ lý mua sắm AI của Nexus-Commerce..." into messages
    - **Typing indicator**: show animated dots while `isStreaming = true`; hide and re-enable send when stream ends
    - **Transitions**: FAB click → toggle `slide-up`/`slide-down` CSS class on chat window
    - **Event wiring**: FAB click, close button click, send button click, Enter key on input
    - _Requirements: 15.1–15.7, 16.1–16.5, 17.1–17.4_

  - [ ] 9.3 Include widget `<script>` tag in PHP layout view
    - Add `<script src="/js/chatbot-widget.js"></script>` at the bottom of the layout body, after the context injection `<script>` block from task 9.1
    - _Requirements: 15.7_


- [ ] 10. Frontend Widget Tests — Vitest + jsdom
  - [ ] 10.1 Write Vitest example tests for widget UI and streaming behaviour
    - Set up `vitest` + `jsdom` + `@vitest/coverage-v8` in a `vitest.config.js` at workspace root or `commerce-core/`
    - FAB click opens chat window (assert class/display state) — Req 15.2
    - FAB/close click closes chat window — Req 15.3
    - Welcome message shown on first open — Req 15.5
    - Typing indicator visible during stream, hidden after — Req 15.6
    - Product slug URL in AI message rendered as `<a>` tag — Req 16.4
    - Network error on `fetch` displays Vietnamese error message and re-enables send button — Req 16.5
    - _Requirements: 15.2, 15.3, 15.5, 15.6, 16.4, 16.5_

  - [ ]* 10.2 Write Vitest property-based test for cart badge synchronisation (Property 17)
    - **Property 17: Cart badge synchronisation** — for any cart_item_count value n in a completed add_to_cart_autonomously tool-call result frame, assert `[data-cart-count]` DOM elements display n after stream processing
    - Use fast-check, min 100 runs
    - **Validates: Requirements 17.1**
    - _Requirements: 17.1_

  - [ ]* 10.3 Write Vitest property-based test for context XSS safety (Property 18)
    - **Property 18: Context XSS safety** — for any session ID or user ID containing HTML special characters (`<`, `>`, `"`, `'`, `&`), assert the rendered `<script>` block output does not contain unescaped HTML and is valid JSON
    - Use fast-check, min 200 runs; test the PHP output via a PHP CLI subprocess or by unit-testing the escaping logic in isolation
    - **Validates: Requirements 18.3**
    - _Requirements: 18.3_

- [ ] 11. Final Checkpoint — full system wired together
  - Ensure all PHPUnit, Jest, and Vitest tests pass, ask the user if questions arise.


---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Each task references specific requirements for full traceability
- Checkpoints (tasks 4, 8, 11) ensure incremental validation at phase boundaries
- Property tests validate universal correctness properties; unit/integration tests validate specific scenarios and edge cases
- The `callPhpApi` helper (task 6.1) must be implemented before any tool file (tasks 6.2–6.5)
- The PHP route registrations (task 1.3) must exist before any PHP endpoint implementation (tasks 2.1–2.11) can be exercised end-to-end
- Widget tests (task 10.1–10.3) require the widget file (task 9.2) to exist first
- PHP PBT library recommendation: [eris/eris](https://github.com/giorgiosironi/eris) or [phpspec/prophecy](https://github.com/phpspec/prophecy) for mocking; Node.js PBT: [fast-check](https://github.com/dubzzz/fast-check)

---

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2"] },
    { "id": 2, "tasks": ["1.3"] },
    { "id": 3, "tasks": ["2.1", "2.3", "2.5", "2.7", "2.9", "2.11", "5.1"] },
    { "id": 4, "tasks": ["2.2", "2.4", "2.6", "2.8", "2.10", "2.12", "5.2"] },
    { "id": 5, "tasks": ["3.1", "5.3", "6.1"] },
    { "id": 6, "tasks": ["6.2", "6.3", "6.4", "6.5"] },
    { "id": 7, "tasks": ["7.1"] },
    { "id": 8, "tasks": ["7.2", "9.1"] },
    { "id": 9, "tasks": ["9.2"] },
    { "id": 10, "tasks": ["9.3"] },
    { "id": 11, "tasks": ["10.1"] },
    { "id": 12, "tasks": ["10.2", "10.3"] }
  ]
}
```
