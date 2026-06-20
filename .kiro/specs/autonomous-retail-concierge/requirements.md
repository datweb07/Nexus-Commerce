# Requirements Document

## Introduction

The **Autonomous Retail Concierge** is an agentic AI system built on top of the existing **Nexus-Commerce** PHP 8.x MVC e-commerce platform. It consists of three integrated layers:

1. **PHP REST API Layer** (`/commerce-core`) — New internal API endpoints that expose product search, cart management, promotion lookup, and order tracking data in clean JSON, specifically designed for AI agent consumption.
2. **Node.js AI Microservice** (`/ai-agent`) — An Express-based microservice using the Vercel AI SDK and the Groq LLM provider. It exposes an agentic chat endpoint that maps user intent to structured tool calls against the PHP API.
3. **Frontend Chatbot UI** (`/commerce-core`) — A floating chatbot widget embedded in the existing PHP views. It provides a conversational shopping interface with real-time cart synchronization.

The system enables shoppers to find products, add items to their cart, discover and apply promotional codes, and track order status — all through natural-language conversation — without leaving the storefront.

---

## Glossary

- **AI_Agent**: The Node.js Express microservice located in `/ai-agent` that orchestrates all AI tool calls.
- **PHP_API**: The new set of REST endpoints added to `/commerce-core` under the `/api/ai/` route prefix.
- **Chatbot_Widget**: The floating HTML/CSS/JS UI component embedded in the Nexus-Commerce PHP views.
- **Tool**: A callable function defined in the AI_Agent using Zod schemas that maps to a PHP_API endpoint.
- **Variant** (`phien_ban_san_pham`): A specific purchasable version of a product, identified by `phien_ban_id`, with its own price, stock, colour, and attributes.
- **Cart** (`gio_hang` / `chi_tiet_gio`): The session-scoped or user-scoped shopping cart persisted in the MySQL database.
- **Coupon** (`ma_giam_gia`): A discount code with a code string (`ma_code`), discount type (`loai_giam`), value, minimum order amount, usage limits, and validity period.
- **Promotion** (`khuyen_mai`): A product-level discount campaign linked via `san_pham_khuyen_mai`.
- **Session_Context**: The PHP session identifier and optional authenticated user ID forwarded by the Chatbot_Widget to the AI_Agent on every request.
- **CORS_Policy**: HTTP headers required to allow the Node.js AI_Agent (a separate origin) to call the PHP_API.
- **Groq_Provider**: The LLM inference backend accessed via `@ai-sdk/groq` inside the AI_Agent.
- **System_Prompt**: The persona and behavioural instructions sent to the Groq_Provider at the start of every chat session.

---

## Requirements

---

### Requirement 1: PHP API — Product Search and Filtering

**User Story:** As the AI_Agent, I want to search and filter the product catalogue by keyword, price range, category, and brand, so that I can retrieve relevant product options to present to the shopper.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `GET` request to `/api/ai/products`, THE PHP_API SHALL return a JSON array of matching products including `id`, `ten_san_pham`, `slug`, `hang_san_xuat`, `ten_danh_muc`, `gia_thap_nhat`, `gia_cao_nhat`, `diem_danh_gia`, `anh_dai_dien`, and `tong_ton_kho`.
2. WHEN the request includes a `q` query parameter, THE PHP_API SHALL filter results to products whose `ten_san_pham` or `hang_san_xuat` contains the keyword using a case-insensitive partial match.
3. WHEN the request includes `gia_min` and/or `gia_max` query parameters as non-negative integers, THE PHP_API SHALL restrict results to products whose minimum variant price falls within the specified range.
4. WHEN the request includes a `danh_muc_id` query parameter as a positive integer, THE PHP_API SHALL restrict results to products belonging to that category or any of its direct sub-categories.
5. WHEN the request includes a `hang` query parameter, THE PHP_API SHALL filter results to products whose `hang_san_xuat` matches the specified brand (case-insensitive).
6. THE PHP_API SHALL return only products with `trang_thai = 'CON_BAN'` and at least one variant with `trang_thai != 'NGUNG_BAN'`.
7. WHEN no products match the applied filters, THE PHP_API SHALL return a `200 OK` response with an empty JSON array.
8. WHEN the request omits all filter parameters, THE PHP_API SHALL return up to 20 products ordered by `diem_danh_gia` descending.
9. WHEN a `limit` parameter between 1 and 50 is supplied, THE PHP_API SHALL return at most that many results.
10. IF a filter parameter contains a value that cannot be cast to its expected type, THEN THE PHP_API SHALL return a `400 Bad Request` JSON response with a `message` field describing the invalid parameter.

