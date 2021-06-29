<?php

namespace GoodPill\Models;

use Carbon\Carbon;
use GoodPill\Logging\GPLog;
use GoodPill\Models\GpOrder;
use Illuminate\Database\Eloquent\Model;

use GoodPill\Models\GpPatient;
use GoodPill\Models\GpRxsSingle;
use GoodPill\Models\v2\PickListDrug;
use GoodPill\Models\Carepoint\CpFdrNdc;
use GoodPill\Models\Carepoint\CpRx;
use GoodPill\Utilities\GpComments;

require_once "helpers/helper_full_item.php";
require_once "helpers/helper_appsscripts.php";
require_once "exports/export_v2_order_items.php";

/**
 * Class GpOrderItem
 */
class GpOrderItem extends Model implements \DaysMessageInterface
{

    use \GoodPill\Traits\HasCompositePrimaryKey;

    /**
     * The Table for this data
     * @var string
     */
    protected $table = 'gp_order_items';

    /**
     * Does the database contining an incrementing field?
     * @var boolean
     */
    public $incrementing = false;

    /**
     * Does the database contining timestamp fields
     * @var boolean
     */
    public $timestamps = false;

    /**
     * Fields that should be cast when they are set
     * @var array
     */
    protected $casts = [
        'invoice_number'                => 'int',
        'patient_id_cp'                 => 'int',
        'rx_number'                     => 'int',
        'rx_dispensed_id'               => 'int',
        'patient_autofill_initial'      => 'int',
        'rx_autofill_initial'           => 'int',
        'zscore_initial'                => 'float',
        'refills_dispensed_default'     => 'float',
        'refills_dispensed_actual'      => 'float',
        'days_dispensed_default'        => 'int',
        'days_dispensed_actual'         => 'int',
        'qty_dispensed_default'         => 'float',
        'qty_dispensed_actual'          => 'float',
        'price_dispensed_default'       => 'float',
        'price_dispensed_actual'        => 'float',
        'qty_pended_total'              => 'float',
        'qty_pended_repacks'            => 'float',
        'count_pended_total'            => 'int',
        'count_pended_repacks'          => 'int',
        'count_lines'                   => 'int',
        'sync_to_date_days_before'      => 'float',
        'sync_to_date_days_change'      => 'float',
        'sync_to_date_max_days_default' => 'float',
        'sync_to_date_min_days_refills' => 'float',
        'sync_to_date_min_days_stock'   => 'float'
    ];

    /**
     * Fields that should be dates
     * @var array
     */
    protected $dates = [
        'item_date_added',
        'refill_date_last',
        'refill_date_manual',
        'refill_date_default'
    ];


    /**
     * Fields that represent database fields and
     * can be set via the fill method
     * @var array
     */
    protected $fillable = [
        'drug_name',
        'patient_id_cp',
        'groups',
        'rx_dispensed_id',
        'stock_level_initial',
        'rx_message_keys_initial',
        'patient_autofill_initial',
        'rx_autofill_initial',
        'rx_numbers_initial',
        'zscore_initial',
        'refills_dispensed_default',
        'refills_dispensed_actual',
        'days_dispensed_default',
        'days_dispensed_actual',
        'qty_dispensed_default',
        'qty_dispensed_actual',
        'price_dispensed_default',
        'price_dispensed_actual',
        'qty_pended_total',
        'qty_pended_repacks',
        'count_pended_total',
        'count_pended_repacks',
        'count_lines',
        'item_message_keys',
        'item_message_text',
        'item_type',
        'item_added_by',
        'item_date_added',
        'refill_date_last',
        'refill_date_manual',
        'refill_date_default',
        'sync_to_date_days_before',
        'sync_to_date_days_change',
        'sync_to_date_max_days_default',
        'sync_to_date_max_days_default_rxs',
        'sync_to_date_min_days_refills',
        'sync_to_date_min_days_refills_rxs',
        'sync_to_date_min_days_stock',
        'sync_to_date_min_days_stock_rxs'
    ];

    /**
     * Property to hold the picklist so it isn't constantly retrieved
     * @var PickListDrug
     */
    protected $pick_list;

    /**
     * The primary key fields
     * @var array
     */
    protected $primaryKey = ['rx_number', 'invoice_number'];

    /*
        ## RELATIONSHIPS
     */

