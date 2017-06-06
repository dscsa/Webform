<?php //For IDE styling only

//TODO
//RUN REVISED CHRIS SCRIPTS
//CALL DB FUNCTIONS AT RIGHT PLACES
//MAKE ALLERGY LIST MATCH GUARDIAN

// Register custom style sheets and javascript.
add_action( 'wp_enqueue_scripts', 'register_custom_plugin_styles' );
function register_custom_plugin_styles() {
    wp_enqueue_style('account', 'https://dscsa.github.io/webform/woocommerce/account.css');
    wp_enqueue_style('checkout', 'https://dscsa.github.io/webform/woocommerce/checkout.css');
    wp_enqueue_style('storefront', 'https://dscsa.github.io/webform/woocommerce/storefront.css');
    wp_enqueue_style('select', 'https://dscsa.github.io/webform/woocommerce/select2.css');

    wp_enqueue_script('order', 'https://dscsa.github.io/webform/woocommerce/checkout.js', ['jquery']);
}

function order_fields() {
  return [
    'source_english' => [
      'type'   	  => 'select',
      'required'  => true,
      'class'     => ['english'],
      'options'   => [
          'erx'   => 'Prescription(s) were sent to Good Pill from my doctor',
          'pharmacy' => 'Please transfer prescription(s) from my pharmacy'
      ]
    ],
    'source_spanish' => [
      'type'   	  => 'select',
      'required'  => true,
      'class'     => ['spanish'],
      'options'   => [
          'erx'   => 'Spanish Source eRx',
          'pharmacy' => 'Spanish Source Pharmacy'
      ]
    ],
    'medication'  => [
      'type'   	  => 'select',
      'label'     => __('Search and select medications by generic name that you want to transfer to Good Pill'),
      'options'   => ['']
    ]
  ];
}

function patient_fields() {
    //https://docs.woocommerce.com/wc-apidocs/source-function-woocommerce_form_field.html#1841-2061
    $user_id = get_current_user_id();

    return [
    'account_language' => [
        'type'   	  => 'radio',
        'label'     => __('Language'),
        'label_class' => ['radio'],
        'required'  => true,
        'options'   => ['english' => 'English', 'spanish' => __('Spanish')],
        'default'   => get_user_meta($user_id, 'account_language', true) ?: 'english'
    ],
    'account_backup_pharmacy' => [
        'type'   	  => 'select',
        'label'     => __('<span class="erx">Name and address of a backup pharmacy to fill your prescriptions if we are out-of-stock</span><span class="pharmacy">Name and address of pharmacy from which we should transfer your medication(s)</span>'),
        'required'  => true,
        'options'   => [''],
        'default'   => get_user_meta($user_id, 'account_backup_pharmacy', true)
    ],
    'account_medications_other' => [
        'label'     =>  __('List any other medication(s) or supplement(s) you are currently taking'),
        'default'   => get_user_meta($user_id, 'account_medications_other', true)
    ],
    'account_allergies' => [
        'type'   	  => 'radio',
        'label'     => __('Allergies'),
        'label_class' => ['radio'],
        'required'  => true,
        'default'   => 'Yes',
        'options'   => ['Yes' => __('Allergies Selected Below'), 'No' => __('No Medication Allergies')],
    	  'default'   => get_user_meta($user_id, 'account_allergies_english', true)
    ],
    'account_allergies_aspirin_salicylates' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('Aspirin and salicylates'),
        'default'   => get_user_meta($user_id, 'account_allergies_aspirin_salicylates', true)
    ],
    'account_allergies_erythromycin_biaxin_zithromax' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('Erythromycin, Biaxin, Zithromax'),
        'default'   => get_user_meta($user_id, 'account_allergies_erythromycin_biaxin_zithromax', true)
    ],
    'account_allergies_nsaids' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('NSAIDS e.g., ibuprofen, Advil'),
        'default'   => get_user_meta($user_id, 'account_allergies_nsaids', true)
    ],
    'account_allergies_penicillins_cephalosporins' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('Penicillins/cephalosporins e.g., Amoxil, amoxicillin, ampicillin, Keflex, cephalexin'),
        'default'   => get_user_meta($user_id, 'account_allergies_penicillins_cephalosporins', true)
    ],
    'account_allergies_sulfa' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('Sulfa drugs e.g., Septra, Bactrim, TMP/SMX'),
        'default'   => get_user_meta($user_id, 'account_allergies_sulfa', true)
    ],
    'account_allergies_tetracycline' => [
        'type'      => 'checkbox',
        'class'     => ['form-row-wide'],
        'label'     => __('Tetracycline antibiotics'),
        'default'   => get_user_meta($user_id, 'account_allergies_tetracycline', true)
    ],
    'account_allergies_other_checkbox' => [
        'type'      => 'checkbox',
        'label'     =>__( 'Other Allergies').'<input class="input-text " name="account_allergies_other" id="account_allergies_other" value="'.get_user_meta($user_id, 'account_allergies_other', true).'">'
    ],
    'account_birth_date' => [
        'label'     => __('Date of Birth'),
        'required'  => true,
        'default'   => get_user_meta($user_id, 'account_birth_date', true)
    ],
    'account_phone' => [
        'label'     => __('Phone'),
       	'required'  => true,
        'type'      => 'tel',
        'validate'  => ['phone'],
        'autocomplete' => 'tel',
        'default'   => get_user_meta($user_id, 'account_phone', true)
    ]
  ];
}

