$(document).ready(function() {

// @todo To be removed when $this->form() witll be used instead of $this->formCollection().

var nav = '<nav class="section-nav">'
    + '<ul>'
    + '<li class="active"><a href="#batch-edit" id="batch-edit-label">' + Omeka.jsTranslate('Batch edit') + '</a></li>'
    + '<li><a href="#bulk-edit" id="bulk-edit-label">' + Omeka.jsTranslate('Advanced bulk edit') + '</a></li>'
    + '</ul>'
    + '</nav>';
$(nav).insertAfter('.batch-edit #page-actions');

var bulkeditFieldsets = [
    'cleaning',
    'replace',
    'order_values',
    'properties_visibility',
    'displace',
    'explode',
    'merge',
    'convert',
    'media_html',
];

$('#cleaning_trim, #cleaning_datatypes, #cleaning_languages, #cleaning_language_codes, #cleaning_language_codes_from, #cleaning_language_codes_to, #cleaning_language_codes_properties, #cleaning_deduplicate').closest('.field')
    .wrapAll('<fieldset id="cleaning" class="field-container">');
$('#cleaning')
    .prepend('<legend>' + Omeka.jsTranslate('Cleaning') + '</legend>');

$('#replace_from, #replace_to, #replace_mode, #replace_remove, #replace_prepend, #replace_append, #replace_language, #replace_language_clear, #replace_properties').closest('.field')
    .wrapAll('<fieldset id="replace" class="field-container">');
$('#replace')
    .prepend('<legend>' + Omeka.jsTranslate('Replace literal values') + '</legend>');

$('#order_languages, #order_properties').closest('.field')
    .wrapAll('<fieldset id="order_values" class="field-container">');
$('#order_values')
    .prepend('<legend>' + Omeka.jsTranslate('Order values') + '</legend>');

$('#propvis_visibility, #propvis_properties, #propvis_datatypes, #propvis_languages, #propvis_contains').closest('.field')
    .wrapAll('<fieldset id="properties_visibility" class="field-container">');
$('#properties_visibility')
    .prepend('<legend>' + Omeka.jsTranslate('Visibility of values') + '</legend>');

$('#displace_from, #displace_to, #displace_datatypes, #displace_languages, #displace_visibility, #displace_contains').closest('.field')
    .wrapAll('<fieldset id="displace" class="field-container">');
$('#displace')
    .prepend('<legend>' + Omeka.jsTranslate('Displace values') + '</legend>');

$('#explode_properties, #explode_separator, #explode_contains').closest('.field')
    .wrapAll('<fieldset id="explode" class="field-container">');
$('#explode')
    .prepend('<legend>' + Omeka.jsTranslate('Explode values') + '</legend>');

$('#merge_properties').closest('.field')
    .wrapAll('<fieldset id="merge" class="field-container">');
$('#merge')
    .prepend('<legend>' + Omeka.jsTranslate('Merge values as uri and label') + '</legend>');

$('#convert_from, #convert_to, #convert_properties, #convert_literal_value, #convert_resource_properties, #convert_uri_label').closest('.field')
    .wrapAll('<fieldset id="convert" class="field-container">');
$('#convert')
    .prepend('<legend>' + Omeka.jsTranslate('Convert datatype') + '</legend>');

$('#mediahtml_from, #mediahtml_to, #mediahtml_mode, #mediahtml_remove, #mediahtml_prepend, #mediahtml_append').closest('.field')
    .wrapAll('<fieldset id="media_html" class="field-container">');
$('#media_html')
    .prepend('<legend>' + Omeka.jsTranslate('Media HTML') + '</legend>');

$('.batch-edit #content > form:first-of-type > div, .batch-edit #content > form:first-of-type > fieldset')
    .filter(function () {
        var id = $(this).attr('id');
        return bulkeditFieldsets.indexOf(id) < 0
            && id !== 'page-actions';
    })
    .wrapAll('<div id="batch-edit" class="section active">');
$('.batch-edit #content > form:first-of-type > fieldset')
    .filter(function () {
        return bulkeditFieldsets.indexOf($(this).attr('id')) >= 0;
    })
    .wrapAll('<div id="bulk-edit" class="section">');
$('#bulk-edit')
    .prepend('<legend>' + Omeka.jsTranslate('The actions are processed in the order of the form. Be careful when mixing them.') + '</legend>');

// Hidden inputs that should not be after the inputs.
$('.batch-edit #content > form:first-of-type input[type=hidden][name=set_value_visibility]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));
$('.batch-edit #content > form:first-of-type input[type=hidden][name=value]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));

// For better ux.
$('.batch-edit').on('change', '#cleaning_language_codes', function() {
    var fields = $('#cleaning_language_codes_from, #cleaning_language_codes_to, #cleaning_language_codes_properties').closest('.field');
    this.checked ? fields.show() : fields.hide();
});
$('#cleaning_language_codes').trigger('change');

$('.batch-edit').on('change', '#convert_to', function() {
    var val = $(this).val();
    $('#convert_literal_value, #convert_resource_properties, #convert_uri_label').closest('.field').hide();
    if (val === 'literal') {
        $('#convert_literal_value').closest('.field').show();
    } else if (val === 'resource') {
        $('#convert_resource_properties').closest('.field').show();
    } else if (val === 'uri') {
        $('#convert_uri_label').closest('.field').show();
    }
});
$('#convert_to').trigger('change');

$('.batch-edit').on('change', '#mediahtml_remove', function() {
    var fields = $('#mediahtml_from, #mediahtml_to, #mediahtml_mode, #mediahtml_prepend, #mediahtml_append').closest('.field');
    this.checked ? fields.hide() : fields.show();
});
$('#mediahtml_remove').trigger('change');

// From resource-form.js.
$('input.value-language').on('keyup', function(e) {
    if ('' === this.value || Omeka.langIsValid(this.value)) {
        this.setCustomValidity('');
    } else {
        this.setCustomValidity(Omeka.jsTranslate('Please enter a valid language tag'));
    }
});

// From admin.js.
// Switch between section tabs.
$('.section-nav a[href^="#"]').click(function (e) {
    e.preventDefault();
    Omeka.switchActiveSection($($(this).attr('href')));
});
$('.section > legend').click(function() {
    $(this).parent().toggleClass('mobile-active');
});

});
