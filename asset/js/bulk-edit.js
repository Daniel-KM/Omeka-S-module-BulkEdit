$(document).ready(function() {

// @todo To be removed when $this->form() witll be used instead of $this->formCollection().

$('#proplang_language, #proplang_clear, #proplang_properties').closest('.field')
    .wrapAll('<fieldset id="properties_language" class="field-container">');
$('#properties_language')
    .prepend('<legend>' + Omeka.jsTranslate('Language') + '</legend>');

$('#propvis_visibility, #propvis_properties').closest('.field')
    .wrapAll('<fieldset id="properties_visibility" class="field-container">');
$('#properties_visibility')
    .prepend('<legend>' + Omeka.jsTranslate('Visibility') + '</legend>');

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
