<?php

namespace GoodPill\Models;

use Carbon\Carbon;
use GoodPill\Models\GpDrugs;
use GoodPill\Models\GpPatient;
use Illuminate\Database\Eloquent\Model;
use GoodPill\Logging\GPLog;

/**
 * Class GpRxsGrouped
 *
 * @property int $patient_id_cp
 * @property string|null $drug_generic
 * @property string|null $drug_brand
 * @property string $drug_name
 * @property float|null $sig_qty_per_day
 * @property string|null $rx_message_keys
 * @property int|null $max_gsn
 * @property string|null $drug_gsns
 * @property float $refills_total
 * @property float $qty_total
 * @property int $rx_autofill
 * @property Carbon|null $refill_date_first
 * @property Carbon|null $refill_date_last
 * @property Carbon|null $refill_date_next
 * @property Carbon|null $refill_date_manual
 * @property int $best_rx_number
 * @property string $rx_numbers
 * @property string $rx_sources
 * @property Carbon|null $rx_date_changed
 * @property Carbon|null $rx_date_expired
 * @property Carbon|null $rx_date_transferred
 *
 * @package App\Models
 */
class GpRxsGrouped extends Model implements \DaysMessageInterface
{

    /**
     * The Table for this data
     * @var string
     */
    protected $table = 'gp_rxs_grouped';

    /**
     * The primary_key for this item
     * @var null
     */
    protected $primaryKey = null;

    /**
     * Does the database contain an incrementing field?
     * @var boolean
     */
    public $incrementing = false;

    /**
     * Does the database contain timestamp fields
     * @var boolean
     */
    public $timestamps = false;

    /**
     * Fields that should be cast when they are set
     * @var array
     */
    protected $casts = [
        'patient_id_cp' => 'int',
        'sig_qty_per_day' => 'float',
        'max_gsn' => 'int',
        'refills_total' => 'float',
        'qty_total' => 'float',
        'rx_autofill' => 'int',
        'best_rx_number' => 'int',
    ];

    /**
     * Fields that should be dates when they are set
     * @var array
     */
    protected $dates = [
        'refill_date_first',
        'refill_date_last',
        'refill_date_next',
        'refill_date_manual',
        'refill_date_default',
        'rx_date_transferred',
        'rx_date_changed',
        'rx_date_expired',
        'rx_date_transferred',
    ];


    /**
     * Fields that represent database fields and
     * can be set via the fill method
     * @var array
     */
    protected $fillable = [];

    /**
     * Relationships
     */

    /**
     * Relationship to a patient
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function patient()
    {
        return $this->belongsTo(GpPatient::class, 'patient_id_cp', 'patient_id_cp');
    }

    /**
     * Relationship to Gp Stock Live
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function stock()
    {
        return $this->hasOne(GpStockLive::class, 'drug_generic', 'drug_generic');
    }

    /**
     * Relationship to Gp Rxs Single
     * Matches on `best_rx_number`, should be careful when using this
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function rxs()
    {
        return $this->hasOne(GpRxsSingle::class, 'rx_number', 'best_rxs_number');
    }

    /**
     * Checks to see if the grouped item is in an order
     * @param $rx_number
     * @return bool
     */
    public function findRxNumber($rx_number) : bool
    {
        $found = strpos($this->rx_numbers,",$rx_number,");
        if ($found === false) {
            return false;
        }

        return true;
    }


    /*
        ## CONDITIONALS
     */

