<?php
/**
 * Membership Expiry Cron
 *
 * Recommended crontab entry (run once daily, early morning):
 *   0 6 * * * /usr/bin/php /path/to/gym-management/cron/membership_expiry_check.php >> /path/to/gym-management/cron/expiry.log 2>&1
 *
 * What it does:
 *   1. Finds members whose expiry_date has passed and are still marked active,
 *      flips their status to 'expired', and disables their biometric door
 *      access via MemberAccess (which talks to the Hikvision device).
 *   2. Sends a reminder message to members expiring within REMINDER_DAYS_BEFORE,
 *      logged to message_logs the same way the Communication module already does.
 *      No SMS/email gateway is wired up yet in this codebase (sms_api_key etc.
 *      are empty settings) - this only logs the message; plug in a real gateway
 *      call where marked TODO once you pick one (Africa's Talking, Twilio, SMTP...).
 */

require_once __DIR__ . '/../config/config.php';

use Gym\Core\Auth;
use Gym\Core\Database;
use Gym\Core\Helper;
use Gym\Core\MemberAccess;
use Gym\Core\AfricasTalking;

// Days-before-expiry thresholds to send a reminder on. Each member's expiry
// date only ever matches one of these on any given day, so no dedup is needed
// across thresholds - only against the cron accidentally running twice in a day.
const REMINDER_DAYS_BEFORE = [7, 3, 1];

$today = date('Y-m-d');

echo '[' . date('Y-m-d H:i:s') . "] Membership expiry check starting...\n";

// ─────────────────────────────────────────────────────────────
// 1. Expire memberships whose expiry_date has passed
// ─────────────────────────────────────────────────────────────

$expiring = Database::fetchAll(
    "SELECT * FROM members 
     WHERE expiry_date IS NOT NULL 
       AND expiry_date < ? 
       AND status != 'expired'",
    [$today]
);

$expiredCount = 0;
$disableFailures = 0;

foreach ($expiring as $member) {
    Database::execute("UPDATE members SET status = 'expired' WHERE id = ?", [$member['id']]);

    if (!empty($member['biometric_id'])) {
        $freshMember = Database::fetchOne("SELECT * FROM members WHERE id = ?", [$member['id']]);
        $result = MemberAccess::syncFromMembershipState($freshMember);
        if (!$result['success']) {
            $disableFailures++;
            echo "  ! Device disable failed for {$member['member_code']}: {$result['message']}\n";
        }
    }

    Auth::logActivity(
        'member_auto_expire',
        "Membership expired for {$member['member_code']}: {$member['first_name']} {$member['last_name']}"
    );
    $expiredCount++;
}

echo "  Expired {$expiredCount} membership(s)"
    . ($disableFailures ? ", {$disableFailures} device disable call(s) failed (will retry next run - check logs)" : '')
    . ".\n";

// ─────────────────────────────────────────────────────────────
// 2. Send expiry reminders
// ─────────────────────────────────────────────────────────────

$reminderTemplate = Database::fetchOne(
    "SELECT * FROM message_templates WHERE name = 'Subscription Expiry Reminder' AND status = 'active' LIMIT 1"
);

$gymName = Auth::getSetting('gym_name', 'the gym');
$remindersSent = 0;

foreach (REMINDER_DAYS_BEFORE as $daysBefore) {
    $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));

    $dueMembers = Database::fetchAll(
        "SELECT * FROM members WHERE expiry_date = ? AND status = 'active'",
        [$targetDate]
    );

    foreach ($dueMembers as $member) {
        // Guard against the cron being run twice in the same day - skip if this
        // member already has any message logged today.
        $already = Database::fetchOne(
            "SELECT id FROM message_logs WHERE recipient_id = ? AND DATE(created_at) = ? LIMIT 1",
            [$member['id'], $today]
        );
        if ($already) {
            continue;
        }

        $content = $reminderTemplate
            ? str_replace(
                ['{first_name}', '{expiry_date}', '{gym_name}'],
                [$member['first_name'], Helper::date($member['expiry_date']), $gymName],
                $reminderTemplate['content']
              )
            : "Hi {$member['first_name']}, your membership at {$gymName} expires in {$daysBefore} day(s) on "
              . Helper::date($member['expiry_date']) . '. Renew soon to avoid interruption.';

        $atUsername = Auth::getSetting('sms_username', '');
        $atApiKey = Auth::getSetting('sms_api_key', '');
        $atSenderId = Auth::getSetting('sms_sender_id', '');

        $sendStatus = 'sent';
        if ($atUsername && $atApiKey && !empty($member['phone'])) {
            $at = new AfricasTalking($atUsername, $atApiKey, $atSenderId);
            $sendResult = $at->sendOne($member['phone'], $content);
            $sendStatus = $sendResult['success'] ? 'sent' : 'failed';
            if (!$sendResult['success']) {
                echo "  ! SMS failed for {$member['member_code']}: {$sendResult['message']}\n";
            }
        } else {
            // Not configured yet - logs only, same as before.
            $sendStatus = 'sent';
        }

        Database::insert(
            "INSERT INTO message_logs 
                (template_id, recipient_type, recipient_id, recipient_phone, message_type, content, status, sent_by)
             VALUES (?, 'single', ?, ?, 'sms', ?, ?, NULL)",
            [
                $reminderTemplate['id'] ?? null,
                $member['id'],
                $member['phone'],
                $content,
                $sendStatus,
            ]
        );

        $remindersSent++;
    }
}

echo "  Sent {$remindersSent} expiry reminder(s).\n";
echo '[' . date('Y-m-d H:i:s') . "] Done.\n";
