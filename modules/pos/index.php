<?php

	// ---------- Production error settings ----------
	ini_set('display_errors', 1);
	error_reporting(E_ALL);
/**
 * Point of Sale - New Sale
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;

Auth::requirePermission('pos', 'create');

$pageTitle = 'New Sale';
$pageDescription = 'Process subscription renewals and product sales';

// Process sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_sale'])) {
    $items = json_decode($_POST['items_json'] ?? '[]', true);
    $memberId = intval($_POST['member_id'] ?? 0);
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $subtotal = floatval($_POST['subtotal'] ?? 0);
    $tax = floatval($_POST['tax'] ?? 0);
    $discount = floatval($_POST['discount'] ?? 0);
    $total = floatval($_POST['total'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($items)) {
        Session::setFlash('danger', 'Please add items to the cart');
    } else {
        try {
            Database::beginTransaction();
            
            $invoiceNumber = Helper::generateInvoiceNumber();
            
            // Insert sale
            $saleId = Database::insert(
                "INSERT INTO sales (invoice_number, member_id, customer_name, customer_phone, subtotal, tax_amount, discount_amount, total_amount, payment_method, notes, sold_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$invoiceNumber, $memberId ?: null, $customerName ?: null, $customerPhone ?: null, $subtotal, $tax, $discount, $total, $paymentMethod, $notes, Auth::id()]
            );
            
            // Insert sale items
            foreach ($items as $item) {
                Database::insert(
                    "INSERT INTO sale_items (sale_id, product_id, plan_id, item_name, quantity, unit_price, total_price) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $saleId,
                        $item['type'] === 'product' ? $item['id'] : null,
                        $item['type'] === 'subscription' ? $item['id'] : null,
                        $item['name'],
                        $item['qty'],
                        $item['price'],
                        $item['total']
                    ]
                );
                
                // Update stock for products
                if ($item['type'] === 'product') {
                    Database::execute(
                        "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?",
                        [$item['qty'], $item['id']]
                    );
                }
                
                // Create/update subscription for subscription sales
                if ($item['type'] === 'subscription' && $memberId) {
                    $plan = Database::fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$item['id']]);
                    if ($plan) {
                        $startDate = date('Y-m-d');
                        $endDate = date('Y-m-d', strtotime("+{$plan['duration_days']} days"));
                        
                        // Cancel existing active subscription
                        Database::execute(
                            "UPDATE member_subscriptions SET status = 'expired' WHERE member_id = ? AND status = 'active'",
                            [$memberId]
                        );
                        
                        // Create new subscription
                        Database::insert(
                            "INSERT INTO member_subscriptions (member_id, plan_id, start_date, end_date, amount_paid, payment_method, status, created_by) 
                             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)",
                            [$memberId, $item['id'], $startDate, $endDate, $item['total'], $paymentMethod, Auth::id()]
                        );
                        
                        // Update member expiry
                        Database::execute(
                            "UPDATE members SET expiry_date = ?, status = 'active' WHERE id = ?",
                            [$endDate, $memberId]
                        );
                    }
                }
            }
            
            // Insert payment record
            Database::insert(
                "INSERT INTO payments (sale_id, amount, payment_method, status) VALUES (?, ?, ?, 'completed')",
                [$saleId, $total, $paymentMethod]
            );
            
            Database::commit();
            
            Auth::logActivity('sale_create', "Created sale {$invoiceNumber} for " . Helper::money($total));
            
            Session::setFlash('success', 'Sale completed! Invoice: ' . $invoiceNumber);
            header('Location: ' . BASE_URL . '/modules/pos/invoice.php?id=' . $saleId);
            exit;
            
        } catch (\Exception $e) {
            Database::rollback();
            Session::setFlash('danger', 'Error processing sale: ' . $e->getMessage());
        }
    }
}

// Get products
$products = Database::fetchAll(
    "SELECT p.*, c.name as category_name FROM products p 
     LEFT JOIN product_categories c ON p.category_id = c.id 
     WHERE p.status = 'active' AND (p.stock_quantity > 0 OR p.is_subscription = 1)
     ORDER BY c.name, p.name"
);

// Get subscription plans
$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price");

// Get members for quick select
$members = Database::fetchAll("SELECT id, first_name, last_name, member_code, phone FROM members WHERE status = 'active' ORDER BY first_name LIMIT 100");

// Tax rate
$taxRate = floatval(Auth::getSetting('tax_rate', 16));
$taxEnabled = Auth::getSetting('tax_enabled', '1') === '1';

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6" x-data="posApp()">
    
    <!-- Products Panel -->
    <div class="lg:col-span-2 space-y-4">
        
        <!-- Category Tabs -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <div class="flex gap-2 overflow-x-auto pb-2">
                <button onclick="filterProducts('all')" class="cat-tab px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-medium whitespace-nowrap transition-colors" data-cat="all">
                    All Items
                </button>
                <button onclick="filterProducts('subscription')" class="cat-tab px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium whitespace-nowrap hover:bg-gray-200 transition-colors" data-cat="subscription">
                    Subscriptions
                </button>
                <?php 
                $categories = [];
                foreach ($products as $p) {
                    if ($p['category_name'] && !isset($categories[$p['category_id']])) {
                        $categories[$p['category_id']] = $p['category_name'];
                    }
                }
                foreach ($categories as $catId => $catName): 
                ?>
                    <button onclick="filterProducts('cat_<?php echo $catId; ?>')" class="cat-tab px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm font-medium whitespace-nowrap hover:bg-gray-200 transition-colors" data-cat="cat_<?php echo $catId; ?>">
                        <?php echo htmlspecialchars($catName); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Products Grid -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3" id="productsGrid">
                <!-- Subscription Plans -->
                <?php foreach ($plans as $plan): ?>
                    <button onclick="addToCart(<?php echo $plan['id']; ?>, '<?php echo addslashes($plan['name']); ?>', <?php echo $plan['price']; ?>, 'subscription')"
                            class="product-card cat_subscription p-4 border-2 border-purple-100 rounded-xl hover:border-purple-300 hover:bg-purple-50 transition-all text-left group"
                            data-cat="subscription">
                        <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center mb-3 group-hover:bg-purple-200 transition-colors">
                            <i data-lucide="credit-card" class="w-5 h-5 text-purple-600"></i>
                        </div>
                        <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($plan['name']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $plan['duration_days']; ?> days</p>
                        <p class="text-lg font-bold text-purple-700 mt-2"><?php echo Helper::money($plan['price']); ?></p>
                    </button>
                <?php endforeach; ?>
                
                <!-- Products -->
                <?php foreach ($products as $product): ?>
                    <?php if (!$product['is_subscription']): ?>
                        <button onclick="addToCart(<?php echo $product['id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['unit_price']; ?>, 'product', <?php echo $product['stock_quantity']; ?>)
                                <?php echo $product['stock_quantity'] <= 0 ? 'disabled' : ''; ?>"
                                class="product-card cat_<?php echo $product['category_id']; ?> p-4 border-2 border-gray-100 rounded-xl hover:border-blue-300 hover:bg-blue-50 transition-all text-left group <?php echo $product['stock_quantity'] <= 0 ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                                data-cat="cat_<?php echo $product['category_id']; ?>">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center mb-3 group-hover:bg-blue-200 transition-colors">
                                <i data-lucide="box" class="w-5 h-5 text-blue-600"></i>
                            </div>
                            <p class="font-medium text-gray-900 text-sm"><?php echo htmlspecialchars($product['name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($product['sku']); ?></p>
                            <div class="flex items-center justify-between mt-2">
                                <p class="text-lg font-bold text-blue-700"><?php echo Helper::money($product['unit_price']); ?></p>
                                <span class="text-xs <?php echo $product['stock_quantity'] <= $product['low_stock_threshold'] ? 'text-red-500' : 'text-gray-400'; ?>">
                                    <?php echo $product['stock_quantity']; ?> in stock
                                </span>
                            </div>
                        </button>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Cart & Checkout -->
    <div class="space-y-4">
        <!-- Customer Info -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <h4 class="font-medium text-gray-900 mb-3">Customer</h4>
            <select id="memberSelect" onchange="selectMember(this.value)"
                    class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 mb-2">
                <option value="">Walk-in Customer</option>
                <?php foreach ($members as $m): ?>
                    <option value="<?php echo $m['id']; ?>" data-name="<?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?>" data-phone="<?php echo $m['phone']; ?>">
                        <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['member_code'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="customerName" placeholder="Customer name" 
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-2 focus:ring-2 focus:ring-blue-500">
            <input type="text" id="customerPhone" placeholder="Phone number" 
                   class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
        </div>
        
        <!-- Cart -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <div class="flex items-center justify-between mb-3">
                <h4 class="font-medium text-gray-900">Cart</h4>
                <button onclick="clearCart()" class="text-xs text-red-600 hover:text-red-700">Clear</button>
            </div>
            
            <div id="cartItems" class="space-y-2 max-h-64 overflow-y-auto mb-4">
                <div class="text-center py-6 text-gray-400 text-sm">Cart is empty</div>
            </div>
            
            <!-- Cart Summary -->
            <div class="border-t border-gray-100 pt-3 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Subtotal</span>
                    <span class="font-medium" id="cartSubtotal">Ksh 0.00</span>
                </div>
                <?php if ($taxEnabled): ?>
                <div class="flex justify-between text-sm">
                    <span class="text-gray-500">Tax (<?php echo $taxRate; ?>%)</span>
                    <span class="font-medium" id="cartTax">Ksh 0.00</span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between items-center">
                    <label class="text-sm text-gray-500">Discount</label>
                    <input type="number" id="cartDiscount" value="0" min="0" step="0.01" onchange="updateCartTotals()"
                           class="w-24 px-2 py-1 border border-gray-200 rounded text-right text-sm">
                </div>
                <div class="flex justify-between text-lg font-bold border-t border-gray-100 pt-2">
                    <span>Total</span>
                    <span id="cartTotal">Ksh 0.00</span>
                </div>
            </div>
        </div>
        
        <!-- Payment -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
            <h4 class="font-medium text-gray-900 mb-3">Payment</h4>
            <form method="POST" action="" id="checkoutForm">
                <input type="hidden" name="process_sale" value="1">
                <input type="hidden" name="items_json" id="itemsJson" value="[]">
                <input type="hidden" name="member_id" id="formMemberId" value="">
                <input type="hidden" name="subtotal" id="formSubtotal" value="0">
                <input type="hidden" name="tax" id="formTax" value="0">
                <input type="hidden" name="discount" id="formDiscount" value="0">
                <input type="hidden" name="total" id="formTotal" value="0">
                
                <input type="text" name="customer_name" id="formCustomerName" placeholder="Customer name" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-2 focus:ring-2 focus:ring-blue-500">
                <input type="text" name="customer_phone" id="formCustomerPhone" placeholder="Phone number" 
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-2 focus:ring-2 focus:ring-blue-500">
                
                <select name="payment_method" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-3 focus:ring-2 focus:ring-blue-500">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>
                
                <textarea name="notes" placeholder="Notes (optional)" rows="2"
                          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-3 focus:ring-2 focus:ring-blue-500"></textarea>
                
                <button type="submit" id="checkoutBtn" disabled
                        class="w-full py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors font-medium flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <i data-lucide="credit-card" class="w-4 h-4"></i> Complete Sale
                </button>
            </form>
        </div>
    </div>
</div>

<script>
let cart = [];
const taxRate = <?php echo $taxRate; ?>;
const taxEnabled = <?php echo $taxEnabled ? 'true' : 'false'; ?>;

function filterProducts(category) {
    // Update tab styles
    document.querySelectorAll('.cat-tab').forEach(tab => {
        if (tab.dataset.cat === category) {
            tab.classList.remove('bg-gray-100', 'text-gray-700');
            tab.classList.add('bg-blue-600', 'text-white');
        } else {
            tab.classList.remove('bg-blue-600', 'text-white');
            tab.classList.add('bg-gray-100', 'text-gray-700');
        }
    });
    
    // Show/hide products
    document.querySelectorAll('.product-card').forEach(card => {
        if (category === 'all' || card.dataset.cat === category) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

function addToCart(id, name, price, type, stock = 999) {
    const existing = cart.find(item => item.id === id && item.type === type);
    
    if (existing) {
        if (type === 'product' && existing.qty >= stock) {
            alert('Not enough stock!');
            return;
        }
        existing.qty++;
        existing.total = existing.qty * existing.price;
    } else {
        cart.push({ id, name, price, type, qty: 1, total: price });
    }
    
    renderCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function updateQty(index, delta) {
    const item = cart[index];
    const newQty = item.qty + delta;
    if (newQty < 1) {
        removeFromCart(index);
        return;
    }
    item.qty = newQty;
    item.total = item.qty * item.price;
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    
    if (cart.length === 0) {
        container.innerHTML = '<div class="text-center py-6 text-gray-400 text-sm">Cart is empty</div>';
        document.getElementById('checkoutBtn').disabled = true;
        updateTotals();
        return;
    }
    
    container.innerHTML = cart.map((item, i) => `
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <div class="flex-1 min-w-0 mr-2">
                <p class="font-medium text-sm text-gray-900 truncate">${item.name}</p>
                <p class="text-xs text-gray-500">Ksh ${item.price.toFixed(2)}</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="updateQty(${i}, -1)" class="w-6 h-6 rounded bg-white border border-gray-200 flex items-center justify-center hover:bg-gray-100">-</button>
                <span class="text-sm font-medium w-6 text-center">${item.qty}</span>
                <button onclick="updateQty(${i}, 1)" class="w-6 h-6 rounded bg-white border border-gray-200 flex items-center justify-center hover:bg-gray-100">+</button>
            </div>
            <div class="ml-3 text-right">
                <p class="font-medium text-sm">Ksh ${item.total.toFixed(2)}</p>
                <button onclick="removeFromCart(${i})" class="text-xs text-red-500 hover:text-red-700">Remove</button>
            </div>
        </div>
    `).join('');
    
    document.getElementById('checkoutBtn').disabled = false;
    updateTotals();
}

function updateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + item.total, 0);
    const discount = parseFloat(document.getElementById('cartDiscount').value) || 0;
    const tax = taxEnabled ? (subtotal - discount) * (taxRate / 100) : 0;
    const total = subtotal - discount + tax;
    
    document.getElementById('cartSubtotal').textContent = 'Ksh ' + subtotal.toFixed(2);
    if (taxEnabled) document.getElementById('cartTax').textContent = 'Ksh ' + tax.toFixed(2);
    document.getElementById('cartTotal').textContent = 'Ksh ' + total.toFixed(2);
    
    // Update form hidden fields
    document.getElementById('itemsJson').value = JSON.stringify(cart);
    document.getElementById('formSubtotal').value = subtotal;
    document.getElementById('formTax').value = tax;
    document.getElementById('formDiscount').value = discount;
    document.getElementById('formTotal').value = total;
}

function updateCartTotals() {
    updateTotals();
}

function selectMember(memberId) {
    document.getElementById('formMemberId').value = memberId;
    
    const select = document.getElementById('memberSelect');
    const option = select.options[select.selectedIndex];
    
    if (memberId && option.dataset.name) {
        document.getElementById('customerName').value = option.dataset.name;
        document.getElementById('customerPhone').value = option.dataset.phone || '';
        document.getElementById('formCustomerName').value = option.dataset.name;
        document.getElementById('formCustomerPhone').value = option.dataset.phone || '';
    } else {
        document.getElementById('customerName').value = '';
        document.getElementById('customerPhone').value = '';
    }
}

// Sync customer name inputs
document.getElementById('customerName').addEventListener('input', function() {
    document.getElementById('formCustomerName').value = this.value;
});
document.getElementById('customerPhone').addEventListener('input', function() {
    document.getElementById('formCustomerPhone').value = this.value;
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
