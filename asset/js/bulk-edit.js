$(document).ready(function() {

// @todo To be removed when $this->form() witll be used instead of $this->formCollection().

$('#replace_from, #replace_to, #replace_mode, #replace_remove, #replace_prepend, #replace_append, #replace_language, #replace_language_clear, #replace_properties').closest('.field')
    .wrapAll('<fieldset id="replace" class="field-container">');
$('#replace')
    .prepend('<legend>' + Omeka.jsTranslate('Replace literal values') + '</legend>');

$('#order_languages, #order_properties').closest('.field')
    .wrapAll('<fieldset id="order_values" class="field-container">');
$('#order_values')
    .prepend('<legend>' + Omeka.jsTranslate('Order values') + '</legend>');

$('#propvis_visibility, #propvis_properties').closest('.field')
    .wrapAll('<fieldset id="properties_visibility" class="field-container">');
$('#properties_visibility')
    .prepend('<legend>' + Omeka.jsTranslate('Visibility of values') + '</legend>');

$('#displace_from, #displace_to, #displace_datatypes, #displace_languages, #displace_visibility, #displace_contains').closest('.field')
    .wrapAll('<fieldset id="displace" class="field-container">');
$('#displace')
    .prepend('<legend>' + Omeka.jsTranslate('Displace values') + '</legend>');

$('#mediahtml_from, #mediahtml_to, #mediahtml_mode, #mediahtml_remove, #mediahtml_prepend, #mediahtml_append').closest('.field')
    .wrapAll('<fieldset id="media_html" class="field-container">');
$('#media_html')
    .prepend('<legend>' + Omeka.jsTranslate('Media HTML') + '</legend>');

$('#cleaning_trim, #cleaning_deduplicate').closest('.field')
    .wrapAll('<fieldset id="cleaning" class="field-container">');
$('#cleaning')
    .prepend('<legend>' + Omeka.jsTranslate('Cleaning') + '</legend>');

// From resource-form.js.
$('input.value-language').on('keyup', function(e) {
    if ('' === this.value || Omeka.langIsValid(this.value)) {
        this.setCustomValidity('');
    } else {
        this.setCustomValidity(Omeka.jsTranslate('Please enter a valid language tag'));
    }
});

});
