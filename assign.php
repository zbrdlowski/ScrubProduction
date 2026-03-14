<?

session_start();
include 'includes/conn.php';
$orderno = $_REQUEST['orderno'];
switch ($_SESSION['dpt']) {
    case '2':
        $sql = "UPDATE orders_" . $append . " SET assign_g = '" . $_SESSION['user_id'] . "', `status` = 'In Process' WHERE `order_nr` = '" .  $_REQUEST['orderno'] . "'";
        break;
    case '6':
        $sql = "UPDATE orders_" . $append . " SET assign_p = '" . $_SESSION['user_id'] . "', `status` = 'In Process' WHERE `order_nr` = '" .  $_REQUEST['orderno'] . "'";
        break;
        case '8':
            $sql = "UPDATE orders_" . $append . " SET assign_s = '" . $_SESSION['user_id'] . "', `status` = 'In Process' WHERE `order_nr` = '" .  $_REQUEST['orderno'] . "'";
            break;  

}
$query = $conn->query($sql);
if($conn->query($sql)){
    $_SESSION['success'] = 'Zmeny úspešne uložené';
}
else{
    $_SESSION['error'] = $conn->error;
}
@header('location: index.php?page=profile');

?>