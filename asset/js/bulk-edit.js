$(document).ready(function() {

    // @todo To be removed when $this->form() will be used instead of $this->formCollection().

    const nav = '<nav class="section-nav">'
        + '<ul>'
        + '<li class="active"><a href="#batch-edit" id="batch-edit-label">' + Omeka.jsTranslate('Batch edit') + '</a></li>'
        + '<li><a href="#bulk-edit" id="bulk-edit-label">' + Omeka.jsTranslate('Advanced') + '</a></li>'
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

    $('#fill_values > .collapsible, #explode_item > .collapsible, #explode_pdf > .collapsible')
        .prepend('<p>' + Omeka.jsTranslate('Processes that manage files and remote data can be slow, so it is recommended to process it in background with "batch edit all", not "batch edit selected".') + '</p>')

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

    /**
     * There is a bug during hydration in abstractResourceEntityAdapter or
     * ValueHydrator: when there is an operation "replace" empty (?), the
     * operation "remove" does not work. Try to remove a property disabling
     * all events of the modules except "form.add_elements".
     * So just remove bulkedit if there is no data.
     * Fixed in Omeka S v4.0.2 (#609dbbb30).
     */
    const bulkEditDefaults = {
        'bulkedit[cleaning][trim_values]': '0',
        'bulkedit[cleaning][specify_datatypes]': '0',
        'bulkedit[cleaning][clean_languages]': '0',
        'bulkedit[cleaning][clean_language_codes]': '0',
        'bulkedit[cleaning][clean_language_codes_from]': '',
        'bulkedit[cleaning][clean_language_codes_to]': '',
        'bulkedit[cleaning][deduplicate_values]': '0',
        'bulkedit[replace][from]': '',
        'bulkedit[replace][to]': '',
        'bulkedit[replace][mode]': 'raw',
        'bulkedit[replace][remove]': '0',
        'bulkedit[replace][prepend]': '',
        'bulkedit[replace][append]': '',
        'bulkedit[replace][language]': '',
        'bulkedit[replace][language_clear]': '0',
        'bulkedit[displace][to]': '',
        'bulkedit[displace][languages]': '',
        'bulkedit[displace][visibility]': '',
        'bulkedit[displace][contains]': '',
        'bulkedit[explode][separator]': '',
        'bulkedit[explode][contains]': '',
        'bulkedit[convert][from]': '',
        'bulkedit[convert][to]': '',
        'bulkedit[convert][literal_value]': '',
        'bulkedit[convert][literal_extract_html_text]': '0',
        'bulkedit[convert][literal_html_only_tagged_string]': '0',
        'bulkedit[convert][uri_extract_label]': '0',
        'bulkedit[convert][uri_label]': '',
        'bulkedit[convert][uri_base_site]': 'api',
        'bulkedit[convert][contains]': '',
        'bulkedit[order_values][languages]': '',
        'bulkedit[properties_visibility][visibility]': '',
        'bulkedit[properties_visibility][languages]': '',
        'bulkedit[properties_visibility][contains]': '',
        'bulkedit[fill_data][owner]': '',
        'bulkedit[fill_values][datatype]': '',
        'bulkedit[fill_values][language]': '',
        'bulkedit[fill_values][update_language]': 'keep',
        'bulkedit[fill_values][featured_subject]': '0',
        'bulkedit[remove][languages]': '',
        'bulkedit[remove][visibility]': '',
        'bulkedit[remove][equal]': '',
        'bulkedit[remove][contains]': '',
        'bulkedit[explode_item][mode]': '',
        'bulkedit[explode_pdf][mode]': '',
        'bulkedit[explode_pdf][process]': 'all',
        'bulkedit[explode_pdf][resolution]': '',
        'bulkedit[media_order][order]': '',
        'bulkedit[media_order][mediatypes]': 'video audio image application/pdf',
        'bulkedit[media_order][extensions]': '',
        'bulkedit[media_html][from]': '',
        'bulkedit[media_html][to]': '',
        'bulkedit[media_html][mode]': 'raw',
        'bulkedit[media_html][remove]': '0',
        'bulkedit[media_html][prepend]': '',
        'bulkedit[media_html][append]': '',
        'bulkedit[media_type][from]': '',
        'bulkedit[media_type][to]': '',
        'bulkedit[media_visibility][visibility]': '',
    };

   $('#content form').on('submit', function(e) {
        const form = $(this);
        const formData = new FormData(form[0]);
        for (const [name, value] of formData) {
            if (bulkEditDefaults.hasOwnProperty(name) && bulkEditDefaults[name] !== value) {
                return true;
            }
        }
        Object.keys(bulkEditDefaults).forEach(name => {
            form.find('[name="' + name + '"]').remove();
            formData.delete(name);
        });
        return true;
    });

});
