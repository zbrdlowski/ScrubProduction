  // TAKE order
  $(document).on('click', '.btn-take-order', function () {
    const orderId = $(this).data('order-id');
    const $btn = $(this);
    $btn.prop('disabled', true).text('...');

    $.ajax({
      url: 'scripts/orders/take_order.php',
      method: 'POST',
      dataType: 'json',
      data: { order_id: orderId },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert('TAKE error: ' + (resp && resp.error ? resp.error : 'unknown'));
          $btn.prop('disabled', false).text('TAKE');
          return;
        }
        // najjednoduchšie: refresh page (aby sa načítali badges)
        location.reload();
      },
      error: function () {
        alert('TAKE error (request failed)');
        $btn.prop('disabled', false).text('TAKE');
      }
    });
  });

    $(document).on('change', '.order-status-select', function () {
    const $select = $(this);
    const orderId = $select.data('order-id');
    const status = $select.val();

    $select.prop('disabled', true);

    $.post('scripts/orders/update_order_status.php', {
      order_id: orderId,
      status: status
    }, function (res) {
      if (!res || !res.ok) {
        alert(res && res.error ? res.error : 'Status update failed');
        $select.prop('disabled', false);
        return;
      }

      location.reload();

    }, 'json').fail(function () {
      alert('Status update request failed');
      $select.prop('disabled', false);
    });
  });

    // fallback pre zatváranie modalu
  $(document).on('click', '.btn-copy-inline', async function (e) {
    e.preventDefault();
    e.stopPropagation();

    const $btn = $(this);
    const text = $btn.attr('data-copy') || '';

    let copied = false;

    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        copied = true;
      } else {
        copied = copyTextFallback(text);
      }
    } catch (err) {
      copied = copyTextFallback(text);
    }

    if (copied) {
      $btn.text('✔');

      setTimeout(() => {
        $btn.text('📋');
      }, 800);
    } else {
      $btn.text('!');
      console.error('Copy failed:', text);

      setTimeout(() => {
        $btn.text('📋');
      }, 800);
    }
  });

    $(document).on('click', '.btn-remove-assignment', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const assignmentId = $(this).data('assignment-id');

    if (!confirm('Remove this assignment?')) {
      return;
    }

    $.ajax({
      url: 'scripts/orders/remove_order_assignment.php',
      method: 'POST',
      dataType: 'json',
      data: {
        assignment_id: assignmentId
      },
      success: function (resp) {
        if (!resp || !resp.ok) {
          alert(resp && resp.error ? resp.error : 'Remove assignment failed');
          return;
        }

        location.reload();
      },
      error: function (xhr) {
        console.log(xhr.responseText);
        alert('Remove assignment request failed');
      }
    });
  });