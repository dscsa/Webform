<?php

require_once 'helpers/helper_days_and_message.php';
require_once 'helpers/helper_full_item.php';
require_once 'exports/export_cp_order_items.php';
require_once 'exports/export_v2_order_items.php';
require_once 'exports/export_gd_transfer_fax.php';

use Sirum\Logging\SirumLog;

function update_order_items($changes) {

  $count_deleted = count($changes['deleted']);
  $count_created = count($changes['created']);
  $count_updated = count($changes['updated']);

  $msg = "$count_deleted deleted, $count_created created, $count_updated updated ";
  echo $msg;
  log_info("update_order_items: all changes. $msg", [
    'deleted_count' => $count_deleted,
    'created_count' => $count_created,
    'updated_count' => $count_updated
  ]);

  if ( ! $count_deleted AND ! $count_created AND ! $count_updated) return;

  $mysql = new Mysql_Wc();
  $mssql = new Mssql_Cp();

  //If just added to CP Order we need to
  //  - determine "days_dispensed_default" and "qty_dispensed_default"
  //  - pend in v2 and save applicable fields
  //  - if first line item in order, find out any other rxs need to be added
  //  - update invoice
  //  - update wc order total
  foreach($changes['created'] as $created) {

    SirumLog::$subroutine_id = "order-items-created-".sha1(serialize($created));

    $item = load_full_item($created, $mysql, true);

    SirumLog::debug(
      "update_order_items: Order Item created",
      [
        'item'    => $item,
        'created' => $created,
        'source'  => 'CarePoint',
        'type'    => 'order-items',
        'event'   => 'created'
      ]
    );

    if ( ! $item) {
      log_error("Created Item Missing", $created);
      continue;
    }

    if ($created['count_lines'] > 1) {
      $error = ["$item[invoice_number] $item[drug_generic] is a duplicate line", 'created' => $created, 'item' => $item];
      $item = deduplicate_order_items($item, $mssql, $mysql);
      SirumLog::alert($error[0], $error);
    }

    //We don't pend inventory in v2 here (v2_pend_item), but at the order level, in case we want to sync any drugs to the order, or vary the days to sync drugs to a date

    if ($item['days_dispensed_actual']) {

      log_error("order_item created but days_dispensed_actual already set.  Most likely an new rx but not part of a new order (days actual is from a previously shipped order) or an item added to order and dispensed all within the time between cron jobs", [$item, $created]);

      SirumLog::debug("Freezing Item as because it's dispensed", $item);
      freeze_invoice_data($item, $mysql);
      continue;
    }

    //TODO Update Salesforce Order Total & Order Count & Order Invoice using REST API or a MYSQL Zapier Integration
  }

  foreach($changes['deleted'] as $deleted) {

    SirumLog::$subroutine_id = "order-items-deleted-".sha1(serialize($deleted));

    SirumLog::debug(
      "update_order_items: Order Item deleted",
      [
          'deleted' => $deleted,
          'source'  => 'CarePoint',
          'type'    => 'order-items',
          'event'   => 'deleted'
      ]
    );

    $item = load_full_item($deleted, $mysql, true);

    //Don't Unpend here.  This is handled by count_item changes in update_orders_cp
    //Count Items will go down, triggering a CP Order Change

    //TODO Update Salesforce Order Total & Order Count & Order Invoice using REST API or a MYSQL Zapier Integration
  }

  //If just updated we need to
  //  - see which fields changed
  //  - think about what needs to be updated based on changes
  foreach($changes['updated'] as $updated) {

   SirumLog::$subroutine_id = "order-items-updated-".sha1(serialize($updated));

    SirumLog::debug(
      "update_order_items: Order Item updated",
      [
          'updated' => $updated,
          'source'  => 'CarePoint',
          'type'    => 'order-items',
          'event'   => 'updated'
      ]
    );

    $item = load_full_item($updated, $mysql, true);

    if ( ! $item) {
      log_error("Updated Item Missing", get_defined_vars());
      continue;
    }

    $changed = changed_fields($updated);

    if ($updated['count_lines'] > 1) {
      $error = ["$item[invoice_number] $item[drug_generic] is a duplicate line", 'updated' => $updated, 'changed' => $changed, 'item' => $item];
      $item = deduplicate_order_items($item, $mssql, $mysql);
      SirumLog::alert($error[0], $error);
    }

    if ($item['days_dispensed_actual']) {
      SirumLog::debug("Freezing Item as because it's dispensed and updated", $item);
      freeze_invoice_data($item, $mysql);

      if ($item['days_dispensed_actual'] == $item['days_dispensed_default']) {
        log_info("days_dispensed_actual was set", [$updated, $changed]);
      } else {
        //This already picked up by dispensing_changes in update_orders_cp.php
        //log_error("days_dispensed_default was wrong: $item[days_dispensed_default] >>> $item[days_dispensed_actual]", ['item' => $item, 'updated' => $updated, 'changed' => $changed]);
      }

      if ($item['refills_total'] != $item['refills_dispensed_default']) { //refills_dispensed_actual is not set yet, so use refills_total instead
        log_notice('update_order_items: refills_dispensed changed', $item);
      }

    } else if ($updated['item_added_by'] == 'MANUAL' AND $updated['old_item_added_by'] != 'MANUAL') {

      log_info("Cindy deleted and readded this item", [$updated, $changed]);

    } else if ( ! $item['days_dispensed_default']) {

      log_error("Updated Item has no days_dispensed_default.  Why no days_dispensed_default? GSN added?", get_defined_vars());

    } else {
      log_info("Updated Item No Action", get_defined_vars());
    }

    log_info("update_order_items", get_defined_vars());

    //TODO Update Salesforce Order Total & Order Count & Order Invoice using REST API or a MYSQL Zapier Integration
  }

  SirumLog::resetSubroutineId();
}

function deduplicate_order_items($item, $mssql, $mysql) {

  $item['count_lines'] = 1;

  $sql1 = "
    UPDATE gp_order_items SET count_lines = 1 WHERE invoice_number = $item[invoice_number] AND rx_number = $item[rx_number]
  ";

  $res1 = $mysql->run($sql1)[0];

  //DELETE doesn't work with offset so do it in two separate queries
  $sql2 = "
    SELECT
      *
    FROM
      csomline
    JOIN
      cprx ON cprx.rx_id = csomline.rx_id
    WHERE
      order_id  = ".($item['invoice_number']-2)."
      AND rxdisp_id = 0
      AND (
        script_no = $item[rx_number]
        OR CONCAT(',', gcn_seqno, ',') LIKE '%$item[drug_gsns]%'
      )
    ORDER BY
      csomline.add_date ASC
    OFFSET 1 ROWS
  ";

  $res2 = $mssql->run($sql2)[0];

  foreach($res2 as $duplicate) {
    $mssql->run("DELETE FROM csomline WHERE line_id = $duplicate[line_id]");
  }

  log_notice('deduplicate_order_item', [$sql1, $res1, $sql2, $res2]);

  $new_count_items = export_cp_recount_items($item['invoice_number'], $mssql);

  return $item;
}
