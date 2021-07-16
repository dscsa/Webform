<?php

use GoodPill\Logging\{
    GPLog,
    AuditLog,
    CLiLog
};

use \GoodPill\Models\GpOrder;
use \GoodPill\Models\GpOrderItem;
use \GoodPill\Models\GpPendGroup;
use \GoodPill\Models\v2\PickListDrug;
use \GoodPill\Events\OrderItem\PendingFailed;

require_once 'exports/export_cp_orders.php';

function export_v2_unpend_order($order, $mysql, $reason)
{
    GPLog::notice(
        "export_v2_unpend_order $reason " . @$order[0]['invoice_number'],
        ['order' => $order]
    );

    if (! @$order[0]['drug_name']) {
        GPLog::error(
            "export_v2_unpend_order: ABORTED! Order " . @$order[0]['invoice_number']
            . " doesn't seem to have any items. $reason",
            ['order' => $order]
        );
        return $order;
    }

    // Unpend the entire order
    v2_unpend_order_by_invoice($order[0]['invoice_number']);

    // save a blank picklist for each item
    foreach ($order as $i => $item) {
        if ($item['rx_number']) {
            GPLog::debug('Saving Pick List', ['item' => $item, 'list' => 0]);
            $order[$i] = save_pick_list($item, 0, $mysql);
        } else {
            GPLog::warning(
                'Trying to pend an item that does not exist',
                ['item' => $item, 'list' => 0]
            );
        }
    }

    return $order;
}
/**
 * Create a picklist for a specific order item and pend that picklist in v2.  This function will
 *      calculate the neccessary quantity then select the inventory to pend for that quantity.
 *
 * @param  array       $item   A Legacy Item array that represents a single order item.
 * @param  null|string $reason Optional. descriptoion of the reason we are pending the order.
 * @param  boolean     $repend Optional.  Passing true will force the item to be unpended and
 *      then repended.
 * @return array The original item including any modifications that may have been made.
 */
function v2_pend_item(array $item, ?string $reason = null, bool $repend = false) : array
{
    $mysql = new Mysql_Wc();

    // Make sure there is an order before we Pend.  If there isn't one skip the
    // pend and put in an alert.
    $gp_order = GpOrder::where('invoice_number', $item['invoice_number']);
    if (is_null($gp_order)) {
        AuditLog::log(
            sprintf(
                "ABORTED PEND Attempted to pend %s for Rx#%s on Invoice #%s. This
                order doesn't exist in the Database",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number']
            ),
            $item
        );

        GPLog::error(
            sprintf(
                "ABORTED PEND Attempted to pend %s for Rx#%s on Invoice #%s. This
                order doesn't exist in the Database",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number']
            ),
            [ 'item' => $item ]
        );

        return $item;
    }

    // Abort
    // if we don't know how many days to dispense
    // or the item has already been marked dispensed
    // or we don't have any left in inventory
    // or the item has already been pended and the request is not a repend
    if (!$item['days_dispensed_default']
      or $item['rx_dispensed_id']
      or is_null($item['last_inventory'])
      or (@$item['count_pended_total'] > 0 && !$repend)) {
        AuditLog::log(
            sprintf(
                "ABORTED PEND Attempted to pend %s for Rx#%s on Invoice #%s for
                the following reasons: Missing days dispensed default - %s,
                The Rx hasn't been assigned - %s, There isn't inventory - %s,
                The Item is already Pended - %s",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number'],
                (!$item['days_dispensed_default']) ? 'Y':'N',
                ($item['rx_dispensed_id']) ? 'Y':'N',
                (is_null($item['last_inventory'])) ? 'Y':'N',
                (@$item['count_pended_total'] > 0) ? 'Y':'N'
            ),
            $item
        );

        GPLog::error(
            sprintf(
                "v2_pend_item: ABORTED! %s %s %s %s days_dispensed_default:%s
                rx_dispensed_id:%s last_inventory:%s count_pended_total:%s",
                @$item['invoice_number'],
                @$item['drug_name'],
                $reason,
                @$item['rx_number'],
                @$item['days_dispensed_default'],
                @$item['rx_dispensed_id'],
                @$item['last_inventory'],
                @$item['count_pended_total']
            ),
            [ 'item' => $item ]
        );
        return $item;
    }

    // Make the picklist
    $list = make_pick_list($item);

    if ($list) {
        AuditLog::log(
            sprintf(
                "Item %s for Rx#%s on Invoice #%s Pended because",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number'],
                $reason
            ),
            $item
        );
        GPLog::debug(
            sprintf(
                "v2_pend_item: make_pick_list SUCCESS %s %s %s %s",
                $item['invoice_number'],
                $item['drug_name'],
                $reason,
                $item['rx_number']
            ),
            [
                'success' => true,
                'item' => $item,
                'list' => $list
            ]
        );
    } else {
        AuditLog::log(
            sprintf(
                "Item %s for Rx#%s on Invoice #%s FAILED to pend",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number']
            ),
            $item
        );
        GPLog::error(
            sprintf(
                "v2_pend_item: make_pick_list ERROR %s %s %s %s",
                $item['invoice_number'],
                $item['drug_name'],
                $reason,
                $item['rx_number']
            ),
            [
              'success' => false,
              'item' => $item,
              'list' => $list
            ]
        );
    }

    print_pick_list($item, $list);
    pend_pick_list($item, $list);
    GPLog::debug('Saving Pick List', ['item' => $item, 'list' => $list ?: 0]);
    $item = save_pick_list($item, $list ?: 0, $mysql);
    return $item;
}

