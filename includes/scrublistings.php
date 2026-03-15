<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$modelcode = isset($_GET['modelcode']) ? trim($_GET['modelcode']) : '';
$modelcode = mb_substr($modelcode, 0, 32);

$canEdit = (isset($_SESSION['permission']) && (int)$_SESSION['permission'] >= 300);
?>
<section class="content">
  <div class="container-fluid">

    <div class="row">
      <div class="col-12">
        <div class="card card-dark shadow-sm">
          <div class="card-header">
            <h3 class="card-title">
              Scrub Listings
              <?php if ($modelcode !== ''): ?>
                <span class="badge badge-secondary ml-2">Model: <?= htmlspecialchars($modelcode) ?></span>
              <?php endif; ?>
            </h3>
          </div>
          <div class="card-body">

            <?php if ($modelcode === ''): ?>
              <div class="alert alert-warning mb-0">
                Chýba parameter <b>modelcode</b>. Použi napr.:
                <code>?page=scrublistings&amp;modelcode=74PT</code>
              </div>
            <?php else: ?>

              <?php
              $sqlListings = "SELECT id, listing_code, listing_name, model_code, price
                              FROM scrub_listings
                              WHERE model_code = ? AND is_active = 1
                              ORDER BY listing_code ASC";

              $stmt = $conn->prepare($sqlListings);
              if (!$stmt) {
                echo '<div class="alert alert-danger">DB error: ' . htmlspecialchars($conn->error) . '</div>';
              } else {
                $stmt->bind_param("s", $modelcode);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res->num_rows === 0) {
                  echo '<div class="alert alert-info mb-0">Pre modelcode <b>' . htmlspecialchars($modelcode) . '</b> nie sú žiadne listingy.</div>';
                } else {

                  $sqlItems = "SELECT
                                sli.barcode,
                                sli.sort_order,
                                i.name,
                                i.description,
                                i.color,
                                i.quantity,
                                i.main_supplier
                              FROM scrub_listing_items sli
                              LEFT JOIN items i ON i.barcode = sli.barcode
                              WHERE sli.listing_id = ?
                              ORDER BY COALESCE(sli.sort_order, 999999), sli.id ASC";

                  $stmtItems = $conn->prepare($sqlItems);
                  if (!$stmtItems) {
                    echo '<div class="alert alert-danger">DB error (items stmt): ' . htmlspecialchars($conn->error) . '</div>';
                  } else {

                    while ($listing = $res->fetch_assoc()) {
                      $listingId   = (int)$listing['id'];
                      $listingCode = (string)$listing['listing_code'];
                      $listingName = (string)$listing['listing_name'];
                      $price       = $listing['price'];

                      $stmtItems->bind_param("i", $listingId);
                      $stmtItems->execute();
                      $resItems = $stmtItems->get_result();
                      $itemCount = $resItems->num_rows;

                      $modalEditListingId = 'modalEditListing' . $listingId;
                      $modalDiscListingId = 'modalDiscListing' . $listingId;
                      ?>

                      <div class="card card-primary card-outline shadow-sm">
                        <div class="card-header bg-gradient-primary">
                          <div class="d-flex justify-content-between align-items-center">
                            <div>
                              <h3 class="card-title mb-0 text-white">
                                <span class="badge badge-dark mr-2"><?= htmlspecialchars($listingCode) ?></span>
                                <?= htmlspecialchars($listingName) ?>
                              </h3>
                              <div class="text-white-50 small mt-1">
                                Items: <b><?= (int)$itemCount ?></b>
                                <?php if ($price !== null): ?>
                                  · Price: <b><?= htmlspecialchars(number_format((float)$price, 2)) ?> €</b>
                                <?php endif; ?>
                              </div>
                            </div>

                            <div class="card-tools">
                              <?php if ($canEdit): ?>
                                <button type="button"
                                        class="btn btn-tool text-white"
                                        data-toggle="modal"
                                        data-target="#<?= htmlspecialchars($modalEditListingId) ?>"
                                        title="Edit listing">
                                  <i class="fas fa-edit"></i>
                                </button>

                                <button type="button"
                                        class="btn btn-tool text-white"
                                        data-toggle="modal"
                                        data-target="#<?= htmlspecialchars($modalDiscListingId) ?>"
                                        title="Discontinue listing">
                                  <i class="fas fa-ban"></i>
                                </button>
                              <?php endif; ?>

                              <button type="button"
                                      class="btn btn-tool text-white"
                                      data-card-widget="collapse"
                                      title="Collapse">
                                <i class="fas fa-minus"></i>
                              </button>
                            </div>
                          </div>
                        </div>

                        <div class="card-body p-0">

                          <?php if ($canEdit): ?>
                            <!-- Inline add barcode -->
                            <div class="p-3 border-bottom">
                              <form class="form-inline" onsubmit="return scrubAddBarcode(<?= (int)$listingId ?>, this);">
                                <input type="text"
                                       name="barcode"
                                       class="form-control form-control-sm mr-2"
                                       placeholder="Add barcode…"
                                       maxlength="64"
                                       required>
                                <button type="submit" class="btn btn-sm btn-primary">
                                  <i class="fas fa-plus"></i> Add
                                </button>
                                <small class="text-muted ml-3">Tip: Enter pridá položku</small>
                              </form>
                            </div>
                          <?php endif; ?>

                          <?php if ($itemCount === 0): ?>
                            <div class="p-3">
                              <div class="alert alert-warning mb-0">Tento listing nemá žiadne položky.</div>
                            </div>
                          <?php else: ?>

                            <div class="table-responsive">
                              <table class="table table-dark table-striped table-hover table-sm mb-0">
                                <thead>
                                  <tr>
                                    <th style="width: 60px;" class="text-center">#</th>
                                    <th style="width: 120px;">Barcode</th>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th style="width: 110px;">Color</th>
                                    <th style="width: 90px;" class="text-right">Qty</th>
                                    <th style="width: 160px;">Supplier</th>
                                    <?php if ($canEdit): ?>
                                      <th style="width: 90px;" class="text-right">Actions</th>
                                    <?php endif; ?>
                                  </tr>
                                </thead>
                                <tbody>
                                <?php
                                $rowNo = 0;
                                while ($it = $resItems->fetch_assoc()) {
                                  $rowNo++;
                                  $barcode = (string)$it['barcode'];
                                  $name = $it['name'];
                                  $desc = $it['description'];
                                  $color = $it['color'];
                                  $qty = $it['quantity'];
                                  $supplier = $it['main_supplier'];

                                  $missing = ($name === null);

                                  $modalEditBarcodeId = 'modalEditBarcode_' . $listingId . '_' . $rowNo;
                                  ?>
                                  <tr class="<?= $missing ? 'table-warning' : '' ?>">
                                    <td class="text-center font-weight-bold"><?= (int)$rowNo ?></td>

                                    <td>
                                      <code><?= htmlspecialchars($barcode) ?></code>
                                      <?php if ($missing): ?>
                                        <div class="small text-muted">nenájdené v items</div>
                                      <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars($name ?? '—') ?></td>

                                    <td class="text-muted">
                                      <?php
                                      $safeDesc = trim($desc ?? '');
                                      if ($safeDesc === '') {
                                        echo '—';
                                      } else {
                                        $short = mb_substr($safeDesc, 0, 140);
                                        echo htmlspecialchars($short) . (mb_strlen($safeDesc) > 140 ? '…' : '');
                                      }
                                      ?>
                                    </td>

                                    <td><?= htmlspecialchars($color ?? '—') ?></td>

                                    <td class="text-right"><?= htmlspecialchars($qty !== null ? (string)$qty : '—') ?></td>

                                    <td><?= htmlspecialchars($supplier ?? '—') ?></td>

                                    <?php if ($canEdit): ?>
                                      <td class="text-right">
                                        <button type="button"
                                                class="btn btn-xs btn-outline-light"
                                                data-toggle="modal"
                                                data-target="#<?= htmlspecialchars($modalEditBarcodeId) ?>"
                                                title="Edit barcode">
                                          <i class="fas fa-pen"></i>
                                        </button>
                                        <button type="button"
                                                class="btn btn-xs btn-outline-danger"
                                                onclick="return scrubDeleteBarcode(<?= (int)$listingId ?>,'<?= htmlspecialchars($barcode, ENT_QUOTES) ?>');"
                                                title="Delete barcode">
                                          <i class="fas fa-trash"></i>
                                        </button>
                                      </td>
                                    <?php endif; ?>
                                  </tr>

                                  <?php if ($canEdit): ?>
                                    <!-- Edit barcode modal -->
                                    <div class="modal fade" id="<?= htmlspecialchars($modalEditBarcodeId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                      <div class="modal-dialog modal-sm" role="document">
                                        <div class="modal-content bg-dark">
                                          <div class="modal-header">
                                            <h5 class="modal-title">Edit barcode</h5>
                                            <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                              <span aria-hidden="true">&times;</span>
                                            </button>
                                          </div>
                                          <div class="modal-body">
                                            <form onsubmit="return scrubUpdateBarcode(<?= (int)$listingId ?>,'<?= htmlspecialchars($barcode, ENT_QUOTES) ?>', this);">
                                              <div class="form-group">
                                                <label class="small text-muted mb-1">Old</label>
                                                <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($barcode) ?>" disabled>
                                              </div>
                                              <div class="form-group mb-0">
                                                <label class="small text-muted mb-1">New barcode</label>
                                                <input type="text" name="new_barcode" class="form-control form-control-sm" maxlength="64" required>
                                              </div>
                                              <div class="mt-3 text-right">
                                                <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                              </div>
                                            </form>
                                          </div>
                                        </div>
                                      </div>
                                    </div>
                                  <?php endif; ?>

                                  <?php
                                } // while items
                                ?>
                                </tbody>
                              </table>
                            </div>

                          <?php endif; ?>
                        </div>
                      </div>

                      <?php if ($canEdit): ?>
                        <!-- Edit listing modal -->
                        <div class="modal fade" id="<?= htmlspecialchars($modalEditListingId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog" role="document">
                            <div class="modal-content bg-dark">
                              <div class="modal-header">
                                <h5 class="modal-title">Edit listing</h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                  <span aria-hidden="true">&times;</span>
                                </button>
                              </div>
                              <div class="modal-body">
                                <form onsubmit="return scrubUpdateListing(<?= (int)$listingId ?>, this);">
                                  <div class="form-group">
                                    <label class="small text-muted mb-1">Listing code</label>
                                    <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($listingCode) ?>" disabled>
                                  </div>

                                  <div class="form-group">
                                    <label class="small text-muted mb-1">Listing name</label>
                                    <input type="text" name="listing_name" class="form-control form-control-sm" maxlength="255"
                                           value="<?= htmlspecialchars($listingName) ?>" required>
                                  </div>

                                  <div class="form-group mb-0">
                                    <label class="small text-muted mb-1">Price (€)</label>
                                    <input type="number" step="0.01" name="price" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars($price !== null ? (string)$price : '') ?>">
                                  </div>

                                  <div class="mt-3 text-right">
                                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                          </div>
                        </div>

                        <!-- Discontinue listing modal -->
                        <div class="modal fade" id="<?= htmlspecialchars($modalDiscListingId) ?>" tabindex="-1" role="dialog" aria-hidden="true">
                          <div class="modal-dialog" role="document">
                            <div class="modal-content bg-dark">
                              <div class="modal-header">
                                <h5 class="modal-title">Discontinue listing</h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                  <span aria-hidden="true">&times;</span>
                                </button>
                              </div>
                              <div class="modal-body">
                                <div class="alert alert-warning">
                                  Tento listing sa prestane zobrazovať (is_active=0).
                                </div>

                                <form onsubmit="return scrubDiscontinueListing(<?= (int)$listingId ?>, this);">
                                  <div class="form-group mb-0">
                                    <label class="small text-muted mb-1">Reason (optional)</label>
                                    <input type="text" name="reason" class="form-control form-control-sm" maxlength="255"
                                           placeholder='e.g. "Manufacturer discontinued"'/>
                                  </div>

                                  <div class="mt-3 text-right">
                                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-danger">Discontinue</button>
                                  </div>
                                </form>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endif; ?>

                      <?php
                    } // while listing

                    $stmtItems->close();
                  }

                } // has listings

                $stmt->close();
              }
              ?>

            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