---

### Requirement 2: PHP API — Variant Detail Lookup

**User Story:** As the AI_Agent, I want to retrieve all purchasable variants for a specific product, so that I can present variant options (colour, storage, RAM) and their prices to the shopper before adding to cart.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `GET` request to `/api/ai/products/{san_pham_id}/variants`, THE PHP_API SHALL return a JSON array of variants for the product, each including `id`, `sku`, `ten_phien_ban`, `mau_sac`, `thuoc_tinh_bien_the`, `gia_ban`, `gia_goc`, `so_luong_ton`, and `trang_thai`.
2. THE PHP_API SHALL only return variants with `trang_thai` equal to `'CON_HANG'` or `'CON_BAN'`.
3. IF the `san_pham_id` does not correspond to an active product, THEN THE PHP_API SHALL return a `404 Not Found` JSON response with a `message` field.
4. WHEN the product has no available variants, THE PHP_API SHALL return a `200 OK` response with an empty JSON array.

---

### Requirement 3: PHP API — Add Variant to Cart

**User Story:** As the AI_Agent, I want to add a product variant to the shopper's cart autonomously, so that the shopper does not need to navigate to the product page.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `POST` request to `/api/ai/cart/add` with a JSON body containing `phien_ban_id` (positive integer) and `so_luong` (positive integer), THE PHP_API SHALL add the specified quantity of that variant to the cart identified by the `session_id` or `nguoi_dung_id` included in the request body.
2. WHEN the variant already exists in the cart, THE PHP_API SHALL increment its quantity by `so_luong` rather than creating a duplicate entry, consistent with the `GH_ThemVaoGioHang` stored procedure behaviour.
3. WHEN the cart item is added or updated successfully, THE PHP_API SHALL return a `200 OK` JSON response including `cart_item_count` (total number of distinct variants in cart) and `tong_tien` (total cart value in VND).
4. IF `phien_ban_id` does not exist or its `trang_thai` is `'NGUNG_BAN'`, THEN THE PHP_API SHALL return a `404 Not Found` JSON response with a `message` field.
5. IF `so_luong_ton` for the requested variant is less than `so_luong`, THEN THE PHP_API SHALL return a `422 Unprocessable Entity` JSON response with a `message` field indicating insufficient stock.
6. IF both `session_id` and `nguoi_dung_id` are absent from the request body, THEN THE PHP_API SHALL return a `400 Bad Request` JSON response with a `message` field.
7. WHEN `nguoi_dung_id` is provided, THE PHP_API SHALL resolve the cart using the user's persistent cart; WHEN only `session_id` is provided, THE PHP_API SHALL resolve the cart using the guest session cart.

---

### Requirement 4: PHP API — Optimal Promotion Lookup

**User Story:** As the AI_Agent, I want to retrieve the best applicable coupon for the current cart value, so that I can proactively suggest savings to the shopper.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `GET` request to `/api/ai/promotions/optimal` with a `tong_tien` query parameter (non-negative integer in VND), THE PHP_API SHALL evaluate all active coupons from `ma_giam_gia` where `trang_thai = 'HOAT_DONG'`, `don_toi_thieu <= tong_tien`, `ngay_bat_dau <= NOW()`, `ngay_ket_thuc >= NOW()`, and usage limit has not been reached.
2. THE PHP_API SHALL select and return the single coupon that produces the greatest `tien_giam` (calculated discount amount), including fields `ma_code`, `loai_giam`, `gia_tri_giam`, `giam_toi_da`, `don_toi_thieu`, `tien_giam`, and `ngay_ket_thuc`.
3. WHEN no eligible coupon exists for the given cart total, THE PHP_API SHALL return a `200 OK` JSON response with a `null` value for the coupon field and a human-readable `message` in Vietnamese.
4. IF the `tong_tien` parameter is missing or is not a non-negative integer, THEN THE PHP_API SHALL return a `400 Bad Request` JSON response with a `message` field.

---

### Requirement 5: PHP API — Apply Coupon to Checkout

