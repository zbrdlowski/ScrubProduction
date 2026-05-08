<?php
declare(strict_types=1);

require_once __DIR__ . '/conn.php';

$dpt = (int) ($_SESSION['dpt'] ?? 0);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($dpt !== 2) {
    echo '<div class="alert alert-warning">Only Graphics department allowed.</div>';
    return;
}

$sql = "
SELECT 
    o.id,
    o.order_number,
    o.status,
    o.order_date,
    cu.name AS customer_name,
    os.code AS source_code,
    oa.role
FROM orders o
JOIN order_assignments oa ON oa.order_id = o.id
JOIN order_sources os ON os.id = o.source_id
LEFT JOIN customers cu ON cu.id = o.customer_id

WHERE 
    oa.employee_id = ?
    AND oa.removed_at IS NULL
    AND oa.role IN ('PRIMARY_GRAPHICS','COLLAB_GRAPHICS')
    AND UPPER(o.status) != 'SHIPPED'

ORDER BY o.order_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
?>

<table class="table table-sm table-hover">
    <thead>
        <tr>
            <th>Date</th>
            <th>Order</th>
            <th>Status</th>
            <th>Customer</th>
            <th>Role</th>
            <th></th>
        </tr>
    </thead>

    <tbody>

        <?php while ($row = $res->fetch_assoc()): ?>

            <tr class="profile-order-row order-row" data-order-id="<?= $row['id'] ?>">
                <td><?= date('d.m.Y', strtotime($row['order_date'])) ?></td>
                <td><b><?= htmlspecialchars($row['order_number']) ?></b></td>
                <td><?= htmlspecialchars($row['status']) ?></td>
                <td><?= htmlspecialchars($row['customer_name'] ?? '-') ?></td>
                <td>
                    <?= $row['role'] === 'PRIMARY_GRAPHICS'
                        ? '<span class="badge badge-info">Mine</span>'
                        : '<span class="badge badge-secondary">Collab</span>' ?>
                </td>
                <td>
                    <button type="button" class="btn btn-info btn-xs btn-profile-order-detail"
                        data-order-id="<?= $row['id'] ?>">
                        Open
                    </button>
                </td>
            </tr>

            <tr class="profile-order-detail-row order-detail-row" data-detail-for="<?= $row['id'] ?>" style="display:none;">
                <td colspan="6"></td>
            </tr>

        <?php endwhile; ?>

    </tbody>
</table>

<script>
    $(document).on('click', '.btn-detail', function () {
        let id = $(this).data('id');
        let row = $('.profile-order-detail-row[data-detail-for="' + id + '"]');

        if (row.is(':visible')) {
            row.hide();
            return;
        }

        $('.profile-order-detail-row').hide();

        row.show().find('td').html('Loading...');

        $.post('scripts/profile_orders/get_order_detail.php', { order_id: id }, function (resp) {
            if (resp.ok) {
                row.find('td').html(resp.html);
            } else {
                row.find('td').html('<div class="alert alert-danger">' + resp.error + '</div>');
            }
        }, 'json');
    });
    $(document).off('click.profileOrders');

    $(document).on('click.profileOrders', '.profile-order-row', function (e) {
        if ($(e.target).closest('button, a, input, select, textarea').length) {
            return;
        }

        openProfileOrderDetail($(this).data('order-id'));
    });

    $(document).on('click.profileOrders', '.btn-profile-order-detail', function (e) {
        e.preventDefault();
        e.stopPropagation();

        openProfileOrderDetail($(this).data('order-id'));
    });

    function openProfileOrderDetail(orderId) {
        const $row = $('.profile-order-row[data-order-id="' + orderId + '"]');
        const $detailRow = $('.profile-order-detail-row[data-detail-for="' + orderId + '"]');
        const $cell = $detailRow.find('td');

        if ($detailRow.is(':visible')) {
            $detailRow.hide();
            $row.removeClass('order-row-open');
            return;
        }

        $('.profile-order-detail-row').hide();
        $('.profile-order-row').removeClass('order-row-open');

        $row.addClass('order-row-open');
        $detailRow.show();
        $cell.html('<div class="p-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Loading...</div>');

        $.ajax({
            url: 'scripts/profile_orders/get_order_detail.php',
            method: 'POST',
            dataType: 'json',
            data: { order_id: orderId },
            success: function (resp) {
                if (!resp || !resp.ok) {
                    $cell.html('<div class="alert alert-danger m-3">' + (resp.error || 'Detail load failed') + '</div>');
                    return;
                }

                $cell.html(resp.html);
            },
            error: function () {
                $cell.html('<div class="alert alert-danger m-3">Detail load failed.</div>');
            }
        });
    }
</script>