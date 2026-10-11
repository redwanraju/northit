<?php
/**
 * Ricevaly Credit Wallet + One-Level Referral Engine V3
 *
 * Business rule:
 * - Registered online buyer earns recurring purchase credit on every qualifying order.
 * - Only the DIRECT referrer earns recurring direct-referral credit on that buyer's qualifying orders.
 * - No multi-level / upline commission.
 * - Credit base = max(0, subtotal - discount). Delivery/tax excluded.
 * - Qualifying order = online + linked website account + configured completed/paid state.
 * - Order sync is idempotent and reversible.
 *
 * IMPORTANT: this file does not begin/commit/rollback transactions.
 * Callers should wrap order state change + rvReferralSyncOrder() atomically.
 */

if (!function_exists('rvReferralTableExists')) {
    function rvReferralTableExists(mysqli $db, string $table): bool {
        static $cache = [];
        $key = spl_object_id($db) . ':' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        $safe = $db->real_escape_string($table);
        $res = $db->query("SHOW TABLES LIKE '{$safe}'");
        $exists = (bool)($res && $res->num_rows > 0);
        if ($res instanceof mysqli_result) $res->free();
        return $cache[$key] = $exists;
    }

    function rvReferralColumnExists(mysqli $db, string $table, string $column): bool {
        static $cache = [];
        $key = spl_object_id($db) . ':' . $table . ':' . $column;
        if (array_key_exists($key, $cache)) return $cache[$key];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
        $t = $db->real_escape_string($table);
        $c = $db->real_escape_string($column);
        $res = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
        $exists = (bool)($res && $res->num_rows > 0);
        if ($res instanceof mysqli_result) $res->free();
        return $cache[$key] = $exists;
    }

    function rvReferralStmt(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Credit database prepare failed.');
        if ($types !== '') {
            $refs = [$types];
            foreach ($params as $k => $v) $refs[] = &$params[$k];
            if (!call_user_func_array([$stmt, 'bind_param'], $refs)) {
                $stmt->close();
                throw new RuntimeException('Credit database bind failed.');
            }
        }
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            throw new RuntimeException($msg ?: 'Credit database execute failed.');
        }
        return $stmt;
    }

    function rvReferralStmtRows(mysqli_stmt $stmt): array {
        $meta = $stmt->result_metadata();
        if (!$meta) return [];
        $fields = $meta->fetch_fields();
        $row = [];
        $bind = [];
        foreach ($fields as $field) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }
        if ($bind) call_user_func_array([$stmt, 'bind_result'], $bind);
        $rows = [];
        while ($stmt->fetch()) {
            $copy = [];
            foreach ($row as $k => $v) $copy[$k] = $v;
            $rows[] = $copy;
        }
        $meta->free();
        return $rows;
    }

    function rvReferralStmtRow(mysqli_stmt $stmt): ?array {
        $rows = rvReferralStmtRows($stmt);
        return $rows[0] ?? null;
    }

    function rvReferralSettings(mysqli $db, int $shopId): ?array {
        if (!rvReferralTableExists($db, 'referral_settings')) return null;
        $stmt = rvReferralStmt($db, "SELECT * FROM referral_settings WHERE shop_id=? LIMIT 1", 'i', [$shopId]);
        $row = rvReferralStmtRow($stmt);
        $stmt->close();
        return $row;
    }

    /** Return only an authenticated WEBSITE customer account id. */
    function rvReferralSessionAccountUserId(mysqli $db, int $shopId): ?int {
        $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($sessionUserId <= 0 || !rvReferralTableExists($db, 'users')) return null;
        $stmt = rvReferralStmt(
            $db,
            "SELECT id FROM users WHERE id=? AND shop_id=? AND role='customer' AND status=1 LIMIT 1",
            'ii', [$sessionUserId, $shopId]
        );
        $row = rvReferralStmtRow($stmt);
        $stmt->close();
        return $row ? (int)$row['id'] : null;
    }

    function rvCreditSchemaReady(mysqli $db): bool {
        return rvReferralTableExists($db, 'user_credit_wallets')
            && rvReferralTableExists($db, 'credit_order_rewards')
            && rvReferralTableExists($db, 'credit_transactions')
            && rvReferralTableExists($db, 'referrals')
            && rvReferralColumnExists($db, 'orders', 'account_user_id')
            && rvReferralColumnExists($db, 'referral_settings', 'purchase_credit_percent')
            && rvReferralColumnExists($db, 'referral_settings', 'direct_referral_percent')
            && rvReferralColumnExists($db, 'referral_settings', 'referral_levels');
    }

    function rvCreditAmount(float $base, float $percent): float {
        return round(max(0, $base) * max(0, $percent) / 100, 2);
    }

    function rvCreditEnsureWallet(mysqli $db, int $shopId, int $userId): array {
        if ($userId <= 0) throw new RuntimeException('Invalid credit wallet user.');
        $stmt = rvReferralStmt(
            $db,
            "INSERT IGNORE INTO user_credit_wallets (shop_id,user_id) VALUES (?,?)",
            'ii', [$shopId,$userId]
        );
        $stmt->close();

        $stmt = rvReferralStmt(
            $db,
            "SELECT * FROM user_credit_wallets WHERE shop_id=? AND user_id=? LIMIT 1 FOR UPDATE",
            'ii', [$shopId,$userId]
        );
        $wallet = rvReferralStmtRow($stmt);
        $stmt->close();
        if (!$wallet) throw new RuntimeException('Credit wallet could not be loaded.');
        return $wallet;
    }

    function rvCreditPostLedger(
        mysqli $db,
        int $shopId,
        int $userId,
        int $orderRewardId,
        int $orderId,
        ?int $sourceUserId,
        string $entryType,
        float $signedAmount,
        string $eventKey,
        string $note = '',
        ?int $createdBy = null
    ): array {
        if (abs($signedAmount) < 0.005) return ['posted'=>false,'reason'=>'zero_amount'];

        // Idempotency check first. Unique key remains the final concurrency guard.
        $stmt = rvReferralStmt($db, "SELECT id,amount,balance_after FROM credit_transactions WHERE event_key=? LIMIT 1", 's', [$eventKey]);
        $existing = rvReferralStmtRow($stmt);
        $stmt->close();
        if ($existing) {
            return ['posted'=>false,'reason'=>'already_posted','transaction_id'=>(int)$existing['id'],'balance_after'=>(float)$existing['balance_after']];
        }

        $wallet = rvCreditEnsureWallet($db, $shopId, $userId);
        $walletId = (int)$wallet['id'];
        $balanceBefore = (float)$wallet['balance'];
        $balanceAfter = round($balanceBefore + $signedAmount, 2);

        $earnedAdd = $signedAmount > 0 ? $signedAmount : 0.0;
        $reversedAdd = ($signedAmount < 0 && $entryType === 'reversal') ? abs($signedAmount) : 0.0;
        $spentAdd = ($signedAmount < 0 && $entryType !== 'reversal') ? abs($signedAmount) : 0.0;

        $stmt = rvReferralStmt(
            $db,
            "UPDATE user_credit_wallets
             SET balance=?, lifetime_earned=lifetime_earned+?, lifetime_spent=lifetime_spent+?,
                 lifetime_reversed=lifetime_reversed+?, updated_at=NOW()
             WHERE id=? AND shop_id=? AND user_id=?",
            'ddddiii',
            [$balanceAfter,$earnedAdd,$spentAdd,$reversedAdd,$walletId,$shopId,$userId]
        );
        $stmt->close();

        // Bind manually so nullable integer references remain SQL NULL when appropriate.
        $sql = "INSERT INTO credit_transactions
                    (shop_id,wallet_id,user_id,order_reward_id,order_id,source_user_id,entry_type,amount,balance_after,event_key,note,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new RuntimeException('Credit ledger prepare failed.');
        $sourceBind = $sourceUserId;
        $createdBind = $createdBy;
        $stmt->bind_param(
            'iiiiiisddssi',
            $shopId,$walletId,$userId,$orderRewardId,$orderId,$sourceBind,$entryType,
            $signedAmount,$balanceAfter,$eventKey,$note,$createdBind
        );
        if (!$stmt->execute()) {
            $msg = $stmt->error;
            $stmt->close();
            throw new RuntimeException($msg ?: 'Credit ledger insert failed.');
        }
        $transactionId = (int)$stmt->insert_id;
        $stmt->close();

        return ['posted'=>true,'transaction_id'=>$transactionId,'balance_after'=>$balanceAfter];
    }

    function rvCreditActivateReward(
        mysqli $db,
        int $shopId,
        int $orderId,
        int $beneficiaryUserId,
        int $sourceUserId,
        ?int $referralId,
        string $rewardType,
        float $baseAmount,
        float $ratePercent
    ): array {
        $creditAmount = rvCreditAmount($baseAmount, $ratePercent);
        if ($creditAmount <= 0) return ['state'=>'skipped','reason'=>'zero_credit'];

        $stmt = rvReferralStmt(
            $db,
            "SELECT * FROM credit_order_rewards
             WHERE shop_id=? AND order_id=? AND beneficiary_user_id=? AND reward_type=?
             LIMIT 1 FOR UPDATE",
            'iiis', [$shopId,$orderId,$beneficiaryUserId,$rewardType]
        );
        $reward = rvReferralStmtRow($stmt);
        $stmt->close();

        if (!$reward) {
            $stmt = $db->prepare(
                "INSERT INTO credit_order_rewards
                    (shop_id,order_id,beneficiary_user_id,source_user_id,referral_id,reward_type,base_amount,rate_percent,credit_amount,status,version_no,credited_at)
                 VALUES (?,?,?,?,?,?,?,?,?,'active',1,NOW())"
            );
            if (!$stmt) throw new RuntimeException('Credit reward insert prepare failed.');
            $referralBind = $referralId;
            $stmt->bind_param(
                'iiiiisddd',
                $shopId,$orderId,$beneficiaryUserId,$sourceUserId,$referralBind,$rewardType,$baseAmount,$ratePercent,$creditAmount
            );
            if (!$stmt->execute()) {
                $msg = $stmt->error;
                $stmt->close();
                throw new RuntimeException($msg ?: 'Credit reward insert failed.');
            }
            $rewardId = (int)$stmt->insert_id;
            $stmt->close();
            $version = 1;
        } else {
            $rewardId = (int)$reward['id'];
            if ((string)$reward['status'] === 'active') {
                return ['state'=>'active','reward_id'=>$rewardId,'amount'=>(float)$reward['credit_amount']];
            }
            $version = (int)$reward['version_no'] + 1;
            $stmt = rvReferralStmt(
                $db,
                "UPDATE credit_order_rewards
                 SET source_user_id=?, referral_id=?, base_amount=?, rate_percent=?, credit_amount=?,
                     status='active', version_no=?, credited_at=NOW(), reversed_at=NULL, updated_at=NOW()
                 WHERE id=?",
                'iidddii',
                [$sourceUserId,$referralId,$baseAmount,$ratePercent,$creditAmount,$version,$rewardId]
            );
            $stmt->close();
        }

        $eventKey = 'credit_reward:' . $rewardId . ':credit:v' . $version;
        $entryType = $rewardType === 'direct_referral_credit' ? 'direct_referral_credit' : 'purchase_credit';
        $note = $rewardType === 'direct_referral_credit'
            ? 'Direct one-level referral commission from qualifying online order.'
            : 'Recurring purchase credit from qualifying online order.';

        $ledger = rvCreditPostLedger(
            $db,$shopId,$beneficiaryUserId,$rewardId,$orderId,$sourceUserId,
            $entryType,$creditAmount,$eventKey,$note,null
        );

        return ['state'=>'credited','reward_id'=>$rewardId,'amount'=>$creditAmount,'balance_after'=>$ledger['balance_after'] ?? null];
    }

    function rvCreditReverseReward(mysqli $db, array $reward, string $reason): array {
        if ((string)$reward['status'] !== 'active') return ['state'=>'already_reversed'];
        $rewardId = (int)$reward['id'];
        $version = (int)$reward['version_no'];
        $amount = (float)$reward['credit_amount'];
        $eventKey = 'credit_reward:' . $rewardId . ':reverse:v' . $version;

        $ledger = rvCreditPostLedger(
            $db,(int)$reward['shop_id'],(int)$reward['beneficiary_user_id'],$rewardId,(int)$reward['order_id'],
            (int)$reward['source_user_id'],'reversal',-$amount,$eventKey,substr($reason,0,500),null
        );

        $stmt = rvReferralStmt(
            $db,
            "UPDATE credit_order_rewards SET status='reversed', reversed_at=NOW(), updated_at=NOW() WHERE id=? AND status='active'",
            'i', [$rewardId]
        );
        $stmt->close();
        return ['state'=>'reversed','reward_id'=>$rewardId,'amount'=>$amount,'balance_after'=>$ledger['balance_after'] ?? null];
    }

    function rvCreditReverseOrder(mysqli $db, int $shopId, int $orderId, string $reason): array {
        $stmt = rvReferralStmt(
            $db,
            "SELECT * FROM credit_order_rewards WHERE shop_id=? AND order_id=? AND status='active' ORDER BY id FOR UPDATE",
            'ii', [$shopId,$orderId]
        );
        $rewards = rvReferralStmtRows($stmt);
        $stmt->close();
        $reversed = [];
        foreach ($rewards as $reward) $reversed[] = rvCreditReverseReward($db,$reward,$reason);
        return ['state'=>$reversed ? 'reversed' : 'no_active_credit','items'=>$reversed];
    }

    function rvDirectReferral(mysqli $db, int $shopId, int $buyerUserId): ?array {
        $stmt = rvReferralStmt(
            $db,
            "SELECT r.*, u.status AS referrer_status, u.role AS referrer_role
             FROM referrals r
             INNER JOIN users u ON u.id=r.referrer_user_id AND u.shop_id=r.shop_id
             WHERE r.shop_id=? AND r.referred_user_id=?
               AND r.status IN ('pending','qualified','rewarded')
             LIMIT 1 FOR UPDATE",
            'ii', [$shopId,$buyerUserId]
        );
        $ref = rvReferralStmtRow($stmt);
        $stmt->close();
        if (!$ref) return null;
        if ((int)$ref['referrer_user_id'] === $buyerUserId) return null;
        if ((int)$ref['referrer_status'] !== 1 || (string)$ref['referrer_role'] !== 'customer') return null;
        return $ref;
    }

    /**
     * Synchronize one order to V3 wallet credit state.
     * Function name is retained for compatibility with checkout/order-management callers.
     */
    function rvReferralSyncOrder(mysqli $db, int $shopId, int $orderId): array {
        if ($orderId <= 0 || $shopId <= 0) return ['state'=>'skipped','reason'=>'invalid_order'];
        if (!rvCreditSchemaReady($db)) return ['state'=>'skipped','reason'=>'v3_credit_schema_missing'];

        $settings = rvReferralSettings($db,$shopId);
        if (!$settings || (int)($settings['enabled'] ?? 0) !== 1) return ['state'=>'disabled'];

        $stmt = rvReferralStmt(
            $db,
            "SELECT id,shop_id,account_user_id,order_source,order_status,payment_status,
                    subtotal,discount,delivery_cost,tax,total_amount,created_at
             FROM orders WHERE id=? AND shop_id=? LIMIT 1 FOR UPDATE",
            'ii', [$orderId,$shopId]
        );
        $order = rvReferralStmtRow($stmt);
        $stmt->close();
        if (!$order) return ['state'=>'skipped','reason'=>'order_not_found'];

        $buyerUserId = (int)($order['account_user_id'] ?? 0);
        if ($buyerUserId <= 0 || (string)$order['order_source'] !== 'online') {
            return ['state'=>'skipped','reason'=>'not_registered_online_order'];
        }

        $requiredOrderStatus = (string)($settings['qualifying_order_status'] ?? 'completed');
        $requiredPaymentStatus = (string)($settings['qualifying_payment_status'] ?? 'paid');
        $baseAmount = round(max(0, (float)($order['subtotal'] ?? 0) - (float)($order['discount'] ?? 0)), 2);
        $minimumOrder = max(0, (float)($settings['minimum_order_amount'] ?? 0));

        $qualifying = (string)$order['order_status'] === $requiredOrderStatus
            && (string)$order['payment_status'] === $requiredPaymentStatus
            && $baseAmount >= $minimumOrder
            && $baseAmount > 0;

        if (!$qualifying) {
            $reason = 'Order no longer qualifies for wallet credit (' . (string)$order['order_status'] . '/' . (string)$order['payment_status'] . ').';
            $rev = rvCreditReverseOrder($db,$shopId,$orderId,$reason);
            return ['state'=>'pending_or_reversed','base_amount'=>$baseAmount,'reversal'=>$rev];
        }

        $purchasePercent = max(0, (float)($settings['purchase_credit_percent'] ?? 2));
        $directPercent = max(0, (float)($settings['direct_referral_percent'] ?? 5));
        $levels = (int)($settings['referral_levels'] ?? 1);
        if ($levels !== 1) $levels = 1; // hard safety: V3 is intentionally one-level only.

        $buyerCredit = rvCreditActivateReward(
            $db,$shopId,$orderId,$buyerUserId,$buyerUserId,null,
            'purchase_credit',$baseAmount,$purchasePercent
        );

        $directCredit = ['state'=>'no_direct_referrer'];
        $referral = rvDirectReferral($db,$shopId,$buyerUserId);
        if ($referral && $directPercent > 0) {
            $referrerId = (int)$referral['referrer_user_id'];
            $directCredit = rvCreditActivateReward(
                $db,$shopId,$orderId,$referrerId,$buyerUserId,(int)$referral['id'],
                'direct_referral_credit',$baseAmount,$directPercent
            );

            // Relationship is lifetime one-level. First qualifying purchase marks it qualified,
            // but subsequent purchases continue to pay direct commission.
            if ((string)$referral['status'] === 'pending') {
                $stmt = rvReferralStmt(
                    $db,
                    "UPDATE referrals
                     SET status='qualified', qualified_order_id=COALESCE(qualified_order_id,?),
                         qualified_at=COALESCE(qualified_at,NOW()), reject_reason=NULL, updated_at=NOW()
                     WHERE id=? AND status='pending'",
                    'ii', [$orderId,(int)$referral['id']]
                );
                $stmt->close();
            }
        }

        return [
            'state'=>'credited',
            'base_amount'=>$baseAmount,
            'purchase_credit'=>$buyerCredit,
            'direct_referral_credit'=>$directCredit,
            'referral_levels'=>1
        ];
    }
}