    /**
     * Relationship to an order entity
     * foreignKey - invoice_number
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order()
    {
        return $this->belongsTo(GpOrder::class, 'invoice_number');
    }

    /**
     * Relationship to a patient entity
     * foreignKey - patient_id_cp
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function patient()
    {
        return $this->belongsTo(GpPatient::class, 'patient_id_cp');
    }

    /**
     * Relationship to a Single Rx
     * foreignKey - rx_number
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function rxs()
    {
        return $this->hasOne(GpRxsSingle::class, 'rx_number', 'rx_number');
    }

    /**
     * Loads the GpRxsGrouped data into an rxs single item
     * Because there is no traditional relationship that laravel can make, we have to pseudo-load the data
     * When inspecting an order item, if you fetch the rxs for that item you would need to call this function
     * to apply the grouped model into the rxs object
     *
     * Duplicate logic of GpRxsSingle - grouped()
     *
     */
    public function grouped()
    {
        $this->grouped = GpRxsGrouped::where('rx_numbers', 'like', "%,{$this->rx_number},%")->first();
    }

    /*
        ## CONDITIONALS
     */

    /**
     * Checks that item has refills available
     * @return bool
     */
    public function hasRefills() : bool
    {
        $grouped = $this->grouped;
        return $grouped->hasRefills();
    }

    /**
     * Query v2 to see if there is already a drug pended for this order
     * @return boolean
     */
    public function isPended() : bool
    {
        return ($this->getPickList()->isPended());
    }

    /**
     * Has the picklist been picked
     * @return bool True if all of the item on the picklist are picked
     */
    public function isPicked() : bool
    {
        return ($this->getPickList()->isPicked());
    }

    /**
     * Determine if the item was manually added
     * @return bool
     */
    function isAddedManually() : bool
    {
        return
            in_array($this->item_added_by, ADDED_MANUALLY) ||
            (
                $this->item_date_added &&
                $this->refill_date_manual &&
                $this->order->is_auto_refill()
            );
    }

    /**
     * Is the item set to auto refill. Based on the order source being auto refill v2
     *
     * @return bool
     */
    public function isAutoRefill(): bool
    {
        return in_array($this->order->order_source, ['Auto Refill v2', 'O Refills']);
    }

    /**
     * GpOrderItem
     * @TODO - Decide what to do with this and corresponding grouped function
     *
     * Determine by the stock level if the item is offered or not
     * @return bool
     */
    public function isNotOffered() : bool
    {
        $rxs = $this->rxs;
        $stock = $rxs->stock;

        $rx_gsn = $rxs->rx_gsn;
        $drug_name = $rxs->drug_name; // this used to be used for logging purposes

        //  For a GpOrderItem, a stock_level_initial exists, so this check is used
        $stock_level = $this->stock_level_initial ?: $stock->stock_level;

        if (
            $rx_gsn > 0 ||
            $stock_level == STOCK_LEVEL['NOT OFFERED'] ||
            $stock_level == STOCK_LEVEL['ORDER DRUG']
        ) {
            return true;
        }

        return false;
    }

    /**
     * Checks to see if the stock price is high
     * @return bool
     */
    public function isHighPrice() : bool
    {
        $price_per_month = $this->rxs->stock->price_per_month;

        return $price_per_month >= 20;
    }

    /**
     * Determines if we should not transfer out items
     * This function is worded poorly. Should really be called 'shouldTransfer'
     * Checks for a high price or to see if the patient's backup pharmacy is us
     *
     * @return bool
     */
    public function isNoTransfer() : bool
    {
        return $this->isHighPrice() || $this->patient->pharmacy_phone === '8889875187';
    }

    /**
     * Check if the item came from a webform transfer of some kind
     *
     * @return bool
     */
    public function isWebform() : bool
    {
        return
            $this->isWebformTransfer() ||
            $this->isWebformErx() ||
            $this->isWebformRefill();
    }

    /**
     * Checks of this is a webform transfer
     * @return bool
     */
    public function isWebformTransfer()
    {
        return in_array($this->order_source, ['Webform Transfer', 'Transfer /w Note']);
    }

    /**
     * Checks of this is a webform exr (surescripts)
     * @return bool
     */
    public function isWebformErx()
    {
        return in_array($this->order_source, ['Webform eRx', 'eRx /w Note']);
    }

    /**
     * Checks of this is a webform refill
     * @return bool
     */
    public function isWebformRefill()
    {
        return in_array($this->order_source, ['Webform Refill', 'Refill w/ Note']);
    }