/**
 * Clear a picklist for a specific order item
 * @param  array  $item   A legacy data array that represents a single order item.
 * @param  string $reason Optional.  The reason we are unpending the item.
 * @return array The original item and any new modifications made as a result of the unpend
 */
function v2_unpend_item(array $item, string $reason = '') : array
{
    $mysql = new Mysql_Wc();

    GPLog::notice(
        sprintf(
            "v2_unpend_item: Invoice: %s Drug: %s Reason: %s Rx# %s",
            @$item['invoice_number'],
            @$item['drug_name'],
            $reason,
            @$item['rx_number']
        ),
        [ 'item' => $item ]
    );

    if (! @$item['rx_number']) { //Empty order will just cause a mysql error in save_pick_list
        return $item;
    }

    AuditLog::log(
        sprintf(
            "Item %s for Rx#%s on Invoice #%s UN-Pended because %s",
            @$item['drug_name'],
            @$item['rx_number'],
            @$item['invoice_number'],
            $reason
        ),
        $item
    );

    if (!@$item['invoice_number']
        or !@$item['order_date_added']
        or !@$item['patient_date_added']) {
        AuditLog::log(
            sprintf(
                "Item %s for Rx#%s on Invoice #%s UN-Pended is missing:
                invoice number - %s, order date - %s, patient date - %s",
                @$item['drug_name'],
                @$item['rx_number'],
                @$item['invoice_number'],
                (!@$item['invoice_number']) ? 'Y':'N',
                (!@$item['order_date_added']) ? 'Y':'N',
                (!@$item['patient_date_added']) ? 'Y':'N'
            ),
            $item
        );

        //  This is where the original PG alert was firing from
    }

    unpend_pick_list($item);
    GPLog::debug('Saving Pick List', ['item' => $item, 'list' => 0]);
    $item = save_pick_list($item, 0, $mysql);
    return $item;
}

function save_pick_list($item, $list, $mysql)
{
    // Check to see if the items has an invoice number and a rx_number
    // If those are missing, we should thorw an alert and return
    if (empty($item['rx_number'])) {
        GPLog::critical(
            'Trying to save a picklist but th item is missing the rx_number',
            [ 'item' => $item ]
        );
        return $item;
    }

    if ($list === 0) {
        $list = [
          'qty'           => 0,
          'qty_repacks'   => 0,
          'count'         => 0,
          'count_repacks' => 0
        ];
    }

    if (! $list) {
        return $item;
    } //List could not be made

    $item['qty_pended_total']     = $list['qty'];
    $item['qty_pended_repacks']   = $list['qty_repacks'];
    $item['count_pended_total']   = $list['count'];
    $item['count_pended_repacks'] = $list['count_repacks'];

    $sql = "UPDATE
              gp_order_items
            SET
              qty_pended_total = {$list['qty']},
              qty_pended_repacks = {$list['qty_repacks']},
              count_pended_total = {$list['count']},
              count_pended_repacks = {$list['count_repacks']}
            WHERE
              invoice_number = {$item['invoice_number']} AND
              rx_number = {$item['rx_number']}";

    GPLog::notice(
        "save_pick_list: $item[invoice_number] " . @$item['drug_name'] . " "
            . @$item['rx_number'],
        [ 'item' => $item, 'list' => $list, 'sql' => $sql ]
    );

    $mysql->run($sql);

    export_cp_set_expected_by($item);

    return $item;
}

function pick_list_name($item)
{
    return pick_list_prefix($item).pick_list_suffix($item);
}

function pick_list_prefix($item)
{
    return 'Pick List #'.$item['invoice_number'].': ';
}

function pick_list_suffix($item)
{
    return $item['drug_generic'];
}

function print_pick_list($item, $list)
{
    $pend_group_name = pend_group_name($item);

    if (! $list) {
        return;
    } //List could not be made

    $header = [
        [
            "Pick List: Order #$pend_group_name $item[drug_generic] ($item[drug_name])",
            '',
            '' ,
            '',
            '',
            ''
        ],
        [
            "Rx $item[rx_number]. $item[rx_message_key]. Item Added:$item[item_date_added]. Created ".date('Y-m-d H:i:s'),
            '',
            '' ,
            '',
            '',
            ''
        ],
        [
            $list['partial_fill']."Count:$list[count], Days:$item[days_dispensed_default], Qty:$item[qty_dispensed_default] ($list[qty]), Stock:$item[stock_level_initial], ",
            '',
            '',
            '',
            '',
            ''
        ],
        [
            '',
            '',
            '',
            '',
            '',
            ''
        ],
        [
            'id',
            'ndc',
            'form',
            'exp',
            'qty',
            'bin'
        ]
      ];

    $args = [
        'method'   => 'newSpreadsheet',
        'file'     => pick_list_name($item),
        'folder'   => PICK_LIST_FOLDER_NAME,
        'vals'     => array_merge($header, $list['list']), //merge arrays, make sure array is not associative or json will turn to object
        'widths'   => [1 => 243] //show the full id when it prints
    ];

    $result = gdoc_post(GD_HELPER_URL, $args);

    GPLog::notice(
        "print_pick_list: $item[invoice_number] ".@$item['drug_name']." ".@$item['rx_number'],
        [
            'item' => $item,
            'count list' => count($list['list']),
            'count pend' => count($list['pend'])
        ]
    ); //We don't need full shopping list cluttering logs
}


/**
 * Check to see if this specific item has already been pended
 * @param  array   $item           The data for an order item
 * @param  boolean $include_picked (Optional) Should we check for pended and picked
 * @return boolean       False if the item is not pended in v2
 */
