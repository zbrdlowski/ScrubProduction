
<?
echo '<div class="modal fade" id="modal-xl">';
echo '<div class="modal-dialog modal-xl">';
  echo '<div class="modal-content">';
    echo '<div class="modal-header">';
      echo '<h4 class="modal-title">Order</h4>';
      echo '<button type="button" class="close" data-dismiss="modal" aria-label="Close">';
        echo '<span aria-hidden="true">&times;</span>';
      echo '</button>';
    echo '</div>';
    echo '<div class="modal-body">';
      echo '<p id="modal-id-display"></p>';
    echo '</div>';
    echo '<div class="modal-footer justify-content-between">';
      echo '<button type="button" class="btn btn-default" data-dismiss="modal">Close </button>';
      echo '<button type="button" class="btn btn-primary">Save changes </button>';
    echo '</div>';
  echo '</div>';
  //<!-- /.modal-content -->
echo '</div>';
//-- /.modal-dialog -->
echo '</div>';
?>