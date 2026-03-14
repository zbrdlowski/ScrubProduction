  <!-- 🔍 Quick Search -->
  <div class="col-md-12">
    <div class="card">
      <div class="card-body">
        <div class="dashboard-section">
          <div class="panel panel-default">
            <div class="panel-heading"><i class="fa fa-search"></i> Quick Search</div><br />
            <div class="panel-body">
              <form method="GET" action="index.php" class="form-inline text-center"> 
                <input type="hidden" name="page" value="search_item">           
                <input type="text" name="query" class="form-control input-sm" placeholder="Barcode, Shelf, or Name" required>
                <button type="submit" class="btn btn-primary">🔍 Search</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>