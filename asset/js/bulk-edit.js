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
    const bulkeditFieldsets = $('#bulkedit-fieldsets').data('bulkedit-fieldsets') || {};
    const bulkeditGroups = $('#bulkedit-fieldsets').data('bulkedit-groups') || {};
    const bulkeditRequired = $('#bulkedit-fieldsets').data('bulkedit-required') || {};
    const bulkeditBlocks = $('#bulkedit-fieldsets').data('bulkedit-blocks') || {};
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

    Object.entries(bulkeditGroups).forEach(([fieldsetName, groupLabel]) => {
        $('<h3 class="bulkedit-group">').text(groupLabel).insertBefore('#bulk-edit > #' + fieldsetName);
    });

    // Gather the elements of the blocks of a fieldset in collapsed blocks.
    Object.entries(bulkeditBlocks).forEach(([fieldsetName, blocks]) => {
        const container = $('#bulk-edit > #' + fieldsetName + ' > .collapsible');
        Object.entries(blocks).forEach(([blockName, blockLabel]) => {
            const fields = container.find('[data-bulkedit-block=' + blockName + ']').closest('.field');
            if (fields.length) {
                $('<div class="bulkedit-block">').attr('data-block', blockName)
                    .append($('<a href="#" class="expand">').attr('aria-label', Omeka.jsTranslate('Expand')).attr('title', Omeka.jsTranslate('Expand')).text(blockLabel))
                    .append($('<div class="collapsible">').append(fields))
                    .appendTo(container);
            }
        });
    });

    // Gather the secondary elements in a collapsed block.
    $('#bulk-edit > fieldset').each(function() {
        const options = $(this).find('[data-bulkedit-option]').closest('.field');
        if (options.length) {
            $('<div class="bulkedit-options">')
                .append('<a href="#" class="expand" aria-label="' + Omeka.jsTranslate('Expand') + '" title="' + Omeka.jsTranslate('Expand') + '">' + Omeka.jsTranslate('Options') + '</a>')
                .append($('<div class="collapsible">').append(options))
                .appendTo($(this).children('.collapsible'));
        }
    });

    // Hidden inputs that should not be after the inputs.
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=set_value_visibility]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));
    $('.batch-edit #content > form:first-of-type input[type=hidden][name=value]').insertAfter($('.batch-edit #content > form:first-of-type input[name=csrf]'));

    // For better ux.

    $('#bulk-edit')
        .prepend('<p>' + Omeka.jsTranslate('The actions are processed in the order of the form. Be careful when mixing them.') + '</p>');
    if (!$('input#geometry_convert_literal_to_coordinates').length) {
        $('#convert_from').closest('.collapsible')
            .append('<p>' + Omeka.jsTranslate('To convert values to/from mapping markers, use module DataTypeGeometry.') + '</p>');
    }

    $('#fill_values > .collapsible, #explode_item > .collapsible, #explode_pdf > .collapsible')
        .prepend('<p>' + Omeka.jsTranslate('Slow process, always run in background.') + '</p>')

    // Mark the actions that will be processed, with the same required elements
    // than the server.
    const fieldValues = (fieldset, name) => {
        // A path "block/name" is used for the elements of the blocks.
        const fieldName = 'bulkedit[' + fieldset.attr('id') + '][' + name.split('/').join('][') + ']';
        return fieldset.find(':input')
            .filter(function() {
                return (this.name === fieldName || this.name === fieldName + '[]')
                    && (!['checkbox', 'radio'].includes(this.type) || this.checked);
            })
            .map(function() { return $(this).val(); }).get()
            .flat().filter(value => value !== null && value !== '');
    };
    const isFilled = (fieldset, token) => token.split('+').every(part => {
        const [name, value] = part.split('=');
        const values = fieldValues(fieldset, name);
        return value === undefined ? values.length > 0 : values.includes(value);
    });
    const isRequiredFilled = (fieldset, required) => required
        .every(group => group.some(token => isFilled(fieldset, token)));
    // A fieldset with blocks is active when one of its blocks is active.
    const isActive = (fieldset) => {
        const required = bulkeditRequired[fieldset.attr('id')] || [[]];
        if (!required.any) {
            return isRequiredFilled(fieldset, required);
        }
        let active = false;
        Object.entries(required.any).forEach(([blockName, blockRequired]) => {
            const isBlockActive = isRequiredFilled(fieldset, blockRequired);
            fieldset.find('.bulkedit-block[data-block="' + blockName + '"]').toggleClass('bulkedit-active', isBlockActive);
            active = active || isBlockActive;
        });
        return active;
    };
    $('#bulk-edit').on('change input', ':input', function() {
        const fieldset = $(this).closest('#bulk-edit > fieldset');
        fieldset.toggleClass('bulkedit-active', isActive(fieldset));
    });

    $('.batch-edit').on('change', '.bulkedit-replace-mode', function() {
        const fieldset = $(this).closest('.bulkedit-block, fieldset');
        // The mode may be a select or radios.
        const modes = fieldset.find('.bulkedit-replace-mode');
        const mode = modes.is('select') ? modes.val() : (modes.filter(':checked').val() || '');
        fieldset.find('[name$="[from]"]').closest('.field').toggle(['raw', 'raw_i', 'html', 'regex'].includes(mode));
        fieldset.find('[name$="[to]"]').closest('.field').toggle(['raw', 'raw_i', 'html', 'regex', 'whole'].includes(mode));
    });
    $('.bulkedit-replace-mode').trigger('change');

    $('.batch-edit').on('change', '[name="bulkedit[thumbnails][mode]"]', function() {
        const mode = $('[name="bulkedit[thumbnails][mode]"]:checked').val() || '';
        $('#thumbnails_asset').closest('.field').toggle(!['', 'remove_primary', 'delete'].includes(mode));
    });
    $('[name="bulkedit[thumbnails][mode]"]').first().trigger('change');

    $('.batch-edit').on('change', '#mediaremove_mode', function() {
        const mode = $(this).val();
        $('#mediaremove_mediatypes').closest('.field').toggle(mode === 'media_type');
        $('#mediaremove_extensions').closest('.field').toggle(mode === 'media_extension');
    });
    $('#mediaremove_mode').trigger('change');

    $('.batch-edit').on('change', '#cleaning_clean_language_codes', function() {
        const fields = $('#cleaning_clean_language_codes_from, #cleaning_clean_language_codes_to, #cleaning_clean_language_codes_properties').closest('.field');
        this.checked ? fields.show() : fields.hide();
    });
    $('#cleaning_clean_language_codes').trigger('change');

    $('.batch-edit').on('change', '#convert_from, #convert_to', function() {
        const datatypes = $('#convert_from').data('datatypes');
        const datatypeFrom = $('#convert_from').val();
        const datatypeTo = $('#convert_to').val();
        const block = $('#convert_from').closest('.collapsible');
        block.find('[data-info-datatype]').closest('.field').hide();
        if (!datatypes[datatypeTo]) {
            return;
        }
        block.find('[data-info-datatype=' + datatypes[datatypeTo] + ']').closest('.field').show();
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
        'bulkedit[modify][replace][value_part]': 'value',
        'bulkedit[modify][replace][from]': '',
        'bulkedit[modify][replace][to]': '',
        'bulkedit[modify][replace][mode]': 'raw',
        'bulkedit[modify][replace][remove]': '0',
        'bulkedit[modify][replace][prepend]': '',
        'bulkedit[modify][replace][append]': '',
        'bulkedit[modify][language][language]': '',
        'bulkedit[modify][language][language_clear]': '0',
        'bulkedit[displace][to]': '',
        'bulkedit[displace][languages]': '',
        'bulkedit[displace][visibility]': '',
        'bulkedit[displace][contains]': '',
        'bulkedit[modify][explode][separator]': '',
        'bulkedit[modify][convert][from]': '',
        'bulkedit[modify][convert][to]': '',
        'bulkedit[modify][convert][literal_value]': '',
        'bulkedit[modify][convert][literal_extract_html_text]': '0',
        'bulkedit[modify][convert][literal_html_only_tagged_string]': '0',
        'bulkedit[modify][convert][uri_extract_label]': '0',
        'bulkedit[modify][convert][uri_label]': '',
        'bulkedit[modify][convert][uri_base_site]': 'api',
        'bulkedit[modify][order_values][languages]': '',
        'bulkedit[modify][properties_visibility][visibility]': '',
        'bulkedit[modify][contains]': '',
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
        'bulkedit[explode_pdf][process_events]': '0',
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
        'bulkedit[create_values][properties]': '',
        'bulkedit[create_values][values]': '',
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

    /**
     * Fix a strange issue with FormData for value "'bulkedit[fill_values][datatypes]'",
     * that is an array of values that should not contain an empty string.
     */
    $('#content form').on('submit', function(e) {
        const form = $(this);
        const formData = new FormData(form[0]);
        const fillValuesDatatypes = formData.getAll('bulkedit[fill_values][datatypes]');
        if (fillValuesDatatypes.length && fillValuesDatatypes[0] === '') {
            form.find('[name="bulkedit[fill_values][datatypes]"]').remove();
            formData.delete('bulkedit[fill_values][datatypes]');
            fillValuesDatatypes.shift();
            fillValuesDatatypes.forEach(value => {
                form.append('<input type="hidden" name="bulkedit[fill_values][datatypes][]" value="' + value + '">');
            });
        }
         return true;
     });

 });
