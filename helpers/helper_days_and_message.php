<?php
require_once 'helpers/helper_calendar.php';

use GoodPill\Logging\{
    GPLog,
    AuditLog,
    CliLog
};

/**
 * Returns the number of days of medicine available and a message
 * describing the condition
 * @param  array $item              A RX we are trying to fill
 * @param  array $patient_or_order  The entire patien or order
 * @return array                    [
 *                                   number_of_days_available
 *                                   message
 *                                  ]
 */
function get_days_and_message($item, $patient_or_order)
{
    GPLog::debug(
        "Get Days And Message Input Parameters",
        [
            'item' => $item,
            'patient_or_order'  => $patient_or_order,
        ]
    );

  // Is this an item we will transfer
    $no_transfer      = is_no_transfer($item);

    // Added by a person, rather than automation, either through CarePoint or Patient Portal
    // we assume that this person knows what they are doing, so we don't want to override
    //their preference if automation thinks something else should be done instead.
    $added_manually   = is_added_manually($item);

    // Origination of this item was carepoint not WooCommerce?
    $is_webform       = is_webform($item);

    // Not available for filling by Goodpill
    $not_offered      = is_not_offered($item);

    // Not the first time filling this Rx
    $is_refill        = is_refill($item, $patient_or_order);

    // Don't fill new perscriptions, Only fill refill
    $refill_only      = is_refill_only($item);

    // 2+ Rxs of the same drug in the order (may be same or different sig_qty_per_day).  This is (almost?) always a mistake
    // NOTE This won't detect properly if coming from get_full_item because patient_or_order is just mocked as [item] so
    // the other items can't actually be checked to see if they have same gsn as the current item
    $is_duplicate_gsn = is_duplicate_gsn($item, $patient_or_order);

    // syncable means that an item is not currently in an order but could/should be added to that order
    // item_date_added is used to determine whether the item is in the order or not
    $is_syncable      = is_syncable($item);

    // The current level for the item_message_keys
    $stock_level      = @$item['stock_level_initial'] ?: $item['stock_level'];

    // Checks to see that there is an order so we know its aorder
    // use order_date_added because invoice_number seems like it may be present
    // even if not an order
    $is_order         = is_order($patient_or_order);

    // How long before our current stock expires
    $days_left_in_expiration = days_left_in_expiration($item);

    // Days left on the current rx refill
    $days_left_in_refills    = days_left_in_refills($item);

    // The number of days left in our current inventory
    $days_left_in_stock      = days_left_in_stock($item);

    // This is the MIN of ($days_left_in_refills, $days_left_in_stock). If either
    // value is 0, Use the DAY_STD in its place
    // This is really more $days_to_fill.  It's not the default, but it's what
    // we've determined as the max amount we can fill
    $days_default            = days_default($days_left_in_refills, $days_left_in_stock, DAYS_STD, $item);

    //rx-created2 can call here and be too early even though it is not an order, we still need to catch it here
    $date_added = @$item['order_date_added'] ?: $item['rx_date_written'];
    $days_early_next = strtotime($item['refill_date_next']) - strtotime($date_added);
    $days_early_default = strtotime($item['refill_date_default']) - strtotime($date_added);
    $days_since = strtotime($date_added) - strtotime($item['refill_date_last']);

    GPLog::debug(
        "Get Days And Message All Parameters",
        [
            'item' => $item,
            'patient_or_order' => $patient_or_order,
            'no_transfer' => $no_transfer,
            'added_manually' => $added_manually,
            'is_webform' => $is_webform,
            'not_offered' => $not_offered,
            'is_refill' => $is_refill,
            'refill_only' => $refill_only,
            'is_duplicate_gsn' => $is_duplicate_gsn,
            'is_syncable' => $is_syncable,
            'stock_level' => $stock_level,
            'is_order' => $is_order,
            'days_left_in_expiration' => $days_left_in_expiration,
            'days_left_in_refills' => $days_left_in_refills,
            'days_left_in_stock' => $days_left_in_stock,
            'days_default' => $days_default,
            'date_added' => $date_added,
            'days_early_next' => $days_early_next,
            'days_early_default' => $days_early_default,
            'days_since' => $days_since
        ]
    );

    /*
      There was some error parsint the Rx
     */
    if (! $item['sig_qty_per_day_default'] and $item['refills_original'] != $item['refills_left']) {
        log_error("helper_days_and_message: RX WAS NEVER PARSED", $item);
    }

    /*
      We have multiple occurances of drugs on the same order
     */
    if (@$item['item_date_added'] and $is_duplicate_gsn) {
        log_error("helper_days_and_message: $item[drug_generic] is duplicate GSN.  Likely Mistake. Different sig_qty_per_day?", ['item' => $item, 'order' => $patient_or_order]);
    }

    /*
       If the RX has transfered, we aren't going to fill it
     */
    if ($item['rx_transfer']) {
        if (!$item['rx_date_transferred']) {
            log_error("rx_transfer is set, but rx_date_transferred is not", get_defined_vars());
        } elseif ($stock_level == STOCK_LEVEL['HIGH SUPPLY'] and strtotime($item['rx_date_transferred']) > strtotime('-'.DAYS_UNIT.' day')) {
            $created = "Created:".date('Y-m-d H:i:s');

            $salesforce = [
                "subject"   => "$item[drug_name] was transferred recently although it's high stock",
                "body"      => "Investigate why drug $item[drug_name] for Rx $item[rx_number] was transferred out on $item[rx_date_transferred] even though it's high stock. ".
                               "If the patient doesn't want or is no longer eligible for this medicine (e.g moved out of state or requested transfer), simply state the reason. ".
                               "If the patient wants and is eligible for this medicine, assign this call to '.Transfer In' so that we can call the patient and ask if they want us to fill it going forward $created",
                "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
                "assign_to" => ".Waitlist",
                "due_date"  => date('Y-m-d')
            ];

            $event_title = "$item[invoice_number] $item[drug_name] Is High Stock But Was Transferred: $salesforce[contact] $created";

            create_event($event_title, [$salesforce]);
            GPLog::warning($event_title, get_defined_vars());
        } elseif ($stock_level == STOCK_LEVEL['HIGH SUPPLY']) {
            GPLog::notice('HIGH STOCK ITEM WAS TRANSFERRED IN THE PAST', get_defined_vars());
        } else {
            GPLog::info("RX WAS ALREADY TRANSFERRED OUT", get_defined_vars());
        }

        return [0, RX_MESSAGE['NO ACTION WAS TRANSFERRED']];
    }

    /*
      Expired Medications
     */
    if (!is_null($days_left_in_expiration) and $days_left_in_expiration < DAYS_BUFFER) {
        GPLog::info("DON'T FILL EXPIRED MEDICATIONS", get_defined_vars());
        return [0, RX_MESSAGE['ACTION EXPIRED']];
    }

    if (! $item['drug_gsns'] and $item['drug_name']) {
        // Check for invoice number otherwise, seemed that SF tasks were being triplicated.
        // Unsure reason, maybe called by order_items and not just orders?
        return [
            ($item['refill_date_first'] ? $days_default : 0),
            RX_MESSAGE['NO ACTION MISSING GSN']
        ];
    }

    /*
      Don't fill drugs we no longer offer
     */
    if (! $is_refill and $not_offered) {
        GPLog::info("TRANSFER OUT NEW RXS THAT WE DONT CARRY", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION WILL TRANSFER']];
    }

    if ($no_transfer and ! $is_refill and $refill_only) {

        no_transfer_out_notice($item);

        GPLog::info("CHECK BACK IF TRANSFER OUT IS NOT DESIRED", get_defined_vars());
        return [0, RX_MESSAGE['ACTION CHECK BACK']];
    }

    // Don't return this if the item was manually added
    if (! $no_transfer and ! $is_refill and $refill_only and ! $added_manually) {
        GPLog::info("TRANSFER OUT NEW RXS THAT WE CANT FILL", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION WILL TRANSFER CHECK BACK']];
    }

    if (! @$item['rx_dispensed_id'] and $item['refills_total'] <= NO_REFILL) { //Unlike refills_dispensed_default/actual might not be set yet
        GPLog::info("DON'T FILL MEDICATIONS WITHOUT REFILLS", $item);
        return [0, RX_MESSAGE['ACTION NO REFILLS']];
    }

    if (! $item['pharmacy_name']) {
        GPLog::info("PATIENT NEEDS TO REGISTER", get_defined_vars());
        return [0, RX_MESSAGE['ACTION NEEDS FORM']];
    }

    if (! $item['patient_autofill'] and $added_manually) {
        GPLog::info("OVERRIDE PATIENT AUTOFILL OFF SINCE MANUALLY ADDED", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION PATIENT REQUESTED']];
    }

    if (! $item['patient_autofill'] and ! $is_webform) {
        GPLog::info("DON'T FILL IF PATIENT AUTOFILL IS OFF AND NOT MANUALLY ADDED AND NOT WEBFORM", get_defined_vars());
        return [0, RX_MESSAGE['ACTION PATIENT OFF AUTOFILL']];
    }

    if (! $item['patient_autofill'] and $is_webform) {

    // If patient is off autofill, allow them to request via the webform.
        // But ignore refill authorization approved/denied message
        if (@$item['item_date_added'] and @$item['item_added_by'] != 'AUT') {
            GPLog::info("OVERRIDE PATIENT AUTOFILL OFF SINCE SELECT AS PART OF WEBFORM ORDER", get_defined_vars());
            return [$days_default, RX_MESSAGE['NO ACTION PATIENT REQUESTED']];
        }

        GPLog::info("DON'T FILL IF PATIENT AUTOFILL IS OFF SINCE NOT SELECTED AS PART OF WEBFORM ORDER", get_defined_vars());
        return [0, RX_MESSAGE['ACTION PATIENT OFF AUTOFILL']];
    }

    //Patient set their refill date_manual earlier than they should have. TODO ensure webform validation doesn't allow this
    if (@$item['item_date_added'] AND $item['refill_date_manual'] AND $days_early_default > DAYS_EARLY*24*60*60 AND ! $added_manually) {
        $created = "Created:".date('Y-m-d H:i:s');

        $salesforce = [
          "subject"   => "Investigate Early Refill",
          "body"      => "Confirm if/why needs $item[drug_name] in Order #".@$item['invoice_number']." even though it's over ".DAYS_EARLY." days before it's due. Add drug to order or contact patient to explain why we are not filling. $created",
          "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
          "assign_to" => ".Testing",
          "due_date"  => date('Y-m-d')
        ];

        $event_title = @$item['invoice_number']." $salesforce[subject]: $salesforce[contact] $created";

        create_event($event_title, [$salesforce]);
        return [0, RX_MESSAGE['NO ACTION NOT DUE']];
    }

    if ($days_early_next > DAYS_EARLY*24*60*60 and $days_since < DAYS_EARLY*24*60*60 and ! $added_manually) {
        GPLog::info("DON'T REFILL IF FILLED WITHIN LAST ".DAYS_EARLY." DAYS UNLESS ADDED MANUALLY", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION RECENT FILL']];
    }

    if ($days_early_next > DAYS_EARLY*24*60*60 and ! $added_manually) {
        GPLog::info("DON'T REFILL IF NOT DUE IN OVER ".DAYS_EARLY." DAYS UNLESS ADDED MANUALLY", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION NOT DUE']];
    }

    if (! $item['refill_date_first'] and $item['last_inventory'] < 2000 and ($item['sig_qty_per_day_default'] > 2.5*($item['qty_repack'] ?: 135)) and ! $added_manually) {
        GPLog::info("SIG SEEMS TO HAVE EXCESSIVE QTY", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION CHECK SIG']];
    }

    //If SureScript comes in AND only *rx* is off autofill, we assume patients wants it.
    //This is different when *patient* is off autofill, then we assume they don't want it unless added_manually
    if (! $item['rx_autofill'] and ! @$item['item_date_added']) {
        GPLog::info("DON'T FILL IF RX_AUTOFILL IS OFF AND NOT IN ORDER", get_defined_vars());
        return [0, RX_MESSAGE['ACTION RX OFF AUTOFILL']];
    }

    if ($is_duplicate_gsn and ! @$item['item_date_added']) {
        GPLog::info("NOT ADDING DRUG BECAUSE DUPLICATE GSN DETECTED", get_defined_vars());
        return [0, RX_MESSAGE['NO ACTION DUPLICATE GSN']];
    }

    if (! $item['rx_autofill'] and @$item['item_date_added']) {

    //39652 don't refill surescripts early if rx is off autofill.  This means refill_date_next is null but refill_date_default may have a value
        if ($days_early_default > DAYS_EARLY*24*60*60 and ! $added_manually) {
            return [0, RX_MESSAGE['ACTION RX OFF AUTOFILL']];
        }

        GPLog::info("OVERRIDE RX AUTOFILL OFF", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION RX REQUESTED']];
    }

    if (! $item['rx_autofill'] and @$item['item_date_added']) {
        GPLog::info("OVERRIDE RX AUTOFILL OFF", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION RX REQUESTED']];
    }

    if ($is_refill and $not_offered) {
        GPLog::info("REFILLS SHOULD NOT HAVE A NOT OFFERED STATUS", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION NEW GSN']];
    }

    if ($is_syncable and ! $is_duplicate_gsn and sync_to_order_new_rx($item, $patient_or_order)) {
        GPLog::info('NO ACTION NEW RX SYNCED TO ORDER', get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION NEW RX SYNCED TO ORDER']];
    }

    //TODO and check if added by this program otherwise false positives
    if ($is_syncable and ! $is_duplicate_gsn and sync_to_order_past_due($item, $patient_or_order)) {
        GPLog::info("WAS PAST DUE SO WAS SYNCED TO ORDER", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION PAST DUE AND SYNC TO ORDER']];
    }

    //TODO CHECK IF THIS IS A GUARDIAN ERROR OR WHETHER WE ARE IMPORTING WRONG.  SEEMS THAT IF REFILL_DATE_FIRST IS SET, THEN REFILL_DATE_DEFAULT should be set
    if ($is_syncable and ! $is_duplicate_gsn and sync_to_order_no_next($item, $patient_or_order)) {
        GPLog::info("WAS MISSING REFILL_DATE_NEXT SO WAS SYNCED TO ORDER", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION NO NEXT AND SYNC TO ORDER']];
    }

    //TODO and check if added by this program otherwise false positives
    if ($is_syncable and ! $is_duplicate_gsn and sync_to_order_due_soon($item, $patient_or_order)) {
        GPLog::info("WAS DUE SOON SO WAS SYNCED TO ORDER", get_defined_vars());
        return [$days_default, RX_MESSAGE['NO ACTION DUE SOON AND SYNC TO ORDER']];
    }

    if ($stock_level == STOCK_LEVEL['ONE TIME']) {
        return [0, RX_MESSAGE['NO ACTION FILL ONE TIME']]; //Replace 0 with $days_default once we figure out ONE-TIME dispensing workflow
    }

    if ($stock_level == STOCK_LEVEL['OUT OF STOCK']) {
        if ($item['last_inventory'] > 750) {
            GPLog::notice("helper_days_and_message: 'out of stock' but inventory > 750", get_defined_vars());
        } elseif ($is_refill and $days_default < DAYS_MIN) {
            $created = "Created:".date('Y-m-d H:i:s');

            $salesforce = [
                "subject"   => "Refill for $item[drug_name] seems to be out-of-stock",
                "body"      => "Refill for $item[drug_generic] $item[drug_gsns] ($item[drug_name]) in Order #$item[invoice_number] seems to be out-of-stock.  Is a substitution or purchase necessary? Details - days_left_in_stock:$days_left_in_stock, last_inventory:$item[last_inventory], sig:$item[sig_actual], $created",
                "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
                "assign_to" => ".Testing",
                "due_date"  => date('Y-m-d')
            ];

            $event_title = "$item[invoice_number] Refill Out Of Stock: $salesforce[contact] $created";

            if (stripos($item['first_name'], 'TEST') === false and stripos($item['last_name'], 'TEST') === false) {
                create_event($event_title, [$salesforce]);
            }
        } elseif ($is_refill) {
            GPLog::notice("WARN USERS IF REFILL RX IS LOW QTY", get_defined_vars());
        } else {
            GPLog::notice("WARN USERS IF NEW RX IS LOW QTY", get_defined_vars());
        }

        return [$days_default, RX_MESSAGE['NO ACTION FILL OUT OF STOCK']];
    }

    if ($days_left_in_refills and $days_left_in_refills <= DAYS_MAX) {
        GPLog::notice("$days_left_in_refills < ".DAYS_MAX." OF DAYS LEFT IN REFILLS", get_defined_vars());
        return [$days_left_in_refills, RX_MESSAGE['ACTION LAST REFILL']];
    }

    //Since last refill check already ran, this means we have more days left in refill that we have in the expiration
    //to maximize the amount dispensed we dispense until 10 days before the expiration and then as much as we can for the last refill
    if ($days_left_in_expiration and $days_left_in_expiration < DAYS_MIN) {
        $days_left_of_qty = $item['qty_left']/$item['sig_qty_per_day'];
        $days_left_of_qty_capped = min(DAYS_MAX, $days_left_of_qty);

        GPLog::notice("RX IS ABOUT TO EXPIRE SO FILL IT FOR EVERYTHING LEFT", get_defined_vars());
        return [$days_left_of_qty_capped, RX_MESSAGE['ACTION EXPIRING']];
    }

    if ($days_left_in_expiration and $days_left_in_expiration < DAYS_STD) {
        $days_left_in_exp_rounded = roundDaysUnit($days_left_in_expiration);
        $days_left_in_exp_rounded_buffered = $days_left_in_exp_rounded-10;

        GPLog::notice("RX WILL EXPIRE SOON SO FILL IT UNTIL RIGHT BEFORE EXPIRATION DATE", get_defined_vars());
        return [$days_left_in_exp_rounded_buffered, RX_MESSAGE['ACTION EXPIRING']];
    }

    if ($stock_level == STOCK_LEVEL['REFILL ONLY']) {
        return [$days_default, RX_MESSAGE['NO ACTION FILL REFILL ONLY']];
    }

    if ($stock_level == STOCK_LEVEL['LOW SUPPLY']) {
        return [$days_default, RX_MESSAGE['NO ACTION FILL LOW SUPPLY']];
    }

    if ($stock_level == STOCK_LEVEL['HIGH SUPPLY']) {
        return [$days_default, RX_MESSAGE['NO ACTION FILL HIGH SUPPLY']];
    }

    GPLog::info("NO SPECIAL RX_MESSAGE USING DEFAULTS", get_defined_vars());
    return [$days_default, RX_MESSAGE['NO ACTION FILL UNKNOWN']];
    //TODO DON'T NO ACTION_PAST_DUE if ( ! drug.$InOrder AND drug.$DaysToRefill < 0)
  //TODO NO ACTION_LIVE_INVENTORY_ERROR if ( ! drug.$v2)
  //TODO ACTION_CHECK_BACK/NO ACTION_WILL_TRANSFER_CHECK_BACK
  //if ( ! drug.$IsRefill AND ! drug.$IsPended AND ~ ['Out of Stock', 'Refills Only'].indexOf(drug.$Stock))
  //if (drug.$NoTransfer)
}

/**
 * Store the invoice data into the gp_tables for point in time.
 * @param  array    $item  The details fo the order
 * @param  Mysql_Wc $mysql The database connection
 * @return void
 */
function set_item_invoice_data($item, $mysql)
{
    if (! $item['days_dispensed_actual']) {
        log_error("set_item_invoice_data has no actual days", get_defined_vars());
        return $item;
    }

    $item['refills_dispensed_actual'] = $item['refills_total'];
    $item['item_message_keys'] = $item['rx_message_keys'];
    $item['item_message_text'] = $item['rx_message_text'];

    $price_dispensed_actual = (@$item['price_dispensed_actual']) ?: 'NULL';
    $refills_total          = $item['refills_total'];
    $rx_message_keys        = escape_db_values($item['rx_message_keys']);
    $rx_message_text        = escape_db_values($item['rx_message_text']);

    $sql = "
    UPDATE
      gp_order_items
    SET
      -- Other Fields Should Already Be Set Above (And May have Been Sent to Patient) so don't change
      price_dispensed_actual   = {$price_dispensed_actual},
      refills_dispensed_actual = {$refills_total},
      item_message_keys        = '{$rx_message_keys}',
      item_message_text        = '{$rx_message_text}'
    WHERE
      invoice_number = {$item['invoice_number']} AND
      rx_number = {$item['rx_number']}
  ";

    $mysql->run($sql);

    return $item;
}

function set_days_and_message($item, $days, $message, $mysql) {

  if (is_null($days) OR is_null($message)) {
    GPLog::critical("set_days_and_message: days/message should not be NULL", compact('item', 'days', 'message'));
    return $item;
  }

    $new_rx_message_key  = array_search($message, RX_MESSAGE);
    $new_rx_message_text = message_text($message, $item);

  if ( ! $new_rx_message_key) {
    GPLog::critical("set_days_and_message: could not get rx_message_key ", compact('item', 'days', 'message', 'new_rx_message_key', 'new_rx_message_text'));
    return $item;
  }

    $item['rx_message_key']  = $new_rx_message_key;
    $item['rx_message_text'] = $new_rx_message_text.($days ? '' : ' **'); //If not filling reference to backup pharmacy footnote on Invoices

    $rx_numbers = str_replace(",", "','", substr($item['rx_numbers'], 1, -1));

    $rx_single_sql = "
    UPDATE
      gp_rxs_single
    SET
      rx_message_key  = '$item[rx_message_key]',
      rx_message_text = '".escape_db_values($item['rx_message_text'])."'
    WHERE
      rx_number IN ('$rx_numbers')
  ";

    $rx_grouped_sql = "
    UPDATE
      gp_rxs_grouped
    SET
      rx_message_keys = '$item[rx_message_key]' -- Don't need GROUP_CONCAT() since last query to gp_rxs_single made all they keys the same
    WHERE
      best_rx_number IN ('$rx_numbers')
  ";

    $mysql->run($rx_single_sql);
    $mysql->run($rx_grouped_sql);

    //We only continue to update gp_order_items IF this is an order_item and not just an rx on the patient's profile
    //This gets called by rxs_single_created1 and rxs_single_created2 where this is not true
    if (! @$item['item_date_added'] or $days === $item['days_dispensed_default']) {
        GPLog::notice("set_days_and_message: for rx or item with no change in days, skipping saving of order_item fields", compact('item', 'days', 'message', 'new_rx_message_key', 'new_rx_message_text', 'rx_single_sql', 'rx_grouped_sql'));
        return $item;
    }

    if (! $item['rx_number'] or ! $item['invoice_number']) {
        log_error("set_days_and_message: without a rx_number AND invoice_number. rx on patient profile OR maybe order_item before order was imported OR (likely) maybe order was deleted in past 10mins and order items have not yet been deleted?", compact('item', 'days', 'message', 'new_rx_message_key', 'new_rx_message_text', 'rx_single_sql', 'rx_grouped_sql'));
        return $item;
    }

    $price = $item['price_per_month'] ?: 0; //Might be null

    $item['days_dispensed_default']    = $days;
    $item['qty_dispensed_default']     = $days*$item['sig_qty_per_day_default'];
    $item['price_dispensed_default']   = ceil($days*$price/30);

    $item['stock_level_initial']       = $item['stock_level'];
    $item['rx_message_keys_initial']   = $item['rx_message_key'];  //Don't need GROUP_CONCAT() since last query to gp_rxs_single made all they keys the same

    $item['zscore_initial']            = $item['zscore'];
    $item['patient_autofill_initial']  = $item['patient_autofill'];
    $item['rx_autofill_initial']       = $item['rx_autofill'];
    $item['rx_numbers_initial']        = $item['rx_numbers'];

    $item['refills_dispensed_default'] = refills_dispensed_default($item);  //We want invoice to show refills after they are dispensed assuming we dispense items currently in order

    if ($item['days_dispensed_actual']) {
        GPLog::notice("set_days_and_message: but it has actual days. Why is this?", compact('item', 'days', 'message', 'new_rx_message_key', 'new_rx_message_text', 'rx_single_sql', 'rx_grouped_sql'));
    }

    $order_item_sql = "
    UPDATE
      gp_order_items

    SET
      days_dispensed_default    = $item[days_dispensed_default],
      qty_dispensed_default     = $item[qty_dispensed_default],
      price_dispensed_default   = $item[price_dispensed_default],

      stock_level_initial       = '$item[stock_level_initial]',
      rx_message_keys_initial   = '$item[rx_message_keys_initial]',

      zscore_initial            = ".(is_null($item['zscore']) ? 'NULL' : $item['zscore']).",
      patient_autofill_initial  = ".(is_null($item['patient_autofill']) ? 'NULL' : $item['patient_autofill']).",
      rx_autofill_initial       = '$item[rx_autofill]',
      rx_numbers_initial        = '$item[rx_numbers]',

      refills_dispensed_default = ".(is_null($item['refills_dispensed_default']) ? 'NULL' : $item['refills_dispensed_default']).",
      refill_date_manual        = ".(is_null($item['refill_date_manual']) ?  'NULL' : "'$item[refill_date_manual]'").",
      refill_date_default       = ".(is_null($item['refill_date_default']) ? 'NULL' : "'$item[refill_date_default]'").",
      refill_date_last          = ".(is_null($item['refill_date_last']) ? 'NULL' : "'$item[refill_date_last]'")."

    WHERE
      invoice_number = $item[invoice_number] AND
      rx_number = $item[rx_number]
  ";

    if (is_null($item['rx_message_key']) or is_null($item['refills_dispensed_default'])) {
        GPLog::warning('set_days_and_message: is rx_message_keys_initial being set correctly? rx_message_key or refills_dispensed_default IS NULL', ['item' => $item, 'sql' => $order_item_sql]);
    }

    $mysql->run($order_item_sql);

    GPLog::notice("set_days_and_message: saved both rx and order_item fields", compact('item', 'order_item_sql', 'rx_single_sql', 'rx_grouped_sql'));

    return $item;
}

/**
 * Refactored into GpOrderItem as `calculateRefillsDispensedDefault`
 * This is an oddly used function, need to reconsider its use
 */
function refills_dispensed_default($item)
{
    if ($item['qty_total'] <= 0) { //Not sure if decimal 0.00 evaluates to falsey in PHP
        return 0;
    }

    if ($item['refill_date_first']) { //This is initially called before days_dispensed_default is set, so assume a refill with null days is going to be filled (so subtract 1 from current refills)
        return max(0, $item['refills_total'] - ($item['days_dispensed_default'] === 0 ? 0 : 1));
    }

    //6028507 if Cindy hasn't adjusted the days/qty yet we need to calculate it ourselves
    if (! is_null($item['qty_dispensed_default'])) {
        return $item['refills_total'] * (1 - $item['qty_dispensed_default']/$item['qty_total']);
    }

    //No much info to go on.  We could throw an error or just go based on whether the drug is in the order or not
    GPLog::warning("CANNOT ASSESS refills_dispensed_default AT THIS POINT", $item);
    return $item['refills_total'] - ($item['item_date_added'] ? 1 : 0);
}

//TODO OR IT'S AN OTC
function is_no_transfer($item)
{
    return is_high_price($item) or patient_no_transfer($item);
}

function is_high_price($item) {
    return $item['price_per_month'] >= 20;
}

function patient_no_transfer($item) {
    return $item['pharmacy_phone'] == "8889875187";
}

function is_syncable($item)
{
    return @$item['is_order'] and ! @$item['item_date_added'] and ! @$item['order_date_dispensed'];
}

/**
 * Was the item manually added via Webform or CarePoint
 *   Returns true if ADDED_MANUALLY valuse are in the item_added_by array
 *     OR
 *   The item has a refill_date_manual and triggered the pharmacy app (or the autofill stored procedure) to add the item at a later date.
 *   We consider this manuall added because the patient or staff "manually" entered this date into the patient portal, but the
 *   addition happened to be "async" - it was added later rather than at that exact moment.  But since it was a direct action per a person,
 *   just like ADDED_MANUALLY, we want to honor that person's intention if possible.
 *
 *  NOTE: This function is not very accurate, just a heuristic right now.  We need more detailed user information in Guardian to know exactly WHO added something, not default Guardian Users (e.g HL7)
 * @param  array  $item  The item to check
 * @return boolean
 *
 */
function is_added_manually($item)
{
    return in_array(@$item['item_added_by'], ADDED_MANUALLY) or (@$item['item_date_added'] and $item['refill_date_manual'] and is_auto_refill($item));
}
/**
 * Did the item come from a webform transfer, surefill or refill
 * @param  array  $item  The item to check
 * @return boolean
 */
function is_webform($item)
{
    return is_webform_transfer($item) or is_webform_erx($item) or is_webform_refill($item);
}

function is_webform_transfer($item)
{
    return in_array(@$item['order_source'], ['Webform Transfer', 'Transfer /w Note']);
}

function is_webform_erx($item)
{
    return in_array(@$item['order_source'], ['Webform eRx', 'eRx /w Note']);
}

function is_webform_refill($item)
{
    return in_array(@$item['order_source'], ['Webform Refill', 'Refill w/ Note']);
}

function is_auto_refill($item)
{
    return in_array(@$item['order_source'], ['Auto Refill v2', 'O Refills']);
}

function is_order($patient_or_order)
{
    return @$patient_or_order[0]['is_order']; //invoice_number is present on singular order-items
}

function is_patient($patient_or_order)
{
    return @$patient_or_order[0]['is_patient']; //invoice_number is present on singular order-items
}

function is_item($patient_or_order)
{
    return @$patient_or_order[0]['is_item']; //invoice_number is present on singular order-items
}

function is_not_offered($item)
{
    $stock_level = @$item['stock_level_initial'] ?: $item['stock_level'];

    if ( ! $item) {
        log_error("helper_days_and_message: $item[drug_name], is_not_offered:true, ERROR no item", ['item' => $item, 'stock_level' => $stock_level]);
        return true;
    }

    if (is_null($stock_level) AND $item['rx_gsn'] > 0) {
        GPLog::notice("helper_days_and_message: $item[drug_name], is_not_offered:true, stock level null", ['item' => $item, 'stock_level' => $stock_level]);
        return true;
    }

    if ($stock_level == STOCK_LEVEL['NOT OFFERED']) {
        GPLog::notice("helper_days_and_message: $item[drug_name], is_not_offered:true, stock level $stock_level", ['item' => $item, 'stock_level' => $stock_level]);
        return true;
    }

    if ($stock_level == STOCK_LEVEL['ORDER DRUG']) {
        GPLog::notice("helper_days_and_message: $item[drug_name], is_not_offered:true, stock level $stock_level", ['item' => $item, 'stock_level' => $stock_level]);
        return true;
    }

    GPLog::notice("helper_days_and_message: $item[drug_name], is_not_offered:false, stock level $stock_level", ['item' => $item, 'stock_level' => $stock_level]);
    return false;
}

function is_refill_only($item)
{
    $stock_level = @$item['stock_level_initial'] ?: $item['stock_level'];
    return in_array(
        $stock_level,
        [
            STOCK_LEVEL['OUT OF STOCK'],
            STOCK_LEVEL['REFILL ONLY']
        ]
    );
}

function is_one_time($item)
{
    $stock_level = @$item['stock_level_initial'] ?: $item['stock_level'];
    return in_array(
        $stock_level,
        [
            STOCK_LEVEL['ONE TIME']
        ]
    );
}

function message_text($message, $item)
{
    return str_replace(array_keys($item), array_values($item), $message[$item['language']]);
}

//Although you can dispense up until an Rx expires (so refill_date_next is well past rx_date_expired) we want to use
//as much of a Rx as possible, so if it will expire before  the standard dispense date than dispense everything left
function days_left_in_expiration($item)
{

  //Usually don't like using time() because it can change, but in this case once it is marked as expired it will always be expired so there is no variability
    $comparison_date = $item['refill_date_next'] ? strtotime($item['refill_date_next']) : time();

    $days_left_in_expiration = (strtotime($item['rx_date_expired']) - $comparison_date)/60/60/24;

    //#29005 was expired but never dispensed, so check "refill_date_first" so we asking doctors for new rxs that we never dispensed
    if ($item['refill_date_first']) {
        return $days_left_in_expiration;
    }
}

function days_left_in_refills($item)
{
    if (! (float) $item['sig_qty_per_day'] or $item['sig_qty_per_day'] > 10) {
        return;
    }

    //Uncomment the line below if we are okay dispensign 2 bottles/rxs.  For now, we will just fill the most we can do with one Rx.
    //if ($item['refills_total'] != $item['refills_left']) return; //Just because we are out of refills on this script doesn't mean there isn't another script with refills

    $days_left_in_refills = $item['qty_left']/$item['sig_qty_per_day'];

    //Fill up to 30 days more to finish up an Rx if almost finished.
    //E.g., If 30 day script with 3 refills (4 fills total, 120 days total) then we want to 1x 120 and not 1x 90 + 1x30
    if ($days_left_in_refills <= DAYS_MAX) {
        return roundDaysUnit($days_left_in_refills);
    }
}

function days_left_in_stock($item)
{
    if (! (float) $item['sig_qty_per_day'] or $item['sig_qty_per_day'] > 10) {
        return;
    }

    $days_left_in_stock = round($item['last_inventory']/$item['sig_qty_per_day']);
    $stock_level = @$item['stock_level_initial'] ?: $item['stock_level'];

    if ($days_left_in_stock >= DAYS_STD or $item['last_inventory'] >= 3*$item['qty_repack']) {
        return;
    }

    if ($stock_level == STOCK_LEVEL['HIGH SUPPLY'] and $item['sig_qty_per_day_default'] != round(1/30, 3)) {
        GPLog::warning("LOW STOCK ITEM IS MARKED HIGH SUPPLY $item[drug_generic] days_left_in_stock:$days_left_in_stock last_inventory:$item[last_inventory]", get_defined_vars());
    }

    if ($item['refill_date_first'] and $stock_level == STOCK_LEVEL['OUT OF STOCK']) {
        GPLog::warning("REFILL ITEM IS MARKED OUT OF STOCK $item[drug_generic] days_left_in_stock:$days_left_in_stock last_inventory:$item[last_inventory]", get_defined_vars());
    }

    return $item['sig_qty_per_day_default'] == round(1/30, 3) ? 60.6 : DAYS_MIN; //Dispensed 2 inhalers per time, since 1/30 is rounded to 3 decimals (.033), 2 month/.033 = 60.6 qty
}

function roundDaysUnit($days)
{
    //+.1 because we had 18qty with .201 sig_qty_per_day_default which gave floor(5.97) -> 75 days instead of 90
  //Bactrim with 6 qty and 2.0 sig_qty_per_day_default which gave floor(6/2/15) -> 0 days
  return $days < DAYS_UNIT ? $days : floor($days/DAYS_UNIT+.1)*DAYS_UNIT; //+.1 because we had 18qty with .201 sig_qty_per_day_default which gave floor(5.97) -> 75 days instead of 90
}

//Days is basically the MIN(target_date ?: std_day, qty_left as days, inventory_left as days).
//NOTE: We adjust bump up the days by upto 30 in order to finish up an Rx (we don't want partial fills left)
//NOTE: We base this on the best_rx_number and NOT on the rx currently in the order
function days_default($days_left_in_refills, $days_left_in_stock, $days_default, $item)
{

  //Cannot have NULL inside of MIN()
    $days = min(
        $days_left_in_refills ?: $days_default,
        $days_left_in_stock ?: $days_default
    );

    $remainder = $days % DAYS_UNIT;

    if (! $days) {
        GPLog::warning("DEFAULT DAYS IS 0! days:$days, days_default:$days_default, days_left_in_stock:$days_left_in_stock, days_left_in_refills:$days_left_in_refills", ['item' => $item]);
    } elseif ($remainder) {
        GPLog::notice("DEFAULT DAYS IS NOT A MULTIPLE OF ".DAYS_UNIT."! days:$days, days_default:$days_default, days_left_in_stock:$days_left_in_stock, days_left_in_refills:$days_left_in_refills", ['item' => $item]);
    } else {
        GPLog::info("days:$days, days_left_in_stock:$days_left_in_stock, days_left_in_refills:$days_left_in_refills", ['item' => $item]);
    }

    return $days;
}

/*
    TODO
    These last two functions are the only two that depend on the ENTIRE order.  This is because
    they group by drug (GSN) and ignore sig_qty_per_day differences.  Can we handle this with SQL
    groups in rxs-grouped table or someplace else?  Or maybe the load the full order themselves?
    If so, we can get rid of need to pass entire order into the function rather than just the one item
*/

//rxs_grouped includes drug name AND sig_qty_per_day_default.  If someone starts on Lipitor 20mg 1 time per day
//and then moves to Lipitor 20mg 2 times per day, we still want to honor this Rx as a refill rather than
//tell them it is out of stock just because the sig changed
function is_refill($item1, $patient_or_order)
{
    foreach ($patient_or_order as $item2) {
        if (
            $item1['drug_generic'] == $item2['drug_generic']
            && @$item2['refill_date_first']
        ) {
            return true;
        }
    }

    return false;
}

//Don't sync if an order with these instructions already exists in order
function is_duplicate_gsn($item1, $patient_or_order)
{
    //Don't sync if an order with these instructions already exists in order
    foreach ($patient_or_order as $item2) {
        if ($item1 !== $item2 and @$item2['item_date_added'] and $item1['drug_gsns'] == $item2['drug_gsns']) {
            GPLog::notice("helper_days_and_message syncing item: matching drug_gsns so did not SYNC TO ORDER' $item1[invoice_number] $item1[drug_name] $item1[rx_message_key] refills last:$item1[refill_date_last] next:$item1[refill_date_next] total:$item1[refills_total] left:$item1[refills_left]", ['item1' => $item1, 'item2' => $item2]);
            return true;
        }
    }

    return false;
}