function get_item_pended_group($item, $include_picked = false)
{
    $possible_pend_groups = [
        'expected'        => pend_group_name($item),
        'refill'          => pend_group_refill($item),
        'webform'         => pend_group_webform($item),
        'new_patient'     => pend_group_new_patient($item),
        'new_patient_old' => pend_group_new_patient_old($item),
        'manual'          => pend_group_manual($item)
    ];

    foreach ($possible_pend_groups as $type => $group) {
        $drug_generic = rawurlencode($item['drug_generic']);
        $pend_url = "/account/8889875187/pend/{$group}/{$drug_generic}";
        $results  = v2_fetch($pend_url, 'GET');
        if (!empty($results) &&
            @$results[0]['next'][0]['pended']) {
            if ($type != 'expected') {
                GPLog::debug(
                    'Drugs pended under unexpected pend group',
                    [
                        'expected' => $possible_pend_groups['expected'],
                        'found_as' => $group
                    ]
                );
            }

            return $group;
        }
    }

    return false;
}

/*
 * Pend Group Name functions
 */

/**
 * Get the pend group name for a Refill order
 * @param  array $item  The item data
 * @return string
 */
function pend_group_refill($item)
{
    $pick_time = strtotime($item['order_date_added'].' +2 days'); //Used to be +3 days
    $invoice   = "R{$item['invoice_number']}"; //N < R so new scripts will appear first on shopping list
    $pick_date = date('Y-m-d', $pick_time);
    return "$pick_date $invoice";
}

function pend_group_webform($item)
{
    $pick_time = strtotime($item['order_date_added'].' +0 days'); //Used to be +1 days
    $invoice   = "W{$item['invoice_number']}";
    $pick_date = date('Y-m-d', $pick_time);
    return "$pick_date $invoice";
}

function pend_group_new_patient($item)
{
    $pick_time = strtotime(@$item['patient_date_added'].' -8 days');
    $invoice   = "P{$item['invoice_number']}";
    $pick_date = date('Y-m-d', $pick_time);
    return "$pick_date $invoice";
}

//This can be deleted once 2021-01-12 P55855 is dispensed
function pend_group_new_patient_old($item)
{
    $pick_time = strtotime($item['patient_date_added'].' +0 days');
    $invoice   = "P{$item['invoice_number']}";
    $pick_date = date('Y-m-d', $pick_time);
    return "$pick_date $invoice";
}

function pend_group_manual($item)
{
    return $item['invoice_number'];
}

function pend_group_name($item)
{

    // See if there is already a pend group for this order
    $pend_group = GpPendGroup::where('invoice_number', $item['invoice_number'])->firstOrNew();

    if ($pend_group->exists) {
        return $pend_group->pend_group;
    }

    //TODO need a different flag here because "Auto Refill v2" can be overwritten by "Webform XXX"
    //We need a flag that won't change otherwise items can be pended under different pending groups
    //Probably need to have each "app" be a different "CP user" so that we can look at item_added_by
    if (is_auto_refill($item)) {
        $pend_group_name = pend_group_refill($item);
    } elseif (!isset($pend_group_name) && @$item['refills_used'] > 0) {
        $pend_group_name = pend_group_webform($item);
    } else {
        $pend_group_name = pend_group_new_patient($item);
    }

    $pend_group->invoice_number = $item['invoice_number'];
    $pend_group->pend_group = $pend_group_name;
    $pend_group->save();

    return $pend_group_name;
}

/**
 * Send a picklist to v2 so it is pended
 * @param  array  $item An item to pend
 * @param  array  $list A v2 compatible picklist
 * @return boolean      False if the list was already pended or was not there.
 */
function pend_pick_list($item, $list)
{
    if (!$list) {
        return false;
    } //List could not be made

    if ($pended_group = get_item_pended_group($item)) {
        // If we get a list of pendgroups, we should compare them to what we
        // are trying to pend and if the new pendgroup is different, unpend them
        // then repend them with the proper pend group
        AuditLog::log(
            sprintf(
                "ABORTED PEND! %s for %s appears to be already pended in pend group %s.  Please confirm.",
                @$item['drug_name'],
                @$item['invoice_number'],
                $pended_group
            ),
            $item
        );

        GPLog::notice(
            sprintf(
                "v2_pend_item: ABORTED! %s for %s appears to be already pended in pend group %s.  Please confirm.",
                @$item['drug_name'],
                @$item['invoice_number'],
                $pended_group
            ),
            [ 'item' => $item ]
        );

        CliLog::notice(
            sprintf(
                "ABORTED PEND! %s for %s appears to be already pended in pend group %s",
                @$item['drug_name'],
                @$item['invoice_number'],
                $pended_group
            )
        );

        return false;
    }

    // TODO PEND
    $pend_group_name = pend_group_name($item);
    $qty             = round($item['qty_dispensed_default']);

    CliLog::debug("pending item {$pend_group_name}:{$item['drug_generic']} - $qty");

    $pend_url = "/account/8889875187/pend/$pend_group_name?repackQty=$qty";

    $pend_attempts_total = 2;
    $pend_attempts = 0;

    do {
        $res = v2_fetch($pend_url, 'POST', $list['pend']);

        if (isset($res) && $list['pend'][0]['_rev'] != $res[0]['rev']) {
            GPLog::debug("pend_pick_list: SUCCESS!! {$item['invoice_number']} {$item['drug_name']} {$item['rx_number']}");
            // We successfully Pended a picklist
            $gpOrderItem = GpOrderItem::where('invoice_number', $item['invoice_number'])
                ->where('rx_number', $item['rx_number'])
                ->first();

            if ($gpOrderItem) {
                // Create a picklist Object fromthe list we created
                $pickList_object = new PickListDrug();
                $pickList_object->setPickList($list['pend']);
                $pickList_object->setIsPended(true);

                // Attache the pickelist to the order so we don't have to call v2
                $gpOrderItem->setPickList($pickList_object);

                // Update Carepoint with the NDC we picked.
                $gpOrderItem->doUpdateCpWithNdc();
            } else {
                GPLog::warning(
                    'Could not load the order item, so Carepoint NDC can not be updated',
                    ['item' => $item]
                );
            }

            return true;
        }

        $pend_attempts++;
    } while ($pend_attempts < $pend_attempts_total);
    //  Retried twice, pending issue so log a warning and send a salesforce message to followup on

    $invoice_number = $item['invoice_number'] ?? 'NO INVOICE';
    $drug_name = $item['drug_name'] ?? 'NO DRUG NAME';
    $rx_number = $item['rx_number'] ?? 'NO RX NUMBER';

    AuditLog::log(
        sprintf(
            "PEND Failed %s for %s failed to pend.   %s.  Please manually pend if needed.",
            $drug_name,
            $invoice_number,
            $pended_group
        ),
        $item
    );

    GPLog::warning($subject, [
        'res' => $res,
        'pend_url' => $pend_url,
        'pend_group_name' => $pend_group_name,
        'item' => $item,
        'list' => $list,
    ]);

    $gpOrderItem = GpOrderItem::where('invoice_number', $item['invoice_number'])
        ->where('rx_number', $item['rx_number'])
        ->first();

    $failed_pend_event = new PendingFailed($gpOrderItem);
    $failed_pend_event->publish();


    return false;
}

