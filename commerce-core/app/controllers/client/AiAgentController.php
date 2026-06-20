<?php

require_once dirname(__DIR__, 2) . '/core/EnvSetup.php';

class AiAgentController
{
    /**
     * Reads AI_AGENT_ORIGIN and AI_API_SECRET via EnvSetup, emits CORS headers,
     * short-circuits OPTIONS with 204, and rejects missing/wrong X-Api-Key with 401.
     */
    public function handleCorsAndAuth(): void
    {
        require_once dirname(__DIR__, 2) . '/core/EnvSetup.php';
        $envConfig = EnvSetup::env(dirname(__DIR__, 3));

        $allowedOrigin = $envConfig('AI_AGENT_ORIGIN', 'http://localhost:3001');
        $apiSecret     = $envConfig('AI_API_SECRET', '');

        // Emit CORS headers
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

        // Short-circuit preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Validate API key
        $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if ($providedKey === '' || $providedKey !== $apiSecret) {
            $this->jsonResponse(401, ['message' => 'Unauthorized: invalid or missing API key']);
        }
    }

    /**
     * Sets Content-Type, writes HTTP status, encodes $data as JSON, then exits.
     *
     * @param int   $status HTTP response code
     * @param mixed $data   Data to JSON-encode
     * @return never
     */
    private function jsonResponse(int $status, mixed $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------------------------------------------------------------
    // Public endpoint stubs — each enforces CORS/auth then returns empty 200
    // -------------------------------------------------------------------------

    /**
     * GET /api/ai/products
     *
     * Query params:
     *   q           string   — keyword (case-insensitive partial match on ten_san_pham / hang_san_xuat)
     *   gia_min     int ≥ 0  — minimum variant price filter
     *   gia_max     int ≥ 0  — maximum variant price filter
     *   danh_muc_id int > 0  — category ID (includes direct sub-categories)
     *   hang        string   — brand name (case-insensitive exact match)
     *   limit       int 1–50 — max results, default 20
     *
     * Returns 200 JSON array of products; 400 on invalid params.
     *
     * Requirements: 1.1–1.10
     */
    public function products(): void
    {
        $this->handleCorsAndAuth();

        // ── 1. Validate and parse query parameters ────────────────────────────

        // gia_min — must be a non-negative integer string or absent
        $giaMinRaw = $_GET['gia_min'] ?? '';
        if ($giaMinRaw !== '' && !ctype_digit($giaMinRaw)) {
            $this->jsonResponse(400, ['message' => 'Tham số gia_min không hợp lệ: phải là số nguyên không âm.']);
        }
        $giaMin = $giaMinRaw !== '' ? (int) $giaMinRaw : null;

        // gia_max — must be a non-negative integer string or absent
        $giaMaxRaw = $_GET['gia_max'] ?? '';
        if ($giaMaxRaw !== '' && !ctype_digit($giaMaxRaw)) {
            $this->jsonResponse(400, ['message' => 'Tham số gia_max không hợp lệ: phải là số nguyên không âm.']);
        }
        $giaMax = $giaMaxRaw !== '' ? (int) $giaMaxRaw : null;

        // danh_muc_id — must be a positive integer or absent
        $danhMucIdRaw = $_GET['danh_muc_id'] ?? '';
        if ($danhMucIdRaw !== '') {
            if (!ctype_digit($danhMucIdRaw) || (int) $danhMucIdRaw <= 0) {
                $this->jsonResponse(400, ['message' => 'Tham số danh_muc_id không hợp lệ: phải là số nguyên dương.']);
            }
        }
        $danhMucId = $danhMucIdRaw !== '' ? (int) $danhMucIdRaw : null;

        // limit — integer 1–50, default 20
        $limitRaw = $_GET['limit'] ?? '20';
        if (!ctype_digit($limitRaw) || (int) $limitRaw < 1 || (int) $limitRaw > 50) {
            $this->jsonResponse(400, ['message' => 'Tham số limit không hợp lệ: phải là số nguyên từ 1 đến 50.']);
        }
        $limit = (int) $limitRaw;

        // hang — string (no type constraint, just read it; empty string treated as absent)
        $hang = isset($_GET['hang']) ? trim((string) $_GET['hang']) : '';

        // q — string keyword (no type constraint)
        $q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        // ── 2. Get a database connection via BaseModel ────────────────────────
        require_once dirname(__DIR__, 2) . '/models/BaseModel.php';
        // BaseModel::__construct() calls taoKetNoi() internally; we use it as a
        // connection holder and to call query() with our custom SQL.
        $model = new BaseModel('san_pham');

        // ── 3. Build WHERE conditions ─────────────────────────────────────────
        $where = [];

        // Only active products
        $where[] = "sp.trang_thai = 'CON_BAN'";

        // Must have at least one non-discontinued variant
        $where[] = "EXISTS (
            SELECT 1 FROM phien_ban_san_pham pbv
            WHERE pbv.san_pham_id = sp.id
              AND pbv.trang_thai != 'NGUNG_BAN'
        )";

        // Keyword search — case-insensitive partial match via LIKE with addslashes for safety
        if ($q !== '') {
            $qEsc = addslashes($q);
            $where[] = "(LOWER(sp.ten_san_pham) LIKE LOWER('%{$qEsc}%')
                        OR LOWER(sp.hang_san_xuat) LIKE LOWER('%{$qEsc}%'))";
        }

