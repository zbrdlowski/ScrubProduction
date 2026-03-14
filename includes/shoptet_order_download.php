<?php
// includes/shoptet_order_download.php
?>


    <section class="content pt-4">
        <div class="container-fluid">
            <div class="row justify-content-center">
                <div class="col-xl-7 col-lg-8 col-md-10">

                    <div class="card card-primary card-outline shadow-lg">
                        <div class="card-header text-center border-0 pt-4">
                            <h2 class="card-title d-block font-weight-bold mb-2" style="font-size: 2rem;">
                                <i class="fas fa-file-download mr-2 text-primary"></i>
                                Shoptet .csv Order Download
                            </h2>
                            <br />
                            <p class="text-muted mb-0" style="font-size: 1rem;">
                                Choose date, System will generate link for download .csv export with selected orders
                            </p>
                        </div>

                        <div class="card-body px-4 px-md-5 pb-5">

                            <div class="callout callout-info mb-4">
                                <h5 class="mb-2">
                                    <i class="fas fa-info-circle mr-1"></i>
                                   How It Works
                                </h5>
                                <p class="mb-0">
                                    Choose a date and the system will automatically generate an API link in the following format:
                                    <br>
                                    <code>https://scrubdesignz.shoptet.able.cz/api/v1/get-orders/YYYY-MM-DD</code>
                                </p>
                            </div>

                            <form id="orderDownloadForm" autocomplete="off">
                                <div class="form-group">
                                    <label for="orderDate" class="font-weight-bold">Date of Orders</label>

                                    <div class="input-group date" id="orderDatePicker" data-target-input="nearest">
                                        <input
                                            type="text"
                                            id="orderDate"
                                            class="form-control datetimepicker-input form-control-lg"
                                            data-target="#orderDatePicker"
                                            placeholder="Choose date..."
                                        />
                                        <div class="input-group-append" data-target="#orderDatePicker" data-toggle="datetimepicker">
                                            <div class="input-group-text">
                                                <i class="far fa-calendar-alt"></i>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted mt-2">
                                        The format of the generated link will be <strong>YYYY-MM-DD</strong>.
                                    </small>
                                </div>

                                <div class="form-group mt-4">
                                    <label class="font-weight-bold">Generated Link</label>
                                    <div class="bg-light border rounded p-3" style="word-break: break-all;">
                                        <code id="generatedUrl">https://scrubdesignz.shoptet.able.cz/api/v1/get-orders/</code>
                                    </div>
                                </div>

                                <div class="row mt-4">
                                    <div class="col-md-6 mb-2 mb-md-0">
                                        <button type="submit" class="btn btn-primary btn-lg btn-block shadow-sm">
                                            <i class="fas fa-download mr-2"></i>
                                            Download CSV
                                        </button>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="button" id="openLinkBtn" class="btn btn-outline-secondary btn-lg btn-block">
                                            <i class="fas fa-external-link-alt mr-2"></i>
                                            Open Link
                                        </button>
                                    </div>
                                </div>
                            </form>    
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>


<script>
$(function () {
    const baseUrl = 'https://scrubdesignz.shoptet.able.cz/api/v1/get-orders/';
    let selectedDate = '';

    $('#orderDatePicker').datetimepicker({
        format: 'L',
        locale: 'sk',
        icons: {
            time: 'far fa-clock',
            date: 'far fa-calendar-alt',
            up: 'fas fa-chevron-up',
            down: 'fas fa-chevron-down',
            previous: 'fas fa-chevron-left',
            next: 'fas fa-chevron-right',
            today: 'far fa-calendar-check',
            clear: 'far fa-trash-alt',
            close: 'far fa-times'
        }
    });

    function updateGeneratedUrl() {
        const displayValue = $('#orderDate').val();

        if (displayValue) {
            const m = moment(displayValue, 'L');
            if (m.isValid()) {
                selectedDate = m.format('YYYY-MM-DD');
                $('#generatedUrl').text(baseUrl + selectedDate);
                return;
            }
        }

        selectedDate = '';
        $('#generatedUrl').text(baseUrl);
    }

    $('#orderDatePicker').on('change.datetimepicker', function () {
        updateGeneratedUrl();
    });

    $('#openLinkBtn').on('click', function () {
        updateGeneratedUrl();

        if (!selectedDate) {
            toastr.warning('Najprv vyber dátum.');
            return;
        }

        window.open(baseUrl + selectedDate, '_blank');
    });

    $('#orderDownloadForm').on('submit', function (e) {
        e.preventDefault();
        updateGeneratedUrl();

        if (!selectedDate) {
            toastr.warning('Najprv vyber dátum.');
            return;
        }

        window.location.href = baseUrl + selectedDate;
    });
});
function setDateAndRefresh(mDate) {
    $('#orderDatePicker').datetimepicker('date', mDate);
    updateGeneratedUrl();
}

setDateAndRefresh(moment());

$('<div class="mt-3">' +
    '<button type="button" class="btn btn-sm btn-outline-primary mr-2 quick-date" data-type="today">Dnes</button>' +
    '<button type="button" class="btn btn-sm btn-outline-primary mr-2 quick-date" data-type="yesterday">Včera</button>' +
    '<button type="button" class="btn btn-sm btn-outline-primary quick-date" data-type="week">Pred 7 dňami</button>' +
  '</div>').insertAfter('#orderDatePicker');

$(document).on('click', '.quick-date', function () {
    const type = $(this).data('type');

    if (type === 'today') setDateAndRefresh(moment());
    if (type === 'yesterday') setDateAndRefresh(moment().subtract(1, 'days'));
    if (type === 'week') setDateAndRefresh(moment().subtract(7, 'days'));
});
</script>