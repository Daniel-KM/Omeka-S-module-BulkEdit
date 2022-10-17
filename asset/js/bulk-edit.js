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
            .wrapAll('<fieldset id="' + fieldsetName + '" class="field-container"><div class="collapsible">');
        $('#' + fieldsetName)
            .prepend('<a href="#" class="expand" aria-label="' + Omeka.jsTranslate('Expand') + '" title="' + Omeka.jsTranslate('Expand') + '">'
                + '<legend>' + fieldsetLabel + '</legend>'
                + '</a>');
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
        })
        .wrapAll('<fieldset id="bulk-edit" class="section">');

    // Hidden inputs that should not be after the inputs.
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=set_value_visibility]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=value]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));

    // For better ux.

    $('#bulk-edit')
        .prepend('<p>' + Omeka.jsTranslate('The actions are processed in the order of the form. Be careful when mixing them.') + '</p>');
    if (!$('input#geometry_convert_literal_to_coordinates').length) {
        $('#bulk-edit')
            .append('<p>' + Omeka.jsTranslate('To convert values to/from mapping markers, use module DataTypeGeometry.') + '</p>');
    }

    $('#fill_values > .collapsible')
        .prepend('<p>' + Omeka.jsTranslate('Fill a value from remote data can be slow, so it is recommended to process it in background with "batch edit all", not "batch edit selected".') + '</p>')

    $('.batch-edit').on('change', '#cleaning_clean_language_codes', function() {
        const fields = $('#cleaning_clean_language_codes_from, #cleaning_clean_language_codes_to, #cleaning_clean_language_codes_properties').closest('.field');
        this.checked ? fields.show() : fields.hide();
    });
    $('#cleaning_clean_language_codes').trigger('change');

    $('.batch-edit').on('change', '#convert_from, #convert_to', function() {
        const datatypes = $('#convert_from').data('datatypes');
        const datatypeFrom = $('#convert_from').val();
        const datatypeTo = $('#convert_to').val();
        $('#bulk-edit #convert [data-info-datatype]').closest('.field').hide();
        if (!datatypes[datatypeTo]) {
            return;
        }
        $('#bulk-edit #convert [data-info-datatype=' + datatypes[datatypeTo] + ']').closest('.field').show();
        if (!datatypes[datatypeFrom]) {
            return;
        }
        if (datatypes[datatypeTo] === 'literal') {
            if (datatypes[datatypeFrom] !== 'uri') {
                $('#convert_literal_value').closest('.field').hide();
            }
            $('#convert_literal_extract_html_text').closest('.field').hide();
            $('#convert_literal_html_only_tagged_string').closest('.field').hide();
            if (datatypeFrom === 'html' || datatypeFrom === 'xml') {
                $('#convert_literal_extract_html_text').closest('.field').show();
            }
            if (datatypes[datatypeTo] === 'literal' && (datatypeTo === 'html' || datatypeTo === 'xml')) {
                $('#convert_literal_html_only_tagged_string').closest('.field').show();
            }
        }
        if (datatypes[datatypeTo] === 'resource' && datatypes[datatypeFrom] !== 'literal') {
            $('#convert_resource_properties').closest('.field').hide();
        }
        if (datatypes[datatypeTo] === 'uri') {
            if (datatypes[datatypeFrom] !== 'literal') {
                $('#convert_uri_extract_label').closest('.field').hide();
            }
            if (datatypes[datatypeFrom] !== 'resource') {
                $('#convert_uri_base_site').closest('.field').hide();
            }
            if (datatypes[datatypeFrom] === 'uri') {
                $('#convert_uri_extract_label').closest('.field').hide();
            }
        }
    });
    $('#convert_from, #convert_to').trigger('change');

    $('.batch-edit').on('change', 'input[name="bulkedit[fill_values][mode]"]', function() {
        const fieldset = $(this).closest('fieldset');
        const mainMode = $(this).data('fill-main').length ? $(this).data('fill-main') : 'label';
        fieldset.find('[data-fill-mode=' + mainMode + ']').closest('.field').show();
        fieldset.find('[data-fill-mode]').not('[data-fill-mode=' + mainMode + ']').closest('.field').hide();
        fieldset.find('[data-fill-mode-option=' + mainMode + ']').show();
        fieldset.find('[data-fill-mode-option]').not('[data-fill-mode-option=' + mainMode + ']').hide();
        fieldset.find('[data-fill-mode-option]').closest('select').trigger('chosen:updated');
    });
    // $('input[name="bulkedit[fill_values][mode]"]').trigger('change');

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
