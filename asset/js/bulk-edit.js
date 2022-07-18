$(document).ready(function() {

    // @todo To be removed when $this->form() will be used instead of $this->formCollection().

    const nav = '<nav class="section-nav">'
        + '<ul>'
        + '<li class="active"><a href="#batch-edit" id="batch-edit-label">' + Omeka.jsTranslate('Batch edit') + '</a></li>'
        + '<li><a href="#bulk-edit" id="bulk-edit-label">' + Omeka.jsTranslate('Advanced bulk edit') + '</a></li>'
        + '</ul>'
        + '</nav>';
    $(nav).insertAfter('.batch-edit #page-actions');

    // Recreate missing bulk edit fieldsets.
    const bulkeditFieldsets = $('#bulkedit-fieldsets').data('bulkedit-fieldsets');
    $('#bulkedit-fieldsets').remove();
    Object.entries(bulkeditFieldsets).forEach(([fieldsetName, fieldsetLabel]) => {
        $('[data-bulkedit-fieldset=' + fieldsetName + ']').closest('.field')
            .wrapAll('<fieldset id="' + fieldsetName + '" class="field-container">');
        $('#' + fieldsetName)
            .prepend('<legend>' + fieldsetLabel + '</legend>');
    });

    // Wrap core form and bulk edit form with a section.
    $('.batch-edit #content > form:first-of-type > div, .batch-edit #content > form:first-of-type > fieldset')
        .filter(function () {
            const id = $(this).attr('id');
            return !bulkeditFieldsets.hasOwnProperty(id)
                && id !== 'page-actions';
        })
        .wrapAll('<div id="batch-edit" class="section active">');
    $('.batch-edit #content > form:first-of-type > fieldset')
        .filter(function () {
            return bulkeditFieldsets.hasOwnProperty($(this).attr('id'));
            return bulkeditFieldsets.indexOf($(this).attr('id')) >= 0;
        })
        .wrapAll('<fieldset id="bulk-edit" class="section">');

    // Hidden inputs that should not be after the inputs.
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=set_value_visibility]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=value]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));

    // For better ux.

    $('#bulk-edit')
        .prepend('<legend>' + Omeka.jsTranslate('The actions are processed in the order of the form. Be careful when mixing them.') + '</legend>');

    $('#fill_values legend')
        .after('<p>' + Omeka.jsTranslate('Fill a value from remote data can be slow, so it is recommended to process it in background with "batch edit all", not "batch edit selected".') + '</p>')

    $('.batch-edit').on('change', '#cleaning_clean_language_codes', function() {
        const fields = $('#cleaning_clean_language_codes_from, #cleaning_clean_language_codes_to, #cleaning_clean_language_codes_properties').closest('.field');
        this.checked ? fields.show() : fields.hide();
    });
    $('#cleaning_clean_language_codes').trigger('change');

    $('.batch-edit').on('change', '#convert_to', function() {
        const val = $(this).val();
        $('#convert_literal_value, #convert_resource_properties, #convert_uri_extract_label, #convert_uri_label, #convert_uri_base_site').closest('.field').hide();
        if (val === 'literal') {
            $('#convert_literal_value').closest('.field').show();
        } else if (val === 'resource') {
            $('#convert_resource_properties').closest('.field').show();
        } else if (val === 'uri') {
            $('#convert_uri_extract_label').closest('.field').show();
            $('#convert_uri_label').closest('.field').show();
            $('#convert_uri_base_site').closest('.field').show();
        }
    });
    $('#convert_to').trigger('change');

    $('.batch-edit').on('change', '#mediahtml_remove', function() {
        const fields = $('#mediahtml_from, #mediahtml_to, #mediahtml_mode, #mediahtml_prepend, #mediahtml_append').closest('.field');
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