        // Category filter — includes direct sub-categories
        if ($danhMucId !== null) {
            $where[] = "sp.danh_muc_id IN (
                SELECT dm_sub.id FROM danh_muc dm_sub
                WHERE dm_sub.id = {$danhMucId} OR dm_sub.danh_muc_cha_id = {$danhMucId}
            )";
        }

        // Brand filter — case-insensitive exact match
        if ($hang !== '') {
            $hangEsc = addslashes($hang);
            $where[] = "LOWER(sp.hang_san_xuat) = LOWER('{$hangEsc}')";
        }

        // Price range filter — applies to gia_thap_nhat (minimum variant price)
        if ($giaMin !== null) {
            $where[] = "(SELECT MIN(pb2.gia_ban) FROM phien_ban_san_pham pb2 WHERE pb2.san_pham_id = sp.id) >= {$giaMin}";
        }
        if ($giaMax !== null) {
            $where[] = "(SELECT MIN(pb2.gia_ban) FROM phien_ban_san_pham pb2 WHERE pb2.san_pham_id = sp.id) <= {$giaMax}";
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // ── 4. Build the full SELECT ──────────────────────────────────────────
        $sql = "SELECT
                    sp.id,
                    sp.ten_san_pham,
                    sp.slug,
                    sp.hang_san_xuat,
                    sp.diem_danh_gia,
                    dm.ten AS ten_danh_muc,
                    (SELECT MIN(pb.gia_ban)
                     FROM phien_ban_san_pham pb
                     WHERE pb.san_pham_id = sp.id) AS gia_thap_nhat,
                    (SELECT MAX(pb.gia_ban)
                     FROM phien_ban_san_pham pb
                     WHERE pb.san_pham_id = sp.id) AS gia_cao_nhat,
                    (SELECT SUM(pbstock.so_luong_ton)
                     FROM phien_ban_san_pham pbstock
                     WHERE pbstock.san_pham_id = sp.id
                       AND pbstock.trang_thai != 'NGUNG_BAN') AS tong_ton_kho,
                    (SELECT hanh.url_anh
                     FROM hinh_anh_san_pham hanh
                     WHERE hanh.san_pham_id = sp.id
                       AND hanh.la_anh_chinh = 1
                     LIMIT 1) AS anh_dai_dien
                FROM san_pham sp
                LEFT JOIN danh_muc dm ON dm.id = sp.danh_muc_id
                {$whereSql}
                ORDER BY sp.diem_danh_gia DESC
                LIMIT {$limit}";

        $rows = $model->query($sql);

        // ── 5. Cast numeric fields and return ────────────────────────────────
        $products = array_map(static function (array $row): array {
            return [
                'id'            => (int) $row['id'],
                'ten_san_pham'  => $row['ten_san_pham'],
                'slug'          => $row['slug'],
                'hang_san_xuat' => $row['hang_san_xuat'],
                'ten_danh_muc'  => $row['ten_danh_muc'],
                'gia_thap_nhat' => $row['gia_thap_nhat'] !== null ? (float) $row['gia_thap_nhat'] : null,
                'gia_cao_nhat'  => $row['gia_cao_nhat']  !== null ? (float) $row['gia_cao_nhat']  : null,
                'diem_danh_gia' => (float) $row['diem_danh_gia'],
                'anh_dai_dien'  => $row['anh_dai_dien'],
                'tong_ton_kho'  => $row['tong_ton_kho'] !== null ? (int) $row['tong_ton_kho'] : 0,
            ];
        }, $rows);

        $this->jsonResponse(200, $products);
    }

    /**
     * GET /api/ai/products/{id}/variants
     */
    public function variants(int $id): void
    {
        $this->handleCorsAndAuth();

        require_once dirname(__DIR__, 2) . '/models/entities/SanPham.php';
        require_once dirname(__DIR__, 2) . '/models/entities/PhienBanSanPham.php';

        // Validate parent product exists and is active
        $sanPhamModel = new SanPham();
        $sanPham = $sanPhamModel->getById($id);
        if (!$sanPham || $sanPham['trang_thai'] !== 'CON_BAN') {
            $this->jsonResponse(404, ['message' => 'Sản phẩm không tồn tại hoặc đã ngừng bán.']);
        }

        // Fetch all variants for this product
        $phienBanModel = new PhienBanSanPham();
        $tatCaPhienBan = $phienBanModel->layPhienBanTheoSanPham($id);

        // Filter to only available statuses
        $availableStatuses = ['CON_HANG', 'CON_BAN'];
        $result = [];
        foreach ($tatCaPhienBan as $pb) {
            if (!in_array($pb['trang_thai'], $availableStatuses, true)) {
                continue;
            }

            // Decode thuoc_tinh_bien_the from JSON string to array/object
            $thuocTinh = null;
            if (!empty($pb['thuoc_tinh_bien_the'])) {
                $decoded = json_decode($pb['thuoc_tinh_bien_the'], true);
                $thuocTinh = ($decoded !== null) ? $decoded : $pb['thuoc_tinh_bien_the'];
            }

            $result[] = [
                'id'                  => (int) $pb['id'],
                'sku'                 => $pb['sku'],
                'ten_phien_ban'       => $pb['ten_phien_ban'],
                'mau_sac'             => $pb['mau_sac'] ?? null,
                'thuoc_tinh_bien_the' => $thuocTinh,
                'gia_ban'             => (float) $pb['gia_ban'],
                'gia_goc'             => isset($pb['gia_goc']) ? (float) $pb['gia_goc'] : null,
                'so_luong_ton'        => (int) $pb['so_luong_ton'],
                'trang_thai'          => $pb['trang_thai'],
            ];
        }

        $this->jsonResponse(200, $result);
    }

    /**
     * POST /api/ai/cart/add
     *
     * Body (JSON): phien_ban_id (int), so_luong (int), session_id (string, optional),
     *              nguoi_dung_id (int, optional)
     *
     * Requirements: 3.1–3.7
     */
    public function cartAdd(): void
    {
        $this->handleCorsAndAuth();

        require_once dirname(__DIR__, 2) . '/models/entities/PhienBanSanPham.php';
        require_once dirname(__DIR__, 2) . '/models/entities/GioHang.php';
        require_once dirname(__DIR__, 2) . '/models/entities/ChiTietGio.php';

        // --- 1. Parse JSON body ---
        $body = json_decode(file_get_contents('php://input'), true);

        // --- 2. Validate phien_ban_id and so_luong (both must be positive integers) ---
        $phienBanId = isset($body['phien_ban_id']) ? $body['phien_ban_id'] : null;
        $soLuong    = isset($body['so_luong'])    ? $body['so_luong']    : null;

        if (
            $phienBanId === null || !is_numeric($phienBanId) || (int)$phienBanId <= 0 ||
            $soLuong    === null || !is_numeric($soLuong)    || (int)$soLuong    <= 0
        ) {
            $this->jsonResponse(400, ['message' => 'Thiếu hoặc sai định dạng phien_ban_id / so_luong (phải là số nguyên dương).']);
        }

        $phienBanId = (int)$phienBanId;
        $soLuong    = (int)$soLuong;

        // --- 3. Validate at least one of session_id or nguoi_dung_id is present ---
        $sessionId   = isset($body['session_id'])   && $body['session_id']   !== '' ? (string)$body['session_id']   : null;
        $nguoiDungId = isset($body['nguoi_dung_id']) && is_numeric($body['nguoi_dung_id']) && (int)$body['nguoi_dung_id'] > 0
                        ? (int)$body['nguoi_dung_id']
                        : null;

        if ($sessionId === null && $nguoiDungId === null) {
            $this->jsonResponse(400, ['message' => 'Yêu cầu phải có session_id hoặc nguoi_dung_id.']);
        }

        // --- 4. Load variant; 404 if not found or discontinued ---
        $phienBanModel = new PhienBanSanPham();
        $phienBan = $phienBanModel->layPhienBanTheoId($phienBanId);

        if ($phienBan === null || $phienBan['trang_thai'] === 'NGUNG_BAN') {
            $this->jsonResponse(404, ['message' => 'Phiên bản sản phẩm không tồn tại hoặc đã ngừng bán.']);
        }

        // --- 5. Stock check; 422 if insufficient ---
        if (!$phienBanModel->kiemTraTonKho($phienBanId, $soLuong)) {
            $this->jsonResponse(422, ['message' => 'Số lượng tồn kho không đủ để đáp ứng yêu cầu.']);
        }

        // --- 6. Resolve cart (user-scoped or guest-scoped) ---
        $gioHangModel = new GioHang();

        if ($nguoiDungId !== null) {
            $gioHang = $gioHangModel->layHoacTaoGioHangUser($nguoiDungId);
        } else {
            $gioHang = $gioHangModel->layHoacTaoGioHangGuest($sessionId);
        }

        // --- 7. Add / increment item in cart ---
        $chiTietGioModel = new ChiTietGio();
        $chiTietGioModel->themVaoGio($gioHang['id'], $phienBanId, $soLuong);

        // --- 8. Return success response with cart summary ---
        $cartItemCount = $chiTietGioModel->demSanPham($gioHang['id']);
        $tongTien      = $chiTietGioModel->tinhTongTien($gioHang['id']);

        $this->jsonResponse(200, [
            'cart_item_count' => $cartItemCount,
            'tong_tien'       => $tongTien,
        ]);
    }

    /**
     * GET /api/ai/promotions/optimal
     *
     * Query param: tong_tien (non-negative integer, VND)
     * Returns the single active coupon that produces the highest discount amount.
     */
    public function promotionsOptimal(): void
    {
        $this->handleCorsAndAuth();

        require_once dirname(__DIR__, 2) . '/models/entities/MaGiamGia.php';

        // --- Validate tong_tien param ---
        $rawTongTien = $_GET['tong_tien'] ?? null;

        if ($rawTongTien === null || $rawTongTien === '' || !ctype_digit((string) $rawTongTien)) {
            $this->jsonResponse(400, ['message' => 'Tham số tong_tien không hợp lệ. Vui lòng cung cấp số nguyên không âm.']);
        }

        $tongTien = (int) $rawTongTien;

        // --- Query eligible coupons ---
        $maGiamGiaModel = new MaGiamGia();

        // Fetch all active coupons where don_toi_thieu <= tong_tien and date window is valid
        // and usage limit has not been reached
        $safeTongTien = (int) $tongTien; // already validated as non-negative int
        $sql = "SELECT * FROM ma_giam_gia
                WHERE trang_thai = 'HOAT_DONG'
                  AND don_toi_thieu <= {$safeTongTien}
                  AND ngay_bat_dau <= NOW()
                  AND ngay_ket_thuc >= NOW()
                  AND (gioi_han_su_dung IS NULL OR so_luot_da_dung < gioi_han_su_dung)";

        $candidates = $maGiamGiaModel->query($sql);

        // --- Select coupon with highest tien_giam ---
        $bestCoupon  = null;
        $bestTienGiam = -1;

        foreach ($candidates as $voucher) {
            $tienGiam = $maGiamGiaModel->tinhSoTienGiam($voucher, (float) $tongTien);
            if ($tienGiam > $bestTienGiam) {
                $bestTienGiam = $tienGiam;
                $bestCoupon  = $voucher;
            }
        }

        // --- Build response ---
        if ($bestCoupon === null || $bestTienGiam <= 0) {
            $this->jsonResponse(200, [
                'coupon'  => null,
                'message' => 'Không có mã giảm giá phù hợp với đơn hàng của bạn.',
            ]);
        }

        $this->jsonResponse(200, [
            'coupon' => [
                'ma_code'       => $bestCoupon['ma_code'],
                'loai_giam'     => $bestCoupon['loai_giam'],
                'gia_tri_giam'  => (float) $bestCoupon['gia_tri_giam'],
                'giam_toi_da'   => $bestCoupon['giam_toi_da'] !== null ? (float) $bestCoupon['giam_toi_da'] : null,
                'don_toi_thieu' => (float) $bestCoupon['don_toi_thieu'],
                'tien_giam'     => $bestTienGiam,
                'ngay_ket_thuc' => $bestCoupon['ngay_ket_thuc'],
            ],
            'message' => null,
        ]);
    }

    /**
     * POST /api/ai/checkout/apply-coupon
     *
     * Body: { "ma_code": string, "tong_tien": float >= 0 }
     *
     * 400 — ma_code or tong_tien absent / invalid
     * 404 — coupon code not found
     * 422 — coupon exists but is not eligible (inactive, expired, usage limit, below minimum)
     * 200 — { ma_giam_gia_id, ma_code, tien_giam, tong_thanh_toan }
     */
    public function checkoutApplyCoupon(): void
    {
        $this->handleCorsAndAuth();

        require_once dirname(__DIR__, 2) . '/models/entities/MaGiamGia.php';

        // Read and decode JSON body
        $body = json_decode(file_get_contents('php://input'), true);

        // Validate ma_code: must be present and a non-empty string
        $maCode = $body['ma_code'] ?? null;
        if ($maCode === null || !is_string($maCode) || trim($maCode) === '') {
            $this->jsonResponse(400, ['message' => 'Thiếu hoặc không hợp lệ: ma_code (string).']);
        }
        $maCode = trim($maCode);

        // Validate tong_tien: must be present and a non-negative number
        $tongTien = $body['tong_tien'] ?? null;
        if ($tongTien === null || (!is_int($tongTien) && !is_float($tongTien)) || $tongTien < 0) {
            $this->jsonResponse(400, ['message' => 'Thiếu hoặc không hợp lệ: tong_tien (số không âm).']);
        }
        $tongTien = (float) $tongTien;

        $maGiamGiaModel = new MaGiamGia();

        // Look up coupon by code
        $voucher = $maGiamGiaModel->timTheoMaCode($maCode);
        if ($voucher === null) {
            $this->jsonResponse(404, ['message' => 'Mã giảm giá không tồn tại']);
        }

        // Check eligibility
        if (!$maGiamGiaModel->kiemTraHopLe($voucher, $tongTien)) {
            $errorMessage = $maGiamGiaModel->layThongBaoLoiMaGiamGia($maCode, $tongTien)
                ?? 'Mã giảm giá không hợp lệ.';
            $this->jsonResponse(422, ['message' => $errorMessage]);
        }

        // Calculate discount amount
        $tienGiam = $maGiamGiaModel->tinhSoTienGiam($voucher, $tongTien);
        $tongThanhToan = $tongTien - $tienGiam;

        $this->jsonResponse(200, [
            'ma_giam_gia_id'  => (int) $voucher['id'],
            'ma_code'         => $voucher['ma_code'],
            'tien_giam'       => $tienGiam,
            'tong_thanh_toan' => $tongThanhToan,
        ]);
    }

    /**
     * GET /api/ai/orders/{order_code}
     *
     * Query param: nguoi_dung_id (int, optional) — when supplied, verifies order ownership.
     *
     * 404 — order not found
     * 403 — nguoi_dung_id supplied but does not match order owner
     * 200 — full order object with san_pham array
     *
     * Requirements: 6.1–6.4
     */
    public function orderStatus(string $code): void
    {
        $this->handleCorsAndAuth();

        require_once dirname(__DIR__, 2) . '/models/entities/DonHang.php';

        $donHangModel = new DonHang();

        // ── 1. Load order by ma_don_hang ──────────────────────────────────────
        $safeCode = addslashes($code);
        $rows = $donHangModel->query(
            "SELECT * FROM don_hang WHERE ma_don_hang = '{$safeCode}' LIMIT 1"
        );

        if (empty($rows)) {
            $this->jsonResponse(404, ['message' => 'Không tìm thấy đơn hàng với mã này.']);
        }

        $donHang = $rows[0];

        // ── 2. Ownership check when nguoi_dung_id is supplied ─────────────────
        $nguoiDungIdRaw = $_GET['nguoi_dung_id'] ?? '';
        if ($nguoiDungIdRaw !== '') {
            if (!ctype_digit((string) $nguoiDungIdRaw) || (int) $nguoiDungIdRaw <= 0) {
                $this->jsonResponse(400, ['message' => 'Tham số nguoi_dung_id không hợp lệ: phải là số nguyên dương.']);
            }

            $nguoiDungId = (int) $nguoiDungIdRaw;
            if ((int) $donHang['nguoi_dung_id'] !== $nguoiDungId) {
                $this->jsonResponse(403, ['message' => 'Đơn hàng này không thuộc về bạn.']);
            }
        }

        // ── 3. Load line items ────────────────────────────────────────────────
        $danhSachSanPham = $donHangModel->laySanPhamTrongDon((int) $donHang['id']);

        $sanPham = array_map(static function (array $item): array {
            return [
                'ten_san_pham'          => $item['ten_san_pham'],
                'ten_phien_ban'         => $item['ten_phien_ban'],
                'so_luong'              => (int) $item['so_luong'],
                'gia_tai_thoi_diem_mua' => (float) $item['gia_tai_thoi_diem_mua'],
            ];
        }, $danhSachSanPham);

        // ── 4. Return order response ──────────────────────────────────────────
        $this->jsonResponse(200, [
            'ma_don_hang'       => $donHang['ma_don_hang'],
            'trang_thai'        => $donHang['trang_thai'],
            'tong_thanh_toan'   => (float) $donHang['tong_thanh_toan'],
            'ngay_tao'          => $donHang['ngay_tao'],
            'ten_nguoi_nhan'    => $donHang['ten_nguoi_nhan'],
            'sdt_nguoi_nhan'    => $donHang['sdt_nguoi_nhan'],
            'dia_chi_giao_hang' => $donHang['dia_chi_giao_hang'],
            'san_pham'          => $sanPham,
        ]);
    }
}