**User Story:** As the AI_Agent, I want to apply a coupon code to the shopper's checkout session, so that the discount is immediately reflected in the order total.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `POST` request to `/api/ai/checkout/apply-coupon` with a JSON body containing `ma_code` (string) and `tong_tien` (non-negative number), THE PHP_API SHALL validate the coupon using the same eligibility rules as `MaGiamGia::kiemTraHopLe`.
2. WHEN the coupon is valid, THE PHP_API SHALL return a `200 OK` JSON response including `ma_giam_gia_id`, `ma_code`, `tien_giam` (calculated discount), and `tong_thanh_toan` (cart total after discount).
3. IF the coupon code does not exist, THEN THE PHP_API SHALL return a `404 Not Found` JSON response with a Vietnamese `message` field.
4. IF the coupon is inactive, expired, usage-exhausted, or the cart total is below `don_toi_thieu`, THEN THE PHP_API SHALL return a `422 Unprocessable Entity` JSON response with a descriptive Vietnamese `message` field using the `MaGiamGia::layThongBaoLoiMaGiamGia` method.
5. IF `ma_code` or `tong_tien` are absent from the request body, THEN THE PHP_API SHALL return a `400 Bad Request` JSON response with a `message` field.

---

### Requirement 6: PHP API — Order Status Lookup

**User Story:** As the AI_Agent, I want to retrieve the current status and summary of an order by its order code, so that the shopper can track their purchase through conversation.

#### Acceptance Criteria

1. WHEN the AI_Agent sends a `GET` request to `/api/ai/orders/{order_code}`, THE PHP_API SHALL query `don_hang` by `ma_don_hang` and return a JSON object containing `ma_don_hang`, `trang_thai`, `tong_thanh_toan`, `ngay_tao`, `ten_nguoi_nhan`, `sdt_nguoi_nhan`, `dia_chi_giao_hang`, and a `san_pham` array with `ten_san_pham`, `ten_phien_ban`, `so_luong`, and `gia_tai_thoi_diem_mua` for each item.
2. WHEN `nguoi_dung_id` is supplied as a query parameter, THE PHP_API SHALL verify that the order belongs to that user; IF the order does not belong to the user, THEN THE PHP_API SHALL return a `403 Forbidden` JSON response with a Vietnamese `message` field.
3. IF no order with the given `order_code` exists, THEN THE PHP_API SHALL return a `404 Not Found` JSON response with a Vietnamese `message` field.
4. WHEN `nguoi_dung_id` is not supplied, THE PHP_API SHALL return order details without ownership verification, to support guest order lookup.

---

### Requirement 7: PHP API — CORS and Security

**User Story:** As the AI_Agent, I want the PHP_API to accept cross-origin requests, so that the Node.js microservice running on a different port or domain can call it without browser security errors.

#### Acceptance Criteria

1. THE PHP_API SHALL include an `Access-Control-Allow-Origin` response header on all `/api/ai/*` endpoints.
2. WHEN the `Origin` header of a request matches the configured `AI_AGENT_ORIGIN` environment variable, THE PHP_API SHALL set `Access-Control-Allow-Origin` to that specific origin.
3. THE PHP_API SHALL include `Access-Control-Allow-Methods: GET, POST, OPTIONS` and `Access-Control-Allow-Headers: Content-Type, X-Api-Key` response headers on all `/api/ai/*` endpoints.
4. WHEN the HTTP method is `OPTIONS`, THE PHP_API SHALL respond with `204 No Content` and the CORS headers without executing business logic, to handle preflight requests.
5. WHEN a request to any `/api/ai/*` endpoint is missing the `X-Api-Key` header or the key does not match the `AI_API_SECRET` environment variable, THE PHP_API SHALL return a `401 Unauthorized` JSON response with a `message` field.
6. THE PHP_API SHALL set `Content-Type: application/json; charset=utf-8` on all non-preflight responses.
7. THE PHP_API SHALL sanitize all query and body parameters before using them in database queries to prevent SQL injection.

---

### Requirement 8: Node.js AI Microservice — Server Setup

**User Story:** As a developer, I want the AI_Agent to run as a standalone Express server, so that it can be started independently and scaled separately from the PHP backend.

#### Acceptance Criteria

