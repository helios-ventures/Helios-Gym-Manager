<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


session_start();
require_once('../config/config.php');
require_once('../config/checklogin.php');
require_once('../config/dbcontroller.php');
require_once('../config/codeGen.php');
require_once('../vendor/autoload.php');
check_login();

/* ---------- Initiate DB Controller ---------- */
$store = $_GET['store'];
$wholesale_enabled_store = '5ee2531595a2e9cd6a4bbe98fbbb8528a7d42ce0c95f79c62a';

$allow_wholesale = ($store === $wholesale_enabled_store);

$db_handle = new DBController();

/* ---------- Handle Cart Actions ---------- */
if (!empty($_GET["action"])) {
    switch ($_GET["action"]) {

        /* ---------- Add Product to Cart ---------- */
        case "add":
    if (!empty($_POST["quantity"]) && isset($_GET["product_id"])) {
        $qty = (int) $_POST["quantity"];
        $product_id = $_GET["product_id"];

        // Reserve stock atomically
        $sql = "
            UPDATE products
            SET reserved_stock = reserved_stock + ?
            WHERE product_id = ? AND (product_quantity - reserved_stock) >= ?
        ";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isi", $qty, $product_id, $qty); // int, string, int
        $stmt->execute();

        if ($stmt->affected_rows === 0) {
            $_SESSION['error'] = 'Insufficient stock available';
            header("Location: pos?store=$store");
            exit();
        }
        $stmt->close();

        // Fetch product details after reservation
        $productByCode = $db_handle->runQuery("
            SELECT *,
                   (product_quantity - reserved_stock) AS available_stock
            FROM products
            WHERE product_id = '$product_id'
        ");

        $Discount   = !empty($_POST['Discount']) ? (float)$_POST['Discount'] : 0.0;
        $price_mode = $_POST['price_mode'] ?? 'retail';

        // Decide unit price based on mode
        $wholesale_price = (float)($productByCode[0]['product_wholesale_price'] ?? 0);

		if ($allow_wholesale && $price_mode === 'wholesale' && $wholesale_price > 0) {
			$unit_price = $wholesale_price;
		} else {
			$price_mode = 'retail'; // force override
			$unit_price = $productByCode[0]['product_sale_price'];
		}

        // Each add = new row in cart
        $line_id = uniqid('line_', true);
        $_SESSION["cart_item"][$line_id] = [
            'line_id'               => $line_id,
            'product_id'            => $productByCode[0]["product_id"],
            'product_code'          => $productByCode[0]["product_code"],
            'product_name'          => $productByCode[0]["product_name"],
            'quantity'              => $qty,
            'product_sale_price'    => ($unit_price - $Discount),
            'product_description'   => $productByCode[0]["product_description"],
            'product_quantity_limit'=> $productByCode[0]["product_quantity_limit"],
            'Discount'              => $Discount,
            'price_mode'            => $price_mode // keep track of mode in cart
        ];
    }
    break;


        /* ---------- Remove Product from Cart ---------- */
        case "remove":
            if (!empty($_SESSION["cart_item"]) && isset($_GET["line_id"])) {
                $remove_line_id = $_GET["line_id"];

                if (isset($_SESSION["cart_item"][$remove_line_id])) {
                    $item = $_SESSION["cart_item"][$remove_line_id];

                    // Safely release reserved stock, prevent negative
                    $sql = "UPDATE products SET reserved_stock = GREATEST(reserved_stock - ?, 0) WHERE product_id = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("is", $item['quantity'], $item['product_id']);
                    $stmt->execute();

                    unset($_SESSION["cart_item"][$remove_line_id]);
                }

                if (empty($_SESSION["cart_item"])) unset($_SESSION["cart_item"]);

                header("Location: pos?store=$store");
                exit();
            }
            break;

        /* ---------- Empty Cart ---------- */
        case "empty":
            if (!empty($_SESSION["cart_item"])) {
                foreach ($_SESSION["cart_item"] as $item) {
                    $sql = "UPDATE products SET reserved_stock = GREATEST(reserved_stock - ?, 0) WHERE product_id = ?";
                    $stmt = $mysqli->prepare($sql);
                    $stmt->bind_param("is", $item['quantity'], $item['product_id']);
                    $stmt->execute();
                }
            }
            unset($_SESSION["cart_item"]);
            header("Location: pos?store=$store");
            exit();
            break;
    }
}


