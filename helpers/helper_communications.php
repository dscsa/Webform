<?php

require_once 'exports/export_gd_comm_calendar.php';

use Sirum\Logging\SirumLog;

//All Communication should group drugs into 4 Categories based on ACTION/NOACTION and FILL/NOFILL
//1) FILLING NO ACTION
//2) FILLING ACTION
//3) NOT FILLING ACTION
//4) NOT FILLING NO ACTION
//TODO Much Better if this was a set of methods on an Order Object.  Like order->count_filled(filled = true/false), order->items_action(action = true/false), order->items_in_order(in_order = true/false), order->items_filled(filled = true/false, template = "{{name}} {{price}} {{item_message_keys}}")
function group_drugs($order, $mysql) {

  if ( ! $order) {
    log_error('GROUP_DRUGS did not get an order', get_defined_vars());
    return;
  }

  $groups = [
    "ALL" => [],
    "FILLED_ACTION" => [],
    "FILLED_NOACTION" => [],
    "NOFILL_ACTION" => [],
    "NOFILL_NOACTION" => [],
    "FILLED" => [],
    "FILLED_WITH_PRICES" => [],
    "IN_ORDER" => [],
    "NO_REFILLS" => [],
    "NO_AUTOFILL" => [],
    "MIN_DAYS" => 366 //Max Days of a Script
  ];

  foreach ($order as $item) {

    $groups['ALL'][] = $item; //Want patient contact_info even if an emoty order

    if ( ! @$item['drug_name']) continue; //Might be an empty order

    $days = @$item['days_dispensed'];
    $fill = $days ? 'FILLED_' : 'NOFILL_';

    //item_message_text is set in freeze_invoice_data once dispensed
    $msg  = @$item['item_message_text'] ?: $item['rx_message_text'];
    $msg  = $msg ? ' '.str_replace(' **', '', $msg) : '';

    if (strpos($msg, 'NO ACTION') !== false)
      $action = 'NOACTION';
    else if (strpos($msg, 'ACTION') !== false)
      $action = 'ACTION';
    else
      $action = 'NOACTION';

    $price = ($days AND $item['price_dispensed']) ? ', $'.((float) $item['price_dispensed']).' for '.$days.' days' : '';

    $groups[$fill.$action][] = $item['drug'].$msg;

    if (@$item['item_date_added'])
      $groups['IN_ORDER'][] = $item['drug'].$msg;

    if ($item['rx_number'] AND @$item['invoice_number']) { //Will be null if drug is NOT in the order.
      $sql = "
        UPDATE
          gp_order_items
        SET
          groups = CASE WHEN groups is NULL THEN '$fill$action' ELSE concat('$fill$action < ', groups) END
        WHERE
          invoice_number = $item[invoice_number] AND
          rx_number = $item[rx_number] AND
          (groups IS NULL OR groups NOT LIKE '$fill$action%')
      ";

      SirumLog::debug(
        "Saving group into order_items",
        [
          "item"   => $item,
          "sql"    => $sql,
          "method" => "group_drugs"
        ]
      );

      $mysql->run($sql);
    }

    if ($days) {//This is handy because it is not appended with a message like the others
      $groups['FILLED'][] = $item['drug'];
      $groups['FILLED_WITH_PRICES'][] = $item['drug'].$price;
    }

    if ( ! @$item['refills_dispensed'] AND ! $item['rx_transfer'])
      $groups['NO_REFILLS'][] = $item['drug'].$msg;

    if ($days AND ! $item['rx_autofill'])
      $groups['NO_AUTOFILL'][] = $item['drug'].$msg;

    if ( ! @$item['refills_dispensed'] AND $days AND $days < $groups['MIN_DAYS'])
      $groups['MIN_DAYS'] = $days; //How many days before the first Rx to run out of refills

    $groups['MANUALLY_ADDED'] = is_added_manually($item);
  }

  $count_filled = count($groups['FILLED_ACTION']) + count($groups['FILLED_NOACTION']);
  $count_nofill = count($groups['NOFILL_ACTION']) + count($groups['NOFILL_NOACTION']);

  if ($count_filled != $order[0]['count_filled']) {
    log_error("group_drugs: wrong count_filled $count_filled != ".$order[0]['count_filled'], get_defined_vars());
  }

  if ($count_nofill != $order[0]['count_nofill']) {
    log_error("group_drugs: wrong count_nofill $count_nofill != ".$order[0]['count_nofill'], get_defined_vars());
  }

  log_info('GROUP_DRUGS', get_defined_vars());

  return $groups;
}

function send_created_order_communications($groups) {

  if ( ! $groups['ALL'][0]['count_nofill'] AND ! $groups['ALL'][0]['count_filled']) {
    log_error("send_created_order_communications: ! count_nofill and ! count_filled. What to do?", $groups);
  }

  //['Not Specified', 'Webform Complete', 'Webform eRx', 'Webform Transfer', 'Auto Refill', '0 Refills', 'Webform Refill', 'eRx /w Note', 'Transfer /w Note', 'Refill w/ Note']
  else if ($groups['ALL'][0]['order_source'] == 'Webform Transfer' OR $groups['ALL'][0]['order_source'] == 'Transfer /w Note')
    transfer_requested_notice($groups);

  else
    order_created_notice($groups);
}

function send_shipped_order_communications($groups) {

  order_shipped_notice($groups);
  confirm_shipment_notice($groups);
  refill_reminder_notice($groups);

  if ($groups['ALL'][0]['payment_method'] == PAYMENT_METHOD['AUTOPAY'])
    autopay_reminder_notice($groups);
}

function send_dispensed_order_communications($groups) {
  order_dispensed_notice($groups);
}

function send_updated_order_communications($groups, $items_added, $items_to_remove) {

  $add_item_names    = [];
  $remove_item_names = [];
  $patient_updates   = [];

  foreach ($items_added as $item) {
    $add_item_names[] = $item['drug_name'];
  }

  foreach ($items_to_remove as $item) {
    $remove_item_names[] = $item['drug_name'];
  }

  if ($add_item_names) {
    $verb = count($add_item_names) == 1 ? 'was' : 'were';
    $patient_updates[] = implode(", ", $add_item_names)." $verb added to your order.";
  }

  if ($remove_item_names) {
    $verb = count($remove_item_names) == 1 ? 'was' : 'were';
    $patient_updates[] = implode(", ", $remove_item_names)." $verb removed from your order.";
  }

  order_updated_notice($groups, $patient_updates);

  log_info('send_updated_order_communications', [
    'groups' => $groups,
    'items_added' => $items_added,
    'items_to_remove' => $items_to_remove,
    'patient_updates' => $patient_updates
  ]);
}
