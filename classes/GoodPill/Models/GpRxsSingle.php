<?php

namespace GoodPill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use GoodPill\Models\GpDrugs;
use GoodPill\Models\GpPatient;
use GoodPill\Logging\GPLog;

/**
 * Class GpRxsSingle
 */
class GpRxsSingle extends Model
{

    use \GoodPill\Traits\IsChangeable;

    /**
     * The Table for this data
     * @var string
     */
    protected $table = 'gp_rxs_single';

    /**
     * The primary_key for this item
     * @var string
     */
    protected $primaryKey = 'rx_number';

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
        'rx_number'               => 'int',
        'patient_id_cp'           => 'int',
        'rx_gsn'                  => 'int',
        'refills_left'            => 'float',
        'refills_original'        => 'float',
        'qty_left'                => 'float',
        'qty_original'            => 'float',
        'sig_qty'                 => 'float',
        'sig_days'                => 'int',
        'sig_qty_per_day_default' => 'float',
        'sig_qty_per_day_actual'  => 'float',
        'rx_autofill'             => 'int',
        'rx_status'               => 'int'
    ];

    /**
     * Fields that should be dates when they are set
     * @var array
     */
    protected $dates = [
        'refill_date_first',
        'refill_date_last',
        'refill_date_manual',
        'refill_date_default',
        'rx_date_transferred',
        'rx_date_changed',
        'rx_date_expired'
    ];


    /**
     * Fields that represent database fields and
     * can be set via the fill method
     * @var array
     */
    protected $fillable = [
        'patient_id_cp',
        'drug_generic',
        'drug_brand',
        'drug_name',
        'rx_message_key',
        'rx_message_text',
        'rx_gsn',
        'drug_gsns',
        'refills_left',
        'refills_original',
        'qty_left',
        'qty_original',
        'sig_actual',
        'sig_initial',
        'sig_clean',
        'sig_qty',
        'sig_v1_qty',
        'sig_v1_days',
        'sig_days',
        'sig_qty_per_day_default',
        'sig_qty_per_day_actual',
        'sig_durations',
        'sig_qtys_per_time',
        'sig_frequencies',
        'sig_frequency_numerators',
        'sig_frequency_denominators',
        'sig_v2_qty',
        'sig_v2_days',
        'sig_v2_qty_per_day',
        'sig_v2_unit',
        'sig_v2_conf_score',
        'sig_v2_dosages',
        'sig_v2_scores',
        'sig_v2_frequencies',
        'sig_v2_durations',
        'rx_autofill',
        'refill_date_first',
        'refill_date_last',
        'refill_date_manual',
        'refill_date_default',
        'rx_status',
        'rx_stage',
        'rx_source',
        'rx_transfer',
        'rx_date_transferred',
        'provider_npi',
        'provider_first_name',
        'provider_last_name',
        'provider_clinic',
        'provider_phone',
        'rx_date_changed',
        'rx_date_expired'
    ];

    /**
     * Link to the GpPatient object on the patient_id_cp
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function patient()
    {
        return $this->belongsTo(GpPatient::class, 'patient_id_cp', 'patient_id_cp');
    }

    /**
     * Relationship to a stock item
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function stock()
    {
        return $this->hasOne(GpStockLive::class, 'drug_generic', 'drug_generic');
    }

    /**
     * Relationship to an order
     * There is no direct reference to an order from an rxs, so hasOneThrough is used
     * @return \Illuminate\Database\Eloquent\Relations\HasOneThrough
     */
    public function order()
    {
        return $this->hasOneThrough(GpOrder::class, GpOrderItem::class);
    }

    /**
     * Relationship to an order item
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function orderitem()
    {
        return $this->belongsTo(GpOrderItem::class, 'rx_number', 'rx_number');
    }

    /**
     * Loads the GpRxsGrouped data into an rxs single item
     * Because there is no traditional relationship that laravel can make, we have to pseudo-load the data
     * When inspecting an order item, if you fetch the rxs for that item you would need to call this function
     * to apply the grouped model into the rxs object
     *
     */
    public function grouped()
    {
        $this->grouped = GpRxsGrouped::where('rx_numbers', 'like', "%,{$this->rx_number},%")->first();
    }

    /**
     * Get the drug that is associated with this RX
     * @return \GoodPill\Models\GpDrugs|null
     */
    public function getDrugAttribute() : ?GpDrugs
    {
        if (!$this->rx_gsn) {
            return null;
        }

        return GpDrugs::where('drug_gsns', 'like', "%,{$this->rx_gsn},%")->first();
    }

    /**
     * Tries to load the drug attribute.  If it's not null then we have the drug
     * @return boolean True if we can load the drug via rx_gsn
     */
    public function isInFormulary() : bool
    {
        $drug = $this->drug;
        return (!is_null($drug));
    }

    /**
     * Does the item need to have it's drug_gsns updated
     * @return boolean True if the rx_gsn has changed or the drug_gsns field is empty
     */
    public function needsGsnUpdate() : bool
    {
        return (
            !empty($this->rx_gsn)
            && (
                ($this->hasGpChanges() && $this->hasFieldChanged('rx_gsn'))
                || empty($this->drug_gsns)
            )
        );
    }

    /**
     * Get the drug and copy the fields to the RX object
     * @return boolean True if the data was updated, False if the drug isn't in v2
     */
    public function updateDrugGsns() : bool
    {
        if (!$this->isInFormulary()) {
            return false;
        }

        $drug = $this->drug;

        if ($drug) {
            GPLog::warning(
                "GpRxSingle::updateDrugGsns() drug found and updating",
                [
                    "rx_number"     => $this->rx_number,
                    "patient_id_cp" => $this->patient_id_cp
                ]
            );

            $this->drug_generic = $drug->drug_generic;
            $this->drug_brand   = $drug->drug_brand;
            $this->drug_gsns    = $drug->drug_gsns;
            $this->save();
        } else {
            GPLog::warning(
                "GpRxSingle::updateDrugGsns() drug not found",
                [
                    "rx_number"     => $this->rx_number,
                    "patient_id_cp" => $this->patient_id_cp,
                    "rx_gsn"        => $this->rx_gsn
                ]
            );
        }

        // If we don't need to update the drug_gsns then the operatino was a success
        return (!$this->needsGsnUpdate());
    }
}