    /**
     * Determine if this is a refill that we should try to fill
     * @return bool
     */
    public function isRefillOnly() : bool
    {
        $rxs = $this->rxs;
        $stock = $rxs->stock;

        $stock_level = $this->stock_level_initial ?: $stock->stock_level;
        return in_array(
            $stock_level,
            [
                STOCK_LEVEL['OUT OF STOCK'],
                STOCK_LEVEL['REFILL ONLY']
            ]
        );
    }

    /**
     * Check stock level to see if this a one-time fill
     * @TODO - Decide what to do with this and corresponding grouped function
     * @return bool
     */
    public function isOneTime() : bool
    {
        $rxs = $this->rxs;
        $stock = $rxs->stock;

        $stock_level = $this->stock_level_initial ?: $stock->stock_level;
        return in_array(
            $stock_level,
            [
                STOCK_LEVEL['ONE TIME']
            ]
        );
    }

    /**
     * Checks if this item happens to already be in an order under same name
     * matches drug_generic rather than rx_number
     *
     * @param GpOrder $order
     * @return bool
     */
    function isRefill(GpOrder $order) : bool
    {
        foreach ($order->items as $orderItem) {
            if (
                $this->drug_generic == $orderItem->drug_generic
                && $orderItem->refill_date_first
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Was the rx properly parsed
     * @return bool
     */
    public function isNotRxParsed() : bool
    {
        $grouped = $this->grouped;
        return $grouped->isNotRxParsed();
    }

    /**
     * Checks if the current item is already in the order
     * This may not be needed for order item
     * @param GpOrder $order
     * @return bool
     */
    public function isDuplicateGsn(GpOrder $order) : bool
    {
        //  @TODO - Write this
        return true;
    }

    /*
        ## ACCESSORS
     */

    /**
     * Get access to the rx drug_name.  We use this here to rollup between brand and generic
     * @return string
     */
    public function getDrugGenericAttribute(): ?string
    {
        $rxs = $this->rxs;

        if (!$rxs->drug_generic) {
            return $rxs->drug_name;
        }

        return $rxs->drug_generic;
    }

    /**
     * Get the days dispensed computed attribute
     * @return float
     */
    public function getDaysDispensedAttribute() : float
    {
        return $this->days_dispensed_actual ?: $this->days_dispensed_default;
    }

    /**
     * Get the price dispensed computed attribute
     * @return float
     */
    public function getPriceDispensedAttribute() : float
    {
        //  Need to get the price_per_month from stock live table
        if ($this->rxs->stock) {
            $price_per_month = $this->rxs->stock->price_per_month;
        } else {
            $price_per_month = 0;
        }

        $price = ceil($this->days_dispensed * $price_per_month / 30);
        /*
         * Should this log continue to be here/do we care about this?
        if ($price > 80) {

            GPLog::debug(
                'GpOrderItem: price_dispensed is too high',
                [
                    'invoice_number' =>  $this->invoice_number,
                    'drug_name' => $this->drug_name,
                    'rx_number' => $this->rx_number,
                ]
            );
        }
        */
        return $price;
    }

    /**
     * Computed property to get the `refills_dispensed` field
     * @return float|null
     */
    public function getRefillsDispensedAttribute() : ?float
    {
        if ($this->refills_dispensed_actual) {
            return round($this->refills_dispensed_actual, 2);
        } elseif ($this->refills_dispensed_default) {
            return round($this->refills_dispensed_default, 2);
        } elseif ($this->refills_total) {
            return round($this->refills_total, 2);
        } else {
            return null;
        }
    }

    /**
     * Gets the date that the prescription was originally written
     * @return false|int
     */
    public function getRxDateWrittenAttribute()
    {
        return  strtotime($this->rxs->rx_date_expired . ' -1 year');
    }

    /**
     * The date that the item was added to the order or prescription written
     * @return mixed
     */
    public function getDateAddedAttribute()
    {
        return $this->order->order_date_added ?: $this->rx_date_written;
    }

    /**
     * Calculated earliest number of days when the item can be filled
     * @return false|int
     */
    public function getDaysEarlyNextAttribute()
    {
        return strtotime($this->refill_date_next) - strtotime($this->date_added);
    }

    /**
     * Calculated default days when an item can be due for another fill
     * @return false|int
     */
    public function getDaysEarlyDefaultAttribute()
    {
        return strtotime($this->refill_date_default) - strtotime($this->date_added);
    }

    /**
     * Calculated days since an item was last filled
     * @return false|int
     */
    public function getDaysSinceAttribute()
    {
        return strtotime($this->date_added) - strtotime($this->rxs->refill_date_last);
    }


    /**
     * Get a user friendly drug name. This is used mostly for communications to the patient
     * @return string
     */
    public function getDrugName()
    {
        $rxs = $this->rxs;

        if (!$rxs->drug_generic) {
            return $rxs->drug_name;
        }

        if (!$rxs->drug_brand) {
            return $rxs->drug_generic;
        }

        return "{$rxs->drug_generic} ({$rxs->drug_brand})";
    }

    /**
     * Gets the days left before an rx expires
     * Calls instance of order items grouped method
     * @return float|int|null
     */
    public function getDaysLeftBeforeExpiration()
    {
        $grouped = $this->grouped;
        return $grouped->getDaysLeftBeforeExpiration();
    }

    /**
     * Determines the number of days left for the current refill
     * @return mixed
     */
    public function getDaysLeftInRefills()
    {
        $grouped = $this->grouped;
        return $grouped->getDaysLeftInRefills();
    }

    /**
     * Determines the number of days left in stock
     * Returns either 60.6 or `DAYS_MIN` of 45
     * @return float|int|void
     */
    public function getDaysLeftInStock()
    {
        $grouped = $this->grouped;
        return $grouped->getDaysLeftInRefills();
    }

    /**
     * Determines the number of default days to return for days_and_messages
     *
     * This is the MIN of ($days_left_in_refills, $days_left_in_stock). If either
     * value is 0, Use the DAY_STD in its place
     *
     * This is really more $days_to_fill.  It's not the default, but it's what
     * we've determined as the max amount we can fill
     * @return mixed
     */
    public function getDaysDefault()
    {
        $days_left_in_refills = $this->getDaysLeftInRefills();
        $days_left_in_stock = $this->getDaysLeftInStock();

        $days = min(
            $days_left_in_refills ?: DAYS_STD,
            $days_left_in_stock ?: DAYS_STD
        );
        //  This is used for generating logs. Skipping logging from class methods
        //$remainder = $days % DAYS_UNIT;

        return $days;
    }

    /**
     * Calculate the new refills_dispensed_default field for the gp_order_item
     *
     * This seems to only be used to save back to gp_order_items table in `helper_days_and_message`
     * This may work best as a mutator
     * @return float|int|mixed
     */
    public function calculateRefillsDispensedDefault()
    {
        //  Not sure if decimal 0.00 evaluates to falsy in PHP
        if ($this->qty_total <= 0)
        {
            return 0;
        }

        if ($this->refill_date_first)
        {
            $countOfRefills = $this->refills_total - ($this->days_dispensed_default === 0 ? 0 : 1);
            return max(0, $countOfRefills);
        }

        //  6028507 if Cindy hasn't adjusted the days/qty yet we need to calculate it ourselves
        if ($this->qty_dispensed_default)
        {
            return $this->refills_total * (1 - $this->qty_dispensed_default/$this->qty_total);
        }

        return $this->refills_total - ($this->item_date_added ? 1 : 0);
    }

    /*
        ## PENDING RELATED
     */

    /**
     * Pend the item in V2
     * @param  string  $reason Optional. The reason we are pending the Item.
     * @param  boolean $force  Optional. Force the pend to happen even if we think
     *      it is pended in goodpill.
     * @return array
     */
    public function doPendItem(string $reason = '', bool $force = false) : ?array
    {
        $legacy_item = $this->getLegacyData();
        return v2_pend_item($legacy_item, $reason, $force);
    }

    /**
     * Unpend the item in V2
     * @param string $reason Optional. The reason we are unpending the Item.
     * @return array
     */
    public function doUnpendItem(string $reason = '') : ?array
    {
        $legacy_item = $this->getLegacyData();
        return v2_pend_item($legacy_item, $reason);
    }

    /**
     * Query v2 to see if there is already a drug pended for this order.  If one is found, then we
     * will use it instead of getting one from v2
     * @return null|GoodPill\Models\v2\PickListDrug
     */
    public function getPickList() : ?PickListDrug
    {
        $order = $this->order;
        $pend_group = $order->pend_group;

        if (isset($this->pick_list)) {
            return $this->pick_list;
        }

        if (!$pend_group) {
            return null;
        }

        $this->pick_list = new PickListDrug($pend_group, $this->drug_generic);
        return $this->pick_list;
    }

    /**
     * A convenient way for setting the pickelist on an item.  This is here to overcome the
     * shortcomings of v2.  V2 fails to load on certain URLS, so this allows us to pass in the
     * calculated picklist
     *
     * @param GoodPil\Models\v2\PickListDrug $pick_list A fully loaded picklist to be used for decisions.
     * @return void
     */
    public function setPickList(PickListDrug $pick_list)
    {
        $this->pick_list = $pick_list;
    }

    /**
     * Look for a matching NDC in carepoint and update the RX with the new NDC
     * @param  null|string $ndc  If null, we will attempt to get the NDC for the pended data.
     * @param  null|string $gsns If null, we will use the gsn from the pended data.  If there isn't
     *      any pended data, we will use the gsns from the rxs.
     * @return boolean True if a ndc was found and the RX was updated
     */
    public function doUpdateCpWithNdc(?string $ndc = null, ?string $gsns = null) : bool
    {
        $found_ndc = $this->doSearchCpNdcs($ndc, $gsns);

        // We have an ndc so lets load the RX and lets update the comment
        if ($found_ndc) {
            $cprx = CpRx::where('script_no', $this->rx_number)->first();
            if ($cprx) {
                // Get the comments to see if there is an og_ndc.
                $gpComments = new GpComments($cprx->cmt);

                // // If there isn't move the current NDC to the og_ndc comment
                if (!isset($gpComments->og_ndc)) {
                    $gpComments->og_ndc = $cprx->ndc;
                }


                if (!isset($gpComments->selected_ndcs)) {
                    $gpComments->selected_ndcs = [];
                }

                if ($found_ndc->ndc != $cprx->ndc) {
                    $ndcs                      = $gpComments->selected_ndcs;
                    $ndcs[]                    = $found_ndc->ndc;
                    $gpComments->selected_ndcs = [$found_ndc->ndc];
                }

                $cprx->cmt = $gpComments->toString();

                // Update the current NDC
                if ($cprx->ndc != $found_ndc->ndc) {
                    $cprx->ndc = $found_ndc->ndc;
                    GPLog::warning(
                        "doUpdateCpWithNdc: We are changing the NDC for RX {$this->rx_number}",
                        [
                            "item" => $this->toArray(),
                            "old_ndc" => $cprx->ndc,
                            "new_ndc" => $found_ndc->ndc
                        ]
                    );
                }

                // Save the CpRx
                $cprx->save();
                return true;
            }
        }

        GPLog::warning(
            "doUpdateCpWithNdc: Could not find an NDC for RX# {$this->rx_number}",
            [
                "item" => $this->toArray()
            ]
        );

        // Load the NDC object and search to see if I can find the correct one
        // If I find one, update the RX to have the newly found NDC
        return false;
    }

    /**
     * Attempt to find a matching ndc in carepoint.  If no NDC or GSN is passed in it will Attempt
     *      to find reasonable replacements for the values.
     * @param  null|string $ndc  If null, we will attempt to get the NDC for the pended data.  If the
     *      item is not pended and $ndc is NULL we will assume there is no NDC to search for and return null.
     * @param  null|string $gsns If null, we will use the gsn from the pended data.  If there isn't
     *      any pended data, we will use the gsns from the rxs.
     * @return null|GoodPill\Models\Carepoint\CpFdrNdc
     */
    public function doSearchCpNdcs(?string $ndc = null, ?string $gsns = null) : ?CpFdrNdc
    {
        if (is_null($ndc)) {
            if (!$this->isPended()) {
                return null;
            }

            // Get the ndc or quit
            $ndc = $this->getPickList()->getNdc();

            if (is_null($ndc)) {
                return null;
            }
        }

        if (is_null($gsns)) {
            if ($this->isPended()) {
                $gsns = $this->getPickList()->getGsns();
            } else {
                $gsns = $this->rxs->drug_gsns;
            }
        }

        $gsns = explode(',', $gsns);

        return CpFdrNdc::doFindByNdcAndGsns($ndc, $gsns);
    }


    /*

        LEGACY DATA

        Work with legacy data structures
     */


    /**
     * Create a legacyPicklist item
     * @return array
     */
    public function doMakeLegacyPickList()
    {
        $item_legacy = $this->getLegacyData();
        $list = make_pick_list($item_legacy);
        return $list;
    }

    /**
     * Get the traditional item data
     * @return array
     */
    public function getLegacyData()
    {
        if ($this->exists) {
            return load_full_item(
                ['rx_number' => $this->rx_number ],
                (new \Mysql_Wc())
            );
        }

        return null;
    }

    /**
     * Get a legacy picklist item
     * @return array A legacy picklist array.
     *
     * @todo We need to change this so we use the getPickList and that PickList
     * @todo will make a new picklist for pending.
     */
    public function getLegacyPickList()
    {
        return make_pick_list(
            $this->getLegacyData()
        );
    }
}