//Display custom fields on account/details
add_action('woocommerce_edit_account_form_start', 'custom_edit_account_form');
function custom_edit_account_form() {

  foreach (patient_fields() as $key => $field) {
    if ($key === "account_backup_pharmacy") {
      $field['options'] = [$field['default'] => $field['default']];
    }

    echo woocommerce_form_field($key, $field);
  }
}

add_action('woocommerce_register_form_start', 'custom_login_form');
function custom_login_form() {
	$patient_fields = patient_fields();

  $first_name = [
    'class' => ['form-row-first'],
    'label'  => __('First name')
  ];

  $last_name = [
    'class' => ['form-row-last'],
    'label'  => __('Last name')
  ];

  echo woocommerce_form_field('account_language', $patient_fields['account_language']);
	echo woocommerce_form_field('account_first_name', $first_name);
  echo woocommerce_form_field('account_last_name', $last_name);
	echo woocommerce_form_field('account_birth_date', $patient_fields['account_birth_date']);
}

//After Registration, set default shipping/billing/account fields
//then save the user into GuardianRx
add_action('woocommerce_created_customer', 'customer_created');
function customer_created($user_id) {
  $first_name = sanitize_text_field($_POST['account_first_name']);
  $last_name = sanitize_text_field($_POST['account_last_name']);
  foreach(['', 'billing_', 'shipping_'] as $field) {
  	update_user_meta($user_id, $field.'first_name', $first_name);
      update_user_meta($user_id, $field.'last_name', $last_name);
  }
  update_user_meta($user_id, 'account_birth_date', $_POST['account_birth_date']);
  update_user_meta($user_id, 'account_language', $_POST['account_language']);

  //Run Guardian addEditPatient()
}

// Function to change email address
add_filter('wp_mail_from', 'email_address');
function email_address() {
    return 'rx@goodpill.org';
}
add_filter('wp_mail_from_name', 'email_name');
function email_name() {
	return 'Good Pill Pharmacy';
}

// After registration and login redirect user to account/orders.
// Clicking on Dashboard/New Order in Nave will add the actual product
add_action('woocommerce_registration_redirect', 'custom_redirect', 2);
add_action('woocommerce_login_redirect', 'custom_redirect', 2);
function custom_redirect() {
    return home_url('/account/orders');
}

add_filter ('woocommerce_account_menu_items', 'custom_my_account_menu');
function custom_my_account_menu($nav) {

  //Clicking on Dashboard/New Order actually adds the product.
  //Hash is necessary to prevent the trailing slash to ignore query
  $new = ['?add-to-cart=30#' => __('New Order')];

  //Preserve order otherwise new link is at the bottom of menu
  foreach ($nav as $key => $val) {
      if ($key != 'dashboard')
          $new[$key] = $val;
  }

  $new['edit-account'] = __('Account Details');
  $new['edit-address'] = __('Address & Payment');

  return $new;
}

