<?php

global $mysql;
use Sirum\Logging\SirumLog;

function wc_get_post_id($invoice_number, $full_order = false)
{
    global $mysql;
    $mysql = $mysql ?: new Mysql_Wc();

    $sql = "SELECT *
            FROM wp_posts
              JOIN wp_postmeta ON wp_posts.id = wp_postmeta.post_id
            WHERE wp_postmeta.meta_key='invoice_number'
              AND wp_postmeta.meta_value = '{$invoice_number}'";
    $res = $mysql->run($sql);

    if (isset($res[0][0])) {
        return $full_order ? $res[0][0] : $res[0][0]['post_id'];
    }
}

/**
 * Insert meta data
 *
 * @deprecated This logic has been moved into wc_update_meta.  I'm leaving this
 *      function to cover any old code and not require large changes
 *
 * @param  int   $invoice_number The invoice we are working with
 * @param  array $metadata       A array of key => values to store
 *
 * @return void               [description]
 */
function wc_insert_meta($invoice_number, $metadata)
{
    // Send all these updates through the update functionality so
    // we don't end up with duplicates
    return wc_update_meta($invoice_number, $metadata);
}

/**
 * Update/Insert the meta values.
 *
 * This funciton combines the Insert and Update into a single function.  Before
 * we do anything, we pull out what meta fields are available.  Then we break the
 * passed in data into 2 arrays, 1 for meta that alread exists and 1 for meta
 * that needs to be inserted.
 *
 * @param  int   $invoice_number The invoice we are working with
 * @param  array $metadata       A array of key => values to store
 *
 * @return void
 */
