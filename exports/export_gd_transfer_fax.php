<?php

require_once 'helpers/helper_appsscripts.php';

function export_gd_transfer_fax($item, $source) {

  //log_notice("WebForm export_gd_transfer_fax CALLED $item[invoice_number] $item[drug_name] $source", get_defined_vars());

  if (
    $item['rx_message_key']  != 'NO ACTION WILL TRANSFER' AND
    $item['rx_message_key']  != 'NO ACTION WILL TRANSFER CHECK BACK' AND
    ($item['rx_message_key'] != 'NO ACTION MISSING GSN' OR ! $item['max_gsn'])
  )
    return;

  if ($item['rx_transfer']) {
    return log_error("WebForm export_gd_transfer_fax NOT SENT, ALREADY TRANSFERRED $item[invoice_number] $item[drug_name] $source", get_defined_vars());
  }

  $to = '8882987726'; //$item['pharmacy_fax'] ?: '8882987726';


  $token = $item['patient_id_cp'] .
           $item['patient_id_wc'] .
           $item['invoice_number'] .
           $item['drug_name'] .
           $item['rx_transfer'] .
           $source;

  // Create a hash for quicker compares
  $hash = sha1($token);

  $notification = new Notification('transfer', $hash, $token);

  if ($notification->isNew()) {
    $args = [
      'method'   => 'mergeDoc',
      'template' => 'Transfer Template v1',
      'file'     => "Transfer Out #".(@$item['invoice_number'])." Rx:$item[best_rx_number] Fax:$to",
      'folder'   => TRANSFER_OUT_FOLDER_NAME,
      'order'    => [$item]
    ];

    $result = gdoc_post(GD_MERGE_URL, $args);

    log_notice("WebForm export_gd_transfer_fax SENT ".(@$item['invoice_number'])." $item[drug_name] $source", get_defined_vars());
  } else {
    $notification->increment();
    log_error("WebForm export_gd_transfer_fax SENT ".(@$item['invoice_number'])." $item[drug_name] $source", get_defined_vars());
  }
}
