$(document).ready(function() {

// @todo To be removed when $this->form() witll be used instead of $this->formCollection().

$('#propval_from, #propval_to, #propval_replace_mode, #propval_remove, #propval_prepend, #propval_append, #propval_language, #propval_language_clear, #propval_properties').closest('.field')
    .wrapAll('<fieldset id="properties_values" class="field-container">');
$('#properties_values')
    .prepend('<legend>' + Omeka.jsTranslate('Literal values') + '</legend>');

$('#propvis_visibility, #propvis_properties').closest('.field')
    .wrapAll('<fieldset id="properties_visibility" class="field-container">');
$('#properties_visibility')
    .prepend('<legend>' + Omeka.jsTranslate('Visibility') + '</legend>');

$('#mediahtml_from, #mediahtml_to, #mediahtml_replace_mode, #mediahtml_remove, #mediahtml_prepend, #mediahtml_append').closest('.field')
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
