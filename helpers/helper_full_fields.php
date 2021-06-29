
<?php

use GoodPill\Models\GpOrderItem;
use GoodPill\Models\GpRxsGrouped;
use GoodPill\Logging\{
    GPLog,
    AuditLog,
    CliLog
};

//Simplify GDoc Invoice Logic by combining _actual
function add_full_fields($patient_or_order, $mysql, $overwrite_rx_messages)
{
    $count_filled            = 0;
    $items_to_add            = [];
    $items_to_remove         = [];
    $duplicate_items_removed = [];

    //Default is to update payment for new orders
    $update_payment          = ! @$patient_or_order[0]['payment_total_default'];
    $is_order                = is_order($patient_or_order);
    $is_new_order            = (is_order($patient_or_order)
                                AND is_null($patient_or_order[0]['count_filled']));

    /*
     * Consolidate default and actual suffixes to avoid conditional overload in
     * the invoice template and redundant code within communications
     *
     * Don't use val because order[$i] and $item will become out of
     * sync as we set properties
     */
    foreach ($patient_or_order as $i => $dontuse) {

        if ( ! $patient_or_order[$i]['drug_name']) {
          GPLog::notice("helper_full_fields: skipping item/rx because no drug name. likely an empty order", ['patient_or_order' => $patient_or_order]);
          continue;
        }

        if ($patient_or_order[$i]['rx_message_key'] == 'ACTION NO REFILLS'
                and @$patient_or_order[$i]['rx_dispensed_id']
                and $patient_or_order[$i]['refills_total'] >= .1) {
            log_error(
                'add_full_fields: status of ACTION NO REFILLS but has refills. ' .
                'Do we need to send updated communications?',
                $patient_or_order[$i]
            );
            $patient_or_order[$i]['rx_message_key'] = null;
        }

        $days     = null;
        $message  = null;

        //Turn string into number so that "0.00" is falsey instead of truthy
        $patient_or_order[$i]['refills_used'] = +$patient_or_order[$i]['refills_used'];

        //Set before export_gd_transfer_fax()
        $patient_or_order[$i]['rx_date_written'] = date(
            'Y-m-d',
            strtotime($patient_or_order[$i]['rx_date_expired'] . ' -1 year')
        );

        //Issues were we have a duplicate webform order (we don't delete a duplicate order if its from webform)
        //some of these orders have different items and so are "valid" but some have same item(s) that are
        //pended multiple times.  Ideally (intuitivelly) this check would be placed in update_order_items analagous
        //that duplicate orders are checked in update_orders_cp.  But order_items is called after orders_cp is run
        //and so the duplicate items will have already assigned days and been pended, which would have to be undone
        //instead putting it here so that it will be called from update_orders and update_order_items
        $duplicate_items = get_current_items($mysql, ['rx_numbers' => "'{$patient_or_order[$i]['rx_numbers']}'"]);

        if ($duplicate_items && count($duplicate_items) > 1) {
          GPLog::error(
              "helper_full_fields: {$patient_or_order[$i]['drug_generic']} is duplicate
              ITEM.  Likely Mistake. Two webform orders?",
              [
                  'duplicate_items' => $duplicate_items,
                  'item' => $patient_or_order[$i],
                  'order' => $patient_or_order,
                  'invoice_number' => $patient_or_order[$i]['invoice_number']
              ]
          );

          foreach($duplicate_items as $i => $duplicate_item) {
              if ($i == 0) continue; //keep the oldest item
              export_cp_remove_items($duplicate_item['invoice_number'], [$duplicate_item]);
              $duplicate_items_removed[] = $duplicate_item;
          }

        }

        // Overwrite refers to the rx_single and rx_grouped table not the order_items table which
        // deliberitely keeps its initial values
        $overwrite = (
          $overwrite_rx_messages === true
          or (
              is_string($overwrite_rx_messages)
              && strpos($patient_or_order[$i]['rx_numbers'], $overwrite_rx_messages) !== false
          )
        );

        $set_days_and_msgs  = (
          ! $patient_or_order[$i]['rx_message_key']
          or is_null($patient_or_order[$i]['rx_message_text'])
          or (
            @$patient_or_order[$i]['item_date_added']
            and is_null($patient_or_order[$i]['days_dispensed_default'])
          )
        );

        $log_suffix = @$patient_or_order[$i]['invoice_number'].' '.$patient_or_order[$i]['first_name'].' '.$patient_or_order[$i]['last_name'].' '.$patient_or_order[$i]['drug_generic'];

        GPLog::notice(
          "add_full_fields $log_suffix",
          [
            "set_days_and_msgs"      => $set_days_and_msgs,
            "overwrite"              => $overwrite,
            "overwrite_rx_messages"  => $overwrite_rx_messages,
            "rx_number"              => $patient_or_order[$i]['rx_number'],
            "patient_or_order[i]"    => $patient_or_order[$i]
          ]
        );

        if ($set_days_and_msgs or $overwrite) {

            list($days, $message) = get_days_and_message($patient_or_order[$i], $patient_or_order);

            /*
             *  Refactored Model Code
             * Used for comparisons currently
             * Need to observe logs and compare to actual production values
             *
             */
            if ($patient_or_order[0]['is_order'] && $patient_or_order[$i]['item_date_added']) {
                $item = GpOrderItem::where('invoice_number', $patient_or_order[$i]['invoice_number'])
                    ->where('rx_number', $patient_or_order[$i]['rx_number'])
                    ->first();
                DaysMessageHelper::getDaysAndMessage($item);
            } else {
                //  This specifically is matching on `rx_numbers`
                //  This is because the assumption is that $patient_or_order already has it's grouped data on the item
                //  and we are just refetching the same thing as a GpRxsGrouped item
                $item = GpRxsGrouped::where('rx_numbers', $patient_or_order[$i]['rx_numbers'])->first();
                DaysMessageHelper::getDaysAndMessage($item);
            }

            //If days_actual are set, then $days will be 0 (because it will be a recent fill)
            $days_added      = (@$patient_or_order[$i]['item_date_added'] AND $days > 0 AND ! @$patient_or_order[$i]['days_dispensed_default']);
            $days_changed    = (@$patient_or_order[$i]['days_dispensed_default'] AND ! @$patient_or_order[$i]['days_dispensed_actual'] AND @$patient_or_order[$i]['days_dispensed_default'] != $days AND @$patient_or_order[$i]['sync_to_date_days_before'] != $days);

            $needs_adding    = ( ! @$patient_or_order[$i]['item_date_added'] AND $days > 0);
            $needs_removing  = (@$patient_or_order[$i]['item_date_added'] AND $days == 0 AND ! is_added_manually($patient_or_order[$i]));

            // The Rx has been added to an order, there are more than 0 days
            // available and we haven't already pended any stock
            $needs_pending   = (@$patient_or_order[$i]['item_date_added'] AND $days > 0  AND ! @$patient_or_order[$i]['count_pended_total']);

            // This item has been pended, but the patient no longer has days left.
            // This is either because the order was filled or they are out of refills
            $needs_unpending = (@$patient_or_order[$i]['item_date_added'] AND $days == 0 AND @$patient_or_order[$i]['count_pended_total']);
            $needs_repending = (@$patient_or_order[$i]['item_date_added'] AND $days_changed AND ! $needs_pending);

            $get_days_and_message = [
                "overwrite_rx_messages" => $overwrite_rx_messages,
                'is_order'              => $is_order,
                'is_new_order'          => $is_new_order,
                "rx_number"             => $patient_or_order[$i]['rx_number'],
                "item_added"            => @$patient_or_order[$i]['item_date_added'] . ' ' . @$patient_or_order[$i]['item_added_by'],

                "new_days_dispensed_default" => $days,
                "old_days_dispensed_default" => @$patient_or_order[$i]['days_dispensed_default'], //Applicable for order but not for patient

                "new_rx_message_text" => "$message[EN] ($message[CP_CODE])",
                "old_rx_message_text" => $patient_or_order[$i]['rx_message_text'],

                "item"                     => $patient_or_order[$i],
                'needs_adding'             => $needs_adding,
                'needs_removing'           => $needs_removing,
                "needs_pending"            => $needs_pending,
                "needs_unpending"          => $needs_unpending,
                "needs_repending"          => $needs_repending,
                "days_added"               => $days_added,
                "days_changed"             => $days_changed,
                "sync_to_date_days_before" => @$patient_or_order[$i]['sync_to_date_days_before']
            ];

             //54376 Sertraline. Probably should create a new order?
            if (($days_added OR $needs_adding) AND @$patient_or_order[$i]['order_date_dispensed'])
              GPLog::error("get_days_and_message ADDING ITEMS RIGHT BEFORE DISPENSING ORDER? $log_suffix", $get_days_and_message);
            else
              GPLog::notice("get_days_and_message $log_suffix", $get_days_and_message);

            //Internal logic keeps initial values on order_items if they exist (don't want to contradict patient comms)
            $patient_or_order[$i] = set_days_and_message($patient_or_order[$i], $days, $message, $mysql);

            export_cp_set_rx_message($patient_or_order[$i], $message);

            if ($needs_removing) {

              if ( ! is_patient($patient_or_order)) { //item or order
                $items_to_remove[] = $patient_or_order[$i];
              } else {
                GPLog::notice(
                    "Item needs to be removed but IS_PATIENT.  This happens when there is
                    a patient change before the order change has been processed.  It should clear
                    itself whenthe order processes",
                    [
                      'days'    => $days,
                      'message' => $message,
                      'item'    => $patient_or_order[$i]
                    ]
                );
              }

              GPLog::notice(
                "helper_full_fields: needs_removing (export_cp_remove_items) ".$patient_or_order[$i]['drug_name'],
                [
                  'item'    => $patient_or_order[$i],
                  'items_to_remove' => $items_to_remove,
                  'days'    => $days,
                  'message' => $message
                ]
              );
            }

            if ($needs_adding) {

              if ( ! is_patient($patient_or_order)) { //item or order
                $patient_or_order[$i]['days_to_add']    = $days;
                $patient_or_order[$i]['message_to_add'] = $message;
                $items_to_add[] = $patient_or_order[$i];
              } else {
                GPLog::warning("Item needs to be added but IS_PATIENT (rxs-single-created2)? Likely IS_ORDER or IS_ITEM will run shortly", [
                  'days'    => $days,
                  'message' => $message,
                  'item'    => $patient_or_order[$i],
                  'todo'    => "If IS_ORDER or IS_ITEM is not run, should we create an order here so that we can add this item?"
                ]);
              }

              GPLog::notice(
                "helper_full_fields: needs_adding (export_cp_add_items) ".$patient_or_order[$i]['drug_name'],
                [
                  'item'         => $patient_or_order[$i],
                  'items_to_add' => $items_to_add,
                  'days'         => $days,
                  'message'      => $message
                ]
              );
            }

            if($needs_pending) {
              GPLog::notice(
                  "helper_full_fields: needs pending",
                  [
                      'get_days_and_message' => $get_days_and_message,
                      'item' => $patient_or_order[$i]
                  ]
              );
              $patient_or_order[$i] = v2_pend_item(
                  $patient_or_order[$i],
                  "Rx has been added to an order and scheduled for dispensing");
            }

            if($needs_unpending) {
              GPLog::notice(
                  "helper_full_fields: needs UN-pending",
                  [
                      'get_days_and_message' => $get_days_and_message,
                      'item' => $patient_or_order[$i]
                  ]);
              $patient_or_order[$i] = v2_unpend_item(
                  $patient_or_order[$i],
                  "Item dispensed or refills have expired"
              );
            }

            if ($needs_repending) {
              GPLog::notice("helper_full_fields: needs repending", ['get_days_and_message' => $get_days_and_message, 'item' => $patient_or_order[$i]]);
              $patient_or_order[$i] = v2_unpend_item($patient_or_order[$i], "helper_full_fields needs_repending");
              $patient_or_order[$i] = v2_pend_item($patient_or_order[$i], "helper_full_fields needs_repending");
            }

            if ($days_added) {
              $update_payment = true; //Too bad there is not a calculation for $items_removed and we instead have to use the proxy items_to_remove which won't detect manual changes
            }

            if ($days_changed) {
              $update_payment = true;
            }

            //Internal logic determines if fax is necessary
            if ($set_days_and_msgs) //Sending because of overwrite may cause multiple faxes for same item
              export_gd_transfer_fax($patient_or_order[$i], 'helper full fields');

            if ($patient_or_order[$i]['sig_days'] and $patient_or_order[$i]['sig_days'] != 90) {
              GPLog::notice("helper_full_order: sig has days specified other than 90", $patient_or_order[$i]);
            }
        }

        if ( ! $patient_or_order[$i]['rx_message_key'] or is_null($patient_or_order[$i]['rx_message_text'])) {
          log_error(
            "add_full_fields: error rx_message not set! $log_suffix",
            [
              'item' => $patient_or_order[$i],
              'days' => $days,
              'message' => $message,
              'set_days_and_msgs' => $set_days_and_msgs,
              '! order[$i][rx_message_key] '       => ! $patient_or_order[$i]['rx_message_key'],
              'is_null(order[$i][rx_message_text]' => is_null($patient_or_order[$i]['rx_message_text'])
            ]
          );
        }

        $patient_or_order[$i]['drug']           = patient_drug_text($patient_or_order[$i]);
        $patient_or_order[$i]['payment_method'] = patient_payment_method($patient_or_order[$i]);

        if (is_patient($patient_or_order)) {
            /*
             * The rest of the fields are order specific and will not be
             * available if this is a patient
             */
            continue;
        }

        $patient_or_order[$i]['days_dispensed']    = patient_days_dispensed($patient_or_order[$i]);
        $patient_or_order[$i]['price_dispensed']   = patient_price_dispensed($patient_or_order[$i]);
        $patient_or_order[$i]['qty_dispensed']     = patient_qty_dispensed($patient_or_order[$i]);
        $patient_or_order[$i]['refills_dispensed'] = patient_refills_dispensed($patient_or_order[$i]);

        if ($patient_or_order[$i]['days_dispensed']) { //this will not include items_to_add
          $count_filled++;
        }

    } //END LARGE FOR LOOP

    if ($items_to_remove) { //WARNING EMPTY OR NULL ARRAY WOULD REMOVE ALL ITEMS
      export_cp_remove_items($patient_or_order[0]['invoice_number'], $items_to_remove);
    }

    if ($items_to_add) {
      export_cp_add_items($patient_or_order[0]['invoice_number'], $items_to_add);
    }

    if ($is_order) {

      $count_nofill = $patient_or_order[0]['drug_name'] //is this an empty order
        ? count($patient_or_order) - $count_filled
        : 0;

      foreach ($patient_or_order as $i => $item) {
        $patient_or_order[$i]['count_nofill']    = $count_nofill;
        $patient_or_order[$i]['count_filled']    = $count_filled;
        $patient_or_order[$i]['count_to_remove'] = count($items_to_remove);
        $patient_or_order[$i]['count_duplicates_removed'] = count($duplicate_items_removed);
        $patient_or_order[$i]['count_to_add']    = count($items_to_add);
      }

      $sql = "
        UPDATE
          gp_orders
        SET
          count_filled = '{$patient_or_order[0]['count_filled']}',
          count_nofill = '{$patient_or_order[0]['count_nofill']}'
        WHERE
          invoice_number = {$patient_or_order[0]['invoice_number']}
      ";
      $mysql->run($sql);
    }

    if ($is_new_order) {

      $reason = "helper_full_fields: send_created_order_communications. Order {$patient_or_order[0]['invoice_number']}";

      GPLog::debug(
        $reason,
        [
          'invoice_number'  => $patient_or_order[0]['invoice_number'],
          'count_nofill'    => $patient_or_order[0]['count_nofill'],
          'count_filled'    => $patient_or_order[0]['count_filled'],
          'count_items'     => $patient_or_order[0]['count_items'],
          'count_to_remove' => $patient_or_order[0]['count_to_remove'],
          'count_to_add'    => $patient_or_order[0]['count_to_add'],
          'is_order'        => $is_order,
          'is_new_order'    => $is_new_order,
          'items_to_add'    => $items_to_add,
          'items_to_remove' => $items_to_remove,
          'order'           => $patient_or_order
        ]
      );

      $groups = group_drugs($patient_or_order, $mysql);
      send_created_order_communications($groups, $items_to_add); //items_added will already be in count_filled.  should we include removals?

    }

    //Check for invoice_number because a patient profile may have an rx turn on/off autofill, causing a day change but we still don't have an order to update
    //TODO Don't generate invoice if we are adding/removing drugs on next go-around, since invoice would need to be updated again?
    if ($is_order AND $update_payment AND ! $items_to_remove AND ! $items_to_add) {

      $reason = "helper_full_fields: is_order and update_payment. Order {$patient_or_order[0]['invoice_number']}";

      GPLog::debug(
        $reason,
        [
          'invoice_number'  => $patient_or_order[0]['invoice_number'],
          'count_nofill'    => $patient_or_order[0]['count_nofill'],
          'count_filled'    => $patient_or_order[0]['count_filled'],
          'count_items'     => $patient_or_order[0]['count_items'],
          'count_to_remove' => $patient_or_order[0]['count_to_remove'],
          'count_to_add'    => $patient_or_order[0]['count_to_add'],
          'order'           => $patient_or_order
        ]
      );

      $patient_or_order = helper_update_payment($patient_or_order, $reason, $mysql);
    }

    return $patient_or_order;
}