1. THE AI_Agent SHALL expose an HTTP server on the port specified by the `PORT` environment variable, defaulting to `3001` when `PORT` is not set.
2. THE AI_Agent SHALL load configuration from a `.env` file in the `/ai-agent` directory using `dotenv`, including `PHP_API_BASE_URL`, `PHP_API_SECRET`, `GROQ_API_KEY`, `ALLOWED_ORIGIN`, and `PORT`.
3. THE AI_Agent SHALL respond to `GET /health` with a `200 OK` JSON response containing `{ "status": "ok" }` to enable liveness checks.
4. THE AI_Agent SHALL include CORS middleware that permits requests from the origin specified by the `ALLOWED_ORIGIN` environment variable.
5. THE AI_Agent SHALL parse `application/json` request bodies using Express's built-in JSON body parser with a maximum body size of 1 MB.
6. IF an uncaught synchronous error occurs in a route handler, THEN THE AI_Agent SHALL return a `500 Internal Server Error` JSON response with `{ "error": "Internal server error" }` without exposing stack traces.

---

### Requirement 9: Node.js AI Microservice — Agentic Chat Endpoint

**User Story:** As the Chatbot_Widget, I want to send a conversation history to the AI_Agent and receive a streamed AI response that may invoke tools autonomously, so that the shopper gets real-time, context-aware answers.

#### Acceptance Criteria

1. THE AI_Agent SHALL expose a `POST /api/chat` endpoint that accepts a JSON body with a `messages` array (OpenAI-compatible format) and a `context` object containing `session_id` and optionally `nguoi_dung_id`.
2. WHEN a valid request is received, THE AI_Agent SHALL call the Groq_Provider using the Vercel AI SDK `streamText` function with the System_Prompt, the provided `messages`, and the registered Tools.
3. THE AI_Agent SHALL stream the response back to the Chatbot_Widget using the Vercel AI SDK data-stream protocol so the widget can render tokens progressively.
4. THE AI_Agent SHALL permit up to 5 sequential autonomous tool invocation rounds (`maxSteps: 5`) before requiring a new user message.
5. IF the `messages` array is absent or not an array, THEN THE AI_Agent SHALL return a `400 Bad Request` JSON response with `{ "error": "messages array required" }`.
6. IF the Groq_Provider returns an error, THEN THE AI_Agent SHALL return a `502 Bad Gateway` JSON response with `{ "error": "AI service unavailable" }`.

---

### Requirement 10: Node.js AI Microservice — Tool: search_and_reason_products

**User Story:** As the AI_Agent, I want to search the product catalogue on behalf of the shopper, so that I can autonomously retrieve and reason about matching products.

#### Acceptance Criteria

1. THE AI_Agent SHALL define a `search_and_reason_products` Tool with a Zod schema accepting `query` (string, required), `gia_min` (number, optional), `gia_max` (number, optional), `danh_muc_id` (number, optional), and `hang` (string, optional).
2. WHEN the tool is invoked, THE AI_Agent SHALL call `GET {PHP_API_BASE_URL}/api/ai/products` with the tool parameters as query string arguments and the `X-Api-Key` header set to `PHP_API_SECRET`.
3. WHEN the PHP_API returns products, THE AI_Agent SHALL pass the raw JSON array to the LLM as the tool result so the model can reason about which product best fits the shopper's expressed need.
4. WHEN the PHP_API returns an empty array, THE AI_Agent SHALL return a tool result indicating no products were found so the model can inform the shopper accordingly.
5. IF the HTTP call to the PHP_API fails or returns a non-2xx status, THEN THE AI_Agent SHALL return a tool result with an `error` field containing the HTTP status and a descriptive message, without throwing an uncaught exception.

---

### Requirement 11: Node.js AI Microservice — Tool: add_to_cart_autonomously

**User Story:** As the AI_Agent, I want to add a specific product variant to the shopper's cart without requiring the shopper to navigate away, so that the shopping experience is fully conversational.

#### Acceptance Criteria

1. THE AI_Agent SHALL define an `add_to_cart_autonomously` Tool with a Zod schema accepting `phien_ban_id` (number, required) and `so_luong` (number, required, minimum 1).
2. WHEN the tool is invoked, THE AI_Agent SHALL call `POST {PHP_API_BASE_URL}/api/ai/cart/add` with a JSON body containing `phien_ban_id`, `so_luong`, and the `session_id` and `nguoi_dung_id` from the current `context` object.
3. WHEN the PHP_API returns a successful response, THE AI_Agent SHALL return a tool result containing `cart_item_count` and `tong_tien` so the LLM can confirm the action to the shopper in natural language.
4. IF the PHP_API returns a `422` response due to insufficient stock, THE AI_Agent SHALL return a tool result with an `error` field containing the Vietnamese message from the PHP_API so the model can relay it to the shopper.
5. IF the HTTP call to the PHP_API fails or returns any other non-2xx status, THEN THE AI_Agent SHALL return a tool result with an `error` field without throwing an uncaught exception.

