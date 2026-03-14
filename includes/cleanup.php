<style>@media (max-width: 768px) {
    .info-box {
        margin-bottom: 15px;    }

}
.archive-cleanup-box {
    min-height: 240px; /* Adjust height as you like */
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}
</style>
<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">×</button>
        <i class="fa fa-check"></i> <?= $_SESSION['success']; ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="close" data-dismiss="alert">×</button>
        <i class="fa fa-warning"></i> <?= $_SESSION['error']; ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- ACTION WIDGETS -->
<div class="row">

    <!-- ARCHIVE CARD -->
<div class="col-md-6">
    <div class="info-box bg-yellow">
        <span class="info-box-icon"><i class="fa fa-database"></i></span>

        <div class="info-box-content archive-cleanup-box">
            <span class="info-box-text">ARCHIVE INVENTORY MOVEMENTS</span>
            <span class="info-box-number">Move old records to archive</span>

            <form method="POST" action="scripts/process_archive.php" style="margin-top:10px;">
                <div class="form-group">
                    <select name="period" class="form-control" required style="width:100%;">
                        <option value="1 DAY">1 Day</option>
                        <option value="1 WEEK">1 Week</option>
                        <option value="2 WEEKS" selected>2 Weeks</option>
                        <option value="1 MONTH">1 Month</option>
                        <option value="3 MONTHS">3 Months</option>
                        <option value="6 MONTHS">6 Months</option>
                        <option value="1 YEAR">1 Year</option>
                    </select>
                </div>
                <button type="submit"
                        class="btn btn-warning btn-block"
                        onclick="return confirm('Move old inventory data to archive?');">
                    <i class="fa fa-upload"></i> ARCHIVE NOW
                </button>
            </form>
        </div>
    </div>
</div>


    <!-- CLEANUP CARD -->
<div class="col-md-6">
    <div class="info-box bg-red">
        <span class="info-box-icon"><i class="fa fa-trash"></i></span>

        <div class="info-box-content archive-cleanup-box">
            <span class="info-box-text">CLEANUP EMPTY SHELVES</span>
            <span class="info-box-number">Remove zero-quantity items</span>
                <br /><br />
            <form method="POST" action="scripts/cleanup_empty_shelves.php" style="margin-top:10px;">
                <button type="submit"
                        class="btn btn-warning btn-block"
                        onclick="return confirm('Delete ALL stock entries with quantity ZERO?');">
                    <i class="fa fa-trash"></i> CLEANUP NOW
                </button>
            </form>
        </div>
    </div>
</div>

