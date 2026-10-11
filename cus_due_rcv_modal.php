<?php
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);

/*
|--------------------------------------------------------------------------
| cus_due_rcv_modal.php
|--------------------------------------------------------------------------
| Use:
| 1) Include this file once near the bottom of customers.php before footer:
|       <?php include "cus_due_rcv_modal.php"; ?>
|
| 2) Button example:
|       <button type="button" class="action-btn btn-due" onclick="openDueReceiveModal(CUSTOMER_ID)">
|           <i class="fas fa-money-bill-wave"></i> Due
|       </button>
|
| This file also works as AJAX endpoint:
| - GET  cus_due_rcv_modal.php?ajax=get_customer&customer_id=1
| - POST cus_due_rcv_modal.php ajax=receive_due
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "permission.php";
require_once "../config/db.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$shop_id = tenantShopId();
$user_id = tenantUserId();

function ensure_customer_due_receive_table($conn) {
    $conn->query("
        CREATE TABLE IF NOT EXISTS customer_due_receives (
            id INT(11) NOT NULL AUTO_INCREMENT,
            shop_id INT(11) NOT NULL,
            customer_id INT(11) NOT NULL,
            receive_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            payment_method VARCHAR(50) DEFAULT 'cash',
            note TEXT NULL,
            created_by INT(11) NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_shop_id (shop_id),
            KEY idx_customer_id (customer_id),
            KEY idx_receive_date (receive_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_effective_due($conn, $shop_id, $customer) {
    $customer_id = (int)$customer['id'];
    $due_amount = (float)($customer['due_amount'] ?? 0);
    $opening_due = (float)($customer['opening_due'] ?? 0);

    /*
     * Existing old customers may have opening_due but due_amount = 0 because
     * older add customer code did not initialize due_amount.
     * If no receive history exists yet, we treat opening_due as current due.
     */
    if ($due_amount <= 0 && $opening_due > 0) {
        ensure_customer_due_receive_table($conn);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total_rcv
            FROM customer_due_receives
            WHERE shop_id = ? AND customer_id = ?
        ");
        $stmt->bind_param("ii", $shop_id, $customer_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if ((int)($row['total_rcv'] ?? 0) === 0) {
            return $opening_due;
        }
    }

    return $due_amount;
}

/* ================= AJAX: GET CUSTOMER ================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_customer') {
    try {
        ensure_customer_due_receive_table($conn);

        $customer_id = (int)($_GET['customer_id'] ?? $_GET['id'] ?? 0);
        if ($customer_id <= 0) {
            throw new Exception("Invalid customer ID.");
        }

        $stmt = $conn->prepare("
            SELECT id, shop_name, name, phone, email, address, area_group, customer_group, opening_due, due_amount
            FROM customers
            WHERE id = ? AND shop_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $customer_id, $shop_id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if (!$customer) {
            throw new Exception("Customer not found.");
        }

        $customer['effective_due'] = get_effective_due($conn, $shop_id, $customer);

        json_response([
            'success' => true,
            'customer' => $customer
        ]);

    } catch (Exception $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/* ================= AJAX: RECEIVE DUE ================= */