/**
 * Remove a specific picklist.  Picklist is the smallest object and this function
 * Actually makes the call to unpend the item from v2
 * @param  array $item        The item we are trying to remove
 * @param  array  $pendgroups (Optional) a specific list of pend groups to
 *      remove the item from
 * @return [type]             [description]
 */
function unpend_pick_list($item)
{

    // TODO PEND
    //
    // If we don't have specific pendgroups, then go get some
    $pend_group = get_item_pended_group($item);

    if (!$pend_group) {
        GPLog::warning(
            sprintf(
                "v2_unpend_item: Nothing Unpened.  Call could have been avoided! %s %s %s",
                @$item['invoice_number'],
                @$item['drug_name'],
                @$item['rx_number']
            ),
            ['item' => $item]
        );
        CliLog::notice(
            sprintf(
                "Nothing Unpened  %s %s %s",
                @$item['invoice_number'],
                @$item['drug_name'],
                @$item['rx_number']
            ),
            ['invoice_number' => @$item['invoice_number']]
        );
    } else {
        CliLog::info(
            sprintf(
                "unpending item %s in %s",
                $item['drug_generic'],
                $pend_group
            )
        );
        do { // Keep doing until we can't find a pended items
            $loop_count = (isset($loop_count) ? ++$loop_count : 1);
            $drug_generic = rawurlencode($item['drug_generic']);
            if ($results = v2_fetch("/account/8889875187/pend/{$pend_group}/{$drug_generic}", 'DELETE')) {
                CLiLog::info(
                    sprintf(
                        "succesfully unpended item %s in %s, unpend attempt #%s",
                        $item['drug_generic'],
                        $pend_group,
                        $loop_count
                    )
                );
                GPLog::info(
                    sprintf(
                        "succesfully unpended item %s in %s, unpend attempt #%s",
                        $item['drug_generic'],
                        $pend_group,
                        $loop_count
                    ),
                    ['invoice_number' => $item['invoice_number']]
                );
                break;
            }
        } while (($pend_group = get_item_pended_group($item)) && $loop_count <= 5);
    }

    //Delete gdoc pick list
    $args = [
        'method'   => 'removeFiles',
        'file'     => pick_list_prefix($item),
        'folder'   => PICK_LIST_FOLDER_NAME
    ];

    $result = gdoc_post(GD_HELPER_URL, $args);

    GPLog::notice("unpend_pick_list", get_defined_vars());
}