//* ---------- Post Cash Sale ---------- */
if (isset($_POST['add_sale']) && !empty($_SESSION["cart_item"])) {
    $sale_payment_method       = $_POST['sale_payment_method'];
    $cart_products             = $_SESSION["cart_item"];
    $sale_transaction_ref      = $_POST['sale_transaction_ref'] ?? null;
    $sale_credit_expected_date = $_POST['sale_credit_expected_date'] ?? null;

    $mysqli->begin_transaction();
    try {
        include('../helpers/cashsale_helper.php'); // runs once

        $mysqli->commit();

        unset($_SESSION["cart_item"]);
        $_SESSION['success'] = "Sale posted successfully";
        header("Location: pos_receipt?store=$store&receipt=$sale_receipt_no");
        exit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['error'] = "Sale failed: " . $e->getMessage();
        header("Location: pos?store=$store");
        exit();
    }
}


/* ---------- Hold Sale ---------- */
if (isset($_POST['hold_sale'])) {
    if (empty($_SESSION['cart_item'])) {
        $_SESSION['error'] = "Cart is empty";
        header("Location: pos?store=$store");
        exit();
    }

    $cart_products    = $_SESSION["cart_item"];
    $hold_sale_number = "HS-" . time() . rand(1000, 9999);

    $mysqli->begin_transaction();
    try {
        $sql = "
            INSERT INTO hold_sales (
                hold_sale_number,
                product_id,
                product_name,
                product_code,
                quantity,
                product_sale_price,
                product_description,
                product_quantity_limit,
                Discount,
                price_mode
            ) VALUES (?,?,?,?,?,?,?,?,?,?)
        ";
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception($mysqli->error);

        foreach ($cart_products as $item) {
            $stmt->bind_param(
                "sssssdssss",
                $hold_sale_number,
                $item['product_id'],
                $item['product_name'],
                $item['product_code'],
                $item['quantity'],
                $item['product_sale_price'],
                $item['product_description'],
                $item['product_quantity_limit'],
                $item['Discount'],
                $item['price_mode']   // preserve mode
            );
            $stmt->execute();
        }

        $mysqli->commit();

        // Do not touch reserved stock here
        unset($_SESSION["cart_item"]);
        $_SESSION['success'] = "Sale held successfully (Hold Sale #{$hold_sale_number})";
        header("Location: pos?store=$store");
        exit();
    } catch (Exception $e) {
        $mysqli->rollback();
        $err = "Failed to hold sale: " . $e->getMessage();
    }
}

/* ---------- Restore Hold Sale ---------- */
if (isset($_POST['restore_sale'])) {
    $hold_sale_number = mysqli_real_escape_string($mysqli, $_POST['hold_sale_number']);
    $items_sql = mysqli_query($mysqli, "SELECT * FROM hold_sales WHERE hold_sale_number = '{$hold_sale_number}'");
    $itemArray = [];

    try {
        while ($row = mysqli_fetch_assoc($items_sql)) {
            $itemArray[] = $row;
        }

        // Remove hold sale record
        $sql = "DELETE FROM hold_sales WHERE hold_sale_number = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("s", $hold_sale_number);
        $stmt->execute();

        $_SESSION["cart_item"] = $itemArray;
        $_SESSION['success'] = "Sale Number #$hold_sale_number restored to Cart";
        header("Location: pos?store=$store");
        exit();
    } catch (Exception $e) {
        $err = "Failed to restore hold sale: " . $e->getMessage();
    }
}

