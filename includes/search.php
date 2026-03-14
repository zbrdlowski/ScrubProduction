<div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <div class="card">
              <div class="card-header">
                <h2>📊 Search</h2>
              </div>
              <!-- /.card-header -->
              <div class="card-body">
                 <input type="text" id="liveSearch" class="form-control" placeholder="Search barcode, shelf, or name">
                    <div id="searchResults" class="list-group" style="position:absolute; z-index:1000;"></div>

                    <script>
                    $('#liveSearch').on('input', function() {
                        const query = $(this).val();
                        if (query.length < 2) {
                        $('#searchResults').empty();
                        return;
                        }

                        $.get('ajax/search_lookup.php', { q: query }, function(data) {
                        $('#searchResults').html(data);
                        });
                    });

                    $(document).on('click', '.search-item', function() {
                        const barcode = $(this).data('barcode');
                        window.location.href = 'search_item.php?query=' + encodeURIComponent(barcode);
                    });
                    </script>
                </div>
              <!-- /.card-body -->
            </div>
            <!-- /.card -->
          </div>
          <!-- /.col -->
        </div>
        <!-- /.row -->
      </div>
      <!-- /.container-fluid -->  
