<?php

namespace GoodPill\Models;

use Carbon\Carbon;
use GoodPill\Models\Carepoint\CpPat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use GoodPill\Models\GpOrder;
use GoodPill\Logging\GPLog;
use GoodPill\Logging\CliLog;
use GoodPill\Models\WordPress\WpUser;
use GoodPill\Events\Event;

// Needed for cancel_events_by_person
require_once "helpers/helper_calendar.php";
require_once "helpers/helper_full_patient.php";
require_once "exports/export_cp_patients.php";
require_once "dbs/mssql_cp.php";
require_once "dbs/mysql_wc.php";

/**
 * Class GpPatient  This is the central point of accessing patient data.  It should be used to
 * get specific data from Carepoint, Goodpill and Wordpress tables
 */
class GpPatient extends Model
{
    // Used the changable to track changes from the system
    use \GoodPill\Traits\IsChangeable;

    /**
     * The Table for this data
     * @var string
     */
    protected $table = 'gp_patients';

    /**
     * The primary_key for this item
     * @var string
     */
    protected $primaryKey = 'patient_id_cp';

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
        'patient_id_cp'    => 'int',
        'patient_id_wc'    => 'int',
        'patient_autofill' => 'int',
        'refills_used'     => 'float'
    ];

    /**
     * Fields that hold dates
     * @var array
     */
    protected $dates = [
        'payment_card_date_expired',
        'patient_date_added',
        'patient_date_registered',
        'patient_date_changed',
        'patient_date_updated'
    ];

    /**
     * Fields that represent database fields and
     * can be set via the fill method
     * @var array
     */
    protected $fillable = [
        'patient_id_wc',
        'first_name',
        'last_name',
        'birth_date',
        'patient_note',
        'phone1',
        'phone2',
        'email',
        'patient_autofill',
        'pharmacy_name',
        'pharmacy_npi',
        'pharmacy_fax',
        'pharmacy_phone',
        'pharmacy_address',
        'payment_card_type',
        'payment_card_last4',
        'payment_card_date_expired',
        'payment_method_default',
        'payment_coupon',
        'tracking_coupon',
        'patient_address1',
        'patient_address2',
        'patient_city',
        'patient_state',
        'patient_zip',
        'refills_used',
        'language',
        'allergies_none',
        'allergies_cephalosporins',
        'allergies_sulfa',
        'allergies_aspirin',
        'allergies_penicillin',
        'allergies_erythromycin',
        'allergies_codeine',
        'allergies_nsaids',
        'allergies_salicylates',
        'allergies_azithromycin',
        'allergies_amoxicillin',
        'allergies_tetracycline',
        'allergies_other',
        'medications_other',
        'patient_date_added',
        'patient_date_registered',
        'patient_date_changed',
        'patient_date_updated',
        'patient_inactive'
    ];

    /*
     * Relationships
     */

    /**
     * Relationship to the goodpill.gp_orders table
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function orders()
    {
        return $this->hasMany(GpOrder::class, 'patient_id_cp', 'patient_id_cp')
                    ->orderBy('invoice_number', 'desc');
    }

    /**
     * Relationship to the goodpill.wp_users table
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function wcUser()
    {
        return $this->hasOne(WpUser::class, 'ID', 'patient_id_wc');
    }

    /**
     * Relationship to the cph.cppat table
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function cpPat()
    {
        return $this->hasOne(CpPat::class, 'pat_id', 'patient_id_cp');
    }

    public function groupedRxs()
    {
        return $this->hasMany(GpRxsGrouped::class, 'patient_id_cp', 'patient_id_cp');
    }

    /*
     * Mutators
     */

    /**
     * Make sure the last name is always UPPERCASE
     * @param string $name The last name of the patient.
     * @return void
     */
    public function setLastName(string $name) : void
    {
        $this->attributes['last_name'] = strtoupper($name);
    }


    /*
        Conditionals
     */

    /**
     * Test to see if the patient has both wc and cp ids
     * @return boolean
     */
    public function isMatched() : bool
    {
        return ($this->exists
                && !empty($this->patient_id_cp)
                && !empty($this->patient_id_wc));
    }

    /**
     * Has the addres changed
     * @return boolean  True if some of the address fields are different
     */
    public function hasAddressChanged() : bool
    {
        return $this->hasAnyFieldChanged(
            [
                'patient_address1',
                'patient_address2',
                'patient_state',
                'patient_zip'
            ]
        );
    }

    /**
     * Is the new address valid?
     * @return boolean  True if the zip is the wrong length, the state is the wrong length or any of
     */
    public function newAddressInvalid() : bool
    {
        if (!$this->hasAnyFieldChanged()) {
            return false;
        }

        return (
            empty($this->patient_address1)
            || empty($this->patient_city)
            || (
                empty($this->patient_address2)
                && !empty($this->oldValue('patient_address2'))
            )
            || (
                strlen($this->patient_state) != 2
                && strlen($this->oldValue('patient_state')) == 2
            )
            || (
                strlen($this->patient_zip) != 5
                && strlen($this->oldValue('patient_zip')) == 5
            )
        );
    }

    /**
     * Has one of the fields that make up the patient label been changed
     * @return boolean
     */
    public function hasLabelChanged() : bool
    {
        return $this->hasAnyFieldChanged(
            [
                'first_name',
                'last_name',
                'birth_date'
            ]
        );
    }

    /**
     * Update Comm Calendar event
     * @param  string $type   The type of event.
     * @param  string $change What we are changing.
     * @param  mixed  $value  The new value.
     * @return void
     */
    public function updateEvents(string $type, string $change, $value) : void
    {
        switch ($type) {
            case 'Autopay Reminder':
                if ($change = 'last4') {
                    update_last4_in_autopay_reminders(
                        $this->first_name,
                        $this->last_name,
                        $this->birth_date,
                        $value
                    );
                }
                break;
        }
    }

    /**
     * Cancel the comm calendar events
     * @param  array $events The type of events to Cancel.
     * @return void
     */
    public function cancelEvents(?array $events = []) : void
    {
        cancel_events_by_person(
            $this->first_name,
            $this->last_name,
            $this->birth_date,
            'Log should be above',
            $events
        );
    }

    /**
     * Create a comm calendar event tied to this user
     * @param  GoodPill\Events\Event $event An object of the event class.
     * @return void
     */
    public function createEvent(Event $event) : void
    {
        $event->patient_label = $this->getPatientLabel();
        $event->publishEvent();
    }

    /**
     * print the patient label.
     * @return string
     */
    public function getPatientLabel() : string
    {
        return sprintf(
            "%s %s %s",
            $this->first_name,
            $this->last_name,
            $this->birth_date
        );
    }


    /**
     * Update the Active status for the user.  Active status is an item that is set in woocomerce
     * to identify an active, inactive or deceased user
     * @return boolean
     */
    public function updateWcActiveStatus() : bool
    {
        GPLog::debug(
            sprintf(
                "Setting patient %s patient_inactive status to %s on WordPress User %s",
                $this->patient_id_cp,
                $this->patient_inactive,
                $this->patient_id_wc
            ),
            ['patient_id_cp' => $this->patient_id_cp]
        );

        switch (strtolower($this->patient_inactive)) {
            case 'inactive':
                $wc_status = 'a:1:{s:8:"inactive";b:1;}';
                break;
            case 'deceased':
                $wc_status = 'a:1:{s:8:"deceased";b:1;}';
                break;
            default:
                $wc_status = 'a:1:{s:8:"customer";b:1;}';
        }

        return $this->updateWpMeta('wp_capabilities', $wc_status);
    }

    /**
     * Upsert a meta value in WooCommerce
     *
     * @param  string $key   The meta key.
     * @param  mixed  $value The value to store.
     * @return boolean
     */
    public function updateWpMeta(string $key, $value) : bool
    {

        $wcuser = $this->wcUser;

        if ($wcuser) {
            $meta = $wcuser->meta()->firstOrNew(['meta_key' => $key]);
            $meta->meta_value = $value;
            return $meta->save();
        }

        return false;
    }

    /**
     * Delete a phone number from Carepoint
     *
     * @todo this needs to be converted to OO based when a carepoint object is created
     *
     * @param  integer $phone_type One of the applicable phone number type IDS in carepoint.
     * @return mixed
     */
    public function deletePhoneFromCarepoint(int $phone_type)
    {
        if ($this->exists) {
            return delete_cp_phone(
                new Mssql_Cp(),
                $this->patient_id_cp,
                $phone_type
            );
        }

        return null;
    }

    /**
     * Will recalculate the RX messages.  Current does this be getting the legacy patient
     * with overwrite set to true.  Does not return the full patient
     *
     * @todo Modify the logic to actually handle the individual RX without using the
     *       legacy functions
     *
     * @return void
     */
    public function recalculateRxMessages()
    {
        $this->getLegacyPatient(true);
    }

    /**
     * Create a comma seperated string of available phone numbers
     * @return string
     */
    public function getPhonesAsString() : string
    {
        return implode(
            ',',
            array_filter(
                [
                    $this->phone1,
                    $this->phone2
                ]
            )
        );
    }

    /**
     * Get a full version of the legacy patient data structure
     * @param  boolean $overwrite_rx_messages Should the RX messages be updated.
     * @return array
     */
    public function getLegacyPatient(bool $overwrite_rx_messages = false)
    {
        if ($this->exists) {
            return load_full_patient(
                ['patient_id_cp' => $this->patient_id_cp],
                (new \Mysql_Wc()),
                $overwrite_rx_messages
            );
        }

        return null;
    }
}