    /**
     * Checks an order to see if the grouped item already exists in it
     * @param GpOrder $order
     * @return bool
     */
    public function isInOrder(GpOrder $order) : bool {
        $orderItems = $order->items;
        $found = $orderItems->filter(function($orderItem) {
            $is_found = $this->findRxNumber($orderItem->rx_number);
            if ($is_found === true) {
                return true;
            } else {
                return false;
            }
        });

        if ($found->count() > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks of item was manually added to order
     * Returns false because an rxs grouped has no order
     *
     * @return bool
     */
    public function isAddedManually(): bool
    {
        return false;
    }

    /**
     * Check if the item came from a webform transfer of some kind
     *
     * This is false because an rxs_grouped is not tied to an order
     * Would be better to have a check to see if is in order and then use
     * the order_item's `isWebform` method instead
     * @return bool
     */
    public function isWebform() : bool
    {
        return false;
    }

    /**
     * Checks that item has refills available
     * @return bool
     */
    public function hasRefills() : bool
    {
        return $this->refills_total > NO_REFILL;
    }

    /**
     * Checks to see if the stock price is high
     * @return bool
     */
    public function isHighPrice() : bool
    {
        $price_per_month = $this->stock->price_per_month;

        return $price_per_month >= 20;
    }

    /**
     * Determines if we should not transfer out items
     * Checks for a high price or to see if the patient's backup pharmacy is us
     *
     * @return bool
     */
    public function isNoTransfer() : bool
    {
        return $this->isHighPrice() || $this->patient->pharmacy_phone === '8889875187';
    }

    /**
     * GpRxsGrouped
     * @TODO - Decide what to do with this and corresponding item function
     *
     * Determine by the stock level if the item is offered or not
     * @return bool
     */
    public function isNotOffered() : bool
    {
        $rxs = $this->rxs;
        $stock = $this->stock;

        $rx_gsn = $rxs->rx_gsn;
        $drug_name = $rxs->drug_name;

        //  For a GpRxsGrouped, it may not relate to an order item,
        // default to the stock_level set on GpStockLive
        $stock_level = $stock->stock_level;


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
     * Check if the item is set to only refill for the order
     * @return bool
     */
    public function isRefillOnly() : bool
    {
        $stock_level = $this->stock->stock_level;
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
     * @TODO - Decide what to do with this and corresponding item function
     *
     * @return bool
     */
    public function isOneTime() : bool
    {
        $stock_level = $this->stock->stock_level;
        return in_array(
            $stock_level,
            [
                STOCK_LEVEL['ONE TIME']
            ]
        );
    }

    /**
     * Determines if the rx was ever parsed
     * This is based on there being on sig_qty_per_day_default
     * Rxs single will have mismatched refills original vs refills left
     *
     * @return bool
     */
    public function isNotRxParsed() : bool
    {
        if (
            !$this->rxs->sig_qty_per_day_default &&
            $this->rxs->refills_original != $this->rxs->refills_left
        ) {
            return true;
        }
        return false;
    }

    /**
     * Gets the days left before an rx expires
     * @return float|int|null
     */
    public function getDaysLeftBeforeExpiration()
    {
        $rxs = $this->rxs;
        //Usually don't like using time() because it can change, but in this case once it is marked as expired it will always be expired so there is no variability
        $comparison_date = $this->refill_date_next ? strtotime($this->refill_date_next) : time();

        $days_left_in_expiration = (strtotime($this->rx_date_expired) - $comparison_date)/60/60/24;

        //#29005 was expired but never dispensed, so check "refill_date_first" so we asking doctors for new rxs that we never dispensed
        if ($this->refill_date_first) {
            return $days_left_in_expiration;
        }

        return null;
    }

    /**
     * Checks to make sure sig qty is set and reasonable
     * If the qty per day > 10 we think that the sig parser might be off
     * @return bool
     */
    public function isSigParsingVerified() : bool
    {
        if (
            !$this->sig_qty_per_day ||
            $this->sig_qty_per_day > 10
        ) {
            return false;
        }

        return true;
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

    /**
     * Determines the number of days left for the current rx refill
     * @return float|int|null
     */
    public function getDaysLeftInRefills()
    {
        if (!$this->isSigParsingVerified())
        {
            return null;
        }

        //Uncomment the line below if we are okay dispensign 2 bottles/rxs.  For now, we will just fill the most we can do with one Rx.
        //if ($item['refills_total'] != $item['refills_left']) return; //Just because we are out of refills on this script doesn't mean there isn't another script with refills

        $days_left_in_refills = $this->rxs->qty_left / $this->sig_qty_per_day;

        //Fill up to 30 days more to finish up an Rx if almost finished.
        //E.g., If 30 day script with 3 refills (4 fills total, 120 days total) then we want to 1x 120 and not 1x 90 + 1x30
        if ($days_left_in_refills <= DAYS_MAX) {
            return $this->roundDaysUnit($days_left_in_refills);
        }

        return null;
    }

    /**
     * Determines the number of days left in stock
     * Returns either 60.6 or `DAYS_MIN` of 45
     * @return float|int|void
     */
    public function getDaysLeftInStock()
    {
        if (!$this->isSigParsingVerified())
        {
            return null;
        }

        $live_stock = $this->stock;

        $days_left_in_stock = round($live_stock->last_inventory / $this->sig_qty_per_day);

        if (
            $days_left_in_stock >= DAYS_STD ||
            $live_stock->last_inventory >= 3 *$live_stock->qty_repack
        ) {
            return null;
        }

        //Dispensed 2 inhalers per time, since 1/30 is rounded to 3 decimals (.033), 2 month/.033 = 60.6 qty
        return $this->rxs->sig_qty_per_day_default === round(1/30, 3) ? 60.6 : DAYS_MIN;
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
     * Gets the date that the prescription was originally written
     * @return false|int
     */
    public function getRxDateWrittenAttribute()
    {
        return  strtotime($this->rx_date_expired . ' -1 year');
    }

    /**
     * Alias for rx_date_written when called on an rxs_grouped entity
     * @return mixed
     */
    public function getDateAddedAttribute()
    {
        return $this->rx_date_written;
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
        return strtotime($this->date_added) - strtotime($this->refill_date_last);
    }

    /**
     * Returns the rounded number of days
     * @param $days
     * @return float|int
     */
    public function roundDaysUnit($days)
    {
        //+.1 because we had 18qty with .201 sig_qty_per_day_default which gave floor(5.97) -> 75 days instead of 90
        //Bactrim with 6 qty and 2.0 sig_qty_per_day_default which gave floor(6/2/15) -> 0 days
        return $days < DAYS_UNIT ? $days : floor($days/DAYS_UNIT+.1)*DAYS_UNIT; //+.1 because we had 18qty with .201 sig_qty_per_day_default which gave floor(5.97) -> 75 days instead of 90
    }
}