//Getting all inventory of a drug can be thousands of items.  Let's start with a low limit that we increase as needed
function make_pick_list($item, $limit = 500)
{
    if (! isset($item['stock_level_initial']) and $item['rx_gsn']) { //If missing GSN then stock level won't be set
        GPLog::error("ERROR make_pick_list: stock_level_initial is not set", get_defined_vars());
    }

    $safety   = 0.05; //Percent qty to overshop
    $min_qty  = $item['qty_dispensed_default'];

    // 2015-05-13 We want any surplus from packing fast movers to be usable for
    // ~6 weeks.  Otherwise a lot of prepacks expire on the shelf
    $long_exp = date('Y-m-01', strtotime("+".($item['days_dispensed_default']+6*7)." days"));

    $inventory     = get_v2_inventory($item, $limit);
    $unsorted_ndcs = group_by_ndc($inventory, $item);
    $sorted_ndcs   = sort_by_ndc($unsorted_ndcs, $long_exp);
    $list          = get_qty_needed($sorted_ndcs, $min_qty, $safety);

    GPLog::notice(
        "make_pick_list: $item[invoice_number] " .@ $item['drug_name']
            . " " . @$item['rx_number'],
        [
            'item' => $item,
            'unsorted_ndcs' => $unsorted_ndcs,
            'sorted_ndcs' => $sorted_ndcs,
            'long_exp' => $long_exp,
            'list' => $list
        ]
    );

    if ($list) {
        $list['partial_fill'] = '';
        return $list;
    }

    if (count($inventory) == $limit) {  //We didn't make the list but there are more drugs that we can scan
        GPLog::error(
            "Webform Pending Error: Not enough qty found for {$item['drug_generic']}."
                . " Increasing limit from {$limit} to " . ($limit*2),
            [ 'count_inventory' => count($inventory), 'item' => $item]
        );
        return make_pick_list($item, $limit*2);
    }

    if ($item['stock_level'] != "OUT OF STOCK") {
        GPLog::error(
            "Webform Pending Error: Not enough qty found for {$item['drug_generic']} "
                . "although it's not OUT OF STOCK.  Looking for {$min_qty} with "
                . "last_inventory of {$item['last_inventory']} (limit {$limit}) #1 of 3, "
                . "trying half fill and no safety",
            [ 'count_inventory' => count($inventory), 'item' => $item ]
        );
    }

    $list = get_qty_needed($sorted_ndcs, $min_qty*0.5, 0);

    if ($list) {
        $list['partial_fill'] = 'HALF FILL - COULD NOT FIND ENOUGH QUANTITY, ';
        return $list;
    }

    GPLog::error(
        "Webform Pending Error: Not enough qty found for {$item['drug_generic']}. "
            . "Looking for {$min_qty} with last_inventory of {$item['last_inventory']} "
            . " (limit {$limit}) #2 of 3, half fill with no safety failed",
        [
            'inventory'       => $inventory,
            'sorted_ndcs'     => $sorted_ndcs,
            'count_inventory' => count($sorted_ndcs),
            'item'            => $item
        ]
    );

    $thirty_day_qty = $min_qty/$item['days_dispensed_default']*30;
    $list = get_qty_needed($sorted_ndcs, $thirty_day_qty, 0);

    if ($list) {
        $list['partial_fill'] = '30 DAY FILL - COULD NOT FIND ENOUGH QUANTITY, ';
        return $list;
    }

    GPLog::error(
        "Webform Pending Error: Not enough qty found for {$item['drug_generic']}."
            . " Looking for {$min_qty} with last_inventory of {$item['last_inventory']} "
            . " (limit $limit) #3 of 3, 30 day with no safety failed",
        [
            'inventory' => $inventory,
            'sorted_ndcs' => $sorted_ndcs,
            'count_inventory' => count($sorted_ndcs),
            'item' => $item
        ]
    );

    //otherwise could create upto 3 SF tasks. rxs-single-updated, orders-cp-created, sync-to-date
    if (! is_null($item['count_pended_total'])) {
        return;
    }

    $created = "Created:".date('Y-m-d H:i:s');

    $salesforce = [
        "subject"   => "Order #$item[invoice_number] cannot pend enough $item[drug_name]",
        "body"      => "Determine if there is enough $item[drug_name] to pend for "
                       ."'{$item['sig_actual']}'. Tried & failed to pend a qty of ".$min_qty." $created",
        "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
        "assign_to" => ".Manually Add Drug To Order",
        "due_date"  => date('Y-m-d')
    ];

    $event_title = "$item[invoice_number] Pending Error: $salesforce[contact] $created";

    create_event($event_title, [$salesforce]);
}

function get_v2_inventory($item, $limit)
{
    $generic  = $item['drug_generic'];
    $min_days = $item['days_dispensed_default'];
    $stock    = $item['stock_level_initial'];

    // Used to use +14 days rather than -14 days as a buffer for dispensing and shipping.
    // But since lots of prepacks expiring I am going to let almost expired things be prepacked.
    // Update on 2020-12-03, -14 days is causing issues when we are behind on filling
    // (on 12/1/2020 a 90 day Rx was pended for exp 01/2021)
    $days_adjustment = 0; //-14 //+14
    $min_exp   = explode('-', date('Y-m', strtotime("+".($min_days+$days_adjustment)." days")));

    $start_key = rawurlencode('["8889875187","month","'.$min_exp[0].'","'.$min_exp[1].'","'.$generic.'"]');
    $end_key   = rawurlencode('["8889875187","month","'.$min_exp[0].'","'.$min_exp[1].'","'.$generic.'",{}]');

    $url  = "/transaction/_design/inventory-by-generic/_view/inventory-by-generic?reduce=false&include_docs=true&limit=$limit&startkey=$start_key&endkey=$end_key";

    try {
        $res = v2_fetch($url);
        GPLog::info("WebForm make_pick_list fetch success.", ['url' => $url, 'item' => $item, 'res' => $res]);
    } catch (Error $e) {
        GPLog::error("WebForm make_pick_list fetch failed.  Retrying $item[invoice_number]", ['url' => $url, 'item' => $item, 'res' => $res, 'error' => $e]);
        $res = v2_fetch($url);
    }

    return $res['rows'];
}

