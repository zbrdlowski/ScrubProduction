<?php 
require_once('includes/conn.php');
// Update View Count

function update_view(){
    global $conn;
    $id = rand(1,7);
    $check = $conn->query("SELECT id from `tb_data` where id ='{$id}'")->num_rows;
    
    $add_view = rand(1,99);
    if($check > 0){
        $conn->query("UPDATE `tb_data` set `age` =  `age` + {$add_view}  where id ='{$id}'");
    }else{
        
        $conn->query("INSERT `tb_data` set `age` =  {$add_view} , id ='{$id}'");
   }
        
}

$data = array();
update_view();
foreach($_POST['id'] as $post_id => $value){
    $value=str_replace(',','',$value);
    // Sample Query
    $views = $conn->query("SELECT age from `tb_data` where id ='{$post_id}'");
    if($views->num_rows > 0){
        $view_count = $views->fetch_array()['view_count'];
        if($value == $view_count)
            $count = 0;
        else
            $count =  $view_count;
    }else{
        $count = 0;
    }

    // Generate Randow Number for content view count
    // $count = mt_rand(0,99999999);
    if($count > 0)
    $data[] = array('id'=>$post_id, 'count'=>number_format($count));
}

echo json_encode($data);

$conn->close();

?>