---

### Requirement 12: Node.js AI Microservice — Tool: hunt_and_apply_promotions

**User Story:** As the AI_Agent, I want to autonomously find and apply the best promotional code for the shopper's current cart, so that the shopper always gets the maximum available discount.

#### Acceptance Criteria

1. THE AI_Agent SHALL define a `hunt_and_apply_promotions` Tool with a Zod schema accepting `tong_tien` (number, required, minimum 0).
2. WHEN the tool is invoked, THE AI_Agent SHALL first call `GET {PHP_API_BASE_URL}/api/ai/promotions/optimal?tong_tien={tong_tien}` to retrieve the best coupon.
3. WHEN a coupon is returned by the optimal promotion endpoint, THE AI_Agent SHALL automatically call `POST {PHP_API_BASE_URL}/api/ai/checkout/apply-coupon` with the `ma_code` and `tong_tien` to validate and apply it, without requiring a separate user instruction.
4. WHEN the coupon is successfully applied, THE AI_Agent SHALL return a tool result containing `ma_code`, `tien_giam`, and `tong_thanh_toan` so the LLM can present the savings to the shopper.
5. WHEN no eligible coupon exists, THE AI_Agent SHALL return a tool result indicating no promotions are available for the current cart total.
6. IF either HTTP call to the PHP_API fails or returns a non-2xx status, THEN THE AI_Agent SHALL return a tool result with an `error` field without throwing an uncaught exception.

---

### Requirement 13: Node.js AI Microservice — Tool: track_order_status

**User Story:** As the AI_Agent, I want to look up the status of a specific order by its order code, so that the shopper can track their delivery through natural-language conversation.

#### Acceptance Criteria

1. THE AI_Agent SHALL define a `track_order_status` Tool with a Zod schema accepting `order_code` (string, required).
2. WHEN the tool is invoked, THE AI_Agent SHALL call `GET {PHP_API_BASE_URL}/api/ai/orders/{order_code}` appending `nguoi_dung_id` as a query parameter when it is present in the current `context`.
3. WHEN the PHP_API returns order details, THE AI_Agent SHALL return the full order object as the tool result so the LLM can summarise the status in Vietnamese for the shopper.
4. IF the PHP_API returns a `404` response, THE AI_Agent SHALL return a tool result with a message indicating the order code was not found.
5. IF the PHP_API returns a `403` response, THE AI_Agent SHALL return a tool result with a message indicating the order does not belong to the current user.
6. IF the HTTP call fails or returns any other non-2xx status, THEN THE AI_Agent SHALL return a tool result with an `error` field without throwing an uncaught exception.

---

### Requirement 14: Node.js AI Microservice — System Prompt

**User Story:** As a shopper, I want the AI concierge to behave like a knowledgeable, proactive retail assistant, so that the conversation feels natural and helpful rather than robotic.

#### Acceptance Criteria

1. THE AI_Agent SHALL configure a System_Prompt that instructs the Groq_Provider to act as a proactive retail assistant for Nexus-Commerce, specialising in Vietnamese electronics and consumer goods.
2. THE System_Prompt SHALL instruct the model to respond exclusively in Vietnamese unless the shopper explicitly uses another language.
3. THE System_Prompt SHALL instruct the model to proactively invoke `search_and_reason_products` when the shopper expresses product interest, without requiring the shopper to ask explicitly.
4. THE System_Prompt SHALL instruct the model to proactively invoke `hunt_and_apply_promotions` after successfully adding an item to the cart, without requiring the shopper to ask.
5. THE System_Prompt SHALL instruct the model to always confirm with the shopper before invoking `add_to_cart_autonomously`, presenting the selected variant name and price.
6. THE System_Prompt SHALL instruct the model to present product prices in formatted Vietnamese Dong (e.g., "34.990.000 ₫") and to include product `slug`-based URLs in the format `/san-pham/{slug}` when recommending a product.

