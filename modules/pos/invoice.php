<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
Auth::requirePermission('pos', 'view');
$saleId = intval($_GET['id'] ?? 0);
$sale = Database::fetchOne("SELECT s.*, m.first_name, m.last_name, m.phone as member_phone, u.first_name as seller_name FROM sales s LEFT JOIN members m ON s.member_id=m.id LEFT JOIN users u ON s.sold_by=u.id WHERE s.id=?", [$saleId]);
$items = Database::fetchAll("SELECT * FROM sale_items WHERE sale_id=?", [$saleId]);
if (!$sale) { Helper::redirect('/modules/pos/history.php', 'warning', 'Invoice not found'); }
$settings = \Gym\Core\Auth::getSettings();
$pageTitle = 'Invoice ' . $sale['invoice_number'];
$hidePageHeader = true;
require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>
<div class="max-w-2xl mx-auto bg-white rounded-xl border border-gray-100 shadow-sm p-8">
    <div class="text-center mb-6">
        <h2 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($settings['gym_name'] ?? 'FitLife Gym'); ?></h2>
        <p class="text-sm text-gray-500 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($settings['receipt_header'] ?? '')); ?></p>
    </div>
    <div class="flex justify-between mb-6 text-sm">
        <div><p class="font-medium">Invoice: <?php echo htmlspecialchars($sale['invoice_number']); ?></p><p class="text-gray-500"><?php echo Helper::datetime($sale['created_at']); ?></p></div>
        <div class="text-right"><p class="font-medium"><?php echo htmlspecialchars($sale['customer_name'] ?? ($sale['first_name'].' '.$sale['last_name']) ?? 'Walk-in'); ?></p><?php if ($sale['customer_phone'] || ($sale['member_phone'] ?? '')): ?><p class="text-gray-500"><?php echo htmlspecialchars($sale['customer_phone'] ?? $sale['member_phone'] ?? ''); ?></p><?php endif; ?></div>
    </div>
    <table class="w-full text-sm mb-6">
        <thead class="border-b-2 border-gray-200"><tr><th class="text-left py-2">Item</th><th class="text-right py-2">Qty</th><th class="text-right py-2">Price</th><th class="text-right py-2">Total</th></tr></thead>
        <tbody>
            <?php foreach ($items as $item): ?><tr class="border-b border-gray-100"><td class="py-2"><?php echo htmlspecialchars($item['item_name']); ?></td><td class="text-right py-2"><?php echo $item['quantity']; ?></td><td class="text-right py-2"><?php echo Helper::money($item['unit_price']); ?></td><td class="text-right py-2 font-medium"><?php echo Helper::money($item['total_price']); ?></td></tr><?php endforeach; ?>
        </tbody>
    </table>
    <div class="border-t-2 border-gray-200 pt-4 space-y-1 text-sm">
        <div class="flex justify-between"><span>Subtotal</span><span><?php echo Helper::money($sale['subtotal']); ?></span></div>
        <?php if ($sale['tax_amount'] > 0): ?><div class="flex justify-between"><span>Tax</span><span><?php echo Helper::money($sale['tax_amount']); ?></span></div><?php endif; ?>
        <?php if ($sale['discount_amount'] > 0): ?><div class="flex justify-between"><span>Discount</span><span>-<?php echo Helper::money($sale['discount_amount']); ?></span></div><?php endif; ?>
        <div class="flex justify-between text-lg font-bold"><span>Total</span><span><?php echo Helper::money($sale['total_amount']); ?></span></div>
        <div class="flex justify-between text-gray-500"><span>Payment</span><span class="capitalize"><?php echo $sale['payment_method']; ?></span></div>
    </div>
    <div class="mt-6 pt-4 border-t text-center text-sm text-gray-500">
        <p class="whitespace-pre-line"><?php echo nl2br(htmlspecialchars($settings['receipt_footer'] ?? 'Thank you for your business!')); ?></p>
        <p class="mt-2">Served by: <?php echo htmlspecialchars($sale['seller_name'] ?? 'Staff'); ?></p>
    </div>
    <div class="mt-6 flex justify-center gap-3 no-print">
        <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium flex items-center gap-2"><i data-lucide="printer" class="w-4 h-4"></i> Print</button>
        <a href="history.php" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 text-sm">Back</a>
    </div>
</div>
<?php require_once INCLUDES_PATH . '/footer.php'; ?>
