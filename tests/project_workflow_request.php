<?php
session_start(['use_cookies'=>0,'cache_limiter'=>'','save_path'=>sys_get_temp_dir()]);
require __DIR__ . '/project_workflow_fixture.php';
$_SESSION=['user_id'=>7,'permission'=>0,'project_workflow_csrf'=>str_repeat('a',64)];
$parent=fixtureAdd($conn,'Create designz for honda crf 450',8,null,true);
$web=fixtureAdd($conn,'List new designz on website',9,$parent);
$ebay=fixtureAdd($conn,'List new designz on eBay',10,$parent,true);
$standalone=fixtureAdd($conn,'Independent task',9);
projectWorkflowMutate($conn,8,'update_task_status',['project_id'=>1,'task_id'=>$parent,'task_status'=>'done']);
if (in_array($argv[1] ?? '', ['--render','--render-profile'], true)) {
 $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/index.php'; $_GET=['view'=>1];
 echo '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/dist/css/adminlte.min.css"><link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css"><script src="/js/jquery-3.6.0.min.js"></script><script src="/plugins/bootstrap/js/bootstrap.bundle.min.js"></script></head><body class="dark-mode p-3"><nav class="navbar navbar-dark"><ul class="navbar-nav ml-auto">';
 include dirname(__DIR__).'/includes/project_notification_nav.php';
 echo '</ul></nav>';
 if ($argv[1] === '--render-profile') {
   echo '<script>window.darkScrubChatNotifications={getPreferences:()=>({toast_mode:"persistent",sound_enabled:true}),savePreferences:async()=>{}};</script>';
   include dirname(__DIR__).'/includes/profile_notifications.php';
 } else include dirname(__DIR__).'/includes/projects.php';
 echo '</body></html>';
 exit;
}
$request=json_decode(stream_get_contents(STDIN),true) ?: [];
$_SESSION['user_id']=$request['user_id'] ?? 7;
$_SERVER['REQUEST_METHOD']=$request['method'] ?? 'GET';
$_POST=$request['post'] ?? [];
register_shutdown_function(function(){fwrite(STDERR,'http_status='.(http_response_code() ?: 200));});
require dirname(__DIR__).'/scripts/projects/notifications.php';