<? include('includes/scrublistings_import.php'); ?>
  </div>
</section>

<script>
  // Uprav podľa toho, kde máš root projektu
  const SCRIPTS_BASE = 'scripts/scrublistings';

  async function scrubPost(endpoint, data) {
    const resp = await fetch(`${SCRIPTS_BASE}/${endpoint}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: new URLSearchParams(data).toString()
    });

    let json = null;
    try { json = await resp.json(); } catch (e) {}

    if (!resp.ok || !json || json.ok !== true) {
      const msg = (json && json.error) ? json.error : `Request failed (${resp.status})`;
      throw new Error(msg);
    }
    return json;
  }

  function scrubToast(msg, type='success') {
    // jednoduchý fallback: alert; neskôr môžeme spraviť AdminLTE Toasts
    if (type === 'error') alert(msg);
  }

  async function scrubUpdateListing(listingId, formEl) {
    try {
      const listing_name = formEl.querySelector('[name="listing_name"]').value.trim();
      const price = formEl.querySelector('[name="price"]').value.trim();

      await scrubPost('update_listing.php', {
        listing_id: listingId,
        listing_name: listing_name,
        price: price
      });

      location.reload();
      return false;
    } catch (e) {
      scrubToast(e.message, 'error');
      return false;
    }
  }

  async function scrubDiscontinueListing(listingId, formEl) {
    if (!confirm('Naozaj chceš discontinue tento listing?')) return false;

    try {
      const reason = formEl.querySelector('[name="reason"]').value.trim();
      await scrubPost('discontinue_listing.php', {
        listing_id: listingId,
        reason: reason
      });
      location.reload();
      return false;
    } catch (e) {
      scrubToast(e.message, 'error');
      return false;
    }
  }

  async function scrubAddBarcode(listingId, formEl) {
    try {
      const barcode = formEl.querySelector('[name="barcode"]').value.trim();
      await scrubPost('add_barcode.php', {
        listing_id: listingId,
        barcode: barcode
      });
      location.reload();
      return false;
    } catch (e) {
      scrubToast(e.message, 'error');
      return false;
    }
  }

  async function scrubDeleteBarcode(listingId, barcode) {
    if (!confirm(`Vymazať barcode ${barcode} z listingu?`)) return false;

    try {
      await scrubPost('delete_barcode.php', {
        listing_id: listingId,
        barcode: barcode
      });
      location.reload();
      return false;
    } catch (e) {
      scrubToast(e.message, 'error');
      return false;
    }
  }

  async function scrubUpdateBarcode(listingId, oldBarcode, formEl) {
    try {
      const newBarcode = formEl.querySelector('[name="new_barcode"]').value.trim();
      await scrubPost('update_barcode.php', {
        listing_id: listingId,
        old_barcode: oldBarcode,
        new_barcode: newBarcode
      });
      location.reload();
      return false;
    } catch (e) {
      scrubToast(e.message, 'error');
      return false;
    }
  }
</script>