//On new order and account/details save account fields back to user
//TODO should changing checkout fields overwrite account fields if they are set?
add_action('woocommerce_save_account_details', 'save_custom_fields_to_user' );
add_action('woocommerce_checkout_update_user_meta', 'save_custom_fields_to_user');
function save_custom_fields_to_user( $user_id) {

  wp_mail('adam.kircher@gmail.com', 'save_custom_fields_to_user', $user_id.' '.print_r($_POST, true));

  foreach (patient_fields() as $key => $field) {
  	update_user_meta( $user_id, $key, sanitize_text_field($_POST[$key]));
  }

  //Run Guardian update shipping address, allergies, etc
}

//Save Billing info to Guardian
add_action('updated_user_meta', 'custom_updated_user_meta', 10, 4);
function custom_updated_user_meta($meta_id, $post_id, $meta_key, $meta_value)
{
  if ($meta_key != '_trustcommerce_customer_id') return;

  wp_mail('adam.kircher@gmail.com', 'updated_post_meta', print_r([$meta_id, $post_id, $meta_key, $meta_value], true));
}

//Didn't work: https://stackoverflow.com/questions/38395784/woocommerce-overriding-billing-state-and-post-code-on-existing-checkout-fields
//Did work: https://stackoverflow.com/questions/36619793/cant-change-postcode-zip-field-label-in-woocommerce
add_filter('ngettext', 'custom_translate');
add_filter('gettext', 'custom_translate');
function custom_translate($term) {

  $toEnglish = [
    'Spanish'  => 'Espanol',
    'Username or email address' => 'Email Address',
    'From your account dashboard you can view your <a href="%1$s">recent orders</a>, manage your <a href="%2$s">shipping and billing addresses</a> and <a href="%3$s">edit your password and account details</a>.' => '',
    'ZIP' => 'Zip code',
    'Your order' => '',
    '%s has been added to your cart.' => 'Fill out the form below to place a new order'
  ];

  $toSpanish = [
    'Language' => 'Lingua',
    'Use a new credit card' => 'Spanish Use a new credit card',
    'Place New Order' => 'Order Nueva',
    'Billing details' => 'La Cuenta Por Favor',
    'Ship to a different address?' => 'Spanish Ship to a different address?',
    'Search and select medications by generic name that you want to transfer to Good Pill' => 'Drogas de Transfer',
    '<span class="erx">Name and address of a backup pharmacy to fill your prescriptions if we are out-of-stock</span><span class="pharmacy">Name and address of pharmacy from which we should transfer your medication(s)</span>' => '<span class="erx">Pharmacia de out-of-stock</span><span class="pharmacy">Pharmacia de transfer</span>',
    'Allergies' => 'Allergias',
    'Allergies Selected Below' => 'Si Allergias',
    'No Medication Allergies' => 'No Allergias',
    'Aspirin and salicylates' => 'Drogas de Aspirin',
    'Erythromycin, Biaxin, Zithromax' => 'Drogas de Erthromycin',
    'NSAIDS e.g., ibuprofen, Advil' => 'Drogas de NSAIDS',
    'Penicillins/cephalosporins e.g., Amoxil, amoxicillin, ampicillin, Keflex, cephalexin' => 'Drogas de Penicillin',
    'Sulfa drugs e.g., Septra, Bactrim, TMP/SMX' => 'Drogas de Sulfa',
    'Tetracycline antibiotics' => 'Antibiotics de Tetra',
    'Other Allergies' => 'Otras Allegerias',
    'Phone' => 'Telefono',
    'List any other medication(s) or supplement(s) you are currently taking' => 'Otras Drogas',
    'Email address' => 'Spanish Email',
    'First name' => 'Nombre Uno',
    'Last name' => 'Nombre Dos',
    'Date of Birth' => 'Fetcha Cumpleanos',
    'Address' => 'Spanish Address',
    'State' => 'Spanish State',
    'Zip code' => 'Spanish Zip',
    'Town / City' => 'Ciudad',
    'Password change' => 'Spanish Password',
    'Current password (leave blank to leave unchanged)' => 'Spanish current password (leave blank to leave unchanged)',
    'New password (leave blank to leave unchanged)' => 'Spanish New password (leave blank to leave unchanged)',
    'Confirm new password' => 'Spanish Confirm new password',
    'Email Address' => 'Spanish Email Address',
    'Fill out the form below to place a new order' => 'Spanish Fill Out Form'
  ];

  $english = isset($toEnglish[$term]) ? $toEnglish[$term] : $term;

  $spanish = $toSpanish[$english];

  if ( ! isset($spanish)) return $english;

  //This allows client side translating based on jQuery listening to radio buttons
  return  "<span class='english'>$english</span><span class='spanish'>$spanish</span>";
}

