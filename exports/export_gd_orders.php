<?php

require_once 'helpers/helper_appsscripts.php';

function export_gd_update_invoice($order, $mysql) {

  if ( ! count($order)) return;

  export_gd_delete_invoice($order); //Avoid having multiple versions of same invoice

  $args = [
    'method'   => 'mergeDoc',
    'template' => 'Invoice Template v1',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_FOLDER_NAME,
    'order'    => $order
  ];

  $result = gdoc_post(GD_MERGE_URL, $args);

  $invoice_doc_id = json_decode($result, true);

  $sql = "
    UPDATE gp_orders SET invoice_doc_id = '$invoice_doc_id' WHERE invoice_number = {$order[0]['invoice_number']}
  ";

  $mysql->run($sql);

  log_error("export_gd_update_invoice", ['args' => $args, 'result' => $result, 'sql' => $sql]);
}

//Cannot delete (with this account) once published
function export_gd_publish_invoice($order) {

 if ( ! $order[0]['tracking_number']) return; //only publish if tracking number since we can't delete extra after this point

  $args = [
    'method'   => 'publishFile',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_FOLDER_NAME,
  ];

  $result = gdoc_post(GD_HELPER_URL, $args);

  log_notice("export_gd_publish_invoice", get_defined_vars());
}

function export_gd_delete_invoice($order) {

  $args = [
    'method'   => 'removeFiles',
    'file'     => 'Invoice #'.$order[0]['invoice_number'],
    'folder'   => INVOICE_FOLDER_NAME
  ];

  $result = gdoc_post(GD_HELPER_URL, $args);

  log_info("export_gd_delete_invoice", get_defined_vars());
}
