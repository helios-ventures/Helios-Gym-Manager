<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('pos', 'view');
$pageTitle = 'Products';
$products = Database::fetchAll("SELECT p.*, c.name as category_name FROM products p LEFT JOIN product_categories c ON p.category_id=c.id ORDER BY p.name");
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between"><h3 class="font-semibold text-gray-900">All Products</h3><span class="text-sm text-gray-500"><?php echo count($products); ?> items</span></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50"><tr><th class="text-left px-5 py-3 font-medium text-gray-500">Product</th><th class="text-left px-5 py-3 font-medium text-gray-500">SKU</th><th class="text-left px-5 py-3 font-medium text-gray-500">Category</th><th class="text-right px-5 py-3 font-medium text-gray-500">Price</th><th class="text-right px-5 py-3 font-medium text-gray-500">Stock</th><th class="text-center px-5 py-3 font-medium text-gray-500">Status</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($products as $p): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3 font-medium text-gray-900"><?php echo htmlspecialchars($p['name']); ?></td>
                    <td class="px-5 py-3 text-gray-500"><?php echo htmlspecialchars($p['sku']); ?></td>
                    <td class="px-5 py-3"><span class="text-xs bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($p['category_name'] ?? 'N/A'); ?></span></td>
                    <td class="px-5 py-3 text-right"><?php echo Helper::money($p['unit_price']); ?></td>
                    <td class="px-5 py-3 text-right <?php echo $p['stock_quantity'] <= $p['low_stock_threshold'] ? 'text-red-600 font-medium' : ''; ?>"><?php echo $p['stock_quantity']; ?></td>
                    <td class="px-5 py-3 text-center"><span class="badge-<?php echo Helper::statusBadge($p['status']); ?> px-2 py-0.5 text-xs rounded-full border"><?php echo ucfirst($p['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?><tr><td colspan="6" class="px-5 py-8 text-center text-gray-400">No products</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