function wc_update_meta($invoice_number, $metadata)
{
    global $mysql;
    $mysql = $mysql ?: new Mysql_Wc();

    $post_id = wc_get_post_id($invoice_number);

    if (! $post_id) {
        return SirumLog::warning(
            "wc_update_meta: no post id",
            [
                "invoice_number" => $invoice_number,
                "meta_values"    => $metadata
            ]
        );
    }

    $existing_meta = $mysql->run(
        "SELECT meta_key
        FROM wp_postmeta
            WHERE post_id = {$post_id}"
    )[0];

    //find fields that are in not in the second array but are in the first array.
    // Should end up with 2 arrays.  one are the fields to update one are the fields to insert.
    // Get the values
    $existing_meta = array_column($existing_meta, 'meta_key');
    // Make them keys
    $existing_meta = array_flip($existing_meta);
    // Find missing keys
    $meta_to_insert = array_diff_key($metadata, $existing_meta);
    // Find Existing Keys
    $meta_to_update = array_intersect_key($metadata, $existing_meta);

    if (count($meta_to_insert) > 0) {
        SirumLog::debug(
            "Inserting Meta data",
            [
                "invoice_number" => $invoice_number,
                "meta_values"    => $metadata,
                "post_id"        => $post_id
            ]
        );

        foreach ($meta_to_insert as $meta_key => $meta_value) {
            if (is_array($meta_value)) {
                $meta_value = json_encode($meta_value);
            }

            $meta_value = clean_val($meta_value);

            //mysql->run() does mysqli_query and not mysqli_multi_query so we cannot concatentate the inserts and run all at once
            $mysql->run("INSERT INTO wp_postmeta (meta_id, post_id, meta_key, meta_value)
                         VALUES (NULL, '{$post_id}', '{$meta_key}', {$meta_value})");
        }
    }

    foreach ($meta_to_update as $meta_key => $meta_value) {
        SirumLog::debug(
            "Update WC Meta",
            [
                "invoice_number" => $invoice_number,
                "meta_values"    => $meta_to_update,
                "post_id"        => $post_id
            ]
        );

        if (is_array($meta_value)) {
            $meta_value = json_encode($meta_value);
        }

        $meta_value = clean_val($meta_value);

        // mysql->run() does mysqli_query and not mysqli_multi_query so we cannot
        // concatentate the inserts and run all at once
        $mysql->run("UPDATE wp_postmeta
                        SET meta_value = $meta_value
                        WHERE post_id = $post_id
                            AND meta_key = '$meta_key'");
    }

    SirumLog::debug(
        "Update WC Meta",
        [
            "invoice_number" => $invoice_number,
            "meta_values"    => $meta_to_update
        ]
    );
}

function wc_update_order($invoice_number, $orderdata)
{
    global $mysql;
    $mysql = $mysql ?: new Mysql_Wc();

    $set = [];

    $post_id = wc_get_post_id($invoice_number);

    foreach ($orderdata as $order_key => $order_value) {
        if (is_array($order_value)) {
            $order_value = json_encode($order_value);
        }

        $order_value = clean_val($order_value);

        $set[] = "$order_key = $order_value";
    }

    $sql = "UPDATE wp_posts
                SET ".implode(', ', $set)."
                WHERE ID = $post_id;";

    if (@$orderdata['post_status']) {
        $res = $mysql->run("SELECT *
                                FROM wp_posts
                                JOIN wp_postmeta ON wp_posts.id = wp_postmeta.post_id
                                WHERE wp_postmeta.meta_key='invoice_number'
                                    AND wp_postmeta.meta_value = '{$invoice_number}'");

        $old_status = $res[0][0]['post_status'];

        if ($res[0][0]['post_status'] != $orderdata['post_status']) {
            log_notice("wc_update_order: status change $old_status >>> $orderdata[post_status]");
            wc_insert_meta(
                $invoice_number,
                [
                    'status_update' => date('Y-m-d H:i:s')." Webform $old_status >>> $orderdata[post_status]"
                ]
            );
        }
    }

    $mysql->run($sql);
}

/**
 * Update WooCommerce with the new orders status
 * @param  array $order An updated order
 * @return void
 */
function export_wc_update_order_status($order)
{
    $orderdata = [
        'post_status' => 'wc-' .  str_replace('wc-', '', $order[0]['order_stage_wc'])
    ];

    log_notice(
        'export_wc_update_order_status: wc_update_order',
        [
            'invoice_number' => $order[0]['invoice_number'],
            'order_stage_wc' => $order[0]['order_stage_wc'],
            'order_stage_cp' => $order[0]['order_stage_cp']
        ]
    );

    wc_update_order($order[0]['invoice_number'], $orderdata);
}

function export_wc_delete_order($invoice_number, $reason)
{
    global $mysql;
    $mysql = $mysql ?: new Mysql_Wc();

    $post_id = wc_get_post_id($invoice_number);

    if (! $post_id) {
        return log_error("export_wc_delete_order: cannot delete if no post_id", get_defined_vars());
    }

    $sql1 = "DELETE FROM wp_postmeta WHERE post_id = $post_id";

    $mysql->run($sql1);

    $sql2 = "DELETE FROM wp_posts WHERE id = $post_id";

    $mysql->run($sql2);

    /*
     * This function call will happen AFTER the wc_order import happened,
     * so we need to remove this order from the gp_orders_wc table or it
     * might still appear as "created" in the wc_order changes feeds
     */
    $sql3 = "DELETE FROM gp_orders_wc WHERE invoice_number = $invoice_number";

    $mysql->run($sql3);

    SirumLog::debug(
        "export_wc_delete_order",
        [
            'invoice_number' => $invoice_number,
            'reason'         => $reason,
            'post_id'        => $post_id
        ]
    );
}

function export_wc_create_order($order, $reason)
{
    global $mysql;
    $mysql          = $mysql ?: new Mysql_Wc();

    $first_item     = $order[0];

    $invoice_number = $first_item['invoice_number'];
    $first_name     = str_replace(["'", '*'], ['',''], $first_item['first_name']); //Ignore Cindy's internal marking
    $last_name      = str_replace(["'", '*'], ['',''], $first_item['last_name']); //Ignore Cindy's internal marking
    $birth_date     = str_replace('*', '', $first_item['birth_date']); //Ignore Cindy's internal marking

    //START DEBUG
    $wc_order = wc_get_post_id($invoice_number, true);

    if ($wc_order) {
        log_error(
            "export_wc_create_order: aborting create WC order because it already exists",
            [$first_item, $wc_order, $reason]
        );
        return $order;
    }
    //STOP DEBUG

    //This creates order and adds invoice number to metadata
    //We do this through REST API because direct database calls seemed messy
    $url = "patient/$first_name $last_name $birth_date/order/$invoice_number";
    $res = wc_fetch($url);

    //if order is set, then its just a this order already exists error
    if (! empty($res['error']) or empty($res['order'])) {
        if (stripos($first_item['first_name'], 'TEST') === false
                and stripos($first_item['last_name'], 'TEST') === false) {
            log_error("export_wc_create_order: res[error] for $url", [$reason, $res, $first_item]);
        }

        return;
    }

    log_notice("export_wc_create_order: success for $url", [$reason, $res, $first_item]);

    //These are the metadata that should NOT change
    //wc_upsert_meta($order_meta, 'shipping_method_id', ['31694']);
    //wc_upsert_meta($order_meta, 'shipping_method_title', ['31694' => 'Admin Fee']);

    $metadata = [
        'patient_id_cp'     => $first_item['patient_id_cp'],
        'patient_id_wc'     => $first_item['patient_id_wc'],
        'order_date_added'  => $first_item['order_date_added'],
        'refills_used'      => $first_item['refills_used'],
        'patient_autofill'  => $first_item['patient_autofill'],
        'order_source'      => $first_item['order_source'],
        'reason'            => $reason
    ];

    wc_insert_meta($invoice_number, $metadata);
    export_wc_update_order_status($order);
    export_wc_update_order_metadata($order, 'wc_insert_meta');
    export_wc_update_order_address($order, 'wc_insert_meta');
    export_wc_update_order_payment($invoice_number, $first_item['payment_fee_default']);

    $address1 = escape_db_values($first_item['order_address1']);
    $address2 = escape_db_values($first_item['order_address2']);

    /*
     * This function call will happen AFTER the wc_order import happened,
     * so we need to add this order to gp_orders_wc table or it might still
     * appear as "deleted" in the wc_order changes feeds
     */
    $sql = "INSERT INTO gp_orders_wc (
                invoice_number,
                patient_id_wc,
                order_stage_wc,
                order_source,
                invoice_doc_id,
                order_address1,
                order_address2,
                order_city,
                order_state,
                order_zip,
                payment_method_actual,
                coupon_lines,
                order_note
              ) VALUES (
                '$invoice_number',
                '{$first_item['patient_id_wc']}',
                '{$first_item['order_stage_wc']}',
                '{$first_item['order_source']}',
                '{$first_item['invoice_doc_id']}',
                '$address1',
                '$address2',
                '{$first_item['order_city']}',
                '{$first_item['order_state']}',
                '{$first_item['order_zip']}',
                '{$first_item['payment_method_actual']}',
                '{$first_item['coupon_lines']}',
                '{$first_item['order_note']}'
              )";

    $mysql->run($sql);

    log_notice('export_wc_create_order: created new order', [$metadata, $sql, $invoice_number]);

    return $order;
}

function export_wc_update_order($order)
{
    if ($order[0]['rx_message_key'] == 'ACTION NEEDS FORM') {
        log_notice('export_wc_update_order: ACTION NEEDS FORM update order skipped because order not yet created in WC', $order[0]);
        return;
    }

    export_wc_update_order_status($order);
    export_wc_update_order_metadata($order);
    export_wc_update_order_address($order);
    export_wc_update_order_payment($order[0]['invoice_number'], $order[0]['payment_fee_default']);
}

//These are the metadata that might change
function export_wc_update_order_metadata($order, $meta_fn = 'wc_update_meta')
{
    if (! $order[0]['order_stage_wc']) {
        return log_error('export_wc_update_order_metadata: no order_stage_wc', get_defined_vars());
    }

    $post_id = wc_get_post_id($order[0]['invoice_number']);

    if (! $post_id) {
        return log_error('export_wc_update_order_metadata: order missing', get_defined_vars());
    }

    $metadata = [
        '_payment_method' => $order[0]['payment_method'],
        'order_stage_cp'  => $order[0]['order_stage_cp'],
        'order_status'    => $order[0]['order_status'],
        'invoice_doc_id'  => $order[0]['invoice_doc_id']
    ];

    if ($order[0]['payment_coupon']) {
        $metadata['_coupon_lines'] = [["code" => $order[0]['payment_coupon']]];
    }

    if ($order[0]['order_date_dispensed']) {
        $metadata['order_date_dispensed'] = $order[0]['order_date_dispensed'];
        //$metadata['invoice_doc_id']       = $order[0]['invoice_doc_id'];
        $metadata['count_items']          = $order[0]['count_items'];
        $metadata['count_filled']         = $order[0]['count_filled'];
        $metadata['count_nofill']         = $order[0]['count_nofill'];
    }

    if ($order[0]['tracking_number']) { //Keep status the same until it is shipped
        $metadata['tracking_number']    = $order[0]['tracking_number'];
        $metadata['order_date_shipped'] = $order[0]['order_date_shipped'];
    }

    $meta_fn($order[0]['invoice_number'], $metadata);
}

function export_wc_update_order_address($order, $meta_fn = 'wc_update_meta')
{
    $post_id = wc_get_post_id($order[0]['invoice_number']);

    if (! $post_id) {
        return log_error('export_wc_update_order_shipping: order missing', get_defined_vars());
    }

    $metadata = [
        '_shipping_first_name' => $order[0]['first_name'],
        '_shipping_last_name'  => $order[0]['last_name'],
        '_shipping_email'      => $order[0]['email'],
        '_shipping_phone'      => $order[0]['phone1'],
        '_billing_phone'       => $order[0]['phone2'],

        '_shipping_address_1'  => $order[0]['order_address1'],
        '_shipping_address_2'  => $order[0]['order_address2'],
        '_shipping_city'       => $order[0]['order_city'],
        '_shipping_state'      => $order[0]['order_state'],
        '_shipping_postcode'   => $order[0]['order_zip']
    ];

    $meta_fn($order[0]['invoice_number'], $metadata);
}

//Use REST API because direct DB calls to order_items and order_itemsmeta tables seemed messy/brittle
function export_wc_update_order_payment($invoice_number, $payment_fee)
{
    $post_id = wc_get_post_id($invoice_number);

    if (! $post_id) {
        return log_error('export_wc_update_order_payment: order missing', get_defined_vars());
    }

    $response = wc_fetch("order/$post_id/payment_fee/$payment_fee");

    if (! $response) {
        return log_error('export_wc_update_order_payment: failed', get_defined_vars());
    }

    if (! empty($response['error'])) {
        return log_error('export_wc_update_order_payment: error', get_defined_vars());
    }
}

function wc_fetch($path, $method = 'GET', $content = null, $retry = false)
{
    $opts = [
        'http' => [
            'method'        => $method,
            'ignore_errors' => true,
            'timeout'       => 2*60, //Seconds
            'header'        => "Content-Type: application/json\r\n".
                               "Accept: application/json\r\n".
                               "Authorization: Basic ".base64_encode(WC_USER.':'.WC_PWD)
        ]
    ];

    if ($content) {
        $opts['http']['content'] = json_encode($content);
    }

    $url = str_replace(' ', '%20', WC_URL."/wp-json/$path");

    $context = stream_context_create($opts);

    $res  = file_get_contents($url, false, $context);
    $json = json_decode($res, true);

    if (! $json and ! $retry) {
        file_get_contents("https://postb.in/1579126559674-7982679251581?url=$url", false, $context);

        /*
         * CloudFlare sometimes returns a 400 Bad Request error even on valid
         * url.  Tried Cloud Flare Page Rules but still having issues
         */
        sleep(5);
        log_error(
            "wc_fetch: no response attempt 1 of 2",
            [
                'url' => $url,
                'res' => $res,
                'http_code' => $http_response_header
            ]
        );
        return wc_fetch($path, $method, $content, true);
    } elseif (! $json) {
        log_error(
            "wc_fetch: no response attempt 2 of 2",
            [
                'url'       => $url,
                'res'       => $res,
                'http_code' => $http_response_header
            ]
        );
        return ['error' => "no response from wc_fetch"];
    }

    log_info("wc_fetch", ['url' => $url, 'json' => $json]);

    return $json;
}