if (isset($_POST['ajax']) && $_POST['ajax'] === 'receive_due') {
    try {
        ensure_customer_due_receive_table($conn);

        $customer_id = (int)($_POST['customer_id'] ?? 0);
        $receive_date = trim($_POST['receive_date'] ?? date('Y-m-d'));
        $amount = (float)($_POST['amount'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? 'cash');
        $note = trim($_POST['note'] ?? '');

        if ($customer_id <= 0) {
            throw new Exception("Invalid customer ID.");
        }

        if ($amount <= 0) {
            throw new Exception("Receive amount must be greater than 0.");
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $receive_date)) {
            throw new Exception("Invalid receive date.");
        }

        $allowed_methods = ['cash', 'bkash', 'nagad', 'bank', 'card', 'other'];
        if (!in_array($payment_method, $allowed_methods, true)) {
            $payment_method = 'cash';
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT id, name, phone, opening_due, due_amount
            FROM customers
            WHERE id = ? AND shop_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param("ii", $customer_id, $shop_id);
        $stmt->execute();
        $customer = $stmt->get_result()->fetch_assoc();

        if (!$customer) {
            throw new Exception("Customer not found.");
        }

        $current_due = get_effective_due($conn, $shop_id, $customer);

        if ($amount > $current_due) {
            throw new Exception("Receive amount cannot be greater than current due. Current due: ৳ " . number_format($current_due, 2));
        }

        $new_due = $current_due - $amount;
        if ($new_due < 0) {
            $new_due = 0;
        }

        $insert = $conn->prepare("
            INSERT INTO customer_due_receives
                (shop_id, customer_id, receive_date, amount, payment_method, note, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->bind_param(
            "iisdssi",
            $shop_id,
            $customer_id,
            $receive_date,
            $amount,
            $payment_method,
            $note,
            $user_id
        );
        $insert->execute();

        $update = $conn->prepare("
            UPDATE customers
            SET due_amount = ?
            WHERE id = ? AND shop_id = ?
        ");
        $update->bind_param("dii", $new_due, $customer_id, $shop_id);
        $update->execute();

        $conn->commit();

        json_response([
            'success' => true,
            'message' => "Due received successfully.",
            'customer_id' => $customer_id,
            'received_amount' => $amount,
            'new_due' => $new_due
        ]);

    } catch (Exception $e) {
        if ($conn->errno === 0) {
            // no-op
        }

        try {
            if ($conn->ping()) {
                $conn->rollback();
            }
        } catch (Exception $ignored) {}

        json_response([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| HTML Modal
|--------------------------------------------------------------------------
| When this file is directly opened without AJAX, it will render only the modal.
| Normally include it in customers.php.
*/
?>

<!-- ================= DUE RECEIVE MODAL ================= -->
<div class="modal fade" id="dueReceiveModal" tabindex="-1">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-money-bill-wave"></i> Customer Due Receive
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form id="dueReceiveForm">
                <div class="modal-body">
                    <input type="hidden" name="ajax" value="receive_due">
                    <input type="hidden" name="customer_id" id="due_customer_id">

                    <div class="mb-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                        <h5 class="mb-1" id="due_customer_name">Customer Name</h5>
                        <div class="small text-muted" id="due_customer_phone"></div>
                        <div class="mt-2">
                            <span class="badge bg-danger" style="font-size:14px;">
                                Current Due: <span id="due_current_amount">৳ 0.00</span>
                            </span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Receive Date <span class="text-danger">*</span></label>
                        <input type="date" name="receive_date" id="receive_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Receive Amount <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="receive_amount" class="form-control" placeholder="0.00" required>
                        <small class="text-muted">Amount current due থেকে বেশি দেওয়া যাবে না।</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="payment_method" id="payment_method" class="form-control">
                            <option value="cash">Cash</option>
                            <option value="bkash">bKash</option>
                            <option value="nagad">Nagad</option>
                            <option value="bank">Bank</option>
                            <option value="card">Card</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <textarea name="note" id="receive_note" class="form-control" rows="2" placeholder="Optional note"></textarea>
                    </div>

                    <div class="alert alert-danger d-none" id="dueReceiveError"></div>
                    <div class="alert alert-success d-none" id="dueReceiveSuccess"></div>
                </div>

                <div class="modal-footer">
                    <a href="#" id="dueLedgerLink" class="btn btn-primary">
                        <i class="fas fa-book"></i> Ledger
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info text-white" id="dueReceiveSubmitBtn">
                        <i class="fas fa-save"></i> Save Receive
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let selectedDueCustomer = null;

window.openDueReceiveModal = function(customerIdOrObject) {
    resetDueReceiveModal();

    if (typeof customerIdOrObject === 'object' && customerIdOrObject !== null) {
        fillDueReceiveModal(customerIdOrObject);
        showDueModal();
        return;
    }

    const customerId = parseInt(customerIdOrObject || 0);
    if (!customerId) {
        alert('Invalid customer ID.');
        return;
    }

    $.ajax({
        url: 'cus_due_rcv_modal.php',
        type: 'GET',
        dataType: 'json',
        data: {
            ajax: 'get_customer',
            customer_id: customerId
        },
        success: function(response) {
            if (response.success) {
                fillDueReceiveModal(response.customer);
                showDueModal();
            } else {
                alert(response.message || 'Customer not found.');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            alert('Failed to load customer due information.');
        }
    });
};

function fillDueReceiveModal(customer) {
    selectedDueCustomer = customer;

    const currentDue = parseFloat(customer.effective_due ?? customer.due_amount ?? 0);

    $('#due_customer_id').val(customer.id || '');
    $('#due_customer_name').text(customer.name || 'Customer Name');
    $('#due_customer_phone').text((customer.phone ? 'Phone: ' + customer.phone : '') + (customer.shop_name ? ' | Shop: ' + customer.shop_name : ''));
    $('#due_current_amount').text('৳ ' + formatDueMoney(currentDue));
    $('#receive_amount').attr('max', currentDue.toFixed(2));
    $('#receive_amount').val('');
    $('#receive_note').val('');
    $('#payment_method').val('cash');
    $('#receive_date').val(new Date().toISOString().slice(0, 10));
    $('#dueLedgerLink').attr('href', 'cus_ledger.php?customer_id=' + customer.id);
}

function resetDueReceiveModal() {
    $('#dueReceiveError').addClass('d-none').text('');
    $('#dueReceiveSuccess').addClass('d-none').text('');
    $('#dueReceiveSubmitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Save Receive');
    $('#dueReceiveForm')[0].reset();
}

$('#dueReceiveForm').on('submit', function(e) {
    e.preventDefault();

    $('#dueReceiveError').addClass('d-none').text('');
    $('#dueReceiveSuccess').addClass('d-none').text('');

    const maxDue = parseFloat($('#receive_amount').attr('max') || 0);
    const amount = parseFloat($('#receive_amount').val() || 0);

    if (amount <= 0) {
        $('#dueReceiveError').removeClass('d-none').text('Receive amount must be greater than 0.');
        return;
    }

    if (amount > maxDue) {
        $('#dueReceiveError').removeClass('d-none').text('Receive amount cannot be greater than current due.');
        return;
    }

    $('#dueReceiveSubmitBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');

    $.ajax({
        url: 'cus_due_rcv_modal.php',
        type: 'POST',
        dataType: 'json',
        data: $(this).serialize(),
        success: function(response) {
            if (response.success) {
                $('#dueReceiveSuccess').removeClass('d-none').text(response.message || 'Saved successfully.');

                setTimeout(function() {
                    hideDueModal();

                    if (typeof loadCustomers === 'function') {
                        loadCustomers();
                    } else {
                        window.location.reload();
                    }
                }, 700);
            } else {
                $('#dueReceiveError').removeClass('d-none').text(response.message || 'Failed to save.');
            }
        },
        error: function(xhr) {
            console.error(xhr.responseText);
            $('#dueReceiveError').removeClass('d-none').text('Server error. Please check console.');
        },
        complete: function() {
            $('#dueReceiveSubmitBtn').prop('disabled', false).html('<i class="fas fa-save"></i> Save Receive');
        }
    });
});

function showDueModal() {
    const el = document.getElementById('dueReceiveModal');

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
        return;
    }

    if (window.jQuery && typeof $('#dueReceiveModal').modal === 'function') {
        $('#dueReceiveModal').modal('show');
        return;
    }

    alert('Bootstrap modal JS not loaded.');
}

function hideDueModal() {
    const el = document.getElementById('dueReceiveModal');

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).hide();
        return;
    }

    if (window.jQuery && typeof $('#dueReceiveModal').modal === 'function') {
        $('#dueReceiveModal').modal('hide');
    }
}

function formatDueMoney(amount) {
    return Number(amount || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}
</script>