function group_by_ndc($rows, $item)
{
    //Organize by NDC since we don't want to mix them
    $ndcs = [];
    $caps = preg_match('/ cap(?!l)s?| cps?\\b| softgel| sfgl\\b/i', $item['drug_name']); //include cap, caps, capsule but not caplet which is more like a tablet
    $tabs = preg_match('/ tabs?| tbs?| capl\\b/i', $item['drug_name']);  //include caplet which is like a tablet

    foreach ($rows as $row) {
        if (
            isset($row['doc']['next'][0])
            && (
                 count($row['doc']['next'][0]) > 1
                 || (
                        count($row['doc']['next'][0]) == 1
                        && ! isset($row['doc']['next'][0]['picked'])
                )
            )
        ) {
            GPLog::error('Shopping list pulled inventory in which "next" is set!', $row, $item);
            if (! empty($row['doc']['next']['dispensed'])) {
                continue;
            }
        }

        //Ignore Cindy's makeshift dispensed queue
        if (in_array($row['doc']['bin'], ['M00', 'T00', 'W00', 'R00', 'F00', 'X00', 'Y00', 'Z00'])) {
            continue;
        }
        //Only select the correct form even though v2 gives us both
        if ($caps and stripos($row['doc']['drug']['form'], 'Tablet') !== false) {
            $msg = 'may only be available in capsule form';
            continue;
        }
        if ($tabs and stripos($row['doc']['drug']['form'], 'Capsule') !== false) {
            $msg = 'may only be available in tablet form';
            continue;
        }

        $ndc = $row['doc']['drug']['_id'];
        $ndcs[$ndc] = isset($ndcs[$ndc]) ? $ndcs[$ndc] : [];
        $ndcs[$ndc]['rows'] = isset($ndcs[$ndc]['rows']) ? $ndcs[$ndc]['rows'] : [];
        $ndcs[$ndc]['prepack_qty'] = isset($ndcs[$ndc]['prepack_qty']) ? $ndcs[$ndc]['prepack_qty'] : 0; //Hacky to set property on an array

        $months_until_exp    = months_between($item['item_date_added'], $row['doc']['exp']['to']);
        $not_purchased_stock = ($months_until_exp < IS_MFG_EXPIRATION);

        if (strlen($row['doc']['bin']) == 3 AND $not_purchased_stock) {
            $ndcs[$ndc]['prepack_qty'] += $row['doc']['qty']['to'];

            if ( ! @$ndcs[$ndc]['prepack_exp'] or $row['doc']['exp']['to'] < @$ndcs[$ndc]['prepack_exp']) {
                GPLog::warning('group_by_ndc: Prepack and setting expiration', [
                    'item_date_added'     => $item['item_date_added'],
                    'qty'                 => $row['doc']['qty']['to'],
                    'months_until_exp'    => $months_until_exp,
                    'not_purchased_stock' => $not_purchased_stock,
                    'prepack_exp'         => @$ndcs[$ndc]['prepack_exp'],
                    'exp'                 => $row['doc']['exp']['to'],
                    'ndc'                 => $ndc,
                    'row'                 => $row,
                    'ndcs'                => $ndcs
                ]);
                $ndcs[$ndc]['prepack_exp'] = $row['doc']['exp']['to'];
            } else {
                GPLog::error('group_by_ndc: Prepack but not setting expiration', [
                    'item_date_added' => $item['item_date_added'],
                    'qty' => $row['doc']['qty']['to'],
                    'months_until_exp' => $months_until_exp,
                    'not_purchased_stock' => $not_purchased_stock,
                    'prepack_exp' => $ndcs[$ndc]['prepack_exp'],
                    'exp' => $row['doc']['exp']['to'],
                    'ndc' => $ndc,
                    'row' => $row,
                    'ndcs' => $ndcs
                ]);
            }
        }

        if ($ndcs[$ndc]['prepack_qty'] > 0 AND ! $ndcs[$ndc]['prepack_exp'])
            GPLog::error('Prepack has a quantity but no expiration!?', [$ndc, $row, $ndcs]);

        $ndcs[$ndc]['rows'][] = $row['doc'];
    }

    return $ndcs;
}

function sort_by_ndc($ndcs, $long_exp)
{
    $sorted_ndcs = [];
    //Sort the highest prepack qty first
    foreach ($ndcs as $ndc => $val) {
        $sorted_ndcs[] = [
            'ndc'         => $ndc,
            'prepack_qty' => $val['prepack_qty'],
            'prepack_exp' => @$val['prepack_exp'],
            'inventory'   => sort_inventory($val['rows'], $long_exp)
        ];
    }
    //Sort in descending order of prepack_qty with Exp limits.  If Exp is not included
    //then purchased stock gets used before anything else
    usort($sorted_ndcs, function ($a, $b) use ($sorted_ndcs) {
        if (! isset($a['prepack_qty']) or ! isset($b['prepack_qty'])) {
            GPLog::error('ERROR: sort_by_ndc but prepack_qty is not set', get_defined_vars());
        } else {
           //Return shortest non-null prepack expiration date (these exclude IS_MFG_EXPIRATION),
           //if a tie use one with greater prepack quantity, if tie choose one with more items

           //Descending NULL
           if ($a['prepack_exp'] != null AND $b['prepack_exp'] == null) {
               return -1;
           }

           //Descending NULL
           if ($a['prepack_exp'] == null AND $b['prepack_exp'] != null) {
               return 1;
           }

           //Ascending EXP
           if  ($a['prepack_exp'] < $b['prepack_exp']) {
               return -1;
           }

            //Ascending EXP
            if ($a['prepack_exp'] > $b['prepack_exp']) {
                return 1;
            }

            //Descending Qty
            if ($a['prepack_qty'] > $b['prepack_qty']) {
                return -1;
            }

            //Descending Qty
            if ($a['prepack_qty'] < $b['prepack_qty']) {
                return 1;
            }

            //Descending Item Count
            return count($b['inventory']) - count($a['inventory']);
        }
    });

    return $sorted_ndcs;
}