// Hook in
add_filter( 'woocommerce_checkout_fields' , 'custom_checkout_fields' );
function custom_checkout_fields( $fields ) {

  $patient_fields = patient_fields();

  //Add some order fields that are not in patient profile
  $order_fields = order_fields();

  //Insert order fields at offset 2
  $offset = 1;
  $fields['order'] =
    array_slice($patient_fields, 0, $offset, true) +
    $order_fields +
    array_slice($patient_fields, $offset, NULL, true);

  //Allow billing out of state but don't allow shipping out of state
  $fields['shipping']['shipping_state']['type'] = 'select';
  $fields['shipping']['shipping_state']['options'] = ['GA' => 'Georgia'];

  //Remove Some Fields
  unset($fields['billing']['billing_first_name']['autofocus']);
  unset($fields['billing']['shipping_first_name']['autofocus']);
  unset($fields['billing']['billing_phone']);
  unset($fields['billing']['billing_email']);
  unset($fields['billing']['billing_company']);
  unset($fields['billing']['billing_country']);
  unset($fields['shipping']['shipping_country']);
  unset($fields['shipping']['shipping_company']);

  return $fields;
}

// SirumWeb_AddRemove_Allergy(
//   @PatID int,     --Carepoint Patient ID number
//   @AddRem int = 1,-- 1=Add 0= Remove
//   @AlrNumber int,  -- From list
//   @OtherDescr varchar(80) = '' -- Description for "Other"
// )
// if      @AlrNumber = 1  -- TETRACYCLINE 250 MG CAPSULE
// else if @AlrNumber = 3  -- Sulfa (Sulfonamide Antibiotics)
// else if @AlrNumber = 4  -- Aspirin
// else if @AlrNumber = 5  -- Penicillins
// else if @AlrNumber = 6  -- Ampicillin
// else if @AlrNumber = 7  -- Erythromycin Base
// else if @AlrNumber = 8  -- Codeine
// else if @AlrNumber = 9  -- NSAIDS e.g., ibuprofen, Advil
// else if @AlrNumber = 99  -- none
// else if @AlrNumber = 100 -- other
function add_remove_allergy($guardian_id, $allergy_id, $value) {
  return run("SirumWeb_AddRemove_Allergy(?, ?, ?, ?)", [
    [$guardian_id, SQLSRV_PARAM_IN],
    [ !!$value, SQLSRV_PARAM_IN],
    [$allergy_id, SQLSRV_PARAM_IN],
    [$value, SQLSRV_PARAM_IN]
  ]);
}

// SirumWeb_AddUpdateCellPhone(
//   @PatID int,  -- ID of Patient
//   @PatCellPhone VARCHAR(20)
// }
function update_cell_phone($guardian_id, $cell_phone) {
  return run("SirumWeb_AddUpdateCellPhone(?, ?)", [
    [$guardian_id, SQLSRV_PARAM_IN],
    [$cell_phone, SQLSRV_PARAM_IN]
  ]);
}

// dbo.SirumWeb_AddUpdatePatShipAddr(
//  @PatID int
// ,@Addr1 varchar(50)    -- Address Line 1
// ,@Addr2 varchar(50)    -- Address Line 2
// ,@Addr3 varchar(50)    -- Address Line 3
// ,@City varchar(20)     -- City Name
// ,@State varchar(2)     -- State Name
// ,@Zip varchar(10)      -- Zip Code
// ,@Country varchar(3)   -- Country Code
function update_shipping_address($guardian_id, $address_1, $address_2, $city, $zip) {
  return run("SirumWeb_AddUpdatePatShipAddr(?, ?, ?, NULL, ?, 'GA', ?, 'US')", [
    [$guardian_id, SQLSRV_PARAM_IN],
    [$address_1, SQLSRV_PARAM_IN],
    [$address_2, SQLSRV_PARAM_IN],
    [$city, SQLSRV_PARAM_IN],
    [$zip, SQLSRV_PARAM_IN]
  ]);
}

