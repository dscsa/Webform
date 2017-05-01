var gsheet = "https://spreadsheets.google.com/feeds/list/1MV5mq6605X7U1Np2fpwZ1RHkaCpjsb7YqieLQsEQK88/ovrg94l/public/values?alt=json"
//ovrg94l is the worksheet id.  To get this you have to use https://spreadsheets.google.com/feeds/worksheets/1MV5mq6605X7U1Np2fpwZ1RHkaCpjsb7YqieLQsEQK88/private/full
var medications

Cognito.load("forms", { id: "17" }, {success:load})

ExoJQuery.ajax({
   url:gsheet,
   type: 'GET',
   cache:true,
   success:function($data) {
     console.log('$data.feed.entry', $data.feed.entry)
     medications = $data.feed.entry.map(gsheet2select)
     console.log('medications', medications)
     load()
   }
})

ExoJQuery(function() {
   ExoJQuery(document).on('navigate.cognito', navigate)
   console.log('page event listeners should be active')
})

load.count = 0
function load() {
  if(++load.count < 2) return
  //setTimeout(showAcceptTerms, 300)
  //setTimeout(upgradeMedication, 300)
}

function upgradeMedication() {

  var medicationSelect = ExoJQuery('[data-field="SearchAndSelectMedicationsByGenericName"] select')
  var medicationPrice  = ExoJQuery('[data-field="MedicationPrice"] select')
  var medicationList   = ExoJQuery('[data-field="MedicationList"] input')
  medicationSelect.children().remove()
  medicationSelect.select2({multiple:true,data:medications}).on("change", updatePrice)

  var medicationInput = ExoJQuery('.select2-search__field')    //this doesn't exist until select2 is run
  medicationInput.removeAttr("type")                        //Removes conflict between enfold and select2

  function updatePrice(e) {
    var price = medicationSelect.select2('data').reduce(sum, 0)
    //We have to update a text box because cognito won't save values from a multi-select form
    //We could just upgrade a text box (rather than select) but that would require full select2 not lite
    medicationPrice.val(Math.min(100, price)).click().change()
    medicationList.val(medicationSelect.val()).click().change()
  }
}

function navigate(e, data) {
  console.log('navigate', e, data)
}

function gsheet2select(entry, i) {
  var price     = entry.gsx$day_2.$t || entry.gsx$day.$t
  var message   = []

  if (entry.gsx$supplylevel.$t)
    message.push(entry.gsx$supplylevel.$t)

  if (entry.gsx$day.$t)
    message.push('30 day')

  message = message.length ? ' ('+message.join(', ')+')' : ''

  var drug = ' '+entry.gsx$drugname.$t+', $'+price+message
  var result = {id:drug, text:drug, disabled:entry.gsx$supplylevel.$t == 'Out of Stock', price:price}
  return result
}

function sum(a, b) {
  return +b.price+a
}

function showAcceptTerms() {
  ExoJQuery('.loader').hide()
  ExoJQuery('.c-button-section').prepend('<div style="font-size:12px; max-width:785px; margin-left:10px; margin-bottom:10px">By clicking Accept & Submit, I attest to the statements below and understand that the medication(s) that I am receiving from SIRUM now & in the future may have been donated, previously dispensed, and potentially stored in an uncontrolled environment.</div>')
}