function sort_inventory($inventory, $long_exp)
{

    // Lots of prepacks were expiring because pulled stock with long exp was being
    // paired with short prepack exp making the surplus shortdated
    // Default to longExp since that simplifies sort() if there are no prepacks
    usort($inventory, function ($a, $b) use ($inventory, $long_exp) {

      //Deprioritize ones that are missing data
        if (! $b['bin'] or ! $b['exp'] or ! $b['qty']) {
            return -1;
        }
        if (! $a['bin'] or ! $a['exp'] or ! $a['qty']) {
            return 1;
        }

        $aPack = strlen($a['bin']) == 3;
        $bPack = strlen($b['bin']) == 3;

        // Let's shop for non-prepacks that are closest (but not less than) to
        // our min prepack exp date in order to avoid waste

        // >0 if minPrepackExp < a.doc.exp.to (which is what we prefer)
        $aMonths = months_between(
            (isset($inventory['prepack_exp']) ? $inventory['prepack_exp'] : $long_exp),
            substr($a['exp']['to'], 0, 10)
        );

        // >0 if minPrepackExp < b.doc.exp.to (which is what we prefer)
        $bMonths = months_between(
            (isset($inventory['prepack_exp']) ? $inventory['prepack_exp'] : $long_exp),
            substr($b['exp']['to'], 0, 10)
        );

        //Priortize non-purchased prepacks over other stock
        //Assume Purchased is <12month expiration - 3mos from days supply = ~ 9mos between
        if ($aPack and $aMonths < IS_MFG_EXPIRATION and ! $bPack) {
            return -1;
        }

        if ($bPack and $bMonths < IS_MFG_EXPIRATION and ! $aPack) {
            return 1;
        }

        // Deprioritize anything with a closer exp date than the min prepack exp
        // date.  This - by definition - can only be non-prepack stock
        if ($aMonths >= 0 and $bMonths < 0) {
            return -1;
        }

        if ($bMonths >= 0 and $aMonths < 0) {
            return 1;
        }

        // Priorize anything that is closer to - but not under - our min prepack exp
        // If there is no prepack this is set to 3 months out so that any surplus
        // has time to sit on our shelf
        if ($aMonths >= 0 and $bMonths >= 0 and $aMonths < $bMonths) {
            return -1;
        }
        if ($aMonths >= 0 and $bMonths >= 0 and $bMonths < $aMonths) {
            return 1;
        }

        //If they both expire sooner than our min prepack exp pick the closest
        if ($aMonths < 0 and $bMonths < 0 and $aMonths > $bMonths) {
            return -1;
        }
        if ($aMonths < 0 and $bMonths < 0 and $bMonths > $aMonths) {
            return 1;
        }

        // keep sorting the same as the view (ascending NDCs) [doc.drug._id,
        // doc.exp.to || doc.exp.from, sortedBin, doc.bin, doc._id]
        return 0;
    });

    return $inventory;
}

function months_between($from, $to)
{
    $diff = date_diff(date_create($from), date_create($to));
    return $diff->m + ($diff->y * 12);
}

/**
 * Get the quantity we are going to pend in the next step
 * @param  array $rows    The items that were returned from v2.
 * @param  int   $min_qty The minimum need to fill this rx.
 * @param  float $safety  Unknown????.
 * @return array The of the details needed to pend the items
 */