---

### Requirement 15: Frontend Chatbot Widget — UI Structure

**User Story:** As a shopper, I want a floating chat button that opens a chat window within the existing storefront, so that I can interact with the AI concierge without leaving the page I am browsing.

#### Acceptance Criteria

1. THE Chatbot_Widget SHALL render a floating action button (FAB) fixed to the bottom-right corner of every page where the widget script is included, at a `z-index` high enough to appear above all existing page content.
2. WHEN the FAB is clicked and the chat window is closed, THE Chatbot_Widget SHALL open the chat window with an animated slide-up transition.
3. WHEN the chat window is open and the FAB or a close button is clicked, THE Chatbot_Widget SHALL close the chat window with a slide-down transition.
4. THE Chatbot_Widget SHALL render a message list area, a text input field, and a send button within the chat window.
5. THE Chatbot_Widget SHALL display a welcome message from the AI concierge in Vietnamese when the chat window is opened for the first time in a page session.
6. WHILE an AI response is being streamed, THE Chatbot_Widget SHALL display a typing indicator and disable the send button to prevent duplicate submissions.
7. THE Chatbot_Widget SHALL be implemented as a single self-contained JavaScript file that can be included in any PHP view via a `<script>` tag.

---

### Requirement 16: Frontend Chatbot Widget — Messaging and Streaming

**User Story:** As a shopper, I want to send messages and see the AI's response appear progressively, so that I do not have to wait for the full reply before seeing partial content.

#### Acceptance Criteria

1. WHEN the shopper submits a message, THE Chatbot_Widget SHALL append the shopper's message to the message list and send the full conversation history to `POST {AI_AGENT_URL}/api/chat` with the `context` object containing `session_id` and `nguoi_dung_id` (if the user is authenticated).
2. THE Chatbot_Widget SHALL read the response as a streamed data-stream using the Vercel AI SDK client `readDataStream` protocol and append tokens to the AI message bubble incrementally.
3. WHEN the AI stream is complete, THE Chatbot_Widget SHALL re-enable the send button and scroll the message list to the bottom.
4. WHEN a message containing a product slug URL in the format `/san-pham/{slug}` is received, THE Chatbot_Widget SHALL render it as a clickable hyperlink opening the product page.
5. IF the HTTP request to the AI_Agent fails (network error or non-2xx status), THE Chatbot_Widget SHALL display a Vietnamese error message in the chat window and re-enable the send button.

---

### Requirement 17: Frontend Chatbot Widget — Cart Synchronisation

**User Story:** As a shopper, I want the cart item count in the page header to update automatically when the AI concierge adds an item to my cart, so that the cart reflects the AI's actions in real time without a page reload.

#### Acceptance Criteria

1. WHEN the AI_Agent's streamed response contains a completed `add_to_cart_autonomously` tool call result with `cart_item_count`, THE Chatbot_Widget SHALL update the cart badge element (identified by the selector `[data-cart-count]`) on the current page to reflect the new count.
2. WHEN the cart badge is updated, THE Chatbot_Widget SHALL briefly apply a CSS highlight animation to the badge element to draw the shopper's attention.
3. THE Chatbot_Widget SHALL extract the `session_id` from the PHP-rendered page context (available as `window.__nexus_session_id`) and include it in every request to the AI_Agent.
4. WHEN the user is authenticated, THE Chatbot_Widget SHALL extract `nguoi_dung_id` from `window.__nexus_user_id` and include it in every request to the AI_Agent.

---

### Requirement 18: Session and User Context Passing

**User Story:** As a developer, I want the PHP views to expose session and user context to JavaScript, so that the Chatbot_Widget can pass the correct cart-resolution context to the AI_Agent without making additional round-trip API calls.

#### Acceptance Criteria

1. THE PHP_API layout view SHALL include a `<script>` block that assigns `window.__nexus_session_id` to the PHP session ID value obtained from `session_id()`.
2. WHEN the user is authenticated, THE PHP_API layout view SHALL assign `window.__nexus_user_id` to the authenticated user's `id` from the session; WHEN the user is not authenticated, THE PHP_API layout view SHALL assign `window.__nexus_user_id` to `null`.
3. THE values assigned to `window.__nexus_session_id` and `window.__nexus_user_id` SHALL be JSON-encoded and output-escaped to prevent XSS.
```
