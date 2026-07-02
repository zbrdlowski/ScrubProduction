<?php
$_SESSION['uri'] = $_SERVER['REQUEST_URI'];

$tablesPresent = true;
$requiredTables = [
  'scrub_catalog_products',
  'scrub_catalog_product_listings',
];

foreach ($requiredTables as $tableName) {
  $safeTableName = $conn->real_escape_string($tableName);
  $tableCheck = $conn->query("SHOW TABLES LIKE '{$safeTableName}'");
  if (!$tableCheck || $tableCheck->num_rows === 0) {
    $tablesPresent = false;
    break;
  }
}

$canEdit = isset($_SESSION['permission']) && (int) $_SESSION['permission'] >= 300;
$search = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$marketplace = trim((string) ($_GET['marketplace'] ?? ''));
$listingStatus = trim((string) ($_GET['listing_status'] ?? ''));
$brand = trim((string) ($_GET['brand'] ?? ''));
$selectedProductId = (int) ($_GET['product_id'] ?? 0);
$importMessage = trim((string) ($_GET['import_message'] ?? ''));

$allowedTypes = ['design', 'seatcover'];
$allowedMarketplaces = ['shoptet', 'ebay'];
$allowedListingStatuses = ['listed', 'not_listed'];

if (!in_array($type, $allowedTypes, true)) {
  $type = '';
}

if (!in_array($marketplace, $allowedMarketplaces, true)) {
  $marketplace = '';
}

if (!in_array($listingStatus, $allowedListingStatuses, true)) {
  $listingStatus = '';
}
?>

