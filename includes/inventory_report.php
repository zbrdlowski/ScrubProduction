
<section class="content">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Inventory Report</h3>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                <?php

                // Query to get current inventory status
                $stmt = $pdo->prepare("SELECT items.barcode, items.scrubcode, items.brand, items.name, items.description, items.color, shelves.location, stock_levels.quantity FROM stock_levels
                JOIN items ON stock_levels.item_id = items.id
                JOIN shelves ON stock_levels.shelf_id = shelves.id");
                $stmt->execute();

                $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

                echo "<h1>Inventory</h1>";
                echo '<input type="text" id="inventoryFilter" class="form-control mb-3" placeholder="Filter inventory...">';
                echo '<table id="example8" class="table table-bordered table-striped">';
                echo "<thead>";
                echo "<tr>";
                echo "<th>Part Number</th><th>Modelcode</th><th>Brand</th><th>Model</th><th>Part</th><th>Color</th><th>Shelf Location</th><th class='text-center'>Quantity</th>";
                echo "</tr>";
                echo "</thead>";
                echo "<tbody>";
                foreach ($inventory as $row) {
                    echo "<tr><td>" . $row['barcode'] . "</td><td>" . $row['scrubcode'] . "</td><td>" . $row['brand'] . "</td><td>" . $row['name'] . "</td><td>" . $row['description'] . "</td><td>" . $row['color'] . "</td><td>" . $row['location'] . "</td><td class='text-center'>" . $row['quantity'] . "</td></tr>";
                }
                echo "</tbody>";
                echo "</table>";
                ?>
                <script src="js/jquery-3.6.0.min.js"></script>
                <script>
                $(document).ready(function() {
                  $('#inventoryFilter').on('keyup', function() {
                    const keywords = $(this).val().toLowerCase().split(/\s+/); // split by space

                    $('#example8 tbody tr').each(function() {
                      let rowText = '';
                      $(this).find('td').each(function(index) {
                        if (index !== 7) { // skip Quantity column
                          rowText += $(this).text().toLowerCase() + ' ';
                        }
                      });

                      // Check if all keywords are found in the row
                      const match = keywords.every(keyword => rowText.includes(keyword));
                      $(this).toggle(match);
                    });
                  });
                });
                </script>

               </div>
<!-- /.box-body -->
            </div>
        </div>
<!-- /.card-body -->
        </div>
    </div>
</section>

