<?php

/**
 * Created by Reliese Model.
 */

namespace GoodPill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use GoodPill\Models\GpPatient;
use GoodPill\Models\GpOrderItem;
use GoodPill\Events\Order\Shipped;

require_once "helpers/helper_full_order.php";

/**
 * Class GpOrder
 *
 * @property int $invoice_number
 * @property int|null $patient_id_cp
 * @property int|null $patient_id_wc
 * @property int $count_items
 * @property int|null $count_filled
 * @property int|null $count_nofill
 * @property string|null $order_source
 * @property string|null $order_stage_cp
 * @property string|null $order_stage_wc
 * @property string|null $order_status
 * @property string|null $invoice_doc_id
 * @property string|null $order_address1
 * @property string|null $order_address2
 * @property string|null $order_city
 * @property string|null $order_state
 * @property string|null $order_zip
 * @property string|null $tracking_number
 * @property Carbon|null $order_date_added
 * @property Carbon|null $order_date_changed
 * @property Carbon $order_date_updated
 * @property Carbon|null $order_date_dispensed
 * @property Carbon|null $order_date_shipped
 * @property Carbon|null $order_date_returned
 * @property int|null $payment_total_default
 * @property int|null $payment_total_actual
 * @property int|null $payment_fee_default
 * @property int|null $payment_fee_actual
 * @property int|null $payment_due_default
 * @property int|null $payment_due_actual
 * @property string|null $payment_date_autopay
 * @property string|null $payment_method_actual
 * @property string|null $coupon_lines
 * @property string|null $order_note
 *
 * @package App\Models
 */
class GpOrder extends Model
{
    /**
     * The Table for this data
     * @var string
     */
    protected $table = 'gp_orders';

    /**
     * The primary_key for this item
     * @var string
     */
    protected $primaryKey = 'invoice_number';

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
        'invoice_number'        => 'int',
        'patient_id_cp'         => 'int',
        'patient_id_wc'         => 'int',
        'count_items'           => 'int',
        'count_filled'          => 'int',
        'count_nofill'          => 'int',
        'payment_total_default' => 'int',
        'payment_total_actual'  => 'int',
        'payment_fee_default'   => 'int',
        'payment_fee_actual'    => 'int',
        'payment_due_default'   => 'int',
        'payment_due_actual'    => 'int'
    ];

    /**
     * Fields that hold dates
     * @var array
     */
    protected $dates = [
        'order_date_added',
        'order_date_changed',
        'order_date_updated',
        'order_date_dispensed',
        'order_date_shipped',
        'order_date_returned'
    ];

    /**
     * Fields that represent database fields and
     * can be set via the fill method
     * @var array
     */
    protected $fillable = [
        'patient_id_cp',
        'patient_id_wc',
        'count_items',
        'count_filled',
        'count_nofill',
        'order_source',
        'order_stage_cp',
        'order_stage_wc',
        'order_status',
        'invoice_doc_id',
        'order_address1',
        'order_address2',
        'order_city',
        'order_state',
        'order_zip',
        'tracking_number',
        'order_date_added',
        'order_date_changed',
        'order_date_updated',
        'order_date_dispensed',
        'order_date_shipped',
        'order_date_returned',
        'payment_total_default',
        'payment_total_actual',
        'payment_fee_default',
        'payment_fee_actual',
        'payment_due_default',
        'payment_due_actual',
        'payment_date_autopay',
        'payment_method_actual',
        'coupon_lines',
        'order_note'
    ];

    /*
     *
     * Relationships
     *
     */

     /**
      * Link to the GpPatient object on the patient_id_cp
      * @return Collection
      */
    public function patient()
    {
        return $this->belongsTo(GpPatient::class, 'patient_id_cp');
    }

    /**
     * Link the the GpOrderItems object on the invoice_number and sort newest to oldest
     * @return Collection
     */
    public function items()
    {
        return $this->hasMany(GpOrderItem::class, 'invoice_number', 'invoice_number')
                    ->orderBy('invoice_number', 'desc');
    }

    public function getItems(?bool $filled = null)
    {
        if (is_null($filled)) {
            return $this->items();
        }

        if ($filled) {
            return $this->items()->whereNotNull('rx_dispensed_id');
        }

        return $this->items()->whereNull('rx_dispensed_id');
    }

    /*
     * Condition Methods:  These methods are all meant to be conditional and should
     *  all return booleans.  The methods should be named with appropriate descriptive verbs
     *  ie: isShipped()
     *      hasItems()
     */

     /**
      * Has the order been marked as shipped
      *  An order will be considered shipped if it
      *     Exist in the Database
      *     AND Has a Shipped Date in the database
      *     AND (
      *         The Shipped Date is more than 12 hours Ago
      *         OR (
      *             There is a tracking number
      *             AND the Shipped Date is more than 10 minutes ago
      *         )
      *      )
      *
      * @return bool true if there is a shipdate
      */
    public function isShipped() : bool
    {
         // We add a 12 hour padding to the order_date_shipped incase they
         // make changes before it leaves the office
         return (
             $this->exists
             && !empty($this->order_date_shipped)
             && (
                 strtotime($this->order_date_shipped) + (60 * 60 * 12) > time()
                 || (
                     isset($this->tracking_number)
                     // Add a 10 minute window just so things that happen on
                     // the same sync execution don't throw an error
                     && strtotime($this->order_date_shipped) + (60 * 10) > time()
                 )
             )
         );
     }

     /**
      * Has the order been dispensed
      * An order will be considered dispensed if it
      *     Exists in the Database
      *     AND There is a dispensed date for the order
      * @return bool [description]
      */
     public function isDispensed() : bool
     {
         return ($this->exists && !empty($this->order_date_dispensed));
     }


    /*
     * Other Methods
     */

    public function markShipped($ship_date, $tracking_number) {
        // See if carepoint has a shipping record,
        // If it does, check to make sure the shipping record matches the details provided,
        // If not update the shipping record

        // Create Calendar Events
        // Create Events
    }


    /**
     * Get to old order array
     * @return null|array
     */
    public function getLegacyOrder() : ?array
    {
        if ($this->exists) {
            return load_full_order(
                ['invoice_number' => $this->invoice_number ],
                (new \Mysql_Wc())
            );
        }

        return null;
    }
}