//$query = sqlsrv_query( $db, "select * from cppat where cppat.pat_id=1003";);
// SirumWeb_FindPatByNameandDOB(
//   @LName varchar(30),           -- LAST NAME
//   @FName varchar(20),           -- FIRST NAME
//   @MName varchar(20)=NULL,     -- Middle Name (optional)
//   @DOB DateTime                -- Birth Date
// )
function findPatient($first_name, $last_name, $birth_date) {
  return run("SirumWeb_FindPatByNameandDOB(?, ?, NULL, ?)", [
    [$last_name, SQLSRV_PARAM_IN],
    [$first_name, SQLSRV_PARAM_IN],
    [$birth_date, SQLSRV_PARAM_IN]
  ]);
}

// SirumWeb_AddEditPatient(
//    @FirstName varchar(20)
//   ,@MiddleName varchar(20)= NULL -- Optional
//   ,@LastName varchar(30)
//   ,@BirthDate datetime
//   ,@ShipAddr1 varchar(50)    -- Address Line 1
//   ,@ShipAddr2 varchar(50)    -- Address Line 2
//   ,@ShipAddr3 varchar(50)    -- Address Line 3
//   ,@ShipCity varchar(20)     -- City Name
//   ,@ShipState varchar(2)     -- State Name
//   ,@ShipZip varchar(10)      -- Zip Code
//   ,@ShipCountry varchar(3)   -- Country Code
//   ,@CellPhone varchar(20)    -- Cell Phone
// )
function addPatient($first_name, $last_name, $birth_date) {
  return run("SirumWeb_FindPatByNameandDOB(?, NULL, ?, ?)", [
    [$first_name, SQLSRV_PARAM_IN],
    [$last_name, SQLSRV_PARAM_IN],
    [$birth_date, SQLSRV_PARAM_IN]
  ]);
}

// SirumWeb_AddToPreorder(
//  @PatID int
// ,@NDC varchar(11)  -- NDC to add
// ,@PharmacyOrgID int -- Org_id from Pharmacy List
// ,@PharmacyName varchar(80)
// ,@PharmacyAddr1 varchar(50)    -- Address Line 1
// ,@PharmacyAddr2 varchar(50)    -- Address Line 2
// ,@PharmacyAddr3 varchar(50)    -- Address Line 3
// ,@PharmacyCity varchar(20)     -- City Name
// ,@PharmacyState varchar(2)     -- State Name
// ,@PharmacyZip varchar(10)      -- Zip Code
// ,@PharmacyPhone varchar(20)   -- Phone Number
// ,@PharmacyFaxNo varchar(20)   -- Phone Fax Number
// )
// function preorder($first_name, $last_name, $birth_date) {
//   return run("SirumWeb_AddToPreorder()", [
//     [$first_name, SQLSRV_PARAM_IN],
//     [$last_name, SQLSRV_PARAM_IN],
//     [$birth_date, SQLSRV_PARAM_IN]
//   ]);
// }

function run($sp, $params) {
  return next_array(query($sp, $params));
}

function runAll($sp, $params) {
  $result = [];
  $query  = query($sp, $params);
  while($result[] = next_array($query));
  return $result;
}

function next_array($query) {
  return sqlsrv_fetch_array($query, SQLSRV_FETCH_ASSOC);
}

function query($sp, $params) {
  return sqlsrv_query(db(), "{call $sp}", $params) ?: db_error("Error executing procedure $sp");
}

function db() {
  return sqlsrv_connect('GOODPILL-SERVER', ['Database' => 'cph']) ?: db_error('sqlsrv_connect error');
}

function db_error($heading) {
  echo "<br><br><strong>$heading</strong><br>";
  print_r(sqlsrv_errors());
}