<section class="content">
  <div class="container-fluid">
    <div class="card card-dark shadow-sm">
      <div class="card-header">
        <h3 class="card-title">Product Listing Catalog</h3>
      </div>
      <div class="card-body">
        <p class="text-muted mb-3">
          Prehlad designov a seat coverov s tym, na ake modely su zalistovane na Shoptete alebo eBay.
        </p>

        <?php if ($importMessage !== ''): ?>
          <div class="alert alert-info"><?= htmlspecialchars($importMessage) ?></div>
        <?php endif; ?>

        <?php if (!$tablesPresent): ?>
          <div class="alert alert-warning mb-0">
            Tento modul potrebuje DB schemu z <code>db/product_listing_catalog.sql</code>.
            Po vytvoreni tabuliek sa tu zobrazi katalog aj import.
          </div>
        <?php else: ?>
          <div class="card card-outline card-secondary mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h3 class="card-title mb-0">Filters</h3>
              <?php if ($canEdit): ?>
                <button type="button" class="btn btn-sm btn-success" data-toggle="modal"
                  data-target="#productCatalogImportModal">
                  <i class="fas fa-file-upload mr-1"></i> Open Import
                </button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="get" action="index.php">
                <input type="hidden" name="page" value="product_listing_catalog">
                <div class="form-row align-items-end">
                  <div class="col-lg-3 col-md-6 mb-2">
                    <label class="small text-muted mb-1">Search</label>
                    <input type="text" class="form-control form-control-sm" name="q"
                      value="<?= htmlspecialchars($search) ?>" placeholder="Product code, name, model code...">
                  </div>

                  <div class="col-lg-2 col-md-6 mb-2">
                    <label class="small text-muted mb-1">Type</label>
                    <select class="form-control form-control-sm" name="type">
                      <option value="">All</option>
                      <option value="design" <?= $type === 'design' ? 'selected' : '' ?>>Design</option>
                      <option value="seatcover" <?= $type === 'seatcover' ? 'selected' : '' ?>>Seat cover</option>
                    </select>
                  </div>

                  <div class="col-lg-2 col-md-6 mb-2">
                    <label class="small text-muted mb-1">Marketplace</label>
                    <select class="form-control form-control-sm" name="marketplace">
                      <option value="">All</option>
                      <option value="shoptet" <?= $marketplace === 'shoptet' ? 'selected' : '' ?>>Shoptet</option>
                      <option value="ebay" <?= $marketplace === 'ebay' ? 'selected' : '' ?>>eBay</option>
                    </select>
                  </div>

                  <div class="col-lg-2 col-md-6 mb-2">
                    <label class="small text-muted mb-1">Listing status</label>
                    <select class="form-control form-control-sm" name="listing_status">
                      <option value="">All listed rows</option>
                      <option value="listed" <?= $listingStatus === 'listed' ? 'selected' : '' ?>>Is Listed</option>
                      <option value="not_listed" <?= $listingStatus === 'not_listed' ? 'selected' : '' ?>>Is NOT Listed
                      </option>
                    </select>
                  </div>

                  <div class="col-lg-2 col-md-6 mb-2">
                    <label class="small text-muted mb-1">Brand</label>
                    <select class="form-control form-control-sm" name="brand">
                      <option value="">All</option>
                      <?php
                      $brandResult = $conn->query("SELECT DISTINCT brand FROM scrubdata ORDER BY brand ASC");
                      if ($brandResult) {
                        while ($brandRow = $brandResult->fetch_assoc()) {
                          $brandName = (string) $brandRow['brand'];
                          $selected = $brandName === $brand ? 'selected' : '';
                          echo '<option value="' . htmlspecialchars($brandName) . '" ' . $selected . '>'
                            . htmlspecialchars($brandName)
                            . '</option>';
                        }
                      }
                      ?>
                    </select>
                  </div>

                  <div class="col-lg-1 col-md-6 mb-2">
                    <label class="small text-muted mb-1 d-block">&nbsp;</label>
                    <div class="btn-group btn-group-sm w-100" role="group">
                      <button type="submit" class="btn btn-primary">Filter</button>
                      <a href="index.php?page=product_listing_catalog" class="btn btn-secondary">Reset</a>
                    </div>
                  </div>
                </div>

                <div class="text-muted small mt-1">
                  Is NOT Listed sa prejavi hlavne v detaile konkretneho produktu.
                </div>
              </form>
            </div>
          </div>

          <?php if ($canEdit): ?>
            <div class="modal fade" id="productCatalogImportModal" tabindex="-1" role="dialog" aria-hidden="true">
              <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content bg-dark">
                  <div class="modal-header">
                    <h5 class="modal-title">CSV Import</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                      <span aria-hidden="true">&times;</span>
                    </button>
                  </div>
                  <div class="modal-body">
                    <p class="text-muted mb-3">
                      Ocakavany header:
                      <code>product_type,product_code,product_name,model_code,marketplace,external_code,external_url,listing_title,is_active</code>
                    </p>
                    <form method="post" action="scripts/product_listing_catalog/import_csv.php"
                      enctype="multipart/form-data">
                      <div class="form-group">
                        <label class="small text-muted mb-1">CSV file</label>
                        <input type="file" class="form-control-file" name="csv_file" accept=".csv,text/csv" required>
                      </div>
                      <div class="text-right mt-4">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                          <i class="fas fa-file-upload mr-1"></i> Import CSV
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php
          $products = [];
          $hasProductFilter = ($search !== '' || $type !== '' || $marketplace !== '' || $brand !== '');

          if ($hasProductFilter) {
            $sql = "
                SELECT
                  p.id,
                  p.product_type,
                  p.product_code,
                  p.product_name,
                  COUNT(DISTINCT pl.model_code) AS model_count,
                  COUNT(*) AS listing_count,
                  SUM(CASE WHEN pl.marketplace = 'shoptet' AND pl.is_active = 1 THEN 1 ELSE 0 END) AS shoptet_count,
                  SUM(CASE WHEN pl.marketplace = 'ebay' AND pl.is_active = 1 THEN 1 ELSE 0 END) AS ebay_count
                FROM scrub_catalog_products p
                INNER JOIN scrub_catalog_product_listings pl ON pl.product_id = p.id
                LEFT JOIN scrubdata sd ON sd.modelcode = pl.model_code
              ";

            $where = [];
            $types = '';
            $params = [];

            if ($search !== '') {
              $where[] = "(p.product_code LIKE ? OR p.product_name LIKE ? OR pl.model_code LIKE ? OR pl.external_code LIKE ?)";
              $types .= 'ssss';
              $likeSearch = '%' . $search . '%';
              $params[] = $likeSearch;
              $params[] = $likeSearch;
              $params[] = $likeSearch;
              $params[] = $likeSearch;
            }

            if ($type !== '') {
              $where[] = "p.product_type = ?";
              $types .= 's';
              $params[] = $type;
            }

            if ($marketplace !== '') {
              $where[] = "pl.marketplace = ?";
              $types .= 's';
              $params[] = $marketplace;
            }

            if ($brand !== '') {
              $where[] = "sd.brand = ?";
              $types .= 's';
              $params[] = $brand;
            }

            if ($where) {
              $sql .= ' WHERE ' . implode(' AND ', $where);
            }

            $sql .= "
                GROUP BY p.id, p.product_type, p.product_code, p.product_name
                ORDER BY p.product_type ASC, p.product_name ASC, p.product_code ASC
              ";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
              if ($params) {
                $bindParams = [$types];
                foreach ($params as $paramIndex => $paramValue) {
                  $bindParams[] = &$params[$paramIndex];
                }
                call_user_func_array([$stmt, 'bind_param'], $bindParams);
              }

              $stmt->execute();
              $result = $stmt->get_result();
              while ($row = $result->fetch_assoc()) {
                $products[] = $row;
              }
              $stmt->close();
            }
          }
          ?>

          <div class="card card-outline card-secondary">
            <div class="card-header">
              <h3 class="card-title">Products</h3>
            </div>
            <div class="card-body p-0">
              <?php if (!$hasProductFilter): ?>
                <div class="alert alert-info m-3 mb-0">Zadaj neičo do Filtra. Products tabuľka sa bez filtra nenačíta, aby
                  zbytočne neťahala celý katalóg.</div>
              <?php elseif (!$products): ?>
                <div class="alert alert-info m-3 mb-0">Filtre nenasli ziadne produkty.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover table-striped mb-0">
                    <thead>
                      <tr>
                        <th>Type</th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th class="text-center">Models</th>
                        <th class="text-center">Shoptet</th>
                        <th class="text-center">eBay</th>
                        <th class="text-center">Listings</th>
                        <th style="width: 120px;"></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($products as $product): ?>
                        <?php
                        $productId = (int) $product['id'];
                        $typeBadgeClass = $product['product_type'] === 'seatcover' ? 'badge-warning' : 'badge-info';
                        ?>
                        <tr>
                          <td><span
                              class="badge <?= $typeBadgeClass ?>"><?= htmlspecialchars($product['product_type']) ?></span>
                          </td>
                          <td><code><?= htmlspecialchars($product['product_code']) ?></code></td>
                          <td><?= htmlspecialchars($product['product_name']) ?></td>
                          <td class="text-center"><?= (int) $product['model_count'] ?></td>
                          <td class="text-center"><?= (int) $product['shoptet_count'] ?></td>
                          <td class="text-center"><?= (int) $product['ebay_count'] ?></td>
                          <td class="text-center"><?= (int) $product['listing_count'] ?></td>
                          <td class="text-right">
                            <a href="index.php?page=product_listing_catalog&product_id=<?= $productId ?>&q=<?= urlencode($search) ?>&type=<?= urlencode($type) ?>&marketplace=<?= urlencode($marketplace) ?>&listing_status=<?= urlencode($listingStatus) ?>&brand=<?= urlencode($brand) ?>"
                              class="btn btn-sm btn-outline-warning" title="View product detail">
                              Detail
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <?php
          if ($selectedProductId > 0) {
            $detailHeader = null;
            $detailRows = [];

            $headerStmt = $conn->prepare("
                SELECT product_type, product_code, product_name
                FROM scrub_catalog_products
                WHERE id = ?
                LIMIT 1
              ");

            if ($headerStmt) {
              $headerStmt->bind_param('i', $selectedProductId);
              $headerStmt->execute();
              $headerResult = $headerStmt->get_result();
              $detailHeader = $headerResult ? $headerResult->fetch_assoc() : null;
              $headerStmt->close();
            }

            if ($detailHeader) {
              if ($listingStatus === 'not_listed') {
                $missingMarketplaceLabel = $marketplace !== '' ? $marketplace : 'missing';
                $detailSql = "
                        SELECT
                          p.product_type,
                          p.product_code,
                          p.product_name,
                          '{$missingMarketplaceLabel}' AS marketplace,
                          sd.modelcode AS model_code,
                          '' AS external_code,
                          '' AS external_url,
                          '' AS listing_title,
                          0 AS is_active,
                          sd.brand,
                          sd.model,
                          sd.rangeyear,
                          COUNT(DISTINCT sd.exactyear) AS exact_year_count
                        FROM scrub_catalog_products p
                        INNER JOIN scrubdata sd ON sd.modelcode IS NOT NULL AND sd.modelcode <> ''
                        WHERE p.id = ?
                          AND NOT EXISTS (
                            SELECT 1
                            FROM scrub_catalog_product_listings pl
                            WHERE pl.product_id = p.id
                              AND pl.model_code = sd.modelcode
                              AND pl.is_active = 1
                      ";

                $detailTypes = 'i';
                $detailParams = [$selectedProductId];

                if ($marketplace !== '') {
                  $detailSql .= "
                              AND pl.marketplace = ?
                          ";
                  $detailTypes .= 's';
                  $detailParams[] = $marketplace;
                }

                $detailSql .= "
                          )
                      ";

                if ($brand !== '') {
                  $detailSql .= "
                          AND sd.brand = ?
                          ";
                  $detailTypes .= 's';
                  $detailParams[] = $brand;
                }

                $detailSql .= "
                        GROUP BY
                          p.product_type,
                          p.product_code,
                          p.product_name,
                          sd.modelcode,
                          sd.brand,
                          sd.model,
                          sd.rangeyear
                        ORDER BY sd.brand ASC, sd.model ASC, sd.rangeyear ASC, sd.modelcode ASC
                      ";
              } else {
                $detailSql = "
                        SELECT
                          p.product_type,
                          p.product_code,
                          p.product_name,
                          pl.marketplace,
                          pl.model_code,
                          MAX(pl.external_code) AS external_code,
                          MAX(pl.external_url) AS external_url,
                          MAX(pl.listing_title) AS listing_title,
                          MAX(pl.is_active) AS is_active,
                          sd.brand,
                          sd.model,
                          sd.rangeyear,
                          COUNT(DISTINCT sd.exactyear) AS exact_year_count
                        FROM scrub_catalog_products p
                        INNER JOIN scrub_catalog_product_listings pl ON pl.product_id = p.id
                        LEFT JOIN scrubdata sd ON sd.modelcode = pl.model_code
                        WHERE p.id = ?
                      ";

                $detailTypes = 'i';
                $detailParams = [$selectedProductId];

                if ($listingStatus === 'listed') {
                  $detailSql .= "
                          AND pl.is_active = 1
                          ";
                }

                if ($marketplace !== '') {
                  $detailSql .= "
                          AND pl.marketplace = ?
                          ";
                  $detailTypes .= 's';
                  $detailParams[] = $marketplace;
                }

                if ($brand !== '') {
                  $detailSql .= "
                          AND sd.brand = ?
                          ";
                  $detailTypes .= 's';
                  $detailParams[] = $brand;
                }

                $detailSql .= "
                        GROUP BY
                          p.product_type,
                          p.product_code,
                          p.product_name,
                          pl.marketplace,
                          pl.model_code,
                          sd.brand,
                          sd.model,
                          sd.rangeyear
                        ORDER BY pl.marketplace ASC, sd.brand ASC, sd.model ASC, sd.rangeyear ASC, pl.model_code ASC
                      ";
              }

              $detailStmt = $conn->prepare($detailSql);

              if ($detailStmt) {
                $bindParams = [$detailTypes];
                foreach ($detailParams as $paramIndex => $paramValue) {
                  $bindParams[] = &$detailParams[$paramIndex];
                }
                call_user_func_array([$detailStmt, 'bind_param'], $bindParams);
                $detailStmt->execute();
                $detailResult = $detailStmt->get_result();
                while ($detailRow = $detailResult->fetch_assoc()) {
                  $detailRows[] = $detailRow;
                }
                $detailStmt->close();
              }
            }
            ?>

            <div class="card card-outline card-primary mt-4">
              <div class="card-header">
                <h3 class="card-title">
                  <?= $detailHeader ? htmlspecialchars($detailHeader['product_name']) : 'Product detail' ?>
                </h3>
              </div>
              <div class="card-body p-0">
                <?php if (!$detailHeader): ?>
                  <div class="alert alert-warning m-3 mb-0">Produkt sa nepodarilo nacitat.</div>
                <?php elseif (!$detailRows): ?>
                  <div class="alert alert-info m-3 mb-0">
                    <?= $listingStatus === 'not_listed' ? 'Nenasli sa ziadne chybajuce modely pre aktualny filter.' : 'Detail produktu nema ziadne riadky pre aktualny filter.' ?>
                  </div>
                <?php else: ?>
                  <div class="p-3 border-bottom bg-dark">
                    <div class="row">
                      <div class="col-md-3">
                        <div class="text-muted small">Type</div>
                        <div><strong><?= htmlspecialchars((string) $detailHeader['product_type']) ?></strong></div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-muted small">Product code</div>
                        <div><code><?= htmlspecialchars((string) $detailHeader['product_code']) ?></code></div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-muted small">Rows</div>
                        <div><strong><?= count($detailRows) ?></strong></div>
                      </div>
                      <div class="col-md-3">
                        <div class="text-muted small">Listing status</div>
                        <div>
                          <strong><?= $listingStatus === 'not_listed' ? 'Is NOT Listed' : ($listingStatus === 'listed' ? 'Is Listed' : 'All listed rows') ?></strong>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                      <thead>
                        <tr>
                          <th>Marketplace</th>
                          <th>Brand</th>
                          <th>Model</th>
                          <th>Range</th>
                          <th>Model Code</th>
                          <th>External Code</th>
                          <th>Listing Title</th>
                          <th>Status</th>
                          <th>Link</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($detailRows as $detailRow): ?>
                          <tr>
                            <td>
                              <span
                                class="badge <?= $detailRow['marketplace'] === 'ebay' ? 'badge-danger' : ($detailRow['marketplace'] === 'shoptet' ? 'badge-success' : 'badge-secondary') ?>">
                                <?= htmlspecialchars((string) $detailRow['marketplace']) ?>
                              </span>
                            </td>
                            <td><?= htmlspecialchars((string) ($detailRow['brand'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($detailRow['model'] ?? '')) ?></td>
                            <td>
                              <?= htmlspecialchars((string) ($detailRow['rangeyear'] ?? '')) ?>
                              <?php if ((int) ($detailRow['exact_year_count'] ?? 0) > 1): ?>
                                <span class="text-muted small">(<?= (int) $detailRow['exact_year_count'] ?> years)</span>
                              <?php endif; ?>
                            </td>
                            <td>
                              <a
                                href="index.php?page=modeldata&scrubcocode=<?= urlencode((string) $detailRow['model_code']) ?>">
                                <code><?= htmlspecialchars((string) $detailRow['model_code']) ?></code>
                              </a>
                            </td>
                            <td><code><?= htmlspecialchars((string) ($detailRow['external_code'] ?? '')) ?></code></td>
                            <td><?= htmlspecialchars((string) ($detailRow['listing_title'] ?? '')) ?></td>
                            <td>
                              <span
                                class="badge <?= (int) $detailRow['is_active'] === 1 ? 'badge-success' : 'badge-secondary' ?>">
                                <?= $listingStatus === 'not_listed' ? 'missing' : ((int) $detailRow['is_active'] === 1 ? 'active' : 'inactive') ?>
                              </span>
                            </td>
                            <td>
                              <?php if (!empty($detailRow['external_url'])): ?>
                                <a href="<?= htmlspecialchars((string) $detailRow['external_url']) ?>" target="_blank"
                                  rel="noopener noreferrer">Open</a>
                              <?php else: ?>
                                -
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php } ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>