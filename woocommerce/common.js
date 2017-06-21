jQuery(load)

function load() {

  if (window.location.search == '?register')
    return register_page()

  translate() //not just account_page.  Payment methods too

  if (window.location.pathname == '/account/details/')
    return account_page()

  if (window.location.pathname == '/account/')
    return account_page()
}

function register_page() {
  createUsername()
  upgradeBirthdate()
}

function account_page() {
  upgradePharmacy()
  upgradeAllergies()
  upgradeBirthdate()
}

function translate() {
  jQuery("#language_english").change(function($event){
    jQuery('#language').html(".spanish{display:none}")
  })

  jQuery("#language_spanish").change(function(){
    jQuery('#language').html(".english{display:none}")
  })

  jQuery("<style id='language' type='text/css'></style>").appendTo('head')
  jQuery("input[name=language]:checked").triggerHandler('change')
}

function upgradeAllergies() {
  jQuery("input[name=allergies_none]").on('change', function(){
    var children = jQuery(".allergies")
    this.value ? children.hide() : children.show()
  })
  jQuery("input[name=allergies_none]:checked").triggerHandler('change')

  var allergies_other = jQuery('#allergies_other').prop('disabled', true)
  jQuery('#allergies_other_input').on('input', function() {
    allergies_other.prop('checked', this.value)
  })
}

function upgradePharmacy(pharmacies) {
  console.log('upgradePharmacy')

  var select = jQuery('#backup_pharmacy')
  var pharmacyGsheet  = "https://spreadsheets.google.com/feeds/list/11Ew_naOBwFihUrkaQnqVTn_3rEx6eAwMvGzksVTv_10/1/public/values?alt=json"
  //ovrg94l is the worksheet id.  To get this you have to use https://spreadsheets.google.com/feeds/worksheets/1MV5mq6605X7U1Np2fpwZ1RHkaCpjsb7YqieLQsEQK88/private/full

  jQuery.ajax({
    url:pharmacyGsheet,
    type: 'GET',
    cache:true,
    success:function($data) {
      var pharmacies = $data.feed.entry.map(pharmacy2select)
      select.select2({data:pharmacies, matcher:matcher, minimumInputLength:3})
    }
  })
}

//TODO move this to PHP
function createUsername() {
  jQuery('#customer_login > div').toggle()
  return jQuery("form.register").submit(function($event){
    this.username.value = this.first_name.value+' '+this.last_name.value+' '+this.birth_date.value
  })
}

function upgradeBirthdate() {
  jQuery('#birth_date').prop('type', 'date') //can't easily set date type in woocommerce
}

function pharmacy2select(entry, i) {
  var address  = entry.gsx$cleanaddress.$t.replace(/(\d{5})(\d{4})/, '$1')
  var pharmacy = entry.gsx$name.$t+', '+address+', Phone: '+entry.gsx$phone.$t
  return {id:pharmacy+', Fax:'+entry.gsx$fax.$t, text:pharmacy}
}

//http://stackoverflow.com/questions/36591473/how-to-use-matcher-in-select2-js-v-4-0-0
function matcher(param, data) {
   if ( ! param.term ||  ! data.text) return null
   var has = true
   var words = param.term.toUpperCase().split(" ")
   var text  = data.text.toUpperCase()
   for (var i =0; i < words.length; i++)
     if ( ! ~ text.indexOf(words[i])) return null

   return data
}