function get_qty_needed(array $rows, int $min_qty, float $safety)
{
    // Get an array of the NDCs and the total quantity available
    $ndc_quantities = array_column(
        array_map(
            function ($row) {
                return [
                    'ndc' => $row['ndc'],
                    'qty_available' => array_sum(
                        array_column(
                            array_column($row['inventory'], 'qty'),
                            'to'
                        )
                    )
                ];
            },
            $rows
        ),
        'qty_available',
        'ndc'
    );

    // filter out NDCs that don't have enough quantity to fill the order
    // Then create an array with the NDC as the key and the qty_available as the value
    $available_ndcs = array_filter(
        $ndc_quantities,
        function ($qty) use ($min_qty) {
            return $qty >= $min_qty;
        }
    );

    // If we don't have any NDCs then we should quit
    if (count($available_ndcs) == 0) {
        GPLog::notice(
            "It appears there are not any NDCs with a quantity great enough to fill this RX.  Is This Accurate?",
            [
                'ndc_quantities'     => $ndc_quantities,
                'available_ndcs'     => $available_ndcs,
                'requested_quantity' => $min_qty
            ]
        );
        return;
    }

    GPLog::debug(
        "There appears to be an ndc that has enough quantity to complete this request",
        [
            'ndc_quantities'     => $ndc_quantities,
            'available_ndcs'     => $available_ndcs,
            'requested_quantity' => $min_qty
        ]
    );

    // Pick the appropriate NDC and then pend
    foreach ($rows as $row) {
        $ndc  = $row['ndc'];

        // Either this is our first NDC or the NDC has changed.
        // Either way we need to define all the variables
        if (!isset($selected_ndc) || $ndc != $selected_ndc) {
            // If we already have a selected NDC, then we need to note there wasn't enough quantity
            if (isset($selected_ndc)) {
                GPLog::debug(
                    "{$selected_ndc} did not have enought stock ({$ndc_quantities[$selected_ndc]})
                    to meet the request of between {$min_safe_qty} and {$max_qty}",
                    [
                        'available_ndcs' => $available_ndcs,
                        'ndc_quantities' => $ndc_quantities,
                        'max_qty' => $max_qty,
                        'min_qty' => $min_qty,
                        'min_safe_qty' => $min_safe_qty,
                        'left' => $left
                    ]
                );
            }

            $selected_ndc = $ndc;
            $min_safe_qty = $min_qty * (1 + $safety);
            $list          = [];
            $pend          = [];
            $qty           = 0;
            $qty_repacks   = 0;
            $count_repacks = 0;
            $left          = $min_qty;
            $max_qty       = ($left * 1.25);
            $max_qty       = (floor($max_qty) < $min_qty) ? $min_qty : floor($max_qty);
        }

        $inventory = $row['inventory'];

        foreach ($inventory as $i => $option) {
            if ($i == 'prepack_qty') {
                continue;
            }

            // Put the option on the top of the pend list
            $will_exceed_max = ($option['qty']['to'] + $qty) > $max_qty;
            if (!$will_exceed_max || $left > 0) {
                array_unshift($pend, $option);

                $usable = 1 - $safety;

                if (strlen($pend[0]['bin']) == 3) {
                    $usable = 1;
                    $qty_repacks += $pend[0]['qty']['to'];
                    $count_repacks++;
                }

                $qty += $pend[0]['qty']['to'];
                $left -= $pend[0]['qty']['to'] * $usable;
                $list = pend_to_list($list, $pend);
            }

            /*
                Shop for all matching medicine in the bin, its annoying and inefficient to pick some
                 and leave the others

                Update 1: Don't do the above if we are in a prepack bin, otherwise way will way
                overshop (eg Order #42107)
                Update 2: Don't do if they are manufacturer bottles otherwise we get way too much
                Update 3: Manufacturer bottles are anything over 60
                Update 4: Quit Pending if we are over 50%% of the originaly pend request
            */
            $different_bin = ($pend[0]['bin'] != @$inventory[$i+1]['bin']);
            $is_prepack    = (strlen($pend[0]['bin']) == 3);
            $is_mfg_bottle = ($pend[0]['qty']['to'] >= 60);
            $over_max      = $qty > $max_qty;
            $min_met      = ($qty >= $min_qty);
            $unit_of_use   = ($min_qty < 5);

            GPLog::debug(
                "get_qty_needed;  {$ndc} SHOULD CONTINUE PENDING?",
                [
                    'left'          => $left,
                    'over_max'      => $over_max,
                    'different_bin' => $different_bin,
                    'is_prepack'    => $is_prepack,
                    'is_mfg_bottle' => $is_mfg_bottle,
                    'unit_of_use'   => $unit_of_use,
                    'ndc'           => $ndc,
                    'qty'           => $qty,
                    'min_qty'       => $min_qty,
                    'max_qty'       => $max_qty,
                    'min_met'       => $min_met,
                    'stop_condition_1' => (int) (
                        $left <= 0
                        && (
                            $over_max
                            || $different_bin
                            || $is_prepack
                            || $is_mfg_bottle
                            || $unit_of_use
                        )
                    ),
                    'stop_condition_2' => (int) $is_mfg_bottle && $min_met
                ]
            );

            if (
                (
                    $left <= 0
                    && (
                        $over_max
                        || $different_bin
                        || $is_prepack
                        || $is_mfg_bottle
                        || $unit_of_use
                    )
                ) || (
                    $is_mfg_bottle && $min_met
                )
            ) {
                usort($list, 'sort_list');

                if (($qty/$min_qty) >= 2) {
                    GPLog::warning(
                        'get_qty_needed;  Pended Quantity > 2x the requested quantity.
                        Verify picked items are correct.  After we have confirmed the qty
                        has been accuratly created we can resolve the alert.  After 10 - 15 of these
                        we can remove the alert completely.',
                        [
                            'left'          => $left,
                            'over_max'      => $over_max,
                            'different_bin' => $different_bin,
                            'is_prepack'    => $is_prepack,
                            'is_mfg_bottle' => $is_mfg_bottle,
                            'unit_of_use'   => $unit_of_use,
                            'list'          => $list,
                            'ndc'           => $ndc,
                            'pend'          => $pend,
                            'qty'           => $qty,
                            'min_qty'       => $min_qty,
                            'max_qty'       => $max_qty,
                            'pend_qty'      => $qty,
                            'count'         => count($list),
                            'qty_repacks'   => $qty_repacks,
                            'count_repacks' => $count_repacks
                        ]
                    );
                } else {
                    GPLog::debug(
                        "get_qty_needed:  Finding quantity to pend for {$option['drug']['generic']}",
                        [
                            'min_qty' => $min_qty,
                            'max_qty' => $max_qty,
                            'pend_qty' => $qty
                        ]
                    );
                }

                return [
                    'list'          => $list,
                    'ndc'           => $ndc,
                    'pend'          => $pend,
                    'qty'           => $qty,
                    'count'         => count($list),
                    'qty_repacks'   => $qty_repacks,
                    'count_repacks' => $count_repacks
                ];
            }
        }
    }
}

function pend_to_list($list, $pend)
{
    $list[] = [
    $pend[0]['_id'],
    $pend[0]['drug']['_id'],
    $pend[0]['drug']['form'],
    substr($pend[0]['exp']['to'], 0, 7),
    $pend[0]['qty']['to'],
    $pend[0]['bin']
  ];
    return $list;
}

function sort_list($a, $b)
{
    $aBin = $a[5];
    $bBin = $b[5];

    $aPack = $aBin and strlen($aBin) == 3;
    $bPack = $bBin and strlen($bBin) == 3;

    if ($aPack > $bPack) {
        return 1;
    }
    if ($aPack < $bPack) {
        return -1;
    }

    //Flip columns and rows for sorting, since shopping is easier if you never move backwards
    $aFlip = $aBin[0].$aBin[2].$aBin[1].(@$aBin[3] ?: '');
    $bFlip = $bBin[0].$bBin[2].$bBin[1].(@$bBin[3] ?: '');

    if ($aFlip < $bFlip) {
        return 1;
    }
    if ($aFlip > $bFlip) {
        return -1;
    }

    return 0;
}