require_once('../partials/head.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>POS Module</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html, body {
            width: 100%;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            color: #333;
        }

        body {
            padding-top: 60px;
            padding-bottom: 60px;
        }

        /* ===== HEADER ===== */
        .nk-app-root {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .nk-wrap {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .nk-content {
            flex: 1;
            padding: 12px;
        }

        .nk-block-head {
            margin-bottom: 16px;
        }

        .nk-block-title {
            font-size: 24px;
            font-weight: 600;
            margin: 0;
        }

        /* ===== SEARCH BAR ===== */
        .search-container {
            margin-bottom: 16px;
        }

        #Product_Search {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.2s;
        }

        #Product_Search:focus {
            outline: none;
            border-color: #28a745;
        }

        /* ===== MAIN LAYOUT ===== */
        .pos-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            max-width: 100%;
        }

        /* ===== PRODUCT LIST ===== */
        .products-panel {
            background: white;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 12px;
            max-height: 60vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 10px;
        }

        .product-card {
            background: #f9f9f9;
            border: 2px solid #007bff;
            border-radius: 8px;
            padding: 10px;
            transition: all 0.2s ease;
        }

        .product-card:active {
            transform: scale(0.98);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .product-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .product-info {
            font-size: 11px;
            margin-bottom: 8px;
            color: #666;
        }

        .stock-status {
            font-size: 11px;
            margin-bottom: 8px;
            padding: 4px;
            border-radius: 4px;
            text-align: center;
        }

        .stock-danger {
            background: #fee;
            color: #c33;
        }

        .stock-warning {
            background: #ffeaa7;
            color: #d63031;
        }

        .stock-success {
            background: #d4edda;
            color: #155724;
        }

        /* Price display */
        .price-display {
            font-size: 12px;
            margin-bottom: 8px;
            font-weight: 600;
            color: #28a745;
        }

        /* Form inputs in product card */
        .product-card select,
        .product-card input {
            width: 100%;
            margin-bottom: 6px;
            padding: 6px 8px;
            font-size: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .product-card button {
            width: 100%;
            padding: 8px;
            font-size: 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }

        .product-card button:active {
            background: #1e7e34;
        }

        /* ===== CART PANEL ===== */
        .cart-panel {
            background: white;
            border: 2px solid #28a745;
            border-radius: 8px;
            padding: 12px;
            max-height: 65vh;
            display: flex;
            flex-direction: column;
        }

        .cart-header {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 12px;
            text-align: center;
        }

        .cart-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .cart-actions button,
        .cart-actions a {
            flex: 1;
            min-width: 100px;
            padding: 10px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .btn-suspend {
            background: #007bff;
            color: white;
        }

        .btn-suspend:active {
            background: #0056b3;
        }

        .btn-clear {
            background: #dc3545;
            color: white;
        }

        .btn-clear:active {
            background: #a02622;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:active {
            background: #0056b3;
        }

        /* Cart table */
        .cart-items {
            flex: 1;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .cart-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .cart-table th {
            background: #f5f5f5;
            padding: 8px 6px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #ddd;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .cart-table td {
            padding: 8px 6px;
            border-bottom: 1px solid #eee;
            word-break: break-word;
        }

        .cart-table th:nth-child(2),
        .cart-table th:nth-child(3),
        .cart-table th:nth-child(4),
        .cart-table th:nth-child(5),
        .cart-table th:nth-child(6),
        .cart-table td:nth-child(2),
        .cart-table td:nth-child(3),
        .cart-table td:nth-child(4),
        .cart-table td:nth-child(5),
        .cart-table td:nth-child(6) {
            text-align: right;
        }

        .cart-table .delete-btn {
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            white-space: nowrap;
        }

        .cart-table .delete-btn:active {
            background: #a02622;
        }

        /* Cart totals */
        .cart-totals {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 12px;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .total-row:last-child {
            margin-bottom: 0;
            font-size: 16px;
            border-top: 2px solid #ddd;
            padding-top: 8px;
            color: #28a745;
        }

        /* Empty cart message */
        .empty-cart {
            background: #f5f5f5;
            padding: 24px 12px;
            text-align: center;
            color: #dc3545;
            font-weight: 600;
        }

        /* ===== MODAL ===== */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .modal.show {
            display: flex;
            align-items: flex-end;
        }

        .modal-content {
            background: white;
            width: 100%;
            margin: auto 0 0 0;
            border-radius: 12px 12px 0 0;
            max-height: 90vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        .modal-header {
            padding: 16px;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }

        .modal-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: -8px;
        }

        .modal-body {
            padding: 16px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #333;
        }

        .form-group input,
        .form-group select {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 2px rgba(40, 167, 69, 0.1);
        }

        .hidden-field {
            display: none;
        }

        .modal-footer {
            padding: 16px;
            border-top: 1px solid #ddd;
            display: flex;
            gap: 8px;
        }

        .modal-footer button {
            flex: 1;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-save {
            background: #28a745;
            color: white;
        }

        .btn-save:active {
            background: #1e7e34;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:active {
            background: #545b62;
        }

        /* ===== TABLETS AND LARGER ===== */
        @media (min-width: 768px) {
            body {
                padding-top: 70px;
                padding-bottom: 0;
            }

            .nk-content {
                padding: 20px;
            }

            .pos-container {
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .products-panel,
            .cart-panel {
                max-height: calc(100vh - 150px);
            }

            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 12px;
            }

            .product-card {
                padding: 12px;
            }

            .product-title {
                font-size: 14px;
            }

            .modal-content {
                width: 600px;
                margin: auto;
                border-radius: 8px;
                max-height: 80vh;
            }

            .modal.show {
                align-items: center;
            }

            .form-row {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        /* ===== LANDSCAPE MODE ===== */
        @media (orientation: landscape) and (max-height: 500px) {
            body {
                padding-top: 50px;
                padding-bottom: 50px;
            }

            .products-panel,
            .cart-panel {
                max-height: calc(100vh - 100px);
            }

            .nk-block-title {
                font-size: 18px;
            }
        }

        /* ===== UTILITIES ===== */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .mt-2 {
            margin-top: 12px;
        }

        .mb-2 {
            margin-bottom: 12px;
        }

        hr {
            border: none;
            border-top: 1px solid #ddd;
            margin: 12px 0;
        }

        /* Alert messages */
        .alert {
            padding: 12px;
            margin-bottom: 12px;
            border-radius: 4px;
            font-size: 13px;
        }

        .alert-danger {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>

<body class="nk-body npc-invest bg-lighter">
    <div class="nk-app-root">
        <!-- Header -->
        <?php require_once('../partials/pos_header.php'); ?>

        <!-- Main Content -->
        <div class="nk-wrap">
            <div class="nk-content">
                <div class="nk-content-inner">
                    <div class="nk-content-body">
                        <div class="nk-block-head">
                            <h2 class="nk-block-title">POS Module</h2>
                        </div>

                        <!-- Search Bar -->
                        <div class="search-container">
                            <input class="form-control" type="text" id="Product_Search" onkeyup="FilterFunction()" placeholder="🔍 Search Products">
                        </div>

                        <!-- Main Layout -->
                        <div class="pos-container">
                            <!-- Products Panel -->
                            <div class="products-panel">
                                <div class="products-grid" id="product-grid">
                                    <?php
                                    $store = $_GET['store'];
                                    $product_array = $db_handle->runQuery("
                                        SELECT 
                                            p.*,
                                            (p.product_quantity - p.reserved_stock) AS available_stock,
                                            rc.*
                                        FROM products p
                                        JOIN receipt_customization rc 
                                            ON p.product_store_id = rc.receipt_store_id
                                        WHERE p.product_status = 'active'
                                          AND p.product_store_id = '{$store}'
                                        ORDER BY p.product_name ASC
                                    ");

                                    if (!empty($product_array)) {
                                        foreach ($product_array as $key => $value) {
                                            if ($product_array[$key]['allow_discounts'] == 'true') {
                                                $available_qty = $product_array[$key]["available_stock"];
                                                $qty_limit = $product_array[$key]["product_quantity_limit"];
                                                $product_name = htmlspecialchars($product_array[$key]["product_name"]);
                                                $product_id = $product_array[$key]["product_id"];
                                    ?>
                                        <div class="product-card Product_Name">
                                            <h5 class="product-title"><?php echo $product_name; ?></h5>

                                            <!-- Stock Status -->
                                            <?php if ($available_qty <= 0): ?>
                                                <div class="stock-status stock-danger">⚠️ Out of Stock</div>
                                            <?php elseif ($available_qty <= $qty_limit): ?>
                                                <div class="stock-status stock-warning">📦 Low: <?php echo $available_qty; ?></div>
                                            <?php else: ?>
                                                <div class="stock-status stock-success">✓ <?php echo $available_qty; ?> left</div>
                                            <?php endif; ?>

                                            <!-- Prices -->
                                            <div class="price-display">
                                                <div>Ksh <?php echo number_format($product_array[$key]["product_sale_price"], 2); ?></div>
                                                <?php
												$wholesale_price = (float)($product_array[$key]["product_wholesale_price"] ?? 0);
												if ($wholesale_price > 0):
												?>
													<div style="font-size: 10px; color: #666; margin-top: 2px;">
														WS: Ksh <?php echo number_format($wholesale_price, 2); ?>
													</div>
												<?php endif; ?>
                                            </div>

                                            <!-- Form -->
                                            <form method="post" action="pos?store=<?php echo $store; ?>&action=add&product_id=<?php echo $product_id; ?>">
                                                <?php
												$wholesale_price = (float)($product_array[$key]["product_wholesale_price"] ?? 0);
												?>

												<?php if ($allow_wholesale && $wholesale_price > 0): ?>
													<select name="price_mode" class="price-mode-select">
														<option value="retail">Retail</option>
														<option value="wholesale">Wholesale</option>
													</select>
												<?php else: ?>
													<input type="hidden" name="price_mode" value="retail">
												<?php endif; ?>

                                                <input type="number" name="quantity" min="1" max="<?php echo $available_qty; ?>" value="1" class="qty-input" />

                                                <?php if ($product_array[$key]['allow_discounts'] == 'true'): ?>
                                                    <input type="number" name="Discount" placeholder="Discount (KSH)" step="0.01" class="discount-input" />
                                                <?php endif; ?>

                                                <button type="submit" <?php echo $available_qty <= 0 ? 'disabled' : ''; ?> style="<?php echo $available_qty <= 0 ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                                                    Add to Cart
                                                </button>
                                            </form>
                                        </div>
                                    <?php
                                            } else {
                                                // Products without discounts
                                                $available_qty = $product_array[$key]["available_stock"];
                                                $qty_limit = $product_array[$key]["product_quantity_limit"];
                                                $product_name = htmlspecialchars($product_array[$key]["product_name"]);
                                                $product_id = $product_array[$key]["product_id"];
                                    ?>
                                        <div class="product-card Product_Name">
                                            <h5 class="product-title"><?php echo $product_name; ?></h5>

                                            <!-- Stock Status -->
                                            <?php if ($available_qty <= 0): ?>
                                                <div class="stock-status stock-danger">⚠️ Out of Stock</div>
                                            <?php elseif ($available_qty <= $qty_limit): ?>
                                                <div class="stock-status stock-warning">📦 Low: <?php echo $available_qty; ?></div>
                                            <?php else: ?>
                                                <div class="stock-status stock-success">✓ <?php echo $available_qty; ?> left</div>
                                            <?php endif; ?>

                                            <!-- Prices -->
                                            <div class="price-display">
                                                <div>Ksh <?php echo number_format($product_array[$key]["product_sale_price"], 2); ?></div>
                                                <?php if ($allow_wholesale && (float)$product_array[$key]["product_wholesale_price"] > 0): ?>
                                                    <div style="font-size: 10px; color: #666; margin-top: 2px;">
                                                        WS: Ksh <?php echo number_format($product_array[$key]["product_wholesale_price"], 2); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Form -->
                                            <form method="post" action="pos?store=<?php echo $store; ?>&action=add&product_id=<?php echo $product_id; ?>">
                                                <?php if ($allow_wholesale && (float)$product_array[$key]["product_wholesale_price"] > 0): ?>
                                                    <select name="price_mode" class="price-mode-select">
                                                        <option value="retail">Retail</option>
                                                        <option value="wholesale">Wholesale</option>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="hidden" name="price_mode" value="retail">
                                                <?php endif; ?>

                                                <input type="number" name="quantity" min="1" max="<?php echo $available_qty; ?>" value="1" class="qty-input" />

                                                <button type="submit" <?php echo $available_qty <= 0 ? 'disabled' : ''; ?> style="<?php echo $available_qty <= 0 ? 'opacity: 0.5; cursor: not-allowed;' : ''; ?>">
                                                    Add to Cart
                                                </button>
                                            </form>
                                        </div>
                                    <?php
                                            }
                                        }
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- Cart Panel -->
                            <div class="cart-panel">
                                <?php if (isset($_SESSION["cart_item"]) && !empty($_SESSION["cart_item"])): ?>
                                    <div class="cart-header">🛒 Cart Items</div>

                                    <div class="cart-actions">
                                        <form method="POST" style="flex: 1;">
                                            <button name="hold_sale" type="submit" class="btn-suspend">⏸ Suspend</button>
                                        </form>
                                        <a href="pos?store=<?php echo $store; ?>&action=empty" class="btn-clear">🗑️ Clear</a>
                                    </div>

                                    <div class="cart-items">
                                        <table class="cart-table">
                                            <thead>
                                                <tr>
                                                    <th>Item</th>
                                                    <th>QTY</th>
                                                    <th>Price</th>
                                                    <th>Disc</th>
                                                    <th>Mode</th>
                                                    <th>Total</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                $total_quantity = 0;
                                                $total_price = 0;

                                                if (!empty($_SESSION["cart_item"])) {
                                                    foreach (array_reverse($_SESSION["cart_item"], true) as $line_id => $item) {
                                                        $item_price = $item["quantity"] * $item["product_sale_price"];
                                                        $total_quantity += $item["quantity"];
                                                        $total_price += $item_price;
                                                ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars(substr($item["product_name"], 0, 12)); ?></td>
                                                        <td><?php echo $item["quantity"]; ?></td>
                                                        <td>Ksh <?php echo number_format($item["product_sale_price"] + $item["Discount"], 0); ?></td>
                                                        <td>Ksh <?php echo number_format($item["Discount"], 0); ?></td>
                                                        <td><?php echo ucfirst($item["price_mode"]); ?></td>
                                                        <td>Ksh <?php echo number_format($item_price, 0); ?></td>
                                                        <td>
                                                            <a href="pos?store=<?php echo $store; ?>&action=remove&line_id=<?php echo $line_id; ?>" class="delete-btn">✕</a>
                                                        </td>
                                                    </tr>
                                                <?php
                                                    }
                                                }
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="cart-totals">
                                        <div class="total-row">
                                            <span>Subtotal:</span>
                                            <span>Ksh <?php echo number_format($total_price, 2); ?></span>
                                        </div>
                                        <div class="total-row">
                                            <span>Items: <?php echo $total_quantity; ?></span>
                                            <span>Ksh <?php echo number_format($total_price, 2); ?></span>
                                        </div>
                                    </div>

                                    <button class="btn-primary" onclick="openCheckoutModal()" style="width: 100%; padding: 14px; font-size: 14px; font-weight: 600;">
                                        💳 Checkout
                                    </button>

                                <?php else: ?>
                                    <div class="empty-cart">
                                        🛒 Cart is Empty<br>
                                        <small>Add products to get started</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <?php require_once('../partials/pos_footer.php'); ?>
    </div>

    <!-- Checkout Modal -->
    <div class="modal" id="checkout_modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Checkout</h4>
                <button type="button" class="close-btn" onclick="closeCheckoutModal()">&times;</button>
            </div>

            <div class="modal-body">
                <?php
                $ret = "SELECT * FROM receipt_customization r
                INNER JOIN payment_settings p ON p.payment_settings_store_id = r.receipt_store_id
                WHERE r.receipt_store_id = '{$store}'";
                $stmt = $mysqli->prepare($ret);
                $stmt->execute();
                $res = $stmt->get_result();

                while ($settings = $res->fetch_object()) {
                    if (isset($_SESSION["cart_item"])) {
                        $total_price = 0;
                        foreach ($_SESSION["cart_item"] as $item) {
                            $total_price += $item["quantity"] * $item["product_sale_price"];
                        }
                    }
                ?>
                    <form method="post" id="checkout-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Customer Name *</label>
                                <input type="text" required name="sale_customer_name" />
                            </div>

                            <div class="form-group">
                                <label>Phone Number *</label>
                                <input type="tel" required name="sale_customer_phoneno" />
                            </div>

                            <div class="form-group">
                                <label>Payment Method *</label>
                                <select required name="sale_payment_method" id="paymentMethod" onchange="togglePaymentFields()">
                                    <option value="">-- Select --</option>
                                    <option value="Cash">💵 Cash</option>
                                    <option value="Mobile Payment">📱 Mobile Payment</option>
                                    <option value="Credit">💳 Credit</option>
                                </select>
                            </div>

                            <div class="form-group hidden-field" id="SaleTXN">
                                <label>Transaction Ref</label>
                                <input type="text" name="sale_transaction_ref" />
                            </div>

                            <div class="form-group hidden-field" id="SaleExpectedPaymentDate">
                                <label>Expected Payment Date</label>
                                <input type="date" name="sale_credit_expected_date" />
                            </div>

                            <input type="hidden" name="total_payable_price" value="<?php echo $total_price; ?>">
                        </div>

                        <div class="modal-footer">
                            <button type="submit" name="add_sale" class="btn-save">✓ Save Sale</button>
                            <button type="button" class="btn-cancel" onclick="closeCheckoutModal()">Cancel</button>
                        </div>
                    </form>
                <?php
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <?php require_once('../partials/scripts.php'); ?>
    <?php require_once('../partials/filter_js.php'); ?>

    <script>
        function openCheckoutModal() {
            document.getElementById('checkout_modal').classList.add('show');
        }

        function closeCheckoutModal() {
            document.getElementById('checkout_modal').classList.remove('show');
        }

        function togglePaymentFields() {
            const method = document.getElementById('paymentMethod').value;
            const txnField = document.getElementById('SaleTXN');
            const dateField = document.getElementById('SaleExpectedPaymentDate');

            if (method === 'Mobile Payment') {
                txnField.classList.remove('hidden-field');
            } else {
                txnField.classList.add('hidden-field');
            }

            if (method === 'Credit') {
                dateField.classList.remove('hidden-field');
            } else {
                dateField.classList.add('hidden-field');
            }
        }

        function validateQty(input) {
            const max = parseInt(input.max, 10);
            const val = parseInt(input.value, 10);

            if (val > max) {
                alert("You can't sell more than available (" + max + ")");
                input.value = max;
            }
            if (val < 1) {
                alert("Quantity must be at least 1");
                input.value = 1;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('checkout_modal');
            if (event.target == modal) {
                closeCheckoutModal();
            }
        }
    </script>

</body>

</html>