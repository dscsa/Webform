<?php

require_once 'helpers/helper_appsscripts.php';

use Sirum\Logging\SirumLog;

function export_gd_update_invoice($order, $reason, $mysql, $try2 = false) {

  SirumLog::notice(
    'export_gd_update_invoice: called',
    [
      "invoice" => $order[0]['invoice_number'],
      "order"   => $order,
      "reason"  => $reason,
      "try2"    => $try2
    ]
  );

  if ( ! count($order)) {
    log_error("export_gd_update_invoice: got malformed order", [$order, $reason]);
    return $order;
  }

  $start = microtime(true);

  export_gd_delete_invoice($order); //Avoid having multiple versions of same invoice

  $args = [
    'method'   => 'mergeDoc',
    'template' => 'Invoice Template v1',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_PENDING_FOLDER_NAME,
    'order'    => $order
  ];

  $result = gdoc_post(GD_MERGE_URL, $args);

  $invoice_doc_id = json_decode($result, true);

  if ( ! $invoice_doc_id) {

    if ( ! $try2) {
      log_error("export_gd_update_invoice: invoice error #1 of 2", ['args' => $args, 'result' => $result]);
      return export_gd_update_invoice($order, $reason, $mysql, true);
    }

    log_error("export_gd_update_invoice: invoice error #2 of 2", ['args' => $args, 'result' => $result]);
    return $order;
  }

  $time = ceil(microtime(true) - $start);

  if ($order[0]['invoice_doc_id'])
    log_notice("export_gd_update_invoice: updated invoice for Order #".$order[0]['invoice_number'].' '.$order[0]['order_stage_cp']." $time seconds. docs.google.com/document/d/".$order[0]['invoice_doc_id']." >>>  docs.google.com/document/d/$invoice_doc_id", [$order, $reason]);
  else
    log_notice("export_gd_update_invoice: created invoice for Order #".$order[0]['invoice_number'].' '.$order[0]['order_stage_cp']." $time seconds. docs.google.com/document/d/$invoice_doc_id", [$order, $reason]);

  //Need to make a second loop to now update the invoice number
  foreach($order as $i => $item)
    $order[$i]['invoice_doc_id'] = $invoice_doc_id;

  $sql = "
    UPDATE
      gp_orders
    SET
      invoice_doc_id = ".($invoice_doc_id ? "'$invoice_doc_id'" : 'NULL')." -- Unique Index forces us to use NULL rather than ''
    WHERE
      invoice_number = {$order[0]['invoice_number']}
  ";

  $mysql->run($sql);

  return $order;
}

function export_gd_print_invoice($order) {

  log_notice("export_gd_print_invoice start: ".$order[0]['invoice_number'], $order);

  $start = microtime(true);

  $args = [
    'method'     => 'moveFile',
    'file'       => 'Invoice #'.$order[0]['invoice_number'],
    'fromFolder' => INVOICE_PENDING_FOLDER_NAME,
    'toFolder'   => INVOICE_PUBLISHED_FOLDER_NAME,
  ];

  $result = gdoc_post(GD_HELPER_URL, $args);

  $time = ceil(microtime(true) - $start);

  log_notice("export_gd_print_invoice $time seconds: ".$order[0]['invoice_number'], $result);
}

//Cannot delete (with this account) once published
function export_gd_publish_invoice($order, $mysql, $retry = false) {

  $start = microtime(true);

  $args = [
    'method'   => 'publishFile',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_PENDING_FOLDER_NAME,
  ];

  $result = gdoc_post(GD_HELPER_URL, $args);

  $time = ceil(microtime(true) - $start);

  $parsed = json_decode($result, true);

  if (@$parsed[0]['name'] == 'Exception' AND ! $retry) {
    export_gd_update_invoice($order, "export_gd_publish_invoice: invoice ".$order[0]['invoice_number']." didn't exist so trying to (re)make it", $mysql);
    export_gd_publish_invoice($order, $mysql, true);
    log_error("export_gd_publish_invoice failed trying again: ".$order[0]['invoice_number'], $result);
  } else {
    log_notice("export_gd_publish_invoice success $time seconds: ".$order[0]['invoice_number'], $result);
  }
}

function export_gd_delete_invoice($order) {

  $args = [
    'method'   => 'removeFiles',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_PENDING_FOLDER_NAME
  ];

  $result = gdoc_post(GD_HELPER_URL, $args);

  log_info("export_gd_delete_invoice", get_defined_vars());
}
