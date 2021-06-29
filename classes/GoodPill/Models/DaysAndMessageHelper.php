<?php

use GoodPill\Logging\GPLog;
use GoodPill\Models\GpOrder;

class DaysMessageHelper {
    public static function getDaysAndMessage(DaysMessageInterface $item, GpOrder $order) {
        $no_transfer = $item->isNoTransfer();
        $added_manually = $item->isAddedManually();
        $is_webform = $item->isWebform();
        $not_offered = $item->isNotOffered();
        $is_refill = $item->isRefill($order);
        $is_refill_only = $item->isRefillOnly();
        $is_not_rxs_parsed = $item->isNotRxsParsed();

        $days_left_in_expiration = $item->getDaysLeftBeforeExpiration();
        $days_left_in_refills = $item->getDaysLeftInRefills();
        $days_left_in_stock = $item->getDaysLeftInStock();
        $days_default = $item->getDaysDefault();
        $date_added = $item->date_added;
        $days_early_next = $item->days_early_next;
        $days_early_default = $item->days_early_default;
        $days_since = $item->days_since;

        GPLog::debug(
            "Days And Message Helper Refactor",
            [
                'item' => $item->toArray(),
                'no_transfer' => $no_transfer,
                'added_manually' => $added_manually,
                'is_webform' => $is_webform,
                'not_offered' => $not_offered,
                'is_refill' => $is_refill,
                'is_refill_only' => $is_refill_only,
                //  'stock_level' => $stock_level, @todo make this a computed attribute
                'is_not_rxs_parsed' => $is_not_rxs_parsed,
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
    }
}
