(function () {
  'use strict';
  function initPendingContacts() {
    // index.php replaces jQuery and Bootstrap in the footer. Use that final runtime.
    var $ = window.jQuery;
    var $modal = $('#pendingContactModal');
    if (!$modal.length) return;
    // Use the constructor directly: the earlier Bootstrap script can overwrite $.fn.modal on DOM ready.
    var modal = new window.bootstrap.Modal($modal[0]);
    var $form = $('#pendingContactForm');
    var $save = $modal.find('.pending-contact-save');
    var $error = $modal.find('.pending-contact-error');
    var $history = $modal.find('.pending-contact-history');
    var $more = $modal.find('.pending-contact-more');
    var $nextDate = $form.find('[name="next_contact_date"]');
    function updateNextContact() {
      var cancellation = $form.find('[name="outcome"]').val() === 'cancel_requested';
      $nextDate.prop('required', !cancellation).prop('disabled', cancellation);
      $form.find('.pending-contact-next').toggleClass('d-none', cancellation);
      $form.find('.pending-contact-cancel-info').toggleClass('d-none', !cancellation);
    }
    $form.find('[name="outcome"]').on('change', updateNextContact);
    var orderId = 0, cursor = 0, generation = 0, saving = false, request = null;

    function error(message) {
      $error.text(message || 'The request failed. Please try again.').removeClass('d-none');
    }
    function requestError(xhr) {
      error(xhr.responseJSON && xhr.responseJSON.error ? xhr.responseJSON.error : 'The request failed. Please try again.');
    }
    function appendHistory(rows) {
      rows.forEach(function (row) {
        var $entry = $('<div/>', {class: 'pending-contact-history-entry'});
        $('<div/>').text(row.channel_label + ' · ' + row.contacted_label + ' · ' + row.actor_name).appendTo($entry);
        $('<div/>', {class: 'small'}).text(row.outcome_label + (row.next_label ? ' · next contact: ' + row.next_label : ' · no follow-up scheduled')).appendTo($entry);
        if (row.note) $('<div/>', {class: 'small pending-contact-history-note'}).text(row.note).appendTo($entry);
        $history.append($entry);
      });
    }
    function loadHistory(more) {
      var currentGeneration = generation;
      $error.addClass('d-none').empty();
      $more.prop('disabled', true);
      request = $.ajax({
        url: 'scripts/orders/pending_contacts.php', method: 'GET', dataType: 'json',
        data: {order_id: orderId, before_id: more ? cursor : 0}
      }).done(function (data) {
        if (currentGeneration !== generation) return;
        if (!data || !data.ok) { error(data && data.error); return; }
        if (!more) {
          $('#pendingContactTitle').text('Contact · ' + data.order_label);
          $form[0].reset();
          ['order_id', 'csrf', 'request_token', 'base_contact_id'].forEach(function (key) {
            $form.find('[name="' + key + '"]').val(data[key]);
          });
          $nextDate.attr('min', data.today).val(data.suggested_date);
          updateNextContact();
          $form.toggleClass('d-none', !data.can_write);
          $save.toggleClass('d-none', !data.can_write).prop('disabled', !data.can_write);
          $modal.find('.pending-contact-resolved').toggleClass('d-none', String(data.status).toUpperCase() === 'PENDING');
          var $customer = $modal.find('.pending-contact-customer').empty();
          if (data.customer_phone) {
            $('<div/>').text('Phone: ' + data.customer_phone).appendTo($customer);
          }
          if (data.customer_email) {
            $('<div/>').text('Email: ' + data.customer_email).appendTo($customer);
          }
          $history.empty();
          if (!data.history.length) $('<div/>', {class: 'small'}).text('No contacts recorded yet.').appendTo($history);
        }
        appendHistory(data.history);
        cursor = data.next_cursor;
        $more.toggleClass('d-none', !data.has_more);
      }).fail(function (xhr, status) {
        if (currentGeneration === generation && status !== 'abort') requestError(xhr);
      }).always(function () {
        if (currentGeneration === generation) {
          $modal.find('.pending-contact-loading').addClass('d-none');
          $more.prop('disabled', false);
          request = null;
        }
      });
    }
    $(document).on('click.pendingContact', '.js-pending-contact', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (saving) return;
      orderId = parseInt($(this).attr('data-order-id'), 10) || 0;
      if (!orderId) return;
      generation++;
      if (request) request.abort();
      cursor = 0;
      $form.addClass('d-none').find('fieldset').prop('disabled', false);
      $save.addClass('d-none').prop('disabled', true).text('Save');
      $error.addClass('d-none').empty();
      $history.empty();
      $more.addClass('d-none');
      $modal.find('.pending-contact-customer').empty();
      $modal.find('.pending-contact-resolved').addClass('d-none');
      $modal.find('.pending-contact-loading').removeClass('d-none');
      $('#pendingContactTitle').text('Customer contact');
      modal.show();
      loadHistory(false);
    });
    $modal.find('[data-dismiss="modal"]').on('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      modal.hide();
    });
    $more.on('click', function () { if (!request) loadHistory(true); });
    $modal.on('hide.bs.modal', function (event) {
      if (saving) { event.preventDefault(); return; }
      generation++;
      if (request) request.abort();
    });
    $form.on('submit', function (event) {
      event.preventDefault();
      if (saving || !$form[0].reportValidity()) return;
      var payload = $form.serialize();
      saving = true;
      $error.addClass('d-none').empty();
      $form.find('fieldset').prop('disabled', true);
      $save.prop('disabled', true).text('Saving…');
      $.ajax({
        url: 'scripts/orders/pending_contacts.php', method: 'POST', dataType: 'json', data: payload
      }).done(function (data) {
        if (!data || !data.ok) { error(data && data.error); return; }
        // Reload preserves the selected filter and refreshes counts, ordering and open detail data.
        window.location.reload();
      }).fail(requestError).always(function () {
        saving = false;
        $form.find('fieldset').prop('disabled', false);
        updateNextContact();
        $save.prop('disabled', false).text('Save');
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPendingContacts, {once: true});
  } else {
    initPendingContacts();
  }
})();
