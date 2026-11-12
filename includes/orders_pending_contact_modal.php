<?php if ($pendingContactsReady): ?>
<style>
#ordersTable.orders-pending-contacts .traffic-cell { white-space: nowrap; }
#ordersTable .pending-contact-last-column,
#ordersTable .pending-contact-next-column { vertical-align: middle; }
.pending-contact-last { width: 160px; line-height: 1.3; font-size: .85rem; }
.pending-contact-last-heading { display: flex; align-items: center; gap: 5px; }
.pending-contact-line { display: block; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pending-contact-note-icon { flex: 0 0 auto; opacity: .75; }
.pending-contact-empty { opacity: .55; }
.pending-contact-cell { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 190px; white-space: nowrap; line-height: 1.3; }
.pending-contact-cell .js-pending-contact { flex: 0 0 auto; margin: 0; white-space: nowrap; }
.pending-contact-schedule { flex: 0 0 auto; }
.pending-contact-secondary { font-size: .8rem; opacity: .8; white-space: nowrap; }
#ordersTable .pending-contact-due > td:first-child { box-shadow: inset 4px 0 #ffc107; }
#ordersTable .pending-contact-repeat > td:first-child,
#ordersTable .pending-contact-cancel_requested > td:first-child { box-shadow: inset 4px 0 #dc3545; }
@media (max-width: 1600px) {
  #ordersTable.orders-pending-contacts [data-order-customer-name] { display: inline-block; max-width: 120px; vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  #ordersTable.orders-pending-contacts .traffic-cell .badge { font-size: .9rem !important; padding: .4em .5em !important; }
  .pending-contact-last { width: 145px; }
  .pending-contact-cell { min-width: 175px; gap: 6px; }
}
.pending-contact-filters { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
#pendingContactModal .pending-contact-history-entry { padding: 10px 0; border-bottom: 1px solid rgba(127,127,127,.3); }
#pendingContactModal .pending-contact-history-note { white-space: pre-wrap; overflow-wrap: anywhere; }
#pendingContactModal .modal-body { max-height: 75vh; overflow-y: auto; }
</style>
<div class="modal fade" id="pendingContactModal" tabindex="-1" role="dialog" aria-labelledby="pendingContactTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content bg-dark text-light">
      <div class="modal-header">
        <h5 class="modal-title" id="pendingContactTitle">Customer contact</h5>
        <button type="button" class="close text-light" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        <div class="pending-contact-error alert alert-danger d-none" role="alert"></div>
        <div class="pending-contact-loading" role="status">Loading contacts…</div>
        <div class="pending-contact-customer mb-3"></div>
        <div class="pending-contact-resolved alert alert-info d-none">This order is no longer PENDING. Contact history is still available.</div>
        <form id="pendingContactForm" class="d-none">
          <input type="hidden" name="order_id"><input type="hidden" name="csrf">
          <input type="hidden" name="request_token"><input type="hidden" name="base_contact_id">
          <fieldset>
            <div class="form-row">
              <div class="form-group col-sm-6">
                <label for="pendingContactChannel">Contact method</label>
                <select id="pendingContactChannel" name="channel" class="form-control form-control-sm" required><option value="">Select...</option>
                  <?php foreach (pendingContactChannels() as $value => $label): ?>
                    <option value="<?= pendingContactEscape($value) ?>"><?= pendingContactEscape($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-sm-6">
                <label for="pendingContactOutcome">Outcome</label>
                <select id="pendingContactOutcome" name="outcome" class="form-control form-control-sm" required><option value="">Select...</option>
                  <?php foreach (pendingContactOutcomes() as $value => $label): ?>
                    <option value="<?= pendingContactEscape($value) ?>"><?= pendingContactEscape($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label for="pendingContactNote">Note</label>
              <textarea id="pendingContactNote" name="note" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="E.g. promised to pay on Friday"></textarea>
            </div>
            <div class="form-group pending-contact-next">
              <label for="pendingContactNext">Next contact</label>
              <input id="pendingContactNext" type="date" name="next_contact_date" class="form-control form-control-sm" required>
              <small>This order will appear in To contact again on this date.</small>
            </div>
            <p class="small pending-contact-cancel-info d-none" role="status">No follow-up is scheduled for a cancellation request. This order will appear in To cancel. Complete the cancellation using the existing order action.</p>
            <p class="small">Recording a contact does not change the order status. Confirm payment by entering the received amount in the order details.</p>
          </fieldset>
        </form>
        <h6 class="mt-4">Contact history</h6>
        <div class="pending-contact-history"></div>
        <button type="button" class="btn btn-sm btn-outline-info mt-2 pending-contact-more d-none">Older contacts</button>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="submit" form="pendingContactForm" class="btn btn-info btn-sm pending-contact-save d-none" disabled>Save</button>
      </div>
    </div>
  </div>
</div>
<?php $pendingContactJsVersion = @filemtime(__DIR__ . '/../scripts/orders/pending_contacts.js') ?: time(); ?>
<script src="scripts/orders/pending_contacts.js?v=<?= (int) $pendingContactJsVersion ?>"></script>
<?php endif; ?>
