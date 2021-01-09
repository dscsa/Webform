<?php

require_once 'helpers/helper_full_order.php';
require_once 'helpers/helper_payment.php';
require_once 'helpers/helper_syncing.php';
require_once 'helpers/helper_communications.php';
require_once 'exports/export_wc_orders.php';
require_once 'exports/export_cp_orders.php';

use Sirum\Logging\SirumLog;
use Sirum\Logging\AuditLog;

function update_orders_cp($changes)
{
    $count_deleted = count($changes['deleted']);
    $count_created = count($changes['created']);
    $count_updated = count($changes['updated']);

    $msg = "$count_deleted deleted, $count_created created, $count_updated updated ";
    echo $msg;
    SirumLog::info(
        "update_orders_cp: all changes. {$msg}",
        [
            'deleted_count' => $count_deleted,
            'created_count' => $count_created,
            'updated_count' => $count_updated
        ]
    );

    if (! $count_deleted and ! $count_created and ! $count_updated) {
        return;
    }

    $mysql = new Mysql_Wc();

    /*
       If just added to CP Order we need to
        - Find out any other rxs need to be added
        - Update invoice
        - Update wc order count/total
    */
    $loop_timer = microtime(true);
    foreach ($changes['created'] as $created) {
        SirumLog::$subroutine_id = "orders-cp-created-".sha1(serialize($created));

        $duplicate = get_current_orders($mysql, ['patient_id_cp' => $created['patient_id_cp']]);

        SirumLog::debug(
            "get_full_order: Carepoint Order created ". $created['invoice_number'],
            [
                'invoice_number' => $created['invoice_number'],
                'created'   => $created,
                'duplicate' => $duplicate,
                'source'    => 'CarePoint',
                'type'      => 'orders',
                'event'     => 'created'
            ]
        );

        if ($created['order_date_returned']) {
          export_wc_return_order($created['invoice_number']);
          continue;
        }

        if (count($duplicate) > 1
            and $duplicate[0]['invoice_number'] != $created['invoice_number']) {
            SirumLog::warning(
                sprintf(
                    "Created Carepoint Order Seems to be a duplicate %s >>> %s",
                    $duplicate[0]['invoice_number'],
                    $created['invoice_number']
                ),
                [
                    'invoice_number' => $created['invoice_number'],
                    'created' => $created,
                    'duplicate' => $duplicate
                ]
            );

            AuditLog::log(
                "Duplicate invoice found in Carepoint and deleted",
                $created
            );

            /*
                Not sure what we should do here. Delete it? Instance where
                current order doesn't have all drugs, so patient/staff add a
                second order with the drug.  Merge orders?
             */
            $order = export_v2_unpend_order(
                $created,
                $mysql,
                sprintf(
                    "Duplicate order  %s >>> %s",
                    $duplicate[0]['invoice_number'],
                    $created['invoice_number']
                )
            );

            export_cp_remove_order(
                $created['invoice_number'],
                "Duplicate of {$duplicate[0]['invoice_number']}"
            );

            continue;
        }

        // Overwrite Rx Messages everytime a new order created otherwise
        // same message would stay for the life of the Rx
        $order = load_full_order($created, $mysql, true);

        if (! $order) {
            SirumLog::debug(
                "Created Order Missing.  Most likely because cp order has liCount >
                  0 even though 0 items in order.  If correct, update liCount in CP to 0",
                ['order' => $order]
            );
            continue;
        }

        SirumLog::debug(
            "Order found for created order",
            [
                'invoice_number' => $order[0]['invoice_number'],
                'order'          => $order,
                'created'        => $created
            ]
        );

        //TODO Add Special Case for Webform Transfer [w/ Note] here?

        if ($created['order_status'] == "Surescripts Authorization Approved") {
            AuditLog::log(
                sprintf(
                    "SureScript authorization approved for invoice %s.",
                    $created['invoice_number']
                ),
                $created
            );
            SirumLog::error(
                "Surescripts Authorization Approved. Created.  What to do here?
                Keep Order {$created['invoice_number']}? Delete Order? Depends
                on Autofill settings?",
                [
                  'invoice_number'   => $created['invoice_number'],
                  'count_items'      => count($order)." / ".@$order['count_items'],
                  'patient_autofill' => $order[0]['patient_autofill'],
                  'rx_autofill'      => $order[0]['rx_autofill'],
                  'order'            => $order
                ]
            );
        }

        if ($order[0]['order_date_dispensed']) {
            $reason = "update_orders_cp: dispened/shipped/returned order being readded";

            export_wc_create_order($order, $reason);
            $order = export_gd_publish_invoice($order, $mysql);
            export_gd_print_invoice($order);
            AuditLog::log(
                sprintf(
                    "Order %s marked as dispensed",
                    $created['invoice_number']
                ),
                $created
            );

            SirumLog::debug(
                'Dispensed/Shipped/Returned order is missing and is being added back to the wc and gp tables',
                [
                    'invoice_number' => $order[0]['invoice_number'],
                    'order'          => $order
                ]
            );

            continue;
        }

        /*
           Patient communication that we are cancelling their order
           examples include:
                NEEDS FORM
                ORDER HOLD WAITING FOR RXS
                TRANSFER OUT OF ALL ITEMS
                ACTION PATIENT OFF AUTOFILL
         */

        if ($order[0]['count_filled'] == 0
            and $order[0]['count_to_add'] == 0
            and ! is_webform_transfer($order[0])) {
            AuditLog::log(
                sprintf(
                    "Order %s has no drugs to fill, so it will be removed",
                    $created['invoice_number']
                ),
                $created
            );

            SirumLog::warning(
                "update_orders_cp: created. no drugs to fill. removing order
                {$order[0]['invoice_number']}. Can we remove the v2_unpend_order
                below because it get called on the next run?",
                [
                    'invoice_number'  => $order[0]['invoice_number'],
                    'count_filled'    => $order[0]['count_filled'],
                    'count_items'     => $order[0]['count_items'],
                    'count_to_add'    => $order[0]['count_to_add'],
                    'count_to_remove' => $order[0]['count_to_remove'],
                    'order'           => $order
                ]
            );

            if ($order[0]['count_items'] - $order[0]['count_to_remove']) {
              SirumLog::alert("update_orders_cp: created. canceling order, but is their a manually added item that we should keep?");
            }

            //TODO Remove/Cancel WC Order Here Or Is this done on next go-around?

            /*
               TODO Why do we need to explicitly unpend?  Deleting an order in CP
               should trigger the deleted loop on next run, which should unpend
               But it seemed that this didn't happen for Order 53684
             */
            if ( ! $order[0]['pharmacy_name'])
              $reason = 'Needs Registration';

            else if ($order[0]['order_status'] == "Surescripts Authorization Denied")
              $reason = 'Surescripts Denied';

            else if ($order[0]['count_to_remove']) {
              $reason = $order[0]['count_to_remove'].' Rxs Removed';

              if ($order[0]['count_items'] == 1) //Not enough space to put reason if >1 drug removed. using 0-index depends on the current sort order based on item_date_added.
                $reason .= " {$order[0]['drug_name']} {$order[0]['rx_number']} {$order[0]['rx_message_key']}";
            }

            else
              $reason = 'Created Empty';

            $order  = export_v2_unpend_order($order, $mysql, $reason);
            export_cp_remove_order($order[0]['invoice_number'], $reason);
            continue;
        }

        if (is_webform_transfer($order[0])) {
            continue; // order hold notice not necessary for transfers
        }

        //Needs to be called before "$groups" is set
        $order  = sync_to_date($order, $mysql);
        $groups = group_drugs($order, $mysql);

        /*
         * 3 Steps of ACTION NEEDS FORM:
         * 1) Here.  update_orders_cp created (surescript came in and created a CP order)
         * 2) Same cycle: update_order_wc deleted (since WC doesn't have the new order yet)
         * 3) Next cycle: update_orders_cp deleted (not sure yet why it gets deleted from CP)
         * Can't test for rx_message_key == 'ACTION NEEDS FORM' because other messages can take precedence
         */

        if ( ! $order[0]['pharmacy_name']) {
            AuditLog::log(
                sprintf(
                    "Order %s was created but patient hasn't yet registered in Patient Portal",
                    $created['invoice_number']
                ),
                $created
            );
            needs_form_notice($groups);
            SirumLog::notice(
                "update_orders_cp created: Guardian Order Created But
                  Patient Not Yet Registered in WC so not creating WC Order",
                [
                  'invoice_number' => $order[0]['invoice_number'],
                  'order' => $order
                ]
            );
            continue;
        }

        if ($order[0]['count_filled'] > 0 OR $order[0]['count_to_add'] > 0) {

          //This is not necessary if order was created by webform, which then created the order in Guardian
          //"order_source": "Webform eRX/Transfer/Refill [w/ Note]"
          if ( ! is_webform($order[0])) {
              SirumLog::debug(
                  "Creating order ".$order[0]['invoice_number']." in woocommerce because source is not the Webform and looks like there are items to fill",
                  [
                      'invoice_number' => $order[0]['invoice_number'],
                      'source'         => $order[0]['order_source'],
                      'order'          => $order,
                      'groups'         => $groups
                  ]
              );

              export_wc_create_order($order, "update_orders_cp: created");
          }

          continue; // order hold notice not necessary if we are adding items on next go-around
        }

        order_hold_notice($groups);

        AuditLog::log(
            sprintf(
                "Order %s is on hold, unknown reason",
                $order[0]['invoice_number']
            ),
            $order[0]
        );
        SirumLog::debug(
            "update_orders_cp: Order Hold, unknown reason
            should have been deleted with sync code above",
            [
                'invoice_number' => $order[0]['invoice_number'],
                'order'          => $order,
                'groups'         => $groups
            ]
        );

        /*
           TODO Update Salesforce Order Total & Order Count & Order Invoice
           using REST API or a MYSQL Zapier Integration
         */
    } // END created loop
    log_timer('orders-cp-created', $loop_timer, $count_created);


    /*
     * If just deleted from CP Order we need to
     *  - set "days_dispensed_default" and "qty_dispensed_default" to 0
     *  - unpend in v2 and save applicable fields
     *  - if last line item in order, find out any other rxs need to be removed
     *  - update invoice
     *  - update wc order total
     */
    $loop_timer = microtime(true);
    foreach ($changes['deleted'] as $deleted) {
        SirumLog::$subroutine_id = "orders-cp-deleted-".sha1(serialize($deleted));

        SirumLog::debug(
            "update_orders_cp: carepoint order {$deleted['invoice_number']} has been deleted",
            [
                'source'         => 'CarePoint',
                'event'          => 'deleted',
                'type'           => 'orders',
                'invoice_number' => $deleted['invoice_number'],
                'deleted'        => $deleted
            ]
        );

        if ($deleted['order_status'] == "Surescripts Authorization Denied") {
            AuditLog::log(
                sprintf(
                    "SureScript authorization denied for invoice %s.  Order will be deleted",
                    $deleted['invoice_number']
                ),
                $deleted
            );
            SirumLog::warning(
                "Surescripts Authorization Denied for Order {$deleted['invoice_number']}.
                Deleted. Skipping for now. What to do here? Unpend?",
                [
                    'invoice_number' => $deleted['invoice_number'],
                    'deleted' => $deleted,
                    'source'  => 'CarePoint',
                    'type'    => 'orders',
                    'event'   => 'deleted'
                ]
            );
            /*
                These are deleted automatically (before having any side effects)
                so we don't want to trigger any side effects here either
             */
            continue;
        }

        if ($deleted['order_status'] == "Surescripts Authorization Approved") {
            AuditLog::log(
                sprintf(
                    "SureScript authorization was approved for invoice %s,
                    but the order was deleted in CarePoint",
                    $deleted['invoice_number']
                ),
                $deleted
            );
            SirumLog::warning(
                "Surescripts Authorization Approved. Deleted.  What to do here?",
                [
                    'invoice_number'   => $deleted['invoice_number'],
                    'count_items'      => $deleted['count_items'],
                    'deleted'          => $deleted
                ]
            );
        }


        //Order #28984, #29121, #29105
        if (! $deleted['patient_id_wc']) {
            /*
               Likely
                    (1) Guardian Order Was Created But Patient Was Not Yet Registered
                        in WC so never created WC Order (and No Need To Delete It)
                    (2) OR Guardian Order had items synced to/from it, so was deleted
                        and readded, which effectively erases the patient_id_wc
            */
            SirumLog::error(
                'update_orders_cp: cp order deleted - no patient_id_wc',
                [ 'deleted' => $deleted ]
            );
        } else {
            SirumLog::notice(
                'update_orders_cp: cp order deleted so deleting wc order as well',
                [ 'deleted' => $deleted ]
            );
        }

        export_gd_delete_invoice($deleted['invoice_number']);

        $is_canceled = ($deleted['count_filled'] > 0 or is_webform($deleted));

        //[NULL, 'Webform Complete', 'Webform eRx', 'Webform Transfer', 'Auto Refill', '0 Refills', 'Webform Refill', 'eRx /w Note', 'Transfer /w Note', 'Refill w/ Note']
        if ($is_canceled) {
            AuditLog::log(
                sprintf(
                    "Order %s was cancelled in CarePoint",
                    $deleted['invoice_number']
                ),
                $deleted
            );
            export_wc_cancel_order($deleted['invoice_number'], "update_orders_cp: cp order canceled $deleted[invoice_number] $deleted[order_stage_cp] $deleted[order_stage_wc] $deleted[order_source] ".json_encode($deleted));
        } else {
            AuditLog::log(
                sprintf(
                    "Order %s was deleted in CarePoint",
                    $deleted['invoice_number']
                ),
                $deleted
            );
            export_wc_delete_order($deleted['invoice_number'], "update_orders_cp: cp order deleted $deleted[invoice_number] $deleted[order_stage_cp] $deleted[order_stage_wc] $deleted[order_source] ".json_encode($deleted));
        }

        export_cp_remove_items($deleted['invoice_number']);

        $patient = load_full_patient($deleted, $mysql, true);  //Cannot load order because it was already deleted in changes_orders_cp
        $groups  = group_drugs($patient, $mysql);

        SirumLog::info(
            'update_orders_cp deleted: unpending all items',
            [
                'deleted' => $deleted,
                'groups' => $groups,
                'patient' => $patient
            ]
        );

        //can't do export_v2_unpend_order because each item won't have an invoice number or order_added_date
        if ($patient)
          foreach ($patient as $i => $item) {
            $patient[$i] = v2_unpend_item(array_merge($item, $deleted), $mysql, 'update_orders_cp deleted: unpending all items');
          }

        AuditLog::log(
            sprintf(
                "All items for order %s have been unpended",
                $deleted['invoice_number']
            ),
            $deleted
        );

        $replacement = get_current_orders($mysql, ['patient_id_cp' => $deleted['patient_id_cp']]);

        if ($replacement) {
            AuditLog::log(
                sprintf(
                    "Order %s was deleted in CarePoint",
                    $deleted['invoice_number']
                ),
                $deleted
            );
            SirumLog::warning(
                'update_orders_cp deleted: their appears to be a replacement',
                [
                    'deleted'     => $deleted,
                    'replacement' => $replacement,
                    'groups'      => $groups,
                    'patient'     => $patient
                ]
            );
            continue;
        }

        if ($is_canceled) {
            SirumLog::warning(
                "update_orders_cp deleted: order_canceled_notice is this right?",
                [
                    'deleted' => $deleted,
                    'groups'  => $groups,
                    'patient' => $patient
                ]
            );
            order_canceled_notice($deleted, $groups); //We passed in $deleted because there is not $order to make $groups
            continue;
        }
    }
    log_timer('orders-cp-deleted', $loop_timer, $count_deleted);


    //If just updated we need to
    //  - see which fields changed
    //  - think about what needs to be updated based on changes
    $loop_timer = microtime(true);
    foreach ($changes['updated'] as $i => $updated) {
        SirumLog::$subroutine_id = "orders-cp-updated-".sha1(serialize($updated));

        $changed  = changed_fields($updated);

        SirumLog::debug(
            "Carepoint Order {$updated['invoice_number']} has been updated",
            [
                'source'         => 'CarePoint',
                'event'          => 'updated',
                'invoice_number' => $updated['invoice_number'],
                'type'           => 'orders',
                'updated'        => $updated,
                'changed'        => $changed
            ]
        );

        $stage_change_cp = $updated['order_stage_cp'] != $updated['old_order_stage_cp'];

        SirumLog::notice(
            sprintf(
                "Updated Orders Cp: %s %s of %s",
                $updated['invoice_number'],
                ($i+1),
                count($changes['updated'])
            ),
            [ 'changed' => $changed]
        );

        $order  = load_full_order($updated, $mysql);
        $groups = group_drugs($order, $mysql);

        if (!$order) {
            SirumLog::error("Updated Order Missing", [ 'order' => $order ]);
            continue;
        }

        SirumLog::debug(
            "Order found for updated order",
            [
                'invoice_number'     => $order[0]['invoice_number'],
                'order'              => $order,
                'updated'            => $updated,
                'order_date_shipped' => $updated['order_date_shipped'],
                'stage_change_cp'    => $stage_change_cp
            ]
        );

        if ($stage_change_cp and $updated['order_date_returned']) {
            AuditLog::log(
                sprintf(
                    "Order %s has been returned",
                    $updated['invoice_number']
                ),
                $updated
            );
            SirumLog::alert(
                'Confirm this order was returned! cp_order with tracking number was deleted, but we keep it in gp_orders and in wc',
                [ 'updated' => $updated ]
            );

            //TODO Patient Communication about the return?

            export_wc_return_order($order[0]['invoice_number']);

            continue;
        }

        if ($stage_change_cp and $updated['order_date_shipped']) {
            AuditLog::log(
                sprintf(
                    "Order %s was shipped.  Tracking number is %s",
                    $updated['invoice_number'],
                    $order[0]['tracking_number']
                ),
                $order
            );

            SirumLog::notice("Updated Order Shipped Started", [ 'order' => $order ]);
            $order = export_v2_unpend_order($order, $mysql, "Order Shipped");
            export_wc_update_order_status($order); //Update status from prepare to shipped
            export_wc_update_order_metadata($order);
            send_shipped_order_communications($groups);
            continue;
        }

        if ($stage_change_cp and $updated['order_date_dispensed']) {
            AuditLog::log(
                sprintf(
                    "Order %s was dispensed",
                    $updated['invoice_number']
                ),
                $updated
            );
            $reason = "update_orders_cp updated: Updated Order Dispensed ".$updated['invoice_number'];
            $order = helper_update_payment($order, $reason, $mysql);
            $order = export_gd_update_invoice($order, $reason, $mysql);
            $order = export_gd_publish_invoice($order, $mysql);
            export_gd_print_invoice($order);
            send_dispensed_order_communications($groups);
            SirumLog::notice($reason, [ 'order' => $order ]);
            continue;
        }

        /*
            We should be able to delete wc-confirm-* from CP queue without
            triggering an order cancel notice
        */
        // count_items may already be 0 on a deleted order that had items e.g 33840
        if ($order[0]['count_filled'] == 0 and $order[0]['count_nofill'] == 0) {
            SirumLog::warning(
                "update_orders_cp updated: no_rx_notice count_filled == 0 AND count_nofill == 0",
                [
                    'updated' => $updated,
                    'groups'  => $groups
                ]
            );
            no_rx_notice($updated, $groups);
            continue;
        }

        /*
            Patient communication that we are cancelling their order
            examples include:
                NEEDS FORM
                ORDER HOLD WAITING FOR RXS
                TRANSFER OUT OF ALL ITEMS
                ACTION PATIENT OFF AUTOFILL
        */

        // count_items in addition to count_filled because it might be a manually
        //  added item, that we are not filling but that the pharmacist is using
        //  as a placeholder/reminder e.g 54732
        if ($order[0]['count_items'] == 0
            and $order[0]['count_filled'] == 0
            and $order[0]['count_to_add'] == 0
            and !is_webform_transfer($order[0])) {
            AuditLog::log(
                sprintf(
                    "Order %s has no Rx to fill so it will be cancelled",
                    $updated['invoice_number']
                ),
                $updated
            );
            SirumLog::alert(
                'update_orders_cp: updated. no drugs to fill. remove order '.$order[0]['invoice_number'].'?',
                [
                    'invoice_number' => $order[0]['invoice_number'],
                    'count_filled'   => $order[0]['count_filled'],
                    'count_items'    => $order[0]['count_items'],
                    'order'          => $order
                ]
            );

            order_canceled_notice($updated, $groups);

            /*
                TODO Why do we need to explicitly unpend?  Deleting an order in
                CP should trigger the deleted loop on next run, which should unpend
                But it seemed that this didn't happen for Order 53684

                TODO Necessary to Remove/Cancel WC Order Here?
             */
            $order = export_v2_unpend_order($order, $mysql, 'Updated Empty');
            export_cp_remove_order($order[0]['invoice_number'], 'Updated Empty');
            continue;
        }

        //Address Changes
        //Stage Change
        //Order_Source Change (now that we overwrite when saving webform)
        SirumLog::notice(
            "update_orders_cp updated: no action taken {$updated['invoice_number']}",
            [
                'order'   => $order,
                'updated' => $updated,
                'changed' => $changed
            ]
        );
    }
    log_timer('orders-cp-updated', $loop_timer, $count_updated);

    SirumLog::resetSubroutineId();
}
