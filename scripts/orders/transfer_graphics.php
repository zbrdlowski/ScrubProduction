<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__,2).'/includes/conn.php';
require_once dirname(__DIR__,2).'/includes/auth.php';
function transfer_reply(array $data, int $status=200): void { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if (!isset($_SESSION['permission']) || !auth_can('orders.view') || !auth_can('orders.work')) transfer_reply(['ok'=>false,'error'=>'No permission to transfer Graphics.'],403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') transfer_reply(['ok'=>false,'error'=>'Method not allowed.'],405);
if (empty($_SESSION['orders_transfer_csrf']) || !hash_equals($_SESSION['orders_transfer_csrf'],(string)($_POST['csrf'] ?? ''))) transfer_reply(['ok'=>false,'error'=>'Your form has expired. Reopen the order detail.'],403);
require_once __DIR__.'/../product_builder/transfers.php';
require_once dirname(__DIR__,2).'/includes/get_order_detail_product_spec_selects.php';
try {
    $row=pb_create_transfer($pdo,(int)($_POST['item_id'] ?? 0),(int)($_POST['order_id'] ?? 0),productSpecFieldDefinitions($conn,'G'),(int)($_SESSION['user_id'] ?? 0) ?: null);
    transfer_reply(['ok'=>true,'transfer'=>$row]);
} catch (InvalidArgumentException $e) { transfer_reply(['ok'=>false,'error'=>$e->getMessage()],422); }
catch (Throwable $e) { error_log('Graphics transfer: '.$e->getMessage()); transfer_reply(['ok'=>false,'error'=>'Unable to transfer Graphics. Please try again.'],500); }
