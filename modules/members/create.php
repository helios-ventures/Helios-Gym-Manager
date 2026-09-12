<?php
/**
 * Create New Member
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Validator;
use Gym\Core\Session;

Auth::requirePermission('members', 'create');

$pageTitle = 'Add New Member';
$pageDescription = 'Register a new gym member';

$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY price");

$errors = [];
$old = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;
    
    $validator = Validator::make($_POST, [
        'first_name' => 'required|min:2|max:50',
        'last_name' => 'required|min:2|max:50',
        'phone' => 'required|phone',
        'email' => 'email|max:100',
        'gender' => 'required|in:male,female,other',
        'date_of_birth' => 'date',
        'address' => 'max:255',
        'emergency_contact_name' => 'max:100',
        'emergency_contact_phone' => 'phone',
        'height_cm' => 'numeric',
        'current_weight_kg' => 'numeric',
        'target_weight_kg' => 'numeric',
        'fitness_goal' => 'in:weight_loss,muscle_gain,maintenance,general_fitness,rehabilitation',
        'health_notes' => 'max:1000',
        'plan_id' => 'required|integer',
        'start_date' => 'required|date',
    ]);
    
    if ($validator->passes()) {
        try {
            Database::beginTransaction();
            
            $memberCode = Helper::generateMemberCode();
            $data = $validator->validated();
            
            // Calculate end date
            $plan = Database::fetchOne("SELECT * FROM subscription_plans WHERE id = ?", [$data['plan_id']]);
            $startDate = $data['start_date'];
            $endDate = date('Y-m-d', strtotime($startDate . ' + ' . $plan['duration_days'] . ' days'));
            
            // Insert member
            //$memberId = Database::insert(
            $memberId = Database::insertAndGetId(
                "INSERT INTO members (member_code, first_name, last_name, email, phone, gender, date_of_birth, 
                 address, emergency_contact_name, emergency_contact_phone, height_cm, current_weight_kg, 
                 target_weight_kg, fitness_goal, health_notes, join_date, expiry_date, status, biometric_id) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)",
                [
                    $memberCode,
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'] ?? null,
                    $data['phone'],
                    $data['gender'],
                    $data['date_of_birth'] ?? null,
                    $data['address'] ?? null,
                    $data['emergency_contact_name'] ?? null,
                    $data['emergency_contact_phone'] ?? null,
                    $data['height_cm'] ?? null,
                    $data['current_weight_kg'] ?? null,
                    $data['target_weight_kg'] ?? null,
                    $data['fitness_goal'] ?? 'general_fitness',
                    $data['health_notes'] ?? null,
                    $startDate,
                    $endDate,
                    $memberId ?? null
                ]
            );
            
            // Create subscription
            Database::insert(
                "INSERT INTO member_subscriptions (member_id, plan_id, start_date, end_date, amount_paid, payment_method, status, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, 'active', ?)",
                [$memberId, $data['plan_id'], $startDate, $endDate, $plan['price'], $data['payment_method'] ?? 'cash', Auth::id()]
            );
            
            // Log weight if provided
            if (!empty($data['current_weight_kg'])) {
                $heightCm = $data['height_cm'] ?? 170;
                $bmi = Helper::calculateBmi($data['current_weight_kg'], $heightCm);
                
                Database::insert(
                    "INSERT INTO weight_logs (member_id, weight_kg, bmi, logged_by) VALUES (?, ?, ?, ?)",
                    [$memberId, $data['current_weight_kg'], $bmi, Auth::id()]
                );
            }
            
            Database::commit();
            
            Auth::logActivity('member_create', "Created member {$memberCode}: {$data['first_name']} {$data['last_name']}");
            
            Helper::redirect('/modules/members/view.php?id=' . $memberId, 'success', 'Member registered successfully! Member code: ' . $memberCode);
            
        } catch (\Exception $e) {
            Database::rollback();
            $errors['general'] = 'Error creating member: ' . $e->getMessage();
        }
    } else {
        $errors = $validator->errors();
    }
}

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<form method="POST" action="" class="space-y-6" enctype="multipart/form-data">
    <?php if (isset($errors['general'])): ?>
        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <p class="text-red-700 text-sm"><?php echo $errors['general']; ?></p>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Personal Information -->
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="user" class="w-5 h-5 text-blue-500"></i>
                Personal Information
            </h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="<?php echo htmlspecialchars($old['first_name'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent <?php echo isset($errors['first_name']) ? 'border-red-300' : ''; ?>">
                    <?php if (isset($errors['first_name'])): ?>
                        <p class="text-red-500 text-xs mt-1"><?php echo $errors['first_name'][0]; ?></p>
                    <?php endif; ?>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($old['last_name'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Phone <span class="text-red-500">*</span></label>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($old['phone'] ?? ''); ?>" required
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           placeholder="e.g. 0700123456">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($old['email'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gender <span class="text-red-500">*</span></label>
                    <select name="gender" required
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">Select gender</option>
                        <option value="male" <?php echo ($old['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($old['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo ($old['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($old['date_of_birth'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                    <textarea name="address" rows="2"
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?php echo htmlspecialchars($old['address'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- Emergency Contact & Health -->
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                <i data-lucide="heart-pulse" class="w-5 h-5 text-red-500"></i>
                Emergency & Health
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Name</label>
                    <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($old['emergency_contact_name'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Emergency Contact Phone</label>
                    <input type="tel" name="emergency_contact_phone" value="<?php echo htmlspecialchars($old['emergency_contact_phone'] ?? ''); ?>"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <hr class="border-gray-100">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Height (cm)</label>
                    <input type="number" name="height_cm" value="<?php echo htmlspecialchars($old['height_cm'] ?? ''); ?>" step="0.1"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Weight (kg)</label>
                    <input type="number" name="current_weight_kg" value="<?php echo htmlspecialchars($old['current_weight_kg'] ?? ''); ?>" step="0.01"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Target Weight (kg)</label>
                    <input type="number" name="target_weight_kg" value="<?php echo htmlspecialchars($old['target_weight_kg'] ?? ''); ?>" step="0.01"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fitness Goal</label>
                    <select name="fitness_goal"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="general_fitness" <?php echo ($old['fitness_goal'] ?? '') === 'general_fitness' ? 'selected' : ''; ?>>General Fitness</option>
                        <option value="weight_loss" <?php echo ($old['fitness_goal'] ?? '') === 'weight_loss' ? 'selected' : ''; ?>>Weight Loss</option>
                        <option value="muscle_gain" <?php echo ($old['fitness_goal'] ?? '') === 'muscle_gain' ? 'selected' : ''; ?>>Muscle Gain</option>
                        <option value="maintenance" <?php echo ($old['fitness_goal'] ?? '') === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="rehabilitation" <?php echo ($old['fitness_goal'] ?? '') === 'rehabilitation' ? 'selected' : ''; ?>>Rehabilitation</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Health Notes</label>
                    <textarea name="health_notes" rows="3"
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Any allergies, injuries, or medical conditions..."><?php echo htmlspecialchars($old['health_notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Subscription -->
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <i data-lucide="credit-card" class="w-5 h-5 text-purple-500"></i>
            Subscription Details
        </h3>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Plan <span class="text-red-500">*</span></label>
                <select name="plan_id" required id="planSelect"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Select a plan</option>
                    <?php foreach ($plans as $p): ?>
                        <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['price']; ?>" data-duration="<?php echo $p['duration_days']; ?>"
                                <?php echo ($old['plan_id'] ?? '') == $p['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name']); ?> - <?php echo Helper::money($p['price']); ?> / <?php echo $p['duration_days']; ?> days
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($old['start_date'] ?? date('Y-m-d')); ?>" required
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Payment Method</label>
                <select name="payment_method"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="mpesa">M-Pesa</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>
            </div>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex items-center justify-end gap-3">
        <a href="index.php" class="px-6 py-2.5 border border-gray-200 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
            Cancel
        </a>
        <button type="submit" class="px-6 py-2.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            Register Member
        </button>
    </div>
</form>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
