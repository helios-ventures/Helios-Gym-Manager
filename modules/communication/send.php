<?php
/**
 * Communication Module - Send bulk SMS, email, WhatsApp messages
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\Session;

Auth::requirePermission('communication', 'send');

$pageTitle = 'Send Message';
$pageDescription = 'Send bulk SMS, email, or WhatsApp messages to members';

// Get templates
$templates = Database::fetchAll("SELECT * FROM message_templates WHERE status = 'active' ORDER BY name");

// Get member groups for filtering
$plans = Database::fetchAll("SELECT * FROM subscription_plans WHERE status = 'active'");

// Handle send
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['message_type'] ?? 'sms';
    $templateId = intval($_POST['template_id'] ?? 0);
    $content = trim($_POST['content'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $recipientFilter = $_POST['recipient_filter'] ?? 'all';
    $planId = intval($_POST['plan_id'] ?? 0);
    $memberIds = $_POST['member_ids'] ?? [];
    
    if (empty($content)) {
        Session::setFlash('danger', 'Message content is required');
    } else {
        // Build recipient query
        $params = [];
        $where = ["status = 'active'"];
        
        if ($recipientFilter === 'plan' && $planId) {
            $where[] = "id IN (SELECT member_id FROM member_subscriptions WHERE plan_id = ? AND status = 'active')";
            $params[] = $planId;
        } elseif ($recipientFilter === 'custom' && !empty($memberIds)) {
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $where[] = "id IN ({$placeholders})";
            $params = array_merge($params, $memberIds);
        }
        
        $whereClause = implode(' AND ', $where);
        
        if ($type === 'sms' || $type === 'whatsapp') {
            $recipients = Database::fetchAll("SELECT id, first_name, last_name, phone FROM members WHERE {$whereClause} AND phone IS NOT NULL", $params);
        } else {
            $recipients = Database::fetchAll("SELECT id, first_name, last_name, email FROM members WHERE {$whereClause} AND email IS NOT NULL", $params);
        }
        
        $sent = 0;
        $failed = 0;
        
        foreach ($recipients as $recipient) {
            $personalizedContent = str_replace(
                ['{first_name}', '{last_name}', '{gym_name}'],
                [$recipient['first_name'], $recipient['last_name'], Auth::getSetting('gym_name', 'FitLife Gym')],
                $content
            );
            
            $recipientContact = ($type === 'sms' || $type === 'whatsapp') ? $recipient['phone'] : $recipient['email'];
            
            try {
                Database::insert(
                    "INSERT INTO message_logs (template_id, recipient_type, recipient_id, recipient_phone, recipient_email, message_type, subject, content, status, sent_by) 
                     VALUES (?, 'single', ?, ?, ?, ?, ?, ?, 'sent', ?)",
                    [
                        $templateId ?: null,
                        $recipient['id'],
                        ($type === 'sms' || $type === 'whatsapp') ? $recipientContact : null,
                        $type === 'email' ? $recipientContact : null,
                        $type,
                        $subject ?: null,
                        $personalizedContent,
                        Auth::id()
                    ]
                );
                $sent++;
            } catch (\Exception $e) {
                $failed++;
            }
        }
        
        Session::setFlash('success', "Message sent to {$sent} recipients" . ($failed > 0 ? ", {$failed} failed" : ''));
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Get members for custom selection
$allMembers = Database::fetchAll("SELECT id, first_name, last_name, member_code, phone, email FROM members WHERE status = 'active' ORDER BY first_name LIMIT 200");

require_once INCLUDES_PATH . '/header.php';
require_once INCLUDES_PATH . '/sidebar.php';
?>

<form method="POST" action="" class="space-y-6">
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Message Content -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <i data-lucide="message-square" class="w-5 h-5 text-blue-500"></i>
                    Message Content
                </h3>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Message Type</label>
                        <div class="flex gap-3">
                            <label class="flex items-center gap-2 px-4 py-2 border-2 border-blue-500 bg-blue-50 rounded-lg cursor-pointer">
                                <input type="radio" name="message_type" value="sms" checked onchange="toggleType('sms')" class="text-blue-600">
                                <span class="text-sm font-medium">SMS</span>
                            </label>
                            <label class="flex items-center gap-2 px-4 py-2 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-gray-300">
                                <input type="radio" name="message_type" value="email" onchange="toggleType('email')" class="text-blue-600">
                                <span class="text-sm font-medium">Email</span>
                            </label>
                            <label class="flex items-center gap-2 px-4 py-2 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-gray-300">
                                <input type="radio" name="message_type" value="whatsapp" onchange="toggleType('whatsapp')" class="text-blue-600">
                                <span class="text-sm font-medium">WhatsApp</span>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Template (optional)</label>
                        <select id="templateSelect" onchange="loadTemplate(this.value)"
                                class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                            <option value="">-- Select template --</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo $t['id']; ?>" data-type="<?php echo $t['type']; ?>" data-subject="<?php echo htmlspecialchars($t['subject'] ?? ''); ?>" data-content="<?php echo htmlspecialchars($t['content']); ?>">
                                    <?php echo htmlspecialchars($t['name']); ?> (<?php echo strtoupper($t['type']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="subjectField" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                        <input type="text" name="subject" id="subjectInput"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                               placeholder="Email subject">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Message Content</label>
                        <textarea name="content" id="contentArea" rows="6" required
                                  class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500"
                                  placeholder="Type your message here... Use {first_name}, {last_name}, {gym_name} as placeholders."></textarea>
                        <div class="flex justify-between mt-1">
                            <span class="text-xs text-gray-500">Available variables: {first_name}, {last_name}, {gym_name}</span>
                            <span class="text-xs text-gray-500" id="charCount">0 chars</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recipients -->
        <div class="space-y-6">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6">
                <h3 class="font-semibold text-gray-900 mb-4 flex items-center gap-2">
                    <i data-lucide="users" class="w-5 h-5 text-green-500"></i>
                    Recipients
                </h3>
                
                <div class="space-y-3">
                    <label class="flex items-center gap-3 p-3 border-2 border-blue-500 bg-blue-50 rounded-lg cursor-pointer">
                        <input type="radio" name="recipient_filter" value="all" checked onchange="toggleRecipients('all')" class="text-blue-600">
                        <div>
                            <p class="font-medium text-sm">All Active Members</p>
                            <p class="text-xs text-gray-500">Send to everyone</p>
                        </div>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-gray-300">
                        <input type="radio" name="recipient_filter" value="plan" onchange="toggleRecipients('plan')" class="text-blue-600">
                        <div>
                            <p class="font-medium text-sm">By Plan</p>
                            <p class="text-xs text-gray-500">Filter by subscription</p>
                        </div>
                    </label>
                    
                    <label class="flex items-center gap-3 p-3 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-gray-300">
                        <input type="radio" name="recipient_filter" value="custom" onchange="toggleRecipients('custom')" class="text-blue-600">
                        <div>
                            <p class="font-medium text-sm">Custom Selection</p>
                            <p class="text-xs text-gray-500">Pick specific members</p>
                        </div>
                    </label>
                </div>
                
                <!-- Plan filter -->
                <div id="planFilter" class="mt-3 hidden">
                    <select name="plan_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Custom selection -->
                <div id="customFilter" class="mt-3 hidden">
                    <div class="max-h-64 overflow-y-auto border border-gray-200 rounded-lg p-2">
                        <?php foreach ($allMembers as $m): ?>
                            <label class="flex items-center gap-2 p-2 hover:bg-gray-50 rounded cursor-pointer">
                                <input type="checkbox" name="member_ids[]" value="<?php echo $m['id']; ?>" class="rounded text-blue-600">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm truncate"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo $m['phone'] ?? $m['email'] ?? 'No contact'; ?></p>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="w-full py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium flex items-center justify-center gap-2">
                <i data-lucide="send" class="w-4 h-4"></i> Send Message
            </button>
        </div>
    </div>
</form>

<script>
function toggleType(type) {
    const subjectField = document.getElementById('subjectField');
    if (type === 'email') {
        subjectField.classList.remove('hidden');
    } else {
        subjectField.classList.add('hidden');
    }
    
    // Filter templates by type
    const select = document.getElementById('templateSelect');
    Array.from(select.options).forEach(opt => {
        if (!opt.value) return;
        if (opt.dataset.type === type) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    });
    select.value = '';
}

function toggleRecipients(type) {
    document.getElementById('planFilter').classList.toggle('hidden', type !== 'plan');
    document.getElementById('customFilter').classList.toggle('hidden', type !== 'custom');
    
    // Style the radio cards
    document.querySelectorAll('input[name="recipient_filter"]').forEach(radio => {
        const card = radio.closest('label');
        if (radio.value === type) {
            card.classList.remove('border-gray-200');
            card.classList.add('border-2', 'border-blue-500', 'bg-blue-50');
        } else {
            card.classList.remove('border-2', 'border-blue-500', 'bg-blue-50');
            card.classList.add('border-gray-200');
        }
    });
}

function loadTemplate(templateId) {
    const select = document.getElementById('templateSelect');
    const option = select.options[select.selectedIndex];
    if (!option.value) return;
    
    const content = option.dataset.content;
    const subject = option.dataset.subject;
    const type = option.dataset.type;
    
    // Set type radio
    document.querySelector(`input[name="message_type"][value="${type}"]`).checked = true;
    toggleType(type);
    
    document.getElementById('contentArea').value = content;
    if (subject) {
        document.getElementById('subjectInput').value = subject;
    }
    
    updateCharCount();
}

function updateCharCount() {
    const count = document.getElementById('contentArea').value.length;
    document.getElementById('charCount').textContent = count + ' chars';
}

document.getElementById('contentArea').addEventListener('input', updateCharCount);
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
