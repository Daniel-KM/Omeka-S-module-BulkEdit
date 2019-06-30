$(document).ready(function() {

// @todo To be removed when $this->form() witll be used instead of $this->formCollection().
$('#langprop_language, #langprop_clear, #langprop_properties').parent().parent()
    .wrapAll('<fieldset id="language_properties" class="field-container">');
$('#language_properties')
    .prepend('<legend>' + Omeka.jsTranslate('Language') + '</legend>');

// From resource-form.js.
$('input.value-language').on('keyup', function(e) {
    if ('' === this.value || Omeka.langIsValid(this.value)) {
        this.setCustomValidity('');
    } else {
        this.setCustomValidity(Omeka.jsTranslate('Please enter a valid language tag'));
    }
});

});
